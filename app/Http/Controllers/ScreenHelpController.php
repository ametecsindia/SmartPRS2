<?php

namespace App\Http\Controllers;

/**
 * rev 100 — SCREEN HELP (Ejaz: an ⓘ on every screen opening a professional
 * popup — what the screen is for, what you can do, how to use it, tips and
 * roles — to make SmartPRS a self-service system).
 *
 * Content lives HERE (plain arrays, module by module) — never inside the
 * boot-JS heredoc — so extending it is a cheap, safe append. The popup
 * fetches GET /app/help/{screen} lazily; unknown screens fall back to their
 * module's generic guide.
 *
 * Entry shape:
 *   m   module label shown in the header eyebrow
 *   t   screen title
 *   g   one-line tagline
 *   w   "what is this screen for" — 2-3 plain sentences
 *   f   features: [[fontawesome-icon, label], ...]  (4-6)
 *   s   steps: [plain sentence, ...]                (3-5)
 *   tip "good to know" — the thing people miss
 *   r   roles: [short chip, ...]
 *   rel "also see" line (optional)
 */
class ScreenHelpController extends Controller
{
    public function show(string $screen)
    {
        $key = strtolower(trim($screen));
        $all = self::content();
        $entry = $all[$key] ?? null;
        if (! $entry) {
            // Alias → canonical (mirrors the boot-JS aliases).
            $alias = ['notice' => 'notice-board', 'att-zkteco' => 'biometric-devices', 'geofence-list' => 'geofence', 'emp-add' => 'emp-list'];
            $entry = $all[$alias[$key] ?? ''] ?? null;
        }
        if (! $entry) {
            $entry = self::fallback($key);
        }

        // rev 101 (Ejaz): the MENTOR/SALES voice — purpose, use case, advantage
        // — merged in as the popup's "Why it matters" tab. Falls back to a
        // generic business-value pitch so the tab is never empty.
        $alias2 = ['notice' => 'notice-board', 'att-zkteco' => 'biometric-devices', 'geofence-list' => 'geofence', 'emp-add' => 'emp-list'];
        $wkey = $alias2[$key] ?? $key;
        $why = self::whys()[$wkey] ?? [
            'why' => 'Every record kept properly here is a dispute avoided later. Organised, dated, searchable records are what separate a professionally run company from one that runs on memory and WhatsApp forwards.',
            'uc' => 'Picture this: six months from now an auditor, a bank, or an unhappy employee asks "show me the record." You open this screen, filter, and the answer is on the table in thirty seconds.',
            'adv' => ['Zero dependence on anyone\'s memory', 'Audit-ready at any moment', 'New staff learn the process from the screen itself'],
        ];
        $entry = array_merge($entry, $why);

        // rev 102 (Ejaz): the SUPERVISOR voice — common mistakes, their impact,
        // and how to avoid/correct. Only where applicable (tab hides otherwise).
        $entry['mk'] = self::mistakes()[$wkey] ?? (self::roadmapMistakes()[$wkey] ?? []);

        return response()->json(['ok' => true, 'help' => $entry]);
    }

    /**
     * rev 102 — "Do it right": [mistake, impact, right way] per screen,
     * written like a senior supervisor warning a new joiner. Screens without
     * an entry simply don't show the tab.
     */
    private static function mistakes(): array
    {
        return [
            'emp-list' => [
                ['Adding employees without a reporting manager', 'Their leave and expense requests have no approver — requests pile up unseen and people think the system is broken', 'Always set the reporting manager while creating the profile; fix existing gaps from the Edit screen'],
                ['Deleting a leaver from the Directory', 'Salary history, PF trail and re-hire checks vanish with them — a future audit or PF query has no answer', 'Never delete real leavers; use Exit & FnF so dues settle and history stays'],
                ['Same employee entered twice (slightly different name)', 'Attendance and payroll split across two records — both wrong', 'Search by mobile/code before adding; if duplicated, soft-delete the wrong row from ID Cards'],
            ],
            'transfers' => [
                ['Moving an employee by just editing their company on the profile', 'No approval, no letter, no date — payroll and history can\'t explain the move later', 'Always use the Transfers screen; it approves, letters, applies on the right date and keeps the trail'],
                ['Forgetting the new manager/team after a company transfer', 'The employee\'s requests have no approver at the new place', 'After the move applies, open the onboarding card and assign role, manager and team the same day'],
            ],
            'idcard' => [
                ['Using the bulk delete for real leavers', 'They vanish without Full & Final — pending dues and recoveries are lost', 'Bulk delete is ONLY for wrong/duplicate rows; real leavers go through Exit & FnF'],
                ['Printing cards with low-quality photos', 'Cards look unprofessional at customer doors — your brand suffers where it matters most', 'Upload clear passport-style photos before generating; re-upload replaces the old one'],
            ],
            'recruitment' => [
                ['Sending bulk WhatsApp before the template is approved', 'Every message fails; candidates get nothing and you wait another day', 'Check WhatsApp Templates first — the row must show green APPROVED; a test send proves it'],
                ['Importing portal files without a mobile column', 'Candidates land in the pool but can\'t be invited — the whole point is lost', 'Use the template or keep portal exports\' mobile column intact; check the import summary'],
                ['Hiring without linking a requisition', 'Openings never show as filled; you over-hire without noticing', 'Always attach candidates to an approved requisition; the fill-count then manages itself'],
            ],
            'offroll-agents' => [
                ['Fielding an agent before DRA/PCC documents are uploaded', 'One customer complaint and the bank finds an undocumented agent — contract risk', 'Complete KYC on the day of engagement; Compliance Alerts then watches the expiries for you'],
                ['Paying off-roll agents in cash outside the system', 'No earnings trail — disputes become your word against theirs', 'Record every earning entry here and let the agent watch the live link; trust comes from the trail'],
            ],
            'att-report' => [
                ['Generating payroll without reviewing the month here first', 'Wrong absents become wrong salaries — and payday becomes dispute day', 'Scan this report on cut-off day; fix errors in Manual Attendance BEFORE generating payroll'],
                ['Treating device-missed days as absent without checking', 'Honest workers get salary cuts for a machine\'s failure — morale damage that lasts', 'Cross-check with the TL, then add the manual punch with a note'],
            ],
            'att-manual' => [
                ['Backfilling attendance casually on request', 'The register loses credibility — if everything is editable, nothing is provable', 'Demand a reason, keep it occasional, and remember every manual entry is logged with your name'],
                ['Uploading bulk attendance with future dates or out<in times', 'Bad rows pollute the staging batch and delay the whole upload', 'Use the template format; the system flags bad rows — fix them in the file, not by force'],
            ],
            'biometric-devices' => [
                ['Registering a device and never checking its sync', 'A silent device means a month of missing punches discovered at payroll time', 'Glance at last-sync when passing this screen; investigate any device quiet for 2+ days'],
            ],
            'geofence' => [
                ['Setting the radius too small (under 50 m)', 'GPS drift blocks honest punches — agents standing at the office get refused', 'Use 100–300 m radius; widen if genuine punches keep failing'],
            ],
            'shifts' => [
                ['Typing shift times in 12-hour format (9:30 PM)', 'The system reads 24-hour HH:MM — a "9:30" night shift becomes a morning shift and everyone shows hours late', 'Always use 24-hour time: 21:30 for 9:30 PM; check the night-shift hint before saving'],
                ['Creating a shift but never assigning anyone to it', 'Employees stay on the default timings — the night team keeps getting flagged late every day', 'After saving a shift, set it as the Default Shift on each employee\'s profile (or roster them day-wise)'],
            ],
            'late-policy' => [
                ['Changing the policy mid-month without announcing', 'Deductions surprise people — the policy becomes "management\'s trick" instead of a rule', 'Change from the 1st, announce on the Notice Board first, and let the wizard\'s example be your announcement'],
            ],
            'leave-apply' => [
                ['Approving leave verbally and skipping the system', 'Payroll counts the days as absent — the employee loses pay for YOUR shortcut', 'Even retroactively, enter and approve the leave here; payroll only believes the system'],
            ],
            'leave-types' => [
                ['Leaving a fresh workspace with no leave types', 'Nobody can apply for anything; leave happens off-book from day one', 'Set your types with quotas before announcing the system to staff'],
            ],
            'salary-setup' => [
                ['Setting basic too low to save PF', 'Statutory authorities check minimum-wage and basic ratios — penalties exceed the savings', 'Keep basic at a defensible percentage (40-50%); save through structure, not suppression'],
                ['Creating employee-level overrides for everyone', 'A hundred special cases means no structure at all — every payroll review becomes archaeology', 'One company structure, few team overrides, employee overrides only for genuine exceptions'],
            ],
            'salary-gen' => [
                ['Generating and approving in the same minute without review', 'A wrong attendance day or duplicate commission flows straight to bank accounts', 'Open 3 sample payslips in the draft — one normal, one with leave, one with commission — then send to approval'],
                ['Re-generating after payslips were published', 'Employees holding old payslips see different numbers than the system', 'Corrections after publishing go through deductions/clawbacks next month — not regeneration'],
            ],
            'salary-approval' => [
                ['Approving without comparing to last month\'s total', 'The fastest error-detector is skipped — big mistakes ride through', 'First look: net total vs last month; investigate any swing before line-level review'],
            ],
            'payslip' => [
                ['Ignoring the e-sign pending list', 'In a dispute, "he never received it" stands unchallenged', 'Chase signatures monthly; the signed payslip is your delivery proof'],
            ],
            'pay-ledger' => [
                ['Paying salary part-amounts in cash without recording', 'The ledger says unpaid; the employee says paid — and nobody can prove anything', 'Record EVERY payment here the same day, with mode and reference'],
            ],
            'deductions' => [
                ['Deducting without a written reason', 'A reasonless deduction is indefensible in any hearing — and looks like harassment', 'Always fill the reason; one sentence today saves one tribunal tomorrow'],
            ],
            'commissions' => [
                ['Confusing the two amounts', '"Collected from customer" entered as the commission = a ₹42,000 payout instead of ₹840', 'Collected Amount = what the CUSTOMER paid; Gross Commission = what the AGENT earns — the scheme picker computes it for you'],
                ['Approving before Accounts confirms', 'The system refuses anyway — but chasing Accounts after month-end delays everyone\'s payout', 'Accounts should confirm collections daily; managers approve right behind them'],
                ['Treating the proof upload as useless because it\'s optional', 'Accounts has to hunt the bank\'s payments list line by line — your confirmation waits', 'Attach the screenshot/slip when you have it — Accounts confirms in seconds instead of days'],
                ['Editing an entry instead of using clawback after it\'s locked', 'You can\'t — and trying signals you don\'t trust your own audit trail', 'Locked is sacred: corrections are NEW clawback entries with reasons'],
                ['Letting entries sit unapproved past month-end', 'They miss the payslip fold-in; agents get paid late and trust drops', 'Approve before cut-off; the Approvals Inbox shows everything waiting'],
            ],
            'commission-calc' => [
                ['Committing without reading the preview', 'A wrong slab rate multiplies across 100 agents in one click', 'Preview totals against a manual spot-check of 2-3 agents, then commit'],
            ],
            'bank-pack' => [
                ['Spelling the same bank three ways on commission entries', 'ICICI, Icici Bank and ICICI-CC become three different packs — none of them complete', 'Fix one naming convention for the Portfolio / Bank field; the dropdown here shows every spelling in use'],
                ['Invoicing the bank without a GSTIN on either side', 'The split defaults to IGST 18% and your invoice may not match the bank\'s books', 'Fill your GSTIN on the Companies screen and ask the bank\'s billing GSTIN once — it is remembered per bank'],
                ['Treating the pre-suggested invoice amount as final', 'The suggestion is the commission gross, not your contracted service fee — billing it blindly can under- or over-charge', 'Check the contract rate for that bank and edit the line before saving'],
            ],
            'bonus' => [
                ['Declaring the register values without verifying the law', 'The wage cap and rate carry state amendments and periodic revisions — filing stale values invites notices', 'Treat the register as the working; verify the cap and % against current law before declaring'],
            ],
            'helpdesk' => [
                ['Raising a ticket with only a subject line', 'HR replies asking "what exactly happened?" and the ticket takes two round-trips instead of one', 'Use the Description box — one clear paragraph gets one clear answer'],
                ['Marking tickets resolved without telling the employee what was done', 'The same person raises the same ticket next month', 'Write the outcome before closing; the employee sees the status change'],
            ],
            'posh' => [
                ['Discussing a POSH complaint outside this screen', 'Confidentiality is a legal requirement of the POSH Act — corridor talk exposes the company and the complainant', 'Keep every detail inside the register; only the Internal Committee and the complainant can see it'],
                ['Sitting on an open complaint', 'The POSH Act expects the inquiry to complete within 90 days — delay itself becomes a violation', 'Move the status to Inquiry immediately and record the outcome when decided'],
            ],
            'incentive-schemes' => [
                ['Publishing a scheme with no expiry and no caps', 'An "open offer" runs forever — claims keep coming long after the campaign died', 'Always set valid-till; add per-person caps when the budget is fixed'],
                ['Withdrawing a scheme to fix a typo in the rate', 'Agents who saw the announcement feel cheated when it vanishes', 'Withdraw + republish FAST with a clear new title; tell the team why in the announcement'],
                ['Announcing to "All employees" for a one-team drive', 'Everyone else asks why they can\'t claim — morale damage from a dropdown', 'Target exactly: one team or selected people; the hierarchy scope exists for this'],
            ],
            'expenses' => [
                ['Approving claims months after the spend', 'Verification becomes impossible; padding creeps in', 'Set a claim-within-7-days culture; old claims get extra scrutiny'],
            ],
            'advance' => [
                ['Giving advances bigger than the month\'s salary', 'Same-month recovery fails and the books carry untracked debt', 'For bigger needs use a Loan with EMIs — advances are for within-month amounts'],
            ],
            'loans' => [
                ['Loans without recorded EMI plans', 'Recovery depends on memory; exits happen with loans forgotten', 'Always set months/EMI here; exit settlement then nets the balance automatically'],
                ['Expecting a half-EMI in a low-salary month', 'The engine skips the EMI when the net cannot fit it in full — assuming it recovered leaves a silent gap', 'Read the payslip note: a skipped EMI is stated there and stays due for the next month'],
            ],
            'overtime' => [
                ['Recording OT but never approving it', 'The payroll engine pays only APPROVED entries — staff see the register and expect money that never arrives', 'Approve or reject every entry BEFORE the month\'s payroll is generated'],
                ['Typing manual amounts when auto-compute would do', 'Different effective rates across staff invite comparisons and disputes', 'Leave the amount blank — hours × multiplier × the salary-based hourly rate is the defensible formula'],
            ],
            'clawbacks' => [
                ['Recovering informally (cash back) instead of a clawback entry', 'The original incentive stays on record as fully paid — books lie forever', 'One clawback entry with the bounce reason keeps the story complete'],
            ],
            'increments' => [
                ['Announcing the raise verbally weeks before the letter', 'Rumour spreads, comparisons start, and the letter\'s motivation moment is wasted', 'Approve and let the letter deliver the news the same hour'],
                ['Forgetting to click Apply after approval', 'The letter promises a CTC the payroll never pays — the worst kind of HR error', 'Apply to record immediately after approval; the green "Applied" chip is your confirmation'],
            ],
            'exits' => [
                ['Settling F&F outside the system to "finish fast"', 'Pending advances/loans get missed; the relieving letter contradicts the books', 'Run the exit here — it nets every due both ways and freezes a clean record'],
            ],
            'pf-esic' => [
                ['Filing from your own Excel instead of the register', 'Two sources of truth drift apart; reconciliation pain forever', 'Always export from here — it\'s computed from the actual payroll'],
            ],
            'tds' => [
                ['Depositing TDS but skipping the quarterly return', 'Penalties accrue silently per day of delay', 'Track quarters in TDS Returns; deposit AND file are two separate obligations'],
            ],
            'compliance-alerts' => [
                ['Treating amber (due-soon) alerts as "later"', 'Due-soon becomes expired during one busy fortnight — and audits check dates, not intentions', 'Action the 30-day list weekly; renewals take longer than you think'],
            ],
            'agent-auth' => [
                ['Letting agents work on verbal authorization', 'One challenged doorstep visit becomes a police matter with no paper', 'No authorization record, no field work — make it a hard rule'],
            ],
            'tests' => [
                ['Testing without acting on failures', 'Low scorers keep facing customers; the test becomes a formality', 'Re-train under-50% scorers within the week, then re-test'],
            ],
            'training-records' => [
                ['Conducting training but recording it "later"', 'Later never comes; the audit finds no evidence of real sessions', 'Record attendees the same day — two minutes while memory is fresh'],
            ],
            'code-of-conduct' => [
                ['Not chasing the un-acknowledged list', 'The one agent who never acknowledged is always the one who misbehaves', 'Make acknowledgement part of onboarding week-one, and review the pending list monthly'],
            ],
            'letters-offer' => [
                ['Editing CTC by hand in a generated letter', 'The letter and the system disagree — a dispute waiting for its day', 'Fix the figure on the candidate/offer record and regenerate; never hand-edit'],
            ],
            'letters-warning' => [
                ['Writing vague warnings ("poor behaviour")', 'Vague warnings collapse under questioning — the record protects nobody', 'Cite specific incidents with dates; specificity is the entire protection'],
            ],
            'letters-templates' => [
                ['Deleting a placeholder while editing wording', 'That detail silently disappears from every future letter', 'Keep the double-curly placeholders intact; generate one test letter after every edit'],
            ],
            'send-message' => [
                ['Broadcasting everything to everyone', 'Real instructions drown; people stop reading', 'Target the audience that must act; use the Notice Board for general info'],
            ],
            'notice-board' => [
                ['Leaving expired notices up for months', 'The board loses credibility; new notices get ignored', 'Retire old notices monthly — a fresh board is a read board'],
            ],
            'wa-templates' => [
                ['Editing an approved template\'s wording "just slightly"', 'WhatsApp approval is per exact text — sends start failing silently', 'The status drops to Draft on edit by design; re-submit in Interakt and re-test before relying on it'],
                ['Template name mismatch between SmartPRS and Interakt', 'Every send fails with "template not found"', 'Copy-paste the name both ways; lowercase, underscores, exact'],
            ],
            'wa-settings' => [
                ['Pasting the key and never sending a test', 'You discover the broken key when a hiring drive fails at 9 AM', 'Always follow setup with one Send test from WhatsApp Templates'],
            ],
            'users' => [
                ['Sharing one admin login among staff', 'The activity log shows one name for everyone\'s actions — accountability dies', 'One person, one login, right role; logins are free, audit clarity is priceless'],
                ['Forgetting to disable leavers\' logins', 'Ex-employees retain access to salaries and data', 'Disable on the last working day — make it part of the exit checklist'],
            ],
            'roles' => [
                ['Giving everyone admin "to avoid permission issues"', 'Everyone can see salaries and delete records; one mistake is everyone\'s mistake', 'Start narrow, widen on request; the matrix takes a minute per role'],
            ],
            'branding' => [
                ['Uploading a low-resolution logo with background', 'Every payslip and ID card inherits the ugliness', 'Use a transparent-background PNG at decent size; check one PDF after'],
            ],
            'company-emails' => [
                ['Saving SMTP without the Send Test', 'Offer letters and payslips silently fail for days', 'Send Test after every change — thirty seconds of certainty'],
            ],
            'fin-year' => [
                ['Forgetting the FY switch in April', 'New records tag to the old year; reports mislead until fixed', 'Make "set active FY" a 1-April ritual'],
            ],
            'my-subscription' => [
                ['Ignoring renewal reminders until lock-out', 'Employees are blocked mid-work; panic renewal under pressure', 'Renew in the 15-day window; early renewal extends from your end date — you lose nothing'],
            ],
            'companies' => [
                ['Creating companies for "maybe later" plans', 'Each costs ₹1,000/month and clutters every dropdown', 'Add a company when it has employees, not before'],
            ],
            'tenants' => [
                ['Suspending a tenant to "get attention" on small dues', 'All their users are blocked instantly — relationship damage exceeds the dues', 'Use the automatic grace flow; suspend only deliberate defaulters'],
                ['Skipping the GST state on tenant setup', 'Their invoices carry the wrong tax split — their accountant rejects them', 'Set state/GSTIN at creation; the invoice engine follows it automatically'],
            ],
            'plans' => [
                ['Testing price ideas on the live plans', 'The public signup shows your experiment to real buyers instantly', 'Decide first, edit once; existing subscribers keep their agreed amounts'],
            ],
            'subscriptions' => [
                ['Manually extending periods as favours', 'Revenue leaks invisibly; the books and gateway stop matching', 'Extend through recorded renewals/invoices so every month is accounted'],
            ],
            'invoices' => [
                ['Deleting test invoices after going live', 'GST sequences must be continuous — gaps invite questions', 'Clean test data BEFORE the first real invoice; after that, sequences are forever'],
            ],
            'gateways' => [
                ['Leaving test mode on in production', 'Real customers "pay" with test cards; no real money arrives', 'Switch to LIVE keys at go-live and verify with one real small payment'],
            ],
            'admin-landing' => [
                ['Putting commas inside amounts in pricing-card lines', 'The card splits the line into two broken bullets', 'Write ₹1000 not ₹1,000 inside plan feature lines'],
            ],
            'admin-leads' => [
                ['Calling leads days later', 'A demo enquiry cold after a week converts at a fraction of an hour-old one', 'Work the New list daily; live-demo leads within the hour'],
            ],
            'admin-quotations' => [
                ['Letting quotes expire silently', 'The prospect\'s approval finally lands — on a dead link', 'Nudge at day 10; a fresh quote takes the client two minutes on the signup page'],
                ['Recording a credit (Due/Partial) entry without a real commitment', 'The workspace goes live, the client uses it, and the money conversation gets harder every week', 'Record credit only after an agreed due date in writing; watch the Credit-clients table weekly — overdue turns red'],
                ['Typing the received amount without the bank reference', 'Two months later nobody can match the ledger entry to the bank statement', 'Always paste the UTR / cheque number in the Reference box — it lands in the payments ledger'],
            ],
            'admin-coupons' => [
                ['Creating a coupon with no expiry and no use limit', 'The code leaks to a WhatsApp group and every signup forever arrives pre-discounted', 'Always set valid-till AND max uses — scarcity is the point of a coupon'],
                ['Forgetting that coupons STACK with the annual 25% discount', 'A 30% code on an annual payment means 55%+ off — margin gone', 'Before publishing, compute the worst case: annual discount + coupon on your cheapest plan'],
                ['Deleting a code mid-campaign instead of disabling', 'Prospects holding the ad get "invalid coupon" and feel cheated', 'Disable stops NEW use politely; delete only codes that were never redeemed'],
            ],
            'admin-staff' => [
                ['Creating staff logins for temporary helpers', 'Platform staff see EVERY client\'s data — temporary access becomes permanent risk', 'Tiny list, real staff only, removed the day they stop'],
            ],
            'admin-onprem' => [
                ['Generating a key before payment is settled', 'A live licence with money pending becomes a collection headache instead of a sale', 'Let the gate work: full payment auto-issues; partial only with YOUR recorded tick'],
                ['Forgetting to note the key at installation time', 'The engineer at the client site has to call back and wait', 'The key shows once after generation and is in the client\'s email — record it in the installation register the same minute'],
                ['Revoking a licence in anger during a dispute', 'A client\'s HR and payroll going dark turns a payment dispute into a reputation war', 'Revoke blocks NEW activations and updates only — settle money commercially, keep the perpetual promise'],
            ],
            'admin-releases' => [
                ['Uploading a zip you did not build with BUILD-RELEASE.bat', 'Wrong contents or missing checksum can fail mid-apply on client machines', 'Always: bump version → BUILD-RELEASE.bat → upload THAT zip'],
                ['Publishing without testing the release on your own editions first', 'Every AMC client gets an email pointing at a broken update', 'Apply to platform + check one local edition BEFORE Publish to clients'],
                ['Writing the changelog in developer language', 'Clients ignore emails they do not understand and stay on old versions', 'One plain line per improvement — what THEY gain, not what code changed'],
            ],
            'sys-updates' => [
                ['Closing the window mid-apply', 'The update may stop half-way (SmartPRS will restore itself, but you must run it again)', 'Click Apply, keep the window open the two minutes, let it reload by itself'],
                ['Ignoring update emails for months', 'You miss fixes and features your AMC already paid for', 'When the email comes, it is a two-minute job — do it the same day'],
            ],
        ];
    }

