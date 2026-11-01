<?php
namespace Sahdev\Lib {
    // Capture the real provider's HTTP options without network access or credentials.
    class DiagnosticHttpFixture { public static $requests=[]; public static $body=''; public static $error=''; }
    function curl_init($url) { return (object) ['url'=>$url,'options'=>[]]; }
    function curl_setopt($h,$key,$value) { $h->options[$key]=$value; return true; }
    function curl_setopt_array($h,$options) { $h->options=$options; return true; }
    function curl_exec($h) { DiagnosticHttpFixture::$requests[]=$h; return DiagnosticHttpFixture::$body; }
    function curl_error($h) { return DiagnosticHttpFixture::$error; }
    function curl_errno($h) { return DiagnosticHttpFixture::$error === '' ? 0 : 28; }
    function curl_getinfo($h,$key=null) { return 200; }
    function curl_close($h) {}
}
namespace {
    require __DIR__.'/../lib/AIProviderInterface.php';
    foreach (['Google','Replicate','OpenRouter','LMStudio'] as $name) require __DIR__.'/../lib/'.$name.'AIProvider.php';
    use Sahdev\Lib\DiagnosticHttpFixture as H;
    $count=0;
    function expect($ok,$name) { global $count; $count++; if (!$ok) throw new RuntimeException($name); }
    set_error_handler(static function($level,$message,$file,$line) { if ($level & error_reporting()) throw new ErrorException($message,0,$level,$file,$line); });
    $settings=['model_name'=>'fixture','temperature'=>0,'max_tokens'=>100,'timeout'=>6,'system_prompt'=>'Return JSON plan','user_prompt_template'=>'{{MESSAGES}}'];
    $context=['__autopilot_raw_reply__'=>true,'messages'=>[['admin'=>false,'date'=>'','message'=>'request']]];
    H::$body=json_encode(['candidates'=>[['content'=>['parts'=>[['text'=>'{"checks":[]}']]]]]]);
    $google=new \Sahdev\Lib\GoogleAIProvider('fixture-key');
    expect($google->generateResponse($context,$settings,'Professional','')['__raw_text__']==='{"checks":[]}','Google raw plan response contract');
    expect(H::$requests[0]->options[CURLOPT_TIMEOUT]===6,'Google uses caller budget');
    H::$requests=[]; H::$error='fixture timeout';
    try { $google->generateResponse($context,$settings,'Professional',''); throw new RuntimeException('Expected failure'); } catch (Exception $e) { expect(count(H::$requests)===1,'Google bounded call never retries paid POST'); }
    H::$error=''; H::$requests=[];H::$body=json_encode(['status'=>'succeeded','output'=>['{"checks":[]}']]);
    $replicate=new \Sahdev\Lib\ReplicateAIProvider('https://api.replicate.com/v1/models/fixture/model/predictions','fixture-key');
    expect($replicate->generateResponse($context,$settings,'Professional','')['__raw_text__']==='{"checks":[]}','Replicate raw response contract');
    expect(H::$requests[0]->options[CURLOPT_TIMEOUT_MS]<=6000,'Replicate uses total deadline');
    H::$requests=[];H::$body=json_encode(['status'=>'starting','urls'=>['get'=>'https://attacker.example/prediction']]);
    try { $replicate->generateResponse($context,$settings,'Professional',''); throw new RuntimeException('Expected failure'); } catch (Exception $e) { expect(count(H::$requests)===1,'Replicate never forwards credentials to arbitrary polling host or retries creation'); }
    H::$requests=[];H::$body=json_encode(['choices'=>[['message'=>['content'=>'{"checks":[]}']]],'usage'=>['prompt_tokens'=>20,'completion_tokens'=>5]]);
    foreach ([new \Sahdev\Lib\OpenRouterAIProvider('fixture-key'),new \Sahdev\Lib\LMStudioAIProvider('https://fixture.example/v1/chat/completions','fixture-key')] as $provider) {
        expect($provider->generateChat([['role'=>'user','content'=>'request']],[],$settings)['content']==='{"checks":[]}','OpenAI-format raw plan response');
        $last=end(H::$requests);expect($last->options[CURLOPT_TIMEOUT]===6,'OpenAI-format provider uses caller timeout');
    }
    echo "Diagnostic providers: $count checks passed\n";
}
