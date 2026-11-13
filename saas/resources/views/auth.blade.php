@extends('layout')
@section('title',ucfirst(str_replace('-',' ',$mode)))
@section('content')
<div class="auth-card"><a class="wordmark" href="/login"><span class="mark">S</span> SAHDEV <small>CLOUD</small></a><p class="eyebrow">YOUR HOSTING WORKSPACE</p><h1>{{ match($mode) {'register'=>'One workspace. Every brand.','forgot-password'=>'Reset your password','reset-password'=>'Choose a new password',default=>'Welcome back.'} }}</h1><p class="muted">Manage your brands, control AI spending, and keep operations in view.</p>
<form action="/{{ $mode }}" method="post" class="stack">@csrf
@if($mode==='register')@include('partials.field',['name'=>'name','label'=>'Your name','value'=>old('name')]) @include('partials.field',['name'=>'organization','label'=>'Company / organization','value'=>old('organization')])@endif
@include('partials.field',['name'=>'email','label'=>'Email address','type'=>'email','value'=>old('email',request('email')),'autocomplete'=>'email'])
@if($mode!=='forgot-password')@include('partials.field',['name'=>'password','label'=>'Password','type'=>'password','autocomplete'=>$mode==='login' ? 'current-password' : 'new-password'])@endif
@if(in_array($mode,['register','reset-password']))@include('partials.field',['name'=>'password_confirmation','label'=>'Confirm password','type'=>'password','hint'=>'At least 12 characters, including letters and numbers.'])@endif
@if($mode==='reset-password')<input type="hidden" name="token" value="{{ $token }}">@endif
<button class="primary">{{ match($mode) {'register'=>'Create your workspace','forgot-password'=>'Send reset link','reset-password'=>'Reset password',default=>'Sign in →'} }}</button>
</form><div class="auth-links"><a href="/login">Sign in</a>@if(config('cloud.registration'))<a href="/register">Create an account</a>@endif<a href="/forgot-password">Forgot password?</a></div></div>
@endsection
