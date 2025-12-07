<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

function env_val(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

$logFile = dirname(__DIR__) . '/logs/app.log';
$phpServerLog = dirname(__DIR__) . '/logs/php-server.log';
$videoRoot = rtrim((string) env_val('VIDEO_ROOT', '/videos'), '/');

$config = [
    'Lidarr URL'            => rtrim((string) env_val('LIDARR_URL', ''), '/'),
    'Video root'            => $videoRoot,
    'Max downloads/run'     => (int) env_val('MAX_DOWNLOADS_PER_RUN', '3'),
    'Dry run'               => strtolower((string) env_val('DRY_RUN', 'false')) === 'true' ? 'Yes' : 'No',
    'Rename-only mode'      => strtolower((string) env_val('RENAME_EXISTING_ONLY', 'false')) === 'true' ? 'Enabled' : 'Disabled',
    'Video quality'         => env_val('VIDEO_QUALITY', '1080p'),
    'Enable Lidarr ingest'  => strtolower((string) env_val('ENABLE_LIDARR_PIPELINE', 'true')) === 'true' ? 'Yes' : 'No',
    'Artist filter'         => env_val('LIDARR_ARTIST_ID', '—'),
];

$actionMessage = null;
$actionDetails = null;

function run_command(string $label, string $command): array {
    $output = [];
    exec($command . ' 2>&1', $output, $code);
    return [$code === 0, $label . ($code === 0 ? ' completed.' : ' failed.'), $output];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'cleanup_fragments') {
        [$ok, $actionMessage, $output] = run_command(
            'Fragment cleanup',
            'php ' . escapeshellarg(dirname(__DIR__) . '/cleanup_fragments.php')
        );
        $actionDetails = implode("\n", $output);
    } elseif ($action === 'run_sync') {
        [$ok, $actionMessage, $output] = run_command(
            'Manual sync run',
            'php ' . escapeshellarg(dirname(__DIR__) . '/sync.php')
        );
        $actionDetails = implode("\n", $output);
    }
}

function read_last_lines(string $file, int $lines = 200): array {
    if (!file_exists($file)) {
        return ['No log file found yet.'];
    }

    $buffer = '';
    $fp = fopen($file, 'rb');
    if ($fp === false) {
        return ['Unable to read log file.'];
    }

    $pos = -1;
    $lineCount = 0;
    $out = [];

    while ($lineCount < $lines) {
        if (fseek($fp, $pos, SEEK_END) !== 0) {
            break;
        }
        $char = fgetc($fp);
        if ($char === "\n") {
            $line = strrev($buffer);
            $out[] = $line;
            $buffer = '';
            $lineCount++;
            $pos--;
            continue;
        }
        if ($char === false) {
            break;
        }
        $buffer .= $char;
        $pos--;
    }

    if ($buffer !== '') {
        $out[] = strrev($buffer);
    }

    fclose($fp);
    return array_reverse($out);
}

