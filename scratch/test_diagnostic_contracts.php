<?php
require_once __DIR__ . '/../lib/DiagnosticWorkflow.php';
use Sahdev\Lib\DiagnosticWorkflow as W;
use Sahdev\Lib\DiagnosticEvidence as E;
use Sahdev\Lib\DiagnosticPolicy as P;
use Sahdev\Lib\LiveChatToolService as T;
set_error_handler(static function ($severity, $message, $file, $line) { if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line); });
$count = 0;
function check($condition, $name) { global $count; $count++; if (!$condition) throw new RuntimeException($name); }
$fixtures = require __DIR__ . '/diagnostic_fixtures.php';
foreach ($fixtures as $tool => $data) {
    $path = T::getToolRegistry()['toolsapi_' . $tool]['_meta']['path'];
    check(E::decode($path, json_encode($data))['valid'], $tool . ' contract');
    $summary = json_decode(E::summary($tool, $path, json_encode($data), 'example.com'), true);
    check(isset($summary['facts']), $tool . ' structured evidence');
    check($summary['observed_at'] === $data['metadata']['timestamp'], $tool . ' freshness retained');
}
$ping = json_decode(E::summary('ping','/ping/{host}',json_encode($fixtures['ping']),'example.com'),true);
check($ping['facts']['is_alive'] === false && $ping['facts']['packet_loss_percent'] === 100, 'Unreachable ping must stay unreachable');
check(!E::decode('/ping/{host}', '{}')['valid'], 'Missing data must not become healthy');
check(!E::decode('/dnsbl/{ip}', '{"error":"rate limited"}')['valid'], '200 error body must not become clean');
check(!E::decode('/ssl/{target}', '<html>error</html>')['valid'], 'Reject non JSON');
check(E::decode('/ping/example.com',json_encode($fixtures['ping']))['valid'], 'Concrete path resolves response contract');
$entities = W::entities('Email sales@example.com; https://a.b.example.com/help?q=1 and 2001:4860:4860::8888');
check(count($entities) === 3, 'Email, deep subdomain URL and IPv6 extracted');
check($entities[0]['target'] === 'example.com', 'Email local part not sent to diagnostics');
check($entities[1]['target'] === 'https://a.b.example.com/help?q=1', 'URL path and query retained');
check(W::entities('999.999.999.999') === [], 'Invalid IP excluded');
check(W::intent('My website is not working') === 'web', 'Initial website issue');
check(W::intent('example.com') === '', 'Target-only message needs conversational state');
check(W::recipe('email',$entities[0],'email not working')[0]['args']['type'] === 'MX', 'Email selects MX rather than A');
$consumed=[];$query=[];
check(strpos(T::buildUpstreamUrl('https://toolsapi.test','/dns/{domain}',['domain'=>'example.com','type'=>'MX'],$consumed,$query),'type=MX') !== false,'Preserve MX request');
T::setEnabledOverride(true);
foreach ([['tool'=>'toolsapi_ping','args'=>['host'=>'8.8.8.8','count'=>100]], ['tool'=>'toolsapi_pagespeed','args'=>['url'=>'http://127.0.0.1','target'=>'8.8.8.8']], ['tool'=>'toolsapi_dns_lookup','args'=>['domain'=>'8.8.8.8','type'=>'AXFR']]] as $case) {
    check(!T::executeToolCall($case['tool'],$case['args'],0)['success'],'Reject invalid and conflicting arguments before execution');
}
check(T::validateTargetSecurity('https://[::1]/')['valid'] === false, 'IPv6 loopback blocked');
check(T::validateTargetSecurity('2001:4860:4860::8888')['valid'] === true, 'Public IPv6 retained');
$allowed = T::getToolRegistry();
try { W::validatePlan(['checks'=>[['tool'=>'toolsapi_ping','args'=>['host'=>'evil.example']]]],$allowed,$entities[0],2); check(false,'Target change rejected'); } catch (RuntimeException $e) { check(true,'Planner cannot change target'); }
check(P::normalize(['max_checks'=>999,'deadline_seconds'=>999])['max_checks'] === 5,'Hard execution cap');
check(P::normalize(['categories'=>[]])['categories'] === [],'All categories can be disabled');
echo "Diagnostic contracts: {$count} checks passed\n";
