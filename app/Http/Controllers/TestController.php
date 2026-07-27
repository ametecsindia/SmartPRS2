<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tests engine — turns "Tests" from a flat list into real assessments:
 * a question bank per test (MCQ, single correct), an employee take-test flow,
 * and auto-scoring that writes a graded row into test_attempts (which Test
 * Results / Test Reports already read). Admin/HR manage questions; any
 * logged-in employee can take a test assigned to them.
 */
class TestController extends Controller
{
    private function isManager(Request $request): bool
    {
        return $request->user()->hasAnyRole(['super_admin', 'admin', 'hr_manager']);
    }

    private function ensureTables(): void
    {
        if (! Schema::hasTable('test_questions')) {
            Schema::create('test_questions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('test_id')->index();
                $t->integer('seq')->default(0);
                $t->text('question')->nullable();
                $t->text('opt_a')->nullable();
                $t->text('opt_b')->nullable();
                $t->text('opt_c')->nullable();
                $t->text('opt_d')->nullable();
                $t->string('correct', 1)->nullable();
                $t->integer('marks')->default(1);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('test_attempts')) {
            Schema::create('test_attempts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->unsignedBigInteger('employee_id')->nullable()->index();
                $t->unsignedBigInteger('test_id')->nullable()->index();
                $t->string('employee')->nullable();
                $t->string('test')->nullable();
                $t->string('status')->nullable();
                $t->decimal('score', 8, 2)->nullable();
                $t->date('attempted_on')->nullable();
                $t->timestamps();
            });
        }
    }

