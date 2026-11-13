<?php
/** Build from versioned files only. Signing uses a key supplied through the process environment, never the repository. */
$root = realpath(__DIR__.'/../..');
$version = $argv[1] ?? ''; $target = $argv[2] ?? ''; $unsigned = in_array('--unsigned',$argv,true);
if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,40}$/D',$version) || !$target) throw new RuntimeException('Usage: php tools/package-module.php VERSION OUTPUT_DIRECTORY [--unsigned]');
if (!is_dir($target) && !mkdir($target,0700,true)) throw new RuntimeException('Cannot create output directory.');
$target=realpath($target); $name='sahdev-module-'.$version.'.zip';
$pipes=[]; $process=proc_open(['git','ls-files','-z'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
fclose($pipes[0]); $files=explode("\0",stream_get_contents($pipes[1])); fclose($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[2]);
if (proc_close($process)!==0) throw new RuntimeException('Cannot enumerate versioned module files.');
$zip=new ZipArchive(); if ($zip->open($target.'/'.$name,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Cannot open package.');
$count=0; $hasConnector=false;
foreach ($files as $file) {
    if (!preg_match('~^(?:sahdev\.php|autoload\.php|hooks\.php|ajax\.php|embed\.js|toolsapi-2hs-in-openapi\.json|(?:lib|controllers|modules|templates|ui|knowledgebase|cron)/.+)$~D',$file)) continue;
    if (preg_match('~(?:^|/)(?:\.env[^/]*|[^/]+\.(?:key|pem|log))$~i',$file)) throw new RuntimeException('Private file found in the module allowlist.');
    $path=realpath($root.'/'.$file);
    if (!$path || !str_starts_with(str_replace('\\','/',$path),str_replace('\\','/',$root).'/') || is_link($root.'/'.$file)) throw new RuntimeException('Invalid module source path.');
    $zip->addFile($path,'sahdev/'.$file); $count++; if ($file==='lib/CloudConnector.php') $hasConnector=true;
}
$zip->close();
if (!$hasConnector) throw new RuntimeException('Stage or commit the cloud connector before packaging.');
$artifacts=['module'=>['filename'=>$name,'size'=>filesize($target.'/'.$name),'sha256'=>hash_file('sha256',$target.'/'.$name)]];
// APK building/signing remains in Flutter CI. An explicit existing release APK can be included here.
if ($apk=getenv('SAHDEV_RELEASE_APK')) {
    if (!is_file($apk)) throw new RuntimeException('Release APK is missing.');
    $apkName='sahdev-android-'.$version.'.apk'; copy($apk,$target.'/'.$apkName);
    $artifacts['android']=['filename'=>$apkName,'size'=>filesize($target.'/'.$apkName),'sha256'=>hash_file('sha256',$target.'/'.$apkName)];
}
$payload=json_encode(['version'=>$version,'created_at'=>gmdate('c'),'artifacts'=>$artifacts],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$key=base64_decode((string)getenv('SAHDEV_RELEASE_SIGNING_KEY'),true);
if (!$unsigned && (!is_string($key) || strlen($key)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)) throw new RuntimeException('Supply an Ed25519 signing key in SAHDEV_RELEASE_SIGNING_KEY. --unsigned is for CI inspection only.');
$envelope=['payload'=>base64_encode($payload),'signature'=>$unsigned ? null : base64_encode(sodium_crypto_sign_detached($payload,$key))];
file_put_contents($target.'/release.json',json_encode($envelope,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo 'Built '.$count.' module files. '.($unsigned ? 'UNSIGNED inspection artifact; the portal will reject it.' : 'Signed manifest ready.')."\n";