    /**
     * rev 101 — the "Why it matters" content: purpose (why this exists),
     * a concrete use case, and the advantages. Written as a professional
     * sales mentor would explain it, screen by screen.
     */
    private static function whys(): array
    {
        return array_merge(self::whysA(), self::whysB(), self::whysRoadmap());
    }

    private static function whysA(): array
    {
        return [
            'dashboard' => [
                'why' => 'A business you cannot see, you cannot run. The dashboard exists so the owner\'s first minute of the day answers the only question that matters: is anything on fire?',
                'uc' => 'Picture this: 9:05 AM, you open SmartPRS with your chai. 4 absent in the Hyderabad branch, 2 approvals waiting, 1 DRA expiring this month. Three phone calls before 9:30 and the day is already under control.',
                'adv' => ['Problems surface before they grow', 'No more "sir, I didn\'t know" — everyone sees the same truth', 'Management by numbers, not by mood'],
            ],
            'live-salary' => [
                'why' => 'In collections, the #1 reason agents quit or argue is "salary ka hisaab clear nahi hai." Live Salary kills that argument by showing every employee their money, building up daily, entry by entry.',
                'uc' => 'Picture this: your agent collects ₹40,000 on Tuesday and his incentive entry is approved Wednesday. He opens Live Salary and SEES it sitting in his month — he doesn\'t call HR, he goes and collects more.',
                'adv' => ['Salary disputes drop to near zero', 'Motivation: agents watch their earnings grow daily', 'HR stops answering "mera kitna bana?" twenty times a day'],
            ],
            'escalations' => [
                'why' => 'One mishandled bank escalation can cost an agency its contract. This register exists so every escalation has an owner, a deadline and a paper trail — because banks judge you by how you handle the bad days.',
                'uc' => 'Picture this: the bank\'s vendor manager calls about a customer complaint from last month. You open the desk, read the closure note with dates, and reply in one email. Contract safe.',
                'adv' => ['No escalation ever falls through a crack', 'Audit-ready evidence of professional handling', 'Banks renew vendors who can show this discipline'],
            ],
            'approvals-inbox' => [
                'why' => 'Approvals delayed are trust destroyed — an employee waiting five days for a ₹500 expense learns his company doesn\'t care. One inbox makes deciding so easy there is no excuse to delay.',
                'uc' => 'Picture this: a team leader opens one screen with morning tea, clears six requests in four minutes, and every one of those six employees starts the day feeling heard.',
                'adv' => ['Decisions in minutes, not days', 'Nothing waits in a forgotten email', 'Employees trust a system that responds'],
            ],
            'notifications' => [
                'why' => 'Information that doesn\'t reach people doesn\'t exist. The feed makes sure decisions and announcements actually land with the person they concern.',
                'uc' => 'Picture this: an agent returns from two days of field work, opens notifications, and in one minute knows his leave was approved and a new notice was published. No catching-up calls.',
                'adv' => ['Nobody can say "I wasn\'t told"', 'Catch up after leave in one minute', 'Less broadcast-WhatsApp noise'],
            ],
            'how-it-works' => [
                'why' => 'Training time is money. This orientation exists so a new user becomes productive in their first hour, without a trainer sitting beside them.',
                'uc' => 'Picture this: you hire a new HR executive Monday. By lunch, she has read this once, used the ⓘ on three screens, and processed her first leave request — zero training calls to you.',
                'adv' => ['New staff productive on day one', 'No dependence on one "system expert"', 'Self-service reduces your training cost to zero'],
            ],
            'kb' => [
                'why' => 'Every question answered twice is a process failure. The knowledge base turns your best answers into permanent assets that work even when the expert is on leave.',
                'uc' => 'Picture this: a field agent asks "can I call a defaulter at 8 PM?" Instead of guessing, he searches the KB and reads the RBI fair-practice answer — your compliance just protected itself.',
                'adv' => ['Compliance knowledge at every agent\'s fingertips', 'HR interruptions cut dramatically', 'Institutional knowledge survives staff churn'],
            ],
            'emp-list' => [
                'why' => 'The directory is the foundation stone — payroll, attendance, approvals, everything reads from here. Companies that keep this clean run clean; companies that don\'t, fight fires forever.',
                'uc' => 'Picture this: a bank audit asks for the list of agents on their portfolio with codes and joining dates. Filter, export, send — four minutes, and you look like the most organised vendor they have.',
                'adv' => ['One truth — no more three different Excel versions', 'Every module works correctly because this is correct', 'Instant professional answers to client/audit queries'],
            ],
            'teams' => [
                'why' => 'Collections runs on team leaders. Defining teams in the system is what lets a TL see his people\'s attendance, approve their requests and own their numbers — accountability with structure.',
                'uc' => 'Picture this: you create "Axis Bucket-2 Team" under Suresh. From tomorrow, Suresh sees his eight members\' attendance and approves their claims — you stopped being the bottleneck.',
                'adv' => ['Team leaders truly own their teams', 'Approvals flow without touching the owner', 'Performance becomes comparable team vs team'],
            ],
            'idcard' => [
                'why' => 'A field agent without an ID card is a stranger at the customer\'s door — and a compliance risk. Instant ID cards make every agent identifiable and your company look established.',
                'uc' => 'Picture this: ten joiners on Monday. Photos uploaded by lunch, ten branded ID cards printed by 2 PM. On Tuesday they\'re at customer doors looking like professionals from a serious company.',
                'adv' => ['Professional image at every doorstep', 'RBI/bank expectations of identification met', 'No designer, no printer-shop dependency'],
            ],
            'transfers' => [
                'why' => 'In group companies, people move — and undocumented moves create salary confusion, missing approvals and audit gaps. This makes every move formal, approved and reversible on paper.',
                'uc' => 'Picture this: Asha moves from Apex to Sentinel from the 1st. The order letter emails itself, she acknowledges on her phone, and on the 1st the system moves her — payroll, attendance, everything follows automatically.',
                'adv' => ['Zero salary confusion on movement', 'A letter trail that stands in any dispute', 'Group-wide career paths without paperwork'],
            ],
            'documents' => [
                'why' => 'Missing documents are discovered at the worst possible time — during an audit or a dispute. A locker per employee means the worst time never comes.',
                'uc' => 'Picture this: a client onboarding asks for PAN + address proof of all 25 deployed agents. You check the screen, see two gaps, fix them today — not on the audit day.',
                'adv' => ['Gaps visible before auditors find them', 'Faster client onboarding of your teams', 'No frantic "bhai, PAN bhejo" calls'],
            ],
            'bgv' => [
                'why' => 'One bad hire in collections can mean theft, fraud or a police case at a customer\'s house — with YOUR company\'s name on it. BGV tracking is cheap insurance against expensive disasters.',
                'uc' => 'Picture this: a bank asks "are your agents verified?" You filter completed BGVs and send the list. The competitor who answers "we\'ll check" loses the allocation; you gain it.',
                'adv' => ['Protects your licence and reputation', 'Wins allocations from compliance-conscious banks', 'Bad apples caught before the uniform is issued'],
            ],
            'onboarding-board' => [
                'why' => 'First impressions are made once. A joiner who waits three days for system access starts demotivated; a checklist makes day one feel like a company that has its act together.',
                'uc' => 'Picture this: a hire from Tuesday\'s walk-in joins Monday. Their card is already on the board — documents, ID, training — and by evening every box is ticked and they\'re working.',
                'adv' => ['Joiners productive in days, not weeks', 'Nothing forgotten — no "ID card never made"', 'Early attrition drops when day one is smooth'],
            ],
            'recruitment' => [
                'why' => 'Collections is a volume-hiring business — agents churn, portfolios grow, and the agency that hires fastest serves the bank best. This screen turns hiring from a phone-call grind into an assembly line.',
                'uc' => 'Picture this: Naukri gives you 300 CVs Monday morning. By 11 AM they\'re imported, filtered to 120, and a walk-in invite with the map link is on every WhatsApp. Thursday you hire 14 — without making one phone call.',
                'adv' => ['Days of calling become one bulk WhatsApp', 'Every candidate tracked — none lost in Excel', 'Hiring speed becomes your sales pitch to banks'],
            ],
            'offroll-agents' => [
                'why' => 'Off-roll agents do your field work but live outside payroll — which is exactly where compliance disasters hide. Full KYC plus live earnings keeps them compliant AND loyal.',
                'uc' => 'Picture this: a vendor agent doubts his payout. Instead of an argument, you send his private earnings link — he watches his approved entries live on his phone and the doubt dies there.',
                'adv' => ['Bank-grade compliance on non-payroll workers', 'Vendor agents trust you — and stay', 'No more month-end payout arguments'],
            ],
            'att-daily' => [
                'why' => 'Attendance is where collections money leaks first — an absent agent collects nothing. Seeing today TODAY, not at month-end, is the difference between fixing and forgiving.',
                'uc' => 'Picture this: 10 AM, the screen shows 3 of your best collectors absent in the same branch. One call to the TL reveals a local issue — solved by noon instead of discovered on the 31st.',
                'adv' => ['Absence handled the same day', 'Field discipline improves when watching is visible', 'Month-end payroll surprises disappear'],
            ],
            'att-report' => [
                'why' => 'This register IS the money — every salary is computed from it. A clean month here means a dispute-free payday; a messy one means a queue outside HR.',
                'uc' => 'Picture this: before generating payroll you scan the report, spot two agents marked absent on a day they were at the bank\'s office, fix it in Manual Attendance — two disputes prevented before they were born.',
                'adv' => ['Paydays without queues and arguments', 'Patterns (late Mondays, branch issues) become visible', 'Bank-ready attendance evidence for deployed staff'],
            ],
            'att-manual' => [
                'why' => 'Machines fail, networks drop, field days happen — but salary must still be right. Manual entry with approval is the safety valve that keeps the record complete without opening the door to misuse.',
                'uc' => 'Picture this: the branch biometric was down Tuesday. The TL uploads one Excel of 22 punches, the admin approves the batch, and Tuesday exists again — properly, with an approval trail.',
                'adv' => ['No salary cut for a machine\'s failure', 'Bulk fix in minutes, not row by row', 'Approval step keeps the record honest'],
            ],
            'biometric-devices' => [
                'why' => 'Devices are your unsleeping attendance clerks. A registry means you know what hardware you own, where it is, and whether its punches are flowing — before a silent device costs you a month of data.',
                'uc' => 'Picture this: the Karimnagar device dies quietly. Because it\'s registered and synced, you notice missing punches in two days — not on the 30th when forty salaries are suddenly uncertain.',
                'adv' => ['Hardware inventory under control', 'Silent failures caught early', 'Multi-branch attendance without manual collection'],
            ],
            'geofence' => [
                'why' => 'Field attendance fraud is the oldest game in collections — punch from home, claim the field. GPS + selfie inside a fence makes every punch a verified fact, not a claim.',
                'uc' => 'Picture this: an agent\'s punch shows the bank\'s collection center at 9:58 AM with his face. The client asks "was your man there?" — you answer with evidence, not assurance.',
                'adv' => ['Field attendance becomes provable', 'Proxy punching ends', 'Clients trust your deployment reports'],
            ],
            'shifts' => [
                'why' => 'Not everyone works 9:30 to 6:30 — collections teams run morning tele-calling shifts and night settlement desks. Named shifts mean each person is judged against THEIR timings, so the night team stops being "late" every single day.',
                'uc' => 'Picture this: the night desk works 22:00–06:00. You create a "Night" shift, set it on those six employees, and attendance instantly reads them correctly — plus the Rs 150/night allowance lands in payroll automatically for nights actually worked.',
                'adv' => ['Night and morning teams measured fairly', 'Roster overrides for week-wise rotation', 'Night allowance paid automatically per night worked'],
            ],
            'late-policy' => [
                'why' => 'Late-coming rules announced verbally are enforced emotionally — and emotionally means inconsistently, which means resentment. A wizard-built policy applies the same maths to everyone, every month.',
                'uc' => 'Picture this: the system deducts a half-day for three lates exactly as the policy says — for the TL\'s favourite and the new joiner alike. Nobody argues with maths that was announced in advance.',
                'adv' => ['Discipline without favouritism', 'Zero month-end manual calculations', 'Employees respect rules a machine applies equally'],
            ],
            'leave-apply' => [
                'why' => 'Unmanaged leave is how companies discover on payday that someone took nine days. Self-service leave with approval keeps the balance, the manager and the payroll in one truthful loop.',
                'uc' => 'Picture this: an agent applies for two days from his phone at night; his TL approves at breakfast; payroll treats it as paid leave automatically. Nobody filled a form, nobody forgot.',
                'adv' => ['Leave chaos becomes a clean workflow', 'Balances enforce themselves', 'Payroll always matches reality'],
            ],
            'leave-types' => [
                'why' => 'Leave policy is a promise to employees — types and quotas make the promise precise, so generosity has boundaries and boundaries have records.',
                'uc' => 'Picture this: you define 12 casual + 6 sick days. In August, an employee\'s request beyond balance is stopped by the system — the awkward conversation happened automatically, policy stayed intact.',
                'adv' => ['Policy enforced without confrontation', 'Paid vs unpaid is never a debate', 'New joiners see exactly what they get'],
            ],
            'holidays' => [
                'why' => 'A declared holiday calendar is cheap goodwill — everyone plans their year, and attendance/payroll treat those days correctly without a single instruction.',
                'uc' => 'Picture this: you publish the year\'s 12 holidays in January. In October, nobody asks "is Dussehra off?" and payroll pays it correctly without you remembering anything.',
                'adv' => ['Zero confusion on festival days', 'Payroll correctness on autopilot', 'Multi-company groups keep separate calendars cleanly'],
            ],
            'salary-setup' => [
                'why' => 'How a CTC splits decides PF, ESI, tax and take-home — get it wrong and you either overpay statutory or underpay people. Structures make the split a designed decision, not an accident.',
                'uc' => 'Picture this: you set basic at 50%, HRA 40% of basic, once. Every offer letter, every payslip, every PF challan after that follows the same correct maths — including the special structure for that one senior hire.',
                'adv' => ['Statutory correctness by design', 'Special cases without breaking the standard', 'Offer-to-payslip consistency, always'],
            ],
            'salary-schedules' => [
                'why' => 'Salary on a predictable date is the quietest retention tool that exists. Schedules make "when do we get paid?" a question nobody needs to ask.',
                'uc' => 'Picture this: cut-off on the 25th, pay on the 1st, every month, both companies. Employees plan EMIs around it; HR plans the run around it; nobody calls the owner about it.',
                'adv' => ['Predictability builds loyalty', 'Time reserved for fixing disputes pre-payroll', 'Group companies on disciplined rhythms'],
            ],
            'salary-gen' => [
                'why' => 'Manual payroll in Excel takes days and one formula error becomes forty angry people. One-click generation makes payday a process, not a project.',
                'uc' => 'Picture this: the 26th, you click Generate for 120 employees. Attendance, leave, late cuts, incentives, PF, ESI, TDS — computed in seconds. You spend your time REVIEWING, not calculating.',
                'adv' => ['Three days of Excel becomes thirty minutes', 'Calculation errors structurally impossible', 'Incentives fold in without separate maths'],
            ],
            'salary-approval' => [
                'why' => 'Money should never move on one person\'s click. The approval step is your four-eyes control — the same discipline banks demand of you, applied to your own payroll.',
                'uc' => 'Picture this: the approver compares this month\'s total to last month\'s, spots a ₹2 lakh jump, drills in, finds a wrongly-entered bonus — caught BEFORE the bank file went out, not after.',
                'adv' => ['Errors caught before money leaves', 'Owner control without owner workload', 'Clean separation of preparer and approver'],
            ],
            'payslip' => [
                'why' => 'A payslip is the one HR document every employee\'s family sees. Branded, accurate, e-signed payslips quietly tell the world your company is real and serious.',
                'uc' => 'Picture this: your agent applies for a two-wheeler loan. He downloads six branded payslips from his phone in the shop itself — loan approved, and he tells the story to the whole team.',
                'adv' => ['Employees get bank-ready documents instantly', 'E-sign trail proves delivery', 'Your brand on every family\'s table'],
            ],
            'pay-ledger' => [
                'why' => 'Where money is paid in parts — and in collections it often is — only a running passbook keeps trust. This ledger means you and the employee always agree on one number: the balance.',
                'uc' => 'Picture this: cash is tight, you pay 60% salaries on the 1st and 40% on the 10th. Every employee\'s ledger shows both payments and the zero balance — nobody is confused, nobody is suspicious.',
                'adv' => ['Partial payments without lost trust', 'One agreed balance ends all arguments', 'Complete money history per person, forever'],
            ],
            'deductions' => [
                'why' => 'Undocumented deductions are how good employers get bad reputations. A reasoned, dated deduction register keeps fairness visible.',
                'uc' => 'Picture this: a damaged device, ₹1,500 recovery, reason recorded. Three months later the employee asks; you show the entry with the date and reason. Conversation over in one minute.',
                'adv' => ['Every rupee deducted has a written why', 'Disputes die against documentation', 'Auditors see discipline, not adjustments'],
            ],
            'payout-recon' => [
                'why' => 'The bank file said paid; the bank sometimes disagrees. Reconciliation catches failed transfers before the employee catches them — at his EMI bounce.',
                'uc' => 'Picture this: two transfers failed for wrong IFSC. You catch them the same day, re-pay, and the employees never knew there was a problem — instead of discovering it through an angry call.',
                'adv' => ['Failed payments caught same-day', 'Employees never feel a banking error', 'Books match the bank, always'],
            ],
            'pay-cycle' => [
                'why' => 'Different teams, different pay rhythms — forcing one date on everyone breaks something. Cycles let each group have its correct rhythm without confusing the engine.',
                'uc' => 'Picture this: head office paid on the 1st, field force on the 7th after collections reconcile. Two cycles, two clean runs, zero cross-confusion.',
                'adv' => ['Pay rhythms match business reality', 'Each lot reviewed on its own', 'No all-or-nothing payroll days'],
            ],
        ];
    }

