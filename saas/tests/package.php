<?php
$zip=new ZipArchive(); if ($zip->open($argv[1] ?? '')!==true) throw new RuntimeException('Package missing.');
$hasConnector=false;
for ($i=0;$i<$zip->numFiles;$i++) {
    $name=$zip->getNameIndex($i);
    if (!str_starts_with($name,'sahdev/') || str_contains($name,'..') || preg_match('~(?:^|/)(?:saas|\.env[^/]*|vendor|tests|scratch|design|\.git)(?:/|$)~',$name)) throw new RuntimeException('Private or non-module path in release: '.$name);
    if ($name==='sahdev/lib/CloudConnector.php') $hasConnector=true;
}
if (!$hasConnector) throw new RuntimeException('Connector was not packaged.');
foreach (['sahdev.php','autoload.php','hooks.php','ajax.php','embed.js','toolsapi-2hs-in-openapi.json','lib/CloudAIProvider.php','ui/copilot.js'] as $required) {
    if ($zip->locateName('sahdev/'.$required)===false) throw new RuntimeException('Required module runtime file missing: '.$required);
}
echo 'PASS release contains the WHMCS connector and excludes cloud source, tests, credentials and workspace files.'.PHP_EOL;
