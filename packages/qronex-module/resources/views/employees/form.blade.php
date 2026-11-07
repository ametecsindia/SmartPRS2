@extends('qronex::layout', ['title' => $employee->name, 'crumb' => '<a class="hover:text-teal" href="'.route('qronex.employees').'">Employees</a> · '.e($employee->employee_code)])
@section('content')
<form method="post" action="{{ route('qronex.employees.update', $employee) }}" class="card space-y-4 max-w-xl">
  @csrf @method('PUT')
  <p class="text-sm text-slate-600">Name, code and contact details come from {{ $host->name() }}. Change them there.</p>
  <div x-data="{office:'{{ old('office_id', $employee->office_id ?? $offices->first()?->id) }}', gate:'{{ old('gate_id', $employee->gate_id) }}', gates:{{ Js::from($offices->mapWithKeys(fn ($o) => [$o->id => $o->gates->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])])) }}}" class="grid sm:grid-cols-2 gap-4">
    <div><label class="label" for="f-office">Office</label><select id="f-office" name="office_id" class="input" x-model="office" @change="gate=''" required>@foreach($offices as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select></div>
    <div><label class="label" for="f-gate">Bound gate</label><select id="f-gate" name="gate_id" class="input" x-model="gate"><option value="">Any gate</option>
      <template x-for="g in (gates[office] || [])" :key="g.id"><option :value="g.id" x-text="g.name" :selected="String(g.id) === String(gate)"></option></template></select></div>
  </div>
  <div><label class="label" for="f-scope">Allow scans at</label><select id="f-scope" name="scan_scope" class="input">@foreach(\Ametecs\Qronex\Models\Employee::SCAN_SCOPES as $k => $l)<option value="{{ $k }}" @selected(old('scan_scope', $employee->scan_scope ?: 'office') === $k)>{{ $l }}</option>@endforeach</select>
    <p class="text-xs text-slate-500 mt-1">“Any office and gate” lets this employee punch at other offices too.</p></div>
  @if(\Ametecs\Qronex\Qronex::can(auth()->user(), 'configure'))<div><label class="label" for="f-maxdev">Phones allowed</label><input id="f-maxdev" name="max_devices" type="number" min="1" max="5" class="input w-32" value="{{ old('max_devices', $employee->max_devices ?? 1) }}">
    <p class="text-xs text-slate-500 mt-1">One phone per employee. Set more only as an exception.</p></div>@endif
  <div class="flex gap-2"><button class="btn">Save</button><a class="btn-ghost" href="{{ route('qronex.employees') }}">Cancel</a></div>
</form>
@endsection