    private static function whysB(): array
    {
        return [
            'commissions' => [
                'why' => 'Incentives are the engine of collections — agents run on them. But unmanaged incentives are also the #1 source of disputes, favouritism claims and audit problems. This register makes every rupee of incentive earned, approved, taxed and paid — provably.',
                'uc' => 'Picture this: 26 agents, 140 commission entries this month. Each approved by the right manager, TDS cut automatically, folded into payslips — and any agent can see his own trail. Month-end incentive day goes from a war zone to a non-event.',
                'adv' => ['The motivation engine runs without disputes', 'TDS compliance automatic on every entry', 'Locked history — nobody can quietly change the past', 'The ledger answers any question in seconds'],
            ],
            'commission-calc' => [
                'why' => 'Calculating 100 agents\' payouts from collection sheets by hand takes a day and breeds errors that breed mistrust. The calculator makes the formula do the work — same rules for everyone.',
                'uc' => 'Picture this: the bank\'s MIS lands Monday. You upload it, pick the slab formula, preview, commit — 100 accurate entries in fifteen minutes, each still requiring approval.',
                'adv' => ['A day of Excel becomes fifteen minutes', 'One formula, applied equally to all', 'Preview-first means zero accidental payouts'],
            ],
            'incentive-schemes' => [
                'why' => 'A collections floor runs on offers — "2% on HDFC this month", "₹500 per settlement this week". Announced verbally, those offers become disputes; announced here, they become a machine: published once, every targeted agent notified, the claim form fills itself, and the scheme reports its own ROI.',
                'uc' => 'Picture this: month-end pressure on the ICICI bucket. The manager publishes "ICICI Final Push — 3% till the 30th" for Team Alpha at 10 AM. By 10:01 all 14 agents have the WhatsApp; their Live Salary card glows with the orange ribbon. Claims flow in pre-filled — no rate confusion, no "sir promised" arguments. On the 31st the scheme card reads: 38 claims, ₹1.4L paid, bucket closed.',
                'adv' => ['Verbal promises become published, provable offers', 'Targeted announcements — the right people, instantly', 'Claims pre-fill themselves: no wrong rates, no disputes', 'Per-scheme ROI: claims, approvals and ₹ on one line'],
            ],
            'expenses' => [
                'why' => 'Field work costs money out of agents\' pockets — and slow reimbursement is paying your most active people with frustration. A clean claim flow keeps your fielders fielding.',
                'uc' => 'Picture this: an agent spends ₹600 on petrol chasing a defaulter across town. He claims it that evening from his phone, the TL approves next morning, and it shows in his Live Salary the same day.',
                'adv' => ['Field staff never out-of-pocket for long', 'Every claim has an approval trail', 'Expense patterns become visible and controllable'],
            ],
            'advance' => [
                'why' => 'Salary advances happen in this industry — emergencies don\'t wait for payday. Doing them through the system means generosity with automatic recovery, instead of loose cash and awkward memories.',
                'uc' => 'Picture this: an agent\'s mother is hospitalised on the 12th. He gets ₹8,000 the same day, and on the 1st his payslip shows the recovery automatically. You helped; the books stayed perfect.',
                'adv' => ['Help employees fast without losing track', 'Recovery is automatic, never awkward', 'Total advances exposure visible anytime'],
            ],
            'loans' => [
                'why' => 'Staff loans build deep loyalty — IF the recovery is painless. EMI-from-salary makes lending to your people safe enough to actually do it.',
                'uc' => 'Picture this: your best TL needs ₹30,000 for school admission. Six EMIs of ₹5,000, auto-deducted on each payslip, balance visible to both of you. He stays loyal for years; you never chased one instalment.',
                'adv' => ['Loyalty-building loans without recovery headaches', 'Outstanding always visible', 'Exit settlement nets pending loans automatically'],
            ],
            'overtime' => [
                'why' => 'Unpaid extra hours are the fastest way to lose good field staff — and untracked overtime is the fastest way to lose money. A register that values the hours automatically and pays them on the payslip keeps both sides honest.',
                'uc' => 'Picture this: month-end pressure — three agents work Sunday to close a bank\'s recovery target. Their supervisor logs 8 hours each at 2x, the amounts compute themselves from each salary, and the payslips pay it without anyone chasing accounts.',
                'adv' => ['Extra effort is visibly and promptly rewarded', 'OT cost is measured, not invisible', 'The 2x convention is built in, applied consistently'],
            ],
            'clawbacks' => [
                'why' => 'In collections, money sometimes comes back — cheques bounce, settlements cancel. Clawbacks let you correct paid incentives formally, instead of either eating the loss or making shady adjustments.',
                'uc' => 'Picture this: a ₹50,000 settlement bounces after the agent\'s incentive was paid. One clawback entry, approved, recovered in the next payroll — the correction as documented as the original.',
                'adv' => ['Corrections without breaking locked history', 'Recovery with reasons, on the record', 'The audit trail stays sacred'],
            ],
            'bonus-enc' => [
                'why' => 'Diwali bonus and leave encashment paid as loose bank transfers disappear from the books. Routing them here keeps generosity inside the tax and ledger system where it belongs.',
                'uc' => 'Picture this: Diwali bonus for 80 staff, entered once, approved, paid through payroll with proper records. In April, the CA finds everything where it should be.',
                'adv' => ['Festive generosity, properly booked', 'TDS treated correctly on one-time payouts', 'Employee ledgers stay complete'],
            ],
            'increments' => [
                'why' => 'A raise without a letter is a rumour. The increment flow turns the most motivating moment in an employee\'s year into a formal, documented, instantly-applied event.',
                'uc' => 'Picture this: you approve a 16% raise for your star recovery officer. The branded letter is in her inbox before you finish your tea, and one click applies the new CTC from the 1st.',
                'adv' => ['The motivation moment, professionally delivered', 'CTC history documented raise by raise', 'No maths errors on the letter, ever'],
            ],
            'exits' => [
                'why' => 'How you handle leavers is watched by everyone who stays. A clean exit with a correct F&F protects you legally AND tells current staff this company settles fairly.',
                'uc' => 'Picture this: an agent resigns mid-month. The F&F computes pending salary and encashment minus his advance balance — settled in one screen, relieving letter issued, history preserved.',
                'adv' => ['Legal protection through complete settlements', 'Subscription seats free up correctly', 'Leavers become alumni, not enemies'],
            ],
            'pf-esic' => [
                'why' => 'PF and ESI are not optional — late or wrong filings bring penalties and block bank empanelments. Filing-ready registers turn a compliance burden into a five-minute routine.',
                'uc' => 'Picture this: the 10th of the month — your accountant opens the register, verifies, exports, files. No spreadsheet building, no UAN hunting.',
                'adv' => ['Penalty risk engineered away', 'Empanelment questionnaires answered instantly', 'Your accountant\'s day becomes an hour'],
            ],
            'tds' => [
                'why' => 'TDS errors are discovered by the tax department, never by you — and by then they cost. Per-person tracking keeps the deduction story straight all year.',
                'uc' => 'Picture this: Q2 return time. Salary TDS and commission TDS already separated and totalled — the return practically fills itself.',
                'adv' => ['Quarterly returns without panic', 'Commission TDS never missed', 'Employees\' 26AS always matches your books'],
            ],
            'pt' => [
                'why' => 'Professional tax is small money but real compliance — states notice defaulters. Slab automation makes it impossible to get wrong.',
                'uc' => 'Picture this: Telangana slabs applied automatically to every salary. The monthly payment figure is just there, correct, every month.',
                'adv' => ['Set-and-forget state compliance', 'Multi-state groups handled per company', 'One less thing to think about'],
            ],
            'gratuity' => [
                'why' => 'Gratuity is a silent liability that grows for years and lands as a shock at exit. Seeing it accrue means budgeting like a professional, not discovering like a victim.',
                'uc' => 'Picture this: your senior-most TL completes five years. You\'ve known the gratuity figure for a year — the F&F holds zero surprises.',
                'adv' => ['No exit-day financial shocks', 'Liability visible and budgetable', 'Statutory obligation met with dignity'],
            ],
            'tds-returns' => [
                'why' => 'Quarterly returns have hard deadlines and soft memories — a tracker makes sure the calendar never wins.',
                'uc' => 'Picture this: Q3\'s row shows filed, acknowledgement stored. When the CA asks in July, the answer is a screenshot, not an archaeology project.',
                'adv' => ['Deadlines never missed', 'Acknowledgements stored where they belong', 'Filing history at a glance'],
            ],
            'compliance-alerts' => [
                'why' => 'An agent with an expired DRA at a customer\'s door is a regulatory incident waiting for a complaint. Expiry radar means renewals happen BEFORE lapses — automatically watched.',
                'uc' => 'Picture this: the system emails that 3 DRAs expire within 30 days; renewals start the same week. At the bank\'s vendor audit, your screen is green — and the contract conversation is easy.',
                'adv' => ['Regulatory incidents prevented, not managed', 'Bank audits become showcases', 'Renewals driven by alerts, not memory'],
            ],
            'agent-auth' => [
                'why' => 'Every field agent must carry valid authorization — banks check, customers challenge, regulators ask. Tracked authorizations keep your people answerable at the door.',
                'uc' => 'Picture this: a defaulter challenges your agent — "who are you to collect?" The agent shows the authorization; the customer\'s lawyer later verifies the dates — everything holds.',
                'adv' => ['Field legitimacy, provable', 'Expiries surface before incidents', 'Client-wise deployment paperwork in one place'],
            ],
            'performance' => [
                'why' => 'People improve what gets measured and recorded. Review cycles turn vague impressions into a fair, documented basis for raises and promotions.',
                'uc' => 'Picture this: appraisal season — instead of arguments from memory, every TL has the year\'s ratings and notes, and your increments stand on written ground.',
                'adv' => ['Raises based on record, not recency', 'Underperformance documented early and fairly', 'Promotion decisions defensible'],
            ],
            'points-rules' => [
                'why' => 'Field teams are competitive by nature — gamification harnesses that. Clear point rules turn the daily grind into a game people want to win.',
                'uc' => 'Picture this: 10 points per ₹10,000 collected, 5 for a full-attendance week. Within a month, agents check the leaderboard before WhatsApp.',
                'adv' => ['Motivation that costs almost nothing', 'Behaviour follows the points you define', 'Energy shifts from complaints to competition'],
            ],
            'points-ledger' => [
                'why' => 'A game is only respected if the scoring is transparent. The ledger is the proof behind every leaderboard position.',
                'uc' => 'Picture this: an agent disputes his rank; you open his ledger — every point, dated and reasoned. The dispute becomes a handshake.',
                'adv' => ['Transparent scoring keeps the game alive', 'Manual awards carry reasons', 'History supports the awards night'],
            ],
            'points-scores' => [
                'why' => 'A leaderboard nobody sees motivates nobody. This screen is built to be SHOWN — on the floor TV, in the Monday meeting.',
                'uc' => 'Picture this: Monday 10 AM, leaderboard on the projector. The #2 agent spends the whole week hunting #1 — and your collections number thanks you.',
                'adv' => ['Peer pressure does the manager\'s job', 'Recognition without spending', 'Stars visible to management automatically'],
            ],
            'awards' => [
                'why' => 'Recognition remembered only in speeches evaporates. Recorded awards build each person\'s story in the company — and stories retain people.',
                'uc' => 'Picture this: two years later, deciding a promotion, you open the profile — Star of the Month ×3, Best Recovery Q2. The decision makes itself.',
                'adv' => ['Recognition that compounds over years', 'Promotion cases built on record', 'Award history motivates the rest'],
            ],
            'tests' => [
                'why' => 'An untrained agent is a compliance risk speaking your company\'s name. Tests prove training actually entered heads, not just attendance sheets.',
                'uc' => 'Picture this: after the RBI fair-practices session, a 10-question test. Three agents score under 50% — re-trained BEFORE they earn a complaint, not after.',
                'adv' => ['Training effectiveness measured, not assumed', 'Weak spots found before customers find them', 'Certification evidence for bank audits'],
            ],
            'test-reports' => [
                'why' => 'Scores unanalysed are effort wasted. Reports show who needs help and which topics need re-teaching — turning testing into improvement.',
                'uc' => 'Picture this: the whole Warangal team scores low on settlement rules. That\'s not 8 weak agents — that\'s one training gap, fixed in one session.',
                'adv' => ['Coaching aimed where needed', 'Systemic gaps separated from individual ones', 'Completion discipline enforced by visibility'],
            ],
            'training-programs' => [
                'why' => 'Banks ask "how do you train your agents?" — and "we tell them things" loses contracts. A structured curriculum, pre-loaded with RBI/DRA content, is a sales asset disguised as HR.',
                'uc' => 'Picture this: in an empanelment meeting you show the curriculum — DRA induction, fair practices, recovery etiquette — with completion records. The bank\'s compliance head visibly relaxes.',
                'adv' => ['A real curriculum from day one', 'Empanelment conversations get easier', 'Consistent training across branches'],
            ],
            'training-records' => [
                'why' => 'Training that isn\'t recorded didn\'t happen — in any audit\'s eyes. Records convert sessions into permanent compliance evidence.',
                'uc' => 'Picture this: an RBI-side query about conduct training. You export completion records with dates; the query closes in one reply.',
                'adv' => ['Audit-proof training evidence', 'Session gaps visible', 'New-joiner training tracked from week one'],
            ],
            'training-content' => [
                'why' => 'Knowledge locked in the trainer\'s head leaves with the trainer. Written lessons make your training survive people.',
                'uc' => 'Picture this: your trainer resigns. The next batch trains from the same written lessons — quality survives the exit.',
                'adv' => ['Training quality independent of individuals', 'Self-paced learning for field staff', 'One source, updated once'],
            ],
            'faqs' => [
                'why' => 'The same ten questions consume HR\'s month. FAQs answer them once, permanently, at midnight if needed.',
                'uc' => 'Picture this: salary day, an agent wonders about a deduction. He reads the late-policy FAQ instead of calling HR — one of fifty calls that never happened.',
                'adv' => ['HR time returned to real work', 'Consistent answers, not versions', 'An always-open answer desk'],
            ],
            'code-of-conduct' => [
                'why' => 'When an agent misbehaves at a customer\'s door, the first legal question is "did your company define conduct rules, and did he acknowledge them?" This screen makes both answers yes.',
                'uc' => 'Picture this: a harassment complaint reaches the bank. You produce the RBI-aligned code and the agent\'s dated acknowledgement — your defence starts strong.',
                'adv' => ['Legal protection from acknowledgements', 'Expectations explicit for everyone', 'RBI-aligned out of the box'],
            ],
            'letters-offer' => [
                'why' => 'Candidates judge a company by its offer letter — and chase-up calls kill momentum. Branded letters with one-tap acceptance close candidates while they\'re still excited.',
                'uc' => 'Picture this: walk-in Thursday, offer Friday morning, accepted from his phone by Friday lunch, joined Monday — before a competitor\'s call could reach him.',
                'adv' => ['Faster acceptance, fewer dropouts', 'Professional first impression', 'Acceptance status without phone chasing'],
            ],
            'letters-increment' => [
                'why' => 'The raise letter is the trophy employees show their families. Automatic, accurate, branded — it turns payroll arithmetic into a motivation event.',
                'uc' => 'Picture this: approval at 11 AM, letter in her inbox at 11:01. The moment of pride arrives at full speed.',
                'adv' => ['Zero gap between decision and delight', 'Figures always match the approved record', 'Archive ready for any reference'],
            ],
            'letters-warning' => [
                'why' => 'Discipline without documentation becomes he-said-she-said in any tribunal. Warning letters create the progressive record that protects fair employers.',
                'uc' => 'Picture this: a termination is challenged. You produce three dated warnings with specific incidents — the matter ends in the meeting room, not the court room.',
                'adv' => ['Progressive discipline, provable', 'Specific incidents beat vague accusations', 'Fairness visible to everyone watching'],
            ],
            'letters-relieving' => [
                'why' => 'A leaver\'s last document shapes their last impression — and their review of you. Prompt relieving letters close chapters cleanly.',
                'uc' => 'Picture this: F&F settled Monday, letter issued Monday. The next employer verifies; everything matches; your reputation compounds.',
                'adv' => ['Clean endings protect reputation', 'Details auto-filled — no manual errors', 'Alumni goodwill, cheaply earned'],
            ],
            'letters-templates' => [
                'why' => 'Letters written fresh each time drift — wording, clauses, tone. Templates lock your best version as the only version.',
                'uc' => 'Picture this: legal suggests one clause change. You edit the template once; every letter from tomorrow carries it.',
                'adv' => ['Consistency without vigilance', 'Legal changes applied once, everywhere', 'Placeholders kill copy-paste errors'],
            ],
            'send-message' => [
                'why' => 'Important instructions sent on WhatsApp groups drown in good-morning messages. Direct, recorded messages make critical communication land and stay provable.',
                'uc' => 'Picture this: a bank changes the deposit process effective tomorrow. You message all field staff tonight — recorded, dated. Tomorrow, "I didn\'t know" is not available.',
                'adv' => ['Critical instructions provably delivered', 'Targeted — only who needs it', 'A record where group chats have none'],
            ],
            'notice-board' => [
                'why' => 'A company\'s notice board is its public voice to employees. A live one — on every dashboard — keeps the whole group hearing the same thing at the same time.',
                'uc' => 'Picture this: revised office timing from Monday. One notice, every dashboard, all companies — and the WhatsApp rumour version never forms.',
                'adv' => ['One announcement, full reach', 'Rumours pre-empted by official word', 'Dated history of every announcement'],
            ],
            'messages' => [
                'why' => 'In disputes, "we informed them" is worth nothing without "on this date, by this channel." The log is your communication evidence room.',
                'uc' => 'Picture this: an exited employee claims he was never told about a policy. You retrieve the March entry from the log; the claim retires.',
                'adv' => ['Evidence of every communication', 'Searchable by person and date', 'Communication discipline made visible'],
            ],
            'wa-templates' => [
                'why' => 'WhatsApp is the only channel this industry\'s staff and candidates actually read — but it only allows pre-approved templates. This library is your licence to use the channel professionally.',
                'uc' => 'Picture this: walk-in templates approved once. Every hiring drive after that reaches 200 candidates on the app they check fifty times a day — legally, trackably.',
                'adv' => ['The highest-read channel, unlocked', 'Approval workflow with proof-by-test', 'Ready library — 17 HR moments covered'],
            ],
            'wa-settings' => [
                'why' => 'One API key stands between you and professional WhatsApp delivery. Configured here, every feature that wants to send — hiring, alerts, OTPs — just works.',
                'uc' => 'Picture this: key pasted once. Months later, hiring drives, lead alerts and demo OTPs all flow through it without anyone remembering it exists.',
                'adv' => ['One-time setup powers everything', 'Logged sends make failures debuggable', 'Provider switchable without touching features'],
            ],
            'sms-templates' => [
                'why' => 'SMS in India is regulated (DLT) — unregistered templates simply don\'t deliver. Organised IDs mean the day you switch SMS on, you\'re ready.',
                'uc' => 'Picture this: a bank insists on SMS confirmations. Your DLT templates are already registered and recorded — live in days, not months.',
                'adv' => ['Regulatory readiness in advance', 'No scrambling when SMS becomes required', 'Exact registered wording preserved'],
            ],
            'reports' => [
                'why' => 'Owners who export their numbers weekly run different companies than owners who feel them monthly. Reports turn operations into decisions.',
                'uc' => 'Picture this: the bank\'s monthly vendor review — attendance of deployed staff, compliance, headcount — exported and emailed in ten minutes, looking like a vendor twice your size.',
                'adv' => ['Decisions from data, not impressions', 'Client reporting at enterprise polish', 'Every module\'s numbers, one tap away'],
            ],
            'attrition' => [
                'why' => 'Churn is the silent tax on collections agencies — every leaver takes training money and portfolio knowledge. Measuring it is the first step to taxing it less.',
                'uc' => 'Picture this: attrition shows 8% overall but 30% in one branch. The problem was never "the industry" — it was one manager. Now you know.',
                'adv' => ['The real cost of churn made visible', 'Problem pockets located precisely', 'Retention efforts measurable'],
            ],
            'activity-logs' => [
                'why' => 'When something looks wrong — a changed record, a strange approval — the question is always "who and when?" Logs answer without interrogations.',
                'uc' => 'Picture this: a CTC looks edited. The log shows who, when, from what to what. A two-day mystery becomes a two-minute fact.',
                'adv' => ['Accountability without accusations', 'Mysteries resolved by record', 'Honest systems keep people honest'],
            ],
            'companies' => [
                'why' => 'Group structures are how this industry grows — different companies for different banks, risks and states. Running them under one roof with separate identities is the whole point.',
                'uc' => 'Picture this: three companies, one login. Each has its own payslips and branding for its clients, but you see consolidated numbers across the group every morning.',
                'adv' => ['Group view AND per-company separation', 'Employees move between companies cleanly', 'One subscription, whole empire'],
            ],
            'departments' => [
                'why' => 'Departments turn a list of people into an organisation. Filters, reports and transfers all become meaningful once people belong somewhere.',
                'uc' => 'Picture this: "how many in telecalling across the group?" — one filter. Without departments, that\'s a counting exercise.',
                'adv' => ['Structure makes reports meaningful', 'Headcount questions answered instantly', 'Function moves tracked'],
            ],
            'designations' => [
                'why' => 'Titles standardised once mean letters, ID cards and reports never embarrass you with a typo. Small master, big polish.',
                'uc' => 'Picture this: every offer letter, payslip and ID card spells every title identically — because titles exist in exactly one place.',
                'adv' => ['Professional consistency everywhere', 'Career ladders visible in data', 'Zero per-document typing'],
            ],
            'branches' => [
                'why' => 'Collections is geographic — branches are how you see performance by place and move people where the work is.',
                'uc' => 'Picture this: attendance by branch shows Karimnagar slipping; you visit Thursday. Without branch structure, you\'d have learned it from the bank.',
                'adv' => ['Geography visible in every report', 'Branch moves are two-minute transfers', 'Expansion is "add a branch", not chaos'],
            ],
            'banks' => [
                'why' => 'Your clients are the spine of the business — a clean client master means portfolios, escalations and authorizations all speak the same names.',
                'uc' => 'Picture this: "everything related to Axis" — escalations, authorized agents, deployments — groups perfectly because Axis exists exactly once in the system.',
                'adv' => ['Client-wise views that actually work', 'Professional consistency in client names', 'Foundation for per-client profitability'],
            ],
            'users' => [
                'why' => 'Every login is a door into your company\'s data. Managing doors — who has one, what it opens, when it closes — is security hygiene most companies skip until it hurts.',
                'uc' => 'Picture this: an HR executive resigns Friday; her login is disabled Friday 5 PM — not discovered active in November when someone wonders who downloaded the salary report.',
                'adv' => ['Data access controlled and revocable', 'Exits close doors immediately', 'Role-appropriate access for everyone'],
            ],
            'roles' => [
                'why' => 'Not everyone should see salaries; not everyone should delete records. The permission matrix turns trust from a feeling into a setting.',
                'uc' => 'Picture this: the new junior HR manages attendance and leave but cannot open payroll — configured in one minute, worried about never.',
                'adv' => ['Sensitive data on need-to-know basis', 'Mistakes limited by permissions', 'Trust granted precisely, not totally'],
            ],
            'branding' => [
                'why' => 'Documents carrying your logo work for your brand every time they\'re seen — in banks, at homes, in loan offices. Branding is free marketing riding on paperwork.',
                'uc' => 'Picture this: each group company\'s payslips and ID cards carry its own logo and colour. To each bank, each entity looks like a dedicated, established firm.',
                'adv' => ['Every document markets you', 'Multi-company identities kept distinct', 'Polish that suggests scale'],
            ],
            'company-emails' => [
                'why' => 'Emails from a generic address get ignored; emails that fail silently are worse. Proper SMTP per company means your letters and payslips actually arrive — from the right name.',
                'uc' => 'Picture this: offer letters from hr@apexcollections.in. Candidates and banks see a real company writing, and the mail log proves delivery.',
                'adv' => ['Deliverability you can verify', 'Each company writes as itself', 'Send Test prevents silent failures'],
            ],
            'fin-year' => [
                'why' => 'India\'s business memory runs April to March. FY-tagged records mean your books, comparisons and audits speak the language your CA and the government speak.',
                'uc' => 'Picture this: "show me FY 2025-26 commissions" — one dropdown. At audit time, the year is already organised instead of being assembled.',
                'adv' => ['Audit-season preparation: zero', 'Year-on-year comparisons built in', 'Clean cutoffs every April'],
            ],
            'my-subscription' => [
                'why' => 'Your subscription is business infrastructure — knowing usage and renewing on time protects the system your whole operation runs on.',
                'uc' => 'Picture this: you\'re at 72 of 75 employees with hiring planned. You upgrade mid-term, pay only the pro-rata difference, and the new hires onboard without hitting a wall.',
                'adv' => ['No surprise lockouts, ever', 'Upgrades cost only the difference', 'GST invoices ready for your books'],
            ],
            'settings' => [
                'why' => 'Defaults set once ripple through thousands of calculations. Getting the dials right here is leverage — one correct setting, twelve correct months.',
                'uc' => 'Picture this: the government revises an ESI rate. One change here; every payroll after is correct. No memos, no recalculations.',
                'adv' => ['One change fixes the future', 'Statutory updates in seconds', 'Engine behaviour under your control'],
            ],
            'platform-dashboard' => [
                'why' => 'You\'re not just running software — you\'re running a SaaS business. MRR, collections and dues are its vital signs, and owners who watch vitals grow companies.',
                'uc' => 'Picture this: morning glance — MRR up ₹9,000 from yesterday\'s signup, one renewal due this week. You know your business\'s pulse before your first call.',
                'adv' => ['Your SaaS health, one glance', 'Revenue movements visible same-day', 'The founder\'s scoreboard'],
            ],
            'tenants' => [
                'why' => 'Each tenant is a paying client whose whole HR runs on you. Knowing their plan, usage and status is knowing your customer book — the real asset of a SaaS business.',
                'uc' => 'Picture this: a client calls about an invoice. You open their tenant — plan, seats, GST state — and answer in thirty seconds like the professional platform you are.',
                'adv' => ['Customer book in one screen', 'GST profile drives correct invoices', 'Suspend/restore control when needed'],
            ],
            'plans' => [
                'why' => 'Pricing is strategy. The plans you set here ARE your public offer — clean tiers that customers understand buy themselves.',
                'uc' => 'Picture this: you adjust Growth\'s seat cap; the public signup reflects it instantly — strategy deployed without touching a developer.',
                'adv' => ['Pricing changes without code', 'Tiers aligned to how clients grow', 'The signup page always honest'],
            ],
            'subscriptions' => [
                'why' => 'Renewals are where SaaS revenue lives or dies. Watching expiries — with the system already reminding clients automatically — keeps churn a fight, not a surprise.',
                'uc' => 'Picture this: a client at 7 days. The system already mailed and WhatsApped them; your call is the gentle third touch that closes the renewal.',
                'adv' => ['Churn fought before it happens', 'Automatic reminders do the chasing', 'Grace/lock policy enforces fairly'],
            ],
            'invoices' => [
                'why' => 'GST-correct, sequenced invoices are the difference between a business and a hobby in your CA\'s eyes — and your clients\' finance teams demand them.',
                'uc' => 'Picture this: a client\'s accountant asks for last quarter\'s invoices. Three PDFs re-emailed in one minute, CGST/SGST split correct for their state.',
                'adv' => ['Clean books from day one', 'Client finance teams satisfied instantly', 'GST splits correct per client state'],
            ],
            'payments' => [
                'why' => 'Revenue not reconciled is revenue not real. The payment register keeps gateway money and book money agreeing.',
                'uc' => 'Picture this: month-end, you match this register against Razorpay\'s statement in ten minutes. The CA gets clean data; you get certainty.',
                'adv' => ['Gateway and books always agree', 'Manual bank-transfer clients recorded too', 'Reconciliation in minutes'],
            ],
            'gateways' => [
                'why' => 'The gateway keys are the pipe your revenue flows through. Configured right — live mode, webhook set — money moves while you sleep.',
                'uc' => 'Picture this: 11 PM, a client renews from their phone. Payment verified, invoice generated, subscription extended — you read about it at breakfast.',
                'adv' => ['Revenue collection runs unattended', 'Webhook recovers closed-browser payments', 'Secrets encrypted at rest'],
            ],
            'admin-landing' => [
                'why' => 'Your website is your hardest-working salesperson. The CMS means YOU control its words — pricing, numbers, testimonials — at the speed of thought, not the speed of a developer.',
                'uc' => 'Picture this: you decide to highlight a new feature at 9 PM. Edited, saved, live by 9:05 — the next morning\'s visitors see it.',
                'adv' => ['Marketing changes at your speed', 'No developer dependency for content', 'The shop window always current'],
            ],
            'admin-leads' => [
                'why' => 'Leads are perishable — a demo enquiry called within an hour converts many times better than one called next week. This work-list is sales discipline made visible.',
                'uc' => 'Picture this: a live-demo lead appears at 11 AM — OTP-verified mobile, company name. Your colleague calls at 11:20 while the prospect is still inside the demo. That\'s how deals start.',
                'adv' => ['No enquiry ever evaporates', 'Verified numbers — no fake leads', 'A funnel your team works, not a list they forget'],
            ],
            'admin-quotations' => [
                'why' => 'B2B deals stall in finance approvals — the quotation with a public pay link keeps YOUR deal alive inside THEIR process, payable the moment approval lands. And when a trusted client asks for a credit period, YOU record the payment (paid / partial / due) and the workspace goes live the same minute — the deal never waits for the gateway.',
                'uc' => 'Picture this: a Hyderabad agency\'s director agrees on the phone — "start us today, our accounts will transfer 50% Friday and the rest next month." You open the quote, record Partial with the due date, and their HR is importing employees within the hour. Friday\'s UTR goes in as a balance entry; the last instalment they pay themselves on the same quote link.',
                'adv' => ['Deals survive approval delays', 'Credit-period clients onboard the same day', 'Every rupee — online or offline — in one ledger'],
            ],
            'admin-coupons' => [
                'why' => 'A discount with no deadline is just a lower price — a COUPON is a reason to buy now. Codes create urgency ("first 50 signups"), reward annual commitment, arm channel partners, and tell you with hard numbers which marketing actually works.',
                'uc' => 'Picture this: you speak at a collections-industry meet in Mumbai and close the talk with "code MUMBAI30, thirty percent off, valid ten days, first twenty agencies." Next week the redemption log shows 14 signups against MUMBAI30 — you now know exactly what that stage slot earned, in rupees.',
                'adv' => ['Urgency and scarcity built into every campaign', 'Each code is its own ROI report — no guessing which ad worked', 'Partner and event codes without touching the price list'],
            ],
            'admin-staff' => [
                'why' => 'Platform staff can see every client\'s data — this list is your blast radius. Keeping it tiny and current is platform security in its simplest form.',
                'uc' => 'Picture this: you onboard one support person for renewals. One login, properly created — and removed the day they move on. Every client\'s trust, maintained.',
                'adv' => ['Total visibility controlled tightly', 'Clean joiner/leaver process for staff', 'Client trust protected structurally'],
            ],
            'admin-onprem' => [
                'why' => 'A perpetual licence is a one-time sale — unless the machinery around it turns it into a relationship. This desk is that machinery: invoice, payment, key, AMC, renewals — one chain, fully recorded. It is also exactly the audit trail a bank principal asks a vendor for.',
                'uc' => 'Picture this: a Pune agency agrees on SmartPRS-L2 at ₹2.5 lakh. You enter the client, click one button — invoice with pay link reaches their director. He pays online from his phone at 9 PM; by 9:01 the key is in their inbox and your engineer installs next morning. Nobody at Ametecs touched anything after the click.',
                'adv' => ['Sale-to-activation with zero manual steps on full payment', 'Partial-payment discretion stays personally yours, recorded', 'AMC renewals become one click — recurring revenue with no spreadsheet'],
            ],
            'admin-releases' => [
                'why' => 'Software you cannot update is software that dies at the client\'s site. This screen makes Ametecs a vendor whose product improves every month — on the cloud AND on every client server — which is precisely what AMC charges are for.',
                'uc' => 'Picture this: you fix a payroll rounding bug on Tuesday. Wednesday morning: one upload, Apply, Publish. By evening the cloud tenants, the demo, and nine on-prem clients are all running the fix — and each client got a friendly email crediting their AMC.',
                'adv' => ['One build updates cloud + every client', 'AMC becomes a visible, deliverable product', 'Backup + auto-rollback means updating is never a gamble'],
            ],
            'calc-logic' => [
                'why' => 'Every demo reaches the same moment: "but HOW do you calculate the salary?" A product that can answer that question from inside itself — in plain language, with the client\'s own examples — closes deals that a black box loses. This screen is that answer, and for employees it is payslip trust.',
                'uc' => 'Picture this: mid-demo, the client\'s accountant asks why one absent day cuts gross by 26 and not 30. You open the guide, search "26", and read the three day-value bases with the worked example — then switch the setting live to show their preferred style.',
                'adv' => ['Demo questions answered from inside the product', 'Employees understand their own payslip — fewer disputes', 'One printable document for every prospect'],
            ],
            'bank-pack' => [
                'why' => 'Month-end with a bank is a documents game: who collected what, what TDS was cut, and where is your service invoice. Agencies that walk in with a clean pack get paid faster and renewed easier — this screen builds the pack from data you already entered.',
                'uc' => 'Picture this: the 2nd of the month. You open the pack for ICICI–June, print the agent-wise register and the 194H annexure, raise the GST invoice with one edit, and the whole set is in the bank\'s inbox before lunch.',
                'adv' => ['Month-end pack in minutes, not days', 'TDS annexure matches the payroll registers exactly', 'Professional numbered GST invoices, remembered per bank'],
            ],
            'bonus' => [
                'why' => 'Statutory bonus is a legal obligation with personal penalties for default — and a morale moment when handled well. A live register turns a year-end scramble into a number you always know.',
                'uc' => 'Picture this: Diwali planning in October. The register already shows every eligible person and the provision total at your configured rate — finance budgets it in one meeting.',
                'adv' => ['Liability visible all year, not discovered in March', 'Eligibility and caps applied consistently', 'Pays through Bonus & Encashment with full records'],
            ],
            'helpdesk' => [
                'why' => 'Payroll doubts, ID card requests, leave confusions — unanswered, they become corridor complaints. A ticket desk turns them into a queue HR can actually clear.',
                'uc' => 'Picture this: an agent doubts a deduction on his payslip. He raises a ticket with the details in the Description box; HR answers once, in writing, and the record stays.',
                'adv' => ['Every request tracked to closure', 'Employees raise their own tickets from their login', 'Priorities keep urgent items on top'],
            ],
            'posh' => [
                'why' => 'The POSH Act 2013 requires every workplace with 10+ people to have a confidential complaint channel to an Internal Committee. A register that only the complainant and Admin/HR can see IS that channel — and your proof it exists.',
                'uc' => 'Picture this: an employee faces harassment but dreads walking into a cabin to say it. From her own login she files the complaint with date and description — it reaches the IC alone, and the inquiry trail is recorded from day one.',
                'adv' => ['Legally required channel, built in', 'Complainant and Admin/HR alone can see complaints', 'Status trail evidences a timely inquiry'],
            ],
            'sys-updates' => [
                'why' => 'Your AMC pays for SmartPRS to keep getting better — this screen is where you collect that value. Two clicks, automatic safety backup, and your HR system stays as current as the day you bought it.',
                'uc' => 'Picture this: the update email says "Payslip PDF now shows the new PF rate". Your admin opens this screen with morning chai, clicks Check, clicks Apply, and by 9:35 the month\'s payslips go out correct — no consultant, no downtime, no fees.',
                'adv' => ['Always-current statutory compliance', 'Two-minute self-service updates', 'Automatic backup means zero risk to your data'],
            ],
        ];
    }

