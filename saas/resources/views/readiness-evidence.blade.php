@if($records)
<div class="rr-evidence"><h4>Recorded acceptance evidence</h4><ul>@foreach($records as $record)<li>{{ $record['reference'] }} · {{ $record['reviewer'] }} · {{ $record['reviewed_on'] }}<small>{{ $record['environment'] }} / {{ $record['version'] }}</small></li>@endforeach</ul></div>
@else
<p class="rr-evidence-empty">Acceptance evidence not yet recorded.</p>
@endif
