<?php

namespace App\Http\Controllers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real, DB-backed master data: Departments, Branches, Banks, Designations.
 * Replaces the prototype-state versions with normalized tables. Each master
 * gets the richer fields you asked for (heads, addresses, contacts, account
 * details) via self-creating columns (project convention), so it works on the
 * deployed schema without a manual migration.
 *
 * Company › Branch › Department › Head is honoured: departments carry company +
 * branch + head; branches carry company + manager + address/contacts.
 *
 * Routes:
 *   GET  /app/master/{type}            list (type: departments|branches|banks|designations)
 *   POST /app/master/{type}            create or update (id present = update)
 *   POST /app/master/{type}/{id}/delete soft/hard delete
 *
 * All endpoints fail soft (JSON {error}, never a 500).
 */
class MasterController extends Controller
{
    /** Definition per master: table + the extra columns it should have + list shape. */
    private function def(string $type): ?array
    {
        $defs = [
            'departments' => [
                'table' => 'departments',
                'cols' => ['name' => 'string', 'company_name' => 'string', 'branch' => 'string', 'head' => 'string'],
                'fields' => ['name', 'company_name', 'branch', 'head'],
                'label' => 'Department',
            ],
            'designations' => [
                'table' => 'designations',
                'cols' => ['name' => 'string', 'department' => 'string', 'grade' => 'string'],
                'fields' => ['name', 'department', 'grade'],
                'label' => 'Designation',
            ],
            'branches' => [
                'table' => 'branches',
                'cols' => ['name' => 'string', 'company_name' => 'string', 'manager' => 'string', 'address' => 'string',
                    'city' => 'string', 'pincode' => 'string', 'phone' => 'string', 'email' => 'string'],
                'fields' => ['name', 'company_name', 'manager', 'address', 'city', 'pincode', 'phone', 'email'],
                'label' => 'Branch',
            ],
            'banks' => [
                'table' => 'banks',
                'cols' => ['name' => 'string', 'company_name' => 'string', 'acc_name' => 'string', 'acc_no' => 'string',
                    'ifsc' => 'string', 'bank_branch' => 'string', 'purpose' => 'string'],
                'fields' => ['name', 'company_name', 'acc_name', 'acc_no', 'ifsc', 'bank_branch', 'purpose'],
                'label' => 'Bank',
            ],
            // Company holiday calendar — feeds payroll LOP (a holiday is a paid
            // non-working day, so it is excluded from the LOP denominator).
            'holidays' => [
                'table' => 'holidays',
                'cols' => ['name' => 'string', 'date' => 'date', 'applies_to' => 'string'],
                'fields' => ['name', 'date', 'applies_to'],
                'label' => 'Holiday',
                'order' => 'date',
            ],
            // Leave categories with annual entitlement + paid/unpaid flag (read by
            // LeaveController balances and by payroll LOP for paid-leave credit).
            'leave-types' => [
                'table' => 'leave_types',
                'cols' => ['name' => 'string', 'days_per_year' => 'decimal', 'carry_forward' => 'bool', 'paid' => 'bool'],
                'fields' => ['name', 'days_per_year', 'carry_forward', 'paid'],
                'label' => 'Leave Type',
                'order' => 'name',
            ],
            // Biometric attendance devices registry (any make). unique_id (serial)
            // is unique + NOT NULL and company_id is NOT NULL, so both are required.
            // rev 83 (Ejaz): real-world fields — brand/model/verification type,
            // connection method (cloud push / LAN pull / offline USB), comm key.
            'biometric-devices' => [
                'table' => 'devices',
                'cols' => ['name' => 'string', 'brand' => 'string', 'model' => 'string', 'device_type' => 'string',
                    'unique_id' => 'string', 'company_name' => 'string', 'location' => 'string',
                    'connect_mode' => 'string', 'ip_address' => 'string', 'port' => 'int', 'comm_key' => 'string',
                    'direction' => 'string', 'status' => 'string', 'notes' => 'text'],
                'fields' => ['name', 'brand', 'model', 'device_type', 'unique_id', 'company_name', 'location',
                    'connect_mode', 'ip_address', 'port', 'comm_key', 'direction', 'status', 'notes'],
                'label' => 'Device',
                'order' => 'name',
                'required' => ['name', 'unique_id', 'company_name'],
            ],
            // Asset register. company_id NOT NULL; issued_to_employee_id is a real
            // FK so the picked person's name is resolved to the id via emp_map (the
            // self-created 'employee' text column keeps the display name too).
            'assets' => [
                'table' => 'assets',
                'cols' => ['asset_id' => 'string', 'asset_type' => 'string', 'company_name' => 'string',
                    'employee' => 'string', 'issue_date' => 'date', 'status' => 'string'],
                'fields' => ['asset_id', 'asset_type', 'company_name', 'employee', 'issue_date', 'status'],
                'label' => 'Asset',
                'order' => 'asset_id',
                'required' => ['asset_id', 'company_name'],
                'emp_map' => ['employee' => 'issued_to_employee_id'],
            ],
            // Complaints register (employee optional → may be anonymous).
            'complaints' => [
                'table' => 'complaints',
                'cols' => ['employee' => 'string', 'company_name' => 'string', 'severity' => 'string',
                    'channel' => 'string', 'description' => 'string', 'status' => 'string', 'date' => 'date'],
                'fields' => ['employee', 'company_name', 'severity', 'channel', 'description', 'status', 'date'],
                'label' => 'Complaint',
                'order' => 'id',
                'required' => ['company_name'],
                'emp_map' => ['employee' => 'employee_id'],
            ],
            // HR Helpdesk tickets. employee_id is NOT NULL, so an employee MUST be
            // picked (resolved by emp_map); subject + company also required.
            'helpdesk' => [
                'table' => 'helpdesk',
                'cols' => ['subject' => 'string', 'employee' => 'string', 'company_name' => 'string',
                    'category' => 'string', 'priority' => 'string', 'description' => 'text', 'status' => 'string', 'hr_comment' => 'text'],
                'fields' => ['subject', 'employee', 'company_name', 'category', 'priority', 'description', 'status', 'hr_comment'],
                'label' => 'Ticket',
                'order' => 'id',
                'required' => ['subject', 'company_name', 'employee'],
                'emp_map' => ['employee' => 'employee_id'],
            ],
            // rev184 (Ejaz) — POSH complaints: a separate CONFIDENTIAL register for
            // the Internal Committee. Employees raise their own (create-only, forced
            // to self by save()); list() shows a non-manager only their own rows.
            // employee_id sits in cols so the self-created table gets the column.
            'posh' => [
                'table' => 'posh_complaints',
                'cols' => ['subject' => 'string', 'employee' => 'string', 'employee_id' => 'int', 'company_name' => 'string',
                    'respondent' => 'string', 'incident_date' => 'date', 'description' => 'text', 'status' => 'string', 'hr_comment' => 'text'],
                'fields' => ['subject', 'employee', 'company_name', 'respondent', 'incident_date', 'description', 'status', 'hr_comment'],
                'label' => 'POSH Complaint',
                'order' => 'id',
                'required' => ['subject', 'company_name', 'employee'],
                'emp_map' => ['employee' => 'employee_id'],
            ],
            // Deductions ledger (ad-hoc deductions). employee_id NOT NULL.
            'deductions' => [
                'table' => 'deductions',
                'cols' => ['employee' => 'string', 'type' => 'string', 'amount' => 'decimal',
                    'month' => 'string', 'source_ref' => 'string', 'status' => 'string'],
                'fields' => ['employee', 'type', 'amount', 'month', 'source_ref', 'status'],
                'label' => 'Deduction',
                'order' => 'id',
                'required' => ['employee'],
                'emp_map' => ['employee' => 'employee_id'],
            ],
            // Payout reconciliation (bank payout vs expected). company_id NOT NULL.
            'payout-recon' => [
                'table' => 'payout_recon',
                'cols' => ['company_name' => 'string', 'bank' => 'string', 'portfolio' => 'string', 'month' => 'string',
                    'expected' => 'decimal', 'received' => 'decimal', 'variance' => 'decimal', 'status' => 'string'],
                'fields' => ['company_name', 'bank', 'portfolio', 'month', 'expected', 'received', 'variance', 'status'],
                'label' => 'Reconciliation',
                'order' => 'id',
                'required' => ['company_name'],
            ],
            // Salary schedules (pay cycles). company_id NOT NULL.
            'salary-schedules' => [
                'table' => 'salary_schedules',
                'cols' => ['name' => 'string', 'company_name' => 'string', 'pay_cycle' => 'string',
                    'pay_day' => 'string', 'applicable_to' => 'string', 'status' => 'string'],
                'fields' => ['name', 'company_name', 'pay_cycle', 'pay_day', 'applicable_to', 'status'],
                'label' => 'Schedule',
                'order' => 'name',
                'required' => ['name', 'company_name'],
            ],
            // TDS returns (quarterly filing tracker). company_id NOT NULL.
            'tds-returns' => [
                'table' => 'tds_returns',
                'cols' => ['company_name' => 'string', 'quarter' => 'string', 'deductees' => 'int',
                    'tax_deducted' => 'decimal', 'deposited' => 'decimal', 'due_date' => 'date', 'status' => 'string'],
                'fields' => ['company_name', 'quarter', 'deductees', 'tax_deducted', 'deposited', 'due_date', 'status'],
                'label' => 'TDS Return',
                'order' => 'id',
                'required' => ['company_name', 'quarter'],
            ],

            // ---- Batch A (rev 45): remaining HR/ops master screens -------------
            'teams' => [
                'table' => 'teams',
                'cols' => ['name' => 'string', 'function' => 'string', 'company_name' => 'string', 'manager' => 'string', 'leader' => 'string', 'status' => 'string'],
                'fields' => ['name', 'function', 'company_name', 'manager', 'leader', 'status'],
                'label' => 'Team', 'order' => 'name', 'required' => ['name', 'company_name'],
                'emp_map' => ['manager' => 'manager_id', 'leader' => 'leader_id'],
            ],
            'bgv' => [
                'table' => 'bgv',
                'cols' => ['employee' => 'string', 'company_name' => 'string', 'agency' => 'string', 'checks' => 'string',
                    'chk_identity' => 'string', 'chk_address' => 'string', 'chk_education' => 'string', 'chk_employment' => 'string', 'chk_criminal' => 'string', 'chk_references' => 'string',
                    'status' => 'string', 'completed_on' => 'date', 'verified_on' => 'date', 'revalidate_months' => 'int', 'next_due' => 'date'],
                'fields' => ['employee', 'company_name', 'agency',
                    'chk_identity', 'chk_address', 'chk_education', 'chk_employment', 'chk_criminal', 'chk_references',
                    'status', 'verified_on', 'revalidate_months', 'next_due'],
                'label' => 'BGV Case', 'order' => 'id', 'required' => ['employee', 'company_name'],
                'emp_map' => ['employee' => 'employee_id'],
            ],
            'documents' => [
                'table' => 'documents',
                'cols' => ['employee' => 'string', 'kind' => 'string', 'status' => 'string', 'expiry' => 'date'],
                'fields' => ['employee', 'kind', 'status', 'expiry'],
                'label' => 'Document', 'order' => 'id', 'required' => ['employee'],
                'emp_map' => ['employee' => 'employee_id'],
            ],
            'offroll-agents' => [
                'table' => 'offroll_agents',
                'cols' => ['name' => 'string', 'company_name' => 'string', 'vendor' => 'string', 'mobile' => 'string', 'payout_type' => 'string', 'rate' => 'decimal', 'dra' => 'string', 'pcc' => 'string', 'status' => 'string'],
                'fields' => ['name', 'company_name', 'vendor', 'mobile', 'payout_type', 'rate', 'dra', 'pcc', 'status'],
                'label' => 'Off-roll Agent', 'order' => 'name', 'required' => ['name', 'company_name'],
            ],
            'geofence' => [
                'table' => 'geofence_rules',
                'cols' => ['employee' => 'string', 'start' => 'string', 'lat' => 'coord', 'lng' => 'coord', 'radius_km' => 'decimal', 'outside' => 'string'],
                'fields' => ['employee', 'start', 'lat', 'lng', 'radius_km', 'outside'],
                'label' => 'Geofence Rule', 'order' => 'id', 'required' => ['employee'],
                'emp_map' => ['employee' => 'employee_id'],
            ],
            // rev173 — Working Shifts master: named shift timings (General / Morning /
            // Night…). Timings feed Attendance Report + Payroll via ShiftResolver
            // (roster entry > employee default > Late Policy > 09:30-18:30).
            // end_time earlier than start_time = night shift (crosses midnight);
            // night_allowance = Rs paid per night actually worked (payroll earning).
            // grace_min / full/half-day hours / break_budget OVERRIDE the Late
            // Policy values only when set — leave blank to keep policy values.
            'shifts' => [
                'table' => 'shifts',
                'cols' => ['name' => 'string', 'code' => 'string', 'company_name' => 'string', 'start_time' => 'string', 'end_time' => 'string', 'grace_min' => 'int', 'full_day_hours' => 'decimal', 'half_day_hours' => 'decimal', 'break_budget' => 'int', 'night_allowance' => 'decimal', 'status' => 'string'],
                'fields' => ['name', 'code', 'company_name', 'start_time', 'end_time', 'grace_min', 'full_day_hours', 'half_day_hours', 'break_budget', 'night_allowance', 'status'],
                'label' => 'Shift', 'order' => 'name', 'required' => ['name', 'start_time', 'end_time'],
            ],
            'late-policy' => [
                'table' => 'late_policy',
                'cols' => ['company_name' => 'string', 'scope' => 'string', 'scope_target' => 'string', 'mode' => 'string', 'shift_start' => 'string', 'shift_end' => 'string', 'grace_min' => 'int', 'full_day_hours' => 'decimal', 'half_day_hours' => 'decimal', 'lates_before_cut' => 'int', 'cut_mode' => 'string', 'cut_n' => 'int', 'l1_min' => 'int', 'l1_cut' => 'decimal', 'l2_min' => 'int', 'l2_cut' => 'decimal', 'l3_min' => 'int', 'l3_cut' => 'decimal', 'break_budget' => 'int', 'break_cut' => 'string', 'addl_late' => 'string', 'weekoff_action' => 'string', 'no_cut_on_weekoff' => 'bool'],
                'fields' => ['company_name', 'scope', 'scope_target', 'mode', 'shift_start', 'shift_end', 'grace_min', 'full_day_hours', 'half_day_hours', 'lates_before_cut', 'cut_mode', 'cut_n', 'l1_min', 'l1_cut', 'l2_min', 'l2_cut', 'l3_min', 'l3_cut', 'break_budget', 'break_cut', 'addl_late', 'weekoff_action', 'no_cut_on_weekoff'],
                'label' => 'Late Policy', 'order' => 'id', 'required' => ['company_name'],
            ],
            'salary-setup' => [
                'table' => 'salary_components',
                'cols' => ['company_name' => 'string', 'scope' => 'string', 'scope_target' => 'string', 'code' => 'string', 'name' => 'string', 'ctype' => 'string', 'category' => 'string', 'base' => 'string', 'calc_value' => 'decimal', 'seq' => 'int', 'calc_type' => 'string', 'taxable' => 'bool'],
                'fields' => ['company_name', 'scope', 'scope_target', 'code', 'name', 'ctype', 'category', 'base', 'calc_value', 'seq', 'taxable'],
                'label' => 'Salary Component', 'order' => 'seq', 'required' => ['code', 'name'],
            ],
            'incentive-schemes' => [
                'table' => 'incentive_schemes',
                'cols' => ['name' => 'string', 'portfolio' => 'string', 'basis' => 'string', 'clawback' => 'bool', 'status' => 'string'],
                'fields' => ['name', 'portfolio', 'basis', 'clawback', 'status'],
                'label' => 'Incentive Scheme', 'order' => 'name', 'required' => ['name'],
            ],
            'points-ledger' => [
                'table' => 'points_ledger',
                'cols' => ['employee' => 'string', 'company_name' => 'string', 'event' => 'string', 'category' => 'string', 'points' => 'int', 'date' => 'date', 'note' => 'string', 'source_ref' => 'string'],
                'fields' => ['employee', 'company_name', 'event', 'category', 'points', 'date', 'note', 'source_ref'],
                'label' => 'Points Entry', 'order' => 'id', 'required' => ['employee'],
                'emp_map' => ['employee' => 'employee_id'],
            ],
            'points-rules' => [
                'table' => 'point_rules',
                'cols' => ['event' => 'string', 'company_name' => 'string', 'category' => 'string', 'points' => 'int', 'note' => 'string'],
                'fields' => ['event', 'company_name', 'category', 'points', 'note'],
                'label' => 'Points Rule', 'order' => 'id', 'required' => ['event'],
            ],
            'tests' => [
                'table' => 'tests',
                'cols' => ['name' => 'string', 'category' => 'string', 'target_type' => 'string', 'target' => 'string', 'questions' => 'int', 'pass_mark' => 'decimal', 'scheduled_on' => 'date'],
                'fields' => ['name', 'category', 'target_type', 'target', 'questions', 'pass_mark', 'scheduled_on'],
                'label' => 'Test', 'order' => 'name', 'required' => ['name'],
            ],
            'training-programs' => [
                'table' => 'training_programs',
                'cols' => ['name' => 'string', 'category' => 'string', 'mode' => 'string', 'mandatory' => 'bool', 'validity' => 'string', 'description' => 'text', 'status' => 'string'],
                'fields' => ['name', 'category', 'mode', 'mandatory', 'validity', 'description', 'status'],
                'label' => 'Training Program', 'order' => 'name', 'required' => ['name'],
            ],
            'training-records' => [
                'table' => 'training_records',
                'cols' => ['employee' => 'string', 'program' => 'string', 'status' => 'string', 'completed_on' => 'date', 'score' => 'decimal', 'expiry' => 'date'],
                'fields' => ['employee', 'program', 'status', 'completed_on', 'score', 'expiry'],
                'label' => 'Training Record', 'order' => 'id', 'required' => ['employee', 'program'],
                'emp_map' => ['employee' => 'employee_id'],
                'ref_map' => ['program' => ['col' => 'program_id', 'table' => 'training_programs', 'match' => 'name']],
            ],
            'code-of-conduct' => [
                'table' => 'code_of_conduct_ack',
                'cols' => ['employee' => 'string', 'acknowledged' => 'bool', 'ack_date' => 'date'],
                'fields' => ['employee', 'acknowledged', 'ack_date'],
                'label' => 'CoC Acknowledgement', 'order' => 'id', 'required' => ['employee'],
                'emp_map' => ['employee' => 'employee_id'],
            ],
            'faqs' => [
                'table' => 'faqs',
                'cols' => ['category' => 'string', 'question' => 'string', 'answer' => 'string'],
                'fields' => ['category', 'question', 'answer'],
                'label' => 'FAQ', 'order' => 'id', 'required' => ['question'],
            ],
            'escalations' => [
                'table' => 'escalations',
                'cols' => ['date' => 'date', 'company_name' => 'string', 'bank' => 'string', 'team' => 'string', 'issue' => 'string', 'severity' => 'string', 'priority' => 'string', 'status' => 'string', 'action_taken' => 'string'],
                'fields' => ['date', 'company_name', 'bank', 'team', 'issue', 'severity', 'priority', 'status', 'action_taken'],
                'label' => 'Escalation', 'order' => 'id', 'required' => ['issue'],
            ],
            'agent-auth' => [
                'table' => 'agent_authorizations',
                'cols' => ['employee' => 'string', 'company_name' => 'string', 'bank' => 'string', 'portfolio' => 'string', 'auth_no' => 'string', 'valid_to' => 'date', 'status' => 'string'],
                'fields' => ['employee', 'company_name', 'bank', 'portfolio', 'auth_no', 'valid_to', 'status'],
                'label' => 'Agent Authorization', 'order' => 'id', 'required' => ['employee', 'company_name'],
                'emp_map' => ['employee' => 'employee_id'],
            ],
            'dra-certs' => [
                'table' => 'dra_certs',
                'cols' => ['employee' => 'string', 'company_name' => 'string', 'cert_no' => 'string', 'institute' => 'string', 'track' => 'string', 'issue_date' => 'date', 'expiry' => 'date', 'status' => 'string'],
                'fields' => ['employee', 'company_name', 'cert_no', 'institute', 'track', 'issue_date', 'expiry', 'status'],
                'label' => 'DRA Certification', 'order' => 'id', 'required' => ['employee'],
                'emp_map' => ['employee' => 'employee_id'],
            ],
            'messages' => [
                'table' => 'messages_log',
                'cols' => ['target' => 'string', 'company_name' => 'string', 'channels' => 'string', 'message' => 'string', 'recipients' => 'int', 'sent_at' => 'date'],
                'fields' => ['target', 'company_name', 'channels', 'message', 'recipients', 'sent_at'],
                'label' => 'Message', 'order' => 'id', 'required' => ['message'],
            ],
            'min-wages' => [
                'table' => 'min_wages',
                'cols' => ['state' => 'string', 'zone' => 'string', 'category' => 'string', 'monthly_min' => 'decimal', 'effective_from' => 'date', 'status' => 'string'],
                'fields' => ['state', 'zone', 'category', 'monthly_min', 'effective_from', 'status'],
                'label' => 'Minimum Wage', 'order' => 'id', 'required' => ['monthly_min'],
            ],
            'policies' => [
                'table' => 'policies',
                'cols' => ['title' => 'string', 'category' => 'string', 'version' => 'string', 'owner' => 'string', 'effective_date' => 'date', 'board_approved_on' => 'date', 'review_due' => 'date', 'ack_required' => 'bool', 'reference' => 'string', 'summary' => 'text', 'status' => 'string'],
                'fields' => ['title', 'category', 'version', 'owner', 'effective_date', 'board_approved_on', 'review_due', 'ack_required', 'reference', 'summary', 'status'],
                'label' => 'Policy', 'order' => 'id', 'required' => ['title', 'version'],
            ],
            'overtime' => [
                'table' => 'overtime',
                'cols' => ['employee' => 'string', 'company_name' => 'string', 'ot_date' => 'date', 'hours' => 'decimal', 'multiplier' => 'string', 'amount' => 'decimal', 'status' => 'string'],
                'fields' => ['employee', 'company_name', 'ot_date', 'hours', 'multiplier', 'amount', 'status'],
                'label' => 'Overtime Entry', 'order' => 'id', 'required' => ['employee', 'hours'],
                'emp_map' => ['employee' => 'employee_id'],
            ],
            'companies' => [
                'table' => 'companies',
                'cols' => ['name' => 'string', 'is_master' => 'bool', 'type' => 'string', 'pan' => 'string', 'gstin' => 'string', 'address' => 'string', 'phone' => 'string', 'email' => 'string', 'website' => 'string', 'grievance_officer' => 'string', 'grievance_phone' => 'string', 'grievance_email' => 'string', 'status' => 'string'],
                'fields' => ['name', 'is_master', 'type', 'pan', 'gstin', 'address', 'phone', 'email', 'website', 'grievance_officer', 'grievance_phone', 'grievance_email', 'status'],
                'label' => 'Company', 'order' => 'name', 'required' => ['name'],
            ],
            // Letters — one `letters` table. Templates (is_template=1) hold a
            // reusable body with {{placeholders}}; issued letters (is_template=0)
            // are per-employee and generate a merged PDF (LetterController::pdf).
            // An OFFER letter is to a CANDIDATE (from Recruitment), not an employee.
            'letters-offer' => [
                'table' => 'letters', 'cols' => ['candidate' => 'string', 'template' => 'string', 'template_id' => 'int', 'company_name' => 'string', 'issued_on' => 'date', 'status' => 'string', 'is_template' => 'bool'],
                'fields' => ['candidate', 'template', 'company_name', 'issued_on', 'status'], 'label' => 'Offer Letter', 'order' => 'id',
                'required' => ['candidate', 'company_name'], 'templateType' => 'offer', 'candidateSource' => true,
                'ref_map' => ['template' => ['col' => 'template_id', 'table' => 'letters', 'match' => 'title', 'where' => ['is_template' => 1, 'letter_type' => 'offer']]],
                'fixed' => ['letter_type' => 'offer', 'is_template' => 0], 'defaults' => ['employee_id' => 0],
            ],
            'letters-increment' => [
                'table' => 'letters', 'cols' => ['employee' => 'string', 'template' => 'string', 'template_id' => 'int', 'company_name' => 'string', 'issued_on' => 'date', 'status' => 'string', 'is_template' => 'bool'],
                'fields' => ['employee', 'template', 'company_name', 'issued_on', 'status'], 'label' => 'Increment Letter', 'order' => 'id',
                'required' => ['employee', 'company_name'], 'emp_map' => ['employee' => 'employee_id'], 'templateType' => 'increment',
                'ref_map' => ['template' => ['col' => 'template_id', 'table' => 'letters', 'match' => 'title', 'where' => ['is_template' => 1, 'letter_type' => 'increment']]],
                'fixed' => ['letter_type' => 'increment', 'is_template' => 0],
            ],
            'letters-warning' => [
                'table' => 'letters', 'cols' => ['employee' => 'string', 'template' => 'string', 'template_id' => 'int', 'company_name' => 'string', 'issued_on' => 'date', 'status' => 'string', 'is_template' => 'bool'],
                'fields' => ['employee', 'template', 'company_name', 'issued_on', 'status'], 'label' => 'Warning Letter', 'order' => 'id',
                'required' => ['employee', 'company_name'], 'emp_map' => ['employee' => 'employee_id'], 'templateType' => 'warning',
                'ref_map' => ['template' => ['col' => 'template_id', 'table' => 'letters', 'match' => 'title', 'where' => ['is_template' => 1, 'letter_type' => 'warning']]],
                'fixed' => ['letter_type' => 'warning', 'is_template' => 0],
            ],
            'letters-relieving' => [
                'table' => 'letters', 'cols' => ['employee' => 'string', 'template' => 'string', 'template_id' => 'int', 'company_name' => 'string', 'issued_on' => 'date', 'status' => 'string', 'is_template' => 'bool'],
                'fields' => ['employee', 'template', 'company_name', 'issued_on', 'status'], 'label' => 'Relieving Letter', 'order' => 'id',
                'required' => ['employee', 'company_name'], 'emp_map' => ['employee' => 'employee_id'], 'templateType' => 'relieving',
                'ref_map' => ['template' => ['col' => 'template_id', 'table' => 'letters', 'match' => 'title', 'where' => ['is_template' => 1, 'letter_type' => 'relieving']]],
                'fixed' => ['letter_type' => 'relieving', 'is_template' => 0],
            ],
            'letters-templates' => [
                'table' => 'letters', 'cols' => ['title' => 'string', 'letter_type' => 'string', 'body' => 'text', 'status' => 'string', 'is_template' => 'bool'],
                'fields' => ['title', 'letter_type', 'body', 'status'], 'label' => 'Letter Template', 'order' => 'id',
                'required' => ['title', 'letter_type'], 'fixed' => ['is_template' => 1], 'defaults' => ['employee_id' => 0],
            ],

            // ---- Batch B + remaining (rev 46): self-creating tables / config -----
            'recruitment' => [
                'table' => 'recruitment',
                'cols' => ['name' => 'string', 'company_name' => 'string', 'position' => 'string', 'source' => 'string', 'stage' => 'string', 'mobile' => 'string', 'email' => 'string', 'notes' => 'string'],
                'fields' => ['name', 'company_name', 'position', 'source', 'stage', 'mobile', 'email', 'notes'],
                'label' => 'Candidate', 'order' => 'id', 'required' => ['name'],
            ],
            'roster' => [
                'table' => 'roster',
                'cols' => ['employee' => 'string', 'company_name' => 'string', 'team' => 'string', 'date' => 'date', 'shift' => 'string', 'status' => 'string'],
                'fields' => ['employee', 'company_name', 'team', 'date', 'shift', 'status'],
                'label' => 'Roster Entry', 'order' => 'id', 'required' => ['employee'],
            ],
            'onboarding-board' => [
                'table' => 'onboarding',
                'cols' => ['employee' => 'string', 'company_name' => 'string', 'stage' => 'string', 'joined_on' => 'date', 'status' => 'string'],
                'fields' => ['employee', 'company_name', 'stage', 'joined_on', 'status'],
                'label' => 'Onboarding', 'order' => 'id', 'required' => ['employee'],
            ],
            'awards' => [
                'table' => 'awards',
                'cols' => ['employee' => 'string', 'company_name' => 'string', 'award' => 'string', 'date' => 'date', 'note' => 'string'],
                'fields' => ['employee', 'company_name', 'award', 'date', 'note'],
                'label' => 'Award', 'order' => 'id', 'required' => ['employee', 'award'],
            ],
            'performance' => [
                'table' => 'performance_reviews',
                'cols' => ['employee' => 'string', 'company_name' => 'string', 'cycle' => 'string', 'rating' => 'decimal', 'reviewer' => 'string', 'status' => 'string'],
                'fields' => ['employee', 'company_name', 'cycle', 'rating', 'reviewer', 'status'],
                'label' => 'Performance Review', 'order' => 'id', 'required' => ['employee'],
            ],
            'notice-board' => [
                'table' => 'notices',
                'cols' => ['title' => 'string', 'body' => 'text', 'company_name' => 'string', 'posted_on' => 'date', 'status' => 'string'],
                'fields' => ['title', 'body', 'company_name', 'posted_on', 'status'],
                'label' => 'Notice', 'order' => 'id', 'required' => ['title'],
            ],
            'feature-flags' => [
                'table' => 'feature_flags',
                'cols' => ['name' => 'string', 'value' => 'string', 'note' => 'string'],
                'fields' => ['name', 'value', 'note'],
                'label' => 'Feature Flag', 'order' => 'name', 'required' => ['name'],
            ],
            'training-content' => [
                'table' => 'training_subjects',
                'cols' => ['program' => 'string', 'module' => 'string', 'subject' => 'string', 'hours' => 'decimal', 'content' => 'text'],
                'fields' => ['program', 'module', 'subject', 'hours', 'content'],
                'label' => 'Training Content', 'order' => 'id', 'required' => ['subject'],
                'ref_map' => ['program' => ['col' => 'program_id', 'table' => 'training_programs', 'match' => 'name']],
            ],
            'test-results' => [
                'table' => 'test_attempts',
                'cols' => ['employee' => 'string', 'test' => 'string', 'status' => 'string', 'score' => 'decimal', 'attempted_on' => 'date'],
                'fields' => ['employee', 'test', 'status', 'score', 'attempted_on'],
                'label' => 'Test Result', 'order' => 'id', 'required' => ['employee', 'test'],
                'emp_map' => ['employee' => 'employee_id'],
                'ref_map' => ['test' => ['col' => 'test_id', 'table' => 'tests', 'match' => 'name']],
            ],
            'pay-cycle' => [
                'table' => 'pay_cycles',
                'cols' => ['name' => 'string', 'company_name' => 'string', 'cycle' => 'string', 'cutoff_day' => 'int', 'pay_day' => 'int', 'status' => 'string'],
                'fields' => ['name', 'company_name', 'cycle', 'cutoff_day', 'pay_day', 'status'],
                'label' => 'Pay Cycle', 'order' => 'name', 'required' => ['name'],
            ],
            'wa-settings' => [
                'table' => 'wa_settings',
                'cols' => ['company_name' => 'string', 'provider' => 'string', 'api_url' => 'string', 'api_key' => 'string', 'sender_number' => 'string', 'waba_id' => 'string', 'status' => 'string'],
                'fields' => ['company_name', 'provider', 'api_url', 'api_key', 'sender_number', 'waba_id', 'status'],
                'label' => 'WhatsApp Setting', 'order' => 'id', 'required' => ['provider'],
            ],
            'sms-settings' => [
                'table' => 'sms_settings',
                'cols' => ['company_name' => 'string', 'provider' => 'string', 'api_url' => 'string', 'api_key' => 'string', 'sender_id' => 'string', 'dlt_entity_id' => 'string', 'status' => 'string'],
                'fields' => ['company_name', 'provider', 'api_url', 'api_key', 'sender_id', 'dlt_entity_id', 'status'],
                'label' => 'SMS Setting', 'order' => 'id', 'required' => ['provider'],
            ],
            'sms-templates' => [
                'table' => 'sms_templates',
                'cols' => ['company_name' => 'string', 'name' => 'string', 'type' => 'string', 'dlt_template_id' => 'string', 'content' => 'text', 'status' => 'string'],
                'fields' => ['company_name', 'name', 'type', 'dlt_template_id', 'content', 'status'],
                'label' => 'SMS Template', 'order' => 'name', 'required' => ['name'],
            ],
            // Manual attendance punch — writes a real row into attendance_logs.
            'att-manual' => [
                'table' => 'attendance_logs',
                'cols' => ['emp_code' => 'string', 'emp_name' => 'string', 'log_date' => 'date', 'punch_at' => 'datetime', 'direction' => 'string', 'source' => 'string'],
                // rev 81b: employee is PICKED (searchable list) — emp_code is
                // resolved server-side in buildRow, never typed by the user.
                'fields' => ['emp_name', 'log_date', 'punch_at', 'direction'],
                'label' => 'Manual Punch', 'order' => 'id', 'required' => ['emp_name', 'punch_at'],
                'defaults' => ['source' => 'manual'],
            ],
        ];

        // Alias nav-screen keys that differ from their canonical def key, so a POST that
        // carries the nav key (e.g. geofence-list) still resolves server-side.
        $aliases = [
            'geofence-list' => 'geofence',
            'att-zkteco' => 'biometric-devices',
        ];
        $type = $aliases[$type] ?? $type;

        return $defs[$type] ?? null;
    }

