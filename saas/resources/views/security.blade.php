@extends('layout')
@section('title','Operator verification')
@section('content')<div class="panel narrow"><p class="eyebrow">COMMAND STATION</p><h1>Verify operator access.</h1><p class="muted">Command Station requires your password and an authenticator code. Verification lasts 15 minutes.</p>
@if($secret)<div class="notice">Add this setup key to your authenticator with account <strong>{{ auth()->user()->email }}</strong>, issuer <strong>Sahdev</strong>, six digits, and a 30-second interval. This key is shown only during setup.<code class="secret">{{ $secret }}</code></div>@endif
<form method="post" action="/security" class="stack">@csrf @include('partials.field',['name'=>'password','label'=>'Account password','type'=>'password','autocomplete'=>'current-password']) @include('partials.field',['name'=>'code','label'=>'Authenticator code','autocomplete'=>'one-time-code'])<button class="primary">Verify and open Command Station</button></form></div>@endsection
