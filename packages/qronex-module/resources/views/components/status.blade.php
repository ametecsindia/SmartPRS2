@props(['s'])
@php($c = ['active' => 'bg-emerald-100 text-emerald-800', 'approved' => 'bg-emerald-100 text-emerald-800', 'paid' => 'bg-emerald-100 text-emerald-800', 'trial' => 'bg-sky-100 text-sky-800', 'pending' => 'bg-amber-100 text-amber-800', 'grace' => 'bg-amber-100 text-amber-800', 'suspended' => 'bg-red-100 text-red-800', 'rejected' => 'bg-red-100 text-red-800', 'revoked' => 'bg-slate-200 text-slate-700', 'cancelled' => 'bg-slate-200 text-slate-700', 'inactive' => 'bg-slate-200 text-slate-700'][$s] ?? 'bg-slate-100 text-slate-700')
<span class="chip {{ $c }}">{{ ucfirst($s) }}</span>