$logLines = read_last_lines($logFile, 200);
$phpLogLines = read_last_lines($phpServerLog, 30);
$videoRootStatus = is_dir($videoRoot)
    ? 'Directory exists'
    : 'Directory missing (will be created by sync loop)';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lidarr MV Sync</title>
    <style>
        :root {
            --bg: #0f172a;
            --panel: #111827;
            --muted: #94a3b8;
            --text: #e2e8f0;
            --accent: #22d3ee;
            --danger: #ef4444;
            --success: #22c55e;
            --border: #1f2937;
            --card: #0b1220;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: radial-gradient(circle at 20% 20%, #0b1220, #0f172a 40%, #0a0f1e 100%);
            color: var(--text);
            min-height: 100vh;
        }
        header {
            padding: 28px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .title {
            display: flex;
            gap: 12px;
            align-items: center;
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .badge {
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(34, 211, 238, 0.15);
            color: var(--accent);
            border: 1px solid rgba(34, 211, 238, 0.25);
            font-weight: 600;
            font-size: 0.9rem;
        }
        main {
            padding: 0 32px 32px;
            display: grid;
            gap: 20px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 14px;
        }
        .card {
            background: linear-gradient(145deg, var(--panel), var(--card));
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 18px;
            box-shadow: 0 12px 50px rgba(0,0,0,0.35);
        }
        .card h2 {
            margin: 0 0 12px;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
        }
        .stat-value {
            font-size: 1.4rem;
            font-weight: 700;
        }
        .muted { color: var(--muted); }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 6px 0;
        }
        button {
            border: 1px solid var(--border);
            background: rgba(34, 211, 238, 0.12);
            color: var(--text);
            padding: 10px 14px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            transition: transform 120ms ease, border-color 120ms ease, box-shadow 120ms ease;
        }
        button:hover {
            transform: translateY(-1px);
            border-color: rgba(34, 211, 238, 0.5);
            box-shadow: 0 8px 24px rgba(34, 211, 238, 0.12);
        }
        button.danger { background: rgba(239, 68, 68, 0.12); border-color: rgba(239, 68, 68, 0.35); }
        button.secondary { background: rgba(148, 163, 184, 0.15); border-color: rgba(148, 163, 184, 0.3); color: #e5e7eb; }
        .logs {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 14px;
        }
        pre {
            background: #0b1220;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px;
            color: #cbd5e1;
            font-family: 'SFMono-Regular', Consolas, ui-monospace, monospace;
            font-size: 0.9rem;
            max-height: 480px;
            overflow: auto;
            white-space: pre-wrap;
        }
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
            background: var(--success);
        }
        .status-dot.danger { background: var(--danger); }
        .pill { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 999px; }
        .section-title { font-size: 1.1rem; font-weight: 700; margin-bottom: 6px; }
        @media (max-width: 900px) { .logs { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<header>
    <div class="title">
        <div style="width: 12px; height: 12px; border-radius: 50%; background: var(--accent);"></div>
        <div>
            <div>Lidarr → YouTube → MV Sync</div>
            <div class="muted" style="font-size: 0.95rem;">Track discovery, downloads, and fragment cleanup</div>
        </div>
    </div>
    <div class="badge">UI monitor</div>
</header>
<main>
    <?php if ($actionMessage !== null): ?>
        <div class="card" style="border-color: rgba(34,211,238,0.45); background: rgba(34,211,238,0.08);">
            <div class="section-title">Action result</div>
            <div style="margin-bottom:8px;"><?php echo htmlspecialchars($actionMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php if ($actionDetails): ?>
                <pre><?php echo htmlspecialchars($actionDetails, ENT_QUOTES, 'UTF-8'); ?></pre>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="grid">
        <div class="card">
            <h2>run state</h2>
            <div class="stat-value">
                <span class="status-dot<?php echo is_dir($videoRoot) ? '' : ' danger'; ?>"></span>
                <?php echo htmlspecialchars($videoRootStatus, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="muted">Video root: <?php echo htmlspecialchars($videoRoot, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="muted" style="margin-top: 6px;">Last sync log update: <?php echo file_exists($logFile) ? date('Y-m-d H:i:s', filemtime($logFile)) . ' UTC' : '—'; ?></div>
        </div>
        <div class="card">
            <h2>configuration</h2>
            <?php foreach ($config as $label => $value): ?>
                <div style="display:flex; justify-content: space-between; margin-bottom: 6px; gap: 10px;">
                    <span class="muted"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span style="font-weight: 700;"><?php echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <h2>actions</h2>
            <div class="actions">
                <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="run_sync">
                    <button type="submit">Run sync now</button>
                </form>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="cleanup_fragments">
                    <button type="submit" class="secondary">Cleanup fragments</button>
                </form>
                <form method="get" style="margin:0;">
                    <button type="submit" class="secondary">Refresh</button>
                </form>
            </div>
            <div class="muted" style="margin-top: 6px;">Actions run in the container; check logs for detailed output.</div>
        </div>
    </div>

    <div class="card">
        <div class="section-title">Runtime snapshot</div>
        <div style="display:flex; flex-wrap: wrap; gap: 10px;">
            <span class="pill"><strong>PHP</strong> <?php echo PHP_VERSION; ?></span>
            <span class="pill">Timezone: <?php echo date_default_timezone_get(); ?></span>
            <span class="pill">Log file: <?php echo file_exists($logFile) ? filesize($logFile) . ' bytes' : 'not created yet'; ?></span>
            <span class="pill">Server log: <?php echo file_exists($phpServerLog) ? filesize($phpServerLog) . ' bytes' : 'not created yet'; ?></span>
        </div>
    </div>

    <div class="logs">
        <div class="card">
            <div class="section-title">Sync log (latest 200 lines)</div>
            <pre><?php echo htmlspecialchars(implode("\n", $logLines), ENT_QUOTES, 'UTF-8'); ?></pre>
        </div>
        <div class="card">
            <div class="section-title">Web server log (latest 30 lines)</div>
            <pre><?php echo htmlspecialchars(implode("\n", $phpLogLines), ENT_QUOTES, 'UTF-8'); ?></pre>
        </div>
    </div>
</main>
</body>
</html>
