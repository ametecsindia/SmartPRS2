<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0E2747">
<title>{{ $title ?? 'QR Attendance' }} · {{ $host->name() }}</title>
<link rel="stylesheet" href="{{ route('qronex.asset', ['f' => 'qronex.css']) }}">
<script defer src="{{ route('qronex.asset', ['f' => 'alpine.min.js']) }}"></script>
@stack('head')
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen antialiased">
@php
  use Ametecs\Qronex\Qronex;
  $u = auth()->user();
  $on = Qronex::enabled(Qronex::tenantId());
  $waiting = $on && Qronex::can($u, 'approve') ? collect(\Ametecs\Qronex\Http\Controllers\Admin\ApprovalsController::pending())->sum(fn ($c) => $c->count()) : 0;
  // [label, route, active pattern, icon, ability, badge]
  $nav = $on ? [
      'Overview' => [['Dashboard', 'qronex.dashboard', 'qronex.dashboard', 'home', 'view'], ['Attendance ledger', 'qronex.attendance', 'qronex.attendance*', 'list', 'view']],
      'Approvals' => [['Approvals', 'qronex.approvals', 'qronex.approvals*', 'check', 'view', $waiting], ['Phones', 'qronex.devices', 'qronex.devices*', 'phone', 'view'], ['Remote attendance', 'qronex.remote', 'qronex.remote*', 'globe', 'view']],
      'Organisation' => [['Employees', 'qronex.employees', 'qronex.employees*', 'users', 'view'], ['Offices & gates', 'qronex.offices', 'qronex.offices*', 'building', 'view']],
      'Settings' => [['Module & policy', 'qronex.settings', 'qronex.settings*', 'sliders', 'view']],
  ] : ['Settings' => [['Module & policy', 'qronex.settings', 'qronex.settings*', 'sliders', 'view']]];
  $tenant = $host->tenant(Qronex::tenantId());
@endphp
<div x-data="{open:false}" class="lg:flex">
  <div x-show="open" x-transition.opacity class="fixed inset-0 bg-navy/60 z-30 lg:hidden" @click="open=false" x-cloak></div>
  <div class="lg:w-64 lg:shrink-0 lg:bg-ink">
  <aside :class="open ? 'translate-x-0' : '-translate-x-full'" class="sidebar bg-gradient-to-b from-navy to-ink fixed z-40 inset-y-0 left-0 w-64 text-white flex flex-col -translate-x-full transition-transform duration-200 lg:translate-x-0 lg:sticky lg:top-0 lg:h-screen lg:shrink-0">
    <div class="px-5 pt-5 pb-4 flex items-center gap-3">
      <svg viewBox="0 0 32 32" class="h-9 w-9 shrink-0" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#fff"/><rect x="6" y="6" width="8" height="8" rx="1.5" fill="none" stroke="#0E2747" stroke-width="2"/><rect x="18" y="6" width="8" height="8" rx="1.5" fill="none" stroke="#0E2747" stroke-width="2"/><rect x="6" y="18" width="8" height="8" rx="1.5" fill="none" stroke="#0E2747" stroke-width="2"/><circle cx="22" cy="22" r="3.2" fill="#0A8278"/></svg>
      <div class="min-w-0">
        <div class="font-bold tracking-wide">QR Attendance</div>
        <div class="text-[11px] text-slate-300 truncate">{{ $host->name() }} · {{ $tenant['name'] ?? '' }}</div>
      </div>
      <button class="ml-auto lg:hidden text-slate-300" @click="open=false" aria-label="Close menu"><x-qx::icon name="x" /></button>
    </div>
    <nav class="nav flex-1 overflow-y-auto px-3 pb-4 space-y-5">
      @foreach($nav as $group => $items)
        <div>
          <div class="px-3 mb-1 text-[10px] font-semibold uppercase tracking-widest text-slate-400">{{ $group }}</div>
          @foreach($items as $i)
            @if(Qronex::can($u, $i[4]))
            <a href="{{ route($i[1]) }}" @class(['on' => request()->routeIs($i[2])])><x-qx::icon :name="$i[3]" class="w-[18px] h-[18px] shrink-0 opacity-80" /><span class="truncate">{{ $i[0] }}</span>
              @if(! empty($i[5]))<span class="ml-auto chip bg-amber-400 text-navy">{{ $i[5] }}</span>@endif</a>
            @endif
          @endforeach
        </div>
      @endforeach
    </nav>
    <div class="border-t border-white/10 p-3">
      <a href="{{ $host->homeUrl() }}" class="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-slate-200 hover:bg-white/5"><x-qx::icon name="logout" class="w-4 h-4" /> Back to {{ $host->name() }}</a>
      <div class="px-3 pt-1 text-[11px] text-slate-400 truncate">{{ $u->name ?? $u->email }}</div>
    </div>
  </aside>
  </div>
  <main class="flex-1 min-w-0">
    <header class="sticky top-0 z-20 bg-white/85 backdrop-blur border-b border-slate-200/80 px-4 md:px-8 py-4 flex flex-wrap items-center gap-3">
      <button class="lg:hidden btn-ghost btn-sm" @click="open=true" aria-label="Open menu"><x-qx::icon name="menu" /></button>
      <div class="min-w-0">
        <div class="text-[11px] text-slate-500 flex items-center gap-2"><span class="rounded bg-teal-soft text-teal font-semibold px-1.5 py-px">QR ATTENDANCE</span> @isset($crumb)<span>{!! $crumb !!}</span>@endisset</div>
        <h1 class="text-xl md:text-2xl font-extrabold tracking-tight text-navy line-clamp-2 md:line-clamp-1 break-words">{{ $title ?? '' }}</h1>
      </div>
      <div class="ml-auto flex flex-wrap items-center gap-2">@yield('actions')</div>
    </header>
    <div class="p-4 md:p-8 space-y-5 max-w-[1400px] fade-in">
      @include('qronex::components.flash')
      @yield('content')
    </div>
  </main>
</div>
@stack('scripts')
</body>
</html>
