@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null])
<div {{ $attributes->only('class') }}>
  <label class="label" for="f-{{ $name }}">{{ $label }}</label>
  <input id="f-{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $type === 'password' ? '' : old($name, $value) }}" {{ $attributes->except('class')->merge(['class' => 'input']) }} @error($name) aria-invalid="true" @enderror>
  @error($name)<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@else @if($hint)<p class="text-xs text-slate-500 mt-1">{{ $hint }}</p>@endif @enderror
</div>
