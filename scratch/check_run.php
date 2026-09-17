<?php
$runId = $argv[1] ?? '35216192511';
$ch = curl_init("https://api.github.com/repos/thekugelblitz/sahdev/actions/runs/{$runId}/jobs");
curl_setopt($ch, CURLOPT_USERAGENT, 'CheckRun');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$data = json_decode(curl_exec($ch), true);
curl_close($ch);

if (!empty($data['jobs'][0]['steps'])) {
    foreach ($data['jobs'][0]['steps'] as $s) {
        $started = isset($s['started_at']) ? substr($s['started_at'], 11, 8) : '-';
        $completed = isset($s['completed_at']) ? substr($s['completed_at'], 11, 8) : '-';
        echo sprintf("%-30s | %-12s | %-10s | %s -> %s\n", $s['name'], $s['status'], $s['conclusion'] ?? '-', $started, $completed);
    }
}
