@props(['icon' => 'inbox', 'title', 'text' => null])
<div {{ $attributes->merge(['class' => 'text-center py-10 px-4']) }}>
  <span class="inline-flex rounded-full bg-slate-100 p-3 text-slate-400"><x-qx::icon :name="$icon" class="w-6 h-6" /></span>
  <div class="mt-3 font-semibold text-navy">{{ $title }}</div>
  @if($text)<p class="mt-1 text-sm text-slate-500 max-w-sm mx-auto">{{ $text }}</p>@endif
  @if(trim($slot))<div class="mt-4">{{ $slot }}</div>@endif
</div>
