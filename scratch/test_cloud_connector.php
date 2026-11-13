<?php
require_once dirname(__DIR__).'/autoload.php';
require_once dirname(__DIR__).'/lib/CloudAIProvider.php';
use Sahdev\Lib\CloudConnector;
use Sahdev\Lib\CloudAIProvider;
$checks=0;
$check=function ($condition,$message) use (&$checks) { if (!$condition) throw new RuntimeException($message); $checks++; };
class FixtureCloudConnector extends CloudConnector
{
    public $calls=[]; public $fail=false; public $reply;
    public function __construct() {}
    public function infer(array $payload): array {
        $this->calls[]=$payload;
        if ($this->fail) throw new RuntimeException('provider outcome unknown');
        return $this->reply ?: ['content'=>'**Hello**','tool_calls'=>[['id'=>'call1','type'=>'function','function'=>['name'=>'database_select','arguments'=>'{"resource":"invoices"}']]],'model'=>'fixture','usage'=>['prompt_tokens'=>100,'completion_tokens'=>20]];
    }
    public function request(string $method,string $path,array $data=[],bool $signed=true): array { return ['models'=>[['id'=>'00000000-0000-4000-8000-000000000001','name'=>'Fixture']]]; }
}
$transport=new FixtureCloudConnector(); $provider=new CloudAIProvider($transport); $id='00000000-0000-4000-8000-000000000001';
$tools=[['type'=>'function','function'=>['name'=>'database_select','parameters'=>['type'=>'object']]]];
$reply=$provider->generateChat([['role'=>'user','content'=>'Invoices']],$tools,['model_name'=>$id,'max_tokens'=>100]);
$check($reply['content']==='**Hello**','Markdown remains available to the existing safe renderer');
$check($reply['tool_calls'][0]['function']['name']==='database_select','Tool proposals return to the local permission-checked orchestrator');
$check(count($transport->calls)===1,'Provider calls the cloud exactly once');
$check($transport->calls[0]['tools']===$tools,'Only caller-provided tool definitions are sent');
$check($provider->getLastTokenUsage()===120 && $provider->getLastTokenDetails()===['input'=>100,'output'=>20],'Usage preserves native provider contracts');
$check($provider->getAvailableModels('unused')===[$id],'Cloud returns only allowed model UUIDs');
$transport->reply=['content'=>json_encode(['CLIENT_REPLY'=>'Reply','ROOT_CAUSE'=>'Cause','RESPONSIBILITY'=>'Host','RISK_LEVEL'=>'Low','INTERNAL_ACTION_PLAN'=>'Review']),'tool_calls'=>[],'usage'=>['prompt_tokens'=>100,'completion_tokens'=>20]];
$settings=['model_name'=>$id,'max_tokens'=>100,'temperature'=>0.2];
$analysis=$provider->generateResponse(['messages'=>[],'subject'=>'Fixture'],$settings,'professional','');
$check($analysis['CLIENT_REPLY']==='Reply','Ticket analysis preserves its JSON response contract');
$check(is_string($transport->calls[1]['messages'][1]['content']),'Text content is normalized for the metered gateway');
$transport->fail=true; $before=count($transport->calls);
try { $provider->generateResponse(['messages'=>[]],$settings,'professional',''); throw new RuntimeException('Expected failure'); } catch (RuntimeException $e) { $check($e->getMessage()==='provider outcome unknown','Ambiguous outcomes propagate for reconciliation'); }
$check(count($transport->calls)===$before+1,'Ambiguous paid requests are not retried automatically');
try { $provider->generateResponse(['messages'=>[],'attachments_images'=>[['url'=>'https://example.com/image.png']]],$settings,'professional',''); throw new RuntimeException('Expected image rejection'); } catch (RuntimeException $e) { $check(strpos($e->getMessage(),'Image/audio pricing is not enabled')!==false,'Unsupported multimodal billing fails closed'); }
foreach (['http://cloud.example.com','https://localhost','https://cloud.example.com/path','https://user:secret@cloud.example.com','https://cloud.example.com:8443'] as $url) {
    try { CloudConnector::validateBase($url); throw new RuntimeException('Expected URL rejection'); } catch (InvalidArgumentException $e) { $checks++; }
}
$sig=CloudConnector::signature($id,'1800000000',str_repeat('a',32),'POST','/api/v1/inference','{"hello":"world"}','test-secret');
$check($sig!==CloudConnector::signature($id,'1800000000',str_repeat('a',32),'POST','/api/v1/inference','{"hello":"changed"}','test-secret'),'Signature binds the raw body');
$check($sig!==CloudConnector::signature($id,'1800000000',str_repeat('a',32),'GET','/api/v1/inference','{"hello":"world"}','test-secret'),'Signature binds the HTTP method');
echo "Cloud connector checks passed: $checks\n";
