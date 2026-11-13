<?php
/** The public distribution is an exact thin-connector allowlist. Never package the legacy root. */
$root = realpath(__DIR__.'/../..');
$version = $argv[1] ?? ''; $target = $argv[2] ?? '';
$unsigned = in_array('--unsigned',$argv,true); $preview = in_array('--preview',$argv,true);
if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,40}$/D',$version) || !$target) throw new RuntimeException('Usage: php tools/package-module.php VERSION OUTPUT_DIRECTORY [--preview] [--unsigned]');
$migration = require __DIR__.'/../config/migration.php';
if (!$preview && (empty($migration['features']) || array_filter($migration['features'],fn ($feature) => $feature['status'] !== 'verified'))) throw new RuntimeException('Stable packaging blocked: thin-connector feature migration is incomplete. --preview builds an acceptance artifact, never a public release.');
if ($unsigned && !$preview) throw new RuntimeException('Unsigned artifacts are restricted to acceptance previews.');
$key = base64_decode((string)getenv('SAHDEV_RELEASE_SIGNING_KEY'),true);
if (!$unsigned && (!is_string($key) || strlen($key)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)) throw new RuntimeException('Supply an Ed25519 signing key through the release runner environment.');
$policy = require __DIR__.'/../config/distribution.php';
$pipes = []; $process = proc_open(['git','ls-files','-z'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
fclose($pipes[0]); $tracked = array_flip(explode("\0",stream_get_contents($pipes[1]))); fclose($pipes[1]); fclose($pipes[2]);
if (proc_close($process)!==0) throw new RuntimeException('Cannot enumerate versioned source.');
$sources = [];
foreach ($policy['files'] as $file) {
    $source = $file === 'README.md' ? 'connector/README.md' : 'connector/sahdev_cloud/'.$file;
    if (!isset($tracked[$source])) throw new RuntimeException('Stage or commit the connector before packaging: '.$source);
    $path = realpath($root.'/'.$source);
    if (!$path || is_link($root.'/'.$source) || !str_starts_with(str_replace('\\','/',$path),str_replace('\\','/',$root).'/connector/')) throw new RuntimeException('Invalid connector source.');
    $sources[$policy['prefix'].$file] = $path;
}
if (!is_dir($target) && !mkdir($target,0700,true)) throw new RuntimeException('Cannot create output directory.');
$target = realpath($target); $filename = 'sahdev-connector-'.$version.'.zip';
$zip = new ZipArchive(); if ($zip->open($target.'/'.$filename,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Cannot open package.');
foreach ($sources as $name=>$path) $zip->addFile($path,$name);
$zip->close();
$payload = json_encode(['version'=>$version,'distribution'=>'thin-connector','channel'=>$preview ? 'preview' : 'stable','created_at'=>gmdate('c'),'artifacts'=>[
    'module'=>['filename'=>$filename,'size'=>filesize($target.'/'.$filename),'sha256'=>hash_file('sha256',$target.'/'.$filename)],
]],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
file_put_contents($target.'/release.json',json_encode(['payload'=>base64_encode($payload),'signature'=>$unsigned ? null : base64_encode(sodium_crypto_sign_detached($payload,$key))],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo 'Built '.count($sources).' thin connector files. '.($preview ? 'ACCEPTANCE PREVIEW: not available for public download.' : 'Signed stable manifest ready.').PHP_EOL;
