@extends('layout')
@section('title','Sign out')
@section('content')
<section class="panel"><h1>Sign out of Sahdev</h1><form method="post" action="/logout">@csrf<button class="primary">Sign out</button> <a href="/app/">Back to support</a></form></section>
@endsection
