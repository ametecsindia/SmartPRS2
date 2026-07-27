@extends('layouts.app')

@section('title', $mode === 'edit' ? 'Edit Employee' : 'Add Employee')
@section('nav_employees', 'active')

@php
    $action = $mode === 'edit' ? route('employees.update', $employee) : route('employees.store');
@endphp

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $mode === 'edit' ? 'Edit Employee' : 'Add Employee' }}</h2>
            <p>{{ auth()->user()->company?->name ?? 'Cross-tenant' }}</p>
        </div>
        <a href="{{ route('employees.index') }}" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to list</a>
    </div>

    @if ($errors->any())
        <div style="background:var(--red-soft);color:#dc2626;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);">
            <i class="fas fa-circle-exclamation"></i> Please fix the highlighted fields below.
        </div>
    @endif

    <form method="POST" action="{{ $action }}">
        @csrf
        @if ($mode === 'edit') @method('PUT') @endif

        <div class="form-card">
            <h3><i class="fas fa-id-badge"></i> Identity</h3>
            <div class="form-grid">
                <div class="fg">
                    <label>Employee Code *</label>
                    <input type="text" name="employee_code" value="{{ old('employee_code', $employee->employee_code) }}" placeholder="EMP003" required>
                    @error('employee_code') <span style="color:var(--red);font-size:11px;">{{ $message }}</span> @enderror
                </div>
                <div class="fg">
                    <label>First Name *</label>
                    <input type="text" name="first_name" value="{{ old('first_name', $employee->first_name) }}" required>
                    @error('first_name') <span style="color:var(--red);font-size:11px;">{{ $message }}</span> @enderror
                </div>
                <div class="fg">
                    <label>Last Name</label>
                    <input type="text" name="last_name" value="{{ old('last_name', $employee->last_name) }}">
                </div>
                <div class="fg">
                    <label>Email</label>
                    <input type="email" name="email" value="{{ old('email', $employee->email) }}">
                    @error('email') <span style="color:var(--red);font-size:11px;">{{ $message }}</span> @enderror
                </div>
                <div class="fg">
                    <label>Phone</label>
                    <input type="text" name="phone" value="{{ old('phone', $employee->phone) }}">
                </div>
                <div class="fg">
                    <label>Position</label>
                    <input type="text" name="position" value="{{ old('position', $employee->position) }}" placeholder="Recovery Officer">
                </div>
                <div class="fg">
                    <label>Device User ID</label>
                    <input type="text" name="device_user_id" value="{{ old('device_user_id', $employee->device_user_id) }}" placeholder="Biometric machine ID">
                </div>
            </div>
        </div>

        <div class="form-card">
            <h3><i class="fas fa-briefcase"></i> Employment &amp; Pay</h3>
            <div class="form-grid">
                <div class="fg">
                    <label>Department</label>
                    <select name="department_id">
                        <option value="">— Unassigned —</option>
                        @foreach ($departments as $dept)
                            <option value="{{ $dept->id }}" @selected(old('department_id', $employee->department_id) == $dept->id)>{{ $dept->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fg">
                    <label>Date of Joining</label>
                    <input type="date" name="date_of_joining" value="{{ old('date_of_joining', optional($employee->date_of_joining)->format('Y-m-d')) }}">
                </div>
                <div class="fg">
                    <label>Employment Type *</label>
                    <select name="employment_type" required>
                        @foreach (['permanent' => 'Permanent', 'contract' => 'Contract', 'field' => 'Field'] as $val => $lbl)
                            <option value="{{ $val }}" @selected(old('employment_type', $employee->employment_type) === $val)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fg">
                    <label>Status *</label>
                    <select name="status" required>
                        @foreach (['active' => 'Active', 'inactive' => 'Inactive', 'exited' => 'Exited'] as $val => $lbl)
                            <option value="{{ $val }}" @selected(old('status', $employee->status) === $val)>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="fg">
                    <label>Gross Salary (₹/month) *</label>
                    <input type="number" step="0.01" min="0" name="gross_salary" value="{{ old('gross_salary', $employee->gross_salary) }}" required>
                    @error('gross_salary') <span style="color:var(--red);font-size:11px;">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <a href="{{ route('employees.index') }}" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-check"></i> {{ $mode === 'edit' ? 'Save changes' : 'Create employee' }}
            </button>
        </div>
    </form>
@endsection
