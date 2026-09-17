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

$runId = $argv[1] ?? '35216192511';
$ch = curl_init("https://api.github.com/repos/thekugelblitz/sahdev/actions/runs/{$runId}/jobs");
curl_setopt($ch, CURLOPT_USERAGENT, 'LogChecker');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
if ($token) curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
$jobs = json_decode(curl_exec($ch), true);
curl_close($ch);

$jobId = $jobs['jobs'][0]['id'] ?? null;
echo "Job ID: {$jobId}\n";
if ($jobId) {
    $ch = curl_init("https://api.github.com/repos/thekugelblitz/sahdev/actions/jobs/{$jobId}/logs");
    curl_setopt($ch, CURLOPT_USERAGENT, 'LogChecker');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($token) curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
    $log = curl_exec($ch);
    curl_close($ch);

    $lines = explode("\n", $log);
    echo "Total lines: " . count($lines) . "\n";
    $tail = array_slice($lines, -60);
    echo implode("\n", $tail) . "\n";
}
