<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');

// Ensure purchase_levels table exists
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `purchase_levels` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `purchase_id`    INT NOT NULL,
    `level_order`    TINYINT NOT NULL,
    `level_name`     VARCHAR(100) NOT NULL,
    `qty_per_parent` INT NOT NULL DEFAULT 1,
    `selling_price`  DECIMAL(10,2) NOT NULL DEFAULT 0,
    INDEX (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// ── AJAX handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $product_id    = (int)($_POST['product_id'] ?? 0);
    $supplier_id   = empty($_POST['supplier_id']) ? 'NULL' : (int)$_POST['supplier_id'];
    $quantity      = max(1, (int)($_POST['quantity'] ?? 1));
    $cost_price    = max(0, (float)($_POST['cost_price'] ?? 0));
    $purchase_date = mysqli_real_escape_string($conn, $_POST['purchase_date'] ?? date('Y-m-d'));

    $names  = array_values($_POST['level_name']  ?? []);
    $qtys   = array_values($_POST['level_qty']   ?? []);
    $prices = array_values($_POST['level_price'] ?? []);

    // Build levels — skip blank rows
    $levels = [];
    foreach ($names as $i => $raw_name) {
        $name = trim($raw_name);
        if ($name === '') continue;
        $levels[] = [
            'order'          => count($levels) + 1,
            'name'           => $name,
            'qty_per_parent' => ($i === 0) ? 1 : max(1, (int)($qtys[$i] ?? 1)),
            'price'          => max(0, (float)($prices[$i] ?? 0)),
        ];
    }

    if ($product_id < 1) {
        echo json_encode(['ok' => false, 'message' => 'Please select a product.']);
        exit;
    }
    if (empty($levels)) {
        echo json_encode(['ok' => false, 'message' => 'Add at least one packaging level.']);
        exit;
    }

    // pieces_per_qty = product of all sub-level multipliers (levels 2, 3, …)
    $pieces_per_qty = 1;
    for ($i = 1; $i < count($levels); $i++) {
        $pieces_per_qty *= $levels[$i]['qty_per_parent'];
    }

    $package_price = (float)$levels[0]['price'];
    $retail_price  = (float)$levels[count($levels) - 1]['price'];

    mysqli_begin_transaction($conn);
    $ok = true;

    $ok = (bool)mysqli_query($conn, "
        INSERT INTO purchases (product_id, supplier_id, quantity, pieces_per_qty,
            cost_price, package_price, retail_price, purchase_date)
        VALUES ($product_id, $supplier_id, $quantity, $pieces_per_qty,
            $cost_price, $package_price, $retail_price, '$purchase_date')
    ");
    $purchase_id = $ok ? (int)mysqli_insert_id($conn) : 0;

    if ($ok) {
        foreach ($levels as $lvl) {
            $n  = mysqli_real_escape_string($conn, $lvl['name']);
            $ok = (bool)mysqli_query($conn, "
                INSERT INTO purchase_levels (purchase_id, level_order, level_name, qty_per_parent, selling_price)
                VALUES ($purchase_id, {$lvl['order']}, '$n', {$lvl['qty_per_parent']}, {$lvl['price']})
            ");
            if (!$ok) break;
        }
    }

    if ($ok) {
        $check = mysqli_query($conn, "SELECT id FROM stock WHERE product_id = $product_id");
        if (mysqli_num_rows($check) > 0) {
            mysqli_query($conn, "
                UPDATE stock SET
                    quantity           = quantity + $quantity,
                    pieces_per_package = $pieces_per_qty,
                    package_price      = $package_price,
                    retail_price       = $retail_price
                WHERE product_id = $product_id
            ");
        } else {
            mysqli_query($conn, "
                INSERT INTO stock (product_id, quantity, pieces_per_package, package_price, retail_price)
                VALUES ($product_id, $quantity, $pieces_per_qty, $package_price, $retail_price)
            ");
        }
        mysqli_commit($conn);
        echo json_encode(['ok' => true, 'message' => 'Purchase recorded successfully.']);
    } else {
        mysqli_rollback($conn);
        echo json_encode(['ok' => false, 'message' => 'DB error: ' . mysqli_error($conn)]);
    }
    exit;
}

// ── Page render ───────────────────────────────────────────────────────────────
$products_r = mysqli_query($conn, "SELECT id, name, category FROM products WHERE deleted = 0 ORDER BY category, name");
$products   = [];
while ($r = mysqli_fetch_assoc($products_r)) $products[] = $r;

$suppliers_r = mysqli_query($conn, "SELECT id, name FROM suppliers ORDER BY name");
$suppliers   = [];
while ($r = mysqli_fetch_assoc($suppliers_r)) $suppliers[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Purchase</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .page-header {
            display: flex; align-items: center; gap: 16px; margin-bottom: 28px;
        }
        .page-header h1 { margin: 0; font-size: 22px; font-weight: 700; }
        .back-link {
            display: inline-flex; align-items: center; gap: 6px;
            color: var(--secondary); text-decoration: none; font-size: 14px;
            padding: 6px 12px; border: 1px solid var(--gray-300);
            border-radius: var(--radius); transition: background .15s;
        }
        .back-link:hover { background: var(--gray-100); }

        .form-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 24px;
        }
        @media (max-width: 768px) { .form-grid { grid-template-columns: 1fr; } }

        .card {
            background: var(--white); border: 1px solid var(--gray-200);
            border-radius: 12px; padding: 24px; box-shadow: var(--shadow-sm);
        }
        .card-title {
            font-size: 14px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .5px; color: var(--secondary);
            margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid var(--gray-200);
        }

        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--dark);
        }
        .form-group input, .form-group select {
            width: 100%; padding: 10px 12px; border: 1px solid var(--gray-300);
            border-radius: var(--radius); font-size: 14px; background: var(--white);
            transition: border-color .15s, box-shadow .15s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,.12);
        }

        /* Searchable product select */
        .searchable-wrap { position: relative; }
        .searchable-dropdown {
            display: none; position: absolute; top: 100%; left: 0; right: 0;
            max-height: 220px; overflow-y: auto; background: var(--white);
            border: 1px solid var(--gray-300); border-top: none;
            border-radius: 0 0 var(--radius) var(--radius); z-index: 200; box-shadow: var(--shadow-md);
        }
        .searchable-dropdown.open { display: block; }
        .sd-option { padding: 9px 12px; cursor: pointer; font-size: 14px; }
        .sd-option:hover, .sd-option.hl { background: var(--gray-100); color: var(--primary); }
        .sd-option.hidden { display: none; }

        /* Levels builder */
        .levels-card { grid-column: 1 / -1; }
        .level-row {
            display: grid; grid-template-columns: 32px 1fr 1fr 1fr 36px;
            gap: 10px; align-items: end; padding: 14px;
            background: var(--gray-100); border-radius: var(--radius);
            margin-bottom: 10px; border: 1px solid var(--gray-200);
        }
        .level-row.top-level { border-left: 3px solid var(--primary); }
        .level-row.mid-level { border-left: 3px solid var(--warning); }
        .level-row.bot-level { border-left: 3px solid var(--success); }
        .level-badge {
            font-size: 11px; font-weight: 700; color: var(--secondary);
            writing-mode: vertical-rl; text-align: center; user-select: none;
        }
        .level-row .form-group { margin-bottom: 0; }
        .btn-remove {
            width: 32px; height: 32px; border-radius: 50%;
            background: none; border: 1px solid var(--gray-300);
            color: var(--danger); cursor: pointer; font-size: 16px;
            display: flex; align-items: center; justify-content: center;
            transition: background .15s; align-self: end; margin-bottom: 2px;
        }
        .btn-remove:hover { background: #fee2e2; border-color: var(--danger); }
        .add-level-btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px;
            background: var(--gray-100); border: 1.5px dashed var(--gray-300);
            border-radius: var(--radius); font-size: 14px; font-weight: 600;
            color: var(--secondary); cursor: pointer; transition: background .15s, border-color .15s;
            margin-top: 4px;
        }
        .add-level-btn:hover { background: var(--gray-200); border-color: var(--primary); color: var(--primary); }

        /* Summary box */
        .summary-box {
            grid-column: 1 / -1;
            background: linear-gradient(135deg, #eff6ff, #f0fdf4);
            border: 1px solid #bfdbfe; border-radius: 12px; padding: 20px 24px;
        }
        .summary-title {
            font-size: 12px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .5px; color: var(--secondary); margin-bottom: 14px;
        }
        .summary-chain {
            display: flex; align-items: center; flex-wrap: wrap; gap: 8px;
            font-size: 14px; font-weight: 600;
        }
        .summary-node {
            background: var(--white); border: 1px solid var(--gray-300);
            border-radius: 20px; padding: 6px 14px;
            display: flex; align-items: center; gap: 6px;
        }
        .summary-node .qty { color: var(--primary); font-size: 16px; }
        .summary-node .unit { color: var(--dark); }
        .summary-arrow { color: var(--gray-300); font-size: 20px; }
        .summary-total {
            margin-top: 14px; padding-top: 12px; border-top: 1px solid #bfdbfe;
            font-size: 13px; color: var(--secondary);
        }
        .summary-total strong { color: var(--dark); }

        .form-actions {
            grid-column: 1 / -1; display: flex; gap: 12px;
            justify-content: flex-end; margin-top: 8px;
        }

        /* Preset examples */
        .preset-label {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .6px; color: var(--secondary); display: block; margin-bottom: 10px;
        }
        .preset-cards { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
        .preset-card {
            padding: 8px 14px; border: 1.5px solid var(--gray-200); border-radius: var(--radius);
            background: var(--gray-100); cursor: pointer; text-align: left;
            transition: border-color .15s, background .15s;
            display: flex; flex-direction: column; gap: 3px;
        }
        .preset-card:hover { border-color: var(--primary); background: #eff6ff; }
        .preset-card.recommended { border-color: #bfdbfe; background: #eff6ff; }
        .pc-title { font-size: 12px; font-weight: 700; color: var(--dark); }
        .pc-chain { font-size: 11px; color: var(--secondary); }
        .preset-card:hover .pc-title,
        .preset-card.recommended .pc-title { color: var(--primary); }
        .preset-card.active {
            border-color: var(--primary); background: #dbeafe;
        }
        .preset-card.active .pc-title { color: var(--primary); }
        .preset-card.active .pc-chain { color: var(--primary-dark, #1d4ed8); }

        /* Toast */
        .toast {
            position: fixed; top: 24px; right: 24px; z-index: 9999;
            padding: 14px 20px; border-radius: 10px; font-size: 14px; font-weight: 600;
            box-shadow: var(--shadow-lg); opacity: 0; transform: translateY(-12px);
            transition: opacity .25s, transform .25s; pointer-events: none; max-width: 360px;
        }
        .toast.show { opacity: 1; transform: translateY(0); pointer-events: auto; }
        .toast.success { background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; }
        .toast.error   { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }

        @media (max-width: 600px) {
            .level-row { grid-template-columns: 1fr 1fr; grid-template-rows: auto auto auto; }
            .level-badge { writing-mode: horizontal-tb; grid-column: 1 / -1; }
            .btn-remove { grid-column: 2; justify-self: end; }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <a href="purchases.php" class="back-link">&#8592; Purchases</a>
            <h1>New Purchase</h1>
        </div>

        <form id="purchaseForm">

            <div class="form-grid">

                <!-- ── Basic info ──────────────────────────────────────────── -->
                <div class="card">
                    <div class="card-title">Purchase Info</div>

                    <div class="form-group">
                        <label>Product *</label>
                        <div class="searchable-wrap" id="productWrap">
                            <input type="hidden" id="product_id" name="product_id">
                            <input type="text" id="product_search" placeholder="Search product…"
                                   autocomplete="off">
                            <div class="searchable-dropdown" id="product_dropdown">
                                <?php foreach ($products as $p): ?>
                                    <div class="sd-option" data-value="<?= $p['id'] ?>">
                                        <?= htmlspecialchars($p['category'] . ' — ' . $p['name']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Purchase Date *</label>
                        <input type="date" name="purchase_date" id="purchase_date"
                               value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="form-group">
                        <label>Supplier</label>
                        <select name="supplier_id">
                            <option value="">— None —</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- ── Quantity & Cost ─────────────────────────────────────── -->
                <div class="card">
                    <div class="card-title">Quantity & Cost</div>

                    <div class="form-group">
                        <label id="qty-label">Quantity Purchased *</label>
                        <input type="text" name="quantity" id="quantity" min="1" value="1"
                               oninput="updateSummary()">
                    </div>

                    <div class="form-group">
                        <label>Cost Price per unit (RWF) *</label>
                        <input type="text" name="cost_price" id="cost_price" min="0" step="1"
                               placeholder="0" oninput="updateSummary();suggestPrices()">
                    </div>

                    <div class="form-group">
                        <label for="suggest_rate">Markup Rate <small style="color:var(--secondary);font-weight:400;">(e.g. 1.05 = 5% profit)</small></label>
                        <input type="text" id="suggest_rate" value="1.05" style="max-width:120px;"
                               oninput="suggestPrices()">
                    </div>
                </div>

                <!-- ── Packaging levels ────────────────────────────────────── -->
                <div class="card levels-card">
                    <div class="card-title">Packaging Levels</div>
                    <p style="font-size:13px;color:var(--secondary);margin-bottom:16px;">
                        Define each level from the biggest container down to the smallest unit.
                        Clients can buy at any level. Set the selling price for each level.
                    </p>

                    <!-- Preset examples -->
                    <span class="preset-label">Load an example to get started:</span>
                    <div class="preset-cards">
                        <button type="button" class="preset-card" onclick="loadPreset('single',this)">
                            <span class="pc-title">1 Level — Single item</span>
                            <span class="pc-chain">Item &nbsp;(e.g. phone, screen)</span>
                        </button>
                        <button type="button" class="preset-card" onclick="loadPreset('two',this)">
                            <span class="pc-title">2 Levels — Box &rarr; Piece</span>
                            <span class="pc-chain">Box &rarr; Piece &times;10</span>
                        </button>
                        <button type="button" class="preset-card recommended" onclick="loadPreset('three',this)">
                            <span class="pc-title">3 Levels — Big &rarr; Small &rarr; Piece</span>
                            <span class="pc-chain">Big Container &rarr; Small &times;4 &rarr; Piece &times;24</span>
                        </button>
                        <button type="button" class="preset-card" onclick="loadPreset('four',this)">
                            <span class="pc-title">4 Levels — Carton chain</span>
                            <span class="pc-chain">Carton &rarr; Box &times;6 &rarr; Pack &times;12 &rarr; Piece &times;10</span>
                        </button>
                        <button type="button" class="preset-card" onclick="loadPreset('weight',this)">
                            <span class="pc-title">Weight — Sack &rarr; Kg</span>
                            <span class="pc-chain">Sack &rarr; Kg &times;50 &nbsp;(rice, sugar, flour…)</span>
                        </button>
                    </div>

                    <div id="levelsContainer"></div>
                    <button type="button" class="add-level-btn" onclick="addLevel()">+ Add Level</button>
                </div>

                <!-- ── Live summary ────────────────────────────────────────── -->
                <div class="summary-box">
                    <div class="summary-title">Breakdown Summary</div>
                    <div class="summary-chain" id="summaryChain">
                        <span style="color:var(--secondary);font-size:13px;">Add levels above to see the breakdown.</span>
                    </div>
                    <div class="summary-total" id="summaryTotal"></div>
                </div>

                <!-- ── Actions ────────────────────────────────────────────── -->
                <div class="form-actions">
                    <a href="purchases.php" class="btn btn-secondary">Cancel</a>
                    <button type="button" class="btn btn-primary" id="saveBtn" onclick="submitPurchase()">
                        Save Purchase
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>

<!-- Toast notification -->
<div class="toast" id="toast"></div>

<script>
// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type + ' show';
    clearTimeout(t._timer);
    t._timer = setTimeout(function () { t.classList.remove('show'); }, 3500);
}

// ── AJAX submit ───────────────────────────────────────────────────────────────
function submitPurchase() {
    var productId = document.getElementById('product_id').value;
    if (!productId) { showToast('Please select a product.', 'error'); return; }

    var quantity  = parseInt(document.getElementById('quantity').value) || 0;
    if (quantity < 1) { showToast('Quantity must be at least 1.', 'error'); return; }

    var costPrice = document.getElementById('cost_price').value;
    if (!costPrice || parseFloat(costPrice) < 0) { showToast('Enter a valid cost price.', 'error'); return; }

    var rows = document.getElementById('levelsContainer').querySelectorAll('.level-row');
    if (rows.length === 0) { showToast('Add at least one packaging level.', 'error'); return; }

    var hasBlankLevel = false;
    rows.forEach(function (r) {
        if (!r.querySelector('input[name="level_name[]"]').value.trim()) hasBlankLevel = true;
    });
    if (hasBlankLevel) { showToast('All level names are required.', 'error'); return; }

    var btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.textContent = 'Saving…';

    fetch('new-purchase.php', {
        method: 'POST',
        body: new FormData(document.getElementById('purchaseForm'))
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
        if (data.ok) {
            showToast(data.message, 'success');
            document.getElementById('purchaseForm').reset();
            document.getElementById('levelsContainer').innerHTML = '';
            levelCount = 0;
            loadPreset('two', null);
            document.getElementById('product_search').value = '';
            document.getElementById('product_id').value = '';
            document.getElementById('product_dropdown').classList.remove('open');
            btn.disabled = false;
            btn.textContent = 'Save Purchase';
        } else {
            showToast(data.message, 'error');
            btn.disabled = false;
            btn.textContent = 'Save Purchase';
        }
    })
    .catch(function () {
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false;
        btn.textContent = 'Save Purchase';
    });
}

// ── Searchable product dropdown ───────────────────────────────────────────────
(function () {
    var hidden   = document.getElementById('product_id');
    var search   = document.getElementById('product_search');
    var dropdown = document.getElementById('product_dropdown');
    var options  = dropdown.querySelectorAll('.sd-option');
    var hlIdx    = -1;

    search.addEventListener('focus', function () { dropdown.classList.add('open'); filter(); });
    search.addEventListener('input', function () { dropdown.classList.add('open'); hlIdx = -1; filter(); });
    search.addEventListener('keydown', function (e) {
        var vis = dropdown.querySelectorAll('.sd-option:not(.hidden)');
        if (e.key === 'ArrowDown') { e.preventDefault(); hlIdx = Math.min(hlIdx+1, vis.length-1); hl(vis); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); hlIdx = Math.max(hlIdx-1, 0); hl(vis); }
        else if (e.key === 'Enter') { e.preventDefault(); if (vis[hlIdx]) pick(vis[hlIdx]); }
        else if (e.key === 'Escape') { dropdown.classList.remove('open'); }
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#productWrap')) dropdown.classList.remove('open');
    });
    options.forEach(function (o) { o.addEventListener('click', function () { pick(o); }); });

    function filter() {
        var t = search.value.toLowerCase();
        options.forEach(function (o) { o.classList.toggle('hidden', o.textContent.toLowerCase().indexOf(t) < 0); });
    }
    function hl(vis) {
        options.forEach(function (o) { o.classList.remove('hl'); });
        if (vis[hlIdx]) { vis[hlIdx].classList.add('hl'); vis[hlIdx].scrollIntoView({block:'nearest'}); }
    }
    function pick(o) {
        hidden.value = o.dataset.value;
        search.value = o.textContent.trim();
        dropdown.classList.remove('open');
        hlIdx = -1;
    }
})();

// ── Level builder ─────────────────────────────────────────────────────────────
var levelCount = 0;

function addLevel() {
    levelCount++;
    var container = document.getElementById('levelsContainer');
    var isFirst   = (container.querySelectorAll('.level-row').length === 0);

    container.querySelectorAll('.level-row').forEach(function (r, i) {
        r.classList.remove('top-level','mid-level','bot-level');
        r.classList.add(i === 0 ? 'top-level' : 'mid-level');
    });

    var row = document.createElement('div');
    row.className = 'level-row bot-level';

    row.innerHTML =
        '<div class="level-badge">L' + levelCount + '</div>' +

        '<div class="form-group">' +
            '<label>Level Name *</label>' +
            '<input type="text" name="level_name[]" placeholder="e.g. ' + defaultName(levelCount) + '"' +
            ' oninput="updateSummary();updateLabels()">' +
        '</div>' +

        '<div class="form-group qty-group"' + (isFirst ? ' style="visibility:hidden"' : '') + '>' +
            '<label class="qty-label">Qty per level above</label>' +
            '<input type="text" name="level_qty[]" min="1" value="1" oninput="updateSummary();suggestPrices()">' +
        '</div>' +

        '<div class="form-group">' +
            '<label>Selling Price (RWF) <small class="level-price-hint" style="color:var(--secondary);font-weight:400;"></small></label>' +
            '<input type="text" name="level_price[]" min="0" step="1" value="0" data-edited="0" oninput="this.dataset.edited=\'1\'">' +
        '</div>' +

        '<button type="button" class="btn-remove" onclick="removeLevel(this)"' +
            (isFirst ? ' style="visibility:hidden"' : '') + '>&#x2715;</button>';

    container.appendChild(row);
    updateSummary();
    updateLabels();
    suggestPrices();
    row.querySelector('input[name="level_name[]"]').focus();
}

function defaultName(n) {
    return (['Big Container','Small Container','Piece','Pack','Unit'])[n-1] || 'Level ' + n;
}

function removeLevel(btn) {
    var container = document.getElementById('levelsContainer');
    if (container.querySelectorAll('.level-row').length <= 1) return;
    btn.closest('.level-row').remove();

    container.querySelectorAll('.level-row').forEach(function (r, i) {
        r.querySelector('.level-badge').textContent = 'L' + (i+1);
        r.classList.remove('top-level','mid-level','bot-level');
        var total = container.querySelectorAll('.level-row').length;
        if (i === 0) {
            r.classList.add('top-level');
            r.querySelector('.qty-group').style.visibility = 'hidden';
            r.querySelector('.btn-remove').style.visibility = 'hidden';
        } else {
            r.classList.add(i === total - 1 ? 'bot-level' : 'mid-level');
            r.querySelector('.qty-group').style.visibility = '';
            r.querySelector('.btn-remove').style.visibility = '';
        }
    });
    updateSummary();
    updateLabels();
}

function updateLabels() {
    var rows = document.getElementById('levelsContainer').querySelectorAll('.level-row');
    rows.forEach(function (row, i) {
        if (i === 0) return;
        var parentName = rows[i-1].querySelector('input[name="level_name[]"]').value.trim() || ('Level ' + i);
        row.querySelector('.qty-label').textContent = 'Qty per ' + parentName;
    });
    if (rows[0]) {
        var topName = rows[0].querySelector('input[name="level_name[]"]').value.trim() || 'top-level unit';
        document.getElementById('qty-label').textContent = 'Quantity Purchased (' + topName + 's) *';
    }
}

// ── Price suggestion (rate read from #suggest_rate input) ────────────────────
function suggestPrices() {
    var cost = parseFloat(document.getElementById('cost_price').value) || 0;
    if (cost <= 0) return;
    var rate = parseFloat(document.getElementById('suggest_rate').value) || 1;
    if (rate <= 0) return;
    var rows = document.getElementById('levelsContainer').querySelectorAll('.level-row');
    var divisor = 1;
    rows.forEach(function(row, i) {
        if (i > 0) {
            var q = parseInt(row.querySelector('input[name="level_qty[]"]').value) || 1;
            divisor *= q;
        }
        var priceInput = row.querySelector('input[name="level_price[]"]');
        if (priceInput && priceInput.dataset.edited === '0') {
            priceInput.value = Math.round(cost / divisor * rate);
        }
    });
    // Update label hints on all level price fields
    var pct = Math.round((rate - 1) * 100);
    document.querySelectorAll('.level-price-hint').forEach(function(el) {
        el.textContent = '×' + rate.toFixed(2) + ' (' + (pct >= 0 ? '+' : '') + pct + '%)';
    });
}

function updateSummary() {
    var chain = document.getElementById('summaryChain');
    var total = document.getElementById('summaryTotal');
    var rows  = document.getElementById('levelsContainer').querySelectorAll('.level-row');
    var qty   = parseInt(document.getElementById('quantity').value) || 0;

    if (!rows.length || !qty) {
        chain.innerHTML = '<span style="color:var(--secondary);font-size:13px;">Add levels above to see the breakdown.</span>';
        total.innerHTML = '';
        return;
    }

    var parts = [], running = qty;
    rows.forEach(function (row, i) {
        var name  = row.querySelector('input[name="level_name[]"]').value.trim() || ('Level ' + (i+1));
        var qtyPP = i === 0 ? 1 : (parseInt(row.querySelector('input[name="level_qty[]"]').value) || 1);
        if (i > 0) running *= qtyPP;
        if (i > 0) parts.push('<span class="summary-arrow">&#8594;</span>');
        parts.push('<div class="summary-node"><span class="qty">' + running.toLocaleString() +
            '</span><span class="unit">' + escHtml(name) + '</span></div>');
    });
    chain.innerHTML = parts.join('');

    var costPrice = parseFloat(document.getElementById('cost_price').value) || 0;
    var totalCost = qty * costPrice;
    var topName   = rows[0].querySelector('input[name="level_name[]"]').value.trim() || 'unit';
    var botName   = rows[rows.length-1].querySelector('input[name="level_name[]"]').value.trim() || 'piece';

    total.innerHTML =
        '<strong>' + qty.toLocaleString() + '</strong> ' + escHtml(topName) +
        (rows.length > 1 ? ' = <strong>' + running.toLocaleString() + '</strong> ' + escHtml(botName) : '') +
        (totalCost > 0 ? ' &nbsp;|&nbsp; Total cost: <strong>RWF ' + totalCost.toLocaleString() + '</strong>' : '');
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Presets ───────────────────────────────────────────────────────────────────
var PRESETS = {
    single: [
        { name: 'Item', qty: 1 }
    ],
    two: [
        { name: 'Box',   qty: 1  },
        { name: 'Piece', qty: 10 }
    ],
    three: [
        { name: 'Big Container',   qty: 1  },
        { name: 'Small Container', qty: 4  },
        { name: 'Piece',           qty: 24 }
    ],
    four: [
        { name: 'Carton', qty: 1  },
        { name: 'Box',    qty: 6  },
        { name: 'Pack',   qty: 12 },
        { name: 'Piece',  qty: 10 }
    ],
    weight: [
        { name: 'Sack', qty: 1  },
        { name: 'Kg',   qty: 50 }
    ]
};

function loadPreset(key, btn) {
    var preset = PRESETS[key];
    if (!preset) return;

    // Mark clicked card as active
    document.querySelectorAll('.preset-card').forEach(function (c) { c.classList.remove('active'); });
    if (btn) btn.classList.add('active');

    var container = document.getElementById('levelsContainer');
    container.innerHTML = '';
    levelCount = 0;

    preset.forEach(function (entry, i) {
        addLevel();
        var rows = container.querySelectorAll('.level-row');
        var row  = rows[rows.length - 1];
        row.querySelector('input[name="level_name[]"]').value = entry.name;
        if (i > 0) {
            var qtyInput = row.querySelector('input[name="level_qty[]"]');
            if (qtyInput) qtyInput.value = entry.qty;
        }
    });

    updateSummary();
    updateLabels();
    suggestPrices();
}

addLevel();
</script>
</body>
</html>
