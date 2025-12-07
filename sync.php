#!/usr/bin/env php
<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

function env_val(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

$config = [
    'lidarr_url'             => rtrim((string) env_val('LIDARR_URL', ''), '/'),
    'lidarr_api_key'         => env_val('LIDARR_API_KEY', ''),
    'video_root'             => rtrim((string) env_val('VIDEO_ROOT', '/videos'), '/'),
    'max_downloads'          => (int) env_val('MAX_DOWNLOADS_PER_RUN', '3'),
    'dry_run'                => strtolower((string) env_val('DRY_RUN', 'false')) === 'true',
    'rename_existing_only'   => strtolower((string) env_val('RENAME_EXISTING_ONLY', 'false')) === 'true',
    'video_quality'          => env_val('VIDEO_QUALITY', '1080p'),
    'ytdlp_max_retries'      => (int) env_val('YTDLP_MAX_RETRIES', '3'),
    'enable_lidarr_pipeline' => strtolower((string) env_val('ENABLE_LIDARR_PIPELINE', 'true')) === 'true',
    'lidarr_artist_id'       => env_val('LIDARR_ARTIST_ID', null),
];

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

if ($config['lidarr_url'] === '' || $config['lidarr_api_key'] === '') {
    log_line('error', 'LIDARR_URL or LIDARR_API_KEY is not configured.');
    exit(1);
}

if (!is_dir($config['video_root']) && !@mkdir($config['video_root'], 0775, true)) {
    log_line('error', 'Unable to create or access VIDEO_ROOT', ['video_root' => $config['video_root']]);
    exit(1);
}

log_line('system', '=== Run start ===', [
    'VIDEO_ROOT'           => $config['video_root'],
    'DRY_RUN'              => $config['dry_run'],
    'RENAME_EXISTING_ONLY' => $config['rename_existing_only'],
    'MAX_DOWNLOADS'        => $config['max_downloads'],
]);

function lidarr_get(string $path, array $query = []): array {
    global $config;

    $query['apikey'] = $config['lidarr_api_key'];
    $qs = http_build_query($query);
    $url = $config['lidarr_url'] . '/api/v1/' . ltrim($path, '/') . '?' . $qs;

    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 30,
        ]
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        log_line('error', 'Failed to fetch from Lidarr', ['url' => $url]);
        return [];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        log_line('error', 'Invalid JSON from Lidarr', ['url' => $url, 'raw' => mb_substr($raw, 0, 500)]);
        return [];
    }

    return $json;
}

function fetch_artists(): array {
    global $config;

    if (!empty($config['lidarr_artist_id'])) {
        $artist = lidarr_get('artist/' . urlencode($config['lidarr_artist_id']));
        if (!$artist) {
            log_line('warn', 'No artist for LIDARR_ARTIST_ID', ['id' => $config['lidarr_artist_id']]);
            return [];
        }
        return [$artist];
    }

    $artists = lidarr_get('artist');
    if (!is_array($artists)) {
        $artists = [];
    }
    return $artists;
}

function fetch_tracks_for_artist(array $artist): array {
    $artistId = $artist['id'] ?? null;
    if ($artistId === null) {
        return [];
    }
    $tracks = lidarr_get('track', ['artistId' => $artistId]);
    if (!is_array($tracks)) {
        $tracks = [];
    }
    return $tracks;
}

function search_youtube_url(string $artistName, string $trackTitle): ?string {
    $queryBase = sprintf('%s - %s', $artistName, $trackTitle);
    $queries = [
        sprintf('%s official music video', $queryBase),
        sprintf('%s music video', $queryBase),
        $queryBase,
    ];

    foreach ($queries as $q) {
        $cmd = sprintf(
            "yt-dlp %s --get-id 2>/dev/null",
            escapeshellarg('ytsearch1:' . $q)
        );
        $output = trim(shell_exec($cmd) ?? '');
        if ($output !== '') {
            return 'https://www.youtube.com/watch?v=' . $output;
        }
    }

    log_line('warn', 'yt-dlp search returned no results', ['artist' => $artistName, 'track' => $trackTitle]);
    return null;
}

