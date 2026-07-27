<?php

namespace App\Http\Controllers;

use App\Models\SalarySlip;

class PayslipController extends Controller
{
    public function index()
    {
        $slips = SalarySlip::with(['employee', 'run'])->latest()->get();

        return view('payslips.index', compact('slips'));
    }

    public function show(SalarySlip $payslip)
    {
        $payslip->load(['employee.department', 'run']);

        return view('payslips.show', ['slip' => $payslip]);
    }
}
