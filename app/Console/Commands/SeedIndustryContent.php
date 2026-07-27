<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds expert, India-compliant Collections & Recovery content into the live
 * tables so the Training Programs, Training Content, FAQs and Knowledge Base
 * (incl. Code of Conduct) screens are populated permanently with detailed,
 * multi-paragraph material.
 *
 *   php artisan content:seed-industry              # all non-deleted tenants + global KB
 *   php artisan content:seed-industry --tenant=2   # one tenant only
 *
 * Idempotent: rows already present (matched by natural key) are skipped, so it
 * is safe to re-run. Also exposed as static seedForTenant()/seedGlobalKb() so
 * new-tenant provisioning auto-installs this as an editable starter template.
 *
 * Grounded in the RBI Fair Practices Code, RBI recovery-agent norms, IIBF DRA
 * certification, the DPDP Act 2023 and the legal toolkit (Sec 138 NI Act,
 * SARFAESI, DRT, Lok Adalat). Educational material, not legal advice.
 */
class SeedIndustryContent extends Command
{
    protected $signature = 'content:seed-industry {--tenant= : Limit to one tenant_id}';

    protected $description = 'Seed detailed Collections & Recovery content (Training, FAQs, Knowledge Base)';

    public function handle(): int
    {
        self::ensureTables();

        $tenants = $this->option('tenant') !== null && $this->option('tenant') !== ''
            ? [(int) $this->option('tenant')]
            : DB::table('tenants')->whereNull('deleted_at')->pluck('id')->all();
        if (empty($tenants)) {
            $tenants = [null];
        }

        $tp = 0;
        $ts = 0;
        $fq = 0;
        foreach ($tenants as $tid) {
            $r = self::seedForTenant($tid);
            $tp += $r['programs'];
            $ts += $r['subjects'];
            $fq += $r['faqs'];
        }
        $kb = self::seedGlobalKb();

        $this->info("Seeded - Training Programs: {$tp}, Training Content: {$ts}, FAQs: {$fq}, KB articles: {$kb}.");
        $this->line('Tenants covered: '.implode(', ', array_map(fn ($t) => $t === null ? 'platform' : $t, $tenants)));

        return self::SUCCESS;
    }

    public static function seedForTenant(?int $tid): array
    {
        self::ensureTables();
        $programs = self::seedPrograms($tid);
        $subjects = self::insertMissingSubjects($tid);
        $faqs = self::insertMissing('faqs', $tid, 'question', self::faqRows($tid));
        self::seedGlobalKb();
        $offer = self::seedOfferTemplate($tid) ? 1 : 0;

        return ['programs' => $programs, 'subjects' => $subjects, 'faqs' => $faqs, 'offer_template' => $offer];
    }

    /** Seed a ready-to-use OFFER-LETTER template (with offer placeholders) if none exists. */
    public static function seedOfferTemplate(?int $tid): bool
    {
        self::ensureLettersTable();
        // If we still can't have a template column, there can be no template to find
        // and no safe column to insert — skip rather than crash.
        if (! Schema::hasTable('letters')
            || ! Schema::hasColumn('letters', 'is_template')
            || ! Schema::hasColumn('letters', 'letter_type')) {
            return false;
        }
        $exists = DB::table('letters')->where('is_template', 1)->where('letter_type', 'offer')
            ->when($tid, fn ($q) => $q->where('tenant_id', $tid))->exists();
        if ($exists) {
            return false;
        }
        $body = <<<'HTML'
<p>Date: {{date}}</p>
<p>Dear {{candidate_name}},</p>
<p>We are pleased to offer you the position of <strong>{{designation}}</strong> at {{company}}. Based on our discussions and your interview, we are confident your skills and experience will be a valuable addition to our team.</p>
<p>The key terms of your offer are as follows:</p>
<ul>
<li><strong>Designation:</strong> {{designation}}</li>
<li><strong>Annual CTC:</strong> {{ctc}}</li>
<li><strong>Performance Incentive / Variable Pay:</strong> {{incentive}}</li>
<li><strong>Joining Bonus:</strong> {{joining_bonus}}</li>
<li><strong>Date of Joining:</strong> {{doj}}</li>
</ul>
<p>This offer is subject to satisfactory background verification and submission of the required documents. The detailed terms and conditions of employment will be provided in your appointment letter at the time of joining.</p>
<p>We look forward to welcoming you on board. Kindly confirm your acceptance using the link provided in the accompanying email.</p>
<p>Warm regards,<br>HR Department<br>{{company}}</p>
HTML;
        $row = [
            'tenant_id' => $tid, 'letter_type' => 'offer', 'is_template' => 1,
            'title' => 'Standard Offer Letter', 'body' => $body, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ];
        $clean = [];
        foreach ($row as $k => $v) {
            if (Schema::hasColumn('letters', $k)) {
                $clean[$k] = $v;
            }
        }

        // Fill any NOT-NULL / no-default legacy columns the app added to `letters`
        // (e.g. company_id) with safe placeholders, same as the other seeders.
        DB::table('letters')->insert($clean + self::requiredDefaults('letters', $clean));

        return true;
    }

    // ---- table self-heal ----------------------------------------------------

    /**
     * Make the `letters` table safe for the offer-template seed. On a fresh DB the
     * table is auto-created elsewhere with a minimal schema (no is_template /
     * letter_type), so the template existence-check used to crash. Create it if
     * missing and add the columns the seed needs. Additive + fail-soft.
     */
    private static function ensureLettersTable(): void
    {
        if (! Schema::hasTable('letters')) {
            try {
                Schema::create('letters', function (Blueprint $t) {
                    $t->id();
                    $t->unsignedBigInteger('tenant_id')->nullable()->index();
                    $t->unsignedBigInteger('employee_id')->nullable();
                    $t->string('letter_type')->nullable();
                    $t->boolean('is_template')->default(false);
                    $t->string('title')->nullable();
                    $t->longText('body')->nullable();
                    $t->string('status')->default('active');
                    $t->timestamps();
                });
            } catch (\Throwable $e) {
            }
        }
        if (! Schema::hasTable('letters')) {
            return;
        }
        $cols = [
            'tenant_id' => 'bigint', 'employee_id' => 'bigint', 'letter_type' => 'string',
            'is_template' => 'bool', 'title' => 'string', 'body' => 'text', 'status' => 'string',
        ];
        foreach ($cols as $col => $type) {
            if (Schema::hasColumn('letters', $col)) {
                continue;
            }
            try {
                Schema::table('letters', function (Blueprint $t) use ($col, $type) {
                    switch ($type) {
                        case 'bigint': $t->unsignedBigInteger($col)->nullable(); break;
                        case 'bool': $t->boolean($col)->default(false); break;
                        case 'text': $t->longText($col)->nullable(); break;
                        default: $t->string($col)->nullable();
                    }
                });
            } catch (\Throwable $e) {
            }
        }
    }

    private static function ensureTables(): void
    {
        if (! Schema::hasTable('training_programs')) {
            Schema::create('training_programs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->string('name')->nullable();
                $t->string('category')->nullable();
                $t->string('mode')->nullable();
                $t->boolean('mandatory')->default(false);
                $t->string('validity')->nullable();
                $t->text('description')->nullable();
                $t->string('status')->default('Active');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('training_subjects')) {
            Schema::create('training_subjects', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->string('program')->nullable();
                $t->string('module')->nullable();
                $t->string('subject')->nullable();
                $t->decimal('hours', 5, 1)->default(0);
                $t->text('content')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('faqs')) {
            Schema::create('faqs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->string('category')->nullable();
                $t->string('question', 500)->nullable();
                $t->text('answer')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('kb_topics')) {
            Schema::create('kb_topics', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->string('category')->default('General');
                $t->string('icon')->default('fa-book-open');
                $t->string('title');
                $t->text('body')->nullable();
                $t->json('roles')->nullable();
                $t->integer('sort')->default(0);
                $t->timestamps();
            });
        }

        // Add any columns a legacy auto-created table is missing (notably tenant_id
        // and training_programs.description).
        $needed = [
            'training_programs' => ['tenant_id' => 'bigint', 'name' => 'string', 'category' => 'string', 'mode' => 'string', 'mandatory' => 'bool', 'validity' => 'string', 'description' => 'text', 'status' => 'string'],
            'training_subjects' => ['tenant_id' => 'bigint', 'program' => 'string', 'module' => 'string', 'subject' => 'string', 'hours' => 'decimal', 'content' => 'text'],
            'faqs' => ['tenant_id' => 'bigint', 'category' => 'string', 'question' => 'string', 'answer' => 'text'],
            'kb_topics' => ['tenant_id' => 'bigint', 'category' => 'string', 'icon' => 'string', 'title' => 'string', 'body' => 'text', 'roles' => 'json', 'sort' => 'int'],
        ];
        foreach ($needed as $table => $defs) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($defs as $col => $type) {
                if (Schema::hasColumn($table, $col)) {
                    continue;
                }
                try {
                    Schema::table($table, function (Blueprint $t) use ($col, $type) {
                        switch ($type) {
                            case 'bigint': $t->unsignedBigInteger($col)->nullable()->index(); break;
                            case 'bool': $t->boolean($col)->default(false); break;
                            case 'decimal': $t->decimal($col, 5, 1)->default(0); break;
                            case 'int': $t->integer($col)->default(0); break;
                            case 'text': $t->text($col)->nullable(); break;
                            case 'json': $t->json($col)->nullable(); break;
                            default: $t->string($col)->nullable();
                        }
                    });
                } catch (\Throwable $e) {
                }
            }
        }

        if (Schema::hasTable('faqs')) {
            try {
                Schema::table('faqs', fn (Blueprint $t) => $t->text('answer')->nullable()->change());
            } catch (\Throwable $e) {
            }
        }
        if (Schema::hasTable('training_subjects')) {
            try {
                Schema::table('training_subjects', fn (Blueprint $t) => $t->text('content')->nullable()->change());
            } catch (\Throwable $e) {
            }
        }
    }

    private static function scoped(string $table, ?int $tid)
    {
        $q = DB::table($table);

        return $tid === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tid);
    }

