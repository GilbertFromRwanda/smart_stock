<?php
require_once 'config.php';

if (!isLoggedIn()) { redirect('login.php'); }
if (($_SESSION['role'] ?? '') !== 'admin') {
    die('<p style="font-family:sans-serif;color:red;padding:40px;">Access denied — admins only.</p>');
}

// ── AJAX: Git Pull ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'git_pull') {
    $out  = [];
    $code = 0;
    exec('git -c safe.directory=' . escapeshellarg(str_replace('\\', '/', __DIR__)) . ' -C ' . escapeshellarg(__DIR__) . ' pull 2>&1', $out, $code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => $code === 0, 'output' => implode("\n", $out)]);
    exit;
}

// ── AJAX: Run DB Updates ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_db') {
    $sql_file = __DIR__ . '/db/updates.sql';
    $results  = [];

    if (!file_exists($sql_file)) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'results' => [['ok' => false, 'sql' => '', 'error' => 'File not found: db/updates.sql']]]);
        exit;
    }

    $raw      = file_get_contents($sql_file);
    $lines    = explode("\n", $raw);
    $cleaned  = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) continue;
        $cleaned[] = $trimmed;
    }
    $statements = array_filter(array_map('trim', explode(';', implode(' ', $cleaned))));

    mysqli_report(MYSQLI_REPORT_OFF);
    foreach ($statements as $stmt) {
        if ($stmt === '') continue;
        try {
            $ok  = mysqli_query($conn, $stmt);
            $err = $ok ? '' : mysqli_error($conn);
        } catch (Throwable $e) {
            $ok  = false;
            $err = $e->getMessage();
        }
        $results[] = ['ok' => (bool)$ok, 'sql' => $stmt, 'error' => $err];
    }

    $fail = count(array_filter($results, fn($r) => !$r['ok']));
    header('Content-Type: application/json');
    echo json_encode(['ok' => $fail === 0, 'results' => $results]);
    exit;
}

