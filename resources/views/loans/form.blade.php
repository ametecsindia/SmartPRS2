@extends('layouts.app')

@section('title', $mode === 'edit' ? 'Edit Loan / Advance' : 'Add Loan / Advance')
@section('nav_loans', 'active')

@php $action = $mode === 'edit' ? route('loans.update', $loan) : route('loans.store'); @endphp

@section('content')
    <div class="page-header">
        <div><h2>{{ $mode === 'edit' ? 'Edit' : 'Add' }} Loan / Advance</h2><p>Installment is deducted in each payroll run until paid off</p></div>
        <a href="{{ route('loans.index') }}" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    @if ($errors->any())
        <div style="background:var(--red-soft);color:#dc2626;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);"><i class="fas fa-circle-exclamation"></i> Please fix the highlighted fields.</div>
    @endif

    <form method="POST" action="{{ $action }}">
        @csrf
        @if ($mode === 'edit') @method('PUT') @endif
        <div class="form-card">
            <h3><i class="fas fa-hand-holding-dollar"></i> Details</h3>
            <div class="form-grid">
                <div class="fg span2">
                    <label>Employee *</label>
                    <select name="employee_id" required>
                        <option value="">— Select —</option>
                        @foreach ($employees as $e)
                            <option value="{{ $e->id }}" @selected(old('employee_id', $loan->employee_id) == $e->id)>{{ $e->employee_code }} · {{ $e->fullName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fg">
                    <label>Type *</label>
                    <select name="type" required>
                        <option value="loan" @selected(old('type', $loan->type) === 'loan')>Loan</option>
                        <option value="advance" @selected(old('type', $loan->type) === 'advance')>Advance</option>
                    </select>
                </div>
                <div class="fg"><label>Principal (₹) *</label><input type="number" step="0.01" min="0" name="principal" value="{{ old('principal', $loan->principal) }}" required></div>
                <div class="fg"><label>Installment / month (₹) *</label><input type="number" step="0.01" min="0" name="installment_amount" value="{{ old('installment_amount', $loan->installment_amount) }}" required></div>
                <div class="fg"><label>Total installments *</label><input type="number" min="1" name="installments_total" value="{{ old('installments_total', $loan->installments_total) }}" required></div>
                <div class="fg"><label>Installments paid</label><input type="number" min="0" name="installments_paid" value="{{ old('installments_paid', $loan->installments_paid) }}"></div>
                <div class="fg"><label>Start month</label><input type="month" name="start_month" value="{{ old('start_month', $loan->start_month) }}"></div>
                <div class="fg">
                    <label>Status *</label>
                    <select name="status" required>
                        <option value="active" @selected(old('status', $loan->status) === 'active')>Active</option>
                        <option value="closed" @selected(old('status', $loan->status) === 'closed')>Closed</option>
                    </select>
                </div>
                <div class="fg span3"><label>Note</label><input type="text" name="note" value="{{ old('note', $loan->note) }}"></div>
            </div>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <a href="{{ route('loans.index') }}" class="btn btn-outline">Cancel</a>
            <button class="btn btn-primary"><i class="fas fa-check"></i> {{ $mode === 'edit' ? 'Save' : 'Create' }}</button>
        </div>
    </form>
@endsection