    /** Placeholder values for any NOT-NULL / no-default legacy columns we do not set. */
    private static function requiredDefaults(string $table, array $have): array
    {
        $extra = [];
        try {
            foreach (DB::select('SHOW COLUMNS FROM `'.$table.'`') as $c) {
                $field = $c->Field;
                if (array_key_exists($field, $have)) {
                    continue;
                }
                if (strtoupper((string) $c->Null) === 'YES') {
                    continue;
                }
                if ($c->Default !== null) {
                    continue;
                }
                if (stripos((string) $c->Extra, 'auto_increment') !== false) {
                    continue;
                }
                $type = strtolower((string) $c->Type);
                if (preg_match('/int|decimal|double|float|bit|year/', $type)) {
                    $extra[$field] = 0;
                } elseif (preg_match('/date|time/', $type)) {
                    $extra[$field] = now();
                } else {
                    $extra[$field] = '';
                }
            }
        } catch (\Throwable $e) {
        }

        return $extra;
    }

    private static function insertMissing(string $table, ?int $tid, string $keyCol, array $rows): int
    {
        $n = 0;
        foreach ($rows as $row) {
            if (self::scoped($table, $tid)->where($keyCol, $row[$keyCol])->exists()) {
                continue;
            }
            $full = $row + ['created_at' => now(), 'updated_at' => now()];
            DB::table($table)->insert($full + self::requiredDefaults($table, $full));
            $n++;
        }

        return $n;
    }

    private static function insertMissingSubjects(?int $tid): int
    {
        $n = 0;
        foreach (self::subjects() as $s) {
            if (self::scoped('training_subjects', $tid)->where('program', $s[0])->where('subject', $s[2])->exists()) {
                continue;
            }
            $row = [
                'tenant_id' => $tid, 'program' => $s[0], 'module' => $s[1], 'subject' => $s[2],
                'hours' => $s[3], 'content' => $s[4], 'created_at' => now(), 'updated_at' => now(),
            ];
            DB::table('training_subjects')->insert($row + self::requiredDefaults('training_subjects', $row));
            $n++;
        }

        return $n;
    }

    // ---- Training Programs (with description / objectives) ------------------

    private static function seedPrograms(?int $tid): int
    {
        $n = 0;
        $hasDesc = Schema::hasColumn('training_programs', 'description');
        foreach (self::programs() as $p) {
            $existing = self::scoped('training_programs', $tid)->where('name', $p[0])->first();
            if ($existing) {
                // Backfill the description if the row was seeded before the column
                // existed and is still empty (never overwrites an admin edit).
                if ($hasDesc && empty($existing->description)) {
                    DB::table('training_programs')->where('id', $existing->id)
                        ->update(['description' => $p[5], 'updated_at' => now()]);
                }
                continue;
            }
            $row = [
                'tenant_id' => $tid, 'name' => $p[0], 'category' => $p[1], 'mode' => $p[2],
                'mandatory' => $p[3], 'validity' => $p[4], 'status' => 'Active',
                'created_at' => now(), 'updated_at' => now(),
            ];
            if ($hasDesc) {
                $row['description'] = $p[5];
            }
            DB::table('training_programs')->insert($row + self::requiredDefaults('training_programs', $row));
            $n++;
        }

        return $n;
    }

