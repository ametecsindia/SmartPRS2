<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 28 Aug 2026 (Ejaz) — THE single source of truth for employee fields.
 *
 * Before today the same field list existed FIVE times, maintained by hand:
 *
 *   1. AppDataController::employeeTemplate()   — the sample import file
 *   2. renderEmpForm() in resources/prototype/app.html — the Employee form
 *   3. EmployeeImportService::FIELDS + payload() — the import wizard
 *   4. EmployeeExportController::MAP           — the export
 *   5. self-onboarding/portal.blade.php + hr-console.blade.php
 *
 * Nothing compared them, so every one of Ejaz's findings on 28 Aug was a place
 * where one list had been updated and the other four had not: CATEGORY in the
 * file but not the form, IDENTIFICATION MARKS on the form but in no file,
 * "Only Salary" vs "Salary", "Government ID / SSN" vs "NATIONAL ID / SSN".
 *
 * FIELDS below is now the ONE list. The template headers, the export map, the
 * importer's aliases, the Employee form panels and the Self-Onboarding HR
 * fields are all DERIVED from it. Add a column here and it appears in all five
 * places at once; there is no longer a way to add it to only some of them.
 *
 * ---------------------------------------------------------------------------
 * ADDING A FIELD — the whole procedure:
 *   1. add one row to FIELDS (below),
 *   2. if it needs a new employees column, add it to the parity migration,
 *   3. if it needs special write handling, add it to
 *      AppDataController::storeEmployee() — plain string columns need nothing.
 * ---------------------------------------------------------------------------
 *
 * Row keys:
 *   h    sample-file column header (ALL CAPS). Also the export header.
 *   c    employees column, or a '__synthetic' the export/import resolves.
 *   f    Employee-form field id, used as f_<f>. null = not on the form.
 *   l    Employee-form label. Kept IDENTICAL in meaning to `h` — that is the
 *        whole point; do not reintroduce a second name for one field.
 *   tab  which Employee-form tab renders it (ep/ej/es/ed/eb).
 *   o    fixed option list, exactly as displayed AND as stored.
 *   src  dynamic option source resolved in the browser (@departments, …).
 *   t    input type: date | number | textarea.
 *   hint small grey helper under the input.
 *   otp  contact field that carries a "Verify" button on the form.
 *   s    the two sample rows in the downloadable file.
 *   a    EXTRA importer aliases (the header itself is always accepted).
 *   hr   true = also collected on the Self-Onboarding HR-fields form.
 *   p    Self-Onboarding portal data-field key (candidate-supplied).
 *   u    'block' = refuse to save when another employee already has this value.
 *   v    format validator key (see formatError()).
 */
class EmployeeFieldRules
{
    /** Canonical salary-type labels. Identical on the form, in the file and in the export. */
    public const SALARY_TYPE = [
        'only_salary' => 'Salary',
        'salary_commission' => 'Salary + Commission',
        'only_commission' => 'Commission',
    ];

    /** Every spelling the importer will accept for a salary type, → column code. */
    public const SALARY_TYPE_IN = [
        'salary' => 'only_salary',
        'only salary' => 'only_salary',
        'salary + commission' => 'salary_commission',
        'salary+commission' => 'salary_commission',
        'commission' => 'only_commission',
        'only commission' => 'only_commission',
    ];

    /** employment_stage: stored value → display label. '' IS Permanent. */
    public const EMPLOYMENT_STAGE = ['' => 'Permanent', 'probation' => 'Probation', 'internship' => 'Internship'];

    public const CATEGORY_OPTS = ['General', 'OBC', 'SC', 'ST', 'EWS'];

