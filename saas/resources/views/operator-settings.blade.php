@extends('layout')
@section('title','Command Station settings')
@section('content')
<section class="panel">
    <h1>Command Station settings</h1>
    <p>Authenticator verification is {{ $required ? 'required' : 'off' }} for all super admins.</p>
    <form method="post" action="/command/settings" class="stack">
        @csrf
        <input type="hidden" name="mfa_required" value="{{ $required ? '0' : '1' }}">
        @include('partials.field',['name'=>'password','label'=>'Confirm your password','type'=>'password','autocomplete'=>'current-password'])
        <button type="submit">{{ $required ? 'Disable MFA' : 'Enable MFA' }}</button>
    </form>
</section>
@endsection
