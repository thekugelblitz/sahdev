<?php
namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use App\Services\InstallationService;
use SahdevConnector\{Access,Client,Records,Tickets};
use Tests\TestCase;

class ThinConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('WHMCS\\Database\\Capsule')) class_alias(DB::class,'WHMCS\\Database\\Capsule');
        require_once __DIR__.'/../Support/WhmcsAdmin.php';
        foreach (['Access','Client','Records','Tickets'] as $file) require_once dirname(__DIR__,3).'/connector/sahdev_cloud/src/'.$file.'.php';
        Schema::create('tbladmins',function(Blueprint $t) { $t->integer('id')->primary(); $t->integer('roleid'); $t->boolean('disabled'); $t->string('supportdepts'); });
        Schema::create('tbladdonmodules',function(Blueprint $t) { $t->string('module'); $t->string('setting'); $t->text('value'); });
        Schema::create('tbltickets',function(Blueprint $t) { $t->integer('id')->primary(); $t->integer('did'); $t->integer('userid'); foreach (['title','message','status','date','name'] as $name) $t->text($name); });
        Schema::create('tblticketreplies',function(Blueprint $t) { $t->integer('id')->primary(); $t->integer('tid'); $t->string('admin'); $t->string('date'); $t->text('message'); });
        Schema::create('tblticketdepartments',function(Blueprint $t) { $t->integer('id'); $t->string('name'); });
        Schema::create('mod_sahdev_cloud_requests',function(Blueprint $t) { $t->string('fingerprint')->primary(); $t->integer('admin_id'); $t->integer('ticket_id'); $t->text('payload'); $t->string('remote_id')->nullable(); $t->string('state'); $t->dateTime('created_at'); });
        DB::table('tbladmins')->insert(['id'=>7,'roleid'=>2,'disabled'=>0,'supportdepts'=>'3']);
        DB::table('tbladdonmodules')->insert(['module'=>'sahdev_cloud','setting'=>'access','value'=>'2']);
        DB::table('tbltickets')->insert(['id'=>42,'did'=>3,'userid'=>19,'title'=>'An outage','message'=>'Opening message','status'=>'Open','date'=>'2026-11-14','name'=>'Fixture']);
        DB::table('tblticketdepartments')->insert(['id'=>3,'name'=>'Support']);
        $_SESSION = ['adminid'=>7]; \WHMCS\User\Admin::$permissions=['View Support Ticket'];
    }
    protected function tearDown(): void { $_SESSION=[]; parent::tearDown(); }
    private function denied(callable $operation,int $status): void
    {
        try { $operation(); $this->fail('Expected connector denial'); }
        catch (\RuntimeException $error) { $this->assertSame($status,$error->getCode()); }
    }
    public function test_native_role_department_and_csrf_checks_fail_closed(): void
    {
        $admin = Access::admin(); $this->assertSame(42,(int)Access::ticket(42,$admin)->id);
        $token = Access::token(); Access::csrf($token); $this->denied(fn()=>Access::csrf('bad'),419);
        \WHMCS\User\Admin::$permissions=[]; $this->denied(fn()=>Access::ticket(42,$admin),403);
        \WHMCS\User\Admin::$permissions=['View Support Ticket']; $admin->supportdepts=''; $this->denied(fn()=>Access::ticket(42,$admin),403);
        DB::table('tbladdonmodules')->update(['value'=>'3']); $this->denied(fn()=>Access::admin(),403);
        DB::table('tbladdonmodules')->update(['value'=>'2']); DB::table('tbladmins')->update(['disabled'=>1]); $this->denied(fn()=>Access::admin(),403);
        $_SESSION=[]; $this->denied(fn()=>Access::admin(),403);
    }
    public function test_uncertain_submission_reuses_the_same_snapshot_and_key(): void
    {
        $client = new class extends Client {
            public array $calls=[];
            public function __construct() {}
            public function request(string $method,string $path,array $data=[],bool $signed=true): array
            {
                $this->calls[] = compact('path','data');
                if (count($this->calls)===1) throw new \RuntimeException('Lost response',502);
                return ['id'=>'00000000-0000-4000-8000-000000000009','state'=>str_ends_with($path,'/result') ? 'settled' : 'reserved'];
            }
        };
        $admin=Access::admin(); $ticket=Access::ticket(42,$admin);
        $input=['workflow'=>'ticket.summarize','model_id'=>$this->model->id,'tone'=>'Professional','max_output_tokens'=>100,'actor'=>'admin:999','system_prompt'=>'secret workflow should never be here'];
        $this->denied(fn()=>Tickets::submit($admin,$ticket,$input,$client),502);
        DB::table('tbltickets')->where('id',42)->update(['message'=>'Later change']); $ticket=Access::ticket(42,$admin);
        $result=Tickets::submit($admin,$ticket,$input,$client); Tickets::submit($admin,$ticket,$input,$client);
        $this->assertCount(2,$client->calls); $this->assertSame($client->calls[0]['data'],$client->calls[1]['data']);
        $data=$client->calls[1]['data']; $this->assertSame('admin:7',$data['actor']); $this->assertArrayNotHasKey('system_prompt',$data);
        $this->assertSame('Opening message',$data['context']['messages'][0]['message']);
        $this->assertSame('',DB::table('mod_sahdev_cloud_requests')->value('payload'));
        $wrongAdmin=clone $admin; $wrongAdmin->id=8; $this->denied(fn()=>Tickets::result($wrongAdmin,$ticket,$result['id'],$client),404);
        Tickets::result($admin,$ticket,$result['id'],$client);
        $this->assertSame('settled',DB::table('mod_sahdev_cloud_requests')->value('state'));
    }
    public function test_extraction_limits_context_to_recent_public_replies(): void
    {
        foreach (range(1,25) as $id) DB::table('tblticketreplies')->insert(['id'=>$id,'tid'=>42,'admin'=>$id%2 ? 'Staff' : '','date'=>'2026-11-14','message'=>"<b>Reply $id</b>".str_repeat('x',7000)]);
        $context=Tickets::context(Access::ticket(42,Access::admin()));
        $this->assertCount(21,$context['messages']); $this->assertStringStartsWith('Reply 6',$context['messages'][1]['message']);
        $this->assertSame(6000,mb_strlen($context['messages'][1]['message']));
        $this->assertSame([],$context['invoices']); $this->assertSame([],$context['services']);
    }

    public function test_copilot_record_pages_enforce_native_permissions_and_use_signed_cloud_presentation(): void
    {
        config(['cloud.thin_connector_preview'=>true]);
        DB::table('tbladdonmodules')->insert(['module'=>'sahdev_cloud','setting'=>'copilot_records','value'=>'on']);
        Schema::create('tblclients',function(Blueprint $table) {
            $table->id();
            foreach (['firstname','lastname','companyname','status','email'] as $column) $table->string($column);
            $table->integer('currency');
        });
        Schema::create('tblcurrencies',function(Blueprint $table) { $table->id(); $table->string('code'); });
        Schema::create('tblinvoices',function(Blueprint $table) {
            $table->id(); $table->integer('userid'); $table->decimal('total',10,2);
            foreach (['invoicenum','status','duedate','notes'] as $column) $table->string($column);
        });
        Schema::create('tblhosting',function(Blueprint $table) {
            $table->id(); $table->integer('userid'); $table->integer('packageid');
            foreach (['domain','domainstatus','billingcycle','password'] as $column) $table->string($column);
        });
        Schema::create('tblproducts',function(Blueprint $table) { $table->id(); $table->string('name'); });
        DB::table('tblcurrencies')->insert(['id'=>1,'code'=>'USD']);
        foreach ([19,37] as $id) {
            DB::table('tblclients')->insert(['id'=>$id,'firstname'=>'Sample','lastname'=>'Client','companyname'=>'Example','status'=>'Active','currency'=>1,'email'=>'private@example.test']);
        }
        foreach (range(1,17) as $id) {
            DB::table('tblinvoices')->insert(['id'=>$id,'userid'=>$id===17 ? 37 : 19,'total'=>'4.25','invoicenum'=>'INV-'.$id,'status'=>'Unpaid','duedate'=>'2026-12-01','notes'=>'private invoice note']);
        }
        DB::table('tblproducts')->insert(['id'=>8,'name'=>'Hosting']);
        DB::table('tblhosting')->insert(['id'=>23,'userid'=>19,'packageid'=>8,'domain'=>'example.test','domainstatus'=>'Active','billingcycle'=>'Monthly','password'=>'private service secret']);

        $installations = app(InstallationService::class);
        $issued = $installations->issue($this->brand,$this->owner->id);
        $secret = str_repeat('a',64);
        $installations->enroll($issued['installation_id'],$issued['token'],$this->brand->whmcs_url,'fixture',$secret);
        $installation = DB::table('installations')->where('id',$issued['installation_id'])->first();
        $signedCall = function (string $method, string $path, array $input = []) use ($installation,$secret) {
            $body = $method === 'GET' ? '' : json_encode($input,JSON_THROW_ON_ERROR);
            $headers = $this->signedHeaders($installation,$secret,$method,$path,$body)+['Content-Type'=>'application/json'];
            return $this->call($method,$path,[],[],[],$this->transformHeadersToServerVars($headers),$body);
        };
        $client = new class($signedCall) extends Client {
            public array $payloads = [];
            private $send;
            public function __construct(callable $send) { $this->send = $send; }
            public function request(string $method, string $path, array $data = [], bool $signed = true): array
            {
                $this->payloads[] = $data;
                return ($this->send)($method,$path,$data)->assertOk()->json();
            }
        };
        $signedCall('GET','/api/v1/connector-status')->assertOk()
            ->assertJsonPath('preview_enabled',true)->assertJsonPath('brand_database_ready',true)->assertJsonPath('models',1);
        $admin = Access::admin();
        $this->denied(fn()=>Records::search($admin,'Sample Client'),403);
        \WHMCS\User\Admin::$permissions = ['List Clients','View Clients Summary','View Invoice','View Clients Products/Services'];
        $this->assertSame([19,37],array_column(Records::search($admin,'Sample Client')['clients'],'id'));
        $this->assertSame([],Records::search($admin,'%')['clients']);

        $request = ['resource'=>'invoices','client_id'=>19,'page'=>1,'actor'=>'admin:99'];
        $first = Records::page($admin,$request,$client);
        $this->assertCount(15,$first['rows']);
        $this->assertTrue($first['has_more']);
        $this->assertSame(19,$first['client']['record']['id']);
        $this->assertSame(16,$first['rows'][0][0]['record']['id']);
        $this->assertSame('USD',substr($first['rows'][0][2]['text'],-3));
        $this->assertSame('admin:7',$client->payloads[0]['actor']);
        $this->assertStringNotContainsString('private',json_encode($client->payloads[0]));
        $request['page'] = 2;
        $second = Records::page($admin,$request,$client);
        $this->assertFalse($second['has_more']);
        $this->assertCount(1,$second['rows']);
        $this->assertSame(1,$second['rows'][0][0]['record']['id']);

        $services = ['resource'=>'services','client_id'=>19,'page'=>1];
        $service = Records::page($admin,$services,$client);
        $this->assertSame(['type'=>'service','id'=>23,'client_id'=>19],$service['rows'][0][0]['record']);
        $this->assertArrayNotHasKey('record',$service['rows'][0][1]);
        \WHMCS\User\Admin::$permissions[] = 'Configure Products/Services';
        $this->assertSame('product',Records::page($admin,$services,$client)['rows'][0][1]['record']['type']);
        \WHMCS\User\Admin::$permissions = ['List Clients','View Clients Summary'];
        $this->denied(fn()=>Records::page($admin,$request,$client),403);
        $this->denied(fn()=>Records::page($admin,$services,$client),403);
        $this->assertCount(4,$client->payloads);
        $this->assertDatabaseCount('reservations',0);
        $this->assertSame(4,DB::table('audit_events')->where('event','copilot.records_read')->count());

        $path = '/api/v1/copilot/records';
        $this->postJson($path,$client->payloads[0])->assertUnauthorized();
        $bad = $client->payloads[0];
        $bad['rows'][0]['notes'] = 'unapproved field';
        $signedCall('POST',$path,$bad)->assertUnprocessable();
        config(['cloud.thin_connector_preview'=>false]);
        $signedCall('POST',$path,$client->payloads[0])->assertStatus(503);
        config(['cloud.thin_connector_preview'=>true]);
        DB::table('brands')->where('id',$this->brand->id)->update(['status'=>'suspended']);
        $signedCall('POST',$path,$client->payloads[0])->assertStatus(423);
    }
}
