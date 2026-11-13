<?php
namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConnectorRoutingTest extends TestCase
{
    public function test_cloud_primary_preserves_unknown_usage_instead_of_starting_the_local_fallback(): void
    {
        // Exercise the real WHMCS routing code with its Capsule queries backed by
        // the disposable Laravel database; no provider or installation is contacted.
        if (!class_exists('WHMCS\\Database\\Capsule')) class_alias(DB::class, 'WHMCS\\Database\\Capsule');
        require_once dirname(__DIR__, 3).'/autoload.php';
        require_once dirname(__DIR__, 3).'/lib/AIController.php';
        Schema::create('tblsahdev_providers', function (Blueprint $t) {
            $t->integer('id')->primary(); $t->string('name'); $t->string('provider_type');
            $t->string('model_name'); $t->string('api_url'); $t->string('api_key'); $t->boolean('is_active');
        });
        Schema::create('tbladdonmodules', function (Blueprint $t) {
            $t->string('module'); $t->string('setting'); $t->text('value');
        });
        DB::table('tbladdonmodules')->insert(['module'=>'sahdev','setting'=>'cloud_connection','value'=>encrypt(json_encode([
            'url'=>'https://cloud.example.com','installation_id'=>'00000000-0000-4000-8000-000000000001','secret'=>'fixture-secret',
        ]))]);
        DB::table('tblsahdev_providers')->insert([
            ['id'=>1,'name'=>'Cloud','provider_type'=>'cloud','model_name'=>'00000000-0000-4000-8000-000000000001','api_url'=>'','api_key'=>'','is_active'=>true],
            ['id'=>2,'name'=>'Local fallback','provider_type'=>'lmstudio','model_name'=>'fixture','api_url'=>'https://local.example.com/v1/chat/completions','api_key'=>'','is_active'=>true],
        ]);
        $reflection = new \ReflectionClass(\Sahdev\Lib\AIController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('settings')->setValue($controller,['primary_provider_id'=>1,'fallback_provider_id'=>2]);
        $method = $reflection->getMethod('getProviderStackForTask');
        $stack = $method->invoke($controller,'ticket_reply');
        $this->assertInstanceOf(\Sahdev\Lib\CloudAIProvider::class,$stack['primary']);
        $this->assertNull($stack['fallback']);
        // Existing local provider failover remains available.
        DB::table('tblsahdev_providers')->where('id',1)->update(['provider_type'=>'lmstudio','api_url'=>'https://local.example.com/v1/chat/completions']);
        $stack = $method->invoke($controller,'ticket_reply');
        $this->assertInstanceOf(\Sahdev\Lib\LMStudioAIProvider::class,$stack['fallback']);
    }
}
