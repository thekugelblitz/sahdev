@php($fieldId = 'field-'.\Illuminate\Support\Str::random(8))
@php($value = ($restoreInput ?? false) && ($type ?? '') !== 'password' ? old($name,$value ?? '') : ($value ?? ''))
<div class="field"><label for="{{ $fieldId }}">{{ $label }}</label>
@if(isset($options))<select id="{{ $fieldId }}" name="{{ $name }}" @required($required ?? true)>@foreach($options as $key=>$text)<option value="{{ $key }}" @selected((string)($value ?? '')===(string)$key)>{{ $text }}</option>@endforeach</select>
@elseif(($type ?? '')==='textarea')<textarea id="{{ $fieldId }}" name="{{ $name }}" rows="{{ $rows ?? 3 }}" @required($required ?? true)>{{ $value ?? '' }}</textarea>
@else<input id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type ?? 'text' }}" value="{{ ($type ?? '')==='password' ? '' : ($value ?? '') }}" @required($required ?? true) @if(isset($placeholder)) placeholder="{{ $placeholder }}" @endif @if(isset($min)) min="{{ $min }}" @endif @if(isset($step)) step="{{ $step }}" @endif @if(isset($autocomplete)) autocomplete="{{ $autocomplete }}" @endif>@endif
@if(isset($hint))<small>{{ $hint }}</small>@endif</div>
