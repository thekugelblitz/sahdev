<?php
namespace Tests\Feature;

use App\Services\{LaunchReadiness, MigrationRegister, ReadinessReport};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadinessReportTest extends TestCase
{
    private function operator(): void
    {
        $this->owner->forceFill(['is_operator'=>true])->save();
        $this->actingAs($this->owner);
    }

    public function test_report_and_export_require_verified_operator_access(): void
    {
        foreach (['/command/release', '/command/release/export'] as $url) $this->get($url)->assertRedirect('/command/login');
        $this->actingAs($this->owner);
        foreach (['/command/release', '/command/release/export'] as $url) $this->get($url)->assertForbidden();
        $this->operator();
        $this->get('/command/release')->assertOk()->assertSee('176')->assertSee('End-to-end flows')->assertSee('PUBLIC LAUNCH BLOCKED');
        DB::table('operator_security')->where('id',1)->update(['mfa_required'=>true]);
        $this->get('/command/release/export')->assertRedirect('/command/security');
        $this->owner->forceFill(['email_verified_at'=>null])->save();
        $this->get('/command/release/export')->assertRedirect('/verify-email');
    }

    public function test_filters_search_actual_capabilities_and_do_not_change_launch_totals(): void
    {
        $this->operator();
        $response = $this->get('/command/release?domain=safe_operations&status=pending&q=rollback');
        $response->assertOk()->assertSee('Atomic')->assertViewHas('filtered', function ($filtered) {
            return array_keys($filtered['domains']) === ['safe_operations'] && $filtered['capabilities'] > 0 && count($filtered['journeys']) > 0;
        });
        $response->assertViewHas('report', fn ($report) => $report['summary']['capabilities'] === 176 && !$report['ready']);
        $this->get('/command/release?status=verified')->assertOk()->assertSee('No matching capabilities')->assertSee('No matching flows')->assertSee('PUBLIC LAUNCH BLOCKED');
        $this->get('/command/release?domain=safe_operations&surface=Mobile%20app')->assertViewHas('filtered', fn ($f) => $f['capabilities'] === 0 && count($f['journeys']) === 0);
        $this->get('/command/release?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        $this->getJson('/command/release?status=invented')->assertUnprocessable();
        $this->getJson('/command/release?q[]=bad')->assertUnprocessable();
    }

    public function test_export_contains_full_register_and_only_safe_configuration_results(): void
    {
        $this->operator();
        config(['cloud.billing_secret'=>'private-billing-sentinel', 'cloud.billing_webhook_secret'=>str_repeat('sensitive-webhook', 3)]);
        $response = $this->get('/command/release/export?domain=safe_operations');
        $response->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="sahdev-release-readiness.json"')
            ->assertJsonPath('schema_version', 1)->assertJsonPath('summary.domains', 22)->assertJsonPath('summary.capabilities', 176)
            ->assertJsonPath('summary.journeys', 31)->assertJsonPath('ready', false)->assertJsonCount(9, 'register.database_resources');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach (['fixture-api-secret', 'private-billing-sentinel', 'sensitive-webhook', $this->owner->email, $this->brand->id] as $secret) $response->assertDontSee($secret, false);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->json('register_revision'));
    }

    public function test_register_has_unique_ids_real_references_and_connected_journeys(): void
    {
        $register = config('migration');
        $capabilities = [];
        foreach ($register['features'] as $id => $domain) {
            $this->assertArrayHasKey($domain['status'], MigrationRegister::STATUSES);
            $this->assertNotEmpty($domain['capabilities']);
            foreach (array_merge($domain['source'], $domain['tests']) as $path) {
                $this->assertFileOrDirectoryExists(base_path('../'.$path));
            }
            foreach ($domain['capabilities'] as $capability) {
                $ref = $id.'.'.$capability['id'];
                $this->assertArrayNotHasKey($ref, $capabilities, 'Duplicate capability '.$ref);
                $this->assertArrayHasKey($capability['status'], MigrationRegister::STATUSES);
                $this->assertNotEmpty($capability['current']);
                $this->assertNotEmpty($capability['acceptance']);
                $capabilities[$ref] = true;
            }
        }
        $journeys = [];
        foreach ($register['journeys'] as $flow) {
            $this->assertArrayNotHasKey($flow['id'], $journeys);
            $journeys[$flow['id']] = true;
            $this->assertNotEmpty($flow['steps']);
            $this->assertNotEmpty($flow['failure_checks']);
            $this->assertArrayHasKey($flow['status'], MigrationRegister::STATUSES);
            foreach ($flow['covers'] as $ref) $this->assertArrayHasKey($ref, $capabilities, 'Missing capability '.$ref);
        }
        foreach ($register['release_sequence'] as $phase) foreach ($phase['domains'] as $id) $this->assertArrayHasKey($id, $register['features']);
        $snapshot = app(ReadinessReport::class)->snapshot();
        $this->assertSame(count($capabilities), $snapshot['summary']['capabilities']);
        $this->assertSame(count($journeys), $snapshot['summary']['journeys']);
    }

    private function assertFileOrDirectoryExists(string $path): void
    {
        $this->assertTrue(file_exists($path), 'Missing inventory reference: '.$path);
    }

    public function test_parent_status_cannot_bypass_capabilities_journeys_or_acceptance_evidence(): void
    {
        $evidence = [['reference'=>'acceptance/123', 'reviewer'=>'Release reviewer', 'reviewed_on'=>'2026-11-20', 'environment'=>'WHMCS staging', 'version'=>'fixture']];
        $accepted = ['status'=>'verified', 'evidence'=>$evidence];
        $register = ['features'=>['one'=>$accepted + ['capabilities'=>[$accepted]]], 'journeys'=>[$accepted]];
        config(['migration'=>$register]);
        $this->assertTrue(app(LaunchReadiness::class)->migrationComplete());
        $changed = $register;
        $changed['features']['one']['capabilities'][0]['status'] = 'implemented';
        $this->assertFalse(MigrationRegister::complete($changed));
        $changed = $register;
        $changed['journeys'][0]['status'] = 'pending';
        $this->assertFalse(MigrationRegister::complete($changed));
        $changed = $register;
        $changed['features']['one']['capabilities'][0]['evidence'] = [];
        $this->assertFalse(MigrationRegister::complete($changed));
        $changed = $register;
        unset($changed['journeys'][0]['evidence'][0]['version']);
        $this->assertFalse(MigrationRegister::complete($changed));
        $this->assertFalse(MigrationRegister::complete(['features'=>['one'=>$accepted]]));
        $this->assertFalse(MigrationRegister::complete([]));
    }
}
