@php($catalogId = $item->id ?? '')
<form action="/command/catalog/{{ $kind }}{{ $catalogId ? '/'.$catalogId : '' }}" method="post" class="form-grid">@csrf
@if($kind!=='promotions')@include('partials.field',['name'=>'name','label'=>'Name','value'=>$item->name ?? ''])@endif
@if($kind==='providers')
@include('partials.field',['name'=>'driver','label'=>'API protocol','value'=>$item->driver ?? 'openai_compatible','options'=>['openai_compatible'=>'OpenAI compatible — OpenAI, OpenRouter, Gemini, etc.','anthropic'=>'Anthropic Messages']])
@include('partials.field',['name'=>'endpoint','label'=>'Full API endpoint','type'=>'url','value'=>$item->endpoint ?? '','hint'=>'HTTPS only. Host must appear in the server’s AI_PROVIDER_HOSTS allowlist.'])
@include('partials.field',['name'=>'secret','label'=>$item ? 'Replace API key (leave empty to retain)' : 'API key','type'=>'password','required'=>!$item,'autocomplete'=>'new-password','hint'=>'Encrypted at rest. Existing credentials are never shown back.'])
@elseif($kind==='models')
@include('partials.field',['name'=>'provider_id','label'=>'Provider','value'=>$item->provider_id ?? '', 'options'=>$providers->pluck('name','id')->all()])
@include('partials.field',['name'=>'remote_model','label'=>'Provider model identifier','value'=>$item->remote_model ?? ''])
@include('partials.field',['name'=>'max_input_bytes','label'=>'Maximum input bytes','type'=>'number','min'=>1000,'value'=>$item->max_input_bytes ?? 16000])
@include('partials.field',['name'=>'max_output_tokens','label'=>'Maximum output tokens','type'=>'number','min'=>1,'value'=>$item->max_output_tokens ?? 2048])
@include('partials.field',['name'=>'token_parameter','label'=>'Output limit parameter (compatible protocol)','value'=>$item->token_parameter ?? 'max_tokens','options'=>['max_tokens'=>'max_tokens','max_completion_tokens'=>'max_completion_tokens']])
@include('partials.field',['name'=>'supports_tools','label'=>'Function calling','value'=>(int)($item->supports_tools ?? false),'options'=>['0'=>'Disabled','1'=>'Enabled']])
@foreach(['input_rate'=>'Customer input price','output_rate'=>'Customer output price','cost_input_rate'=>'Estimated provider input cost','cost_output_rate'=>'Estimated provider output cost'] as $key=>$label)
@include('partials.field',['name'=>$key,'label'=>$label.' per 1M tokens','value'=>str_replace(',','',\App\Support\Amount::format($item->price->{$key} ?? 0)),'hint'=>str_starts_with($key,'cost_') ? 'Provider currency units; estimate only, reconcile provider invoices separately.' : 'Sahdev credits. All input tokens, including cached input, use this published input rate.'])
@endforeach
@include('partials.field',['name'=>'cost_currency','label'=>'Provider cost currency','value'=>$item->price->cost_currency ?? 'USD','options'=>['USD'=>'USD','INR'=>'INR','EUR'=>'EUR']])
@elseif($kind==='plans')
@include('partials.field',['name'=>'brand_limit','label'=>'Brand limit','type'=>'number','min'=>1,'value'=>$item->brand_limit ?? 5])
@include('partials.field',['name'=>'seat_limit','label'=>'Staff seat limit','type'=>'number','min'=>1,'value'=>$item->seat_limit ?? 10])
@include('partials.field',['name'=>'concurrency_limit','label'=>'Concurrent request limit','type'=>'number','min'=>1,'value'=>$item->concurrency_limit ?? 2])
<fieldset class="full"><legend>Models included in this plan</legend>@forelse($models as $m)<label class="check"><input type="checkbox" name="model_ids[]" value="{{ $m->id }}" @checked($plan_models->where('plan_id',$catalogId)->contains('model_id',$m->id))>{{ $m->name }}</label>@empty<p class="muted">Add models first, then grant them to a plan.</p>@endforelse</fieldset>
@elseif($kind==='packs')
@include('partials.field',['name'=>'credits','label'=>'Credits provided','value'=>str_replace(',','',\App\Support\Amount::format($item->credits ?? 100000000))])
@include('partials.field',['name'=>'price','label'=>'Price before tax','value'=>str_replace(',','',\App\Support\Amount::format($item->price_minor ?? 10000,2))])
@include('partials.field',['name'=>'currency','label'=>'Currency','value'=>$item->currency ?? 'INR','options'=>['INR'=>'INR','USD'=>'USD','EUR'=>'EUR']])
@elseif($kind==='promotions')
@include('partials.field',['name'=>'code','label'=>'Promotion code (uppercase)','value'=>$item->code ?? ''])
@include('partials.field',['name'=>'discount_percent','label'=>'Discount percent','type'=>'number','min'=>1,'value'=>$item->discount_percent ?? 10])
@include('partials.field',['name'=>'max_redemptions','label'=>'Maximum redemptions','type'=>'number','min'=>1,'value'=>$item->max_redemptions ?? 50,'hint'=>'One redemption per organization. Creating an invoice reserves a redemption.'])
@include('partials.field',['name'=>'expires_at','label'=>'Expires at (UTC)','type'=>'datetime-local','value'=>isset($item->expires_at) ? str_replace(' ','T',substr($item->expires_at,0,16)) : now()->addMonth()->format('Y-m-d\TH:i')])
@endif
@include('partials.field',['name'=>'active','label'=>'Availability','value'=>(int)($item->active ?? false),'options'=>['0'=>'Disabled','1'=>'Enabled']])
<div class="form-actions"><button class="primary">{{ $item ? 'Save changes' : 'Create' }}</button>@if($kind==='models')<small>Saving creates a new immutable price version.</small>@endif</div></form>
@if($catalogId)<form action="/command/catalog/{{ $kind }}/{{ $catalogId }}" method="post" data-confirm="Archive this item? Financial history will remain available.">@csrf @method('DELETE')<button class="danger">Archive {{ rtrim($kind,'s') }}</button></form>@endif