    /** Generic module-level fallback so the popup NEVER comes up empty. */

    /**
     * rev182 / Enhancement Roadmap (26 Jul 2026) — help for the screens added in
     * this release: notifications, attendance correction, probation, greetings,
     * the import wizard and absence alerts.
     */
    private static function roadmapModule(): array
    {
        return [
            'notifications' => [
                'm' => 'Main', 't' => 'Notifications', 'g' => 'Everything waiting for your attention, in one place',
                'w' => 'Your inbox inside SmartPRS. Approvals waiting on you, probation dates coming up, birthdays and anniversaries that went out, and days where someone had no attendance — all land here instead of getting lost in email. Unread items are highlighted and the bell at the top shows the count. Clicking an item takes you straight to the screen where you can act on it.',
                'f' => [['fa-bell', 'Live unread count on the topbar bell'], ['fa-arrow-pointer', 'Click through to the screen that needs you'], ['fa-check-double', 'Mark all read in one click'], ['fa-envelope', 'The same alert also goes out by email']],
                's' => ['Watch the bell — a red number means something needs you.', 'Click any item to open the screen it refers to; it is marked read automatically.', 'Use "Mark all read" after a catch-up session.'],
                'tip' => 'Notifications are per user. What you see here is what YOUR role and your team are responsible for — not the whole company.',
                'r' => ['All roles'], 'rel' => 'approvals-inbox',
            ],
            'att-correction' => [
                'm' => 'Time & Attendance', 't' => 'Attendance Correction', 'g' => 'Fix a wrong or missing attendance day, with approval',
                'w' => 'When the biometric misses a punch, someone forgets their card, or a person spends the day on official outdoor duty, this is where it gets put right — properly. The employee raises a request showing what the system currently has and what it should be, their manager or HR approves (adjusting the times if needed), and only then is the attendance record updated. Nothing is edited silently: every correction keeps the original value, the requested value and what was finally approved.',
                'f' => [['fa-pen-to-square', 'Seven correction types, from missed punch to on-duty'], ['fa-clock-rotate-left', 'Original, requested and approved times all kept'], ['fa-user-check', 'Manager or HR approves, and can adjust the times'], ['fa-ban', 'Cancel your own request while it is still pending'], ['fa-lock', 'Blocked months are flagged instead of quietly changed']],
                's' => ['Pick the date — the screen shows what is currently on record for you.', 'Choose what went wrong, enter the correct in/out time, and give a reason.', 'Submit. Your approver is notified by email and in the app.', 'Once approved, your attendance is updated automatically.'],
                'tip' => 'If the payroll for that month is already finalised, the correction is still approved but recorded for the next adjustment — closed payroll is never silently rewritten.',
                'r' => ['Employee (own)', 'Manager', 'HR', 'Admin'], 'rel' => 'att-report',
            ],
            'probation' => [
                'm' => 'People', 't' => 'Probation Tracking', 'g' => 'Nobody stays on probation because everyone forgot',
                'w' => 'Every employee still on probation, with the date it ends and how many days are left — overdue ones in red. From here you either confirm the person as permanent or extend them with a reason. Confirming matters for more than morale: while someone is on probation their PF, PT and TDS are skipped, so a forgotten confirmation quietly means wrong statutory deductions month after month.',
                'f' => [['fa-hourglass-half', 'Days-left countdown, overdue highlighted'], ['fa-user-check', 'Confirm as permanent in one click'], ['fa-calendar-plus', 'Extend with a mandatory reason'], ['fa-bell', 'Automatic reminders to HR and the reporting manager'], ['fa-clock-rotate-left', 'Full history of every confirmation and extension']],
                's' => ['Review who is due in the next few weeks.', 'Confirm the ones who have passed — statutory deductions resume from the next payroll.', 'Extend anyone who needs more time, with a clear written reason.'],
                'tip' => 'Reminders go out at 30, 15, 7 and 0 days before the end date, plus a nudge once it passes. Change those under Probation Settings.',
                'r' => ['HR', 'Manager', 'Admin'], 'rel' => 'emp-list',
            ],
            'probation-config' => [
                'm' => 'Administration', 't' => 'Probation Settings', 'g' => 'How long probation runs, and who gets reminded',
                'w' => 'Set your company default probation period and the reminder schedule once, and every new joiner follows it automatically. An individual employee can still be given a different period where their role calls for it. You also decide who hears about it: HR, the reporting manager, or both.',
                'f' => [['fa-hourglass-start', 'Default probation months for the company'], ['fa-bell', 'Reminder milestones you choose'], ['fa-users', 'Notify HR, the manager, or both'], ['fa-cake-candles', 'Optional congratulations to the employee on confirmation']],
                's' => ['Set the default number of months.', 'Enter reminder days, e.g. 30, 15, 7, 0.', 'Choose who should be notified, and save.'],
                'tip' => 'Reminders are per employee and per milestone, so nobody is spammed even if the sweep runs more than once.',
                'r' => ['HR Manager', 'Admin'], 'rel' => 'probation',
            ],
            'greetings' => [
                'm' => 'Administration', 't' => 'Greetings', 'g' => 'Birthday and anniversary wishes that go out on their own',
                'w' => 'Small gesture, real effect on how people feel about the place they work. Write the birthday and work-anniversary messages once, choose the hour they should go out, and SmartPRS sends them automatically — by email and in the app. Placeholders fill in the person\'s name, company and years of service. Nothing sends until you switch it on, and you can preview or send yourself a test first.',
                'f' => [['fa-cake-candles', 'Birthday and work-anniversary messages'], ['fa-code', 'Placeholders for name, company and years'], ['fa-eye', 'Preview before anything goes out'], ['fa-paper-plane', 'Send yourself a test'], ['fa-clock', 'Choose the hour it sends']],
                's' => ['Write the subject and message, using the placeholders shown.', 'Click Preview to see it filled in, or send yourself a test.', 'Tick "Send greetings automatically", pick the hour, and Save.'],
                'tip' => 'Preview and test never save your settings — only the Save button does. So you can try wording out safely without switching greetings on.',
                'r' => ['HR Manager', 'Admin'], 'rel' => 'greetings-log',
            ],
            'greetings-log' => [
                'm' => 'Administration', 't' => 'Greetings Log', 'g' => 'Proof of what was actually sent',
                'w' => 'Every greeting that went out, who it was for, and whether the email was delivered. Useful when someone says they never got their birthday wish, and useful for checking that a newly configured message is actually reaching people.',
                'f' => [['fa-envelope-open-text', 'Every greeting sent, newest first'], ['fa-circle-check', 'Email delivery status per message']],
                's' => ['Open after the first day greetings are live and confirm messages went out.', 'If email status shows failed or skipped, check your SMTP settings.'],
                'tip' => 'A "skipped" status usually means the employee has no email address on record.',
                'r' => ['HR Manager', 'Admin'], 'rel' => 'greetings',
            ],
            'emp-import' => [
                'm' => 'People', 't' => 'Import Employees', 'g' => 'Bring a staff list in from Excel, safely',
                'w' => 'A guided import that shows you exactly what will happen before anything is written. Upload your file, SmartPRS reads the headings and guesses which column is which, you correct anything it got wrong, and then it checks every row and tells you how many will be added, how many updated, and which rows have problems. Only when you are satisfied does the import actually run — and it runs all-or-nothing, so a bad row halfway through can never leave you with half a staff list.',
                'f' => [['fa-file-import', 'CSV or Excel'], ['fa-wand-magic-sparkles', 'Column matching guessed for you'], ['fa-list-check', 'See the result before importing'], ['fa-shield', 'All-or-nothing: it rolls back on failure'], ['fa-file-csv', 'Download a report of the rows that failed']],
                's' => ['Choose your file and click Read file.', 'Check the column matching — Name is required, the rest are optional.', 'Decide what happens to employees who already exist: update, leave alone, or add as new.', 'Click Check the file, read the counts, then Import.'],
                'tip' => 'Dates can be written 13/05/2024 or 2024-05-13 — both are understood. Impossible dates like 31/02 are reported instead of being silently accepted.',
                'r' => ['HR Manager', 'Admin'], 'rel' => 'emp-list',
            ],
            'absence-config' => [
                'm' => 'Time & Attendance', 't' => 'Absence Alerts', 'g' => 'Tell people the same day, not on payday',
                'w' => 'Every working morning SmartPRS looks at the previous day and quietly tells anyone who had no attendance at all — with a link to raise a correction. People on approved leave, holidays and weekly offs are never flagged. The point is to catch a missed punch while the person still remembers the day, instead of discovering it as a salary cut three weeks later.',
                'f' => [['fa-user-clock', 'Checks the previous working day automatically'], ['fa-clock', 'Runs at the hour you choose'], ['fa-calendar-check', 'Skips leave, holidays and weekly offs'], ['fa-pen-to-square', 'Links straight to Attendance Correction'], ['fa-play', 'Run it manually for any date']],
                's' => ['Set the hour — late morning is safest so biometric imports have landed.', 'Set your working days, and choose whether managers are told too.', 'Switch it on and Save.', 'Use "Run now for yesterday" to see how many people it would flag today.'],
                'tip' => 'Run it manually first. If it flags far more people than you expect, your attendance import is probably not arriving before the send hour.',
                'r' => ['HR Manager', 'Admin'], 'rel' => 'att-correction',
            ],
            'statutory-config' => [
                'm' => 'Indian Statutory', 't' => 'Statutory Configuration', 'g' => 'Change a PF, ESI, PT or TDS rate from a date, for one scope, without a code release',
                'w' => 'The rates that drive payroll — PF, ESI, Professional Tax and TDS — used to be fixed in one place with no history and no way to differ by company or state. This screen adds three things a group actually needs. First, effective dating: a rate you set here applies from the date you choose, so regenerating an earlier month still uses that month rate instead of silently applying today one. Second, scope: an override can apply to one branch, one location (a branch city), one company, one state, or the whole tenant, and the narrowest one wins. Third, editable Professional Tax slabs, so a state that revises its slab, or a state not built in at all, is a setting you change rather than a release you wait for. With nothing saved here, payroll behaves exactly as it does today.',
                'f' => [['fa-scale-balanced', 'PF, ESI, PT and TDS overrides in one place'], ['fa-calendar-day', 'Every change is effective from a date you choose'], ['fa-layer-group', 'Scope by branch, location, company, state or tenant'], ['fa-table-cells', 'Editable Professional Tax state slabs'], ['fa-eye', 'Preview the effect on a month before you save'], ['fa-clock-rotate-left', 'Full history of every rate change']],
                's' => ['Pick which rates you are changing, and who they apply to.', 'Set the effective-from date, usually the first of the month the change begins.', 'Enter the new values, then click Preview impact to see what it does to a month payslips.', 'Save. The override applies from that date on; earlier months are untouched.'],
                'tip' => 'Only Professional Tax genuinely differs by state in law; PF, ESI and TDS are the same across India, so scope those only for your own business reasons. Preview never saves, only Save does.',
                'r' => ['HR Manager', 'Admin'], 'rel' => 'pf-esic',
            ],
        ];
    }

    /** rev182 — "Why it matters" for the screens added in this release. */
    private static function whysRoadmap(): array
    {
        return [
            'notifications' => [
                'why' => 'Work does not stall because people refuse to act — it stalls because nobody told them it was their turn. One place that says "this is waiting on you" removes the most common excuse in any office.',
                'uc' => 'Picture this: an employee\'s probation ended eleven days ago and nobody noticed, so PF and PT still are not being deducted. The reminder was sitting in the bell the whole time. Now it is the first thing HR sees at 9 AM.',
                'adv' => ['Nothing waits on someone remembering', 'The same alert reaches them by email too', 'One click from the alert to the action'],
            ],
            'att-correction' => [
                'why' => 'A machine failure should never cost an honest worker a day\'s pay. But letting HR edit attendance freely destroys the register\'s credibility. A request-and-approve trail gives you both: mistakes get fixed, and every fix has a name and a reason behind it.',
                'uc' => 'Picture this: the device was down on Tuesday and fourteen people show as absent. Instead of fourteen arguments on payday, each raises a correction, the manager approves them in one sitting, and the register is right before payroll runs.',
                'adv' => ['Genuine mistakes get corrected fast', 'Every change is requested, approved and logged', 'Closed payroll is never quietly rewritten'],
            ],
            'probation' => [
                'why' => 'A forgotten confirmation is not just an HR courtesy that was missed — while someone sits on probation their PF, PT and TDS are skipped. Every month nobody notices is a month of wrong statutory deductions to unwind later.',
                'uc' => 'Picture this: an employee completed probation in March and it was never confirmed. In September the auditor asks why there is no PF for six months. With reminders at 30, 15 and 7 days, that conversation never happens.',
                'adv' => ['Statutory deductions start exactly when they should', 'Employees are not left in limbo', 'Extensions are documented, not verbal'],
            ],
            'probation-config' => [
                'why' => 'Policies that live in someone\'s head get applied inconsistently. Setting the probation period and reminder schedule once means every joiner is treated the same way without anyone having to remember the rule.',
                'uc' => 'Picture this: two people join the same week. One is confirmed on time, the other drifts four months because a different manager handled it. One default setting removes that entirely.',
                'adv' => ['Same rule for everyone', 'Exceptions are deliberate, not accidental', 'No dependence on any one person\'s memory'],
            ],
            'greetings' => [
                'why' => 'Retention is not won with big gestures — it is won by people feeling noticed. A birthday message that arrives on time, every time, costs nothing and is remembered. One that is forgotten for half the staff is worse than none at all.',
                'uc' => 'Picture this: a field agent who rarely visits head office gets a birthday message from the company at 9 AM. It takes five seconds to read and it is the reason he tells a friend this is a decent place to work.',
                'adv' => ['Nobody is ever missed', 'Consistent tone across the whole company', 'Zero effort once it is set up'],
            ],
            'greetings-log' => [
                'why' => 'Automation you cannot see is automation you cannot trust. The log is how you know the messages are genuinely landing, not silently failing on a bad SMTP setting.',
                'uc' => 'Picture this: greetings have been on for a month. The log shows every message sent — except three staff with no email on record, which is now an easy fix.',
                'adv' => ['Proof the automation is working', 'Delivery failures surface immediately', 'Easy to spot missing employee emails'],
            ],
            'emp-import' => [
                'why' => 'Most bad payroll data enters on day one, through a careless import. Showing exactly what will happen before it happens turns the riskiest data operation in the system into a routine one.',
                'uc' => 'Picture this: onboarding 120 staff from a client handover sheet. The check tells you 118 will be added, 2 rows have invalid joining dates. You fix two cells instead of hunting bad records across the system for weeks.',
                'adv' => ['See the outcome before committing', 'A failed import leaves no half-loaded mess', 'Bad rows come back as a list you can fix'],
            ],
            'absence-config' => [
                'why' => 'The cost of an unnoticed missing punch is not the day — it is the argument on payday, and the trust lost when the company appears to be docking pay unfairly. Telling people the next morning turns a dispute into a two-minute correction.',
                'uc' => 'Picture this: an agent forgets to punch on Thursday. Friday at 11 AM he gets a note, raises a correction, and his manager approves it that afternoon. Payday passes without a single query.',
                'adv' => ['Problems fixed while people still remember the day', 'Far fewer payday disputes', 'Managers see attendance gaps early'],
            ],
            'statutory-config' => [
                'why' => 'A statutory rate is not something you should have to redeploy software to change, and it is not something that should quietly rewrite months you have already paid. Effective-dated, scoped overrides mean a PF or PT change is a two-minute setting that starts exactly when the law or your policy says it should, and leaves last quarter alone.',
                'uc' => 'Picture this: a state revises its Professional Tax slab from 1 August. You open this screen, edit that state slab effective 1 August, preview the effect on August payroll, and save. September onwards is correct, July is untouched, and there was no release to wait for.',
                'adv' => ['Rate changes without a software release', 'Earlier months are never silently rewritten', 'A different position per company or state where you need it'],
            ],
        ];
    }

    /** rev182 — "Do it right" warnings for the screens added in this release. */
    private static function roadmapMistakes(): array
    {
        return [
            'att-correction' => [
                ['Approving corrections without checking anything', 'The approval becomes a rubber stamp and the register stops meaning anything', 'Look at the original value on the row before approving — if the person claims a full day, ask why there is no punch at all'],
                ['Letting requests sit for weeks', 'Payroll runs on stale attendance and the correction arrives too late to help', 'Clear pending corrections before the payroll cut-off, not after'],
            ],
            'probation' => [
                ['Ignoring the overdue list', 'Probation employees keep skipping PF, PT and TDS — every month adds to the correction you will owe', 'Clear anything red the same week it appears'],
                ['Extending probation verbally', 'The employee remembers a different conversation and you have nothing in writing', 'Always extend on this screen with a written reason — it is kept in the history'],
            ],
            'greetings' => [
                ['Switching it on without a test', 'A wrong placeholder or an SMTP problem reaches the whole company at once', 'Send yourself a test first, then check the log the next morning'],
                ['Writing messages that read like a machine', 'People notice instantly and it has the opposite effect to the one you wanted', 'Keep it short and human — use the first name, not the full name'],
            ],
            'emp-import' => [
                ['Importing without reading the check', 'Duplicate or half-filled employee records spread into attendance and payroll, and are painful to unwind', 'Always read the add/update/skip counts and the problem rows before importing'],
                ['Choosing "add as new" for people who already exist', 'You get two records for one person and their attendance splits between them', 'Use update for an existing staff list; add-as-new is only for a genuinely fresh batch'],
            ],
            'absence-config' => [
                ['Setting the send hour too early', 'Alerts go out before the biometric import lands and half the company is wrongly told they were absent', 'Late morning is safest — run it manually once and check the numbers look sane'],
                ['Turning it on without telling anyone', 'People receive an unexplained absence email and assume their pay is being cut', 'Tell staff what the alert is and what to do about it before you enable it'],
            ],
            'statutory-config' => [
                ['Setting the effective-from date to today for a change that began earlier', 'The months in between keep the old rate and quietly disagree with the law', 'Date the override from the month the change actually took effect, not the day you enter it'],
                ['Changing a rate without previewing the impact', 'A wrong figure reaches every payslip on the next run and is painful to unwind', 'Always click Preview impact first; it shows the rupee effect on a real month before you commit'],
            ],
        ];
    }

    private static function fallback(string $key): array
    {
        return [
            'm' => 'SmartPRS', 't' => 'About this screen', 'g' => 'Part of your SmartPRS workspace',
            'w' => 'This screen is part of your SmartPRS workspace. Records you create here follow the same pattern as everywhere else: add or import entries, edit them with the pencil icon, and remove them with the bin icon where your role allows it.',
            'f' => [['fa-plus', 'Add new records'], ['fa-pen', 'Edit existing rows'], ['fa-file-import', 'Import from Excel/CSV where available'], ['fa-magnifying-glass', 'Search and filter']],
            's' => ['Use the button at the top right to add a record.', 'Click the pencil on any row to edit it.', 'Use the search box or filters to find records quickly.'],
            'tip' => 'What you can see and change here depends on your role — admins can adjust this under Administration → Roles & Permissions.',
            'r' => ['Per your role'],
            'rel' => '',
        ];
    }

    private static function content(): array
    {
        return array_merge(
            self::roadmapModule(),
            self::mainModule(),
            self::peopleModule(),
            self::hiringModule(),
            self::attendanceModule(),
            self::leaveModule(),
            self::payrollModule(),
            self::compensationModule(),
            self::statutoryModule(),
            self::performanceModule(),
            self::learningModule(),
            self::lettersModule(),
            self::communicationModule(),
            self::reportsModule(),
            self::adminModule(),
            self::saasModule(),
            self::adminPanelModule()
        );
    }

