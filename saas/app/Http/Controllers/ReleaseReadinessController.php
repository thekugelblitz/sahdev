<?php
namespace App\Http\Controllers;

use App\Services\{MigrationRegister, ReadinessReport};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ReleaseReadinessController
{
    public function index(Request $request, ReadinessReport $reports)
    {
        $domains = config('migration.features');
        $surfaces = array_values(array_unique(array_column($domains, 'surface')));
        sort($surfaces);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string', Rule::in(array_keys(MigrationRegister::STATUSES))],
            'domain' => ['nullable', 'string', Rule::in(array_keys($domains))],
            'surface' => ['nullable', 'string', Rule::in($surfaces)],
        ]);
        $filters = array_map(fn ($value) => $value ?? '', $filters);
        $report = $reports->snapshot();
        return response()->view('release-readiness', [
            'report' => $report, 'filtered' => $reports->filter($report, $filters),
            'filters' => $filters, 'surfaces' => $surfaces,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function export(ReadinessReport $reports)
    {
        // Explicit report fields only. Never serialize application configuration or database rows.
        return response()->json($reports->snapshot(), 200, [
            'Content-Disposition' => 'attachment; filename="sahdev-release-readiness.json"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
