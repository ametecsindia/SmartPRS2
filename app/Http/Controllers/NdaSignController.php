<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public NDA / confidentiality e-sign flow. An agent receives a tokenised link
 * (LetterController::sendAcceptLink for letter_type = nda), opens this PUBLIC page
 * (no login), reviews the undertaking, types their name and confirms — which stamps
 * the letter signed + time + IP + name. No auth (the token is the secret).
 */
class NdaSignController extends Controller
{
    private function ensureCols(): void
    {
        if (! Schema::hasTable('letters')) {
            return;
        }
        foreach (['accept_token' => 'string', 'accepted_at' => 'ts', 'accepted_ip' => 'string', 'signed_name' => 'string'] as $c => $t) {
            if (! Schema::hasColumn('letters', $c)) {
                Schema::table('letters', function (Blueprint $b) use ($c, $t) {
                    $t === 'ts' ? $b->timestamp($c)->nullable() : $b->string($c)->nullable();
                });
            }
        }
    }

    private function find(string $token)
    {
        $this->ensureCols();

        return DB::table('letters')->where('accept_token', $token)
            ->where('is_template', 0)->where('letter_type', 'nda')->first();
    }

    private function renderBody($letter): array
    {
        $company = DB::table('companies')->where('id', $letter->company_id)->value('name') ?: 'the Company';
        $emp = DB::table('employees')->where('id', $letter->employee_id)->first() ?: (object) [];

        $tpl = null;
        if (! empty($letter->template_id)) {
            $tpl = DB::table('letters')->where('id', $letter->template_id)->where('is_template', 1)->first();
        }
        if (! $tpl) {
            $tpl = DB::table('letters')->where('is_template', 1)->where('letter_type', 'nda')
                ->when($letter->tenant_id, fn ($q) => $q->where('tenant_id', $letter->tenant_id))
                ->orderByDesc('id')->first();
        }
        $body = ($tpl && trim((string) $tpl->body)) ? $tpl->body
            : "CONFIDENTIALITY & NON-DISCLOSURE UNDERTAKING\n\nI, {{employee_name}} ({{emp_code}}), engaged as {{designation}} in {{department}} at {{company}}, will access confidential borrower and customer information and undertake to: keep it strictly confidential and use it only for authorised recovery work; never share, export or handle it via personal devices, email or messaging; comply with the RBI fair-practices / recovery-conduct norms and the Digital Personal Data Protection Act, 2023; return or securely destroy it and surrender all access on the end of my engagement; and accept that any breach may lead to disciplinary action and civil / criminal consequences.\n\nSigned on {{date}}.\n\n{{employee_name}}\nFor {{company}}";

        $date = $letter->issued_on ? Carbon::parse($letter->issued_on)->format('d M Y') : now()->format('d M Y');
        $body = strtr($body, [
            '{{employee_name}}' => $emp->name ?? ($emp->full_name ?? ''),
            '{{emp_code}}' => $emp->emp_code ?? '',
            '{{designation}}' => $emp->designation ?? '',
            '{{department}}' => $emp->department ?? '',
            '{{company}}' => $company,
            '{{date}}' => $date,
        ]);

        return [$company, $body];
    }

    public function show(string $token)
    {
        $letter = $this->find($token);
        if (! $letter) {
            abort(404, 'This signing link is invalid or has expired.');
        }
        [$company, $body] = $this->renderBody($letter);

        return view('nda-sign', [
            'letter' => $letter, 'company' => $company, 'body' => $body, 'token' => $token,
            'signed' => ($letter->status ?? '') === 'signed',
        ]);
    }

    public function sign(Request $request, string $token)
    {
        $letter = $this->find($token);
        if (! $letter) {
            abort(404);
        }
        if (($letter->status ?? '') !== 'signed') {
            DB::table('letters')->where('id', $letter->id)->update([
                'status' => 'signed',
                'accepted_at' => now(),
                'accepted_ip' => $request->ip(),
                'signed_name' => trim((string) $request->input('name')) ?: ($letter->signed_name ?? null),
                'updated_at' => now(),
            ]);
        }

        return redirect()->route('nda.show', $token)->with('justSigned', true);
    }
}
