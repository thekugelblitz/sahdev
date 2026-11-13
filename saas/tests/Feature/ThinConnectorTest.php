<?php
namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use SahdevConnector\{Access,Client,Tickets};
use Tests\TestCase;

class ThinConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('WHMCS\\Database\\Capsule')) class_alias(DB::class,'WHMCS\\Database\\Capsule');
        require_once __DIR__.'/../Support/WhmcsAdmin.php';
        foreach (['Access','Client','Tickets'] as $file) require_once dirname(__DIR__,3).'/connector/sahdev_cloud/src/'.$file.'.php';
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
}
