<?php
require_once 'config.php';
require_once __DIR__ . '/stock_value.php';
if (!isLoggedIn()) redirect('login.php');

// ── AJAX: save adjustment ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust'])) {
    $pid        = (int)$_POST['product_id'];
    $wh_qty     = max(0, (int)$_POST['wh_qty']);
    $rt_qty     = max(0, (int)$_POST['rt_qty']);
    $reason_raw = trim($_POST['reason'] ?? '');
    $reason     = mysqli_real_escape_string($conn, $reason_raw);

    $old_wh_res = mysqli_query($conn, "SELECT quantity FROM stock WHERE product_id=$pid");
    $old_wh_row = $old_wh_res ? mysqli_fetch_assoc($old_wh_res) : false;
    $old_wh     = $old_wh_row ? (int)$old_wh_row['quantity'] : 0;

    $old_rt_res = mysqli_query($conn, "SELECT pieces_quantity FROM retail_stock WHERE product_id=$pid");
    $old_rt_row = $old_rt_res ? mysqli_fetch_assoc($old_rt_res) : false;
    $old_rt     = $old_rt_row ? (int)$old_rt_row['pieces_quantity'] : 0;

    mysqli_query($conn, "UPDATE stock SET quantity=$wh_qty WHERE product_id=$pid");
    mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity=$rt_qty WHERE product_id=$pid");
    recalcStockValue($conn, $pid);

    $today = date('Y-m-d');
    if ($reason !== '') {
        mysqli_query($conn, "INSERT INTO stock_movements (product_id, pieces_moved, moved_date, notes)
            VALUES ($pid, 0, '$today', 'Manual adjust — $reason')");
    }
    audit_log($conn, 'STOCK_ADJUST', 'stock', $pid, "Manual stock adjust product_id=$pid reason=$reason_raw",
        ['wh_qty' => $old_wh, 'rt_qty' => $old_rt],
        ['wh_qty' => $wh_qty, 'rt_qty' => $rt_qty]);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────
$sql = "
SELECT
    p.id,
    p.name,
    p.category,
    COALESCE(s.quantity,          0) AS wh_qty,
    COALESCE(s.pieces_per_package,1) AS ppp,
    COALESCE(s.package_price,     0) AS pkg_price,
    COALESCE(rs.pieces_quantity,  0) AS rt_qty,
    COALESCE(rs.retail_price,     0) AS rt_price,
    COALESCE(c.cost_wh, 0) AS cost_wh,
    COALESCE(c.cost_rt, 0) AS cost_rt,
    COALESCE(c.sell_wh, 0) AS sell_wh,
    COALESCE(c.sell_rt, 0) AS sell_rt,
    c.updated_at
FROM products p
LEFT JOIN stock s              ON s.product_id  = p.id
LEFT JOIN retail_stock rs      ON rs.product_id = p.id
LEFT JOIN stock_value_cache c  ON c.product_id  = p.id
WHERE p.deleted = 0
ORDER BY p.name
";
$res   = mysqli_query($conn, $sql);
$rows  = [];
while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Adjust</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .val-block { font-size:11px; color:var(--secondary); }
        .val-cost  { color:#059669; font-weight:600; }
        .val-sell  { color:#2563eb; font-weight:600; }
        .qty-chip  {
            display:inline-block; padding:2px 10px; border-radius:12px;
            font-weight:700; font-size:12px;
        }
        .chip-wh   { background:#dbeafe; color:#1e40af; }
        .chip-rt   { background:#d1fae5; color:#065f46; }
        .chip-zero { background:#f3f4f6; color:#9ca3af; }

        /* modal */
        .modal-overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,.45); z-index:1000;
            align-items:center; justify-content:center;
        }
        .modal-overlay.open { display:flex; }
        .modal-box {
            background:#fff; border-radius:16px; padding:28px 32px;
            width:100%; max-width:420px; box-shadow:0 20px 60px rgba(0,0,0,.2);
        }
        .modal-box h3 { margin:0 0 18px; font-size:16px; }
        .modal-field { margin-bottom:14px; }
        .modal-field label { display:block; font-size:12px; font-weight:600; margin-bottom:5px; color:var(--secondary); }
        .modal-field input, .modal-field textarea {
            width:100%; padding:8px 12px; border:1px solid var(--gray-200);
            border-radius:8px; font-size:13px; box-sizing:border-box;
        }
        .modal-field textarea { resize:vertical; min-height:58px; }
        .modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; }
        .badge-reason { font-size:11px; color:var(--secondary); margin-top:4px; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 style="margin:0;">Stock Adjust</h1>
                <p style="margin:4px 0 0;color:var(--secondary);font-size:12px;">
                    View cached stock values and correct warehouse / retail quantities when they drift.
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="text" id="srch" placeholder="Search product…"
                       oninput="applyFilter()"
                       style="padding:7px 12px;border:1px solid var(--gray-200);border-radius:8px;font-size:13px;min-width:200px;">
                <select id="sortSel" onchange="applySort()"
                        style="padding:7px 12px;border:1px solid var(--gray-200);border-radius:8px;font-size:13px;background:#fff;">
                    <option value="name-asc">Name A→Z</option>
                    <option value="name-desc">Name Z→A</option>
                    <option value="wh-desc">WH Qty ↓</option>
                    <option value="wh-asc">WH Qty ↑</option>
                    <option value="rt-desc">Retail Qty ↓</option>
                    <option value="rt-asc">Retail Qty ↑</option>
                    <option value="costwh-desc">Cost WH ↓</option>
                    <option value="sellwh-desc">Sell WH ↓</option>
                </select>
            </div>
        </div>

        <?php if (isset($_SESSION['flash_success'])): ?>
        <div class="alert alert-success" style="margin-bottom:16px;">
            <?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?>
        </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table" id="adjTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>WH Qty (pkgs)</th>
                        <th>WH Value</th>
                        <th>Retail Qty (pcs)</th>
                        <th>Retail Value</th>
                        <th>Cache updated</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $r): ?>
                <tr data-name="<?php echo strtolower(htmlspecialchars($r['name'])); ?>"
                    data-wh="<?php echo $r['wh_qty']; ?>"
                    data-rt="<?php echo $r['rt_qty']; ?>"
                    data-costwh="<?php echo $r['cost_wh']; ?>"
                    data-sellwh="<?php echo $r['sell_wh']; ?>">
                    <td style="color:var(--secondary);font-size:11px;"><?php echo $i+1; ?></td>
                    <td>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($r['name']); ?></div>
                        <div style="font-size:11px;color:var(--secondary);"><?php echo htmlspecialchars($r['category'] ?: '—'); ?></div>
                    </td>
                    <td>
                        <span class="qty-chip <?php echo $r['wh_qty'] > 0 ? 'chip-wh' : 'chip-zero'; ?>">
                            <?php echo number_format($r['wh_qty']); ?> pkg
                        </span>
                        <?php if ($r['ppp'] > 1): ?>
                        <div class="val-block"><?php echo number_format($r['wh_qty'] * $r['ppp']); ?> pcs total</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="val-block">Cost: <span class="val-cost">RWF <?php echo number_format($r['cost_wh'], 0); ?></span></div>
                        <div class="val-block">Sell: <span class="val-sell">RWF <?php echo number_format($r['sell_wh'], 0); ?></span></div>
                    </td>
                    <td>
                        <span class="qty-chip <?php echo $r['rt_qty'] > 0 ? 'chip-rt' : 'chip-zero'; ?>">
                            <?php echo number_format($r['rt_qty']); ?> pcs
                        </span>
                    </td>
                    <td>
                        <div class="val-block">Cost: <span class="val-cost">RWF <?php echo number_format($r['cost_rt'], 0); ?></span></div>
                        <div class="val-block">Sell: <span class="val-sell">RWF <?php echo number_format($r['sell_rt'], 0); ?></span></div>
                    </td>
                    <td style="font-size:11px;color:var(--secondary);">
                        <?php echo $r['updated_at'] ? date('M d, Y H:i', strtotime($r['updated_at'])) : '—'; ?>
                    </td>
                    <td>
                        <button class="btn btn-secondary btn-sm"
                                onclick="openModal(<?php echo $r['id']; ?>, <?php echo htmlspecialchars(json_encode($r['name'])); ?>, <?php echo $r['wh_qty']; ?>, <?php echo $r['rt_qty']; ?>)">
                            ✏ Adjust
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<!-- Adjust modal -->
<div class="modal-overlay" id="modal">
    <div class="modal-box">
        <h3 id="modalTitle">Adjust Stock</h3>
        <form id="adjForm">
            <input type="hidden" name="adjust" value="1">
            <input type="hidden" name="product_id" id="fPid">
            <div class="modal-field">
                <label>Warehouse qty (packages)</label>
                <input type="number" name="wh_qty" id="fWh" min="0" required>
            </div>
            <div class="modal-field">
                <label>Retail qty (pieces)</label>
                <input type="number" name="rt_qty" id="fRt" min="0" required>
            </div>
            <div class="modal-field">
                <label>Reason (optional — saved to movement log)</label>
                <textarea name="reason" id="fReason" placeholder="e.g. Physical count correction"></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="saveBtn">Save</button>
            </div>
        </form>
    </div>
</div>

<script src="script.js"></script>
<script>
function openModal(pid, name, whQty, rtQty) {
    document.getElementById('modalTitle').textContent = 'Adjust — ' + name;
    document.getElementById('fPid').value = pid;
    document.getElementById('fWh').value  = whQty;
    document.getElementById('fRt').value  = rtQty;
    document.getElementById('fReason').value = '';
    document.getElementById('modal').classList.add('open');
    document.getElementById('fWh').focus();
}

function closeModal() {
    document.getElementById('modal').classList.remove('open');
}

document.getElementById('modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.getElementById('adjForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    var data = new FormData(this);
    fetch('stock_adjust.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok) location.reload();
        })
        .finally(function() {
            btn.disabled = false;
            btn.textContent = 'Save';
        });
});

function applyFilter() {
    var q = document.getElementById('srch').value.toLowerCase();
    document.querySelectorAll('#adjTable tbody tr').forEach(function(row) {
        row.style.display = row.dataset.name.includes(q) ? '' : 'none';
    });
    reindex();
}

function applySort() {
    var val  = document.getElementById('sortSel').value;
    var tbody = document.querySelector('#adjTable tbody');
    var rows  = Array.from(tbody.querySelectorAll('tr'));

    rows.sort(function(a, b) {
        switch (val) {
            case 'name-asc':   return a.dataset.name.localeCompare(b.dataset.name);
            case 'name-desc':  return b.dataset.name.localeCompare(a.dataset.name);
            case 'wh-desc':    return parseFloat(b.dataset.wh)     - parseFloat(a.dataset.wh);
            case 'wh-asc':     return parseFloat(a.dataset.wh)     - parseFloat(b.dataset.wh);
            case 'rt-desc':    return parseFloat(b.dataset.rt)     - parseFloat(a.dataset.rt);
            case 'rt-asc':     return parseFloat(a.dataset.rt)     - parseFloat(b.dataset.rt);
            case 'costwh-desc':return parseFloat(b.dataset.costwh) - parseFloat(a.dataset.costwh);
            case 'sellwh-desc':return parseFloat(b.dataset.sellwh) - parseFloat(a.dataset.sellwh);
            default: return 0;
        }
    });
    rows.forEach(function(r) { tbody.appendChild(r); });
    reindex();
}

function reindex() {
    var i = 1;
    document.querySelectorAll('#adjTable tbody tr').forEach(function(row) {
        if (row.style.display !== 'none') row.cells[0].textContent = i++;
    });
}
</script>
</body>
</html>