    /** The employee record for the current user (link → email → name). */
    private function currentEmployee(Request $request)
    {
        $user = $request->user();
        $tid = $user->tenant_id;
        if (! empty($user->employee_id)) {
            $e = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
            if ($e) {
                return $e;
            }
        }

        return DB::table('employees')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('email', $user->email)->orWhere('name', $user->name))
            ->first();
    }

    /** Tests list with question counts + the current user's latest attempt. */
    public function list(Request $request)
    {
        try {
            $this->ensureTables();
            $tid = $request->user()->tenant_id;
            $emp = $this->currentEmployee($request);

            $tests = DB::table('tests')
                ->when($tid && Schema::hasColumn('tests', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->when(Schema::hasColumn('tests', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->orderBy('name')->get();

            $counts = DB::table('test_questions')
                ->when($tid && Schema::hasColumn('test_questions', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->select('test_id', DB::raw('COUNT(*) as n'))->groupBy('test_id')->pluck('n', 'test_id');

            $myAttempts = [];
            if ($emp) {
                $myAttempts = DB::table('test_attempts')
                    ->where('employee_id', $emp->id)
                    ->orderByDesc('id')->get()->keyBy('test_id');
            }

            $rows = $tests->map(function ($t) use ($counts, $myAttempts) {
                $a = $myAttempts[$t->id] ?? null;

                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'category' => $t->category ?? '',
                    'pass_mark' => (float) ($t->pass_mark ?? 0),
                    'questions' => (int) ($counts[$t->id] ?? 0),
                    'my_status' => $a->status ?? null,
                    'my_score' => $a ? (float) $a->score : null,
                ];
            })->all();

            return response()->json(['ok' => true, 'isManager' => $this->isManager($request), 'hasEmployee' => (bool) $emp, 'tests' => $rows]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'tests' => []]);
        }
    }

    /** Admin: the full question bank for a test (includes correct answers). */
    public function questions(Request $request, int $testId)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $this->ensureTables();
        $tid = $request->user()->tenant_id;
        $qs = DB::table('test_questions')
            ->when($tid && Schema::hasColumn('test_questions', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
            ->where('test_id', $testId)->orderBy('seq')->orderBy('id')->get();

        return response()->json(['ok' => true, 'questions' => $qs]);
    }

    public function saveQuestion(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $v = $request->validate([
                'id' => ['nullable'],
                'test_id' => ['required'],
                'question' => ['required', 'string'],
                'opt_a' => ['nullable', 'string'],
                'opt_b' => ['nullable', 'string'],
                'opt_c' => ['nullable', 'string'],
                'opt_d' => ['nullable', 'string'],
                'correct' => ['required', 'in:a,b,c,d'],
                'marks' => ['nullable', 'numeric'],
                'seq' => ['nullable', 'numeric'],
            ]);
            $this->ensureTables();
            $tid = $request->user()->tenant_id;
            $row = [
                'tenant_id' => $tid,
                'test_id' => (int) $v['test_id'],
                'question' => $v['question'],
                'opt_a' => $v['opt_a'] ?? '',
                'opt_b' => $v['opt_b'] ?? '',
                'opt_c' => $v['opt_c'] ?? '',
                'opt_d' => $v['opt_d'] ?? '',
                'correct' => $v['correct'],
                'marks' => (int) ($v['marks'] ?? 1),
                'seq' => (int) ($v['seq'] ?? 0),
                'updated_at' => now(),
            ];
            if (! empty($v['id'])) {
                DB::table('test_questions')->where('id', (int) $v['id'])->update($row);
            } else {
                $row['created_at'] = now();
                DB::table('test_questions')->insert($row);
            }
            $this->syncCount((int) $v['test_id'], $tid);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function deleteQuestion(Request $request, int $id)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        $this->ensureTables();
        $tid = $request->user()->tenant_id;
        $q = DB::table('test_questions')->where('id', $id)->first();
        DB::table('test_questions')->where('id', $id)->delete();
        if ($q) {
            $this->syncCount((int) $q->test_id, $tid);
        }

        return response()->json(['ok' => true]);
    }

    /** Keep tests.questions (the count column) in sync for display. */
    private function syncCount(int $testId, ?int $tid): void
    {
        try {
            if (! Schema::hasColumn('tests', 'questions')) {
                return;
            }
            $n = DB::table('test_questions')->where('test_id', $testId)->count();
            DB::table('tests')->where('id', $testId)->update(['questions' => $n]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /** Employee: the questions for a test WITHOUT the correct answers. */
    public function take(Request $request, int $testId)
    {
        try {
            $this->ensureTables();
            $tid = $request->user()->tenant_id;
            $test = DB::table('tests')->where('id', $testId)->first();
            if (! $test) {
                return response()->json(['ok' => false, 'error' => 'Test not found.'], 404);
            }
            $qs = DB::table('test_questions')
                ->when($tid && Schema::hasColumn('test_questions', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->where('test_id', $testId)->orderBy('seq')->orderBy('id')
                ->get(['id', 'question', 'opt_a', 'opt_b', 'opt_c', 'opt_d', 'marks']);
            if ($qs->isEmpty()) {
                return response()->json(['ok' => false, 'error' => 'This test has no questions yet.'], 422);
            }

            return response()->json(['ok' => true, 'test' => ['id' => $test->id, 'name' => $test->name, 'pass_mark' => (float) ($test->pass_mark ?? 0)], 'questions' => $qs]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Employee: submit answers → auto-score → write a graded attempt. */
    public function submit(Request $request, int $testId)
    {
        try {
            $v = $request->validate(['answers' => ['required', 'array']]);
            $this->ensureTables();
            $tid = $request->user()->tenant_id;
            $emp = $this->currentEmployee($request);
            $test = DB::table('tests')->where('id', $testId)->first();
            if (! $test) {
                return response()->json(['ok' => false, 'error' => 'Test not found.'], 404);
            }
            $qs = DB::table('test_questions')->where('test_id', $testId)->get();
            if ($qs->isEmpty()) {
                return response()->json(['ok' => false, 'error' => 'This test has no questions.'], 422);
            }
            $answers = $v['answers'];
            $totalMarks = 0;
            $earned = 0;
            foreach ($qs as $q) {
                $m = (int) ($q->marks ?: 1);
                $totalMarks += $m;
                $given = strtolower((string) ($answers[$q->id] ?? ''));
                if ($given !== '' && $given === strtolower((string) $q->correct)) {
                    $earned += $m;
                }
            }
            $pct = $totalMarks > 0 ? round($earned * 100 / $totalMarks, 2) : 0.0;
            $passMark = (float) ($test->pass_mark ?? 0);
            $passed = $pct >= $passMark;

            $row = [
                'tenant_id' => $tid,
                'company_id' => $emp->company_id ?? null,
                'employee_id' => $emp->id ?? null,
                'test_id' => $testId,
                'employee' => $emp->name ?? $request->user()->name,
                'test' => $test->name,
                'status' => $passed ? 'passed' : 'failed',
                'score' => $pct,
                'attempted_on' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $row = ApprovalService::safeRow('test_attempts', $row);
            DB::table('test_attempts')->insert($row);

            return response()->json(['ok' => true, 'score' => $pct, 'earned' => $earned, 'total' => $totalMarks,
                'passed' => $passed, 'pass_mark' => $passMark,
                'message' => ($passed ? 'Passed' : 'Did not pass').' — scored '.$pct.'% ('.$earned.'/'.$totalMarks.'), pass mark '.$passMark.'%.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
