@props(['label', 'value', 'icon' => null, 'tone' => 'navy', 'sub' => null, 'href' => null, 'delta' => null])
@php($tones = ['navy' => 'bg-navy/5 text-navy', 'teal' => 'bg-teal-soft text-teal', 'amber' => 'bg-amber-100 text-amber-700', 'red' => 'bg-red-100 text-red-700', 'sky' => 'bg-sky-100 text-sky-700'])
<{{ $href ? 'a' : 'div' }} @if($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'card stat group '.($href ? 'hover:border-teal/50 hover:shadow-md transition' : '')]) }}>
  <div class="flex items-start justify-between gap-3">
    <div class="min-w-0">
      <div class="text-[13px] font-medium text-slate-500">{{ $label }}</div>
      <div class="mt-1.5 text-2xl md:text-[2rem] leading-none font-extrabold tracking-tight text-navy tabular-nums">{{ $value }}</div>
    </div>
    @if($icon)<span class="shrink-0 rounded-lg p-2 {{ $tones[$tone] ?? $tones['navy'] }}"><x-qx::icon :name="$icon" /></span>@endif
  </div>
  @if($sub || $delta !== null)
    <div class="mt-2 text-xs text-slate-500 flex flex-wrap items-center gap-2">
      @if($delta !== null)<span class="chip {{ $delta >= 0 ? 'bg-teal-soft text-teal' : 'bg-red-100 text-red-700' }}">{{ $delta >= 0 ? '▲' : '▼' }} {{ abs($delta) }}%</span>@endif
      {{ $sub }}
    </div>
  @endif
</{{ $href ? 'a' : 'div' }}>
