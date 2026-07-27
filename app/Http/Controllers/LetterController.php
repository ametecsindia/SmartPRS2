<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Letter generation (rev 45c). An issued letter (letters.is_template = 0) is
 * turned into a PDF by merging the latest matching TEMPLATE's body (same
 * letter_type, is_template = 1) with the employee's real data via {{placeholder}}
 * substitution. Tenant-scoped, fail-soft.
 *
 * Supported placeholders: {{employee_name}} {{emp_code}} {{designation}}
 * {{department}} {{company}} {{company_address}} {{date}} {{ctc}} {{doj}}
 * {{pan}} {{uan}} {{bank_acc}} {{ifsc}} {{email}} {{mobile}}
 */
class LetterController extends Controller
{
    /** Stream the letter PDF inline (preview in the browser tab). */
    public function pdf(Request $request, int $id)
    {
        $b = $this->buildLetter($request, $id);
        if (isset($b['error'])) {
            return $b['error'];
        }

        return $b['pdf']->stream($b['file']);
    }

    /** Build the merged letter PDF + recipient. Returns ['pdf','email','name','file','type'] or ['error']. */
    private function buildLetter(Request $request, int $id): array
    {
        $tid = $request->user()->tenant_id;

        $letter = DB::table('letters as l')
            ->leftJoin('employees as e', 'e.id', '=', 'l.employee_id')
            ->leftJoin('companies as c', 'c.id', '=', 'l.company_id')
            ->where('l.id', $id)
            ->when($tid, fn ($q) => $q->where('l.tenant_id', $tid))
            ->where('l.is_template', 0)
            ->first(['l.*', 'e.emp_code', 'e.name as emp_name', 'e.designation_id', 'e.department_id',
                'e.doj', 'e.ctc', 'e.pan', 'e.uan', 'e.bank_acc', 'e.ifsc', 'e.email', 'e.mobile',
                'c.name as company_name', 'c.address as company_address']);

        if (! $letter) {
            return ['error' => response('Letter not found.', 404)->header('Content-Type', 'text/plain')];
        }

        // Prefer the template chosen on the letter; else the latest of that type.
        $tpl = null;
        if (! empty($letter->template_id)) {
            // rev165 SECURITY: scope the template to the caller's tenant so a crafted
            // template_id can't merge another tenant's letter body into the PDF.
            $tpl = DB::table('letters')->where('id', $letter->template_id)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('is_template', 1)->first();
        }
        if (! $tpl) {
            $tpl = DB::table('letters')
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))
                ->where('is_template', 1)
                ->where('letter_type', $letter->letter_type)
                ->orderByDesc('id')->first();
        }

        // NDA / confidentiality undertaking ships with a built-in default body, so HR
        // can issue one without first creating a template (still editable in Templates).
        if ((! $tpl || ! trim((string) $tpl->body)) && $letter->letter_type === 'nda') {
            $tpl = (object) [
                'id' => 0,
                'title' => 'Confidentiality & Non-Disclosure Undertaking',
                'body' => "CONFIDENTIALITY & NON-DISCLOSURE UNDERTAKING\n\n"
                    ."I, {{employee_name}} ({{emp_code}}), engaged as {{designation}} in {{department}} at {{company}}, acknowledge that in the course of my duties I will access confidential borrower and customer information, account and financial data, and other business-sensitive information.\n\n"
                    ."I undertake that I shall:\n"
                    ."1. Keep all borrower / customer personal and financial data strictly confidential and use it only for authorised recovery work for the assigned bank / NBFC portfolio.\n"
                    ."2. Not copy, export, photograph, forward or disclose such data to any third party, and not use personal devices, personal email, or personal messaging (e.g. WhatsApp) to handle it.\n"
                    ."3. Comply with the RBI fair-practices and recovery-conduct norms and the Digital Personal Data Protection Act, 2023.\n"
                    ."4. Return or securely destroy all such information, and surrender all access, on the end of my engagement.\n"
                    ."5. Understand that any breach may lead to disciplinary action and civil / criminal consequences.\n\n"
                    ."Signed on {{date}}.\n\n{{employee_name}}\nFor {{company}}",
            ];
        }

        if (! $tpl || ! trim((string) $tpl->body)) {
            return ['error' => response('No template (with a body) found for "'.$letter->letter_type.'" letters. '
                .'Create one in HR Letters → Templates first.', 422)->header('Content-Type', 'text/plain')];
        }

        $date = $letter->issued_on ? Carbon::parse($letter->issued_on)->format('d M Y') : now()->format('d M Y');

        // An OFFER letter goes to a CANDIDATE (in Recruitment), not an employee.
        // Other letter types are for existing employees.
        if (! empty($letter->candidate)) {
            // The recruitment table self-creates on first use; an offer letter may
            // be generated before it exists, so guard the lookup (fail-soft to null).
            $cand = Schema::hasTable('recruitment')
                ? DB::table('recruitment')->where('name', $letter->candidate)
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first()
                : null;
            $personName = $letter->candidate;
            $personCode = '';
            $designation = $cand->position ?? '';
            $department = '';
            $ctc = ($cand && $cand->offered_ctc) ? '₹'.number_format((float) $cand->offered_ctc) : '';
            $doj = ($cand && $cand->offer_doj) ? Carbon::parse($cand->offer_doj)->format('d M Y') : '';
            $incentive = ($cand && $cand->offer_incentive) ? '₹'.number_format((float) $cand->offer_incentive) : '';
            $joiningBonus = ($cand && $cand->joining_bonus) ? '₹'.number_format((float) $cand->joining_bonus) : '';
            $pan = '';
            $uan = '';
            $bankAcc = '';
            $ifsc = '';
            $email = $cand->email ?? '';
            $mobile = $cand->mobile ?? '';
        } else {
            $personName = $letter->emp_name ?? '';
            $personCode = $letter->emp_code ?? '';
            $designation = $letter->designation_id ? DB::table('designations')->where('id', $letter->designation_id)->value('name') : '';
            $department = $letter->department_id ? DB::table('departments')->where('id', $letter->department_id)->value('name') : '';
            $ctc = $letter->ctc ? '₹'.number_format((float) $letter->ctc) : '';
            $doj = $letter->doj ? Carbon::parse($letter->doj)->format('d M Y') : '';
            $incentive = '';
            $joiningBonus = '';
            $pan = $letter->pan ?? '';
            $uan = $letter->uan ?? '';
            $bankAcc = $letter->bank_acc ?? '';
            $ifsc = $letter->ifsc ?? '';
            $email = $letter->email ?? '';
            $mobile = $letter->mobile ?? '';
        }

        // H4 — grievance-officer details (per company) auto-merge into every letter.
        $grievOfficer = '';
        $grievPhone = '';
        $grievEmail = '';
        if (Schema::hasColumn('companies', 'grievance_officer')) {
            $gc = DB::table('companies')->where('id', $letter->company_id)
                ->first(['grievance_officer', 'grievance_phone', 'grievance_email']);
            if ($gc) {
                $grievOfficer = $gc->grievance_officer ?? '';
                $grievPhone = $gc->grievance_phone ?? '';
                $grievEmail = $gc->grievance_email ?? '';
            }
        }
        // H1 — lawful borrower-contact window for {{contact_hours}}.
        $contactHours = '08:00–19:00';
        try {
            $cr = \App\Http\Controllers\SettingsController::rates($letter->tenant_id);
            $contactHours = ($cr['contact_window_start'] ?? '08:00').'–'.($cr['contact_window_end'] ?? '19:00');
        } catch (\Throwable $e) {
        }

        $repl = [
            '{{employee_name}}' => $personName,
            '{{candidate_name}}' => $personName,
            '{{emp_code}}' => $personCode,
            '{{designation}}' => $designation ?: '',
            '{{department}}' => $department ?: '',
            '{{company}}' => $letter->company_name ?? '',
            '{{company_address}}' => $letter->company_address ?? '',
            '{{date}}' => $date,
            '{{ctc}}' => $ctc,
            '{{incentive}}' => $incentive,
            '{{joining_bonus}}' => $joiningBonus,
            '{{doj}}' => $doj,
            '{{pan}}' => $pan,
            '{{uan}}' => $uan,
            '{{bank_acc}}' => $bankAcc,
            '{{ifsc}}' => $ifsc,
            '{{email}}' => $email,
            '{{mobile}}' => $mobile,
            '{{grievance_officer}}' => $grievOfficer,
            '{{grievance_phone}}' => $grievPhone,
            '{{grievance_email}}' => $grievEmail,
            '{{contact_hours}}' => $contactHours,
        ];
        $body = strtr((string) $tpl->body, $repl);

        $brand = [];
        try {
            $brand = ConfigController::brandFor($letter->tenant_id, $letter->company_id);
        } catch (\Throwable $e) {
            $brand = [];
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('letter-pdf', [
            'title' => $tpl->title ?: (ucfirst((string) $letter->letter_type).' Letter'),
            'body' => $body,
            'brand' => $brand,
            'company' => $letter->company_name,
            'companyAddress' => $letter->company_address,
            'date' => $date,
            'empName' => $personName,
            'empCode' => $personCode,
        ]);

        return [
            'pdf' => $pdf,
            'email' => $email,
            'name' => $personName,
            'file' => 'letter-'.($personCode ?: $id).'-'.$letter->letter_type.'.pdf',
            'type' => $letter->letter_type,
            'company' => $letter->company_name,
            'tenant_id' => $letter->tenant_id,
            'company_id' => $letter->company_id,
        ];
    }

    /** Email the generated letter (as a PDF attachment) to the recipient. */
    public function email(Request $request, int $id)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $b = $this->buildLetter($request, $id);
            if (isset($b['error'])) {
                $msg = method_exists($b['error'], 'getContent') ? $b['error']->getContent() : 'Could not build the letter.';

                return response()->json(['ok' => false, 'error' => $msg], 422);
            }
            if (empty($b['email'])) {
                return response()->json(['ok' => false, 'error' => 'No email address on file for the recipient.'], 422);
            }

            // Apply the per-company SMTP at runtime (same as MailService).
            $m = ConfigController::mailConfigFor($b['tenant_id'], $b['company_id']);
            if (empty($m['host']) || empty($m['from_address'])) {
                return response()->json(['ok' => false, 'error' => 'No SMTP configured. Set it in Settings → Email/SMTP, then retry.'], 422);
            }
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => $m['host'],
                'mail.mailers.smtp.port' => (int) $m['port'],
                'mail.mailers.smtp.username' => $m['username'] ?: null,
                'mail.mailers.smtp.password' => $m['password'] ?: null,
                'mail.mailers.smtp.encryption' => $m['encryption'] === 'none' ? null : $m['encryption'],
                'mail.from.address' => $m['from_address'],
                'mail.from.name' => $m['from_name'] ?: 'SmartPRS',
            ]);

            $pdfData = $b['pdf']->output();
            $subject = ucfirst((string) $b['type']).' Letter — '.($b['company'] ?: 'SmartPRS');
            \Illuminate\Support\Facades\Mail::send([], [], function ($mail) use ($b, $pdfData, $subject) {
                $mail->to($b['email'], $b['name'])->subject($subject)
                    ->html('<p>Dear '.e($b['name']).',</p><p>Please find your '.e($b['type']).' letter attached.</p><p>Regards,<br>'.e($b['company'] ?: 'HR').'</p>')
                    ->attachData($pdfData, $b['file'], ['mime' => 'application/pdf']);
            });

            return response()->json(['ok' => true, 'message' => 'Letter emailed to '.$b['email'].'.']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Printable employee ID card PDF (embeds the photo if uploaded). */
    public function idcard(Request $request, string $code)
    {
        $tid = $request->user()->tenant_id;
        $e = DB::table('employees as e')
            ->leftJoin('companies as c', 'c.id', '=', 'e.company_id')
            ->leftJoin('designations as d', 'd.id', '=', 'e.designation_id')
            ->leftJoin('departments as dep', 'dep.id', '=', 'e.department_id')
            ->where('e.emp_code', $code)
            ->when($tid, fn ($q) => $q->where('e.tenant_id', $tid))
            ->whereNull('e.deleted_at')
            ->first(['e.*', 'c.name as company_name', 'd.name as designation_name', 'dep.name as department_name']);
        if (! $e) {
            return response('Employee "'.$code.'" not found.', 404)->header('Content-Type', 'text/plain');
        }
        $brand = [];
        try {
            $brand = ConfigController::brandFor($e->tenant_id, $e->company_id);
        } catch (\Throwable $x) {
            $brand = [];
        }

        // Embed the photo as a base64 data URI (reliable in dompdf).
        $photo = null;
        $pp = $e->photo_path ?? null;
        if ($pp) {
            $full = storage_path('app/public/'.$pp);
            if (is_file($full)) {
                $photo = 'data:'.(function_exists('mime_content_type') ? mime_content_type($full) : 'image/jpeg').';base64,'.base64_encode(file_get_contents($full));
            }
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('idcard-pdf', [
            'e' => $e, 'brand' => $brand,
            'company' => $e->company_name,
            'designation' => $e->designation_name,
            'department' => $e->department_name,
            'photo' => $photo,
        ]);

        // Desktop opens this in a new tab for preview (inline). Mobile browsers and
        // the app webview can't reliably render an inline PDF in a popup tab, so when
        // the client asks with ?dl=1 we force a real download (Content-Disposition:
        // attachment) which works on phones. rev 122.
        $fname = 'idcard-'.$code.'.pdf';
        if ($request->boolean('dl')) {
            return $pdf->download($fname);
        }
        return $pdf->stream($fname);
    }

    /** Upload/replace an employee photo (used by the ID card + directory). */
    public function uploadPhoto(Request $request, string $code)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $request->validate(['photo' => ['required', 'image', 'max:5120']]);
            $tid = $request->user()->tenant_id;
            if (! Schema::hasColumn('employees', 'photo_path')) {
                Schema::table('employees', function (Blueprint $t) {
                    $t->string('photo_path')->nullable();
                });
            }
            $e = DB::table('employees')->where('emp_code', $code)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')->first();
            if (! $e) {
                return response()->json(['ok' => false, 'error' => 'Employee not found'], 404);
            }
            $path = $request->file('photo')->store('emp-photos', 'public');
            DB::table('employees')->where('id', $e->id)->update(['photo_path' => $path, 'updated_at' => now()]);

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Email a candidate a public offer-acceptance link (tokenised). */
    public function sendAcceptLink(Request $request, int $id)
    {
        if ($deny = ApprovalService::denyUnlessRole($request, ['admin', 'hr_manager'])) {
            return $deny;
        }
        try {
            $tid = $request->user()->tenant_id;
            $letter = DB::table('letters')->where('id', $id)->where('is_template', 0)
                ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
            if (! $letter) {
                return response()->json(['ok' => false, 'error' => 'Letter not found'], 404);
            }

            // NDA / confidentiality undertaking goes to the EMPLOYEE (agent); an offer
            // letter goes to the CANDIDATE in Recruitment.
            $isNda = ($letter->letter_type ?? '') === 'nda';
            if ($isNda) {
                $emp = DB::table('employees')->where('id', $letter->employee_id)
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
                $to = $emp->email ?? null;
                $toName = $emp->name ?? ($emp->full_name ?? 'Agent');
                if (! $to) {
                    return response()->json(['ok' => false, 'error' => 'This agent has no email on their employee record.'], 422);
                }
            } else {
                if (empty($letter->candidate)) {
                    return response()->json(['ok' => false, 'error' => 'This offer has no candidate.'], 422);
                }
                $cand = DB::table('recruitment')->where('name', $letter->candidate)
                    ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->first();
                $to = $cand->email ?? null;
                $toName = $letter->candidate;
                if (! $to) {
                    return response()->json(['ok' => false, 'error' => 'The candidate has no email in Recruitment.'], 422);
                }
            }

            foreach (['accept_token' => 'string', 'accepted_at' => 'ts', 'accepted_ip' => 'string', 'signed_name' => 'string'] as $c => $t) {
                if (! Schema::hasColumn('letters', $c)) {
                    Schema::table('letters', function (Blueprint $b) use ($c, $t) {
                        $t === 'ts' ? $b->timestamp($c)->nullable() : $b->string($c)->nullable();
                    });
                }
            }
            $token = $letter->accept_token ?: \Illuminate\Support\Str::random(40);
            DB::table('letters')->where('id', $id)->update(['accept_token' => $token, 'updated_at' => now()]);
            $company = DB::table('companies')->where('id', $letter->company_id)->value('name');

            if ($isNda) {
                $url = url('/nda/'.$token);
                \App\Services\MailService::queue([
                    'tenant_id' => $letter->tenant_id, 'company_id' => $letter->company_id,
                    'to' => $to, 'to_name' => $toName,
                    'subject' => 'Confidentiality undertaking to e-sign — '.($company ?: 'SmartPRS'),
                    'heading' => 'Please review & e-sign',
                    'intro' => 'Dear '.$toName.', please review the confidentiality / non-disclosure undertaking for '.($company ?: 'your engagement').' and confirm your e-signature using the button below.',
                    'cta_label' => 'Review & e-sign', 'cta_url' => $url,
                    'kind' => 'nda.link',
                ]);
            } else {
                $url = url('/offer/'.$token);
                \App\Services\MailService::queue([
                    'tenant_id' => $letter->tenant_id, 'company_id' => $letter->company_id,
                    'to' => $to, 'to_name' => $toName,
                    'subject' => 'Your offer from '.($company ?: 'us'),
                    'heading' => 'You have an offer!',
                    'intro' => 'Dear '.$toName.', '.($company ?: 'We').' is pleased to extend an offer to you. Please review the details and confirm your acceptance using the button below.',
                    'cta_label' => 'View & Accept Offer', 'cta_url' => $url,
                    'kind' => 'offer.link',
                ]);
            }

            return response()->json(['ok' => true, 'message' => 'Link emailed to '.$to.'.', 'link' => $url]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Serve an employee photo (avatar/thumbnail) — no symlink needed. */
    public function servePhoto(Request $request, string $code)
    {
        $tid = $request->user()->tenant_id;
        $pp = DB::table('employees')->where('emp_code', $code)
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->whereNull('deleted_at')->value('photo_path');
        $full = $pp ? storage_path('app/public/'.$pp) : null;
        if (! $full || ! is_file($full)) {
            return response('', 404);
        }

        return response(file_get_contents($full), 200, [
            'Content-Type' => (function_exists('mime_content_type') ? mime_content_type($full) : 'image/jpeg'),
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /** Self-service: the logged-in employee sets their OWN photo (no role guard). */
    public function uploadMyPhoto(Request $request)
    {
        try {
            $request->validate(['photo' => ['required', 'image', 'max:5120']]);
            $user = $request->user();
            $empId = $user->employee_id ?? null;
            if (! $empId) {
                $empId = DB::table('employees')
                    ->when($user->tenant_id, fn ($q) => $q->where('tenant_id', $user->tenant_id))
                    ->where(function ($q) use ($user) {
                        $q->where('email', $user->email)->orWhere('name', $user->name);
                    })->whereNull('deleted_at')->value('id');
            }
            $path = $request->file('photo')->store('emp-photos', 'public');
            if ($empId) {
                // Linked to an employee — store on the employee record (shows on ID card etc.).
                if (! Schema::hasColumn('employees', 'photo_path')) {
                    Schema::table('employees', function (Blueprint $t) {
                        $t->string('photo_path')->nullable();
                    });
                }
                DB::table('employees')->where('id', $empId)->update(['photo_path' => $path, 'updated_at' => now()]);
            } else {
                // No employee record (e.g. an admin / MD login) — keep it as the user's own profile photo.
                if (! Schema::hasColumn('users', 'photo_path')) {
                    Schema::table('users', function (Blueprint $t) {
                        $t->string('photo_path')->nullable();
                    });
                }
                DB::table('users')->where('id', $user->id)->update(['photo_path' => $path, 'updated_at' => now()]);
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Serve the logged-in user's own photo: employee photo if linked, else profile photo. */
    public function myPhoto(Request $request)
    {
        $user = $request->user();
        $pp = null;
        $empId = $user->employee_id ?? null;
        if (! $empId) {
            $empId = DB::table('employees')
                ->when($user->tenant_id, fn ($q) => $q->where('tenant_id', $user->tenant_id))
                ->where(function ($q) use ($user) {
                    $q->where('email', $user->email)->orWhere('name', $user->name);
                })->whereNull('deleted_at')->value('id');
        }
        if ($empId) {
            $pp = DB::table('employees')->where('id', $empId)->value('photo_path');
        }
        if (! $pp && Schema::hasColumn('users', 'photo_path')) {
            $pp = DB::table('users')->where('id', $user->id)->value('photo_path');
        }
        $full = $pp ? storage_path('app/public/'.$pp) : null;
        if (! $full || ! is_file($full)) {
            return response('', 404);
        }

        return response(file_get_contents($full), 200, [
            'Content-Type' => (function_exists('mime_content_type') ? mime_content_type($full) : 'image/jpeg'),
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