    /** Add the rich columns to the master table if missing (idempotent). */
    private function ensureCols(array $def): void
    {
        $table = $def['table'];
        // Self-create the whole table when it doesn't exist yet (lets new
        // screens work without a migration; columns are added below).
        if (! Schema::hasTable($table)) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->timestamps();
            });
        }
        $missing = [];
        foreach ($def['cols'] as $c => $t) {
            if (! Schema::hasColumn($table, $c)) {
                $missing[$c] = $t;
            }
        }
        // tenant/company/timestamps are assumed present from the base migration.
        if (! Schema::hasColumn($table, 'company_id') && ! in_array('company_id', array_keys($def['cols']), true)) {
            // some masters (banks/designations) may lack company_id — add nullable.
            $missing['company_id'] = 'company';
        }
        // rev 92b (Ejaz live test): PLATFORM rows (super admin) carry NO company —
        // but the original wa_settings migration made company_id NOT NULL, so the
        // insert blew up with "Column 'company_id' cannot be null". Relax the
        // legacy column once (same fresh-install-minefield family as the enum
        // widenings in ApprovalService). MySQL only; idempotent via SHOW COLUMNS.
        if ($table === 'wa_settings' && DB::getDriverName() === 'mysql') {
            try {
                $col = DB::selectOne("SHOW COLUMNS FROM `wa_settings` LIKE 'company_id'");
                if ($col && strtoupper((string) ($col->Null ?? '')) === 'NO') {
                    DB::statement('ALTER TABLE `wa_settings` MODIFY `company_id` BIGINT UNSIGNED NULL');
                }
            } catch (\Throwable $e) {
                // non-fatal — tenant-admin saves (with a company) still work
            }
        }
        // Repair coord columns (lat/lng) that an earlier auto-create made decimal(12,2),
        // which would truncate coordinates to ~1km. Idempotent; MySQL only (sqlite tests skip).
        if (DB::getDriverName() === 'mysql') {
            foreach ($def['cols'] as $c => $t) {
                if ($t !== 'coord' || ! Schema::hasColumn($table, $c)) {
                    continue;
                }
                try {
                    $meta = DB::selectOne(
                        'SELECT NUMERIC_SCALE s FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                        [$table, $c]
                    );
                    if ($meta && (int) $meta->s !== 7) {
                        DB::statement("ALTER TABLE `{$table}` MODIFY `{$c}` DECIMAL(11,7) NULL");
                    }
                } catch (\Throwable $e) { /* leave as-is on any failure */ }
            }
        }
        if (! $missing) {
            return;
        }
        Schema::table($table, function (Blueprint $t) use ($missing) {
            foreach ($missing as $c => $type) {
                if ($type === 'company') {
                    $t->unsignedBigInteger('company_id')->nullable();
                } elseif ($type === 'date') {
                    $t->date($c)->nullable();
                } elseif ($type === 'coord') {
                    $t->decimal($c, 11, 7)->nullable(); // lat/lng — ~1cm precision
                } elseif ($type === 'decimal') {
                    $t->decimal($c, 12, 2)->nullable();
                } elseif ($type === 'int') {
                    $t->integer($c)->nullable();
                } elseif ($type === 'bool') {
                    $t->boolean($c)->default(0);
                } elseif ($type === 'text') {
                    $t->longText($c)->nullable();
                } elseif ($type === 'datetime') {
                    $t->dateTime($c)->nullable();
                } else {
                    $t->string($c)->nullable();
                }
            }
        });
    }

    private function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['super_admin', 'admin', 'hr_manager']);
    }

    /** rev184 — masters a NON-manager may raise for themselves: helpdesk tickets + POSH complaints. */
    private function selfServe(string $type): bool
    {
        return in_array($type, ['helpdesk', 'posh'], true);
    }

    /** rev184 — the logged-in user's own employee row (works for employee AND field-agent logins). */
    private function selfEmployee(Request $request): ?object
    {
        $user = $request->user();
        if (! empty($user->employee_id)) {
            $e = DB::table('employees')->where('id', $user->employee_id)->whereNull('deleted_at')->first();
            if ($e) {
                return $e;
            }
        }

        return DB::table('employees')->whereNull('deleted_at')
            ->when($user->tenant_id, fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->where(fn ($q) => $q->where('email', $user->email)->orWhere('name', $user->name))
            ->first();
    }

    public function list(Request $request, string $type)
    {
        try {
            $def = $this->def($type);
            if (! $def) {
                return response()->json(['rows' => [], 'error' => 'Unknown master']);
            }
            $this->ensureCols($def);
            $table = $def['table'];
            $tid = $request->user()->tenant_id;

            // Master/Subsidiary (rev 77): the signed-up (oldest) company is the
            // MASTER; additional companies are subsidiaries. Backfill the flag
            // for tenants created before the column existed.
            if ($type === 'companies' && $tid && Schema::hasColumn('companies', 'is_master')) {
                try {
                    $has = DB::table('companies')->where('tenant_id', $tid)->whereNull('deleted_at')->where('is_master', 1)->exists();
                    if (! $has) {
                        $oldest = DB::table('companies')->where('tenant_id', $tid)->whereNull('deleted_at')->orderBy('id')->value('id');
                        if ($oldest) {
                            DB::table('companies')->where('id', $oldest)->update(['is_master' => 1, 'updated_at' => now()]);
                        }
                    }
                } catch (\Throwable $e) {
                }
            }

            $q = DB::table($table)->when($tid && Schema::hasColumn($table, 'tenant_id'), fn ($x) => $x->where('tenant_id', $tid));
            if (Schema::hasColumn($table, 'deleted_at')) {
                $q->whereNull('deleted_at');
            }
            foreach ($def['fixed'] ?? [] as $fk => $fv) {
                $q->where($fk, $fv);   // screen pins a constant column (e.g. letter_type)
            }
            // rev184 — CONFIDENTIALITY on self-serve masters (helpdesk / POSH):
            // a non-manager sees ONLY the rows they raised themselves.
            $canManage = $this->canManage($request);
            $selfEmp = null;
            if ($this->selfServe($type) && ! $canManage) {
                $selfEmp = $this->selfEmployee($request);
                $q->where('employee_id', (int) ($selfEmp->id ?? -1));
            }
            $rows = $q->orderBy($def['order'] ?? 'name')->get();

            // Company name lookup for display (where a company_id exists).
            $companies = DB::table('companies')->pluck('name', 'id');
            $out = $rows->map(function ($r) use ($def, $companies) {
                $a = (array) $r;
                $item = ['id' => $a['id']];
                foreach ($def['fields'] as $f) {
                    $item[$f] = $a[$f] ?? '';
                }
                // Resolve company_name from FK if the saved text is blank.
                if (in_array('company_name', $def['fields'], true) && empty($item['company_name']) && ! empty($a['company_id'])) {
                    $item['company_name'] = $companies[$a['company_id']] ?? '';
                }

                return $item;
            })->values();

            // Templates available for a letter screen's picker (titles).
            $templates = [];
            if (! empty($def['templateType']) && Schema::hasTable('letters') && Schema::hasColumn('letters', 'is_template')) {
                $templates = DB::table('letters')
                    ->when($tid && Schema::hasColumn('letters', 'tenant_id'), fn ($x) => $x->where('tenant_id', $tid))
                    ->where('is_template', 1)->where('letter_type', $def['templateType'])
                    ->orderByDesc('id')->pluck('title')->filter()->values();
            }
            // Recruitment candidates (for the Offer Letter picker).
            $candidates = [];
            if (! empty($def['candidateSource']) && Schema::hasTable('recruitment')) {
                $candidates = DB::table('recruitment')
                    ->when($tid && Schema::hasColumn('recruitment', 'tenant_id'), fn ($x) => $x->where('tenant_id', $tid))
                    ->orderBy('name')->pluck('name')->filter()->values();
            }

            return response()->json([
                'rows' => $out,
                'fields' => $def['fields'],
                'label' => $def['label'],
                'canManage' => $canManage,
                'canCreate' => $canManage || ($this->selfServe($type) && $selfEmp !== null),
                'templates' => $templates,
                'candidates' => $candidates,
                'companies' => DB::table('companies')->when($tid, fn ($x) => $x->where('tenant_id', $tid))->whereNull('deleted_at')->orderBy('name')->pluck('name')->values(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['rows' => [], 'error' => $e->getMessage()]);
        }
    }

    public function save(Request $request, string $type)
    {
        try {
            // rev184 — self-serve CREATE: an employee/agent may raise their own
            // helpdesk ticket or POSH complaint (never edit, never someone else's).
            $selfServeEmp = null;
            if (! $this->canManage($request)) {
                abort_unless($this->selfServe($type) && empty($request->input('item.id')), 403);
                $selfServeEmp = $this->selfEmployee($request);
                abort_unless($selfServeEmp !== null, 403);
            }
            $def = $this->def($type);
            if (! $def) {
                return response()->json(['ok' => false, 'error' => 'Unknown master'], 422);
            }
            $this->ensureCols($def);
            $table = $def['table'];
            $tid = $request->user()->tenant_id ?? DB::table('tenants')->value('id');

            $input = (array) $request->input('item', []);
            $input = AppDataController::stripHtmlDeep($input); // rev172 (H3) — strip HTML from all master free-text (complaints, helpdesk, titles…) against stored XSS
            foreach ($def['required'] ?? ['name'] as $rf) {
                if (empty($input[$rf])) {
                    return response()->json(['ok' => false, 'error' => ucfirst(str_replace('_', ' ', $rf)).' is required'], 422);
                }
            }

            // Build the row from the allowed fields only (schema-safe), coercing
            // each value to its declared column type (bool/decimal/int/date).
            $row = $this->buildRow($def, $table, $tid, $input);

            // rev184 — self-serve rows are ALWAYS the raiser's own, and start open.
            // rev184b — Priority / Status / HR-comment are Admin-HR's to set AFTER
            // the ticket is raised; whatever a non-manager posted is discarded.
            if ($selfServeEmp !== null) {
                $row['employee'] = $selfServeEmp->name;
                $row['employee_id'] = (int) $selfServeEmp->id;
                $row['status'] = 'open';
                unset($row['priority'], $row['hr_comment']);
            }

            // C3 — BGV re-verification scheduler: derive the next-due date from the
            // verification date + the configured periodicity (months).
            if ($type === 'bgv' && ! empty($row['verified_on']) && ! empty($row['revalidate_months'])) {
                try {
                    $row['next_due'] = \Illuminate\Support\Carbon::parse($row['verified_on'])
                        ->addMonths((int) $row['revalidate_months'])->toDateString();
                } catch (\Throwable $e) {
                    // leave any manually-entered next_due as-is on a parse error
                }
            }

            // E3 — Overtime register: auto-compute the OT amount (hours × multiplier ×
            // hourly rate) when no amount was entered. Hourly rate = monthly gross
            // (CTC / 12) / 26 days / 8 hours.
            if ($type === 'overtime' && ! empty($row['employee_id']) && ! empty($row['hours']) && empty($row['amount'])) {
                $ctc = (float) DB::table('employees')->where('id', $row['employee_id'])->value('ctc');
                $mult = (float) ($row['multiplier'] ?? 2);
                $hourly = $ctc > 0 ? ($ctc / 12 / 26 / 8) : 0.0;
                $row['amount'] = round(((float) $row['hours']) * ($mult > 0 ? $mult : 2) * $hourly, 2);
            }

            $id = $input['id'] ?? null;
            if ($id) {
                DB::table($table)->where('id', $id)
                    ->when($tid && Schema::hasColumn($table, 'tenant_id'), fn ($x) => $x->where('tenant_id', $tid))
                    ->update($row);
            } else {
                // COMPANY LIMIT (rev 76): a NEW company must fit within the
                // subscribed company count (every plan includes 1; each extra
                // company is ₹1,000/month). Edits are never blocked; super
                // admin (no tenant) is never gated.
                if ($type === 'companies') {
                    $userTid = $request->user()->tenant_id;
                    $climit = \App\Services\SubscriptionService::canAddCompany($userTid ? (int) $userTid : null);
                    if (! $climit['ok']) {
                        return response()->json(['ok' => false, 'error' => $climit['error']], 422);
                    }
                }
                $fy = \App\Http\Controllers\FinYearController::stamp($table, $tid);
                if ($fy) {
                    $row['fin_year'] = $fy;
                }
                $row['created_at'] = now();
                $newId = DB::table($table)->insertGetId($row);
            }

            // J2 — immutable audit trail: log every create / update of any master
            // record into the tamper-evident activity log (hash-chained).
            \App\Services\Audit::record(
                $tid ? (int) $tid : null,
                optional($request->user())->id,
                $id ? 'update' : 'create',
                $type,
                $id ?: ($newId ?? 0),
                ['fields' => array_values(array_diff(array_keys($row), ['tenant_id', 'updated_at', 'created_at', 'fin_year']))],
                $request->ip()
            );

            // B2 — DRA eligibility gate (warn + allow). When an agent is assigned to a
            // bank / portfolio without a valid DRA certificate, allow the save but
            // surface a warning and record an override note in the audit log.
            if ($type === 'agent-auth' && ! empty($row['employee_id'])) {
                $warn = $this->draGateWarning((int) $row['employee_id'], $tid, $request);
                if ($warn) {
                    return response()->json(['ok' => true, 'warning' => $warn]);
                }
            }

            // rev180 (Gap 4) — LABOUR CODE CHECK on Salary Structure saves: the
            // four Labour Codes (in force Nov 2025) define "wages" such that
            // Basic+DA must be at least 50% of total pay. After any component
            // save, test the affected scope's full component set on a sample
            // gross and warn (save still succeeds — HR decides).
            if ($type === 'salary-setup') {
                $lcWarn = $this->labourCodeWarning($row, $tid);
                if ($lcWarn) {
                    return response()->json(['ok' => true, 'warning' => $lcWarn]);
                }
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * rev180 (Gap 4) — Labour Code "wages ≥ 50%" check for a saved Salary
     * Structure component's scope. Loads the scope's full component set, runs
     * the REAL engine on a sample CTC, and compares (Basic + DA) against the
     * wage gross (gross minus reimbursements). Returns a warning string when
     * below 50%, null when compliant or on any failure (fail-soft, never blocks).
     */
    private function labourCodeWarning(array $row, $tid): ?string
    {
        try {
            if (! Schema::hasTable('salary_components')) {
                return null;
            }
            $comps = DB::table('salary_components')
                ->when($tid && Schema::hasColumn('salary_components', 'tenant_id'), fn ($q) => $q->where('tenant_id', $tid))
                ->when(Schema::hasColumn('salary_components', 'company_name'), function ($q) use ($row) {
                    $cn = trim((string) ($row['company_name'] ?? ''));
                    $q->where(fn ($x) => $cn === ''
                        ? $x->whereNull('company_name')->orWhere('company_name', '')
                        : $x->where('company_name', $cn));
                })
                ->when(Schema::hasColumn('salary_components', 'scope'), function ($q) use ($row) {
                    $sc = trim((string) ($row['scope'] ?? 'company'));
                    $q->where(fn ($x) => $x->where('scope', $sc)->orWhereNull('scope')->orWhere('scope', ''));
                })
                ->get();
            if ($comps->isEmpty()) {
                return null;
            }
            $slip = AppDataController::computeSlipFromComponents(1200000, $comps, SettingsController::rates($tid ? (int) $tid : null), '');
            if (! $slip) {
                return null;
            }
            $wageGross = (float) ($slip['wage_gross'] ?? $slip['gross'] ?? 0);
            if ($wageGross <= 0) {
                return null;
            }
            $basicDa = (float) ($slip['basic'] ?? 0);
            foreach (($slip['earnings'] ?? []) as $en => $ea) {
                $lc = strtolower((string) $en);
                if (str_contains($lc, 'dearness') || preg_match('/(^|[^a-z])da([^a-z]|$)/', $lc)) {
                    $basicDa += (float) $ea;
                }
            }
            $pct = round($basicDa / $wageGross * 100, 1);
            if ($pct < 50) {
                return 'Labour Code check: Basic+DA is only '.$pct.'% of pay for this structure — the Code on Wages expects at least 50%. The structure is saved, but consider raising Basic (affects PF & gratuity).';
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * B2 DRA eligibility gate. Returns null when the agent holds a valid DRA
     * certificate (status = verified and not past expiry); otherwise records an
     * override audit note and returns a warning string for the UI.
     */
    private function draGateWarning(int $employeeId, $tid, Request $request): ?string
    {
        $valid = false;
        if (Schema::hasTable('dra_certs')) {
            $today = now()->toDateString();
            $valid = DB::table('dra_certs')->where('employee_id', $employeeId)
                ->where('status', 'verified')
                ->where(function ($q) use ($today) {
                    $q->whereNull('expiry')->orWhere('expiry', '>=', $today);
                })->exists();
        }
        if ($valid) {
            return null;
        }
        $name = DB::table('employees')->where('id', $employeeId)->value('name') ?: ('Agent #'.$employeeId);
        try {
            if (Schema::hasTable('activity_logs')) {
                DB::table('activity_logs')->insert([
                    'tenant_id' => $tid,
                    'user_id' => optional($request->user())->id,
                    'action' => 'dra_override',
                    'entity' => 'agent_authorizations',
                    'entity_id' => $employeeId,
                    'detail' => json_encode(['note' => 'Bank/portfolio authorisation saved without a valid DRA certificate', 'employee' => $name]),
                    'ip' => $request->ip(),
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // audit logging is best-effort; never block the save on it
        }

        return $name.' has no valid DRA certificate — the authorisation was saved and the override recorded in the audit log. Please add or renew the DRA under Field Force → DRA Certifications.';
    }

    /** Build a schema-safe, type-coerced DB row from a master screen's input (shared by save + import). */
    private function buildRow(array $def, string $table, $tid, array $input): array
    {
        $row = ['tenant_id' => $tid, 'updated_at' => now()];
        foreach ($def['fields'] as $f) {
            if (! array_key_exists($f, $input)) {
                continue;
            }
            $val = $input[$f];
            $type = $def['cols'][$f] ?? 'string';
            if ($type === 'bool') {
                $val = in_array(strtolower((string) $val), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
            } elseif ($type === 'decimal' || $type === 'coord') {
                $val = is_numeric($val) ? (float) $val : 0;
            } elseif ($type === 'int') {
                $val = (int) $val;
            } elseif ($type === 'date') {
                $val = $val !== '' ? $val : null;
            } elseif ($type === 'datetime') {
                $val = $val !== '' ? str_replace('T', ' ', (string) $val) : null;
            } else {
                // string / untyped: send NULL for blanks so the insert never hits
                // "Data truncated" if the underlying column is numeric/enum.
                if ($val === '') {
                    $val = null;
                }
            }
            $row[$f] = $val;
        }
        // Map a chosen company_name back to company_id when possible.
        if (! empty($input['company_name'])) {
            $cid = DB::table('companies')->when($tid, fn ($x) => $x->where('tenant_id', $tid))
                ->where('name', $input['company_name'])->value('id');
            if ($cid) {
                $row['company_id'] = $cid;
            }
        }
        // If the table requires a company_id but none was chosen, default to the
        // tenant's primary company (prevents NOT-NULL violations on company-scoped
        // tables whose screen doesn't force a company pick).
        if ($table !== 'companies' && Schema::hasColumn($table, 'company_id') && empty($row['company_id'])) {
            $row['company_id'] = DB::table('companies')
                ->when($tid && Schema::hasColumn('companies', 'tenant_id'), fn ($x) => $x->where('tenant_id', $tid))
                ->whereNull('deleted_at')->value('id');
        }
        // rev 81b: Manual Attendance — the employee is picked by NAME from the
        // searchable list; resolve to emp_code here (a code typed directly is
        // accepted too). Unknown name = clear error, never a junk punch row.
        if ($table === 'attendance_logs') {
            $pick = trim((string) ($input['emp_name'] ?? ''));
            if ($pick !== '') {
                $emp = DB::table('employees')->when($tid, fn ($x) => $x->where('tenant_id', $tid))
                    ->whereNull('deleted_at')
                    ->where(function ($q2) use ($pick) {
                        $q2->where('name', $pick)->orWhere('emp_code', $pick);
                    })
                    ->first(['emp_code', 'name']);
                if (! $emp) {
                    throw new \RuntimeException('Employee "'.$pick.'" not found — please pick from the list.');
                }
                $row['emp_code'] = $emp->emp_code;
                $row['emp_name'] = $emp->name;
            }
        }
        // Resolve any employee-name fields to their FK id column (emp_map). Accept emp_code too.
        foreach ($def['emp_map'] ?? [] as $nameField => $idCol) {
            if (! empty($input[$nameField])) {
                $eid = DB::table('employees')->when($tid, fn ($x) => $x->where('tenant_id', $tid))
                    ->where(function ($q) use ($input, $nameField) {
                        $q->where('name', $input[$nameField])->orWhere('emp_code', $input[$nameField]);
                    })->whereNull('deleted_at')->value('id');
                if ($eid) {
                    $row[$idCol] = $eid;
                }
            }
        }
        // Resolve other name→FK references (ref_map: nameField => [col, table, match]).
        foreach ($def['ref_map'] ?? [] as $nameField => $spec) {
            if (! empty($input[$nameField])) {
                $q2 = DB::table($spec['table'])
                    ->when($tid && Schema::hasColumn($spec['table'], 'tenant_id'), fn ($x) => $x->where('tenant_id', $tid))
                    ->where($spec['match'] ?? 'name', $input[$nameField]);
                foreach ($spec['where'] ?? [] as $wk => $wv) {
                    $q2->where($wk, $wv);
                }
                $rid = $q2->value('id');
                if ($rid) {
                    $row[$spec['col']] = $rid;
                }
            }
        }
        // Constant column values pinned by the screen (fixed).
        foreach ($def['fixed'] ?? [] as $fk => $fv) {
            $row[$fk] = $fv;
        }
        // Insert-time defaults for NOT-NULL columns a screen doesn't expose.
        if (empty($input['id'])) {
            foreach ($def['defaults'] ?? [] as $dk => $dv) {
                if (! array_key_exists($dk, $row)) {
                    $row[$dk] = $dv;
                }
            }
        }

        return array_intersect_key($row, array_flip(Schema::getColumnListing($table)));
    }

    /** Bulk CSV import for any master screen (e.g. geofence rules). Header = field names. */
    public function import(Request $request, string $type)
    {
        try {
            abort_unless($this->canManage($request), 403);
            $def = $this->def($type);
            if (! $def) {
                return response()->json(['ok' => false, 'error' => 'Unknown master'], 422);
            }
            $request->validate(['file' => ['required', 'file', 'mimes:csv,txt']]);
            $this->ensureCols($def);
            $table = $def['table'];
            $tid = $request->user()->tenant_id ?? DB::table('tenants')->value('id');

            $lines = file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (! $lines) {
                return response()->json(['ok' => false, 'error' => 'Empty file'], 422);
            }
            // Header → canonical field names (case/space/dash tolerant).
            $rawHead = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv(array_shift($lines)));
            $norm = fn ($s) => str_replace([' ', '-', '(km)', '(', ')'], ['_', '_', '', '', ''], trim((string) $s));
            $header = array_map($norm, $rawHead);

            $required = $def['required'] ?? ['name'];
            $empCols = array_keys($def['emp_map'] ?? []);
            $count = 0;
            $skipped = 0;
            $errors = [];
            foreach ($lines as $ln => $line) {
                $cells = str_getcsv($line);
                if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                    continue;
                }
                $assoc = array_combine($header, array_pad($cells, count($header), null));
                $input = [];
                foreach ($def['fields'] as $f) {
                    if (array_key_exists($f, $assoc) && $assoc[$f] !== null) {
                        $input[$f] = trim((string) $assoc[$f]);
                    }
                }
                // If an Emp ID / Emp Code column is supplied, use it to resolve the real
                // employee and fill the canonical name (so name and id always agree).
                $empCodeVal = '';
                foreach (['emp_code', 'employee_id', 'emp_id', 'empid', 'code'] as $ck) {
                    if (! empty($assoc[$ck])) {
                        $empCodeVal = trim((string) $assoc[$ck]);
                        break;
                    }
                }
                if ($empCodeVal !== '' && $empCols) {
                    $emp = DB::table('employees')->when($tid, fn ($x) => $x->where('tenant_id', $tid))
                        ->where('emp_code', $empCodeVal)->whereNull('deleted_at')->first();
                    if ($emp) {
                        foreach ($empCols as $nameField) {
                            $input[$nameField] = $emp->name;
                        }
                    }
                }
                $ok = true;
                foreach ($required as $rf) {
                    if (empty($input[$rf])) {
                        $ok = false;
                        break;
                    }
                }
                if (! $ok) {
                    $skipped++;
                    continue;
                }
                $row = $this->buildRow($def, $table, $tid, $input);
                // Upsert one row per employee for emp_map tables (re-import = update, not duplicate).
                $existingId = null;
                foreach ($empCols as $nameField) {
                    $idCol = $def['emp_map'][$nameField];
                    if (! empty($row[$idCol])) {
                        $existingId = DB::table($table)
                            ->when($tid && Schema::hasColumn($table, 'tenant_id'), fn ($x) => $x->where('tenant_id', $tid))
                            ->where($idCol, $row[$idCol])
                            ->when(Schema::hasColumn($table, 'deleted_at'), fn ($x) => $x->whereNull('deleted_at'))
                            ->value('id');
                        break;
                    }
                }
                if ($existingId) {
                    DB::table($table)->where('id', $existingId)->update($row);
                } else {
                    $row['created_at'] = now();
                    DB::table($table)->insert($row);
                }
                $count++;
            }

            return response()->json(['ok' => true, 'count' => $count, 'skipped' => $skipped]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Download a CSV import template for a master screen (header = field labels + a sample row). */
    public function template(Request $request, string $type)
    {
        $def = $this->def($type);
        if (! $def) {
            return response('Unknown master', 404);
        }
        $samples = [
            'geofence' => ['emp_code' => 'EMP001', 'employee' => 'Asha Rao', 'start' => 'home', 'lat' => '17.3796218', 'lng' => '78.3777371', 'radius_km' => '0.25', 'outside' => 'strict'],
        ];
        // For employee-based screens, lead with an emp_code column (id + name together).
        $cols = $def['fields'];
        if (! empty($def['emp_map'])) {
            array_unshift($cols, 'emp_code');
        }
        $head = implode(',', $cols);
        $sample = $samples[$type] ?? [];
        $rowVals = array_map(fn ($f) => $sample[$f] ?? '', $cols);
        $csv = $head."\n".implode(',', $rowVals)."\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="smartprs-'.$type.'-import-template.csv"',
        ]);
    }

    public function delete(Request $request, string $type, int $id)
    {
        try {
            abort_unless($this->canManage($request), 403);
            $def = $this->def($type);
            if (! $def) {
                return response()->json(['ok' => false, 'error' => 'Unknown master'], 422);
            }
            $table = $def['table'];
            $tid = $request->user()->tenant_id;
            $q = DB::table($table)->where('id', $id)->when($tid && Schema::hasColumn($table, 'tenant_id'), fn ($x) => $x->where('tenant_id', $tid));
            if (Schema::hasColumn($table, 'deleted_at')) {
                $q->update(['deleted_at' => now()]);
            } else {
                $q->delete();
            }

            // J2 — audit the deletion.
            \App\Services\Audit::record(
                $tid ? (int) $tid : null,
                optional($request->user())->id,
                'delete',
                $type,
                $id,
                null,
                $request->ip()
            );

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }
}