    // ================= ADMIN PANEL (Laravel pages: /admin/*) =================
    private static function adminPanelModule(): array
    {
        return [
            'admin-landing' => [
                'm' => 'SaaS Platform', 't' => 'Landing Page (CMS)', 'g' => 'Edit your public website without code',
                'w' => 'Everything on smartprs.com — hero text, the 16 feature cards, pricing cards, testimonials, contact numbers, the WhatsApp button and lead-alert recipients — is edited here. Changes go live the moment you save.',
                'f' => [['fa-globe', 'Hero, features & pricing text'], ['fa-phone', 'Contact & WhatsApp numbers'], ['fa-bullseye', 'Lead-alert recipients'], ['fa-eye', 'Open-site preview link']],
                's' => ['Edit the fields you need — keep pricing-card lines free of commas inside amounts.', 'Set the WhatsApp number and where lead alerts go (email + WhatsApp).', 'Save, then open the site in a new tab and hard-refresh to confirm.'],
                'tip' => 'Saved content WINS over code defaults — after a deploy that changes defaults, re-save this page once to pick them up.',
                'r' => ['Super Admin'],
                'rel' => 'Also see: Leads, Quotations',
            ],
            'admin-leads' => [
                'm' => 'SaaS Platform', 't' => 'Leads (Demo Requests)', 'g' => 'Every website enquiry, ready to work',
                'w' => 'Everyone who filled the demo form, clicked the WhatsApp button with details, or entered the Live Demo (OTP-verified) lands here — with their contact details, source, and a status you work through: New → Contacted → Closed.',
                'f' => [['fa-bullseye', 'All enquiries with source'], ['fa-filter', 'Status filter chips'], ['fa-phone', 'One-tap call / WhatsApp / email'], ['fa-pen', 'Notes per lead']],
                's' => ['Start each morning on the New filter.', 'Call or WhatsApp from the row links; note what happened.', 'Move the status forward — Closed means decided, either way.'],
                'tip' => 'Live-demo leads have OTP-VERIFIED mobile numbers — call those first, they are the warmest.',
                'r' => ['Super Admin / platform staff'],
                'rel' => '',
            ],
            'admin-quotations' => [
                'm' => 'SaaS Platform', 't' => 'Quotations', 'g' => 'Quotes, offline payments & credit clients',
                'w' => 'Every quotation from the signup page — with its public pay link and PDF. The client pays online through the link, OR you press Record payment and enter what actually happened: Paid (full amount received offline), Partial (part now, balance on credit), or Due (whole amount on a credit period). Either way the workspace is created immediately. Credit clients stay in the second table until their balance is cleared.',
                'f' => [['fa-cash-register', 'Record offline payment (paid/partial/due)'], ['fa-hand-holding-dollar', 'Balance instalment entries'], ['fa-link', 'Public pay link — works for the balance too'], ['fa-hourglass-half', 'Expiry + overdue highlighting']],
                's' => ['Client paid by bank transfer / UPI / cheque / cash, or asked for credit? Open Record payment on the quote.', 'Pick Paid, Partial or Due; enter the amount received, how it came, the UTR/cheque reference, and the credit due date.', 'Save — tenant, subscription, GST invoice and payment entry are all created; the welcome email with sign-in details goes out.', 'Watch the Credit clients table: record balance instalments as they arrive, or let the client pay the balance on the same quote link. Overdue turns red — follow up personally.'],
                'tip' => 'Nothing locks automatically on an overdue balance — credit is YOUR commercial call, the red highlight is your reminder. The invoice flips to PAID by itself the moment received money covers the total.',
                'r' => ['Super Admin / platform staff'],
                'rel' => 'Also see: Subscriptions, Invoices, Payments',
            ],
            'admin-coupons' => [
                'm' => 'SaaS Platform', 't' => 'Discount Coupons', 'g' => 'Campaign codes that sell and track themselves',
                'w' => 'Create discount codes for marketing campaigns — a percentage or a flat ₹ amount off, working on new signups, renewals, or both. Each code carries its own rules: expiry date, maximum uses, minimum advance period, specific plans, one-use-per-customer. Every redemption is recorded below with the company it brought, so a code doubles as campaign tracking.',
                'f' => [['fa-ticket', 'Percent or flat ₹ codes'], ['fa-calendar-xmark', 'Expiry + max-use limits'], ['fa-filter', 'Plan / cycle / context rules'], ['fa-chart-line', 'Redemption log per campaign']],
                's' => ['Create the code with its discount and limits — give it a campaign note like "June LinkedIn ads".', 'Share the code in the ad, WhatsApp broadcast or with a partner.', 'The prospect types it on the signup page (or a client in their renewal dialog) — price drops instantly, server re-checks everything at payment.', 'Watch the redemption log to see which campaign actually brings clients; Disable the code when the campaign ends.'],
                'tip' => 'Coupons stack ON TOP of the advance-payment discount — an annual payer with LAUNCH10 gets both. Price that into the campaign before publishing the code.',
                'r' => ['Super Admin'],
                'rel' => 'Also see: Landing Page (CMS), Quotations',
            ],
            'admin-staff' => [
                'm' => 'SaaS Platform', 't' => 'Platform Staff', 'g' => 'Who runs the platform',
                'w' => 'Super-admin and platform staff logins — the people who manage tenants, billing and the website. Separate from any tenant\'s own users.',
                'f' => [['fa-user-shield', 'Add / edit staff logins'], ['fa-toggle-off', 'Disable on exit']],
                's' => ['Add a staff member with their email — they set their password by invite.', 'Disable immediately when someone leaves the team.'],
                'tip' => 'Keep this list tiny — every login here can see every tenant.',
                'r' => ['Super Admin'],
                'rel' => '',
            ],
            'admin-onprem' => [
                'm' => 'SaaS Platform', 't' => 'On-Prem Clients & Licences', 'g' => 'The perpetual-licence sales desk',
                'w' => 'Every on-premise client lives here: their details and edition, the invoice with its online pay link, payments received, and the licence key with its AMC expiry. The flow is one straight line — record the client, email the invoice, payment arrives (online or recorded manually), the key generates and is emailed.',
                'f' => [['fa-building-lock', 'Client record with edition & price'], ['fa-file-invoice', 'GST invoice + secure pay link'], ['fa-indian-rupee-sign', 'Payments (gateway or manual)'], ['fa-key', 'Key generation + email'], ['fa-rotate', 'AMC renewal +1 year'], ['fa-server', 'Server-move release & revoke']],
                's' => ['Add the client with edition, employee band, price and AMC % (GST adds automatically).', 'Click "Email invoice + pay link" — the client can pay online; full online payment issues the key BY ITSELF.', 'For NEFT/cheque, record the payment here; partial payment needs your "Activate on partial" tick before a key is allowed.', 'When they change servers, use "Release server binding"; renew AMC each year from the same row.'],
                'tip' => 'Full payment through the pay link is hands-free: payment verifies, the key generates, and the email goes — you only watch.',
                'r' => ['Super Admin'],
                'rel' => 'Also see: Releases & Updates',
            ],
            'admin-releases' => [
                'm' => 'SaaS Platform', 't' => 'Releases & Updates', 'g' => 'One upload updates everyone',
                'w' => 'Upload the release zip from BUILD-RELEASE.bat and two buttons do the rest: "Apply to platform" updates THIS server (every tenant, the demo and teamdemo together — with automatic backup and rollback), and "Publish to clients" grants the release to all AMC-active on-prem clients and emails each of them.',
                'f' => [['fa-cloud-arrow-up', 'Upload + checksum register'], ['fa-shield-halved', 'Apply with backup & auto-rollback'], ['fa-envelope', 'Grant + update emails (AMC-gated)'], ['fa-list-check', 'Client update activity log']],
                's' => ['Bump the version in config, run BUILD-RELEASE.bat, upload the zip with a plain-language changelog.', 'Apply to platform — maintenance mode for about two minutes, then everyone is on the new version.', 'Publish to clients — AMC-active clients are granted and emailed automatically.', 'Watch the activity log to confirm clients applied it.'],
                'tip' => 'Lapsed-AMC clients are skipped silently — their Updates screen shows a polite renewal message instead. Updates are your AMC product; this screen is where that revenue is earned.',
                'r' => ['Super Admin'],
                'rel' => 'Also see: On-Prem Clients & Licences',
            ],
            'sys-updates' => [
                'm' => 'Administration', 't' => 'Updates & Licence', 'g' => 'Your SmartPRS stays current here',
                'w' => 'Shows the version you are running, your licence and AMC validity, and any update Ametecs has released for you. Updating is two clicks: Check, then Apply — SmartPRS backs itself up first and restores itself automatically if anything goes wrong.',
                'f' => [['fa-code-branch', 'Current version & licence'], ['fa-rotate', 'Check for updates'], ['fa-circle-down', 'One-click apply with auto-backup'], ['fa-clock-rotate-left', 'Update history']],
                's' => ['When the update email arrives, open this screen and click "Check for updates".', 'Read what is new, then click Apply — keep the window open for about two minutes.', 'The page reloads on the new version; the history table records it.'],
                'tip' => 'Updates need active AMC — if the screen shows your AMC has ended, one WhatsApp to 9000098877 renews it and updates flow again.',
                'r' => ['Admin'],
                'rel' => '',
            ],
        ];
    }

    // ================= LEARNING & KNOWLEDGE =================
    private static function learningModule(): array
    {
        return [
            'training-programs' => [
                'm' => 'Learning & Knowledge', 't' => 'Training Programs', 'g' => 'Courses your team runs on',
                'w' => 'Structured programs — DRA induction, recovery etiquette, RBI fair practices — each with subjects and descriptions. New workspaces come pre-loaded with a collections & recovery curriculum you can edit.',
                'f' => [['fa-graduation-cap', 'Programs with subjects'], ['fa-pen', 'Editable starter curriculum'], ['fa-link', 'Feeds training records']],
                's' => ['Review the pre-loaded programs and adapt them to your house style.', 'Add your own programs as needs appear.', 'Record who attended under Training Records.'],
                'tip' => 'The built-in content is grounded in RBI fair-practice and IIBF DRA norms — a real curriculum, not filler.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Training Records, Tests',
            ],
            'training-records' => [
                'm' => 'Learning & Knowledge', 't' => 'Training Records', 'g' => 'Who was trained, when',
                'w' => 'The attendance register of learning — which employee completed which program on what date. This is the evidence banks ask for when they audit agent readiness.',
                'f' => [['fa-clipboard-user', 'Completion per employee'], ['fa-calendar', 'Dates & outcomes']],
                's' => ['After each session, record the attendees against the program.', 'Pull the list when a client asks "are your agents trained?"'],
                'tip' => 'Pair a Test with each program — a score next to a completion date is twice as convincing.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'training-content' => [
                'm' => 'Learning & Knowledge', 't' => 'Training Content', 'g' => 'The lesson material itself',
                'w' => 'The actual reading material per subject — lessons and articles your team studies, editable like any document.',
                'f' => [['fa-book-open', 'Lessons per subject'], ['fa-pen', 'Edit / extend freely']],
                's' => ['Open a subject and read or update its lessons.', 'Add company-specific material alongside the built-ins.'],
                'tip' => 'Short lessons that fit one phone-screen get read; long essays get skipped.',
                'r' => ['Admin & HR — edit', 'Everyone — read'],
                'rel' => '',
            ],
            'faqs' => [
                'm' => 'Learning & Knowledge', 't' => 'FAQs', 'g' => 'Answers before the question',
                'w' => 'Frequently asked questions for staff — leave rules, payslip queries, field do\'s and don\'ts — pre-loaded for collections work and editable.',
                'f' => [['fa-circle-question', 'Q&A entries by topic']],
                's' => ['Skim what is pre-loaded; adjust wording to your policies.', 'When HR answers the same question twice, add it here.'],
                'tip' => 'Every FAQ added is ten interruptions saved.',
                'r' => ['Admin & HR — edit', 'Everyone — read'],
                'rel' => 'Also see: Knowledge Base',
            ],
            'code-of-conduct' => [
                'm' => 'Learning & Knowledge', 't' => 'Code of Conduct', 'g' => 'Read & acknowledge',
                'w' => 'The company\'s code of conduct — built on RBI fair-practice norms for collections — which every employee reads and acknowledges. Acknowledgements are recorded with name and date.',
                'f' => [['fa-file-shield', 'The full conduct policy'], ['fa-signature', 'One-click acknowledgement'], ['fa-list-check', 'Who has / hasn\'t acknowledged']],
                's' => ['Read the policy top to bottom.', 'Click acknowledge — your name and date are recorded.', 'HR follows up the not-yet-acknowledged list.'],
                'tip' => 'A recorded acknowledgement protects both sides when conduct is questioned — make it part of onboarding.',
                'r' => ['Everyone — read & acknowledge', 'HR — track'],
                'rel' => '',
            ],
        ];
    }

