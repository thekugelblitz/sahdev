@extends('layout')
@section('title','Verify email')
@section('content')<div class="panel narrow"><p class="eyebrow">ONE MORE STEP</p><h1>Verify your email.</h1><p>Open the verification link sent to {{ auth()->user()->email }} to access your brands.</p><form method="post" action="/email/verification-notification">@csrf<button class="primary">Send another verification email</button></form></div>@endsection