$sql_file = __DIR__ . '/db/updates.sql';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Run Updates</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .ru-wrap       { max-width: 820px; margin: 0 auto; padding: 32px 24px; }
        .ru-page-title { font-size: 22px; font-weight: 700; margin-bottom: 24px; color: var(--text, #1e293b); }

        .ru-card { background: #fff; border-radius: 14px; border: 1px solid var(--gray-200, #e2e8f0);
                   box-shadow: 0 1px 4px rgba(0,0,0,.05); padding: 28px 28px 24px; margin-bottom: 20px; }

        .ru-card-head  { display: flex; align-items: center; gap: 14px; margin-bottom: 6px; }
        .ru-icon       { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center;
                         justify-content: center; font-size: 18px; flex-shrink: 0; }
        .ru-icon.green { background: #dcfce7; }
        .ru-icon.blue  { background: #dbeafe; }
        .ru-card-title { font-size: 16px; font-weight: 700; color: var(--text, #1e293b); }
        .ru-card-sub   { font-size: 13px; color: #64748b; margin-bottom: 20px; margin-left: 52px; }

        .ru-meta { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
                   padding: 10px 14px; font-family: monospace; font-size: 12px; color: #64748b;
                   margin-bottom: 18px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        .ru-btn { padding: 9px 22px; border: none; border-radius: 8px; font-size: 14px;
                  font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 7px;
                  transition: opacity .15s; }
        .ru-btn:disabled { opacity: .55; cursor: not-allowed; }
        .ru-btn.green { background: #16a34a; color: #fff; }
        .ru-btn.green:hover:not(:disabled) { background: #15803d; }
        .ru-btn.blue  { background: #2563eb; color: #fff; }
        .ru-btn.blue:hover:not(:disabled)  { background: #1d4ed8; }

        .ru-output { background: #0f172a; color: #94a3b8; font-family: monospace; font-size: 12.5px;
                     padding: 14px 16px; border-radius: 8px; white-space: pre-wrap; word-break: break-all;
                     margin-top: 16px; line-height: 1.6; display: none; }
        .ru-output.visible { display: block; }
        .ru-output .ok-line  { color: #86efac; }
        .ru-output .err-line { color: #fca5a5; }

        .ru-status { margin-top: 12px; font-size: 13px; font-weight: 600; display: none; }
        .ru-status.ok   { color: #16a34a; }
        .ru-status.fail { color: #dc2626; }

        /* DB result list */
        .ru-results  { margin-top: 18px; display: none; }
        .ru-summary  { padding: 12px 16px; border-radius: 8px; font-size: 14px; font-weight: 600; margin-bottom: 14px; }
        .ru-summary.ok   { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .ru-summary.fail { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        .ru-item { border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; margin-bottom: 8px; }
        .ru-item-head { display: flex; align-items: flex-start; gap: 10px; padding: 9px 12px; }
        .ru-item-head.ok   { background: #f0fdf4; }
        .ru-item-head.fail { background: #fff1f2; }
        .ru-badge { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 99px; flex-shrink: 0; margin-top: 2px; }
        .ru-badge.ok   { background: #dcfce7; color: #166534; }
        .ru-badge.fail { background: #fee2e2; color: #991b1b; }
        .ru-sql  { font-family: monospace; font-size: 12px; color: #334155; flex: 1; word-break: break-all; }
        .ru-err  { font-family: monospace; font-size: 12px; color: #dc2626; padding: 8px 12px 9px 38px;
                   background: #fff5f5; border-top: 1px solid #fecaca; }

        .spinner { width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.4);
                   border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
    <div class="ru-wrap">
        <div class="ru-page-title">System Updates</div>

        <!-- Git Pull -->
        <div class="ru-card">
            <div class="ru-card-head">
                <div class="ru-icon green">&#9654;</div>
                <div class="ru-card-title">Git Pull</div>
            </div>
            <div class="ru-card-sub">Pull the latest code from the remote repository.</div>
            <button class="ru-btn green" id="gitBtn" onclick="runGitPull()">
                <span id="gitBtnText">Pull Latest Code</span>
            </button>
            <div class="ru-output" id="gitOutput"></div>
            <div class="ru-status" id="gitStatus"></div>
        </div>

        <!-- DB Updates -->
        <div class="ru-card">
            <div class="ru-card-head">
                <div class="ru-icon blue">&#9670;</div>
                <div class="ru-card-title">Run DB Updates</div>
            </div>
            <div class="ru-card-sub">Executes all statements in <code>db/updates.sql</code>.</div>

            <div class="ru-meta">
                <span>&#128196;</span>
                <span><?php echo htmlspecialchars(realpath($sql_file) ?: $sql_file); ?></span>
                <?php if (file_exists($sql_file)): ?>
                    <span style="color:#94a3b8;">&mdash; <?php echo number_format(filesize($sql_file)); ?> bytes</span>
                <?php else: ?>
                    <span style="color:#dc2626;">(file not found)</span>
                <?php endif; ?>
            </div>

            <button class="ru-btn blue" id="dbBtn" onclick="runDb()">
                <span id="dbBtnText">Run Updates</span>
            </button>
            <div class="ru-results" id="dbResults"></div>
        </div>

        <a href="dashboard.php" style="font-size:13px;color:#64748b;text-decoration:none;">&#8592; Back to Dashboard</a>
    </div>
    </div>
</div>

<script>
function runGitPull() {
    var btn  = document.getElementById('gitBtn');
    var txt  = document.getElementById('gitBtnText');
    var out  = document.getElementById('gitOutput');
    var stat = document.getElementById('gitStatus');

    btn.disabled = true;
    txt.innerHTML = '<span class="spinner"></span> Pulling…';
    out.className = 'ru-output';
    stat.className = 'ru-status';
    stat.style.display = 'none';

    var data = new FormData();
    data.append('action', 'git_pull');

    fetch('run_update.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            out.textContent = res.output || '(no output)';
            out.className = 'ru-output visible';
            stat.textContent = res.ok ? '✓ Pull succeeded.' : '✗ Pull failed or returned errors.';
            stat.className = 'ru-status ' + (res.ok ? 'ok' : 'fail');
            stat.style.display = 'block';
        })
        .catch(function(e) {
            out.textContent = 'Network error: ' + e;
            out.className = 'ru-output visible';
            stat.textContent = '✗ Request failed.';
            stat.className = 'ru-status fail';
            stat.style.display = 'block';
        })
        .finally(function() {
            btn.disabled = false;
            txt.textContent = 'Pull Latest Code';
        });
}

function runDb() {
    var btn     = document.getElementById('dbBtn');
    var txt     = document.getElementById('dbBtnText');
    var results = document.getElementById('dbResults');

    btn.disabled = true;
    txt.innerHTML = '<span class="spinner"></span> Running…';
    results.style.display = 'none';

    var data = new FormData();
    data.append('action', 'run_db');

    fetch('run_update.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            var rows = res.results || [];
            if (!rows.length) {
                results.innerHTML = '<div style="text-align:center;padding:24px;color:#94a3b8;font-size:14px;">No SQL statements found.</div>';
                results.style.display = 'block';
                return;
            }
            var ok_cnt   = rows.filter(function(r) { return r.ok; }).length;
            var fail_cnt = rows.length - ok_cnt;
            var html = '<div class="ru-summary ' + (fail_cnt === 0 ? 'ok' : 'fail') + '">';
            html += fail_cnt === 0
                ? '&#10003; All ' + rows.length + ' statement' + (rows.length !== 1 ? 's' : '') + ' executed successfully.'
                : '&#9888; ' + ok_cnt + ' of ' + rows.length + ' succeeded &mdash; ' + fail_cnt + ' failed.';
            html += '</div>';

            rows.forEach(function(r) {
                var preview = r.sql.length > 120 ? r.sql.substring(0, 120) + '…' : r.sql;
                html += '<div class="ru-item">' +
                    '<div class="ru-item-head ' + (r.ok ? 'ok' : 'fail') + '">' +
                    '<span class="ru-badge ' + (r.ok ? 'ok' : 'fail') + '">' + (r.ok ? 'OK' : 'FAIL') + '</span>' +
                    '<span class="ru-sql" title="' + r.sql.replace(/"/g, '&quot;') + '">' + preview + '</span>' +
                    '</div>' +
                    (r.error ? '<div class="ru-err">' + r.error + '</div>' : '') +
                    '</div>';
            });

            results.innerHTML = html;
            results.style.display = 'block';
        })
        .catch(function(e) {
            results.innerHTML = '<div style="color:#dc2626;font-size:13px;">Network error: ' + e + '</div>';
            results.style.display = 'block';
        })
        .finally(function() {
            btn.disabled = false;
            txt.textContent = 'Run Updates';
        });
}
</script>
</body>
</html>