    public const FIELDS = [
        // ------------------------------------------------ Personal (tab-ep)
        ['h' => 'EMPLOYEE CODE', 'c' => 'emp_code', 'f' => 'id', 'l' => 'Employee Code', 'tab' => 'ep',
            'a' => ['empcode', 'code', 'employeeid', 'empid'], 'hr' => true, 's' => ['EMP100', 'EMP101']],
        ['h' => 'NAME', 'c' => 'name', 'f' => 'name', 'l' => 'Name', 'tab' => 'ep',
            'a' => ['employeename', 'fullname'], 'p' => 'full_name', 's' => ['Sample Name', 'Field Agent Name']],
        ['h' => 'DOB (DD-MM-YYYY)', 'c' => '__dob', 'f' => 'dob', 'l' => 'Date of Birth', 'tab' => 'ep', 't' => 'date',
            'a' => ['dob', 'dateofbirth', 'birthdate'], 'p' => 'dob', 'v' => 'dob', 's' => ['15-06-1995', '20-02-1998']],
        ['h' => 'GENDER', 'c' => 'gender', 'f' => 'gender', 'l' => 'Gender', 'tab' => 'ep',
            'o' => ['Male', 'Female', 'Other'], 'a' => ['sex'], 'p' => 'gender', 's' => ['Male', 'Female']],
        ['h' => 'MARITAL STATUS', 'c' => 'marital_status', 'f' => 'maritalStatus', 'l' => 'Marital Status', 'tab' => 'ep',
            'o' => ['Single', 'Married', 'Divorced', 'Widowed'], 'a' => ['marital'], 'p' => 'marital', 's' => ['Married', 'Single']],
        ['h' => 'FATHER NAME', 'c' => 'father', 'f' => 'father', 'l' => 'Father Name', 'tab' => 'ep',
            'a' => ['father', 'fathersname'], 'p' => 'father_name', 's' => ['Ramesh Sample', 'Suresh Kumar']],
        ['h' => 'MOTHER NAME', 'c' => 'mother', 'f' => 'mother', 'l' => 'Mother Name', 'tab' => 'ep',
            'a' => ['mother', 'mothersname'], 'p' => 'mother_name', 's' => ['Sita Sample', 'Latha Kumar']],
        ['h' => 'SPOUSE NAME', 'c' => 'spouse', 'f' => 'spouse', 'l' => 'Spouse Name', 'tab' => 'ep',
            'a' => ['spouse'], 'p' => 'spouse_name', 'hint' => 'Husband / Wife / Partner', 's' => ['Priya Sample', '']],
        ['h' => 'NATIONALITY', 'c' => 'nationality', 'f' => 'nationality', 'l' => 'Nationality', 'tab' => 'ep',
            'p' => 'nationality', 's' => ['Indian', 'Indian']],
        ['h' => 'CATEGORY', 'c' => 'category', 'f' => 'category', 'l' => 'Category', 'tab' => 'ep',
            'o' => self::CATEGORY_OPTS, 'p' => 'category', 's' => ['General', 'General']],
        ['h' => 'BLOOD GROUP', 'c' => 'blood_group', 'f' => 'bloodGroup', 'l' => 'Blood Group', 'tab' => 'ep',
            'o' => ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'], 'a' => ['blood'], 'p' => 'blood_group', 's' => ['O+', 'B+']],
        ['h' => 'IDENTIFICATION MARKS', 'c' => 'id_marks', 'f' => 'idMarks', 'l' => 'Identification Marks', 'tab' => 'ep',
            'a' => ['identificationmark', 'idmarks', 'identificationmarks'], 'p' => 'id_marks',
            's' => ['Mole on right cheek', 'Scar on left hand']],
        ['h' => 'NATIONAL ID / SSN', 'c' => 'national_id', 'f' => 'nationalId', 'l' => 'National ID / SSN', 'tab' => 'ep',
            'a' => ['nationalid', 'ssn', 'governmentid', 'govtid', 'aadhaar', 'aadhar'], 'p' => 'aadhaar',
            'u' => 'block', 's' => ['ABCDE1234F', 'FGHIJ5678K']],
        ['h' => 'MOBILE', 'c' => 'mobile', 'f' => 'mobile', 'l' => 'Mobile', 'tab' => 'ep', 't' => 'tel', 'otp' => true,
            'a' => ['phone', 'mobileno', 'contact', 'contactno'], 'v' => 'mobile', 'u' => 'block', 's' => ['9999999999', '8888888888']],
        ['h' => 'WHATSAPP', 'c' => 'whatsapp', 'f' => 'whatsapp', 'l' => 'WhatsApp', 'tab' => 'ep', 't' => 'tel', 'otp' => true,
            'a' => ['whatsappnumber'], 'v' => 'mobile', 's' => ['9999999999', '8888888888']],
        ['h' => 'EMAIL', 'c' => 'email', 'f' => 'email', 'l' => 'Email', 'tab' => 'ep', 't' => 'email', 'otp' => true,
            'a' => ['emailaddress', 'officialemail'], 'v' => 'email', 'u' => 'block',
            's' => ['sample@company.in', 'agent@company.in']],
        ['h' => 'EMERGENCY CONTACT PERSON', 'c' => 'emergency_name', 'f' => 'emergencyName', 'l' => 'Emergency Contact Person', 'tab' => 'ep',
            'a' => ['emergencyname', 'emergencyperson', 'emergencycontact'], 'p' => 'emergency_name',
            's' => ['Ramesh Sample', 'Suresh Kumar']],
        ['h' => 'EMERGENCY CONTACT NUMBER', 'c' => 'emergency_phone', 'f' => 'emergencyPhone', 'l' => 'Emergency Contact Number', 'tab' => 'ep', 't' => 'tel',
            'a' => ['emergencyphone', 'emergencynumber', 'emergencymobile'], 'p' => 'emergency_phone',
            'v' => 'mobile', 's' => ['9888888888', '8777777777']],
        ['h' => 'PRESENT ADDRESS', 'c' => 'address', 'f' => 'addr', 'l' => 'Present Address', 'tab' => 'ep', 't' => 'textarea',
            'a' => ['address', 'currentaddress'], 'p' => 'current_address',
            's' => ['12 MG Road, Hyderabad', '45 Park Street, Pune']],
        ['h' => 'PERMANENT ADDRESS', 'c' => 'permanent_address', 'f' => 'permanentAddress', 'l' => 'Permanent Address', 'tab' => 'ep', 't' => 'textarea',
            'a' => ['permaddress'], 'p' => 'permanent_address',
            's' => ['12 MG Road, Hyderabad', '45 Park Street, Pune']],

        // -------------------------------------------- Job & Company (tab-ej)
        ['h' => 'COMPANY', 'c' => '__company', 'f' => 'companyName', 'l' => 'Company', 'tab' => 'ej', 'src' => '@companies',
            'a' => ['companyname'], 's' => ['Acme Recovery Pvt Ltd', 'Acme Recovery Pvt Ltd']],
        ['h' => 'ALSO WORKS FOR', 'c' => 'also_works_for', 'f' => 'multi', 'l' => 'Also Works For', 'tab' => 'ej',
            'o' => ['None', 'Multiple companies'], 'a' => ['alsoworksfor', 'multi'], 'hr' => true,
            's' => ['None', 'None']],
        ['h' => 'DEPARTMENT', 'c' => 'department', 'f' => 'dept', 'l' => 'Department', 'tab' => 'ej', 'src' => '@departments',
            'a' => ['dept'], 'hr' => true, 's' => ['Operations', 'Collections']],
        ['h' => 'DESIGNATION', 'c' => 'designation', 'f' => 'designation', 'l' => 'Designation', 'tab' => 'ej', 'src' => '@designations',
            'a' => ['title', 'role'], 'hr' => true, 's' => ['Executive', 'Field Officer']],
        // 28 Aug 2026 — was headed 'TYPE' in the file and labelled "Employment
        // Type" on the form, which collided head-on with EMPLOYMENT STAGE and
        // with the HR console's own "Employment type". One name now:
        // EMPLOYEE TYPE, and the 'employmenttype' alias is deliberately NOT
        // accepted here any more (it used to import "Permanent" as Office).
        ['h' => 'EMPLOYEE TYPE', 'c' => 'type', 'f' => 'type', 'l' => 'Employee Type', 'tab' => 'ej',
            'o' => ['Office', 'Field'], 'a' => ['type', 'employeetype', 'worktype'], 'hr' => true,
            's' => ['Office', 'Field']],
        ['h' => 'EMPLOYMENT STAGE', 'c' => 'employment_stage', 'f' => 'employment_stage', 'l' => 'Employment Stage', 'tab' => 'ej',
            'o' => ['Permanent', 'Probation', 'Internship'], 'a' => ['stage', 'employmentstage'], 'hr' => true,
            's' => ['Permanent', 'Probation']],
        ['h' => 'STATUS', 'c' => 'status', 'f' => 'status', 'l' => 'Status', 'tab' => 'ej',
            'o' => ['Active', 'Inactive'], 'a' => ['employeestatus'], 'hr' => true, 's' => ['Active', 'Active']],
        ['h' => 'BRANCH', 'c' => 'branch', 'f' => 'branch', 'l' => 'Branch', 'tab' => 'ej', 'src' => '@branches',
            'a' => ['location'], 'hr' => true, 's' => ['Head Office', 'Branch-2']],
        ['h' => 'TEAM', 'c' => 'team', 'f' => 'team', 'l' => 'Team', 'tab' => 'ej', 'src' => '@teams',
            'hr' => true, 's' => ['Alpha Team', 'Bravo Team']],
        ['h' => 'REPORTING MANAGER', 'c' => 'reporting_manager', 'f' => 'teamManager', 'l' => 'Reporting Manager', 'tab' => 'ej', 'src' => '@employees',
            'a' => ['manager', 'reportsto', 'teammanager'], 'hr' => true, 's' => ['Reporting Manager Name', 'Sample Name']],
        ['h' => 'TEAM LEADER', 'c' => 'team_leader', 'f' => 'teamLeader', 'l' => 'Team Leader', 'tab' => 'ej', 'src' => '@employees',
            'a' => ['teamleader', 'leader'], 'hr' => true, 's' => ['Sample Name', 'Sample Name']],
        ['h' => 'DOJ (DD-MM-YYYY)', 'c' => '__doj', 'f' => 'doj', 'l' => 'Date of Joining', 'tab' => 'ej', 't' => 'date',
            'a' => ['doj', 'dateofjoining', 'joiningdate'], 'hr' => true, 'v' => 'doj', 's' => ['01-04-2024', '10-05-2024']],
        ['h' => 'SHIFT', 'c' => 'shift', 'f' => 'shift', 'l' => 'Shift', 'tab' => 'ej', 'src' => '@shifts',
            'a' => ['workingshift'], 'hr' => true, 's' => ['General Shift', 'General Shift']],
        ['h' => 'BIOMETRIC ID', 'c' => 'device_user_id', 'f' => 'deviceUserId', 'l' => 'Biometric ID', 'tab' => 'ej',
            'a' => ['biometricid', 'deviceuserid', 'bioid', 'biometricemployeeid'], 'hr' => true,
            'hint' => 'The ID as it appears on the attendance device', 'v' => 'deviceUserId', 'u' => 'block',
            's' => ['1043', '1044']],

        // -------------------------------------- Statutory & Salary (tab-es)
        ['h' => 'SALARY TYPE', 'c' => '__salaryType', 'f' => 'salaryType', 'l' => 'Salary Type', 'tab' => 'es',
            'o' => ['Salary', 'Salary + Commission', 'Commission'], 'a' => ['salarytype'], 'hr' => true,
            's' => ['Salary', 'Salary + Commission']],
        ['h' => 'SALARY SCHEDULE', 'c' => '__schedule', 'f' => 'schedule', 'l' => 'Salary Schedule', 'tab' => 'es', 'src' => '@schedules',
            'a' => ['schedule', 'payschedule', 'salaryschedule'], 'hr' => true, 's' => ['', '']],
        ['h' => 'CTC', 'c' => 'ctc', 'f' => 'ctc', 'l' => 'CTC', 'tab' => 'es', 't' => 'number',
            'a' => ['annualctc', 'salary', 'grosssalary'], 'hr' => true, 'hint' => 'Annual, in ₹', 'v' => 'ctc',
            's' => ['600000', '336000']],
        ['h' => 'COMMISSION %', 'c' => 'comm_pct', 'f' => 'commPct', 'l' => 'Commission %', 'tab' => 'es', 't' => 'number',
            'a' => ['commission', 'commissionpct', 'commissionpercent', 'commpct'], 'hr' => true,
            'hint' => 'Only when the salary type includes commission', 'v' => 'commPct', 's' => ['0', '30']],
        ['h' => 'PF APPLICABLE', 'c' => 'pf_applicable', 'f' => 'pf', 'l' => 'PF Applicable', 'tab' => 'es',
            'o' => ['Yes', 'No'], 'a' => ['pfapplicable'], 'hr' => true, 's' => ['Yes', 'Yes']],
        ['h' => 'UAN', 'c' => 'uan', 'f' => 'uan', 'l' => 'UAN', 'tab' => 'es',
            'a' => ['uan', 'pfnumber', 'pf', 'uannumber', 'pfuan'], 'p' => 'uan', 'hint' => 'PF universal account number — 12 digits',
            'v' => 'uan', 'u' => 'block', 's' => ['100200300400', '100200300401']],
        ['h' => 'ESI APPLICABLE', 'c' => 'esi_applicable', 'f' => 'esi', 'l' => 'ESI Applicable', 'tab' => 'es',
            'o' => ['Auto', 'Yes', 'No'], 'a' => ['esiapplicable'], 'hr' => true,
            'hint' => 'Auto applies ESI when the wage is under ₹21,000', 's' => ['Auto', 'Yes']],
        ['h' => 'ESIC', 'c' => 'esic_no', 'f' => 'esicNo', 'l' => 'ESIC', 'tab' => 'es',
            'a' => ['esic', 'esicno', 'esicnumber'], 'p' => 'esic', 'hr' => true, 'u' => 'block',
            's' => ['31001234567', '']],
        ['h' => 'PT STATE', 'c' => 'pt_state', 'f' => 'ptState', 'l' => 'PT State', 'tab' => 'es', 'src' => '@ptStates',
            'a' => ['ptstate', 'professionaltaxstate'], 'hr' => true,
            'hint' => 'PT is levied only by some states; the amount comes from the PT slabs in Statutory Rate Settings',
            's' => ['Telangana', 'Maharashtra']],

        // ----------------------------------------------- Documents (tab-ed)
        ['h' => 'PAN', 'c' => 'pan', 'f' => 'pan', 'l' => 'PAN', 'tab' => 'ed',
            'a' => ['panno', 'pannumber'], 'p' => 'pan', 'v' => 'pan', 'u' => 'block',
            's' => ['ABCDE1234F', 'FGHIJ5678K']],
        ['h' => 'DRA DECLARED (YES/NO)', 'c' => '__dra', 'f' => 'draDeclared', 'l' => 'DRA Declared', 'tab' => 'ed',
            'o' => ['Yes', 'No', 'NA'], 'a' => ['dra', 'dpa', 'drapcc', 'dpapcc', 'dradeclared'], 'p' => 'dra_status',
            'hint' => 'The employee’s own declaration — required for a new hire', 's' => ['Yes', 'Yes']],
        ['h' => 'PCC DECLARED (YES/NO)', 'c' => '__pcc', 'f' => 'pccDeclared', 'l' => 'PCC Declared', 'tab' => 'ed',
            'o' => ['Yes', 'No', 'NA'], 'a' => ['pcc', 'policeclearance', 'pccdeclared'], 'p' => 'pcc_status',
            'hint' => 'The employee’s own declaration — required for a new hire', 's' => ['Yes', 'NA']],
        ['h' => 'DRA CERTIFICATE', 'c' => 'dra_status', 'f' => 'dra', 'l' => 'DRA Certificate', 'tab' => 'ed',
            'o' => ['Pending', 'Submitted', 'Verified'], 'a' => ['dracertificate', 'drastatus', 'dracert', 'dracertificatestatus'],
            's' => ['Verified', 'Pending']],
        ['h' => 'DRA EXPIRY (DD-MM-YYYY)', 'c' => 'dra_expiry', 'f' => 'draExpiry', 'l' => 'DRA Expiry', 'tab' => 'ed', 't' => 'date',
            'a' => ['draexpiry', 'draexpirydate', 'dracertificateexpiry'], 'v' => 'date', 's' => ['31-03-2027', '']],
        ['h' => 'PCC STATUS', 'c' => 'pcc_status', 'f' => 'pcc', 'l' => 'PCC Status', 'tab' => 'ed',
            'o' => ['Pending', 'Submitted', 'Verified'], 'a' => ['pccstatus'], 's' => ['Verified', 'Pending']],
        ['h' => 'PCC DEADLINE (DD-MM-YYYY)', 'c' => 'pcc_deadline', 'f' => 'pccDeadline', 'l' => 'PCC Deadline', 'tab' => 'ed', 't' => 'date',
            'a' => ['pccdeadline'], 'v' => 'date', 's' => ['30-06-2026', '31-12-2026']],
        ['h' => 'PCC EXPIRY (DD-MM-YYYY)', 'c' => 'pcc_expiry', 'f' => 'pccExpiry', 'l' => 'PCC Expiry', 'tab' => 'ed', 't' => 'date',
            'a' => ['pccexpiry', 'pccexpirydate'], 'v' => 'date', 's' => ['30-06-2028', '']],

        // ---------------------------------------------------- Bank (tab-eb)
        ['h' => 'BANK NAME', 'c' => 'bank_name', 'f' => 'bankName', 'l' => 'Bank Name', 'tab' => 'eb',
            'a' => ['bank'], 'p' => 'bank_name', 's' => ['State Bank of India', 'HDFC Bank']],
        ['h' => 'BANK ACCOUNT HOLDER', 'c' => 'account_holder', 'f' => 'accountHolder', 'l' => 'Bank Account Holder', 'tab' => 'eb',
            'a' => ['accountholder', 'accountholdername', 'bankholder'], 'p' => 'acc_name',
            'hint' => 'The name exactly as it appears on the bank account', 's' => ['Sample Name', 'Field Agent Name']],
        ['h' => 'ACCOUNT NUMBER', 'c' => 'bank_acc', 'f' => 'bankAcc', 'l' => 'Account Number', 'tab' => 'eb',
            'a' => ['bankacc', 'bankaccount', 'accountno'], 'p' => 'acc_no', 'v' => 'bankAcc', 'u' => 'block',
            's' => ['12345678901', '10987654321']],
        ['h' => 'BANK BRANCH', 'c' => 'bank_branch', 'f' => 'bankBranch', 'l' => 'Bank Branch', 'tab' => 'eb',
            'a' => ['branchname', 'bankbranch'], 'p' => 'bank_branch', 's' => ['MG Road', 'Park Street']],
        ['h' => 'IFSC', 'c' => 'ifsc', 'f' => 'ifsc', 'l' => 'IFSC', 'tab' => 'eb',
            'a' => ['ifsccode'], 'p' => 'ifsc', 'v' => 'ifsc', 's' => ['SBIN0001234', 'HDFC0005678']],
    ];

    /**
     * Import-only column. Never exported (it is a password), always last in the
     * sample file so the sheet reads as "the data, then the one extra".
     */
    public const PASSWORD_COLUMN = 'DEFAULT PASSWORD';

    public const PASSWORD_ALIASES = ['defaultpassword', 'password', 'loginpassword', 'firsttimepassword'];

    public const PASSWORD_SAMPLE = ['Welcome@123', 'Welcome@123'];

    /** Indian states + UTs, for the PT State dropdown and the file's list sheet. */
    public const PT_STATES = [
        'Telangana', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat',
        'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra',
        'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu',
        'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Andaman & Nicobar Islands', 'Chandigarh',
        'Dadra & Nagar Haveli and Daman & Diu', 'Delhi', 'Jammu & Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry',
    ];

    /* ===================================================== derived views == */

    /** Sample-file / export headers, in order. */
    public static function headers(): array
    {
        return array_map(fn ($f) => $f['h'], self::FIELDS);
    }

    /** header => employees column (or '__synthetic'), for EmployeeExportController. */
    public static function exportMap(): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $out[$f['h']] = $f['c'];
        }

