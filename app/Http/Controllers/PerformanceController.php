<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Performance reviews as a staged workflow instead of a flat form:
 *   draft → self-review → manager-review → finalized
 * Each stage captures its own rating + comments; the per-stage data and the
 * current stage are shown on the review screen with the next action available.
 */
class PerformanceController extends Controller
{
    private const EXTRA_COLS = ['self_rating', 'self_comments', 'manager_rating', 'manager_comments', 'final_rating', 'goals'];

    private function ensureCols(): void
    {
        if (! Schema::hasTable('performance_reviews')) {
            Schema::create('performance_reviews', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('company_id')->nullable();
                $t->unsignedBigInteger('employee_id')->nullable();
                $t->string('employee')->nullable();
                $t->string('company_name')->nullable();
                $t->string('cycle')->nullable();
                $t->decimal('rating', 5, 2)->nullable();
                $t->string('reviewer')->nullable();
                $t->string('status')->nullable();
                $t->timestamps();
            });
        }
        $add = [];
        foreach (self::EXTRA_COLS as $c) {
            if (! Schema::hasColumn('performance_reviews', $c)) {
                $add[] = $c;
            }
        }
        if ($add) {
            Schema::table('performance_reviews', function (Blueprint $t) use ($add) {
                foreach ($add as $c) {
                    if (str_contains($c, 'rating')) {
                        $t->decimal($c, 5, 2)->nullable();
                    } else {
                        $t->text($c)->nullable();
                    }
                }
            });
        }
    }

    public function board(Request $request)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $this->ensureCols();
            $tid = $request->user()->tenant_id;
            $rows = DB::table('performance_reviews')
                ->when($tid && Schema::hasColumn('performance_reviews', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->when(Schema::hasColumn('performance_reviews', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->orderByDesc('id')->get();

            $out = $rows->map(function ($r) {
                $status = $r->status ?: 'draft';

                return [
                    'id' => $r->id,
                    'employee' => $r->employee ?? '',
                    'cycle' => $r->cycle ?? '',
                    'reviewer' => $r->reviewer ?? '',
                    'status' => $status,
                    'self_rating' => $r->self_rating ?? null,
                    'self_comments' => $r->self_comments ?? '',
                    'manager_rating' => $r->manager_rating ?? null,
                    'manager_comments' => $r->manager_comments ?? '',
                    'final_rating' => $r->final_rating ?? ($r->rating ?? null),
                    'goals' => $r->goals ?? '',
                ];
            })->all();

            return response()->json(['ok' => true, 'rows' => $out]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'rows' => []]);
        }
    }

    /** Advance a review through its workflow. action ∈ self | manager | finalize. */
    public function advance(Request $request, int $id)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $v = $request->validate([
                'action' => ['required', 'in:self,manager,finalize'],
                'rating' => ['nullable', 'numeric'],
                'comments' => ['nullable', 'string'],
                'goals' => ['nullable', 'string'],
            ]);
            $this->ensureCols();
            $rev = DB::table('performance_reviews')->where('id', $id)->first();
            if (! $rev) {
                return response()->json(['ok' => false, 'error' => 'Review not found.'], 404);
            }
            $upd = ['updated_at' => now()];
            if ($v['action'] === 'self') {
                $upd['self_rating'] = $v['rating'] ?? null;
                $upd['self_comments'] = $v['comments'] ?? '';
                if (! empty($v['goals'])) {
                    $upd['goals'] = $v['goals'];
                }
                $upd['status'] = 'self_done';
            } elseif ($v['action'] === 'manager') {
                $upd['manager_rating'] = $v['rating'] ?? null;
                $upd['manager_comments'] = $v['comments'] ?? '';
                $upd['status'] = 'reviewed';
                $upd['reviewer'] = $request->user()->name;
            } else { // finalize
                $upd['final_rating'] = $v['rating'] ?? ($rev->manager_rating ?? null);
                $upd['rating'] = $upd['final_rating']; // keep the legacy rating column in sync
                $upd['status'] = 'finalized';
            }
            DB::table('performance_reviews')->where('id', $id)->update($upd);

            return response()->json(['ok' => true, 'status' => $upd['status']]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
