@extends('layouts.app')

@section('title', $mode === 'edit' ? 'Edit Department' : 'Add Department')
@section('nav_departments', 'active')

@php
    $action = $mode === 'edit' ? route('departments.update', $department) : route('departments.store');
@endphp

@section('content')
    <div class="page-header">
        <div>
            <h2>{{ $mode === 'edit' ? 'Edit Department' : 'Add Department' }}</h2>
            <p>{{ auth()->user()->company?->name ?? 'Cross-tenant' }}</p>
        </div>
        <a href="{{ route('departments.index') }}" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to list</a>
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
            <h3><i class="fas fa-sitemap"></i> Department Details</h3>
            <div class="form-grid">
                <div class="fg span2">
                    <label>Name *</label>
                    <input type="text" name="name" value="{{ old('name', $department->name) }}" placeholder="Collections" required>
                    @error('name') <span style="color:var(--red);font-size:11px;">{{ $message }}</span> @enderror
                </div>
                <div class="fg">
                    <label>Code</label>
                    <input type="text" name="code" value="{{ old('code', $department->code) }}" placeholder="COL">
                </div>
                <div class="fg span3">
                    <label>Description</label>
                    <input type="text" name="description" value="{{ old('description', $department->description) }}" placeholder="Field recovery & collections team">
                </div>
                <div class="fg">
                    <label>Status</label>
                    <select name="is_active">
                        <option value="1" @selected(old('is_active', $department->is_active) == 1)>Active</option>
                        <option value="0" @selected(old('is_active', $department->is_active) == 0)>Inactive</option>
                    </select>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <a href="{{ route('departments.index') }}" class="btn btn-outline">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> {{ $mode === 'edit' ? 'Save changes' : 'Create department' }}</button>
        </div>
    </form>
@endsection
