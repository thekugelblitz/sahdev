<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title', 'Workspace') · Sahdev</title><link rel="stylesheet" href="/app.css"><script src="/app.js" defer></script></head>
<body>
<a class="skip" href="#main">Skip to content</a>
@auth
<aside class="sidebar"><a class="wordmark" href="/dashboard"><span class="mark">S</span> SAHDEV <small>CLOUD</small></a><div class="nav-label">WORKSPACE</div><nav aria-label="Main navigation"><a href="/dashboard" @class(['selected'=>request()->is('dashboard')])>Overview <span>↗</span></a><a href="/billing" @class(['selected'=>request()->is('billing')])>Credits & billing</a><a href="/downloads">Downloads</a><a href="/team" @class(['selected'=>request()->is('team')])>Team & access</a>@if(auth()->user()->is_operator)<div class="nav-label">HOSTINGSPELL</div><a href="/command" @class(['selected'=>request()->is('command*')])>Command Station</a><a href="/command/security">Operator security</a>@endif</nav><form class="mobile-logout" method="post" action="/logout">@csrf<button>Sign out</button></form><div class="sidebar-footer"><span class="avatar">{{ mb_substr(auth()->user()->name,0,1) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->email }}</small></div><form method="post" action="/logout">@csrf<button class="text-button" title="Sign out">↪</button></form></div></aside>
@endauth
<main id="main" @class(['workspace'=>auth()->check(),'auth-shell'=>!auth()->check()])>
@auth<header class="topbar"><span>Hosting operations, connected.</span><span class="environment">{{ app()->isProduction() ? 'Secure workspace' : 'Development environment' }}</span></header>@endauth
@if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert"><strong>Please check the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
<footer class="page-footer">Sahdev by HostingSpell <span>Credit usage and monthly budgets use UTC.</span></footer>
</main></body></html>