    // ================= HR LETTERS =================
    private static function lettersModule(): array
    {
        return [
            'letters-offer' => [
                'm' => 'HR Letters', 't' => 'Offer Letters', 'g' => 'Offer, sent and accepted online',
                'w' => 'Generate branded offer letters from templates — CTC, joining date, incentives auto-filled — email them with a secure accept link, and watch acceptance status without a single phone call.',
                'f' => [['fa-file-signature', 'Template-based generation'], ['fa-paper-plane', 'Email with accept link'], ['fa-check-double', 'Acceptance tracking']],
                's' => ['Generate the letter for the candidate — details flow in from Recruitment.', 'Send it; the candidate opens the link and accepts.', 'HR confirms the hire from the pipeline once accepted.'],
                'tip' => 'Edit the standard template once with your tone and terms — every letter after that is consistent.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Recruitment, Letter Templates',
            ],
            'letters-increment' => [
                'm' => 'HR Letters', 't' => 'Increment Letters', 'g' => 'The raise, in writing',
                'w' => 'Formal increment letters with old/new CTC and effective date — generated from the increment record and emailed automatically on approval.',
                'f' => [['fa-file-arrow-up', 'Auto-generated on approval'], ['fa-envelope', 'Emailed as branded PDF']],
                's' => ['Approve the increment under Compensation — the letter sends itself.', 'Re-issue happens automatically if the approved figures are corrected.'],
                'tip' => 'You rarely open this screen — the increment flow does the work; this is the archive.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Increment / Appraisal',
            ],
            'letters-warning' => [
                'm' => 'HR Letters', 't' => 'Warning Letters', 'g' => 'Discipline, documented',
                'w' => 'Issue formal warnings — misconduct, absenteeism, policy breach — from templates, keeping a dated record on the employee\'s file.',
                'f' => [['fa-triangle-exclamation', 'Template-based warnings'], ['fa-folder', 'On-file history']],
                's' => ['Generate the letter citing the specific incident and date.', 'Issue it and keep the acknowledgement.'],
                'tip' => 'Specific beats general — "absent 4, 5, 6 June without intimation" stands in any review; "frequent absence" does not.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'letters-relieving' => [
                'm' => 'HR Letters', 't' => 'Relieving Letters', 'g' => 'A clean goodbye',
                'w' => 'Relieving and experience letters for departing staff — dates and designation auto-filled from the record, issued after the Full & Final settles.',
                'f' => [['fa-file-export', 'Relieving/experience letters']],
                's' => ['Complete the exit and F&F first.', 'Generate and issue the letter.'],
                'tip' => 'Issue promptly after settlement — delayed relieving letters generate the angriest follow-ups.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Exit & FnF',
            ],
            'letters-templates' => [
                'm' => 'HR Letters', 't' => 'Letter Templates', 'g' => 'Design once, reuse forever',
                'w' => 'The master templates behind every letter — edit the wording, add placeholders like candidate name, CTC and joining date, and every future letter follows.',
                'f' => [['fa-file-pen', 'Editable templates per letter type'], ['fa-code','Placeholders auto-fill']],
                's' => ['Open the template for the letter type.', 'Adjust wording; keep the placeholders intact.', 'Generate one test letter to verify.'],
                'tip' => 'Placeholders are typed in double curly braces — remove one and that detail silently disappears from letters, so test after editing.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
        ];
    }

    // ================= COMMUNICATION =================
    private static function communicationModule(): array
    {
        return [
            'send-message' => [
                'm' => 'Communication', 't' => 'Send Message', 'g' => 'Reach staff directly',
                'w' => 'Compose and send a message to chosen employees, teams or everyone — delivered by email, for announcements that need to land personally rather than on the notice board.',
                'f' => [['fa-paper-plane', 'Compose to person/team/all'], ['fa-envelope', 'Email delivery']],
                's' => ['Pick the audience.', 'Write a clear subject and short body; send.'],
                'tip' => 'For standing information use the Notice Board; use direct messages for things that need action.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Notice Board',
            ],
            'notice-board' => [
                'm' => 'Communication', 't' => 'Notice Board', 'g' => 'Company announcements',
                'w' => 'Publish notices — holidays, policy updates, winners — visible to everyone on their dashboard. The digital equivalent of the board at reception, minus the pins.',
                'f' => [['fa-bullhorn', 'Publish notices'], ['fa-gauge-high', 'Shows on dashboards']],
                's' => ['Add the notice with a clear title and date.', 'It appears on dashboards immediately.'],
                'tip' => 'Old notices clutter fast — retire them monthly so the board stays believable.',
                'r' => ['Admin & HR — publish', 'Everyone — read'],
                'rel' => '',
            ],
            'messages' => [
                'm' => 'Communication', 't' => 'Messages Log', 'g' => 'What was sent, to whom',
                'w' => 'The history of messages sent through SmartPRS — your record of who was informed of what, and when.',
                'f' => [['fa-clock-rotate-left', 'Send history']],
                's' => ['Search by recipient or date when you need proof of communication.'],
                'tip' => 'In disputes, "informed on this date by email" backed by this log ends the argument.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'wa-templates' => [
                'm' => 'Communication', 't' => 'WhatsApp Templates', 'g' => 'Pre-approved message formats',
                'w' => 'WhatsApp requires every business-initiated message to use a pre-approved template. This registry holds your library — ready-made for offer letters, interviews, walk-ins, payslips and more — with the approval workflow: create here, submit in the Interakt dashboard, then a successful test send marks it Approved automatically.',
                'f' => [['fa-message', '17 ready HR templates'], ['fa-copy', 'Copy for the Interakt dashboard'], ['fa-vial', 'Test send proves approval'], ['fa-file-csv', 'Export the library']],
                's' => ['Open a template and click Copy.', 'Create it in the Interakt dashboard with the same name, language and category; submit.', 'Mark it Submitted here; once Interakt approves, hit Send test — delivery flips it to Approved.'],
                'tip' => 'Editing an approved template\'s wording drops it back to Draft on purpose — WhatsApp approval is per exact text.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Recruitment bulk WhatsApp',
            ],
            'wa-settings' => [
                'm' => 'Communication', 't' => 'WhatsApp API', 'g' => 'Connect your WhatsApp provider',
                'w' => 'The connection to your WhatsApp provider (Interakt) — paste the API key, set the sender number, and the platform can send template messages.',
                'f' => [['fa-key', 'API key & sender'], ['fa-toggle-on', 'Active/inactive']],
                's' => ['Get the Secret Key from Interakt → Developer Settings.', 'Add a row: provider interakt, paste the key, status active.', 'Send a test from WhatsApp Templates to confirm.'],
                'tip' => 'Every send attempt is logged (wa_log) with the provider\'s exact error — debugging starts there.',
                'r' => ['Admin'],
                'rel' => '',
            ],
            'sms-templates' => [
                'm' => 'Communication', 't' => 'SMS Templates', 'g' => 'DLT-registered SMS formats',
                'w' => 'India requires SMS templates to be DLT-registered. Keep each approved template and its DLT ID here, ready for when SMS sending is enabled.',
                'f' => [['fa-comment-sms', 'Templates with DLT IDs']],
                's' => ['Record each DLT-approved template with its ID.', 'Keep wording exactly as registered.'],
                'tip' => 'WhatsApp is the live channel today; SMS sits ready for when a provider is connected.',
                'r' => ['Admin'],
                'rel' => '',
            ],
        ];
    }

    // ================= REPORTS & ANALYTICS =================
    private static function reportsModule(): array
    {
        return [
            'reports' => [
                'm' => 'Reports & Analytics', 't' => 'Reports', 'g' => 'The numbers, exportable',
                'w' => 'The reporting hub — attendance summaries, payroll registers, headcount, compliance — filterable by company and period, exportable for management and bank partners.',
                'f' => [['fa-chart-line', 'Cross-module reports'], ['fa-filter', 'Company & period filters'], ['fa-file-export', 'Export']],
                's' => ['Pick the report and the period.', 'Filter to a company or the whole group.', 'Export and share.'],
                'tip' => 'For bank MIS, the attendance and compliance exports together usually answer the standard vendor questionnaire.',
                'r' => ['Admin, HR & managers'],
                'rel' => '',
            ],
            'attrition' => [
                'm' => 'Reports & Analytics', 't' => 'Attrition', 'g' => 'Who is leaving, and how fast',
                'w' => 'Joiners versus leavers over time — the churn picture that tells you whether hiring is filling a bucket with a hole in it.',
                'f' => [['fa-arrow-trend-down', 'Attrition rate by period']],
                's' => ['Review monthly alongside your hiring numbers.'],
                'tip' => 'High attrition in one team or branch is a management signal, not an HR statistic.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'activity-logs' => [
                'm' => 'Reports & Analytics', 't' => 'Activity Logs', 'g' => 'Who did what, when',
                'w' => 'The audit trail of actions in the workspace — changes, approvals, logins — for the day something needs reconstructing.',
                'f' => [['fa-timeline','Chronological action log']],
                's' => ['Filter by person or date when investigating.'],
                'tip' => 'Approvals and money actions also keep their own per-record history — check the record first, logs second.',
                'r' => ['Admin'],
                'rel' => '',
            ],
        ];
    }

    // ================= ADMINISTRATION =================
    private static function adminModule(): array
    {
        return [
            'companies' => [
                'm' => 'Administration', 't' => 'Companies', 'g' => 'Your group, structured',
                'w' => 'The companies in your group — one master company and its subsidiaries — each with its own branding, statutory profile and payroll, all run from one login. Your subscription\'s employee limit applies across the whole group.',
                'f' => [['fa-building', 'Master & subsidiary companies'], ['fa-palette', 'Per-company branding'], ['fa-right-left', 'Employees transfer between companies']],
                's' => ['Add each group company with its legal details.', 'Mark the parent as Master.', 'Use the top-bar switcher to work group-wide or company-wise.'],
                'tip' => 'Each additional company is ₹1,000/month on your subscription — the employee limit stays shared across all.',
                'r' => ['Admin'],
                'rel' => 'Also see: Transfers, My Subscription',
            ],
            'departments' => [
                'm' => 'Administration', 't' => 'Departments', 'g' => 'Organisational units',
                'w' => 'The departments employees belong to — Collections, Telecalling, Legal, Admin — used in profiles, filters and transfers.',
                'f' => [['fa-sitemap', 'Department list with heads']],
                's' => ['Create your departments once; assign on employee profiles.'],
                'tip' => 'Keep the list short and real — ten meaningful departments beat thirty empty ones.',
                'r' => ['Admin & HR'], 'rel' => '',
            ],
            'designations' => [
                'm' => 'Administration', 't' => 'Designations', 'g' => 'Job titles, standardised',
                'w' => 'The official job titles — Recovery Officer, Team Leader, Branch Manager — used on profiles, ID cards and letters.',
                'f' => [['fa-user-tie', 'Title master list']],
                's' => ['Define titles once; pick them everywhere else.'],
                'tip' => 'Letters print these titles verbatim — fix spelling here, not on each letter.',
                'r' => ['Admin & HR'], 'rel' => '',
            ],
            'branches' => [
                'm' => 'Administration', 't' => 'Branches', 'g' => 'Your locations',
                'w' => 'Physical locations per company — used in attendance filters, transfers and reporting.',
                'f' => [['fa-location-dot', 'Branch list per company']],
                's' => ['Add each office/branch; assign employees to theirs.'],
                'tip' => 'Branch transfers are a two-minute job under People → Transfers once branches exist.',
                'r' => ['Admin & HR'], 'rel' => '',
            ],
            'banks' => [
                'm' => 'Administration', 't' => 'Banks', 'g' => 'Client bank master',
                'w' => 'The banks and NBFCs you work for — referenced by portfolios, escalations and agent authorizations.',
                'f' => [['fa-building-columns', 'Client list']],
                's' => ['Add each client bank once; reference it everywhere.'],
                'tip' => 'Consistent client names here make every report group correctly.',
                'r' => ['Admin & HR'], 'rel' => '',
            ],
            'users' => [
                'm' => 'Administration', 't' => 'User Management', 'g' => 'Who can sign in',
                'w' => 'Logins for your workspace — admins, HR and staff — with roles, invitations by email, and enable/disable control.',
                'f' => [['fa-user-plus', 'Invite users by email'], ['fa-user-shield', 'Assign roles'], ['fa-toggle-off', 'Disable on exit']],
                's' => ['Create the user with the right role; they get a set-password email.', 'Disable immediately when someone leaves.'],
                'tip' => 'Fewer admins is safer — most people need only the role that matches their daily work.',
                'r' => ['Admin'],
                'rel' => 'Also see: Roles & Permissions',
            ],
            'roles' => [
                'm' => 'Administration', 't' => 'Roles & Permissions', 'g' => 'What each role can see and do',
                'w' => 'The permission matrix — per role, per module: view, create, edit, approve, delete. The menus and buttons each user sees follow this exactly.',
                'f' => [['fa-table-cells', 'Module × permission matrix'], ['fa-eye-slash', 'Hide whole modules per role']],
                's' => ['Pick a role and tick what it may do per module.', 'Save — affected users see the change on next load.'],
                'tip' => 'A role with no matrix defined is unrestricted by design (so nobody gets locked out) — define the sensitive ones first.',
                'r' => ['Admin'],
                'rel' => '',
            ],
            'branding' => [
                'm' => 'Administration', 't' => 'Company Branding', 'g' => 'Logos & colours per company',
                'w' => 'Upload each company\'s logo and accent colour — payslips, ID cards, letters and the app header follow it, so every document looks like that company issued it.',
                'f' => [['fa-image', 'Logo per company'], ['fa-palette', 'Accent colour']],
                's' => ['Upload a clean transparent-background logo per company.', 'Pick the accent; check one payslip PDF after.'],
                'tip' => 'A branded payslip quietly markets you to every employee\'s family and bank.',
                'r' => ['Admin'],
                'rel' => '',
            ],
            'company-emails' => [
                'm' => 'Administration', 't' => 'Email / SMTP Settings', 'g' => 'How your mail goes out',
                'w' => 'Outgoing mail servers — a tenant-wide default plus optional per-company SMTP, so letters and payslips can send from each company\'s own address.',
                'f' => [['fa-envelope-circle-check', 'Tenant default + per company'], ['fa-vial', 'Send a test email']],
                's' => ['Set the default SMTP (host, port, user, password, from).', 'Use Send Test to confirm before relying on it.', 'Add company-specific servers only if needed.'],
                'tip' => 'For Gmail use an App Password, not the login password — and always Send Test after changes.',
                'r' => ['Admin'],
                'rel' => '',
            ],
            'fin-year' => [
                'm' => 'Administration', 't' => 'Financial Year', 'g' => 'April–March, organised',
                'w' => 'Set the active financial year (India: 1 April to 31 March). New money records are tagged with it, and every register can be filtered FY-wise — clean books, clean audits.',
                'f' => [['fa-calendar-days','Active FY selection'], ['fa-tags', 'Auto-tagging of records'], ['fa-chart-pie', 'Per-FY money summary']],
                's' => ['Confirm the active FY each April.', 'Use the FY dropdown on money screens to view any year.'],
                'tip' => 'Old records without a tag still appear in FY views by their date — nothing is lost.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'my-subscription' => [
                'm' => 'Administration', 't' => 'My Subscription', 'g' => 'Your plan & renewals',
                'w' => 'Your SmartPRS plan — employees and companies subscribed versus in use, validity, invoices — with self-service renewal and mid-term upgrades paid securely online.',
                'f' => [['fa-id-card', 'Plan, seats & companies'], ['fa-rotate', 'Renew online'], ['fa-arrow-up-right-dots', 'Pro-rata mid-term upgrade'], ['fa-file-invoice', 'GST invoices']],
                's' => ['Check subscribed vs in-use — the OVER LIMIT flag tells you when to upgrade.', 'Renew or upgrade with the button; pay by card/UPI; the invoice emails itself.'],
                'tip' => 'Renewal reminders arrive automatically from 15 days out — renewing early extends from your current end date, you lose nothing.',
                'r' => ['Admin'],
                'rel' => '',
            ],
            'settings' => [
                'm' => 'Administration', 't' => 'Settings', 'g' => 'Workspace defaults',
                'w' => 'General workspace settings and statutory rates — the dials that the payroll and compliance engines read.',
                'f' => [['fa-sliders', 'Rates & defaults']],
                's' => ['Review once at setup; touch rarely after.'],
                'tip' => 'Statutory rate changes (PF/ESI/PT) are made here once and apply to all future months.',
                'r' => ['Admin'],
                'rel' => '',
            ],
        ];
    }

    // ================= SAAS PLATFORM (super admin) =================
    private static function saasModule(): array
    {
        return [
            'platform-dashboard' => [
                'm' => 'SaaS Platform', 't' => 'Platform Dashboard', 'g' => 'Your SaaS business at a glance',
                'w' => 'The owner\'s view — active tenants, MRR, collections this month, dues — the health of SmartPRS as a business.',
                'f' => [['fa-gauge-high', 'Tenants, MRR, collections'], ['fa-bolt', 'Quick links']],
                's' => ['Check after each signup or renewal.', 'Click a card to drill in.'],
                'tip' => 'MRR here is computed from active subscriptions — it moves the moment a renewal or upgrade lands.',
                'r' => ['Super Admin'],
                'rel' => '',
            ],
            'tenants' => [
                'm' => 'SaaS Platform', 't' => 'Tenants', 'g' => 'Every client workspace',
                'w' => 'All client organisations on the platform — plan, usage, status — with controls to edit details (including their GST state for invoices), suspend, or create a tenant manually.',
                'f' => [['fa-building-user', 'Tenant list & usage'], ['fa-pen', 'Edit incl. GSTIN/state'], ['fa-ban', 'Suspend / reactivate'], ['fa-plus', 'Manual provisioning']],
                's' => ['Use New Tenant for manually-onboarded clients; self-serve signups appear automatically.', 'Edit a tenant to fix details or set their GST profile.', 'Suspend only with cause — all their users lose access instantly.'],
                'tip' => 'The tenant\'s state/GSTIN drives CGST+SGST vs IGST on every invoice — set it right once.',
                'r' => ['Super Admin'],
                'rel' => '',
            ],
            'plans' => [
                'm' => 'SaaS Platform', 't' => 'Plans & Pricing', 'g' => 'What you sell',
                'w' => 'The published plans — base price, included employees, per-extra pricing and which modules each plan carries. The public signup reads exactly this.',
                'f' => [['fa-tags', 'Plan & price editor'], ['fa-users', 'Seat caps & extras']],
                's' => ['Edit with care — changes show on the public signup immediately.'],
                'tip' => 'Existing subscriptions keep their agreed amount; new pricing applies to new purchases and renewals.',
                'r' => ['Super Admin'],
                'rel' => '',
            ],
            'subscriptions' => [
                'm' => 'SaaS Platform', 't' => 'Subscriptions', 'g' => 'Who is paid up, until when',
                'w' => 'Every tenant\'s subscription — plan, seats, companies, cycle, expiry with days-left, grace and lock status — plus the automatic alert log.',
                'f' => [['fa-id-card', 'Per-tenant subscription'], ['fa-hourglass-half', 'Expiry & grace tracking'], ['fa-bell', 'Alert history']],
                's' => ['Scan the expiry column weekly.', 'The system already emails/WhatsApps renewals from 15 days out — call only the stubborn ones.'],
                'tip' => 'After grace, employees are blocked but the admin can still sign in to renew — recovery is one payment away.',
                'r' => ['Super Admin'],
                'rel' => '',
            ],
            'invoices' => [
                'm' => 'SaaS Platform', 't' => 'Invoices', 'g' => 'GST tax invoices to clients',
                'w' => 'Every invoice raised to tenants — amount, GST split, paid status — with PDF download and re-email. Numbers run in sequence for your books.',
                'f' => [['fa-file-invoice', 'Invoice register'], ['fa-file-pdf', 'Branded PDF'], ['fa-envelope', 'Re-email']],
                's' => ['Invoices create themselves on signup/renewal; generate manually only for special cases.', 'Download or re-send from the row.'],
                'tip' => 'The GST split (CGST+SGST vs IGST) follows the tenant\'s state automatically.',
                'r' => ['Super Admin'],
                'rel' => '',
            ],
            'payments' => [
                'm' => 'SaaS Platform', 't' => 'Payments', 'g' => 'Money received',
                'w' => 'Payments recorded against invoices — gateway reference, date, method — your reconciliation view against the Razorpay dashboard.',
                'f' => [['fa-money-bill-wave', 'Payment register']],
                's' => ['Razorpay payments record themselves; add manual rows for bank-transfer clients.'],
                'tip' => 'Reconcile monthly against the gateway statement — five minutes that keeps the CA happy.',
                'r' => ['Super Admin'],
                'rel' => '',
            ],
            'gateways' => [
                'm' => 'SaaS Platform', 't' => 'Payment Gateways', 'g' => 'How clients pay you',
                'w' => 'Razorpay keys for the platform — test or live mode, key id, secret and webhook secret. Secrets are encrypted at rest.',
                'f' => [['fa-key', 'Mode & keys'], ['fa-plug','Webhook secret']],
                's' => ['Paste the LIVE keys when going to production.', 'Create the webhook in Razorpay pointing at /webhooks/razorpay and store its secret here.'],
                'tip' => 'Test mode rejects common test cards as "international" — use the domestic test card from the docs.',
                'r' => ['Super Admin'],
                'rel' => '',
            ],
        ];
    }

    // ================= TIME & ATTENDANCE =================
    private static function attendanceModule(): array
    {
        return [
            'att-daily' => [
                'm' => 'Time & Attendance', 't' => 'Daily Attendance', 'g' => 'Who is in, right now',
                'w' => 'Today\'s attendance at a glance — present, absent, late and on-leave, per company or for the whole group. Punches from biometric devices, the mobile GPS punch and manual entries all land here.',
                'f' => [['fa-user-check', 'Present / absent / late today'], ['fa-clock', 'In and out times per person'], ['fa-building', 'Company & branch filters']],
                's' => ['Open the screen in the morning — the day builds up live as punches arrive.', 'Use the filters to focus on one branch or team.', 'Spot absentees early and follow up while the day can still be saved.'],
                'tip' => 'A punch recorded anywhere — device, mobile or manual — appears here within moments. If someone is missing, check Manual Attendance.',
                'r' => ['Admin & HR — all', 'Managers — their team'],
                'rel' => 'Also see: Attendance Report, Manual Attendance',
            ],
            'att-report' => [
                'm' => 'Time & Attendance', 't' => 'Attendance Report', 'g' => 'The month, employee by employee',
                'w' => 'The full attendance register for any period — days present, absences, late marks and leave — with filters for branch, team, manager and leader. This is the record payroll reads, so what you see here is what salaries are calculated on.',
                'f' => [['fa-table', 'Period register per employee'], ['fa-filter', 'Branch / team / manager filters'], ['fa-file-export', 'Export for audits'], ['fa-triangle-exclamation', 'Late & absence patterns']],
                's' => ['Pick the month and any filters.', 'Review the grid — each cell is a day, colour-coded.', 'Before running payroll, scan this once; fix wrong days via Manual Attendance.'],
                'tip' => 'Payroll uses exactly this data. A correction here today saves a salary dispute next week.',
                'r' => ['Admin & HR — all', 'Managers — their team'],
                'rel' => 'Also see: Generate Payroll, Late Policy',
            ],
            'att-manual' => [
                'm' => 'Time & Attendance', 't' => 'Manual Attendance', 'g' => 'Fix or add punches by hand',
                'w' => 'For the days machines miss — a forgotten punch, a device offline, a field day without signal. Enter a single punch instantly, or upload a whole Excel of attendance which an admin approves before it counts.',
                'f' => [['fa-user-pen', 'Single punch with employee search'], ['fa-file-arrow-up', 'Bulk Excel upload (employee-day or punch-log format)'], ['fa-user-shield', 'Admin approval for bulk batches'], ['fa-circle-exclamation', 'Bad rows flagged, good rows kept']],
                's' => ['For one person: pick them in the search box, set date, time and direction, save — it counts immediately.', 'For many: download the template, fill it, upload — review the batch summary.', 'An admin approves the batch; only then does it enter the attendance register.'],
                'tip' => 'Device exports (like ZKTeco punch dumps) upload here too — the system auto-detects the punch-log format with in/out columns.',
                'r' => ['Admin & HR — entry & upload', 'Admin — approve batches'],
                'rel' => '',
            ],
            'biometric-devices' => [
                'm' => 'Time & Attendance', 't' => 'Biometric Devices', 'g' => 'Your device registry',
                'w' => 'Register every attendance device — brand, model, location, connection mode and credentials — so punches can be pulled in and devices tracked across branches.',
                'f' => [['fa-fingerprint', 'Register devices (ZKTeco, eSSL & more)'], ['fa-network-wired', 'Connection mode: cloud push / LAN / offline'], ['fa-rotate', 'Sync punches'], ['fa-screwdriver-wrench', 'Status & retirement']],
                's' => ['Add the device with its brand, serial/unique id and location.', 'Set the connection mode; for LAN devices add IP, port and comm key.', 'Use Sync to pull punches; for offline devices, upload their export in Manual Attendance.'],
                'tip' => 'On cloud hosting, an office device behind your router can\'t be reached directly — use the offline punch-dump upload until a push agent is configured.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Manual Attendance',
            ],
            'geofence' => [
                'm' => 'Time & Attendance', 't' => 'Geofence Rules', 'g' => 'GPS boundaries for field punch',
                'w' => 'Draw the locations where field staff are allowed to punch — the office, branch, or a client site — each with a radius. The mobile punch then records GPS plus a selfie, so an attendance mark always has a real place and face behind it.',
                'f' => [['fa-map-location-dot', 'Interactive map of all rules'], ['fa-circle-dot', 'Radius per location'], ['fa-camera', 'Selfie + GPS punch enforcement']],
                's' => ['Add a rule: search the location on the map, set the radius (100–300 m works well).', 'Assign which employees or teams it applies to.', 'Field staff now punch from the mobile screen — outside the fence, the punch is refused.'],
                'tip' => 'GPS+selfie punch needs HTTPS — it works on your live domain; plain http will not ask for location.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'shifts' => [
                'm' => 'Time & Attendance', 't' => 'Working Shifts', 'g' => 'Named shift timings per employee',
                'w' => 'Define named shifts (General, Morning, Night) with start/end times. Attendance and payroll then judge each employee against THEIR shift — a roster entry for the day wins, else the employee\'s default shift, else the Late Policy timings. An end time earlier than the start marks a night shift, and its night allowance is paid per night actually worked.',
                'f' => [['fa-clock', 'Named shifts with timings'], ['fa-moon', 'Night shifts across midnight'], ['fa-indian-rupee-sign', 'Automatic night allowance in payroll'], ['fa-route', 'Roster overrides per day']],
                's' => ['Add a shift: name + start and end time in 24-hour HH:MM (22:00–06:00 = night).', 'Set it as the Default Shift on each employee\'s profile; use the Roster for day-wise overrides.', 'Attendance flags and payroll cuts now use those timings; night allowance appears on the payslip by itself.'],
                'tip' => 'Grace, day-hours and break budget on the shift are OPTIONAL overrides — leave them blank and the Late Policy values apply, so you configure rules once.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Roster, Late Policy, Attendance Report',
            ],
            'late-policy' => [
                'm' => 'Time & Attendance', 't' => 'Late Policy', 'g' => 'Late marks → salary, automatically',
                'w' => 'A six-step wizard that turns your lateness rules into automatic payroll deductions — grace minutes, how many lates make a half-day, break rules and week-offs. Once saved, the policy applies itself every month.',
                'f' => [['fa-wand-magic-sparkles', 'Guided 6-step wizard'], ['fa-stopwatch', 'Grace time & late slabs'], ['fa-calculator', 'Worked example before saving'], ['fa-building', 'Per company / shift scope']],
                's' => ['Start the wizard and pick the scope (company / shift).', 'Set grace minutes and what happens as lates accumulate.', 'Review the plain-English summary with a worked example, then save.'],
                'tip' => 'The review step shows a real calculation ("3 lates of 20 min = half-day") — read it once before saving and there are no surprises on payday.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Attendance Report, Generate Payroll',
            ],
        ];
    }

    // ================= LEAVE =================
    private static function leaveModule(): array
    {
        return [
            'leave-apply' => [
                'm' => 'Leave', 't' => 'Leave', 'g' => 'Apply, approve, done',
                'w' => 'Employees apply for leave here; the request goes to their manager; on approval the balance updates and payroll automatically treats the days correctly — paid leave keeps salary, loss-of-pay deducts it.',
                'f' => [['fa-umbrella-beach', 'Apply with type & dates'], ['fa-check-double', 'Manager approval chain'], ['fa-scale-balanced', 'Live balances per type'], ['fa-envelope', 'Decision notified automatically']],
                's' => ['Click Apply, pick the leave type and the from–to dates, add a reason.', 'Your manager sees it in their Approvals Inbox and decides.', 'You are notified either way; approved paid leave will not cut your salary.'],
                'tip' => 'Check your balance before applying — over-balance requests are the most common rejection reason.',
                'r' => ['Everyone — own leave', 'Managers — approve team', 'HR — all'],
                'rel' => 'Also see: Leave Types, Holidays',
            ],
            'leave-types' => [
                'm' => 'Leave', 't' => 'Leave Types', 'g' => 'Define your leave rules',
                'w' => 'Create the kinds of leave your company offers — casual, sick, earned, loss-of-pay — each with its yearly quota and whether it is paid. These types power the apply screen and the payroll treatment.',
                'f' => [['fa-list', 'Types with yearly quota'], ['fa-indian-rupee-sign', 'Paid vs unpaid flag']],
                's' => ['Add each type your policy offers with its annual days.', 'Mark whether it is paid — payroll follows this flag exactly.'],
                'tip' => 'A fresh workspace has NO leave types — set them up before employees try to apply.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'holidays' => [
                'm' => 'Leave', 't' => 'Holidays', 'g' => 'The company holiday calendar',
                'w' => 'Declare the year\'s holidays once. Attendance expects nobody on these days, and payroll counts them as paid days automatically — each holiday is removed from the LOP working-days count.',
                'f' => [['fa-calendar-day', 'Holiday list per year'], ['fa-building', 'Per-company calendars'], ['fa-calendar-xmark', 'Weekly offs set separately (Statutory Rate Settings)']],
                's' => ['ADD: click Add Holiday, give the name and date — payroll respects it immediately.', 'DELETE: trash icon on the holiday\'s row (pen icon to edit).', 'Do NOT add Sundays / 2nd & 4th Saturdays here — set weekly offs once in Statutory & Compliance → PF & ESIC → Edit Rates → Weekly offs.'],
                'tip' => 'Forgotten holidays show up as mass "absences" — if a day looks wrongly absent for everyone, check here first.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
        ];
    }

    // ================= PAYROLL =================
    private static function payrollModule(): array
    {
        return [
            'salary-setup' => [
                'm' => 'Payroll', 't' => 'Salary Structure', 'g' => 'How a CTC splits into components',
                'w' => 'Define how salaries are composed — basic, HRA, allowances, PF, ESI, professional tax — as percentage or fixed rules. Structures can be set company-wide, per team, or per employee; the most specific one wins.',
                'f' => [['fa-layer-group', 'Component rules (% or fixed)'], ['fa-bullseye', 'Company / team / employee scope'], ['fa-shield', 'Statutory components built in']],
                's' => ['Start with one company-wide structure.', 'Add team or employee overrides only where genuinely different.', 'Generate a test payroll and check one payslip before the real run.'],
                'tip' => 'Most-specific wins: an employee-level rule beats a team rule beats the company rule — useful for special hires.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Generate Payroll',
            ],
            'salary-schedules' => [
                'm' => 'Payroll', 't' => 'Salary Schedules', 'g' => 'WHEN salary is paid, per company',
                'w' => 'This screen controls the PAY DATE. A schedule row with Pay Day N (1st–28th) makes that company\'s salary pay on the Nth of the FOLLOWING month; with no schedule, salary pays on month-end. A row naming a specific company always beats an all-company row, so different companies in a group can pay on different dates. Two things this screen does NOT change: the attendance period (always the calendar month, 1st to last day — every punch, leave and OT inside those dates belongs to that month\'s payslip) and the salary amounts themselves (those come from Salary Setup and the attendance engine).',
                'f' => [['fa-money-bill-transfer', 'Pay Day 1st–28th, per company'], ['fa-building', 'Company-specific beats all-company'], ['fa-calendar-days', 'Attendance period stays the calendar month'], ['fa-bolt', 'Applies from the next generated run']],
                's' => ['Create a schedule: pick the company (or leave blank for all companies), choose the Pay Day, keep Status active.', 'Generate payroll as usual — the run\'s pay date follows the schedule automatically; the pay date shows on the run and drives the NEFT bank file.', 'Changing the Pay Day later affects the NEXT generated run; already-generated runs keep their dates.'],
                'tip' => 'Pay Day 7th is the sweet spot for most: a full week to fix attendance disputes after month-end, and within the Payment-of-Wages 7-day timeline. For the full logic of period vs pay date, open Payroll → Salary Calculation Guide (section 3).',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Pay Cycle, Generate Payroll, Salary Calculation Guide',
            ],
            'salary-gen' => [
                'm' => 'Payroll', 't' => 'Generate Payroll', 'g' => 'The monthly run, one click',
                'w' => 'Pick the company and month, click generate, and the engine computes every salary — attendance and leave applied, late-policy cuts, approved commissions and overtime folded in, PF/ESI/PT/TDS deducted, and loan EMIs plus salary advances recovered on the payslip itself. Review the draft, then send it for approval.',
                'f' => [['fa-bolt', 'One-click computation'], ['fa-list-check', 'Draft review per employee'], ['fa-hand-holding-dollar', 'Commissions & overtime auto-included'], ['fa-sack-dollar', 'Loan EMI & advance recovery'], ['fa-scale-balanced', 'LOP basis: working / calendar / fixed 30'], ['fa-route', 'Send to approval flow']],
                's' => ['Choose company and month, click Generate.', 'Non-working days AND the value of a day are automatic: weekly offs, the Saturday policy and the Salary/LOP day basis (working days, calendar days, or a fixed 30) all come from Statutory & Compliance → PF & ESIC → Edit Rates; festival holidays are added/deleted in Leave → Holidays.', 'The engine also recovers each active loan\'s EMI and the month\'s approved salary advances, and pays approved Overtime Register entries — all shown as their own lines on the payslip.', 'Open a few payslips in the draft — check one normal case, one with leave, one with commission or a loan EMI.', 'Send the run for approval; after approval, payslips publish and employees are notified.'],
                'tip' => 'Approved commissions whose payout month matches are pulled in and LOCKED automatically — corrections after that go through clawbacks. Every payslip carries a plain-English calculation note explaining the LOP basis, cuts, EMIs, overtime and commissions line by line — answer any doubt from there. Want to test a what-if before running? The <b>Salary Simulator</b> button opens a live payslip illustration where you can change every variable.',
                'r' => ['Admin & HR — generate', 'Approvers — approve the run'],
                'rel' => 'Also see: Salary Approval, Payslips',
            ],
            'salary-approval' => [
                'm' => 'Payroll', 't' => 'Salary Approval', 'g' => 'Four-eyes before money moves',
                'w' => 'Generated runs wait here for a second pair of eyes. The approver sees totals and per-employee lines, can send back with remarks, or approve — after which payslips are published and emailed.',
                'f' => [['fa-magnifying-glass-dollar', 'Run summary & line view'], ['fa-rotate-left', 'Send back with remarks'], ['fa-stamp', 'Approve & publish payslips']],
                's' => ['Open the pending run and scan the totals against last month.', 'Drill into any odd line.', 'Approve — payslips go live and employees are emailed.'],
                'tip' => 'Compare the net-total with the previous month first — a big jump is the fastest error detector.',
                'r' => ['Admin / designated approver'],
                'rel' => '',
            ],
            'payslip' => [
                'm' => 'Payroll', 't' => 'Payslips', 'g' => 'Every month, every slip',
                'w' => 'All published payslips — view, download as branded PDFs, and track employee e-signatures. Employees see their own history; HR sees everyone.',
                'f' => [['fa-file-pdf', 'Branded PDF slips'], ['fa-signature', 'Employee e-sign tracking'], ['fa-clock-rotate-left', 'Full history']],
                's' => ['Pick the month to list slips.', 'Open or download any slip; employees can e-sign theirs.', 'Use the signed status to chase pending acknowledgements.', 'The Payslip Policy button controls who may self-download AND whether the YTD (financial-year-to-date) column appears on the PDFs; View Sample Payslip previews exactly how they will look.'],
                'tip' => 'The slip\'s calculation note shows exactly which commissions, overtime, loan EMIs, advance recoveries and attendance cuts built the figure — and which LOP day-basis valued them. Answer disputes from there.',
                'r' => ['Employees — own', 'Admin & HR — all'],
                'rel' => 'Also see: Salary & Commission Ledger',
            ],
            'pay-ledger' => [
                'm' => 'Payroll', 't' => 'Salary & Commission Ledger', 'g' => 'The complete money passbook',
                'w' => 'One running account per employee: salary months as credits, commissions as credits, every payment as a debit, with a live balance. Supports partial salary payments — common when cash flow is tight — without losing track of a single rupee.',
                'f' => [['fa-book', 'All / Salary / Commission tabs'], ['fa-money-bill-wave', 'Record full or part payments'], ['fa-scale-balanced', 'Running balance & outstanding'], ['fa-file-export', 'Export for reconciliation']],
                's' => ['Pick an employee (managers/HR) — your own opens automatically otherwise.', 'Read it like a bank passbook: credit, debit, balance.', 'Record a payment against any month with an outstanding balance.'],
                'tip' => 'Salary "paid" comes only from recorded payments here — marking a payroll run disbursed does not auto-debit this ledger.',
                'r' => ['Admin & HR — all + record payments', 'Employees — own'],
                'rel' => '',
            ],
            'deductions' => [
                'm' => 'Payroll', 't' => 'Deductions Ledger', 'g' => 'Ad-hoc deductions, recorded',
                'w' => 'One-off deductions outside the standard structure — damage recovery, fines, canteen dues — logged per employee with the month they should hit.',
                'f' => [['fa-minus', 'Add a deduction with reason'], ['fa-calendar', 'Target month']],
                's' => ['Add the deduction with employee, amount, reason and month.', 'The payroll run for that month picks it up.'],
                'tip' => 'Always write the reason — a deduction without a reason becomes next month\'s argument.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'payout-recon' => [
                'm' => 'Payroll', 't' => 'Payout Reconciliation', 'g' => 'Bank file vs expected',
                'w' => 'After paying salaries through the bank, reconcile what actually went out against what payroll expected — catching failed transfers and wrong accounts.',
                'f' => [['fa-building-columns', 'Expected vs actual per employee'], ['fa-triangle-exclamation', 'Mismatch flags']],
                's' => ['Record the actual payout amounts after the bank run.', 'Investigate any row where actual differs from expected.'],
                'tip' => 'Failed bank transfers found here should be re-paid and recorded in the employee ledger too.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'pay-cycle' => [
                'm' => 'Payroll', 't' => 'Pay Cycle', 'g' => 'The payment rhythm, explained',
                'w' => 'Pay Cycle rows work exactly like Salary Schedules: they set the PAY DATE — Pay Day N pays on the Nth of the month AFTER the payroll month, company-specific rows beating all-company rows; no row means month-end. The payroll month itself (what period the money covers) is always the calendar month, 1st to last day. So "pay cycle" in SmartPRS answers WHEN money leaves the bank — never WHAT period it covers or HOW the amount is computed.',
                'f' => [['fa-calendar-days', 'Pay Day 1st–28th → Nth of next month'], ['fa-building', 'Per company or all companies'], ['fa-file-invoice', 'Drives the run pay date & NEFT file'], ['fa-book-open', 'Full logic in the Salary Calculation Guide']],
                's' => ['Add a cycle row with the company and Pay Day; keep it active.', 'The next generated payroll picks the date automatically — verify it on the run header in Salary Approval.', 'When a client asks about 21st→20th style working months: the attendance period is currently always the calendar month — a configurable period is on the roadmap.'],
                'tip' => 'Changing the Pay Day never touches amounts or attendance — only the date. For the complete period-and-pay-date logic with examples, open Payroll → Salary Calculation Guide (section 3) or press "How salary is calculated" on Generate Payroll.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Salary Schedules, Generate Payroll, Salary Calculation Guide',
            ],
        ];
    }

    // ================= COMPENSATION & CLAIMS =================
    private static function compensationModule(): array
    {
        return [
            'commissions' => [
                'm' => 'Compensation & Claims', 't' => 'Commission Entries', 'g' => 'Claim with proof, verify the money, then pay',
                'w' => 'Every commission claim carries its COLLECTION EVIDENCE: customer, account/ID, what was collected, when and where, the mode (cash to office / deposited / client paid directly) and a proof upload. Two amounts, clearly separate: the amount collected FROM the customer, and the commission the agent is claiming (gross − TDS = net). Accounts confirms the money actually arrived, and only then can the manager approve. Pick a published Scheme and the form fills itself with the right rate.',
                'f' => [['fa-plus', 'Claim with collection evidence'], ['fa-bullseye', 'Scheme picker — rates auto-fill'], ['fa-receipt', 'Proof upload (screenshot / slip)'], ['fa-check-double', 'Accounts confirm → manager approve'], ['fa-file-excel', 'Bulk import (evidence columns)'], ['fa-book', 'Ledger + auto-lock on payslip']],
                's' => ['Pick the Claim Type — Collection (customer money, full evidence) or Simple (target / bonus, notes only).', 'For collections: customer, account/ID, what was collected, date-time, location, mode; attach the proof if you have it (optional — Accounts checks the bank\'s payments list, but a proof speeds confirmation).', 'Enter the amount collected from the customer; pick a Scheme and the commission computes itself, or type the gross — TDS and net auto-calculate.', 'Accounts (or Admin) clicks Confirm once the money is verified — the amber chip turns green; simple claims skip this stage.', 'Then the manager Approves; "with salary" folds into the payslip, "separate" pays through Record Payment.'],
                'tip' => 'The approve button refuses until Accounts confirms — the money trail comes before the commission. Once an entry enters a payslip it locks; corrections go through Clawbacks.',
                'r' => ['Admin — everything', 'Accountant — confirm / flag collections', 'HR & managers — add, approve, pay', 'Employees — view own + self-claim'],
                'rel' => 'Also see: Incentive Schemes, Live Salary, Salary & Commission Ledger, Clawbacks',
            ],
            'incentive-schemes' => [
                'm' => 'Compensation & Claims', 't' => 'Commission & Incentive Schemes', 'g' => 'Publish offers your people claim against',
                'w' => 'Create a scheme — month-wise, weekly or portfolio-wise — with how it pays (% of collections, fixed ₹ per claim, or open), who can claim (everyone, one team, or selected people), validity dates and optional per-person caps. On publish, the targeted people are announced by email, WhatsApp and the notice board, their Live Salary card shows an orange ribbon, and the scheme appears inside their commission claim form with everything pre-filled.',
                'f' => [['fa-bullseye', '% / fixed / open pay types'], ['fa-users', 'All / team / selected targeting'], ['fa-calendar-xmark', 'Validity window + withdraw'], ['fa-gauge', 'Per-person claim & ₹ caps'], ['fa-bullhorn', 'Auto announcements'], ['fa-chart-line', 'Claims & ₹ per scheme']],
                's' => ['Click New Scheme — title it the way you would announce it on the floor.', 'Choose how it pays and who it is for (team leaders can target only their own people).', 'Set the window and caps; Publish — announcements go out by themselves.', 'Agents pick the scheme in their claim form; everything fills in; approvals flow as usual.', 'Watch the Claims column; Withdraw any time — existing claims stay.'],
                'tip' => 'The scheme decides the money server-side — an agent cannot type a different rate. Expired or withdrawn schemes vanish from claim forms instantly but never touch claims already raised.',
                'r' => ['Admin / HR — any scheme incl. company-wide', 'Managers & Team Leaders — schemes for their own people', 'Employees — see and claim what applies to them'],
                'rel' => 'Also see: Commission Entries, Live Salary, Commission Calculator',
            ],
            'mobile-devices' => [
                'm' => 'Administration', 't' => 'Mobile Devices', 'g' => 'Approve the phones that may use the app',
                'w' => 'When an employee installs the SmartPRS app and enters your company web address, their phone appears here as Pending. You approve it once — then they can sign in and use everything, including GPS attendance punch. Only an approved device gets in, so a lost or unknown phone never reaches your data.',
                'f' => [['fa-mobile-screen', 'Pending / approved / rejected devices'], ['fa-check', 'Approve a device'], ['fa-ban', 'Reject or revoke (lost phone)'], ['fa-hashtag', 'Match by the code shown on the phone'], ['fa-bell', 'Push-enabled indicator']],
                's' => ['An employee enters your web address in the app; their device shows here as Pending with a short code.', 'Confirm it is really their phone (the same code shows on their screen), then tap Approve.', 'The phone unlocks within a few seconds and they sign in.', 'If a phone is lost or stolen, tap Revoke — it loses access on its next check.'],
                'tip' => 'The device gate is your anti-fraud lock — approve deliberately, and revoke the moment a phone goes missing. No background tracking is used; punch GPS is foreground-only.',
                'r' => ['Admin / HR'],
                'rel' => 'Also see: Users, Roles & Permissions',
            ],
            'commission-calc' => [
                'm' => 'Compensation & Claims', 't' => 'Commission Calculator', 'g' => 'Bulk compute from collection sheets',
                'w' => 'Upload a CSV of collections and let the engine compute payouts — flat percentage, slab rates, or per-portfolio rates, with optional target gates. Preview everything, then commit the lines into Commission Entries. On commit, TDS u/s 194H is deducted automatically (rate from Statutory Rate Settings, default 5%) — gross, TDS and net are stored on every entry, and the NET is what pays.',
                'f' => [['fa-file-csv', 'Upload collection data'], ['fa-percent', 'Flat / slab / portfolio formulas'], ['fa-bullseye', 'Target-achievement gate'], ['fa-receipt', 'TDS 194H auto-deducted'], ['fa-eye', 'Preview before commit']],
                's' => ['Download the template and fill collected amounts per employee.', 'Pick the basis and formula; upload.', 'Check the preview figures, then Commit — entries land in the register (gross − TDS = net) for normal approval.'],
                'tip' => 'Nothing is final at preview — only Commit writes entries, and even those still need approval. The preview shows GROSS payouts; the committed entries carry the net after TDS, same as single commission entries.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'expenses' => [
                'm' => 'Compensation & Claims', 't' => 'Expense Claims', 'g' => 'Field expenses, reimbursed properly',
                'w' => 'Petrol, travel, court fees — employees claim them here, managers approve, and approved amounts are reimbursed and visible in Live Salary.',
                'f' => [['fa-receipt', 'Claim with details'], ['fa-check', 'Hierarchy approval'], ['fa-calendar', 'Financial-year filter']],
                's' => ['Add the claim with amount and what it was for.', 'Your manager approves or rejects with remarks.', 'Approved claims appear in your Live Salary as earnings.'],
                'tip' => 'Claim promptly — month-old expense claims are the hardest to verify and the slowest to approve.',
                'r' => ['Everyone — own claims', 'Managers — approve'],
                'rel' => '',
            ],
            'advance' => [
                'm' => 'Compensation & Claims', 't' => 'Salary Advance', 'g' => 'Part of salary, early',
                'w' => 'An employee can request part of their salary before payday. On approval it is paid out, and the payroll engine recovers it in the SAME month\'s payslip automatically, as its own "Salary Advance Recovery" deduction line.',
                'f' => [['fa-hand-holding-dollar', 'Request with amount & reason'], ['fa-check', 'Approval chain'], ['fa-rotate', 'Auto-recovery on the payslip']],
                's' => ['Request the advance with the amount needed.', 'On approval, the amount is paid.', 'The month\'s payslip shows the recovery as its own deduction line, and the calculation note explains it.'],
                'tip' => 'Advances recover in the month they are approved. If the net salary is too small to recover it fully, the balance is noted on the payslip for manual follow-up — for spread-out recovery use a Loan with EMIs instead.',
                'r' => ['Everyone — request', 'Managers/HR — approve'],
                'rel' => 'Also see: Loans',
            ],
            'overtime' => [
                'm' => 'Compensation & Claims', 't' => 'Overtime Register', 'g' => 'Extra hours, valued and paid',
                'w' => 'Record extra hours worked — employee, date, hours and multiplier — and the amount computes itself from the employee\'s salary (hourly rate = monthly gross ÷ 26 days ÷ 8 hours). Once an entry is APPROVED, that month\'s payroll pays it as an "Overtime" earning on the payslip and stamps the entry Paid.',
                'f' => [['fa-clock', 'Hours × multiplier entry'], ['fa-calculator', 'Auto amount from salary'], ['fa-check', 'Approve before pay'], ['fa-money-check', 'Paid on the payslip'], ['fa-lock', 'Paid entries tied to a run']],
                's' => ['Add the entry with employee, OT date and hours; pick the multiplier (2x is the standard legal convention for overtime).', 'Leave the amount blank to auto-compute, or type an agreed amount.', 'Approve the entry — the payroll run of that month pays it and marks it Paid.'],
                'tip' => 'Only APPROVED entries dated inside the payroll month are paid — pending entries are never picked up, and Paid entries can never pay twice. Regenerating a draft run releases its OT entries and re-includes them safely.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Generate Payroll, Payslips',
            ],
            'loans' => [
                'm' => 'Compensation & Claims', 't' => 'Loans', 'g' => 'Borrow now, EMI from salary',
                'w' => 'Staff loans with EMI recovery — set the amount, the EMI and the number of months; each month\'s generated payslip deducts the EMI as its own "Loan EMI" line until every installment is recovered, then the loan closes itself automatically. Each recovery is written to a loan statement (which payroll run recovered which installment), and Live Salary shows the coming deduction so there are no surprises.',
                'f' => [['fa-sack-dollar', 'Loan with EMI plan'], ['fa-calendar-check', 'Auto EMI on the payslip'], ['fa-book', 'Recovery statement per run'], ['fa-circle-check', 'Auto-close when repaid'], ['fa-chart-line', 'Outstanding tracking']],
                's' => ['Request or record the loan with amount, EMI and months.', 'On approval, EMIs start deducting from the next generated payroll.', 'Track installments recovered and the remaining balance on the row — the loan closes itself after the last EMI.'],
                'tip' => 'If a month\'s net salary is too small to fit the full EMI, that month is SKIPPED and noted on the payslip — the installment stays due; it is never half-recovered. On exit, any pending balance is settled in the Full & Final.',
                'r' => ['Everyone — request', 'Admin & HR — approve/manage'],
                'rel' => '',
            ],
            'clawbacks' => [
                'm' => 'Compensation & Claims', 't' => 'Clawbacks / Reversals', 'g' => 'Take back what was overpaid',
                'w' => 'When a commission was paid but the case bounced — a cheque returned, a settlement cancelled — record a clawback. Once APPROVED, the payroll engine recovers it automatically on the payslip of its month as a "Clawback / Reversal" deduction line (net never goes below zero; whatever does not fit stays open on the entry). The fastest way to raise one is the Bounce action on the commission entry itself — it creates the approved clawback for the paid amount automatically.',
                'f' => [['fa-rotate-left', 'Reversal with reason'], ['fa-check', 'Approval before recovery'], ['fa-money-bill-transfer', 'Auto-deducts on the payslip'], ['fa-bolt', 'Auto-created by Bounce'], ['fa-file-lines', 'Audit trail']],
                's' => ['Prefer the Bounce action on the commission entry — it fills the amount, reason and approval automatically.', 'Or add a clawback manually with the employee, amount, month and reason.', 'On approval, that month\'s payroll deducts it as its own payslip line; the calculation note explains it.'],
                'tip' => 'Recovery is attempted in the clawback\'s month only — if the net salary could not cover it fully, the shortfall stays visible on the entry for manual handling (or move its month forward to retry). Regenerating a draft run safely re-attempts the recovery.',
                'r' => ['Admin & HR — raise', 'Approvers — approve'],
                'rel' => 'Also see: Commission Entries, Bank Payout Pack',
            ],
            'calc-logic' => [
                'm' => 'Payroll', 't' => 'Salary Calculation Guide', 'g' => 'The engine, explained in plain language',
                'w' => 'The complete story of how every rupee on a payslip is computed — the CTC-to-net pipeline, attendance and LOP day-values, statutory deductions, commissions and payout methods, loan/advance/clawback recoveries, overtime, and the locks that protect history — with worked rupee examples and the FAQs every client asks in demos, searchable and printable.',
                'f' => [['fa-diagram-project', 'The full calculation pipeline'], ['fa-calculator', 'Worked rupee examples'], ['fa-circle-question', 'Searchable demo FAQs'], ['fa-print', 'Print the whole guide'], ['fa-user-group', 'Visible to every role']],
                's' => ['Open the guide from the Payroll menu — or from the "How salary is calculated" button on Generate Payroll and Live Salary.', 'Use the chips to jump to a topic; use the FAQ search box for a specific question (try "TDS", "EMI", "bounce").', 'Click Print the guide to hand a client the whole document in a demo.'],
                'tip' => 'The guide describes what the engine ACTUALLY does — the same rules the Simulator and every payslip calculation note follow. When a client asks "but how do you calculate…", open this screen and search their word.',
                'r' => ['Everyone — read-only'],
                'rel' => 'Also see: Salary Simulator, Generate Payroll',
            ],
            'bank-pack' => [
                'm' => 'Commissions & Incentives', 't' => 'Bank Payout Pack', 'g' => 'Month-end pack, per bank/NBFC',
                'w' => 'Everything a collection agency owes each bank at month-end, generated from data already in SmartPRS: the agent-wise PAYOUT REGISTER (gross / TDS 194H / net for every commission entry on that bank\'s portfolio), the deductee-wise TDS ANNEXURE for the 26Q return, and a printable GST SERVICE INVOICE to the bank (CGST+SGST vs IGST decided automatically from the two GSTINs).',
                'f' => [['fa-building-columns', 'Per bank, per month'], ['fa-table-list', 'Agent-wise payout register'], ['fa-receipt', 'TDS 194H annexure'], ['fa-file-invoice-dollar', 'GST invoice, numbered & saved'], ['fa-print', 'Print-ready documents']],
                's' => ['Pick the month and the bank (the Portfolio / Bank field on commission entries is the key — spell it consistently).', 'Review the register; the totals separate approved-net from everything recorded.', 'Print the register and annexure; create the GST invoice — the amount is pre-suggested from the register and fully editable.', 'The bank\'s GSTIN and address are remembered for the next month\'s invoice.'],
                'tip' => 'Rejected entries stay on the register (the story is complete) but are excluded from totals. Off-roll agent earnings carry no bank tag yet — a v1 limit. Make sure your company\'s GSTIN, PAN and address are filled on the Companies screen; they are the seller block on the invoice.',
                'r' => ['Admin, HR & Accounts'],
                'rel' => 'Also see: Commission Entries, TDS, Payout Reconciliation',
            ],
            'helpdesk' => [
                'm' => 'People', 't' => 'HR Helpdesk', 'g' => 'Employee tickets to HR',
                'w' => 'The internal ticket desk: employees raise requests and problems (payroll doubts, documents, IT, leave questions) with a subject, category, priority and a free-text description; HR works the queue by status. Employees see only their own tickets; HR and Admin see all of them.',
                'f' => [['fa-headset', 'Tickets with priority + status'], ['fa-pen', 'Free-text description'], ['fa-user-lock', 'Employees see only their own'], ['fa-list-check', 'HR works one queue']],
                's' => ['Employee: click Add Ticket, describe the issue in the Description box, submit — it starts as Open.', 'HR: triage by priority, update the status as you work it, close with the outcome noted.', 'Watch categories that repeat — each repeat is an FAQ or a policy fix waiting.'],
                'tip' => 'Ask employees to put WHAT + WHEN + WHERE in the description — one complete paragraph closes tickets in one reply.',
                'r' => ['Everyone — raise & track their own', 'Admin & HR — manage all'],
                'rel' => 'Also see: FAQs, POSH Complaints',
            ],
            'posh' => [
                'm' => 'Statutory & Compliance', 't' => 'POSH Complaints', 'g' => 'Confidential channel to the Internal Committee',
                'w' => 'The confidential register required by the POSH Act 2013: any employee can file a sexual-harassment complaint from their own login — subject, who it is against, incident date and a full free-text description. Only the complainant and Admin/HR (the Internal Committee) can see a complaint; managers and colleagues cannot. Status tracks the inquiry from Open to Resolved.',
                'f' => [['fa-shield-heart', 'File from your own login'], ['fa-user-lock', 'Visible ONLY to you and Admin/HR'], ['fa-calendar-day', 'Incident date + full description'], ['fa-list-check', 'Inquiry status trail']],
                's' => ['Employee: Add POSH Complaint, state what happened in the Description, submit — it reaches the Internal Committee alone.', 'IC (Admin/HR): acknowledge fast, move the status to Inquiry, record the outcome as Resolved/Closed.', 'Keep every communication inside the record — confidentiality is the law, not a courtesy.'],
                'tip' => 'The POSH Act expects the inquiry to complete within 90 days of the complaint — the Open date on the row is your clock.',
                'r' => ['Everyone — file & track their own', 'Admin & HR (IC) — full register'],
                'rel' => 'Also see: Code of Conduct, Complaints',
            ],
            'bonus-enc' => [
                'm' => 'Compensation & Claims', 't' => 'Bonus & Encashment', 'g' => 'Festival bonus & leave encashment',
                'w' => 'One-time payouts outside regular salary — annual bonus, festival advance, leave encashment — requested, approved and folded into payroll like everything else.',
                'f' => [['fa-gift', 'Bonus / encashment entries'], ['fa-check', 'Approval chain']],
                's' => ['Add the entry with type and amount.', 'On approval it pays through the next payroll.'],
                'tip' => 'Use this rather than ad-hoc bank transfers — it keeps the GST/TDS records and the employee ledger correct.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'increments' => [
                'm' => 'Compensation & Claims', 't' => 'Increment / Appraisal', 'g' => 'Raise the CTC, with the letter',
                'w' => 'Record an increment — old CTC to new CTC (the percentage cross-calculates), optional promotion — route it for approval, email the formal increment letter automatically, and apply the new CTC to the employee record with one click.',
                'f' => [['fa-arrow-trend-up', 'Old → new CTC with %'], ['fa-user-tie', 'Optional designation change'], ['fa-file-pdf', 'Auto increment letter'], ['fa-bolt', 'One-click apply to record']],
                's' => ['Add the increment — pick the employee; old CTC fills itself; enter new CTC or %.', 'On approval the letter emails automatically.', 'Click Apply to update the employee\'s CTC from the effective date.'],
                'tip' => 'Edits after approval re-send a corrected letter automatically; once APPLIED, the record is frozen — raise a fresh increment for further changes.',
                'r' => ['Admin & HR — raise/apply', 'Approvers — approve'],
                'rel' => '',
            ],
            'exits' => [
                'm' => 'Compensation & Claims', 't' => 'Exit & Full-and-Final', 'g' => 'Leave properly, settle fully',
                'w' => 'The right way to offboard: record the exit, compute the full-and-final — pending salary, encashment, recoveries, loan balances — settle, and the employee record closes with history intact and the seat freed.',
                'f' => [['fa-person-walking-arrow-right', 'Exit with last working day'], ['fa-calculator', 'F&F computation'], ['fa-check', 'Approval & settlement']],
                's' => ['Raise the exit with reason and last working day.', 'Review the F&F lines — recoveries and dues both ways.', 'Approve and settle; the employee deactivates with records preserved.'],
                'tip' => 'Never hard-delete a leaver from the Directory — exits keep the history that audits and re-hire checks need.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
        ];
    }

    // ================= STATUTORY & COMPLIANCE =================
    private static function statutoryModule(): array
    {
        return [
            'pf-esic' => [
                'm' => 'Statutory & Compliance', 't' => 'PF & ESI', 'g' => 'Provident fund & insurance, filing-ready',
                'w' => 'Monthly PF and ESI figures computed from payroll — employee and employer contributions per person — in formats ready for the EPFO/ESIC portals.',
                'f' => [['fa-shield', 'Monthly contribution registers'], ['fa-file-export', 'ECR-style export'], ['fa-building', 'Per company']],
                's' => ['Pick the month after payroll is approved.', 'Verify totals, export, and file on the government portal.', 'Edit Rates (top of this screen) also holds the payroll behaviour settings — weekly off day, Saturday policy and the Salary/LOP day basis: Working days (1 LOP day costs gross ÷ working days), Calendar days (gross ÷ days in month — offs and holidays are paid days, the common Indian payslip style) or Fixed 30 (gross ÷ 30 every month).'],
                'tip' => 'Rates are configurable in Edit Rates — if a statutory rate or your LOP day-basis policy changes, update once and every later month follows. Existing companies keep the Working-days basis until you change it.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'tds' => [
                'm' => 'Statutory & Compliance', 't' => 'TDS', 'g' => 'Tax deducted at source, tracked',
                'w' => 'TDS cut from salaries and commissions, per employee per month — the working you need for challan deposits and quarterly returns.',
                'f' => [['fa-percent', 'Per-employee TDS register'], ['fa-file-invoice', 'Deposit & return support']],
                's' => ['Review monthly TDS after each payroll.', 'Deposit by the due date; use TDS Returns for the quarterly filing tracker.'],
                'tip' => 'Commission TDS (default 5%) and salary TDS are tracked separately on each entry — the registers show both.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: TDS Returns',
            ],
            'pt' => [
                'm' => 'Statutory & Compliance', 't' => 'Professional Tax', 'g' => 'State PT slabs applied',
                'w' => 'Professional tax computed by state slab for every employee, summarised for monthly payment to the state.',
                'f' => [['fa-scale-balanced', 'Slab-wise computation'], ['fa-building', 'Per company/state']],
                's' => ['Open the month and verify the slab totals.', 'Pay and keep the challan reference.'],
                'tip' => 'PT differs by state — multi-state groups see each company under its own state slab.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'gratuity' => [
                'm' => 'Statutory & Compliance', 't' => 'Gratuity', 'g' => 'Long-service liability view',
                'w' => 'A report of gratuity accrued per eligible employee (4 years 240 days+), so the liability never surprises you at exit time.',
                'f' => [['fa-award', 'Eligibility & accrual per employee']],
                's' => ['Review yearly, and before any senior exit.'],
                'tip' => 'Gratuity owed appears in the exit Full & Final automatically for eligible leavers.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'tds-returns' => [
                'm' => 'Statutory & Compliance', 't' => 'TDS Returns', 'g' => 'Quarterly filing tracker',
                'w' => 'Track each quarter\'s TDS return per company — due date, filed date, acknowledgement — so no quarter slips past its deadline.',
                'f' => [['fa-calendar-check', 'Quarter-wise status'], ['fa-file-circle-check', 'Acknowledgement record']],
                's' => ['Create the quarter entry when you file.', 'Store the acknowledgement number against it.'],
                'tip' => 'This is a company-level tracker; the per-employee tax detail lives in the TDS register.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'compliance-alerts' => [
                'm' => 'Statutory & Compliance', 't' => 'Compliance Alerts', 'g' => 'DRA / PCC expiry radar',
                'w' => 'Every field agent\'s DRA certification and police clearance tracked with expiry dates. The system emails alerts before anything lapses — expired, due in 7 days, due in 30 — so agents are never fielded non-compliant.',
                'f' => [['fa-bell', 'Automatic expiry alerts'], ['fa-list', 'Expired / due-soon lists'], ['fa-paper-plane', 'Send the digest now']],
                's' => ['Keep DRA/PCC dates current on agent profiles.', 'Watch this screen weekly; act on the red list first.', 'Use Run Now to email the digest before a client audit.'],
                'tip' => 'Banks check exactly this in vendor audits — a clean screen here is business protection, not paperwork.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Off-roll Agents, Agent Authorization',
            ],
            'bonus' => [
                'm' => 'Statutory & Compliance', 't' => 'Statutory Bonus', 'g' => 'Payment of Bonus Act register',
                'w' => 'The annual statutory bonus register (Payment of Bonus Act, 1965): who is eligible (Basic+DA ≤ ₹21,000/month), the capped bonus wage (₹7,000/month), months worked this financial year (joiners prorated), and the annual bonus at your configured rate (8.33% minimum to 20%).',
                'f' => [['fa-coins', 'Eligibility & wage cap applied'], ['fa-calendar', 'FY months prorated for joiners'], ['fa-sliders', 'Rate configurable (8.33–20%)'], ['fa-file-lines', 'Provision total for budgeting']],
                's' => ['Open the register — eligibility and amounts compute from the same engine as the payslips.', 'Set your bonus % in Settings → Statutory Rates (default 8.33%, the statutory minimum).', 'Pay the amounts through Bonus & Encashment so they land on payslips with proper records.'],
                'tip' => 'Values are indicative defaults — verify the wage cap and rate against current law and any state amendment before declaring. Pay within 8 months of the financial year close.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Bonus & Encashment',
            ],
            'agent-auth' => [
                'm' => 'Field Force', 't' => 'Agent Authorization', 'g' => 'Who may work which portfolio',
                'w' => 'Authorization letters that tie an agent to a bank/portfolio with validity dates — the document a field agent must carry, tracked so expired authorizations stand out.',
                'f' => [['fa-id-card-clip', 'Authorization per agent & client'], ['fa-calendar', 'Validity tracking']],
                's' => ['Record each authorization with client, portfolio and validity.', 'Renew before expiry — the list highlights what is close.'],
                'tip' => 'Pair this with Compliance Alerts — together they are your audit-day answer for "show me agent compliance."',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
        ];
    }

    // ================= PERFORMANCE & REWARDS =================
    private static function performanceModule(): array
    {
        return [
            'performance' => [
                'm' => 'Performance & Rewards', 't' => 'Performance', 'g' => 'Review cycles, recorded',
                'w' => 'Run appraisal cycles — set the cycle, record ratings and reviewer notes per employee, and use the outcome to drive increments.',
                'f' => [['fa-clipboard-check', 'Cycle-wise reviews'], ['fa-star-half-stroke', 'Ratings & notes']],
                's' => ['Create the cycle (e.g., FY annual).', 'Record each employee\'s rating and remarks.', 'Feed the results into Increment / Appraisal.'],
                'tip' => 'Keep remarks factual and specific — they become the justification on the increment letter trail.',
                'r' => ['Admin, HR & managers'],
                'rel' => 'Also see: Increment / Appraisal',
            ],
            'points-rules' => [
                'm' => 'Performance & Rewards', 't' => 'Points Rules', 'g' => 'Gamification settings',
                'w' => 'Define what earns points — collections, attendance streaks, training completion — and how many. The rules feed the points ledger and leaderboards that keep field teams pushing.',
                'f' => [['fa-gears', 'Point values per action']],
                's' => ['Set a handful of clear rules everyone understands.', 'Watch behaviour follow the points.'],
                'tip' => 'Fewer, bigger rules beat many small ones — teams game what they can see.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'points-ledger' => [
                'm' => 'Performance & Rewards', 't' => 'Points Ledger', 'g' => 'Every point, accounted',
                'w' => 'The running record of points earned and redeemed per employee — the backing data for scores and awards.',
                'f' => [['fa-coins', 'Earn/redeem entries'], ['fa-user', 'Per-employee history']],
                's' => ['Entries post from rules or manual awards.', 'Review before announcing winners.'],
                'tip' => 'Manual adjustments belong here with a reason — silent score changes kill trust in the game.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'points-scores' => [
                'm' => 'Performance & Rewards', 't' => 'Leaderboard', 'g' => 'Who is on top',
                'w' => 'Live standings from the points ledger — by team or company — for the floor screen and monthly announcements.',
                'f' => [['fa-ranking-star', 'Rankings by period']],
                's' => ['Open it Monday morning; let the team see it.'],
                'tip' => 'Publishing the leaderboard on the Notice Board doubles its effect.',
                'r' => ['Everyone'],
                'rel' => '',
            ],
            'awards' => [
                'm' => 'Performance & Rewards', 't' => 'Awards & Rewards', 'g' => 'Recognise the best',
                'w' => 'Record awards — star of the month, best recovery, milestones — building each employee\'s recognition history.',
                'f' => [['fa-trophy', 'Award entries with reason']],
                's' => ['Add the award with reason and date.', 'Announce it on the Notice Board.'],
                'tip' => 'Recognition recorded here shows on the employee\'s profile — it compounds.',
                'r' => ['Admin, HR & managers'],
                'rel' => '',
            ],
            'tests' => [
                'm' => 'Performance & Rewards', 't' => 'Tests', 'g' => 'Quiz your team',
                'w' => 'Create multiple-choice tests — product knowledge, RBI norms, process — assign them, and collect scores automatically.',
                'f' => [['fa-circle-question', 'MCQ builder'], ['fa-users', 'Assign to staff'], ['fa-square-poll-vertical', 'Auto-scored attempts']],
                's' => ['Create the test and add questions with four options each.', 'Assign and set a window.', 'Scores collect under Test Reports.'],
                'tip' => 'Short and frequent beats long and rare — 10 questions monthly keeps knowledge fresh.',
                'r' => ['Admin & HR — build', 'Everyone — attempt'],
                'rel' => 'Also see: Training Programs',
            ],
            'test-reports' => [
                'm' => 'Performance & Rewards', 't' => 'Test Reports', 'g' => 'Scores & completion',
                'w' => 'Who attempted, who passed, who never opened it — completion and score analysis per test.',
                'f' => [['fa-chart-column', 'Scores per test & person']],
                's' => ['Open after each test window closes.', 'Follow up the never-attempted list.'],
                'tip' => 'Repeated low scores on the same topic = a training gap, not a people problem.',
                'r' => ['Admin, HR & managers'],
                'rel' => '',
            ],
        ];
    }

    // ================= MAIN =================
    private static function mainModule(): array
    {
        return [
            'dashboard' => [
                'm' => 'Main', 't' => 'Dashboard', 'g' => 'Your live command centre',
                'w' => 'The dashboard shows the health of your workforce right now — active employees, who is present today, approvals waiting for you, compliance flags and the latest payroll. Every number is live and clickable.',
                'f' => [['fa-gauge-high', 'Live headcount & attendance'], ['fa-hourglass-half', 'Pending approvals count'], ['fa-triangle-exclamation', 'Compliance flags (30 days)'], ['fa-money-check-dollar', 'Latest payroll status'], ['fa-bolt', 'Quick links to daily screens']],
                's' => ['Check the cards each morning — they answer "is anything waiting for me?"', 'Click any card to jump straight to that screen.', 'Use the company switcher in the top bar to see one company or the whole group.'],
                'tip' => 'The Live Salary card at the bottom shows YOUR OWN salary earned till today — every employee sees their own.',
                'r' => ['Everyone — numbers scoped to your role'],
                'rel' => 'Also see: Live Salary, Approvals Inbox',
            ],
            'live-salary' => [
                'm' => 'Main', 't' => 'Live Salary', 'g' => 'Salary earned till today, entry by entry',
                'w' => 'Instead of waiting for month-end, this screen shows how much salary has been earned up to today — basic, allowances, deductions, plus approved overtime, loan EMIs, advance recoveries and every commission entry, including ones still awaiting approval. It uses the same engine (and the same Salary/LOP day basis setting) as the real payroll, so the numbers always match.',
                'f' => [['fa-bolt', 'Net earned till today'], ['fa-chart-line', 'Full-month projection'], ['fa-list', '"How you earned it" passbook'], ['fa-hand-holding-dollar', 'Commissions tab with status'], ['fa-users', 'Managers: view your team']],
                's' => ['Open the screen — your own live salary loads automatically.', 'Switch to the Commissions & Incentives tab to see every entry and whether it is approved, pending or locked.', 'Managers and HR can pick any team member from the dropdown.'],
                'tip' => 'Pending commissions are shown but NOT counted in the big number until approved — the amber chip tells you what is awaiting approval. Curious what next month could look like with more collections or overtime? The <b>Simulator</b> button opens a what-if payslip where you can change every variable.',
                'r' => ['Employees — own salary', 'Managers — own + team', 'Admin/HR — everyone'],
                'rel' => 'Also see: Commission Entries, Salary & Commission Ledger',
            ],
            'escalations' => [
                'm' => 'Main', 't' => 'Escalation Desk', 'g' => 'Bank escalations, tracked to closure',
                'w' => 'When a bank or client raises an escalation — a complaint, an audit query, a penalty notice — log it here so nothing slips. Each escalation carries a severity, an owner, a resolution deadline and a closure note.',
                'f' => [['fa-plus', 'Log a new escalation'], ['fa-user-check', 'Assign an owner'], ['fa-clock', 'Track SLA / deadline'], ['fa-flag-checkered', 'Close with resolution notes']],
                's' => ['Click the add button and describe the escalation with the client name and severity.', 'Assign it to the person responsible and set the resolution date.', 'Update the status as it progresses; close it with what was done.'],
                'tip' => 'Banks ask for escalation registers during audits — keeping this screen current is your evidence.',
                'r' => ['Admin & HR — full', 'Managers — own assignments'],
                'rel' => '',
            ],
            'approvals-inbox' => [
                'm' => 'Main', 't' => 'Approvals Inbox', 'g' => 'Everything waiting for YOUR decision',
                'w' => 'One inbox for every request where you are the approver — leave, expenses, advances, loans, commissions, increments, transfers and more. Decide from here without hunting through each module.',
                'f' => [['fa-inbox', 'All pending requests in one list'], ['fa-check', 'Approve with one click'], ['fa-xmark', 'Reject with remarks'], ['fa-user', 'Tap a name for the profile card']],
                's' => ['Open the inbox — newest requests are on top.', 'Click a row to see the full details.', 'Approve or reject; add remarks so the employee knows why.'],
                'tip' => 'The bell icon in the top bar shows the same count — it updates live as requests arrive.',
                'r' => ['Anyone who approves — managers, HR, Admin'],
                'rel' => '',
            ],
            'notifications' => [
                'm' => 'Main', 't' => 'Notifications', 'g' => 'Your activity stream',
                'w' => 'A running list of things that involve you — requests decided, notices published, tasks assigned. Use it to catch up after a day off.',
                'f' => [['fa-bell', 'Chronological activity feed'], ['fa-filter', 'Filter by type'], ['fa-arrow-right', 'Jump to the related screen']],
                's' => ['Scan the list — unread items are highlighted.', 'Click an item to open the screen it refers to.'],
                'tip' => 'The bell in the top bar is the quick version of this screen.',
                'r' => ['Everyone — own notifications'],
                'rel' => '',
            ],
            'how-it-works' => [
                'm' => 'Main', 't' => 'How It Works', 'g' => 'A quick orientation to SmartPRS',
                'w' => 'A guided overview of how the pieces fit together — how attendance flows into payroll, how approvals move, and where to start as a new user.',
                'f' => [['fa-graduation-cap', 'Module-by-module overview'], ['fa-route', 'Recommended first steps']],
                's' => ['Read top to bottom once when you join.', 'Come back whenever a flow feels unclear.'],
                'tip' => 'Every screen also has this ⓘ guide — tap it anywhere you feel lost.',
                'r' => ['Everyone'],
                'rel' => '',
            ],
            'kb' => [
                'm' => 'Main', 't' => 'Knowledge Base', 'g' => 'Policies, guides and industry know-how',
                'w' => 'Searchable articles — company policies, process guides and collections-industry references (RBI fair practices, DRA norms, recovery etiquette). Content is role-filtered, so people see what applies to them.',
                'f' => [['fa-magnifying-glass', 'Search all articles'], ['fa-folder-open', 'Browse by category'], ['fa-shield', 'RBI / DRA compliance references']],
                's' => ['Type a keyword in the search box.', 'Open an article and follow it step by step.'],
                'tip' => 'Admins can add company-specific articles — new joiners then self-serve instead of asking HR.',
                'r' => ['Everyone — role-filtered'],
                'rel' => 'Also see: FAQs, Code of Conduct',
            ],
        ];
    }

    // ================= PEOPLE =================
    private static function peopleModule(): array
    {
        return [
            'emp-list' => [
                'm' => 'People', 't' => 'Directory', 'g' => 'One source of truth for every employee',
                'w' => 'Every employee with their photo, code, designation, company, team and contact details. This is where records are created, edited and kept current — every other module reads from here.',
                'f' => [['fa-user-plus', 'Add an employee'], ['fa-file-import', 'Bulk import from Excel'], ['fa-pen', 'Edit any profile'], ['fa-sitemap', 'Set manager, team & company'], ['fa-magnifying-glass', 'Search & filter']],
                's' => ['Click Add Employee and fill the profile — code, designation, company, CTC and reporting manager matter most.', 'Or import many at once from the Excel template.', 'Keep the reporting manager correct — approvals and team views depend on it.'],
                'tip' => 'Deleting is deliberately hard here. Real leavers should go through Exit & FnF so dues are settled and history is kept.',
                'r' => ['Admin & HR — full', 'Managers — view team', 'Employees — view directory'],
                'rel' => 'Also see: Teams, ID Cards, Transfers',
            ],
            'teams' => [
                'm' => 'People', 't' => 'Teams', 'g' => 'Group people under leaders',
                'w' => 'Create teams (for example, a recovery squad per portfolio or branch) with a team leader. Teams drive scoping — leaders see their members in attendance, live salary and approvals.',
                'f' => [['fa-users', 'Create teams'], ['fa-user-tie', 'Assign a leader'], ['fa-arrows-rotate', 'Move members between teams']],
                's' => ['Add a team with a clear name and pick its leader.', 'Open employees in the Directory and set their team.', 'The leader now sees their members across the app.'],
                'tip' => 'A company transfer clears team membership on purpose — re-assign the person at the new location.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'idcard' => [
                'm' => 'People', 't' => 'ID Cards', 'g' => 'Printable employee ID cards',
                'w' => 'Generate professional ID cards as PDFs — photo, name, code, designation and company branding. Upload a photo once and the card is ready to print.',
                'f' => [['fa-camera', 'Upload / change photo'], ['fa-id-card', 'Generate the ID card PDF'], ['fa-square-check', 'Bulk select rows'], ['fa-trash', 'Admin: soft-delete wrong rows']],
                's' => ['Click Set Photo on a row and upload a clear passport-style picture.', 'Click ID Card PDF — it opens ready to print.', 'Repeat per employee, or fix photos first and batch-print.'],
                'tip' => 'The delete here is for wrong or duplicate rows only (it keeps history). Real leavers go through Exit & FnF.',
                'r' => ['Admin & HR — manage', 'Admin — delete'],
                'rel' => '',
            ],
            'transfers' => [
                'm' => 'People', 't' => 'Transfers', 'g' => 'Branch, department & company moves with a paper trail',
                'w' => 'Move an employee to another branch, department or group company with approval, a formal transfer-order letter, the employee\'s acknowledgement and automatic application on the effective date. The employee keeps the same code across the group.',
                'f' => [['fa-right-left', 'Raise a transfer request'], ['fa-calendar-check', 'Future-dated auto-apply'], ['fa-file-pdf', 'Transfer order letter PDF'], ['fa-signature', 'Employee acknowledgement link'], ['fa-list-check', 'Per-transfer process tracker']],
                's' => ['Raise the transfer: pick the employee, type of move, destination and effective date.', 'The approver decides; on approval the order letter is emailed automatically.', 'On the effective date the move applies itself; use Track on the row to watch each stage.'],
                'tip' => 'Company moves clear the old manager and team (designation stays) and open a transfer-onboarding card so the new location assigns them properly.',
                'r' => ['Admin & HR — raise/apply', 'Managers — approve', 'Employee — acknowledge'],
                'rel' => 'Also see: Onboarding, Directory',
            ],
            'documents' => [
                'm' => 'People', 't' => 'Documents', 'g' => 'Employee document locker',
                'w' => 'Track the documents each employee has submitted — ID proof, PAN, certificates, agreements — with expiry dates where relevant, so nothing is missing when an audit comes.',
                'f' => [['fa-folder-open', 'Record documents per employee'], ['fa-calendar', 'Expiry tracking'], ['fa-magnifying-glass', 'Find who is missing what']],
                's' => ['Add a record per document with its type and expiry if any.', 'Review the list before audits or client onboarding.'],
                'tip' => 'For field agents, DRA and PCC have their own dedicated tracking under Compliance — with automatic expiry alerts.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'bgv' => [
                'm' => 'People', 't' => 'Background Verification', 'g' => 'BGV status per hire',
                'w' => 'Track background verification for new hires — address check, previous employer, police verification — with a clear status per person so onboarding is never blocked silently.',
                'f' => [['fa-user-shield', 'BGV record per employee'], ['fa-list-check', 'Stage-wise status'], ['fa-flag', 'Flag failures for action']],
                's' => ['Create a BGV entry when a hire is confirmed.', 'Update the status as checks complete.', 'Close it verified, or flag it and inform HR.'],
                'tip' => 'Banks increasingly insist on completed BGV for collections staff — keep this current for client audits.',
                'r' => ['Admin & HR'],
                'rel' => '',
            ],
            'onboarding-board' => [
                'm' => 'People', 't' => 'Onboarding', 'g' => 'New-hire checklist, visual',
                'w' => 'Every new joiner gets an onboarding card with a checklist — documents, ID card, system access, training. Transfer arrivals appear here too, so the new location completes their setup.',
                'f' => [['fa-clipboard-check', 'Checklist per joiner'], ['fa-bars-progress','Stage tracking'], ['fa-user-gear', 'Assign role, manager & team']],
                's' => ['Open the card of a new joiner.', 'Tick items as they complete; use the assign link to set manager and team.', 'Close the card when everything is done.'],
                'tip' => 'Cards appear automatically when you hire from Recruitment or approve a company transfer.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Recruitment, Transfers',
            ],
        ];
    }

    // ================= HIRING & ONBOARDING =================
    private static function hiringModule(): array
    {
        return [
            'recruitment' => [
                'm' => 'Hiring & Onboarding', 't' => 'Recruitment', 'g' => 'Portal data to hired — at volume',
                'w' => 'Your complete hiring desk: raise requisitions with approval, import hundreds of candidates from job portals into the Talent Pool, send bulk WhatsApp interview or walk-in invites, run Hiring Drives, interview with panel scores, and convert to employee in one click.',
                'f' => [['fa-clipboard-list', 'Requisitions with approval'], ['fa-database', 'Talent Pool + portal import'], ['fa-whatsapp', 'Bulk WhatsApp invites'], ['fa-calendar-check', 'Hiring Drives with panels'], ['fa-comments', 'Interviews & scores'], ['fa-user-check', 'One-click hire → employee']],
                's' => ['Raise a requisition for the position; an admin approves it.', 'Import the job-portal export (Excel/CSV) into the Talent Pool and filter to the matching candidates.', 'Shortlist and send a bulk WhatsApp invite — or plan a Hiring Drive with venue, map link and panel.', 'Mark attendance and scores during interviews; hit Hire on the selected ones.', 'The hire becomes a full employee with onboarding started automatically.'],
                'tip' => 'Every bulk send becomes a tracked campaign under the WhatsApp Campaigns tab — sent, delivered, interested, attended, hired. And during the interview itself, click the <b>Salary Simulator</b> button (top of this screen) — enter the offer CTC and show the candidate a live payslip illustration of their exact in-hand, with PF, ESI, PT and TDS worked out. Print it as a one-page handout.',
                'r' => ['Admin & HR — full', 'Admins — approve requisitions'],
                'rel' => 'Also see: Onboarding, Off-roll Agents',
            ],
            'offroll-agents' => [
                'm' => 'Field Force', 't' => 'Off-roll Agents', 'g' => 'Vendor agents outside payroll, fully compliant',
                'w' => 'Commission-only field agents engaged through vendors live here — separate from payroll employees — with complete KYC: photo, ID, PAN, DRA certificate, police clearance, agreement and bank details, plus their own live-earnings page.',
                'f' => [['fa-id-badge', 'Full KYC per agent'], ['fa-file-shield', 'DRA & PCC documents'], ['fa-envelope-circle-check', 'Email verification'], ['fa-coins', 'Earnings entries with approval'], ['fa-link', 'Public live-earnings link for the agent']],
                's' => ['Add the agent with vendor, payout type and rate.', 'Open KYC / Docs on the row and upload each document.', 'Record earnings as they happen; approve them; send the agent their private earnings link on WhatsApp.'],
                'tip' => 'The earnings link is public-but-secret (token protected) — the agent sees approved amounts live without needing a login.',
                'r' => ['Admin & HR'],
                'rel' => 'Also see: Agent Authorization, Compliance Alerts',
            ],
        ];
    }
}
