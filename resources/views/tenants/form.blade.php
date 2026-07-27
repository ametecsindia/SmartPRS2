@extends('layouts.app')

@section('title', $mode === 'edit' ? 'Edit Tenant' : 'Add Tenant')
@section('nav_tenants', 'active')

@php $action = $mode === 'edit' ? route('tenants.update', $company) : route('tenants.store'); @endphp

@section('content')
    <div class="page-header">
        <div><h2>{{ $mode === 'edit' ? 'Edit' : 'Add' }} Tenant</h2><p>Client company on the SmartPRS platform</p></div>
        <a href="{{ route('tenants.index') }}" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    @if ($errors->any())
        <div style="background:var(--red-soft);color:#dc2626;font-size:13px;padding:12px 16px;border-radius:10px;margin-bottom:18px;font-family:var(--font2);"><i class="fas fa-circle-exclamation"></i> Please fix the highlighted fields.</div>
    @endif

    <form method="POST" action="{{ $action }}">
        @csrf
        @if ($mode === 'edit') @method('PUT') @endif
        <div class="form-card">
            <h3><i class="fas fa-building-user"></i> Company</h3>
            <div class="form-grid">
                <div class="fg span2"><label>Name *</label><input type="text" name="name" value="{{ old('name', $company->name) }}" required></div>
                <div class="fg"><label>Code *</label><input type="text" name="code" value="{{ old('code', $company->code) }}" placeholder="EXON" required></div>
                <div class="fg span3"><label>Legal Name</label><input type="text" name="legal_name" value="{{ old('legal_name', $company->legal_name) }}"></div>
                <div class="fg"><label>GSTIN</label><input type="text" name="gstin" value="{{ old('gstin', $company->gstin) }}"></div>
                <div class="fg"><label>PAN</label><input type="text" name="pan" value="{{ old('pan', $company->pan) }}"></div>
                <div class="fg"><label>State</label><input type="text" name="state" value="{{ old('state', $company->state) }}"></div>
                <div class="fg"><label>Phone</label><input type="text" name="phone" value="{{ old('phone', $company->phone) }}"></div>
                <div class="fg"><label>Email</label><input type="email" name="email" value="{{ old('email', $company->email) }}"></div>
                <div class="fg">
                    <label>Deployment *</label>
                    <select name="deployment" required>
                        <option value="saas" @selected(old('deployment', $company->deployment) === 'saas')>SaaS (hosted)</option>
                        <option value="onprem" @selected(old('deployment', $company->deployment) === 'onprem')>On-Premise</option>
                    </select>
                </div>
                <div class="fg">
                    <label>Status</label>
                    <select name="is_active">
                        <option value="1" @selected(old('is_active', $company->is_active) == 1)>Active</option>
                        <option value="0" @selected(old('is_active', $company->is_active) == 0)>Suspended</option>
                    </select>
                </div>
            </div>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <a href="{{ route('tenants.index') }}" class="btn btn-outline">Cancel</a>
            <button class="btn btn-primary"><i class="fas fa-check"></i> {{ $mode === 'edit' ? 'Save' : 'Create' }}</button>
        </div>
    </form>
@endsection