function build_filename(string $artist, string $title, ?int $year = null): string {
    $base = $artist . ' - ' . $title;
    if ($year !== null) {
        $base .= ' (' . $year . ')';
    }
    $base = preg_replace('/[\\\\\\/:"*?<>|]+/u', '_', $base);
    $base = preg_replace('/\s+/', ' ', $base);
    $base = trim($base);
    return $base . '.mp4';
}

function run_ytdlp(string $videoUrl, string $targetPath): bool {
    global $config;

    $dir = dirname($targetPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $format = 'bestvideo[height<=1080]+bestaudio/best[height<=1080]';
    if ($config['video_quality'] === '720p') {
        $format = 'bestvideo[height<=720]+bestaudio/best[height<=720]';
    }

    $cmd = [
        'yt-dlp',
        '--no-part',
        '--no-warnings',
        '--retries', (string)$config['ytdlp_max_retries'],
        '-f', $format,
        '-o', $targetPath,
        $videoUrl,
    ];

    $cmdStr = '';
    foreach ($cmd as $piece) {
        $cmdStr .= escapeshellarg($piece) . ' ';
    }

    log_line('text', 'Downloading with yt-dlp', ['cmd' => $cmd]);

    exec($cmdStr . '2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        log_line('error', 'yt-dlp failed', [
            'exitCode' => $exitCode,
            'output'   => implode("\n", $output),
        ]);
        return false;
    }

    return true;
}

if ($config['rename_existing_only']) {
    log_line('system', 'Rename-only run (no new downloads) requested.');
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($config['video_root']));
    foreach ($rii as $file) {
        if ($file->isDir()) {
            continue;
        }
        $path = $file->getPathname();
        $ext  = strtolower($file->getExtension());
        if (!in_array($ext, ['mp4', 'mkv', 'm4v'], true)) {
            continue;
        }
        log_line('text', 'Rename-only mode placeholder, would rename file', ['file' => $path]);
    }
    log_line('system', 'Rename-only run finished.');
    exit(0);
}

$artists = fetch_artists();
log_line('system', 'Fetched artists', ['count' => count($artists)]);

$downloadsStarted = 0;

foreach ($artists as $artist) {
    $artistName = $artist['artistName'] ?? $artist['sortName'] ?? 'Unknown Artist';

    if (stripos($artistName, 'unknown') !== false) {
        log_line('warn', 'Skipping artist marked as Unknown', ['artist' => $artistName]);
        continue;
    }

    $tracks = fetch_tracks_for_artist($artist);
    log_line('system', 'Fetched tracks for artist', [
        'artist' => $artistName,
        'count'  => count($tracks),
    ]);

    foreach ($tracks as $track) {
        if ($downloadsStarted >= $config['max_downloads']) {
            log_line('system', 'Reached MAX_DOWNLOADS_PER_RUN; stopping.');
            break 2;
        }

        $title = $track['title'] ?? 'Unknown Title';
        $year  = null;
        if (!empty($track['releaseDate'])) {
            $year = (int)substr($track['releaseDate'], 0, 4);
        }

        $filename   = build_filename($artistName, $title, $year);
        $targetPath = $config['video_root'] . '/' . $filename;

        if (file_exists($targetPath)) {
            log_line('text', 'Target exists, skipping.', ['path' => $targetPath]);
            continue;
        }

        if ($config['dry_run']) {
            log_line('text', 'DRY_RUN: would download track', [
                'artist' => $artistName,
                'title'  => $title,
                'target' => $targetPath,
            ]);
            $downloadsStarted++;
            continue;
        }

        $videoUrl = search_youtube_url($artistName, $title);
        if ($videoUrl === null) {
            continue;
        }

        $ok = run_ytdlp($videoUrl, $targetPath);
        if ($ok) {
            $downloadsStarted++;
            log_line('system', 'Download complete', [
                'artist' => $artistName,
                'title'  => $title,
                'file'   => $targetPath,
            ]);
        }
    }
}

log_line('system', '=== Run end ===', ['downloads_started' => $downloadsStarted]);
