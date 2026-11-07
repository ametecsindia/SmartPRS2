<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>QR Attendance</title>
<link rel="stylesheet" href="{{ route('qronex.asset', 'qronex.css') }}"></head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
<div class="card w-full max-w-md space-y-3">
  <div class="card-t">QR Attendance — choose a company</div>
  @forelse($tenants as $id => $name)<a class="block rounded-lg px-3 py-2 hover:bg-slate-50 text-navy" href="{{ request()->fullUrlWithQuery(['tenant' => $id]) }}">{{ $name }}</a>
  @empty<p class="text-sm text-slate-500">No companies yet.</p>@endforelse
</div></body></html>
