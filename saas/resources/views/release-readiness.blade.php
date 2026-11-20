@extends('layout')
@section('title','Release readiness')
@push('head')
<link rel="stylesheet" href="/readiness.css"><script src="/readiness.js" defer></script>
@endpush
@section('content')
<div class="readiness">
    <header class="rr-heading">
        <div><p class="eyebrow">COMMAND STATION / RELEASE CONTROL</p><h1>Public release readiness.</h1><p class="muted">The complete path from the WHMCS module to Sahdev Cloud.<br>Track what exists, what remains, and what must pass before launch.</p></div>
        <div class="rr-actions"><a class="button" href="/command/release/export">Download full JSON report</a><button type="button" data-rr-print hidden>Print report</button></div>
    </header>

    <section class="rr-gate {{ $report['ready'] ? 'is-ready' : '' }}" aria-label="Release decision">
        <div><span class="rr-gate-label">{{ $report['ready'] ? 'RELEASE CHECKS PASSED' : 'PUBLIC LAUNCH BLOCKED' }}</span><h2>{{ $report['ready'] ? 'Ready for the release operator.' : 'Acceptance evidence is still required.' }}</h2><p>Signup, purchases and stable connector downloads depend on the full register and live checks. Filtering this page never changes release eligibility.</p></div>
        <a href="#release-checks">Review live checks ↓</a>
    </section>

    <div class="rr-metrics" aria-label="Full register totals">
        <div><strong>{{ $report['summary']['domains'] }}</strong><span>feature domains</span></div>
        <div><strong>{{ $report['summary']['capabilities'] }}</strong><span>capabilities inventoried</span></div>
        <div><strong>{{ $report['summary']['statuses']['verified'] }}<small> / {{ $report['summary']['capabilities'] }}</small></strong><span>capabilities verified</span></div>
        <div><strong>{{ $report['summary']['verified_journeys'] }}<small> / {{ $report['summary']['journeys'] }}</small></strong><span>journeys accepted</span></div>
        <div><strong>{{ $report['summary']['ready_checks'] }}<small> / {{ $report['summary']['checks'] }}</small></strong><span>live checks passed</span></div>
    </div>

    <nav class="rr-nav" aria-label="Readiness sections"><a href="#feature-register">Feature register</a><a href="#journeys">End-to-end flows</a><a href="#database-scope">Database access</a><a href="#release-sequence">Launch sequence</a><a href="#evidence">Verification evidence</a></nav>

    <section id="release-checks" class="rr-section">
        <div class="rr-section-title"><div><p class="eyebrow">01 / LIVE STATE</p><h2>Release checks</h2></div><p class="muted">Checked {{ \Carbon\Carbon::parse($report['generated_at'])->format('d M Y, H:i') }} UTC · <a href="{{ url()->full() }}">Refresh</a></p></div>
        <p class="muted">Configuration is checked at page load. A configured integration still needs its real-world acceptance flow below.</p>
        <div class="rr-checks">
            @foreach($report['checks'] as $id => $check)
            <article class="rr-check"><div class="rr-check-title"><h3>{{ $check['label'] }}</h3><span class="rr-badge {{ $check['ready'] ? 'verified' : 'partial' }}">{{ $check['ready'] ? 'Passed' : 'Pending' }}</span></div><p>{{ $check['detail'] }}</p></article>
            @endforeach
        </div>
    </section>

    <section id="feature-register" class="rr-section">
        <div class="rr-section-title"><div><p class="eyebrow">02 / PRODUCT COVERAGE</p><h2>Feature register</h2></div><div class="rr-actions"><button type="button" data-rr-expand hidden>Expand results</button><button type="button" data-rr-collapse hidden>Collapse results</button></div></div>
        <p class="muted">{{ $report['register']['scope'] }}</p>
        <details class="rr-legend"><summary>What each status means</summary><dl>@foreach($report['statuses'] as $id => $status)<div><dt><span class="rr-badge {{ $id }}">{{ $status['label'] }}</span> <strong>{{ $report['summary']['statuses'][$id] }}</strong></dt><dd>{{ $status['description'] }}</dd></div>@endforeach</dl></details>
        <form class="rr-filters" method="get" action="/command/release#feature-register" role="search" aria-label="Filter readiness register">
            <div class="field"><label for="rr-q">Search capabilities and flows</label><input type="search" id="rr-q" name="q" maxlength="160" value="{{ $filters['q'] ?? '' }}" placeholder="Try rollback, invoices, mobile…"></div>
            <div class="field"><label for="rr-status">Status</label><select id="rr-status" name="status"><option value="">All statuses</option>@foreach($report['statuses'] as $id => $status)<option value="{{ $id }}" @selected(($filters['status'] ?? '') === $id)>{{ $status['label'] }}</option>@endforeach</select></div>
            <div class="field"><label for="rr-domain">Feature domain</label><select id="rr-domain" name="domain"><option value="">All domains</option>@foreach($report['register']['features'] as $id => $domain)<option value="{{ $id }}" @selected(($filters['domain'] ?? '') === $id)>{{ $domain['label'] }}</option>@endforeach</select></div>
            <div class="field"><label for="rr-surface">Product surface</label><select id="rr-surface" name="surface"><option value="">All surfaces</option>@foreach($surfaces as $surface)<option @selected(($filters['surface'] ?? '') === $surface)>{{ $surface }}</option>@endforeach</select></div>
            <div class="rr-filter-actions"><button class="primary">Apply filters</button><a href="/command/release#feature-register">Reset</a></div>
        </form>
        <p class="rr-results" role="status">Showing <strong>{{ $filtered['capabilities'] }}</strong> of {{ $report['summary']['capabilities'] }} capabilities across {{ count($filtered['domains']) }} domains, and <strong>{{ count($filtered['journeys']) }}</strong> of {{ $report['summary']['journeys'] }} flows. Totals and launch checks always use the full register.</p>
        <div class="rr-domain-list">
        @forelse($filtered['domains'] as $id => $domain)
            <details id="domain-{{ $id }}" class="rr-domain rr-expandable" @if(array_filter($filters)) open @endif>
                <summary><span class="rr-domain-title"><strong>{{ $domain['label'] }}</strong><span>{{ $domain['surface'] }} · {{ count($domain['capabilities']) }} {{ array_filter($filters) ? 'matching' : '' }} capabilities</span></span><span class="rr-domain-count">{{ $domain['counts']['verified'] }} verified</span><span class="rr-badge {{ $domain['status'] }}">{{ $report['statuses'][$domain['status']]['label'] }}</span></summary>
                <div class="rr-domain-content"><p>{{ $domain['summary'] }}</p><p class="muted">Responsible discipline: {{ $domain['owner'] }}</p>
                    @foreach($domain['capabilities'] as $capability)
                    <article class="rr-capability" id="capability-{{ $id }}-{{ $capability['id'] }}">
                        <div class="rr-capability-title"><h3>{{ $capability['label'] }}</h3><span class="rr-badge {{ $capability['status'] }}">{{ $report['statuses'][$capability['status']]['label'] }}</span></div>
                        <p>{{ $capability['current'] }}</p><h4>Acceptance criteria</h4><ul>@foreach($capability['acceptance'] as $criterion)<li>{{ $criterion }}</li>@endforeach</ul>
                        @include('readiness-evidence',['records'=>$capability['evidence']])
                        <a class="rr-permalink" href="/command/release#capability-{{ $id }}-{{ $capability['id'] }}">{{ $id }}.{{ $capability['id'] }} ↗</a>
                    </article>
                    @endforeach
                    <details class="rr-sources"><summary>Code, baseline tests and domain evidence</summary><p class="muted">References locate existing implementation and tests. They do not prove SaaS migration or a passing live test.</p><div class="rr-reference-grid"><div><h4>Code references</h4><ul>@foreach($domain['source'] as $source)<li><code>{{ $source }}</code></li>@endforeach</ul></div><div><h4>Baseline test references</h4><ul>@forelse($domain['tests'] as $test)<li><code>{{ $test }}</code></li>@empty<li>No dedicated test reference recorded.</li>@endforelse</ul></div></div>@include('readiness-evidence',['records'=>$domain['evidence']])</details>
                </div>
            </details>
        @empty
            <div class="rr-empty"><h3>No matching capabilities</h3><p>Broaden the search or <a href="/command/release#feature-register">reset the filters</a>. Matching end-to-end flows may still appear below.</p></div>
        @endforelse
        </div>
    </section>

    <section id="journeys" class="rr-section">
        <div class="rr-section-title"><div><p class="eyebrow">03 / ACCEPTANCE JOURNEYS</p><h2>End-to-end flows</h2></div><span class="muted">{{ count($filtered['journeys']) }} matching flows</span></div>
        <p class="muted">A feature is only accepted when the complete journey works with its permissions, billing, failure and recovery paths. The filters above apply here too.</p>
        @forelse($filtered['journeys'] as $flow)
        <details class="rr-flow rr-expandable" id="flow-{{ $flow['id'] }}"><summary><span class="rr-domain-title"><strong>{{ $flow['label'] }}</strong><span>{{ implode(' / ', $flow['actors']) }}</span></span><span class="rr-badge {{ $flow['status'] }}">{{ $report['statuses'][$flow['status']]['label'] }}</span></summary><div class="rr-flow-content"><div class="rr-flow-grid"><div><h4>Successful path</h4><ol>@foreach($flow['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol></div><div class="rr-failure"><h4>Failure and recovery checks</h4><ul>@foreach($flow['failure_checks'] as $failure)<li>{{ $failure }}</li>@endforeach</ul></div></div><h4>Capabilities covered</h4><div class="rr-links">@foreach($flow['covers'] as $ref)<a href="/command/release#capability-{{ str_replace('.', '-', $ref) }}">{{ $report['capability_labels'][$ref] }} ↗</a>@endforeach</div>@include('readiness-evidence',['records'=>$flow['evidence']])</div></details>
        @empty
        <div class="rr-empty"><h3>No matching flows</h3><p>Adjust the filters to see other acceptance journeys.</p></div>
        @endforelse
    </section>

    <section id="database-scope" class="rr-section">
        <p class="eyebrow">04 / DATABASE BOUNDARIES</p><h2>Database access and rollback</h2><p class="muted">Full legacy policy inventory, unaffected by filters. These operations are <strong>not yet enabled in the SaaS connector</strong>. Each requires an explicit role grant, field scope and acceptance evidence. This is not arbitrary SQL access.</p>
        <div class="rr-db-rules"><p><strong>Before approval</strong>Exact records, before/after values, allowed fields, affected row counts and external effects must be shown.</p><p><strong>During execution</strong>Recheck permissions and row versions, then write the operation and rollback snapshot in one transaction.</p><p><strong>During rollback</strong>Restore all covered rows atomically only when unchanged since the action. Report conflicts; external effects need a separate recovery plan.</p></div>
        <div class="table-wrap rr-db-table" tabindex="0" role="region" aria-label="Scrollable database resource matrix"><table><caption>Legacy resource policies · all require cloud migration acceptance</caption><thead><tr><th>Resource / scope</th><th>Allowed operation types</th><th>Writable fields</th><th>Required boundary</th></tr></thead><tbody>@foreach($report['register']['database_resources'] as $resource)<tr><th scope="row">{{ $resource['label'] }}<small><code>{{ $resource['table'] }}</code><br>{{ $resource['scope'] }}</small></th><td>{{ implode(', ', $resource['operations']) }}</td><td>{{ $resource['writable_fields'] ? implode(', ', $resource['writable_fields']) : 'Read only' }}</td><td>{{ $resource['notes'] }}</td></tr>@endforeach</tbody></table></div>
    </section>

    <section id="release-sequence" class="rr-section"><p class="eyebrow">05 / LAUNCH ORDER</p><h2>What has to happen next</h2><div class="rr-sequence">@foreach($report['register']['release_sequence'] as $phase)<article><span>{{ str_pad($loop->iteration,2,'0',STR_PAD_LEFT) }}</span><div><h3>{{ $phase['label'] }}</h3><p>{{ $phase['detail'] }}</p><div class="rr-links">@foreach($phase['domains'] as $ref)<a href="/command/release#domain-{{ $ref }}">{{ $report['register']['features'][$ref]['label'] }} ↗</a>@endforeach</div></div></article>@endforeach</div></section>

    <section id="evidence" class="rr-section panel"><p class="eyebrow">06 / VERIFICATION RECORD</p><h2>What counts as accepted evidence?</h2><ul>@foreach($report['register']['evidence_requirements'] as $requirement)<li>{{ $requirement }}</li>@endforeach</ul><p>A reviewer records evidence against the domain, individual capability and end-to-end flow in the versioned release register. All three must be verified. A top-level status alone cannot open registration or publish a stable connector.</p><p class="muted">This page is a read-only report. It does not execute WHMCS operations or change acceptance statuses. JSON exports contain the full register and live check results, regardless of active filters.</p><details><summary>Register version and maintenance</summary><p>Inventory reviewed: {{ $report['register']['reviewed_on'] }}. Source: <code>saas/config/release-register.json</code>. Changes are reviewed and deployed with the application.</p><p>For each accepted item, attach evidence with <code>reference</code>, <code>reviewer</code>, <code>reviewed_on</code>, <code>environment</code> and <code>version</code>. Keep private evidence in an access-controlled system and reference its record ID.</p><p>Register fingerprint: <code class="rr-hash">{{ $report['register_revision'] }}</code></p></details></section>
</div>
@endsection
