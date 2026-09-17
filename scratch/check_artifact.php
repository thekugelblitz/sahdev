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

$runId = $argv[1] ?? '35216509476';
$ch = curl_init("https://api.github.com/repos/thekugelblitz/sahdev/actions/runs/{$runId}");
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

if (!empty($artData['artifacts'][0]['archive_download_url'])) {
    $art = $artData['artifacts'][0];
    $downloadUrl = $art['archive_download_url'];
    $sizeMb = round($art['size_in_bytes'] / (1024 * 1024), 2);
    echo "Artifact Name: " . $art['name'] . " ({$sizeMb} MB)\n";
    echo "Downloading zip from GitHub...\n";

    $zipPath = __DIR__ . '/apk_artifact.zip';
    $fp = fopen($zipPath, 'w+');
    $ch = curl_init($downloadUrl);
    curl_setopt($ch, CURLOPT_USERAGENT, 'ArtifactChecker');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($token) curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);

    echo "Downloaded " . round(filesize($zipPath) / (1024 * 1024), 2) . " MB zip file.\n";
    echo "Extracting app-release.apk...\n";

    $zip = new ZipArchive();
    if ($zip->open($zipPath) === true) {
        $zip->extractTo(__DIR__ . '/extracted_apk/');
        $zip->close();
        unlink($zipPath);

        $extractedApk = __DIR__ . '/extracted_apk/app-release.apk';
        if (file_exists($extractedApk)) {
            $dest1 = __DIR__ . '/../mobile/app-release.apk';
            copy($extractedApk, $dest1);
            echo "Updated {$dest1} (" . round(filesize($dest1) / (1024 * 1024), 2) . " MB)\n";

            $dest2Dir = __DIR__ . '/../mobile_apk';
            if (is_dir($dest2Dir)) {
                copy($extractedApk, $dest2Dir . '/app-release.apk');
                echo "Updated {$dest2Dir}/app-release.apk\n";
            }
            unlink($extractedApk);
            @rmdir(__DIR__ . '/extracted_apk/');
            echo "SUCCESS: Latest release APK installed locally!\n";
        }
    }
}

