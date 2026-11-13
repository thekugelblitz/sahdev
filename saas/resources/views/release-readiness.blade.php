@extends('layout')
@section('title','Release readiness')
@section('content')
<p class="eyebrow">COMMAND STATION · DISTRIBUTION</p><h1>Public release readiness.</h1>
<p class="muted">A running gateway is one part of the product. Public signup and stable downloads stay closed until the connector and every feature domain are verified.</p>
<section class="panel"><h2>Release checks</h2><div class="table-wrap"><table><thead><tr><th>Check</th><th>Status</th><th>Evidence needed</th></tr></thead><tbody>@foreach($checks as $check)<tr><td>{{ $check['label'] }}</td><td>{{ $check['ready'] ? 'Configured' : 'Pending' }}</td><td>{{ $check['detail'] }}</td></tr>@endforeach</tbody></table></div></section>
<section class="panel"><h2>Feature migration</h2><p>Implemented means code exists and has automated coverage. Verified means the complete feature has also passed its WHMCS and deployment acceptance tests.</p><div class="table-wrap"><table><thead><tr><th>Feature domain</th><th>Status</th></tr></thead><tbody>@foreach($features as $feature)<tr><td>{{ $feature['label'] }}</td><td>{{ ucfirst(str_replace('_',' ',$feature['status'])) }}</td></tr>@endforeach</tbody></table></div></section>
<p>Release evidence lives in the versioned migration register. Changing an environment flag cannot bypass incomplete feature migration. No passwords or API credentials are shown here.</p>
@endsection
