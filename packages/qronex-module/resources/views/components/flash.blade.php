{{-- Toasts (success) + inline error summary. Toasts auto-dismiss; errors stay until fixed. --}}
@if(session('ok'))
  <div x-data="{s:true}" @unless(str_contains(session('ok'), 'code')) x-init="setTimeout(() => s = false, 5000)" @endunless x-show="s" x-transition.opacity.duration.300ms
       class="fixed z-50 bottom-4 right-4 left-4 sm:left-auto sm:w-96 rounded-xl bg-navy text-white shadow-2xl px-4 py-3 flex gap-3 items-start" role="status">
    <span class="mt-0.5 rounded-full bg-teal p-1"><x-qx::icon name="check" class="w-4 h-4" /></span>
    <div class="text-sm flex-1">{{ session('ok') }}</div>
    <button class="text-slate-300 hover:text-white" @click="s=false" aria-label="Dismiss"><x-qx::icon name="x" class="w-4 h-4" /></button>
  </div>
@endif
@if($errors->any())
  <div class="rounded-xl bg-red-50 text-red-800 border border-red-200 px-4 py-3 text-sm flex gap-3" role="alert">
    <x-qx::icon name="alert" class="w-5 h-5 shrink-0 text-red-500" />
    <ul class="space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
  </div>
@endif