    private static function programs(): array
    {
        $nl = "\n";

        // [name, category, mode, mandatory(bool), validity, description]
        return [
            ['RBI Fair Practices Code & Recovery Compliance', 'Compliance', 'Classroom + Online', 1, 'Annual refresher',
                'Purpose: ensure every collections and recovery staff member operates strictly within the RBI Fair Practices Code and the lender\'s board-approved recovery policy.'.$nl.$nl.
                'What it covers: permissible contact hours and channels, the list of prohibited and harassing practices, borrower privacy and the rule against third-party disclosure, identity disclosure, and the borrower grievance-redressal mechanism.'.$nl.$nl.
                'Who must attend: all tele-callers, field officers, team leaders and managers who contact borrowers or supervise those who do. It is mandatory before an agent is allotted live accounts, with an annual refresher.'.$nl.$nl.
                'Outcomes: by the end, a participant can describe the rules that bind every contact, recognise a compliance breach in real time, and route a grievance correctly. Assessment is a short pass/fail test; the certificate is valid for one year.'],
            ['IIBF DRA Certification Preparation', 'Certification', 'Online', 1, 'One-time (lifetime)',
                'Purpose: prepare recovery staff for the IIBF Debt Recovery Agent (DRA) certificate, which RBI mandates before an agent collects on behalf of a regulated lender.'.$nl.$nl.
                'What it covers: the lending and recovery cycle, the role and responsibilities of a recovery agent, business communication and behavioural/soft skills, the rural and financial-inclusion context, and the legal foundations of recovery.'.$nl.$nl.
                'Training hours: graduates complete 50 hours and undergraduates 100 hours of training before sitting the IIBF examination; banks and NBFCs must ensure agents are certified within one year of engagement.'.$nl.$nl.
                'Outcomes: a prepared candidate who can pass the IIBF exam and hold a valid DRA certificate. The certification is effectively lifetime, but the conduct refreshers in the other programs still apply annually.'],
            ['Collections Process & Bucket Management', 'Process', 'Online', 1, 'Annual refresher',
                'Purpose: build a disciplined, metric-driven collections routine so accounts are worked in the right order and do not roll forward into deeper delinquency.'.$nl.$nl.
                'What it covers: Days-Past-Due (DPD) buckets, roll-rate and roll-forward, prioritisation by balance and risk, Promise-to-Pay (PTP) capture and follow-up, and segmenting borrowers by intent versus ability to pay.'.$nl.$nl.
                'Who must attend: all collections staff and their supervisors. It is the operating backbone that the conduct and skills programs sit on top of.'.$nl.$nl.
                'Outcomes: a participant can read a bucket report, plan a day around fresh roll-ins and broken PTPs, and choose the right treatment (reminder, restructure, settlement or escalation) for each account.'],
            ['Tele-calling Etiquette & Right-Party Contact', 'Skills', 'Online', 1, 'Annual refresher',
                'Purpose: raise the quality and compliance of every outbound and inbound recovery call.'.$nl.$nl.
                'What it covers: the mandatory opening and identity disclosure, confirming Right-Party Contact (RPC) versus Third-Party Contact (TPC), tone and scripting, objection handling, de-escalation, and accurate disposition/logging of each call.'.$nl.$nl.
                'Who must attend: all tele-calling and call-centre staff, and the team leaders who monitor calls.'.$nl.$nl.
                'Outcomes: a caller who opens correctly, never discloses the debt to the wrong person, drives every call to a concrete next step, and records the outcome cleanly for the next action.'],
            ['Negotiation & Settlement Techniques', 'Skills', 'Classroom', 0, 'Annual refresher',
                'Purpose: convert willingness-to-engage into recovered rupees through structured, authorised negotiation.'.$nl.$nl.
                'What it covers: reading intent versus ability, the order of offers (pay-in-full, dated part-payment, one-time settlement), anchoring and concession discipline, and the absolute rule that settlements and waivers are confirmed only in writing by an authorised officer.'.$nl.$nl.
                'Who should attend: experienced collectors, settlement desks and team leaders handling deeper buckets.'.$nl.$nl.
                'Outcomes: a negotiator who maximises recovery within authority, documents every commitment, and never exposes the company to a complaint by promising something they cannot grant.'],
            ['Field Collections, Visit Protocol & Repossession Conduct', 'Field Operations', 'Classroom', 1, 'Annual refresher',
                'Purpose: make field visits safe, compliant and effective, and ensure any repossession is lawful and dignified.'.$nl.$nl.
                'What it covers: pre-visit planning within permitted hours, carrying and displaying authorisation and ID, conduct at the doorstep, privacy in front of family and neighbours, handling vulnerable situations, and the repossession code for secured assets (notice, inventory, no force).'.$nl.$nl.
                'Who must attend: all field officers, field-collection executives and their supervisors. Mandatory before field allotment, with an annual refresher.'.$nl.$nl.
                'Outcomes: a field officer who plans a compliant beat, conducts a respectful visit, protects borrower privacy, and knows exactly where lawful action ends.'],
            ['Customer Grievance Handling & De-escalation', 'Skills', 'Online', 1, 'Annual refresher',
                'Purpose: resolve disputes early and protect the lender from escalations and regulatory complaints.'.$nl.$nl.
                'What it covers: listening and acknowledgement, accurate logging, issuing a reference, routing to the Grievance Redressal Officer, the rule that recovery pauses on a disputed account, and verbal de-escalation techniques.'.$nl.$nl.
                'Who must attend: everyone who interacts with borrowers, plus the grievance and quality teams.'.$nl.$nl.
                'Outcomes: staff who treat grievances as genuine, defuse anger calmly, and follow the pause-and-route rule so disputes are closed before they reach the Ombudsman.'],
            ['Legal Recovery Toolkit (Sec 138, SARFAESI, DRT, Lok Adalat)', 'Legal', 'Online', 0, 'Every 2 years',
                'Purpose: give collections staff the legal literacy to know which recovery route applies and to support it correctly.'.$nl.$nl.
                'What it covers: the Section 138 NI Act process for dishonoured cheques (notice timelines and penalties), the SARFAESI Act for secured assets (section 13(2) notice and 13(4) possession), the Debt Recovery Tribunal route, and amicable closure through Lok Adalat.'.$nl.$nl.
                'Who should attend: senior collectors, legal-coordination staff and managers who decide escalation.'.$nl.$nl.
                'Outcomes: staff who can prepare a clean documentary trail, recognise when an account is ready for legal action, and understand that these are lender-led steps they support rather than execute.'],
            ['Data Privacy & DPDP Act 2023 for Collections', 'Compliance', 'Online', 1, 'Annual refresher',
                'Purpose: protect borrower data and keep the company compliant with the Digital Personal Data Protection Act 2023 and RBI data-sharing norms.'.$nl.$nl.
                'What it covers: need-to-know data sharing and purpose limitation, secure handling within approved systems only, call-recording disclosure, retention limits, and the prohibition on copying or forwarding borrower data via personal devices, WhatsApp or email.'.$nl.$nl.
                'Who must attend: everyone who can see borrower data, including tele-callers, field staff and back-office.'.$nl.$nl.
                'Outcomes: staff who handle only the minimum data needed, keep it in the company system, and understand that a data breach carries penalties for the company and disciplinary action for the individual.'],
            ['KYC & Anti-Money Laundering (AML) Awareness', 'Compliance', 'Online', 1, 'Annual refresher',
                'Purpose: ensure staff recognise and report suspicious activity and understand basic KYC obligations relevant to collections and settlements.'.$nl.$nl.
                'What it covers: the purpose of KYC, red flags in repayment patterns and settlement requests, the prohibition on accepting unexplained cash, and the internal reporting route for anything suspicious.'.$nl.$nl.
                'Who must attend: collections, settlement and cash-handling staff.'.$nl.$nl.
                'Outcomes: staff who follow approved payment channels, never facilitate structuring or unexplained cash, and escalate red flags promptly.'],
            ['POSH & Workplace Conduct', 'HR / Compliance', 'Online', 1, 'Annual refresher',
                'Purpose: maintain a safe, respectful workplace and meet the legal requirement for awareness under the Prevention of Sexual Harassment (POSH) Act.'.$nl.$nl.
                'What it covers: what constitutes harassment, the role of the Internal Committee, how to raise a complaint, the protection against retaliation, and the standards of professional conduct expected of every employee.'.$nl.$nl.
                'Who must attend: all employees, with an annual refresher.'.$nl.$nl.
                'Outcomes: employees who know their rights and responsibilities, the reporting channel, and the behaviour expected of them at work and in the field.'],
            ['Ethical Skip Tracing & Address Verification', 'Skills', 'Online', 0, 'Annual refresher',
                'Purpose: locate and verify unreachable borrowers using only lawful, privacy-respecting methods.'.$nl.$nl.
                'What it covers: permissible information sources, the limits on third-party contact (contact details only, never the debt), verifying an address before a field visit, and recording the source and outcome of each trace.'.$nl.$nl.
                'Who should attend: tele-callers and field staff who handle skip accounts, and the tracing desk.'.$nl.$nl.
                'Outcomes: staff who can re-establish contact without breaching privacy or the Fair Practices Code, and who document the trail behind every located account.'],
        ];
    }

    // ---- Training Content (detailed curriculum) -----------------------------

