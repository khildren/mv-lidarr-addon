#!/usr/bin/env php
<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

function env_val(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

$videoRoot = rtrim((string) env_val('VIDEO_ROOT', '/videos'), '/');
$deleteOrphans = strtolower((string) env_val('FRAGMENT_DELETE_ORPHANS', 'false')) === 'true';

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/app.log';

function log_line(string $level, string $message, array $context = []): void {
    global $logFile;
    $timestamp = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
    $level = strtoupper($level);
    $contextStr = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
    $line = sprintf('[%s] [%s] %s%s', $timestamp, $level, $message, $contextStr);
    fwrite(STDOUT, $line . PHP_EOL);
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

log_line('system', '=== Fragment cleanup start ===', [
    'VIDEO_ROOT'        => $videoRoot,
    'DELETE_ORPHANS'    => $deleteOrphans,
]);

if (!is_dir($videoRoot)) {
    log_line('error', 'VIDEO_ROOT does not exist or is not a directory', ['video_root' => $videoRoot]);
    exit(1);
}

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($videoRoot));
$videoParts = [];
$audioParts = [];

foreach ($rii as $file) {
    if ($file->isDir()) {
        continue;
    }
    $path = $file->getPathname();
    $ext  = strtolower($file->getExtension());
    if ($ext === 'f137') {
        $base = substr($path, 0, -strlen('.f137'));
        $videoParts[$base] = $path;
    } elseif ($ext === 'f251') {
        $base = substr($path, 0, -strlen('.f251'));
        $audioParts[$base] = $path;
    }
}

$mergedCount  = 0;
$orphanCount  = 0;

foreach ($videoParts as $base => $videoPath) {
    $audioPath = $audioParts[$base] ?? null;
    if ($audioPath === null) {
        $orphanCount++;
        log_line('warn', 'Video-only fragment (no matching audio)', ['video' => $videoPath]);
        if ($deleteOrphans) {
            @unlink($videoPath);
            log_line('text', 'Deleted orphan video fragment', ['video' => $videoPath]);
        }
        continue;
    }

    $outputPath = $base . '.mp4';

    $cmd = sprintf(
        "ffmpeg -y -i %s -i %s -c copy %s 2>&1",
        escapeshellarg($videoPath),
        escapeshellarg($audioPath),
        escapeshellarg($outputPath)
    );

    log_line('text', 'Merging fragments with ffmpeg', [
        'video'  => $videoPath,
        'audio'  => $audioPath,
        'output' => $outputPath,
    ]);

    exec($cmd, $out, $code);
    if ($code !== 0) {
        log_line('error', 'ffmpeg merge failed', [
            'exitCode' => $code,
            'output'   => implode("\n", $out),
        ]);
        continue;
    }

    $mergedCount++;
    @unlink($videoPath);
    @unlink($audioPath);
    log_line('system', 'Merge complete, fragments removed', [
        'video'  => $videoPath,
        'audio'  => $audioPath,
        'output' => $outputPath,
    ]);
}

foreach ($audioParts as $base => $audioPath) {
    if (isset($videoParts[$base])) {
        continue;
    }
    $orphanCount++;
    log_line('warn', 'Audio-only fragment (no matching video)', ['audio' => $audioPath]);
    if ($deleteOrphans) {
        @unlink($audioPath);
        log_line('text', 'Deleted orphan audio fragment', ['audio' => $audioPath]);
    }
}

log_line('system', '=== Fragment cleanup end ===', [
    'merged'  => $mergedCount,
    'orphans' => $orphanCount,
]);
