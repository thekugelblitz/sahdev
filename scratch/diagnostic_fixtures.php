<?php
// Published API shapes, including negative observations. No upstream requests.
$meta = ['query'=>'example.com', 'duration_ms'=>12, 'timestamp'=>'2026-11-01T10:00:00Z', 'cached'=>false];
return [
 'ping'=>['metadata'=>$meta,'host'=>'example.com','is_alive'=>false,'packets_sent'=>4,'packets_received'=>0,'packet_loss_percent'=>100,'avg_ms'=>null],
 'whois_query'=>['metadata'=>$meta,'record'=>['registrar'=>'Fixture Registrar','creation_date'=>'2020-01-01T00:00:00Z','expiration_date'=>'2030-01-01T00:00:00Z','status'=>['clientHold'],'nameservers'=>['ns1.example.com']]],
 'pagespeed'=>['metadata'=>$meta,'result'=>['strategy'=>'desktop','performance_score'=>42,'metrics'=>['largestContentfulPaint'=>'3.4 s']]],
 'dns_lookup'=>['metadata'=>$meta,'records'=>[['type'=>'MX','name'=>'example.com','value'=>'mail.example.com','ttl'=>60]]],
 'ssl_inspect'=>['metadata'=>$meta,'target'=>'example.com','is_valid'=>false,'subject'=>['commonName'=>'wrong.example.com'],'issuer'=>['commonName'=>'Fixture CA'],'san'=>[],'valid_from'=>'2020-01-01','valid_to'=>'2021-01-01','days_remaining'=>-500,'serial_number'=>'1','fingerprint_sha256'=>'abc'],
 'dns_propagation'=>['metadata'=>$meta,'domain'=>'example.com','record_type'=>'A','servers'=>[['server_name'=>'one','server_ip'=>'8.8.8.8','location'=>'test','records'=>['1.1.1.1']],['server_name'=>'two','server_ip'=>'1.1.1.1','location'=>'test','records'=>['8.8.8.8']]],'total_servers'=>2,'consistent_servers'=>1,'propagation_percent'=>50,'fully_propagated'=>false],
 'traceroute'=>['metadata'=>$meta,'host'=>'example.com','max_hops'=>30,'total_hops'=>0,'reached'=>false,'hops'=>[]],
];
