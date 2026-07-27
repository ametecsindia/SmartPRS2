@extends('layouts.app')

@section('title', 'Apply Leave')
@section('nav_leave', 'active')

@section('content')
    <div class="page-header">
        <div><h2>Apply Leave</h2><p>Submit a leave application</p></div>
        <a href="{{ route('leave.index') }}" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    @if ($errors->any())
        <div style="background:var(--red-soft);color:#dc2626;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);"><i class="fas fa-circle-exclamation"></i> Please fix the highlighted fields.</div>
    @endif

    <form method="POST" action="{{ route('leave.store') }}">
        @csrf
        <div class="form-card">
            <h3><i class="fas fa-plane-departure"></i> Leave Details</h3>
            <div class="form-grid">
                <div class="fg span2">
                    <label>Employee *</label>
                    <select name="employee_id" required>
                        <option value="">— Select —</option>
                        @foreach ($employees as $e)
                            <option value="{{ $e->id }}" @selected(old('employee_id') == $e->id)>{{ $e->employee_code }} · {{ $e->fullName() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fg">
                    <label>Type *</label>
                    <select name="leave_type" required>
                        @foreach (['casual' => 'Casual', 'sick' => 'Sick', 'earned' => 'Earned', 'unpaid' => 'Unpaid (LWP)'] as $v => $l)
                            <option value="{{ $v }}" @selected(old('leave_type') == $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fg"><label>From *</label><input type="date" name="from_date" value="{{ old('from_date') }}" required></div>
                <div class="fg"><label>To *</label><input type="date" name="to_date" value="{{ old('to_date') }}" required></div>
                <div class="fg span3"><label>Reason</label><input type="text" name="reason" value="{{ old('reason') }}" placeholder="Optional"></div>
            </div>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <a href="{{ route('leave.index') }}" class="btn btn-outline">Cancel</a>
            <button class="btn btn-primary"><i class="fas fa-check"></i> Submit</button>
        </div>
    </form>
@endsection
