<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Only admins can run migrations
if (($_SESSION['role'] ?? '') !== 'admin') {
    die('<p style="font-family:sans-serif;color:red;padding:40px;">Access denied — admins only.</p>');
}

$sql_file = __DIR__ . '/db/updates.sql';
$results  = [];
$executed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run'])) {
    $executed = true;

    if (!file_exists($sql_file)) {
        $results[] = ['ok' => false, 'sql' => '', 'error' => 'File not found: db/updates.sql'];
    } else {
        $raw = file_get_contents($sql_file);

        // Strip comment lines (-- ...) and blank lines, then split on semicolons
        $lines = explode("\n", $raw);
        $cleaned = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) continue;
            $cleaned[] = $trimmed;
        }
        $sql_body   = implode(' ', $cleaned);
        $statements = array_filter(array_map('trim', explode(';', $sql_body)));

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
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Run DB Updates</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .update-wrap   { max-width: 860px; margin: 40px auto; padding: 0 20px; font-family: var(--font, sans-serif); }
        .update-card   { background: var(--white, #fff); border-radius: 14px; border: 1px solid var(--gray-200, #e2e8f0); box-shadow: 0 1px 4px rgba(0,0,0,.06); padding: 32px; }
        .update-title  { font-size: 22px; font-weight: 700; margin-bottom: 6px; }
        .update-sub    { color: #64748b; font-size: 13px; margin-bottom: 28px; }
        .sql-file-box  { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 16px; font-family: monospace; font-size: 13px; color: #475569; margin-bottom: 24px; }
        .result-item   { border-radius: 8px; margin-bottom: 10px; overflow: hidden; border: 1px solid #e2e8f0; }
        .result-header { display: flex; align-items: flex-start; gap: 12px; padding: 10px 14px; }
        .result-header.ok   { background: #f0fdf4; }
        .result-header.fail { background: #fff1f2; }

        .badge { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 99px; white-space: nowrap; flex-shrink: 0; margin-top: 2px; }
        .badge-ok       { background: #dcfce7; color: #166534; }
        .badge-fail     { background: #fee2e2; color: #991b1b; }

        .result-sql  { font-family: monospace; font-size: 12px; color: #334155; word-break: break-all; flex: 1; }
        .result-err  { font-family: monospace; font-size: 12px; color: #dc2626; padding: 8px 14px 10px 48px; background: #fff5f5; border-top: 1px solid #fecaca; }
        .summary     { border-radius: 8px; padding: 14px 18px; font-size: 14px; font-weight: 600; margin-bottom: 20px; }
        .summary.all-ok   { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .summary.has-fail { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .btn-run { display: inline-block; padding: 10px 28px; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn-run:hover { background: #1d4ed8; }
        .empty-notice { text-align: center; padding: 32px; color: #94a3b8; font-size: 14px; }
    </style>
</head>
<body>
<div class="update-wrap">
    <div class="update-card">
        <div class="update-title">Run DB Updates</div>
        <div class="update-sub">Executes all SQL statements in <code>db/updates.sql</code> as a single transaction. Rolls back everything if any statement fails.</div>

        <div class="sql-file-box">
            <?php echo htmlspecialchars(realpath($sql_file) ?: $sql_file); ?>
            <?php if (file_exists($sql_file)): ?>
                &nbsp;&mdash;&nbsp;<?php echo number_format(filesize($sql_file)); ?> bytes
            <?php else: ?>
                &nbsp;<span style="color:#dc2626;">(file not found)</span>
            <?php endif; ?>
        </div>

        <?php if (!$executed): ?>
        <form method="POST">
            <button type="submit" name="run" class="btn-run">Run Updates</button>
        </form>
        <?php endif; ?>

        <?php if ($executed): ?>
            <?php
            $total   = count($results);
            $ok_cnt  = count(array_filter($results, fn($r) => $r['ok']));
            $fail_cnt = $total - $ok_cnt;
            ?>

            <?php if ($total === 0): ?>
                <div class="empty-notice">No SQL statements found in the file.</div>
            <?php else: ?>
                <div class="summary <?php echo $fail_cnt === 0 ? 'all-ok' : 'has-fail'; ?>">
                    <?php if ($fail_cnt === 0): ?>
                        &#10003; All <?php echo $total; ?> statement<?php echo $total !== 1 ? 's' : ''; ?> executed successfully.
                    <?php else: ?>
                        &#9888; <?php echo $ok_cnt; ?> of <?php echo $total; ?> succeeded &mdash; <?php echo $fail_cnt; ?> failed (skipped, others applied).
                    <?php endif; ?>
                </div>

                <?php foreach ($results as $i => $r): ?>
                    <?php
                    $cls     = $r['ok'] ? 'ok' : 'fail';
                    $badge   = $r['ok'] ? 'OK' : 'FAILED';
                    $bcls    = $r['ok'] ? 'badge-ok' : 'badge-fail';
                    $preview = mb_strlen($r['sql']) > 120 ? mb_substr($r['sql'], 0, 120) . '…' : $r['sql'];
                    ?>
                    <div class="result-item">
                        <div class="result-header <?php echo $cls; ?>">
                            <span class="badge <?php echo $bcls; ?>"><?php echo $badge; ?></span>
                            <span class="result-sql" title="<?php echo htmlspecialchars($r['sql']); ?>">
                                <?php echo htmlspecialchars($preview); ?>
                            </span>
                        </div>
                        <?php if ($r['error']): ?>
                            <div class="result-err"><?php echo htmlspecialchars($r['error']); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div style="margin-top:24px;">
                <a href="run_update.php" class="btn-run" style="text-decoration:none;">Run Again</a>
                &nbsp;
                <a href="dashboard.php" style="color:#64748b;font-size:13px;margin-left:8px;">← Back to Dashboard</a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
