<?php

namespace App\Http\Controllers;

/**
 * rev181b (Ejaz, 10 Jul 2026) — THE SALARY CALCULATION GUIDE, in the app.
 *
 * The ⓘ screen help explains each SCREEN; this controller documents the
 * ENGINE — the actual calculation rules — plus the FAQs every client asks in
 * demos ("why gross ÷ 26 and not ÷ 30?", "why is TDS zero?", "why didn't the
 * loan EMI deduct?"). Until now this logic lived only in external reference
 * documents; prospects in a demo never see those. Now it is a first-class,
 * searchable, printable screen inside SmartPRS.
 *
 * Content lives HERE as plain arrays (same philosophy as ScreenHelpController:
 * never inside the boot-JS heredoc) so extending it is a cheap, safe append.
 * Served read-only to EVERY logged-in role — employees deserve to understand
 * their own payslip; that transparency is itself a selling point.
 *
 * KEEP THIS ACCURATE: whenever a rev changes the engine, update the matching
 * section/FAQ here — this screen is a promise to clients about how the
 * engine behaves.
 */
class CalcGuideController extends Controller
{
    public function show()
    {
        return response()->json([
            'ok' => true,
            'updated' => 'July 2026 (rev183 - F1 Statutory Configuration)',
            'sections' => self::sections(),
            'faqs' => self::faqs(),
        ]);
    }