    private static function subjects(): array
    {
        $nl = "\n";

        return [
            ['RBI Fair Practices Code & Recovery Compliance', 'Module 1 - The Regulatory Frame', 'Permissible Contact Hours & Channels', 1.5,
                'The single most-checked rule in recovery is timing. Borrowers may be contacted for recovery only between 8:00 a.m. and 7:00 p.m. This window applies to every channel without exception - voice calls, SMS, WhatsApp, e-mail and physical field visits. A call placed at 7:05 p.m. is a breach even if the borrower picks up.'.$nl.$nl.
                'Beyond timing, the manner of contact matters. Repeated calls in quick succession, calls from withheld or anonymous numbers, and continuing to call a number after the borrower has asked you to use a different one are all treated as harassment under the Fair Practices Code, regardless of the hour.'.$nl.$nl.
                'Every contact must be logged: date, time, channel, who was reached (right party or third party), and the outcome. This log is your protection - if a borrower alleges harassment, a clean, time-stamped record is the difference between a dismissed complaint and a sustained one.'.$nl.$nl.
                'Practical rule of thumb: if you would not be comfortable explaining the call to a regulator, do not make it. When in doubt, schedule the contact for the next permitted slot and note why.'],
            ['RBI Fair Practices Code & Recovery Compliance', 'Module 1 - The Regulatory Frame', 'Prohibited Practices & Harassment', 1.5,
                'Recovery is firm, factual follow-up - never coercion. The following are absolutely prohibited: abusive, obscene or threatening language; intimidation or the use or threat of muscle power; public shaming of the borrower; and any threat of violence against the borrower or their family.'.$nl.$nl.
                'Also prohibited: persistent calling clearly intended to pressure rather than communicate, contacting the borrower at odd hours, and visiting or calling in a manner designed to embarrass them in front of others. None of these are made acceptable by the size of the overdue amount.'.$nl.$nl.
                'The accountability is severe. In the RBI\'s framework a proven act of harassment by an agent is treated as a violation by the lender\'s Board - the lender cannot hide behind the fact that recovery was outsourced. One bad call can therefore put the entire engagement at risk.'.$nl.$nl.
                'If a borrower becomes abusive, you do not mirror it. Stay calm, restate the facts once, and if the call cannot proceed professionally, close it politely and note the disposition. Document any threat made to you and report it to your supervisor.'],
            ['RBI Fair Practices Code & Recovery Compliance', 'Module 2 - Privacy & Accountability', 'Borrower Privacy & Third-Party Disclosure', 1.0,
                'The existence and amount of a debt are confidential between the lender and the borrower (and any guarantor). You must never disclose them to the borrower\'s family, friends, neighbours, colleagues or employer.'.$nl.$nl.
                'There is one narrow exception. When the borrower is genuinely unreachable, you may approach a third party - but only to ask for updated contact information, and even then you must not reveal why you are calling or that a debt exists. The moment a third party asks "what is this about?", the honest answer is a neutral one: that you need to reach the person on a personal matter.'.$nl.$nl.
                'Do not contact a borrower at their workplace unless they have specifically agreed to it. Workplace contact that exposes the debt to an employer is one of the most common and most serious privacy breaches.'.$nl.$nl.
                'Treat every borrower detail - phone, address, balance - as confidential company data governed by the DPDP Act. Share it only on a need-to-know basis and only through approved systems.'],
            ['RBI Fair Practices Code & Recovery Compliance', 'Module 2 - Privacy & Accountability', 'Grievance Redressal & Escalation', 1.0,
                'Every recovery communication must carry the name and contact details of the Grievance Redressal Officer (GRO). This is not optional fine print - it is how a borrower exercises the right to be heard.'.$nl.$nl.
                'The core rule: when a borrower raises a dispute or grievance, recovery activity on that account pauses until the grievance is resolved. Continuing to chase a disputed account is itself a breach. As a front-line agent, the moment you hear a grievance you stop pressing for payment, acknowledge it, and route it to the GRO the same day.'.$nl.$nl.
                'Give the borrower a reference for their complaint and a realistic timeline. A borrower who feels heard rarely escalates; a borrower who is ignored goes to the Banking Ombudsman, which is far costlier for everyone.'.$nl.$nl.
                'Keep your own note of the grievance - what was alleged, when, and to whom you routed it - so the handover is clean and nothing falls through the cracks.'],

            ['IIBF DRA Certification Preparation', 'Module 1', 'The DRA Role & RBI Mandate', 2.0,
                'A Debt Recovery Agent (DRA) is the front-line representative of the lender. RBI requires that, before an agent collects on behalf of a regulated lender, they hold the IIBF DRA certificate - and that banks and NBFCs ensure every recovery agent is certified within one year of engagement.'.$nl.$nl.
                'The training requirement is tiered by education: graduates complete 50 hours of training and undergraduates 100 hours, followed by the IIBF examination. The certificate signals that the agent understands both the mechanics of recovery and the conduct expected of them.'.$nl.$nl.
                'The role carries real responsibility: an agent represents the lender\'s brand, handles confidential borrower data, and operates under a code where their misconduct is the lender\'s liability. Professionalism is therefore not a nicety - it is the job.'.$nl.$nl.
                'This module sets expectations: courteous, ethical persuasion; accurate record-keeping; and a clear understanding of the boundary between lawful follow-up and harassment.'],
            ['IIBF DRA Certification Preparation', 'Module 2', 'Recovery Process & Soft Skills', 2.0,
                'The IIBF syllabus treats recovery as a communication discipline. It covers the lending and recovery cycle end to end, the agent\'s role within it, and the behavioural and soft skills that separate an effective collector from an aggressive one.'.$nl.$nl.
                'Key soft skills: active listening to understand whether a borrower cannot pay or will not pay; clear, simple business communication; empathy without losing firmness; and the ability to keep a difficult conversation moving towards a concrete commitment.'.$nl.$nl.
                'The syllabus also covers the rural and financial-inclusion context, because many borrowers are first-time or low-literacy customers who need patience and plain language rather than jargon or pressure.'.$nl.$nl.
                'The exam rewards candidates who internalise that ethical persuasion and good record-keeping recover more, over time, than pressure tactics - which generate complaints and lost accounts.'],
            ['IIBF DRA Certification Preparation', 'Module 3', 'Legal Aspects of Recovery', 2.0,
                'An agent does not need to be a lawyer, but must know the legal landscape well enough to act correctly and to recognise when a matter belongs with the legal team.'.$nl.$nl.
                'Core topics: the lender-borrower contract and what it permits; the Negotiable Instruments Act and the consequences of a dishonoured cheque (Section 138); the SARFAESI Act for taking possession of secured assets; and the Debt Recovery Tribunal route for larger claims.'.$nl.$nl.
                'Equally important are the limits the law places on the agent: no trespass, no force, no seizure of property that is not the financed security, and no conduct that amounts to criminal intimidation.'.$nl.$nl.
                'The practical takeaway: an agent should be able to identify when persuasion has been exhausted and escalate with a clean documentary trail, rather than crossing a legal line in an attempt to recover personally.'],

            ['Collections Process & Bucket Management', 'Module 1', 'DPD Buckets & Roll-Rate Discipline', 1.5,
                'Accounts are organised by Days Past Due (DPD) into buckets - typically X (1-30 days), 30+, 60+, 90+ and then NPA. Each bucket has a different objective and a different tone.'.$nl.$nl.
                'The 0-30 bucket is a service-and-reminder stage, not an enforcement stage. Many borrowers here have simply forgotten or had a temporary cash gap; a courteous reminder recovers most of them and preserves the relationship. The goal is to stop the account "rolling forward" into 30+ and beyond.'.$nl.$nl.
                'Roll-rate is the share of accounts that move from one bucket to the next worse one. Managing roll-rate in early buckets is the single highest-leverage activity in collections, because recovery becomes harder and more expensive in every deeper bucket.'.$nl.$nl.
                'Daily prioritisation: work fresh roll-ins first, then high-balance accounts, then broken promises. A high-balance 30+ account saved from rolling to 60+ is worth more than chasing a handful of small, deeply delinquent ones.'],
            ['Collections Process & Bucket Management', 'Module 2', 'Promise-to-Pay (PTP) Discipline', 1.0,
                'A Promise-to-Pay (PTP) is a borrower\'s commitment to pay a specific amount on a specific date. It is the core unit of work in collections, and capturing it well is what separates a professional operation from a noisy one.'.$nl.$nl.
                'Every PTP must be recorded in the system with the amount and date, confirmed back to the borrower ("so I will see the 5,000 by Friday the 12th, correct?"), and followed up on or before that date. A PTP with no diarised follow-up is worthless.'.$nl.$nl.
                'Track "PTP kept versus broken" as your core quality metric. A high broken-PTP rate usually points to weak qualification (the borrower never really intended to pay) or weak follow-up - both are process problems you can fix.'.$nl.$nl.
                'When a PTP breaks, the next contact is not an accusation; it is a calm "we had agreed on the 12th - what changed, and when can we realistically expect it?" That keeps the borrower engaged and the account moving.'],
            ['Collections Process & Bucket Management', 'Module 3', 'Segmenting Intent vs Ability', 1.0,
                'Every overdue borrower falls into one of two broad groups, and treating them the same is the most common cause of wasted effort. Separate the borrower who will not pay (an intent problem) from the one who cannot pay (an ability problem).'.$nl.$nl.
                'Intent cases - the borrower has the money but is avoiding or testing you - need firm, well-documented follow-up and, where the contract and law allow, timely escalation. Soft treatment here just trains the borrower to ignore you.'.$nl.$nl.
                'Ability cases - genuine hardship, job loss, illness - need a solution, not pressure: a restructured plan, a part-payment arrangement, or an approved one-time settlement. Pressure here generates complaints and rarely recovers anything.'.$nl.$nl.
                'Diagnosing which is which is a listening skill. Ask open questions about the situation, listen for specifics, and verify against the account history before you decide the treatment.'],

            ['Tele-calling Etiquette & Right-Party Contact', 'Module 1', 'The Opening & Mandatory Identity Disclosure', 1.0,
                'The first thirty seconds set the tone and the compliance of the entire call. Open by greeting the person, identifying yourself by name, naming the agency, and stating on whose behalf you are calling.'.$nl.$nl.
                'Before discussing anything about the account, confirm you are speaking to the right party. Until identity is confirmed, you must not reveal that a debt exists or any account detail - this protects both the borrower\'s privacy and you.'.$nl.$nl.
                'Keep the opening tight and confident; a rambling or apologetic opening invites the borrower to take control or hang up. A calm, professional tone signals that this is a legitimate, routine matter to be resolved.'.$nl.$nl.
                'Record the outcome the instant the call ends, while it is fresh: who you reached, what was agreed, and the next action with a date.'],
            ['Tele-calling Etiquette & Right-Party Contact', 'Module 2', 'Right-Party vs Third-Party Contact', 1.0,
                'Right-Party Contact (RPC) means you have reached the borrower or guarantor themselves. Only with the right party may you discuss the account, the amount and the way forward.'.$nl.$nl.
                'Third-Party Contact (TPC) is anyone else - a family member, colleague or whoever answers the phone. With a third party you may only request updated contact details for the borrower, and you must not disclose the debt, the amount, or even that the call concerns money.'.$nl.$nl.
                'Disposition every call accurately as RPC, TPC or No-contact. This drives the next action and keeps reporting honest. Mislabelling a TPC as an RPC, or discussing the debt with a third party, is a privacy breach.'.$nl.$nl.
                'If a third party becomes hostile or asks probing questions, stay neutral, thank them, and end the call. You never owe an explanation that would compromise the borrower\'s privacy.'],
            ['Tele-calling Etiquette & Right-Party Contact', 'Module 3', 'Tone, Scripting & Objection Handling', 1.0,
                'Use the approved script as a frame, not as a robotic reading. The structure keeps you compliant and on-track; your delivery makes it human and persuasive.'.$nl.$nl.
                'A reliable flow: acknowledge the borrower\'s situation, restate the amount and due date clearly, ask a direct question about payment, and steer towards one concrete next step - a payment now, a dated PTP, or an agreed callback time.'.$nl.$nl.
                'Handle objections by listening first, then responding to the actual concern. "I have no money this month" is an ability signal - explore a part-payment or date. "I already paid" is a dispute - stop, verify, and route if needed. Never argue; redirect.'.$nl.$nl.
                'De-escalate anger by lowering your own tone and slowing down, never by matching the borrower. End every call by restating clearly what was agreed, so there is no ambiguity later.'],

            ['Negotiation & Settlement Techniques', 'Module 1', 'Structuring Settlements & Restructures', 1.5,
                'Negotiation in collections follows an order of preference, and you should always start at the top and move down only as needed: full payment, then a dated part-payment plan, then a one-time settlement (OTS) within your approved authority.'.$nl.$nl.
                'Anchor on the full amount due before discussing any reduction, so any concession is clearly a concession. Quantify every option precisely - amount, dates, and what closes the account - and put the borrower\'s commitment on record immediately.'.$nl.$nl.
                'Restructures suit ability cases with a real future income: smaller, realistic instalments that the borrower can actually meet beat an ambitious plan that breaks in month one. A kept small plan is worth more than a broken large one.'.$nl.$nl.
                'The hard limit: never promise a waiver, settlement, or "no legal action" that you are not specifically authorised to grant. Unauthorised promises are the leading cause of disputes and regulatory complaints.'],
            ['Negotiation & Settlement Techniques', 'Module 2', 'Documenting Settlements - No Verbal Promises', 1.0,
                'A settlement only exists once it is in writing from an authorised officer. Any waiver, reduction or settlement figure must be confirmed in an official communication before the borrower acts on it.'.$nl.$nl.
                'Verbal assurances are not binding and are a frequent source of complaints - the borrower remembers a promise you were not authorised to make, pays a reduced amount, and then disputes the balance. Protect yourself and the company by routing every settlement for written approval.'.$nl.$nl.
                'Once approved, issue the settlement letter stating the amount, the deadline, the mode of payment and the fact that paying it closes the account. Collect within the stated validity, and update the account status promptly so no further recovery action is taken.'.$nl.$nl.
                'Keep the approval and the letter on file. A clean settlement record is what lets you confidently tell a borrower - or a regulator - exactly what was agreed and when.'],

            ['Field Collections, Visit Protocol & Repossession Conduct', 'Module 1', 'Pre-Visit Planning & Geo-fenced Beats', 1.5,
                'A good field visit is planned, not improvised. Schedule visits within the 8 a.m. to 7 p.m. window, group them sensibly by area to follow your assigned beat or geo-fence, and review each account\'s history before you set out.'.$nl.$nl.
                'Carry your authorisation letter and photo ID on every visit, ready to display on request. Log the start and end of each visit with the location, so there is a verifiable record of where you were and when.'.$nl.$nl.
                'An unannounced visit is acceptable; an aggressive, intimidating or repeated harassing visit is not. The objective is a respectful conversation that produces a payment or a clear commitment, not a confrontation.'.$nl.$nl.
                'Plan for safety too: share your route, avoid isolated visits late in the day, and disengage from any situation that turns hostile - report it rather than escalate it.'],
            ['Field Collections, Visit Protocol & Repossession Conduct', 'Module 2', 'Conduct at the Doorstep', 1.0,
                'At the door, display your ID, greet the borrower, and ask to speak with them privately. Never discuss the debt in front of neighbours, children, or anyone who is not the borrower or guarantor - doing so is a privacy breach and a reputational risk.'.$nl.$nl.
                'Read the situation with judgement and compassion. If the borrower is unwell, if there has been a bereavement, or if only a vulnerable person (an elderly parent, a minor) is present, withdraw politely and reschedule. Pressing on in these moments is exactly the kind of conduct that triggers complaints.'.$nl.$nl.
                'Maintain dignity throughout - yours and theirs. You are the visible face of the lender; the way you conduct a doorstep conversation is the borrower\'s impression of the entire institution.'.$nl.$nl.
                'Close every visit with a clear outcome: a payment, a dated PTP, or a scheduled follow-up. Record it before you move to the next address.'],
            ['Field Collections, Visit Protocol & Repossession Conduct', 'Module 3', 'Repossession Code (Secured Assets)', 1.5,
                'Repossession is a lawful, last-resort step that applies only to the financed or secured asset - the vehicle or equipment on which the loan was given - and only through the contractually and legally permitted process.'.$nl.$nl.
                'Due process means proper notice before repossession, repossession conducted respectfully and without force, and a signed inventory recording the asset and its condition at the time it is taken. Photograph the condition where possible.'.$nl.$nl.
                'Strict prohibitions: never use or threaten force; never seize personal belongings or any property that is not the secured asset; never repossess in a manner designed to humiliate. Seizing the wrong property, or using force, is illegal and exposes both you and the lender.'.$nl.$nl.
                'If the borrower resists or the situation risks turning physical, stand down and escalate to the legal/supervisory team. A repossession done wrong costs far more than the asset is worth.'],

            ['Customer Grievance Handling & De-escalation', 'Module 1', 'Listen, Log, Resolve, Escalate', 1.0,
                'Treat every grievance as genuine until it is verified. The borrower who feels dismissed escalates; the borrower who feels heard usually settles. Your first job is to listen fully without interrupting or defending.'.$nl.$nl.
                'Then log it accurately - what is alleged, the date, and the account - give the borrower a reference, and set a realistic expectation for when they will hear back. Route the grievance to the Grievance Redressal Officer the same day.'.$nl.$nl.
                'Apply the pause rule: recovery on a disputed account stops until the grievance is closed. Continuing to chase a borrower who has raised a genuine dispute is itself a breach and turns a small problem into a regulatory one.'.$nl.$nl.
                'De-escalation is mostly tone and pace: acknowledge the emotion ("I understand this is frustrating"), slow down, deal in facts, and offer a concrete next step. Most heated calls calm down within a minute of being genuinely heard.'],

            ['Legal Recovery Toolkit (Sec 138, SARFAESI, DRT, Lok Adalat)', 'Module 1', 'Section 138 NI Act - Cheque Dishonour', 1.5,
                'When a cheque issued for a legally enforceable debt is dishonoured, Section 138 of the Negotiable Instruments Act provides a criminal remedy - but only if the procedure is followed exactly.'.$nl.$nl.
                'The sequence: the cheque must have been presented within its validity; on dishonour, a written demand notice must be sent within 30 days of the bank\'s return memo; the drawer then has 15 days to pay; and only if they fail can a complaint be filed.'.$nl.$nl.
                'The consequences are serious - the offence carries imprisonment of up to two years, a fine of up to twice the cheque amount, or both - which is why the threat of a properly built 138 case is itself a strong recovery lever.'.$nl.$nl.
                'For collections staff the job is evidence: preserve the original cheque, the bank\'s return memo, and proof of the demand notice and its delivery. A 138 case stands or falls on this documentary trail, so capture it cleanly the moment a cheque bounces.'],
            ['Legal Recovery Toolkit (Sec 138, SARFAESI, DRT, Lok Adalat)', 'Module 2', 'SARFAESI Act & Debt Recovery Tribunals', 1.5,
                'For secured loans, the SARFAESI Act, 2002 is the lender\'s most powerful tool. It allows the lender to issue a demand notice under section 13(2) giving the borrower 60 days to pay, and, on continued default, to take possession of the secured asset under section 13(4) - without first going to court.'.$nl.$nl.
                'Larger or unsecured claims, and disputes over the SARFAESI process itself, are handled by the Debt Recovery Tribunal (DRT), a specialised forum set up to resolve lender recovery suits faster than ordinary civil courts.'.$nl.$nl.
                'These are lender-led legal steps executed by the legal team and authorised officers, not by field agents. The collections role is to identify eligible accounts early, ensure the documentation and notices are in order, and provide accurate field information (asset location and condition).'.$nl.$nl.
                'Knowing this route exists also sharpens negotiation: a borrower who understands that a secured asset can lawfully be repossessed often engages more seriously on a settlement before it gets there.'],
            ['Legal Recovery Toolkit (Sec 138, SARFAESI, DRT, Lok Adalat)', 'Module 3', 'Lok Adalat & Amicable Settlement', 1.0,
                'A Lok Adalat (People\'s Court) is the fastest and cheapest way to close an eligible dispute, including Section 138 cheque cases. Both sides sit across the table and agree a final figure.'.$nl.$nl.
                'The settlement reached carries the force of a civil court decree, is final and binding with no appeal, and - a key advantage - the complainant\'s court fee is refunded by the government. For the lender it converts a slow, contested case into a clean, enforceable closure.'.$nl.$nl.
                'For collections, the implication is to build and maintain a clean documentary record on every account, so that any matter ripe for amicable settlement can be moved to a Lok Adalat quickly and closed.'.$nl.$nl.
                'It is also a useful message in negotiation: settling now, voluntarily, is almost always better for the borrower than a contested case that ends in a binding decree against them anyway.'],

            ['Data Privacy & DPDP Act 2023 for Collections', 'Module 1', 'Need-to-Know Data Sharing', 1.0,
                'Under the Digital Personal Data Protection Act 2023 and RBI norms, borrower data is a protected asset, and you are entrusted with only the minimum needed to do your task. This is the principle of purpose limitation.'.$nl.$nl.
                'In practice: do not copy, photograph, screenshot, forward or store borrower data on personal devices or personal messaging apps. Work only inside the company system, which keeps an access trail and the right controls.'.$nl.$nl.
                'Share data with a third party only on the narrow, lawful basis already covered (contact details, never the debt). Sharing a borrower\'s information beyond what the task requires is a breach even if no harm results.'.$nl.$nl.
                'The stakes are both organisational and personal: a data breach exposes the company to penalties under the DPDP Act and the individual to disciplinary action, up to and including termination.'],
            ['Data Privacy & DPDP Act 2023 for Collections', 'Module 2', 'Recording, Storage & Retention', 1.0,
                'Where calls are recorded, follow the company\'s disclosure practice and tell the borrower the call is being recorded where required. Recordings and notes are evidence - they must be stored only in approved systems, never on a personal phone or drive.'.$nl.$nl.
                'Retention has limits: borrower data is kept only as long as the purpose requires and the policy allows, then disposed of securely. Hoarding old data "just in case" is itself a compliance risk.'.$nl.$nl.
                'Never share account screenshots, statements or borrower details over personal WhatsApp or e-mail, even with a colleague - use the official channels that maintain the access record.'.$nl.$nl.
                'If you ever suspect data has been lost, exposed or mishandled, report it immediately. Early reporting limits the damage and is itself a duty under the data-protection framework.'],
        ];
    }

