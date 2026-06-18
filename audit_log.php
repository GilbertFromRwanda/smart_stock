<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');

$role = $_SESSION['role'] ?? 'staff';
if (!in_array($role, ['admin', 'manager'])) {
    redirect('dashboard.php');
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_action = trim($_GET['action'] ?? '');
$filter_module = trim($_GET['module'] ?? '');
$filter_user   = trim($_GET['user']   ?? '');
$filter_from   = trim($_GET['from']   ?? '');
$filter_to     = trim($_GET['to']     ?? '');

$where = ['1=1'];
if ($filter_action !== '') $where[] = "action = '" . mysqli_real_escape_string($conn, $filter_action) . "'";
if ($filter_module !== '') $where[] = "module = '" . mysqli_real_escape_string($conn, $filter_module) . "'";
if ($filter_user   !== '') $where[] = "username LIKE '%" . mysqli_real_escape_string($conn, $filter_user) . "%'";
if ($filter_from   !== '') $where[] = "DATE(created_at) >= '" . mysqli_real_escape_string($conn, $filter_from) . "'";
if ($filter_to     !== '') $where[] = "DATE(created_at) <= '" . mysqli_real_escape_string($conn, $filter_to) . "'";

$where_sql = implode(' AND ', $where);

$per_page = 50;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$total = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM audit_log WHERE $where_sql"))['c'] ?? 0);
$pages = max(1, (int)ceil($total / $per_page));

$rows = [];
$res  = mysqli_query($conn, "SELECT * FROM audit_log WHERE $where_sql ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

// Distinct values for filter dropdowns
$actions = [];
$ra = mysqli_query($conn, "SELECT DISTINCT action FROM audit_log ORDER BY action");
while ($x = mysqli_fetch_assoc($ra)) $actions[] = $x['action'];

$modules = [];
$rm = mysqli_query($conn, "SELECT DISTINCT module FROM audit_log ORDER BY module");
while ($x = mysqli_fetch_assoc($rm)) $modules[] = $x['module'];

// ── Action badge colours ──────────────────────────────────────────────────────
$action_cls = [
    'DELETE'       => 'badge-delete',
    'UPDATE'       => 'badge-update',
    'STOCK_ADJUST' => 'badge-adjust',
    'MOVE_RETAIL'  => 'badge-move',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Log</title>
<link rel="stylesheet" href="css/style.css">
<style>
.audit-filters {
    display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;
    background:#f8fafc; border:1px solid var(--gray-200);
    border-radius:12px; padding:16px 20px; margin-bottom:22px;
}
.filter-group { display:flex; flex-direction:column; gap:4px; }
.filter-group label { font-size:11px; font-weight:600; color:var(--secondary); }
.filter-group select,
.filter-group input  { padding:7px 10px; border:1px solid var(--gray-200); border-radius:8px; font-size:13px; background:#fff; min-width:130px; }
.filter-actions { display:flex; gap:8px; align-items:flex-end; }

.badge {
    display:inline-block; padding:3px 9px; border-radius:20px;
    font-size:11px; font-weight:700; letter-spacing:.3px;
}
.badge-delete  { background:#fee2e2; color:#b91c1c; }
.badge-update  { background:#dbeafe; color:#1d4ed8; }
.badge-adjust  { background:#fef3c7; color:#92400e; }
.badge-move    { background:#d1fae5; color:#065f46; }
.badge-default { background:#f1f5f9; color:#475569; }

.audit-desc {
    font-size:12px; color:var(--secondary); max-width:480px;
    word-break:break-word; white-space:pre-wrap;
}
.audit-meta { font-size:11px; color:#94a3b8; }
.val-pairs { display:flex; flex-direction:column; gap:3px; }
.val-row   { display:flex; flex-direction:column; }
.val-key   { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#94a3b8; }
.val-old   { font-size:12px; font-weight:600; color:#b91c1c; background:#fee2e2; padding:1px 6px; border-radius:4px; display:inline-block; }
.val-new   { font-size:12px; font-weight:600; color:#065f46; background:#d1fae5; padding:1px 6px; border-radius:4px; display:inline-block; }
.page-nav { display:flex; gap:6px; flex-wrap:wrap; margin-top:18px; align-items:center; }
.page-nav a, .page-nav span {
    padding:5px 12px; border-radius:7px; font-size:13px; font-weight:500;
    border:1px solid var(--gray-200); text-decoration:none; color:var(--secondary);
}
.page-nav a:hover { background:var(--gray-100); }
.page-nav .cur { background:#eff6ff; color:#2563eb; border-color:#bfdbfe; }
.summary-bar {
    display:flex; flex-wrap:wrap; gap:12px; margin-bottom:20px;
}
.sum-card {
    background:#fff; border:1px solid var(--gray-200); border-radius:10px;
    padding:12px 18px; min-width:130px;
}
.sum-card .s-num { font-size:22px; font-weight:800; color:#0f172a; }
.sum-card .s-lbl { font-size:11px; color:var(--secondary); font-weight:600; margin-top:2px; }
</style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">

        <div style="margin-bottom:22px;">
            <h1 style="margin:0;">Audit Log</h1>
            <p style="margin:4px 0 0; color:var(--secondary); font-size:13px;">
                All destructive and sensitive actions — deletes, stock adjustments, updates.
            </p>
        </div>

        <?php
        // Summary counts (no filters, all time)
        $sc = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT
                SUM(action='DELETE')       AS deletes,
                SUM(action='UPDATE')       AS updates,
                SUM(action='STOCK_ADJUST') AS adjusts,
                SUM(action='MOVE_RETAIL')  AS moves
             FROM audit_log"));
        ?>
        <div class="summary-bar">
            <div class="sum-card">
                <div class="s-num" style="color:#b91c1c;"><?php echo number_format((int)$sc['deletes']); ?></div>
                <div class="s-lbl">Total Deletes</div>
            </div>
            <div class="sum-card">
                <div class="s-num" style="color:#1d4ed8;"><?php echo number_format((int)$sc['updates']); ?></div>
                <div class="s-lbl">Total Updates</div>
            </div>
            <div class="sum-card">
                <div class="s-num" style="color:#92400e;"><?php echo number_format((int)$sc['adjusts']); ?></div>
                <div class="s-lbl">Stock Adjustments</div>
            </div>
            <div class="sum-card">
                <div class="s-num" style="color:#065f46;"><?php echo number_format((int)$sc['moves']); ?></div>
                <div class="s-lbl">Retail Moves</div>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" action="audit_log.php">
            <div class="audit-filters">
                <div class="filter-group">
                    <label>Action</label>
                    <select name="action">
                        <option value="">All actions</option>
                        <?php foreach ($actions as $a): ?>
                        <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $filter_action === $a ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($a); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Module</label>
                    <select name="module">
                        <option value="">All modules</option>
                        <?php foreach ($modules as $m): ?>
                        <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $filter_module === $m ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($m); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>User</label>
                    <input type="text" name="user" value="<?php echo htmlspecialchars($filter_user); ?>" placeholder="username…">
                </div>
                <div class="filter-group">
                    <label>From</label>
                    <input type="date" name="from" value="<?php echo htmlspecialchars($filter_from); ?>">
                </div>
                <div class="filter-group">
                    <label>To</label>
                    <input type="date" name="to" value="<?php echo htmlspecialchars($filter_to); ?>">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="audit_log.php" class="btn btn-secondary">Clear</a>
                </div>
            </div>
        </form>

        <div style="color:var(--secondary);font-size:13px;margin-bottom:12px;">
            <?php echo number_format($total); ?> record<?php echo $total !== 1 ? 's' : ''; ?>
            <?php if ($pages > 1): ?> &nbsp;· page <?php echo $page; ?> of <?php echo $pages; ?><?php endif; ?>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:32px;">#</th>
                        <th>When</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Module</th>
                        <th>Record</th>
                        <th>Description</th>
                        <th>Old Value</th>
                        <th>New Value</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                <tr><td colspan="10" style="text-align:center;color:var(--secondary);padding:32px;">No audit records found.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $i => $r): ?>
                <?php
                    $badge   = $action_cls[$r['action']] ?? 'badge-default';
                    $ts      = date('d M Y H:i', strtotime($r['created_at']));
                    $old_arr = $r['old_value'] ? json_decode($r['old_value'], true) : [];
                    $new_arr = $r['new_value'] ? json_decode($r['new_value'], true) : [];
                ?>
                <tr>
                    <td class="audit-meta"><?php echo $offset + $i + 1; ?></td>
                    <td>
                        <div style="font-size:13px;font-weight:600;white-space:nowrap;"><?php echo $ts; ?></div>
                        <div class="audit-meta"><?php echo date('D', strtotime($r['created_at'])); ?></div>
                    </td>
                    <td>
                        <span style="font-weight:600;font-size:13px;"><?php echo htmlspecialchars($r['username']); ?></span>
                    </td>
                    <td>
                        <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($r['action']); ?></span>
                    </td>
                    <td>
                        <span style="font-size:12px;font-weight:600;color:var(--secondary);">
                            <?php echo htmlspecialchars($r['module']); ?>
                        </span>
                    </td>
                    <td class="audit-meta"><?php echo (int)$r['record_id']; ?></td>
                    <td>
                        <div class="audit-desc"><?php echo htmlspecialchars($r['description']); ?></div>
                    </td>
                    <td>
                        <?php if ($old_arr): ?>
                        <div class="val-pairs">
                            <?php foreach ($old_arr as $k => $v): ?>
                            <div class="val-row">
                                <span class="val-key"><?php echo htmlspecialchars($k); ?></span>
                                <span class="val-old"><?php echo htmlspecialchars((string)$v); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <span class="audit-meta">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($new_arr): ?>
                        <div class="val-pairs">
                            <?php foreach ($new_arr as $k => $v): ?>
                            <div class="val-row">
                                <span class="val-key"><?php echo htmlspecialchars($k); ?></span>
                                <span class="val-new"><?php echo htmlspecialchars((string)$v); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <span class="audit-meta">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="audit-meta"><?php echo htmlspecialchars($r['ip_address'] ?? '—'); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
        <?php
        $qs = http_build_query(array_filter([
            'action' => $filter_action,
            'module' => $filter_module,
            'user'   => $filter_user,
            'from'   => $filter_from,
            'to'     => $filter_to,
        ]));
        $base = 'audit_log.php' . ($qs ? '?' . $qs . '&' : '?');
        ?>
        <div class="page-nav">
            <?php if ($page > 1): ?>
            <a href="<?php echo $base; ?>page=<?php echo $page - 1; ?>">‹ Prev</a>
            <?php endif; ?>
            <?php
            $lo = max(1, $page - 3);
            $hi = min($pages, $page + 3);
            for ($p = $lo; $p <= $hi; $p++):
            ?>
            <?php if ($p === $page): ?>
            <span class="cur"><?php echo $p; ?></span>
            <?php else: ?>
            <a href="<?php echo $base; ?>page=<?php echo $p; ?>"><?php echo $p; ?></a>
            <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $pages): ?>
            <a href="<?php echo $base; ?>page=<?php echo $page + 1; ?>">Next ›</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
</div>
<script src="script.js"></script>
</body>
</html>
