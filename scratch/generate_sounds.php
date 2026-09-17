<?php

$dir = dirname(__DIR__) . '/mobile/assets/sounds';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

function writeWav(string $filePath, array $samples, int $sampleRate = 44100)
{
    $numSamples = count($samples);
    $bytesPerSample = 2; // 16-bit
    $dataSize = $numSamples * $bytesPerSample;
    $chunkSize = 36 + $dataSize;

    // RIFF Header
    $header = "RIFF" . pack("V", $chunkSize) . "WAVE";
    // Subchunk 1: fmt 
    $header .= "fmt " . pack("V", 16) . pack("v", 1) . pack("v", 1); // PCM, 1 channel
    $header .= pack("V", $sampleRate); // Sample rate
    $header .= pack("V", $sampleRate * $bytesPerSample); // Byte rate
    $header .= pack("v", $bytesPerSample); // Block align
    $header .= pack("v", 16); // Bits per sample
    // Subchunk 2: data
    $header .= "data" . pack("V", $dataSize);

    $binary = $header;
    foreach ($samples as $s) {
        $val = max(-32767, min(32767, (int) ($s * 32767)));
        $binary .= pack("v", $val < 0 ? $val + 65536 : $val);
    }

    file_put_contents($filePath, $binary);
    echo "Generated: " . basename($filePath) . " (" . round(strlen($binary) / 1024, 1) . " KB)\n";
}

$sampleRate = 44100;

// 1. Radar (Sonar ping: 1200Hz sine with exponential decay)
$duration = 1.0;
$samples = [];
$total = (int) ($sampleRate * $duration);
for ($i = 0; $i < $total; $i++) {
    $t = $i / $sampleRate;
    $env = exp(-4.5 * $t);
    $freq = 1150;
    $s = sin(2 * M_PI * $freq * $t) * $env;
    $samples[] = $s * 0.9;
}
writeWav("$dir/radar.wav", $samples, $sampleRate);

// 2. Crystal (High sparkling dual tone)
$duration = 1.2;
$samples = [];
$total = (int) ($sampleRate * $duration);
for ($i = 0; $i < $total; $i++) {
    $t = $i / $sampleRate;
    $env = exp(-3.5 * $t);
    $s1 = sin(2 * M_PI * 1760 * $t);
    $s2 = sin(2 * M_PI * 2640 * $t) * 0.5;
    $s3 = sin(2 * M_PI * 3520 * $t) * 0.25;
    $samples[] = ($s1 + $s2 + $s3) * $env * 0.7;
}
writeWav("$dir/crystal.wav", $samples, $sampleRate);

// 3. Bell (Warm service bell: 880Hz + harmonic overtone)
$duration = 1.4;
$samples = [];
$total = (int) ($sampleRate * $duration);
for ($i = 0; $i < $total; $i++) {
    $t = $i / $sampleRate;
    $env = exp(-3.0 * $t);
    $s = sin(2 * M_PI * 880 * $t) + 0.4 * sin(2 * M_PI * 1760 * $t) + 0.15 * sin(2 * M_PI * 2640 * $t);
    $samples[] = $s * $env * 0.75;
}
writeWav("$dir/bell.wav", $samples, $sampleRate);

// 4. Siren (Urgent two-tone alternating warble)
$duration = 1.8;
$samples = [];
$total = (int) ($sampleRate * $duration);
for ($i = 0; $i < $total; $i++) {
    $t = $i / $sampleRate;
    $freq = 750 + 250 * sin(2 * M_PI * 3.5 * $t);
    $env = min(1.0, $t * 20) * min(1.0, ($duration - $t) * 10);
    $s = sin(2 * M_PI * $freq * $t);
    $samples[] = $s * $env * 0.85;
}
writeWav("$dir/siren.wav", $samples, $sampleRate);

// 5. Neon Ping (Modern sci-fi blip)
$duration = 0.8;
$samples = [];
$total = (int) ($sampleRate * $duration);
for ($i = 0; $i < $total; $i++) {
    $t = $i / $sampleRate;
    $env = exp(-6.0 * $t);
    $freq = 1400 - 400 * $t;
    $s = sin(2 * M_PI * $freq * $t) + 0.3 * sin(2 * M_PI * 2 * $freq * $t);
    $samples[] = $s * $env * 0.8;
}
writeWav("$dir/neon_ping.wav", $samples, $sampleRate);