    // ---- FAQs (fuller answers) ----------------------------------------------

    private static function faqRows(?int $tid): array
    {
        $out = [];
        foreach (self::faqs() as $f) {
            $out[] = ['tenant_id' => $tid, 'category' => $f[0], 'question' => $f[1], 'answer' => $f[2]];
        }

        return $out;
    }

    private static function faqs(): array
    {
        return [
            ['Calling & Contact', 'What are the permitted hours to contact a borrower?', 'Recovery contact is allowed only between 8:00 a.m. and 7:00 p.m. This applies to every channel - voice calls, SMS, WhatsApp and field visits. A contact even a few minutes outside this window is a breach of the RBI Fair Practices Code, so when in doubt, schedule it for the next permitted slot and note the reason.'],
            ['Calling & Contact', 'How many times can I call a borrower in a day?', 'There is no fixed number, but the test is intent. Purposeful contact to communicate or follow up on a commitment is fine; repeated, back-to-back, or anonymous calls clearly meant to pressure the borrower are treated as harassment. Make the contact, log it, and honour any request to be called at a different time or on a different number.'],
            ['Calling & Contact', 'Can I contact the borrower at their workplace?', 'Only if the borrower has specifically agreed to workplace contact. Otherwise, restrict yourself to their personal number or address. You must never reveal the debt to colleagues, an employer or a receptionist - workplace disclosure of a debt is one of the most serious privacy breaches.'],
            ['Calling & Contact', 'Can I discuss the loan with the borrower\'s family or neighbours?', 'No. The existence and amount of a debt are confidential. You may approach a third party only when the borrower is genuinely unreachable, and only to ask for updated contact details - without revealing that a debt exists or what the call is about. If they ask, keep it neutral: you need to reach the person on a personal matter.'],
            ['Conduct & Compliance', 'What conduct is strictly prohibited?', 'Abusive, obscene or threatening language; intimidation or the use or threat of force; public shaming; contacting outside permitted hours; and persistent calling designed to harass. None of these are excused by the size of the overdue amount, and a proven breach by an agent is treated as a violation by the lender itself.'],
            ['Conduct & Compliance', 'A borrower says they have filed a complaint or dispute. What do I do?', 'Stop pressing for payment immediately. Acknowledge the grievance, log it with the date and details, give the borrower a reference, and route it to the Grievance Redressal Officer the same day. Recovery on that account stays paused until the grievance is resolved - continuing to chase it is itself a breach.'],
            ['Conduct & Compliance', 'Do I have to identify myself on every call?', 'Yes. Open every call by stating your name, the agency, and on whose behalf you are calling, and then confirm you are speaking to the right party before discussing any account detail. Until identity is confirmed, you must not reveal that a debt exists.'],
            ['Certification', 'Is DRA certification mandatory to work as a recovery agent?', 'Yes. The IIBF Debt Recovery Agent (DRA) certificate is mandatory under RBI norms before an agent collects for a regulated lender. Graduates complete 50 hours of training and undergraduates 100 hours, then pass the IIBF examination, and the lender must ensure this happens within one year of engagement.'],
            ['Settlement', 'Can I promise a borrower a waiver or one-time settlement?', 'Only within your written, approved authority. Any waiver, reduction, settlement figure or assurance of "no legal action" must be confirmed in writing by an authorised officer before the borrower acts on it. Verbal promises are not binding and are a leading cause of disputes, so always route a settlement for written approval first.'],
            ['Settlement', 'What is a Promise-to-Pay (PTP)?', 'A PTP is a borrower\'s commitment to pay a specific amount on a specific date. Always capture it in the system, confirm it back to the borrower in plain terms, and follow up on or before the date. Tracking PTP kept versus broken is a core quality metric - a promise with no diarised follow-up is worthless.'],
            ['Field Visits', 'What must I carry on a field visit?', 'Your authorisation letter and a photo ID, ready to show on request. Visit only within the 8 a.m. to 7 p.m. window, stay within your assigned beat or geo-fence, log the visit start and end with location, and record the outcome (payment, PTP, or follow-up) before moving on.'],
            ['Field Visits', 'Can I repossess an asset on my own?', 'Repossession is a lender-led legal process that applies only to the financed or secured asset, with proper notice and a signed inventory of its condition. Never use force and never seize personal belongings or any property that is not the security. If the borrower resists, stand down and escalate - do not act unilaterally.'],
            ['Legal', 'When can a Section 138 (cheque bounce) case be filed?', 'After a cheque for a legally enforceable debt is dishonoured, a written demand notice must be sent within 30 days of the bank\'s return memo; the drawer then has 15 days to pay; only if they fail can a complaint be filed. The offence carries up to two years\' imprisonment and/or a fine up to twice the cheque amount, so preserve the cheque, the return memo and the notice trail carefully.'],
            ['Legal', 'What is the SARFAESI route?', 'For secured loans, the SARFAESI Act lets the lender issue a 60-day demand notice under section 13(2) and, on continued default, take possession of the secured asset under section 13(4) without going to court. Larger or unsecured claims go to the Debt Recovery Tribunal. These are lender-led legal steps - agents support them with documentation and field information, but do not execute them.'],
            ['Legal', 'Why settle through a Lok Adalat?', 'A Lok Adalat settlement carries the force of a civil court decree, is final and binding with no appeal, and the complainant\'s court fee is refunded. It turns a slow, contested case into a fast, enforceable closure - which is why a clean documentary record on every account is worth maintaining, so eligible matters can be moved there quickly.'],
            ['Data Privacy', 'Can I save borrower details on my phone or share them on WhatsApp?', 'No. Under the DPDP Act 2023 and RBI norms you handle only the minimum data needed and keep it inside the company system. Copying, photographing, or forwarding borrower data on personal devices or personal messaging is a breach that exposes the company to penalties and you to disciplinary action.'],
            ['Grievance', 'A borrower disputes the amount they owe. How should I respond?', 'Acknowledge calmly and treat the dispute as genuine until verified. Log it with details, give a reference number, and escalate to the Grievance Redressal Officer. Do not continue pressing for payment until the dispute is resolved - the pause rule applies, and a borrower who feels heard rarely escalates further.'],
        ];
    }

