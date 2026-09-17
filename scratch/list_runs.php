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

$ch = curl_init('https://api.github.com/repos/thekugelblitz/sahdev/actions/runs?per_page=5');
curl_setopt($ch, CURLOPT_USERAGENT, 'RunLister');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
if ($token) curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
$runs = json_decode(curl_exec($ch), true);
curl_close($ch);

foreach ($runs['workflow_runs'] as $r) {
    echo "ID: " . $r['id'] . " | Commit: " . substr($r['head_sha'], 0, 7) . " | Status: " . $r['status'] . " | Conclusion: " . ($r['conclusion'] ?? 'in_progress') . " | Title: " . $r['display_title'] . "\n";
}
