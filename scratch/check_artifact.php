<?php
$desc = [
    0 => ["pipe", "r"],
    1 => ["pipe", "w"],
    2 => ["pipe", "w"]
];
$proc = proc_open("git credential fill", $desc, $pipes);
$token = '';
if (is_resource($proc)) {
    fwrite($pipes[0], "protocol=https\nhost=github.com\n\n");
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    if (preg_match('/password=(.+)/', $out, $m)) $token = trim($m[1]);
}

$ch = curl_init('https://api.github.com/repos/thekugelblitz/sahdev/actions/runs/35207829462');
curl_setopt($ch, CURLOPT_USERAGENT, 'ArtifactChecker');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
if ($token) curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
$run = json_decode(curl_exec($ch), true);
curl_close($ch);

echo "Run status: " . ($run['status'] ?? '') . "\n";
echo "Run conclusion: " . ($run['conclusion'] ?? '') . "\n";
echo "Run artifacts URL: " . ($run['artifacts_url'] ?? '') . "\n";

$ch = curl_init($run['artifacts_url']);
curl_setopt($ch, CURLOPT_USERAGENT, 'ArtifactChecker');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
if ($token) curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
$artData = json_decode(curl_exec($ch), true);
curl_close($ch);

print_r($artData);