    private static function sections(): array
    {
        return [
            [
                'id' => 'pipeline', 'icon' => 'fa-diagram-project', 'title' => '1 · From CTC to Net Pay — the pipeline',
                'tag' => 'Every payslip is computed the same way, in the same order.',
                'points' => [
                    '<b>CTC is annual.</b> Monthly gross = CTC ÷ 12. Example: CTC ₹6,00,000 → monthly gross ₹50,000.',
                    '<b>Components split the gross.</b> Default structure: Basic = 50% of gross, HRA = 40% of Basic, the rest as Special Allowance. If Salary Setup defines a structure, it OVERRIDES the default — resolved most-specific-first: employee → team → company.',
                    '<b>Attendance factor scales the earnings</b> (see section 2) — absences, late cuts and the sandwich rule reduce pay; weekly offs and holidays never do.',
                    '<b>Extras are added:</b> approved commissions/incentives due this month (each Purpose as its own earning line), approved overtime, night-shift allowance.',
                    '<b>Deductions come off:</b> statutory (PF, ESI, PT, TDS — section 4), then recoveries in strict order: Loan EMI → Salary Advance → Clawback (section 6).',
                    '<b>Net pay can never go below zero.</b> If deductions would exceed earnings, recoveries are skipped or reduced and the payslip note says so.',
                    'Every payslip carries a <b>calculation note</b> explaining its own maths — open any payslip and read the story of that month.',
                ],
                'example' => 'CTC ₹6,00,000 → gross ₹50,000/month → Basic ₹25,000 · HRA ₹10,000 · Special ₹15,000 → PF ₹1,800 (12% of capped Basic) → attendance full → net before extras ₹48,200.',
            ],
            [
                'id' => 'attendance', 'icon' => 'fa-calendar-check', 'title' => '2 · Attendance, LOP and the day-value',
                'tag' => 'What one day of salary is worth — the question behind most demo doubts.',
                'points' => [
                    '<b>Present days</b> = distinct punch dates in the month (biometric, mobile, manual or CSV — all land in the same register).',
                    '<b>Working days</b> = calendar days − weekly offs (configurable day + Saturday policy: all working / 2nd &amp; 4th off / 1st &amp; 3rd off) − holidays from the Holiday calendar.',
                    '<b>Approved PAID leave counts as present.</b> Unpaid leave types do not — they become LOP.',
                    '<b>The LOP day-value has three selectable bases</b> (Settings → Statutory Rates): <b>Working days</b> — one absent day costs gross ÷ working days (e.g. ÷ 26); <b>Calendar days</b> — costs gross ÷ days in month (÷ 31), weekly offs &amp; holidays are PAID days — the common Indian payslip style ("Total days 31"); <b>Fixed 30</b> — always gross ÷ 30.',
                    '<b>Late Policy</b> (company/team/employee scoped) adds late-mark cuts: grace minutes, tiered cuts, break-budget overruns — shifts from the Working Shifts master supply each day\'s timings.',
                    '<b>Sandwich rule (optional, OFF by default):</b> a weekly off between two absent days also becomes LOP.',
                    '<b>Mid-month joiner:</b> pay is prorated from the date of joining, not the whole month.',
                    '<b>Zero-punch = full pay (deliberate safety rule):</b> an employee with NO punches at all is paid in full rather than zero — a missing device must never zero a salary. The report screens make the gap visible instead.',
                ],
                'example' => 'Gross ₹50,000, July (31 days, 27 working), 2 absent days. Working basis: 50,000 × 25÷27 = ₹46,296. Calendar basis: 50,000 × 29÷31 = ₹46,774. Fixed-30: 50,000 × 28÷30 = ₹46,667. Same attendance — three defensible conventions; you choose once in settings.',
            ],
            [
                'id' => 'period', 'icon' => 'fa-calendar-days', 'title' => '3 · The payroll month and the pay date',
                'tag' => 'What period the money covers, and when it is paid.',
                'points' => [
                    '<b>By default the attendance period is the calendar month</b> — 1st to the last day. Every punch, leave and OT entry inside those dates belongs to that month\'s payslip.',
                    '<b>A cut-off period can be set instead</b> (Pay Cycle / Salary Schedules → Cut-off day). The period then runs from the day AFTER last month\'s cut-off to this month\'s cut-off. With cut-off 20, the July payslip covers <b>21 June to 20 July</b>. Clients use this as a retention guard — the last few days of work are always still unpaid.',
                    '<b>A cut-off day that does not exist in a short month falls back to the last calendar day</b> — a cut-off of 30 becomes 28 (or 29) in February, so periods never break.',
                    '<b>The pay DATE is the first occurrence of Pay Day on or after the period ends.</b> On a calendar month that means the following month (period ends 31 July, Pay Day 7 → 7 August). With a cut-off it is often the SAME month: period ends 20 July, Pay Day 30 → <b>30 July</b>. A company-specific schedule row beats an all-company row.',
                    '<b>The period is recorded on the run and on every payslip.</b> Regenerating uses the window the run was built from — changing the cut-off later can never silently re-slice a month already paid. The payslip prints it: <i>Salary Period: 21 Jun – 20 Jul 2026 (30 days)</i>.',
                    '<b>Changing the cut-off is guarded.</b> If the new period would leave days unpaid (switching calendar → 21st-20th skips 1–20 of the changeover month) or pay days twice, SmartPRS refuses the run and names the exact dates. Run a one-off payroll for the bridging days first.',
                    '<b>The LOP day-value follows the period</b>, not an assumed 30 or 31 — a 21st-to-20th period of 30 days divides by that period\'s working or calendar days (section 2).',
                    'Payslips, statutory registers (PF/ESI/PT/TDS), YTD and ledgers are all keyed to the same month label — one month, one truth.',
                    'Commissions are the exception by design: their <b>Payout Date</b> decides which month\'s payslip pays them (section 5).',
                ],
            ],
            [
                'id' => 'statutory', 'icon' => 'fa-landmark', 'title' => '4 · Statutory deductions — PF, ESI, PT, TDS',
                'tag' => 'Computed per slip from editable rates — never hardcoded.',
                'points' => [
                    '<b>PF:</b> 12% of min(Basic+DA, ₹15,000 wage cap) — employee side deducted, employer side shown in CTC workings. Example: Basic ₹25,000 → PF ₹1,800 (12% of the capped ₹15,000).',
                    '<b>ESI:</b> 0.75% employee / 3.25% employer when monthly gross ≤ ₹21,000. <b>Half-year lock:</b> once ESI applies in a contribution period (Apr–Sep / Oct–Mar), it continues on the FULL gross until the period ends — even if a raise crosses ₹21,000 mid-period. That is the law, not a bug.',
                    '<b>Professional Tax:</b> state-wise monthly slabs built in for 16 states (keyed on each employee\'s PT State), PT-free states show ₹0, Maharashtra February charges ₹300 so the year totals ₹2,500. No state set → the configured default slab. A state that revises its slab, or one not built in at all, is corrected under Statutory Configuration rather than by a code release.',
                    '<b>TDS on salary (Sec 192, new regime):</b> annual taxable = wage gross × 12 (reimbursements excluded) − standard deduction ₹75,000 → slabs → 87A rebate (nil tax up to ₹12,00,000) → 4% cess → ÷ 12 monthly. This is why most staff below ₹12.75L CTC see TDS ₹0 — the rebate, not an error.',
                    '<b>TDS on commissions (Sec 194H):</b> flat % (default 5, configurable; higher no-PAN rate supported) deducted on EVERY commission entry — gross − TDS = the net that pays. Registers for 192 and 194H are kept separately for the 26Q/24Q returns.',
                    '<b>Probation / Internship stage:</b> PF, PT and TDS are skipped for probationers and interns (as configured); ESI and LOP still apply.',
                    'All rates and slabs are editable in Settings → Statutory Rates — the values ship as current-law defaults and are marked indicative: verify before filing.',
                    '<b>Effective-dated, scoped overrides (Statutory Configuration):</b> any of these rates, and the PT state slabs, can be overridden from a chosen date and for a chosen scope. The rate in force is resolved defaults, then saved tenant rates, then a tenant-scope override, then state, then company, then location (branch city), then branch, with the narrowest winning and the payroll PERIOD (never today) deciding which override applies. With nothing saved there, the engine uses exactly the rates above. A branch or location override lets a group hold a different position at one site; only Professional Tax differs by state in law.',
                ],
            ],
            [
                'id' => 'commissions', 'icon' => 'fa-coins', 'title' => '5 · Commissions & incentives — from claim to payout',
                'tag' => 'The collection-industry heart of SmartPRS.',
                'points' => [
                    '<b>The lifecycle:</b> entry (with collection evidence — customer, account, mode, proof) → <b>Accounts confirms the money was received</b> → manager approves → payout. Approval is BLOCKED until Accounts confirms a collection claim.',
                    '<b>TDS 194H is netted on entry:</b> gross claimed − TDS = net payable. The net is what ever gets paid.',
                    '<b>Payout Method per entry (and per bulk batch):</b> <b>With salary</b> — folds into a payslip as its own earning line named by Purpose (e.g. "Recovery Incentive"); <b>Separate</b> — never touches the payslip; Accounts uses Record Payment (partial allowed) on any date, every payment lands in the Salary &amp; Commission Ledger passbook, and each payment has a <b>printable Payment Voucher</b> (branded, with signature blocks).',
                    '<b>Payout Date decides the month:</b> a June commission with payout date 10 August pays on the AUGUST payslip. No date → the earned month — unless the optional <b>payout lag</b> setting is on, which auto-dates entries N months after the earned month (the retention-guard lag; editable per entry).',
                    '<b>Once a payslip pays it, the entry LOCKS forever</b> — no edits, no re-decisions. Corrections go through Clawbacks, never by editing history.',
                    '<b>Bounce:</b> if a cheque returns or a settlement cancels, the Bounce action on the entry auto-creates an approved Clawback for whatever was paid (and rejects the unpaid remainder) — the next payslip recovers it (section 6).',
                    '<b>Eligibility gates (configurable):</b> the DRA gate (warn or block on expired/missing DRA certification) and the monthly points threshold gate apply at commit/approval. Points are a scoreboard only — they NEVER convert to money; they only decide who qualifies.',
                    '<b>Bulk engine:</b> the Calculator computes payouts from collected/target figures via flat %, slabs or per-portfolio rates (CSV import supported) and commits entries in one shot with the same TDS and gates.',
                ],
            ],
            [
                'id' => 'recoveries', 'icon' => 'fa-money-bill-transfer', 'title' => '6 · Loans, advances and clawbacks — how money comes back',
                'tag' => 'Deducted on the payslip, in a strict order, with the net-zero floor.',
                'points' => [
                    '<b>Order of recovery on every slip:</b> statutory deductions first, then Loan EMI → Salary Advance → Clawback. Net can never go below zero.',
                    '<b>Loan EMI is full-EMI-only:</b> if the month\'s net cannot fit the whole EMI, that month is SKIPPED (noted on the slip) and the installment stays due — never half-recovered. Loans auto-close after the last installment; every recovery is written to a loan statement (which run recovered which installment).',
                    '<b>Salary advances</b> recover in the same month they are approved; if the net is short, the balance is noted for manual follow-up — spread recoveries belong to Loans.',
                    '<b>Clawbacks</b> (manual or auto-created by Bounce) deduct as their own "Clawback / Reversal" line in their month. Recovery is attempted once; any shortfall stays open on the entry, visible, for manual handling.',
                    '<b>Everything is run-keyed:</b> if a draft run is regenerated, every recovery, lock and paid-flag from the old draft is reversed automatically and re-applied by the new one — no double deductions, no lost recoveries.',
                ],
            ],
            [
                'id' => 'ot', 'icon' => 'fa-business-time', 'title' => '7 · Overtime & night shifts',
                'tag' => 'Extra hours valued from the salary itself.',
                'points' => [
                    '<b>Overtime formula:</b> hourly rate = monthly gross ÷ 26 days ÷ 8 hours; OT amount = hours × multiplier (2× is the standard convention) × hourly rate. A manual amount can override.',
                    'Only <b>APPROVED</b> OT entries dated inside the month are paid; the run marks them Paid so they can never pay twice.',
                    '<b>Night shift allowance:</b> a fixed ₹ per night actually worked, from the Working Shifts master — counted from real punch dates on rostered night shifts.',
                ],
                'example' => 'Gross ₹50,000 → hourly ₹240.38. Sunday OT, 8 hours at 2× = ₹3,846 on that month\'s slip, as its own "Overtime" line.',
            ],
            [
                'id' => 'other', 'icon' => 'fa-gift', 'title' => '8 · Bonus, increments, arrears and exits',
                'tag' => 'The other money events, all through the same payslip discipline.',
                'points' => [
                    '<b>Statutory bonus:</b> the register applies the Payment of Bonus Act — eligible when Basic ≤ ₹21,000/month, bonus wage capped at ₹7,000/month, FY months prorated for joiners, rate configurable 8.33–20%. Paying happens through Bonus &amp; Encashment entries.',
                    '<b>Increments:</b> old CTC → new CTC with auto %, letter emailed on approval, one-click Apply updates the record. A <b>backdated</b> increment auto-creates a "Salary Arrears" entry: (new − old monthly) × back months, first month day-prorated — it pays on the next open payroll.',
                    '<b>Exit &amp; Full-and-Final:</b> pending salary, leave encashment and recoveries both ways are netted in one settlement; the employee record closes with history intact.',
                    '<b>Employment stage matters:</b> Permanent / Probation / Internship each carry their configured statutory treatment (section 4).',
                ],
            ],
            [
                'id' => 'locks', 'icon' => 'fa-lock', 'title' => '9 · Locks, regeneration and the safety rules',
                'tag' => 'Why the numbers can be trusted — the rules that never bend.',
                'points' => [
                    '<b>Draft runs can be regenerated freely:</b> the old draft\'s payslips are replaced and every side effect (commission locks, OT paid-flags, loan/advance/clawback recoveries) is reversed and cleanly re-applied.',
                    '<b>Approved runs are frozen.</b> Corrections to paid money go through Clawbacks — history is never edited.',
                    '<b>A commission paid by a payslip is locked forever</b>, with the run number stamped in its history.',
                    '<b>The six standing safety rules:</b> points ≠ money · off-roll agents stay outside payroll · every money side-effect is run-keyed · net ≥ 0 always · zero-punch = full pay · CTC 0 = skipped (flagged, never paid ₹0).',
                    'Every payslip stores its own calculation note; every money action lands in the audit trail. What a client sees in a demo is what the engine actually does.',
                ],
            ],
        ];
    }