    // ---- Knowledge Base (global) + Code of Conduct --------------------------

    public static function seedGlobalKb(): int
    {
        self::ensureTables();
        $n = 0;
        $sort = (int) (DB::table('kb_topics')->max('sort') ?? 0);
        foreach (self::kb() as $a) {
            if (DB::table('kb_topics')->where('title', $a['title'])->exists()) {
                continue;
            }
            $sort += 1;
            $row = [
                'tenant_id' => null,
                'category' => $a['category'],
                'icon' => $a['icon'],
                'title' => $a['title'],
                'body' => $a['body'],
                'roles' => json_encode($a['roles']),
                'sort' => $sort,
                'created_at' => now(), 'updated_at' => now(),
            ];
            DB::table('kb_topics')->insert($row + self::requiredDefaults('kb_topics', $row));
            $n++;
        }

        return $n;
    }

    private static function kb(): array
    {
        $nl = "\n";

        return [
            ['category' => 'Code of Conduct', 'icon' => 'fa-scale-balanced', 'roles' => ['all'],
                'title' => 'Recovery Agent Code of Conduct',
                'body' => 'Our recovery work is built on a simple principle: firmness with fairness. Every agent represents the lender, is bound by the RBI Fair Practices Code, and is personally accountable for upholding it. A breach by an agent is treated as a breach by the company.'.$nl.$nl.
                    'The standards every agent follows:'.$nl.
                    '1. Treat every borrower with courtesy and respect, regardless of how overdue the account is.'.$nl.
                    '2. Always identify yourself, the agency, and the lender you represent.'.$nl.
                    '3. Contact borrowers only between 8:00 a.m. and 7:00 p.m. on any channel.'.$nl.
                    '4. Never use abusive, threatening or intimidating language, and never threaten or use force.'.$nl.
                    '5. Keep the debt confidential - never disclose it to family, neighbours, colleagues or employers.'.$nl.
                    '6. Record every interaction accurately and honestly.'.$nl.
                    '7. Make no promise of waiver, settlement or no-legal-action without written authority.'.$nl.
                    '8. Hand any grievance to the Grievance Redressal Officer the same day and pause recovery on that account.'.$nl.
                    '9. Protect borrower data and use only company systems.'.$nl.$nl.
                    'These are not aspirations - they are conditions of doing this job. A proven breach can lead to disciplinary action, loss of certification, and legal consequences for both the individual and the company.'],
            ['category' => 'Code of Conduct', 'icon' => 'fa-phone', 'roles' => ['all'],
                'title' => 'Telephonic Conduct Standards',
                'body' => 'A recovery call is a professional interaction with a regulatory framework around it. How you open, conduct and close it determines both its effectiveness and its compliance.'.$nl.$nl.
                    'Opening: within the first thirty seconds, greet the person, identify yourself and the agency, name the lender you represent, and confirm you are speaking to the right party before discussing any account detail.'.$nl.$nl.
                    'During the call: speak calmly and never match a borrower\'s anger. State the amount and due date clearly, listen to the borrower\'s situation, and steer towards one concrete next step - a payment, a Promise-to-Pay, or an agreed callback time.'.$nl.$nl.
                    'Boundaries: do not call before 8 a.m. or after 7 p.m., do not place repeated or anonymous calls, and honour any request to be contacted on a different number or at a different time. With a third party, never disclose the debt.'.$nl.$nl.
                    'Closing and after: restate clearly what was agreed, then log the outcome immediately - right-party or third-party, the disposition, and the next action with a date.'],
            ['category' => 'Code of Conduct', 'icon' => 'fa-person-walking', 'roles' => ['all', 'field_agent'],
                'title' => 'Field Visit & Repossession Conduct',
                'body' => 'A field visit puts the lender\'s reputation on someone\'s doorstep. Conduct it as a planned, respectful, compliant interaction - never a confrontation.'.$nl.$nl.
                    'Before the visit: plan within the 8 a.m. to 7 p.m. window, stay within your assigned beat or geo-fence, review the account history, and carry your authorisation letter and photo ID. Log the visit start and end with location.'.$nl.$nl.
                    'At the door: display your ID, be courteous, and speak to the borrower privately - never discuss the debt in front of neighbours, children or anyone who is not the borrower or guarantor. If the borrower is unwell, bereaved, or only a vulnerable person is present, withdraw politely and reschedule.'.$nl.$nl.
                    'Repossession: it applies only to the secured or financed asset, only with due notice, conducted without force, and recorded with a signed inventory of the asset and its condition. Never seize personal belongings or unrelated property, and never use or threaten force. If the situation risks turning physical, stand down and escalate.'],
            ['category' => 'Code of Conduct', 'icon' => 'fa-user-shield', 'roles' => ['all'],
                'title' => 'Borrower Privacy & Confidentiality',
                'body' => 'The fact that a person owes a debt, and how much, is confidential information between the lender and the borrower. Protecting it is both a Fair Practices Code requirement and a duty under the DPDP Act 2023.'.$nl.$nl.
                    'The core rule: never reveal the debt to family, friends, neighbours, colleagues or employers. The borrower\'s financial situation is theirs alone.'.$nl.$nl.
                    'The narrow exception: when the borrower is genuinely unreachable, you may approach a third party only to obtain updated contact details, and only without disclosing the purpose or that a debt exists. The moment a third party probes, your answer stays neutral.'.$nl.$nl.
                    'Data handling: work with the minimum borrower data needed, keep it inside approved company systems, and never copy, photograph or forward it on personal devices or messaging apps. A breach of borrower data carries penalties for the company and disciplinary action for the individual.'],

            ['category' => 'Compliance & Regulation', 'icon' => 'fa-landmark', 'roles' => ['all'],
                'title' => 'RBI Fair Practices Code - Overview',
                'body' => 'The RBI Fair Practices Code (FPC) is the rulebook that governs how regulated lenders and their agents deal with borrowers - including during recovery. Every collections process in this company is built to comply with it.'.$nl.$nl.
                    'It requires conduct that is fair, transparent and non-coercive; limited, secure sharing of borrower data with agents; and a dedicated grievance-redressal mechanism whose officer is named in every recovery communication.'.$nl.$nl.
                    'A defining feature is accountability: the lender\'s MD and CEO are responsible for the conduct of outsourced recovery agents. An agent\'s violation is treated as the lender\'s violation - the lender cannot disown it because recovery was outsourced.'.$nl.$nl.
                    'The FPC also protects the borrower\'s right to be heard: where a grievance is pending, recovery on that account must pause until it is resolved, and the borrower may escalate to the RBI Ombudsman if unsatisfied.'],
            ['category' => 'Compliance & Regulation', 'icon' => 'fa-gavel', 'roles' => ['all'],
                'title' => 'RBI Recovery-Agent Norms at a Glance',
                'body' => 'A quick-reference summary of the rules that bind every recovery contact. When unsure, default to the more cautious option.'.$nl.$nl.
                    'Contact window: 8:00 a.m. to 7:00 p.m. only, on every channel.'.$nl.
                    'No harassment: no abusive language, threats, intimidation, public shaming, or excessive/anonymous calls.'.$nl.
                    'Privacy: no third-party disclosure of the debt; no workplace contact without the borrower\'s consent.'.$nl.
                    'Identity: agents must identify themselves and the lender on every contact.'.$nl.
                    'Certification: recovery agents must hold the IIBF DRA certificate within one year of engagement.'.$nl.
                    'Grievances: a named Grievance Redressal Officer in every communication; recovery halts while a grievance is open; the borrower may escalate to the RBI Ombudsman.'.$nl.$nl.
                    'Accountability: the lender owns the conduct of its agents - which is why these norms are enforced internally as strictly as the regulator enforces them.'],
            ['category' => 'Compliance & Regulation', 'icon' => 'fa-lock', 'roles' => ['all'],
                'title' => 'DPDP Act 2023 for Collections',
                'body' => 'The Digital Personal Data Protection Act 2023 treats borrower information as a protected asset and places clear duties on everyone who handles it.'.$nl.$nl.
                    'Purpose limitation: collect and use only the minimum data needed for the task at hand. You receive borrower data to recover a specific account - not to retain, copy, or use for anything else.'.$nl.$nl.
                    'Security and storage: keep data only in approved company systems, never on personal devices or personal messaging. Do not screenshot or forward statements and account details. Retain data only as long as the purpose and policy require.'.$nl.$nl.
                    'Consequences: a data breach can expose the company to financial penalties under the Act and the individual to disciplinary action. If you suspect data has been mishandled or exposed, report it immediately - early reporting limits the harm and is itself a duty.'],

            ['category' => 'Collections Process', 'icon' => 'fa-layer-group', 'roles' => ['all'],
                'title' => 'Bucket & DPD Management',
                'body' => 'Collections is organised around Days Past Due (DPD) buckets: 1-30 (X), 30+, 60+, 90+, and then NPA. Each bucket has a different objective, and working them in the right order is the heart of an efficient operation.'.$nl.$nl.
                    'Early buckets (0-30) are a service-and-reminder stage. Most of these borrowers simply forgot or had a brief cash gap; a courteous reminder recovers them and preserves the relationship. The goal here is to stop the account rolling forward into deeper, harder buckets.'.$nl.$nl.
                    'Deeper buckets need firmer, well-documented follow-up and, where the contract and law allow, timely escalation. Recovery gets harder and more expensive in every deeper bucket, which is why prevention in early buckets has the highest payoff.'.$nl.$nl.
                    'Daily discipline: prioritise fresh roll-ins and high-balance accounts, review your broken-PTP list every morning, and measure roll-rate (the share of accounts moving to a worse bucket) as your headline operational metric.'],
            ['category' => 'Collections Process', 'icon' => 'fa-handshake-angle', 'roles' => ['all'],
                'title' => 'Promise-to-Pay (PTP) Discipline',
                'body' => 'The Promise-to-Pay is the basic unit of collections work: a borrower\'s commitment to pay a specific amount on a specific date. Handling it well is what makes an operation predictable rather than chaotic.'.$nl.$nl.
                    'Capture every PTP in the system with the amount and the date. Confirm it back to the borrower in plain words so there is no ambiguity, and diarise a follow-up on or before that date. A promise with no follow-up plan is worthless.'.$nl.$nl.
                    'Measure PTP kept versus broken as your core quality metric. A high broken rate usually signals weak qualification (the borrower never really intended to pay) or weak follow-up - both fixable process problems rather than borrower problems.'.$nl.$nl.
                    'When a PTP breaks, the next contact is calm and factual: acknowledge the agreed date, ask what changed, and secure a new, realistic commitment. This keeps the account engaged and moving rather than rolling forward.'],
            ['category' => 'Collections Process', 'icon' => 'fa-scale-unbalanced', 'roles' => ['all'],
                'title' => 'Intent vs Ability - Segmenting Borrowers',
                'body' => 'The most common cause of wasted collections effort is treating every overdue borrower the same. Always separate the borrower who will not pay (an intent problem) from the one who cannot pay (an ability problem).'.$nl.$nl.
                    'Intent cases - the money exists but the borrower is avoiding or testing you - need firm, well-documented follow-up and timely escalation where allowed. Going soft here simply trains the borrower to ignore you.'.$nl.$nl.
                    'Ability cases - genuine hardship such as job loss or illness - need a workable solution, not pressure: a restructured plan, a part-payment arrangement, or an approved one-time settlement. Pressure here produces complaints and recovers little.'.$nl.$nl.
                    'Diagnosing which is which is a listening skill. Ask open questions, listen for specifics, and check the account history before deciding the treatment - then apply the right one consistently.'],

            ['category' => 'Legal Recovery', 'icon' => 'fa-file-contract', 'roles' => ['all', 'admin', 'hr_manager'],
                'title' => 'Section 138 NI Act - Cheque Dishonour',
                'body' => 'Section 138 of the Negotiable Instruments Act gives a criminal remedy when a cheque issued for a legally enforceable debt is dishonoured - but the remedy depends entirely on following the procedure and timelines.'.$nl.$nl.
                    'The sequence: the cheque must be presented within validity; on dishonour, a written demand notice must be sent within 30 days of the bank\'s return memo; the drawer then has 15 days to pay; and only if they fail can a complaint be filed within the prescribed period.'.$nl.$nl.
                    'The consequences make it a strong lever: the offence carries up to two years\' imprisonment, a fine up to twice the cheque amount, or both. A properly built 138 case often prompts settlement before it reaches judgment.'.$nl.$nl.
                    'The collections role is evidence discipline: preserve the original cheque, the bank\'s return memo, and proof of the demand notice and its service. The case stands or falls on this trail, so capture it the moment a cheque bounces.'],
            ['category' => 'Legal Recovery', 'icon' => 'fa-building-columns', 'roles' => ['all', 'admin', 'hr_manager'],
                'title' => 'SARFAESI Act & Debt Recovery Tribunals',
                'body' => 'For secured loans, the SARFAESI Act, 2002 is the lender\'s most powerful recovery tool because it permits action without first going to court.'.$nl.$nl.
                    'The process: the lender issues a demand notice under section 13(2) giving the borrower 60 days to pay; on continued default, the lender may take possession of the secured asset under section 13(4). The asset must be the financed security, and the process must follow the law precisely.'.$nl.$nl.
                    'Larger or unsecured claims, and challenges to the SARFAESI process, go before the Debt Recovery Tribunal (DRT) - a specialised forum designed to resolve lender recovery matters faster than ordinary courts.'.$nl.$nl.
                    'These are lender-led steps executed by the legal team and authorised officers, not field agents. Collections supports them by flagging eligible accounts early, ensuring documentation and notices are in order, and providing accurate field information on the asset.'],
            ['category' => 'Legal Recovery', 'icon' => 'fa-handshake', 'roles' => ['all', 'admin', 'hr_manager'],
                'title' => 'Lok Adalat & Amicable Settlement',
                'body' => 'A Lok Adalat (People\'s Court) is the fastest, cheapest way to close an eligible dispute, including Section 138 cheque cases, by agreeing a final figure across the table.'.$nl.$nl.
                    'The advantages are significant: the settlement carries the force of a civil court decree, is final and binding with no appeal, and the complainant\'s court fee is refunded by the government. For the lender, it converts a slow contested case into a clean, enforceable closure.'.$nl.$nl.
                    'For collections, the practical implication is to keep a clean documentary record on every account so eligible matters can be moved to a Lok Adalat and closed quickly.'.$nl.$nl.
                    'It is also a persuasive point in negotiation: settling voluntarily now is almost always better for the borrower than a contested case that ends in a binding decree against them.'],

            ['category' => 'Skills & Best Practice', 'icon' => 'fa-headset', 'roles' => ['all'],
                'title' => 'Tele-calling Script & Etiquette',
                'body' => 'A good script is a frame that keeps you compliant and on-track; your delivery is what makes it human and persuasive. Read it as a guide, not a robot.'.$nl.$nl.
                    'Opening: greet, identify yourself and the agency, name the lender, and confirm the right party before any account detail is discussed. For example: a brief, confident introduction that states who you are and asks to confirm you are speaking with the borrower.'.$nl.$nl.
                    'Body: acknowledge the borrower\'s situation, restate the amount and due date clearly, ask a direct payment question, and handle objections by responding to the real concern - explore a date for an ability objection, verify and route a dispute, and never argue.'.$nl.$nl.
                    'Close: agree one concrete next step (a payment, a dated PTP, or a callback time), restate it so there is no ambiguity, thank the borrower, and log the outcome immediately. Lower your tone to de-escalate; never match anger.'],
            ['category' => 'Skills & Best Practice', 'icon' => 'fa-comments-dollar', 'roles' => ['all'],
                'title' => 'Negotiation Playbook',
                'body' => 'Effective recovery negotiation is structured, not improvised. Work the offers in order and move down only as the borrower\'s situation requires.'.$nl.$nl.
                    'Order of offers: full payment first; then a dated part-payment plan; then a one-time settlement (OTS) within your approved authority. Anchor on the full amount due before discussing any reduction, so any concession is clearly understood as one.'.$nl.$nl.
                    'For ability cases, a smaller realistic instalment plan that the borrower can actually meet beats an ambitious plan that breaks immediately - a kept small plan recovers more than a broken large one.'.$nl.$nl.
                    'The hard limits: quantify every option precisely, get the borrower\'s commitment on record, and never promise a waiver, settlement or no-legal-action you are not authorised to grant. Route any settlement for written approval, then document and follow up on the agreed date.'],
        ];
    }
}