        return $out;
    }

    /** The two sample rows written into the downloadable file. */
    public static function sampleRows(): array
    {
        $rows = [[], []];
        foreach (self::FIELDS as $f) {
            $rows[0][] = $f['s'][0] ?? '';
            $rows[1][] = $f['s'][1] ?? '';
        }

        return $rows;
    }

    /**
     * column => [normalised aliases]. The header itself is ALWAYS one of them,
     * so a column can never be added to the file without the importer knowing
     * it — which is exactly how CATEGORY / ESIC / PERMANENT ADDRESS were being
     * dropped in silence before rev190.
     */
    public static function importAliases(): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            $key = self::importKey($f);
            $set = [self::norm($f['h'])];
            foreach (($f['a'] ?? []) as $a) {
                $set[] = self::norm($a);
            }
            $out[$key] = array_values(array_unique(array_merge($out[$key] ?? [], $set)));
        }
        $out['password'] = self::PASSWORD_ALIASES;

        return $out;
    }

    /** The key EmployeeImportService uses for a field (synthetics get a short name). */
    public static function importKey(array $f): string
    {
        return match ($f['c']) {
            '__company' => 'company',
            '__salaryType' => 'salary_type',
            '__schedule' => 'schedule_id',
            '__dra' => 'dpa',
            '__pcc' => 'pcc',
            '__doj' => 'doj',
            '__dob' => 'dob',
            default => $f['c'],
        };
    }

    /** Header normalisation shared by the importer's matcher. */
    public static function norm(string $h): string
    {
        $h = preg_replace('/\([^)]*\)/', ' ', $h);      // drop "(DD-MM-YYYY)", "(YES/NO)"

        return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $h));
    }

    /**
     * The Employee-form panels, as the boot JS needs them:
     *   { ep: [ {k,l,opts?,src?,t?,hint?,otp?}, … ], ej: […], es: […], ed: […], eb: […] }
     * This is what makes the form and the sample file impossible to drift apart.
     */
    public static function formPanels(): array
    {
        $out = ['ep' => [], 'ej' => [], 'es' => [], 'ed' => [], 'eb' => []];
        foreach (self::FIELDS as $f) {
            if (empty($f['f']) || empty($f['tab']) || ! isset($out[$f['tab']])) {
                continue;
            }
            $one = ['k' => $f['f'], 'l' => $f['l']];
            if (isset($f['o'])) {
                $one['opts'] = array_values($f['o']);
            }
            if (isset($f['src'])) {
                $one['src'] = $f['src'];
            }
            if (isset($f['t'])) {
                $one['t'] = $f['t'];
            }
            if (isset($f['hint'])) {
                $one['hint'] = $f['hint'];
            }
            if (! empty($f['otp'])) {
                $one['otp'] = true;
            }
            $out[$f['tab']][] = $one;
        }
        $out['ptStates'] = self::PT_STATES;

        return $out;
    }

    /** Fields the Self-Onboarding HR-fields form collects: [key, label, opts, src]. */
    public static function hrFields(): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            if (empty($f['hr'])) {
                continue;
            }
            $out[] = [
                'k' => self::importKey($f),
                'l' => $f['l'],
                'opts' => array_values($f['o'] ?? []),
                'src' => $f['src'] ?? '',
                't' => $f['t'] ?? '',
            ];
        }

        return $out;
    }

    /**
     * Self-Onboarding portal data-field key => employees column.
     * Uses importKey() so the date synthetics come out as 'doj' / 'dob', the
     * same shape formatErrors() expects.
     *
     * Note `aadhaar` => `national_id`: the portal's key is deliberately left
     * alone so onboarding records already in flight keep loading, but the value
     * belongs in the column the Employee form and the import file use. It used
     * to be written to a separate employees.aadhaar that nothing else read.
     */
    public static function portalMap(): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            if (! empty($f['p'])) {
                $out[$f['p']] = self::importKey($f);
            }
        }

        return $out;
    }

    /** Employee-form field id => employees column, for storeEmployee(). */
    public static function formToColumn(): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            if (! empty($f['f']) && strpos((string) $f['c'], '__') !== 0) {
                $out[$f['f']] = $f['c'];
            }
        }

        return $out;
    }

    /** Fields that must not repeat across employees: column => human label. */
    public static function uniqueFields(): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            if (($f['u'] ?? '') === 'block' && strpos((string) $f['c'], '__') !== 0) {
                $out[$f['c']] = $f['l'];
            }
        }

        return $out;
    }

    /* ================================================ format validation == */

    /**
     * The SAME rules the Employee form enforces in the browser
     * (AppController::empFieldError), now available server-side so
     * Self-Onboarding and the import wizard cannot accept what the form
     * rejects. Blank is ALWAYS allowed — these fields are optional; only a
     * filled-in value of the wrong shape is refused.
     *
     * @return string|null null when the value is acceptable
     */
    public static function formatError(string $rule, $raw): ?string
    {
        $v = trim((string) $raw);
        if ($v === '') {
            return null;
        }
        switch ($rule) {
            case 'pan':
                return preg_match('/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/', $v)
                    ? null : 'PAN must be 10 characters in the format ABCDE1234F.';
            case 'ifsc':
                return preg_match('/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/', $v)
                    ? null : 'IFSC must be 11 characters like SBIN0001234.';
            case 'uan':
                return preg_match('/^[0-9]{12}$/', str_replace(' ', '', $v))
                    ? null : 'UAN must be exactly 12 digits.';
            case 'bankAcc':
                return preg_match('/^[0-9]{6,20}$/', str_replace(' ', '', $v))
                    ? null : 'Bank account number must be 6 to 20 digits (no letters).';
            case 'email':
                return preg_match('/^[^ @]+@[^ @]+\.[^ @]+$/', $v)
                    ? null : 'Enter a valid email address.';
            case 'deviceUserId':
                return preg_match('/^[A-Za-z0-9]+$/', $v)
                    ? null : 'Biometric ID must be letters and numbers only — no spaces or symbols.';
            case 'ctc':
                return preg_match('/^[0-9]+(\.[0-9]+)?$/', str_replace([',', ' '], '', $v))
                    ? null : 'CTC must be a number (no letters or symbols).';
            case 'commPct':
                $pc = (float) $v;

                return (is_numeric($v) && $pc >= 0 && $pc <= 100)
                    ? null : 'Commission % must be a number between 0 and 100.';
            case 'mobile':
                if (preg_match('/[^0-9+\-\s()]/', $v)) {
                    return 'Mobile number is invalid — remove letters and symbols.';
                }
                $d = preg_replace('/\D+/', '', $v);
                if (strlen($d) === 12 && str_starts_with($d, '91')) {
                    $d = substr($d, 2);
                } elseif (strlen($d) === 11 && str_starts_with($d, '0')) {
                    $d = substr($d, 1);
                }

                return preg_match('/^[6-9][0-9]{9}$/', $d)
                    ? null : 'Enter a valid 10-digit mobile number (starting 6-9).';
            case 'dob':
            case 'doj':
            case 'date':
                $d = self::parseDate($v);
                if (! $d) {
                    return 'Enter a real date in DD-MM-YYYY.';
                }
                if ($rule === 'dob' && $d > time()) {
                    return 'Date of Birth cannot be in the future.';
                }

                return null;
        }

        return null;
    }

    /** DD-MM-YYYY or YYYY-MM-DD → unix ts, or null. Rejects 31-02-2020. */
    public static function parseDate(string $v): ?int
    {
        $v = trim(preg_replace('/-+/', '-', preg_replace('/[^0-9]/', '-', $v)), '-');
        $p = explode('-', $v);
        if (count($p) !== 3) {
            return null;
        }
        if (strlen($p[0]) === 4) {
            [$y, $m, $d] = [(int) $p[0], (int) $p[1], (int) $p[2]];
        } else {
            [$d, $m, $y] = [(int) $p[0], (int) $p[1], (int) $p[2]];
        }
        if (! checkdate($m, $d, $y) || $y < 1900 || $y > 3000) {
            return null;
        }

        return mktime(0, 0, 0, $m, $d, $y);
    }

    /**
     * Validate a whole set of values against the format rules. Keys are
     * employees columns, except the date synthetics which use their short
     * importer keys ('doj' / 'dob') — the same shape payload() builds.
     * Returns key => message for everything that failed.
     */
    public static function formatErrors(array $byColumn): array
    {
        $errs = [];
        foreach (self::FIELDS as $f) {
            if (empty($f['v'])) {
                continue;
            }
            $key = self::importKey($f);
            if (! array_key_exists($key, $byColumn)) {
                continue;
            }
            if ($msg = self::formatError($f['v'], $byColumn[$key])) {
                $errs[$key] = $f['l'].': '.$msg;
            }
        }

        return $errs;
    }

    /* ====================================================== uniqueness === */

    /**
     * 28 Aug 2026 (Ejaz) — "PAN will be unique to the individuals. It is
     * accepting the PAN multiple times for different employees."
     *
     * The employees table carries exactly ONE unique index —
     * (tenant_id, emp_code) — and none of the three write paths checked
     * anything else, so the same PAN, UAN, National ID, bank account,
     * Biometric ID or email could be saved against any number of people.
     *
     * Biometric ID and email are the dangerous two: device_user_id is the key
     * punch ingestion matches on (a duplicate files attendance against the
     * wrong employee), and email is the ESS login id (a duplicate lets an
     * import overwrite another employee's password).
     *
     * This runs in PHP rather than as a unique index on purpose: an existing
     * install may already hold duplicates, and it must still be able to
     * migrate and then be cleaned up from the Directory.
     *
     * @param  array     $byColumn   employees column => value (blank = skipped)
     * @param  int|null  $ignoreId   employees.id being edited, excluded from the search
     * @return array     column => "PAN ABCDE1234F is already used by EMP100 (Ravi)."
     */
    public static function duplicateErrors(array $byColumn, $tenantId, ?int $ignoreId = null): array
    {
        $errs = [];
        if (! Schema::hasTable('employees')) {
            return $errs;
        }
        foreach (self::uniqueFields() as $col => $label) {
            $val = trim((string) ($byColumn[$col] ?? ''));
            if ($val === '' || ! Schema::hasColumn('employees', $col)) {
                continue;
            }
            try {
                $q = DB::table('employees')->whereNull('deleted_at')
                    ->whereRaw('LOWER(TRIM('.$col.')) = ?', [strtolower($val)]);
                if ($tenantId) {
                    $q->where('tenant_id', $tenantId);
                }
                if ($ignoreId) {
                    $q->where('id', '!=', $ignoreId);
                }
                if (Schema::hasColumn('employees', 'archived_at')) {
                    $q->whereNull('archived_at');
                }
                $hit = $q->first(['emp_code', 'name']);
                if ($hit) {
                    $who = trim((string) ($hit->emp_code ?? ''));
                    $who .= ($hit->name ?? '') ? ' ('.$hit->name.')' : '';
                    $errs[$col] = $label.' "'.$val.'" is already used by '.($who ?: 'another employee')
                        .'. Every employee must have their own '.$label.'.';
                }
            } catch (\Throwable $e) {
                // a failed lookup must never block a save outright
            }
        }

        return $errs;
    }
}