    private static function faqs(): array
    {
        // The questions actually asked across 25+ client demos (Ejaz, Jul 2026).
        // q = the question as clients ask it; a = the plain answer; w = where to see it.
        return [
            ['q' => 'Why does one absent day cut gross ÷ 26 and not gross ÷ 30?',
                'a' => 'Because the LOP day-value basis is set to "Working days" (gross ÷ working days after removing weekly offs and holidays). If you prefer the common payslip style "Total days 31" where offs are paid days, switch the basis to "Calendar days" — or "Fixed 30" for a flat ÷30 every month. One setting, applies from the next payroll.',
                'w' => 'Settings → Statutory Rates → Salary / LOP day basis'],
            ['q' => 'An employee has NO punches at all — why did they get full salary?',
                'a' => 'Deliberate safety rule: zero punches almost always means a device or sync problem, and a machine fault must never zero a person\'s salary. The attendance reports make the gap visible so you can fix the data and regenerate the draft.',
                'w' => 'Attendance → Attendance Report; regenerate from Generate Payroll'],
            ['q' => 'Why is TDS showing zero for most employees?',
                'a' => 'The new-regime 87A rebate: annual taxable income up to ₹12,00,000 pays nil tax (after the ₹75,000 standard deduction, that is roughly CTC ₹12.75L). It is the law, not a miss — employees above the threshold get slab-wise TDS with cess, spread monthly.',
                'w' => 'Statutory → TDS (per-employee working)'],
            ['q' => 'Salary crossed ₹21,000 after a raise — why is ESI still being deducted?',
                'a' => 'The ESI half-year lock: once a contribution period (Apr–Sep or Oct–Mar) starts with ESI applicable, contributions continue on the full gross until the period ends. From the next period the employee exits ESI automatically.',
                'w' => 'Statutory → PF & ESI'],
            ['q' => 'Why did the loan EMI not deduct this month?',
                'a' => 'Full-EMI-only rule: if the month\'s net (after other deductions) cannot fit the entire EMI, the month is skipped and the payslip note says so. The installment stays due and recovers when the net allows. Partial EMIs are never taken.',
                'w' => 'The payslip\'s calculation note; Loans screen shows installments recovered'],
            ['q' => 'Why is an approved commission not showing on the payslip?',
                'a' => 'Four possibilities, in demo order: (1) its Payout Method is "Separate" — it pays through Record Payment and the ledger, never the payslip; (2) its Payout Date points to a different month; (3) Accounts has not confirmed the collection yet, so it could not be approved; (4) it is still Pending. The entry\'s History link shows its exact state.',
                'w' => 'Commissions → Commission Entries → History'],
            ['q' => 'Client pays salary on the 1st but incentives on the 10th — can we do that?',
                'a' => 'Yes, two ways: set the entries\' Payout Method to "Separate" and record the payment on the 10th (partial allowed, full passbook, printable voucher per payment); or keep "With salary" and set the Payout Date so they fold into the payslip of the month you want. Both controls are also on the bulk Calculator commit, and the optional payout-lag setting auto-dates entries N months forward. Salary\'s own date comes from Pay Day on the Salary Schedule.',
                'w' => 'Commission entry form + Calculator (Payout Method / Payout Date); Settings → Statutory Rates → payout lag'],
            ['q' => 'What decides the salary payment date?',
                'a' => 'Salary Schedules / Pay Cycle → Pay Day N pays on the Nth of the following month (a company-specific schedule beats an all-company one); with no schedule the pay date is month-end. The attendance period itself is always the calendar month.',
                'w' => 'Payroll → Pay Cycle / Salary Schedules'],
            ['q' => 'What happens if we regenerate a payroll after fixing attendance?',
                'a' => 'The draft\'s payslips are replaced and every side effect of the old draft is reversed automatically — commissions unlock and re-fold, OT paid-flags reset, loan/advance/clawback recoveries reverse and re-apply. Nothing double-deducts, nothing is lost. Approved runs cannot be regenerated — they are frozen.',
                'w' => 'Payroll → Generate Payroll (regenerating a draft month)'],
            ['q' => 'Why do Live Salary and the final payslip differ slightly?',
                'a' => 'Live Salary is the running month computed only till today — remaining working days, month-end OT, late-policy outcomes and approvals still change the total. The sandwich rule also applies only at real payroll generation, not in the live preview. Same engine, different cut-off.',
                'w' => 'Payroll → Live Salary (caption states the till-today basis)'],
            ['q' => 'How is a mid-month joiner paid?',
                'a' => 'Prorated from the date of joining: the engine counts the working days available FROM the DOJ and scales the gross accordingly (the day-value follows your chosen LOP basis). The payslip note shows the working.',
                'w' => 'The joiner\'s payslip calculation note'],
            ['q' => 'A cheque bounced AFTER the agent\'s incentive was paid — now what?',
                'a' => 'Open the commission entry and click Bounce with the reason. If money was already paid, an approved Clawback is created automatically for the paid amount and the next payslip recovers it as its own "Clawback / Reversal" line (shortfall stays visible if the net cannot cover it). If nothing was paid yet, the entry is simply rejected and can never pay.',
                'w' => 'Commissions → Commission Entries → Bounce; Clawbacks screen'],
            ['q' => 'Why was an agent skipped in the bulk incentive commit?',
                'a' => 'The commit message names every skip and the reason: no matching employee, DRA gate (certification expired/missing — in Block mode), or the monthly points threshold not met. Warn mode pays but lists the DRA warning and records it in the audit log.',
                'w' => 'Commission Calculator commit result; Settings → Statutory Rates → Money-point gates'],
            ['q' => 'Do reward points convert into money?',
                'a' => 'Never. Points are a motivation scoreboard. At most they act as an eligibility GATE for incentives (a configurable monthly minimum) — the money itself always comes from commission entries with proper TDS and approvals.',
                'w' => 'Performance → Points; Settings → Statutory Rates'],
            ['q' => 'Why can\'t I edit this commission entry?',
                'a' => 'It is LOCKED — a payslip already paid it (the lock notes which run). Locked history is sacred; corrections go through a Clawback entry with a reason, keeping both the original and the correction on record.',
                'w' => 'The entry\'s LOCKED chip and History trail'],
            ['q' => 'Where does the ₹1,800 PF figure come from?',
                'a' => '12% of Basic+DA capped at the ₹15,000 PF wage ceiling: any Basic of ₹15,000 or more gives 12% × 15,000 = ₹1,800. Below the cap it is 12% of actual Basic. The cap and rate are editable in Statutory Rates.',
                'w' => 'Statutory → PF & ESI; Settings → Statutory Rates'],
            ['q' => 'Why does the payslip say "Total days 31" for one client and "26 working days" for another?',
                'a' => 'Each tenant chooses its LOP day-value basis. Calendar basis shows "Total days 31" with offs as paid days; Working basis shows the working-day denominator. Both are lawful conventions — the setting exists precisely because clients are used to different styles.',
                'w' => 'Settings → Statutory Rates → Salary / LOP day basis'],
            ['q' => 'Weekly off between two absents — paid or LOP?',
                'a' => 'By default, paid (offs are never LOP). If you enable the optional Sandwich rule, an off-day falling BETWEEN two absent days becomes LOP too — stated on the payslip note whenever it triggers. Off by default because it is the stricter convention.',
                'w' => 'Settings → Statutory Rates → Sandwich rule'],
            ['q' => 'Is there a record of every rupee paid outside the payslip?',
                'a' => 'Yes — the Salary & Commission Ledger is the passbook: separate commission payouts (with date, mode, reference, partial payments — each with a printable Payment Voucher), payslip-paid commissions (auto-debited with the run number), and salary disbursements. Every agent\'s money story in one place.',
                'w' => 'Payroll → Salary & Commission Ledger (Voucher link on each payment row)'],
            ['q' => 'Can the numbers in a demo be verified live?',
                'a' => 'Yes — the Salary Simulator computes a what-if payslip with the REAL engine (any CTC, attendance, OT, loans) and prints it; Generate Payroll\'s preview shows every employee\'s computation before any run is created; and each payslip\'s calculation note explains itself. Nothing is a black box.',
                'w' => 'Salary Simulator buttons on Generate Payroll / Live Salary / Recruitment'],
            ['q' => 'Can we set a different PF/PT rate for one company or state, or from a specific date?',
                'a' => 'Yes. Statutory Configuration holds effective-dated, scoped overrides. Choose which rates, choose the scope (the whole tenant, a state, a company, a branch, or a location which is a branch city), set the effective-from date, and save; the narrowest scope wins and earlier months keep their own rates. You can also correct a Professional Tax state slab or add a state that is not built in. Preview shows the rupee effect on a real month before you commit. With nothing saved there, payroll is unchanged.',
                'w' => 'Indian Statutory → Statutory Configuration'],
        ];
    }
}