// 6. Pulse (High velocity urgent staccato alert)
$duration = 1.2;
$samples = [];
$total = (int) ($sampleRate * $duration);
for ($i = 0; $i < $total; $i++) {
    $t = $i / $sampleRate;
    $pulsePhase = fmod($t * 5.0, 1.0);
    $env = exp(-12.0 * $pulsePhase);
    $s = sin(2 * M_PI * 1046.5 * $t) * $env; // C6
    $samples[] = $s * 0.85;
}
writeWav("$dir/pulse.wav", $samples, $sampleRate);

// 7. Cosmic (Rising 4-note celestial arpeggio)
$duration = 1.5;
$notes = [523.25, 659.25, 783.99, 1046.50]; // C5, E5, G5, C6
$samples = [];
$total = (int) ($sampleRate * $duration);
for ($i = 0; $i < $total; $i++) {
    $t = $i / $sampleRate;
    $noteIdx = min(3, (int) ($t / 0.25));
    $noteT = $t - ($noteIdx * 0.25);
    $env = exp(-4.0 * $noteT);
    $freq = $notes[$noteIdx];
    $s = sin(2 * M_PI * $freq * $t) * $env;
    $samples[] = $s * 0.8;
}
writeWav("$dir/cosmic.wav", $samples, $sampleRate);

// 8. Electro (High-tech digital trill)
$duration = 0.9;
$samples = [];
$total = (int) ($sampleRate * $duration);
for ($i = 0; $i < $total; $i++) {
    $t = $i / $sampleRate;
    $env = exp(-4.0 * $t);
    $trill = (int) ($t * 24) % 2 == 0 ? 1200 : 1500;
    $s = sin(2 * M_PI * $trill * $t) * $env;
    $samples[] = $s * 0.8;
}
writeWav("$dir/electro.wav", $samples, $sampleRate);

// 9. Heartbeat (Deep double pulse)
$duration = 1.2;
$samples = [];
$total = (int) ($sampleRate * $duration);
for ($i = 0; $i < $total; $i++) {
    $t = $i / $sampleRate;
    $env1 = ($t < 0.35) ? exp(-15.0 * $t) : 0;
    $t2 = $t - 0.25;
    $env2 = ($t2 >= 0 && $t2 < 0.4) ? exp(-12.0 * $t2) : 0;
    $freq = 110; // Low thump
    $s = (sin(2 * M_PI * $freq * $t) * $env1) + (sin(2 * M_PI * $freq * $t) * $env2 * 0.8);
    $samples[] = $s * 0.95;
}
writeWav("$dir/heartbeat.wav", $samples, $sampleRate);

// 10. Marimba (Warm 3-note ascending marimba)
$duration = 1.3;
$marNotes = [440.0, 554.37, 659.25]; // A4, C#5, E5
$samples = [];
$total = (int) ($sampleRate * $duration);
for ($i = 0; $i < $total; $i++) {
    $t = $i / $sampleRate;
    $mIdx = min(2, (int) ($t / 0.22));
    $mT = $t - ($mIdx * 0.22);
    $env = exp(-5.5 * $mT);
    $freq = $marNotes[$mIdx];
    $s = (sin(2 * M_PI * $freq * $t) + 0.3 * sin(2 * M_PI * 2 * $freq * $t)) * $env;
    $samples[] = $s * 0.8;
}
writeWav("$dir/marimba.wav", $samples, $sampleRate);

// 11. Chime (Gentle two-note chime)
$duration = 1.2;
$samples = [];
$total = (int) ($sampleRate * $duration);
for ($i = 0; $i < $total; $i++) {
    $t = $i / $sampleRate;
    $env1 = exp(-4.0 * $t);
    $t2 = $t - 0.2;
    $env2 = ($t2 > 0) ? exp(-3.5 * $t2) : 0;
    $s = (sin(2 * M_PI * 784 * $t) * $env1 * 0.6) + (sin(2 * M_PI * 1046.5 * $t) * $env2 * 0.8);
    $samples[] = $s * 0.75;
}
writeWav("$dir/chime.wav", $samples, $sampleRate);

// 12. Alarm (Urgent buzzer alarm)
$duration = 1.4;
$samples = [];
$total = (int) ($sampleRate * $duration);
for ($i = 0; $i < $total; $i++) {
    $t = $i / $sampleRate;
    $beep = fmod($t * 4.0, 1.0) < 0.6 ? 1.0 : 0.0;
    $s = sin(2 * M_PI * 950 * $t) * $beep;
    $samples[] = $s * 0.85;
}
writeWav("$dir/alarm.wav", $samples, $sampleRate);

echo "All 12 distinct audio alert sounds successfully synthesized!\n";
