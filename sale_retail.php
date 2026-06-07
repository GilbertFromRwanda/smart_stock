<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');

if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

$retail_products = mysqli_query($conn, "
    SELECT r.*, p.name, p.unit_measure, p.category
    FROM retail_stock r
    JOIN products p ON r.product_id = p.id
    WHERE r.pieces_quantity > 0
    ORDER BY p.category, p.name
");

$loan_clients_query = mysqli_query($conn, "
    SELECT name AS client, phone, total_loans AS visits, unpaid_amount AS outstanding
    FROM loan_clients ORDER BY updated_at DESC
");
$loan_clients_arr = [];
while ($c = mysqli_fetch_assoc($loan_clients_query)) $loan_clients_arr[] = $c;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gucuruza Detaye - Retail Sale - Smart Stock</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/sales.css">
    <style>
        .sale-page-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: 32px;
            max-width: 960px;
        }
        .form-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 20px;
        }
        .pay-sum-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            align-items: start;
            margin-bottom: 16px;
        }
        .sale-page-header {
            display: flex; align-items: center; gap: 16px; margin-bottom: 28px;
        }
        .sale-page-header h1 { margin: 0; font-size: 22px; }
        .back-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: var(--radius);
            background: var(--gray-100); color: var(--dark);
            text-decoration: none; font-size: 13px; font-weight: 500;
            border: 1px solid var(--gray-300);
        }
        .back-btn:hover { background: var(--gray-200); }
        .searchable-select { position: relative; }
        .searchable-select-input {
            width: 100%; padding: 10px 12px;
            border: 1px solid var(--gray-300); border-radius: var(--radius);
            font-size: 14px; background: var(--white); cursor: text;
        }
        .searchable-select-input:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,.15);
        }
        .searchable-select-dropdown {
            display: none; position: absolute; top: 100%; left: 0; right: 0;
            max-height: 220px; overflow-y: auto; background: var(--white);
            border: 1px solid var(--gray-300); border-top: none;
            border-radius: 0 0 var(--radius) var(--radius);
            z-index: 1000; box-shadow: var(--shadow-md);
        }
        .searchable-select-dropdown.open { display: block; }
        .searchable-select-option { padding: 9px 12px; cursor: pointer; font-size: 14px; }
        .searchable-select-option:hover,
        .searchable-select-option.highlighted { background: var(--gray-100); color: var(--primary); }
        .searchable-select-option.hidden { display: none; }
        .split-payment-box { border: 1px solid var(--gray-300); border-radius: var(--radius); overflow: hidden; }
        .split-row { display: flex; align-items: center; padding: 8px 12px; gap: 10px; border-bottom: 1px solid var(--gray-100); }
        .split-row:last-child { border-bottom: none; }
        .split-label { width: 70px; font-size: 13px; font-weight: 500; flex-shrink: 0; }
        .split-row input[type="text"] { flex: 1; padding: 6px 10px; border: 1px solid var(--gray-300); border-radius: var(--radius); font-size: 14px; }
        .split-remaining-row { justify-content: space-between; background: var(--gray-50); font-weight: 600; }
        .split-remaining-row.valid  { background: #ecfdf5; color: #059669; }
        .split-remaining-row.invalid { background: #fef2f2; color: #dc2626; }
        .lvl-btn {
            display: inline-flex; flex-direction: column; align-items: center;
            padding: 8px 14px; border: 1.5px solid var(--gray-300);
            border-radius: var(--radius); cursor: pointer; background: var(--white);
            transition: all .15s; min-width: 100px; gap: 2px;
        }
        .lvl-btn:hover { border-color: var(--primary); background: #eff6ff; }
        .lvl-btn.active { border-color: var(--primary); background: #eff6ff; }
        .lvl-btn-name  { font-size: 13px; font-weight: 700; color: var(--dark); }
        .lvl-btn-stock { font-size: 11px; color: var(--secondary); }
        .lvl-btn-price { font-size: 14px; font-weight: 700; color: var(--primary); }
        .lvl-btn.active .lvl-btn-name,
        .lvl-btn.active .lvl-btn-stock { color: var(--primary); }
        .field-hint  { display: block; font-size: 12px; color: var(--secondary); margin-top: 4px; }
        .field-error { display: none; font-size: 12px; color: #dc2626; margin-top: 4px; }
        .price-warning { display: none; font-size: 12px; color: #d97706; margin-top: 4px; background: #fffbeb; padding: 4px 8px; border-radius: 4px; }
        .price-input-group { display: flex; gap: 8px; align-items: center; }
        .price-input-group input { flex: 1; }
        .default-price-badge {
            font-size: 11px; background: var(--gray-100); color: var(--secondary);
            border: 1px solid var(--gray-300); border-radius: 4px;
            padding: 4px 8px; cursor: pointer; white-space: nowrap;
        }
        .default-price-badge:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
        #saleToast {
            display: none; position: fixed; bottom: 24px; right: 24px;
            padding: 12px 20px; border-radius: 8px; font-size: 14px; font-weight: 600;
            z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,.15);
        }
        #saleToast.show { display: block; }
        #saleToast.toast-success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        #saleToast.toast-error   { background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="sale-page-card">
            <div class="sale-page-header">
                <a href="sales.php?tab=retail" class="back-btn">&#8592; Back</a>
                <h1>Gucuruza Detaye &mdash; Retail Sale</h1>
            </div>

            <form method="POST" action="sales.php" id="retailSaleForm">
                <div class="form-group">
                    <label>Select Product*</label>
                    <select id="retail_product_id" name="product_id" required onchange="updateRetailProductDetails()" style="display:none">
                        <option value="">Choose product...</option>
                        <?php while ($row = mysqli_fetch_assoc($retail_products)): ?>
                            <option value="<?php echo $row['product_id']; ?>"
                                    data-price="<?php echo $row['retail_price']; ?>"
                                    data-stock="<?php echo $row['pieces_quantity']; ?>"
                                    data-product-name="<?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>"
                                    data-unit="<?php echo htmlspecialchars($row['unit_measure']); ?>">
                                <?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <div class="searchable-select" id="retailProductSearchable">
                        <input type="text" class="searchable-select-input" id="retail_product_search" placeholder="Search product..." autocomplete="off">
                        <div class="searchable-select-dropdown" id="retail_product_dropdown">
                            <?php
                            mysqli_data_seek($retail_products, 0);
                            while ($row = mysqli_fetch_assoc($retail_products)):
                            ?>
                                <div class="searchable-select-option"
                                     data-value="<?php echo $row['product_id']; ?>"
                                     data-price="<?php echo $row['retail_price']; ?>"
                                     data-stock="<?php echo $row['pieces_quantity']; ?>"
                                     data-product-name="<?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>"
                                     data-unit="<?php echo htmlspecialchars($row['unit_measure']); ?>">
                                    <?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>

                <div id="retail_product_details" class="price-history" style="display:none;">
                    <strong>Product Info:</strong> <span id="retail_product_info"></span>
                </div>

                <div id="retail_level_selector" style="display:none;margin-bottom:16px;">
                    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:8px;">Select Selling Level</label>
                    <div id="retail_level_buttons" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
                </div>

                <div class="form-2col">
                    <div class="form-group">
                        <label id="retail_qty_label">Number of Pieces*</label>
                        <input type="text" id="pieces_sold" name="pieces_sold" required min="1" value="1" oninput="calculateRetailTotal()">
                        <small id="retail_stock_info" class="field-hint"></small>
                        <small id="retail_qty_error" class="field-error"></small>
                    </div>
                    <div class="form-group">
                        <label>Selling Price (per piece)*</label>
                        <div class="price-input-group">
                            <input type="text" id="retail_selling_price" name="selling_price" required min="1" oninput="calculateRetailTotal()">
                            <span class="default-price-badge" onclick="setRetailDefaultPrice()">Use Default</span>
                        </div>
                        <div id="retail_price_warning" class="price-warning"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Customer Name</label>
                    <input type="text" id="retail_customer" name="customer_name" value="client" placeholder="Enter customer name">
                </div>

                <div class="form-group" style="margin:0 0 16px;display:flex;flex-wrap:wrap;gap:10px;">
                    <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;padding:10px 14px;border:1.5px solid var(--gray-300);border-radius:var(--radius);background:var(--gray-50);">
                        <input type="checkbox" id="retail_is_loan" onchange="toggleRetailShortcut('loan')" style="width:17px;height:17px;cursor:pointer;accent-color:var(--primary);">
                        <span style="font-weight:700;font-size:14px;">Is Loan?</span>
                        <span style="font-size:12px;color:var(--secondary);">Full amount goes to loan</span>
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;padding:10px 14px;border:1.5px solid var(--gray-300);border-radius:var(--radius);background:var(--gray-50);">
                        <input type="checkbox" id="retail_is_cash" onchange="toggleRetailShortcut('cash')" style="width:17px;height:17px;cursor:pointer;accent-color:#16a34a;">
                        <span style="font-weight:700;font-size:14px;">Is Cash?</span>
                        <span style="font-size:12px;color:var(--secondary);">Full amount goes to cash</span>
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;padding:10px 14px;border:1.5px solid var(--gray-300);border-radius:var(--radius);background:var(--gray-50);">
                        <input type="checkbox" id="retail_is_momo" onchange="toggleRetailShortcut('momo')" style="width:17px;height:17px;cursor:pointer;accent-color:#2563eb;">
                        <span style="font-weight:700;font-size:14px;">Is Momo?</span>
                        <span style="font-size:12px;color:var(--secondary);">Full amount goes to momo</span>
                    </label>
                </div>

                <div class="pay-sum-grid">
                    <div id="retail_payment_section" style="display:none;">
                        <div class="form-group">
                            <label>Payment Breakdown</label>
                            <div class="split-payment-box">
                                <div class="split-row">
                                    <span class="split-label">Cash</span>
                                    <input type="text" id="retail_cash" name="cash_amount" min="0" step="1" value="0" oninput="calcRetailSplit('cash')">
                                </div>
                                <div class="split-row">
                                    <span class="split-label">Momo</span>
                                    <input type="text" id="retail_momo" name="momo_amount" min="0" step="1" value="0" oninput="calcRetailSplit('momo')">
                                </div>
                                <div class="split-row">
                                    <span class="split-label">Loan</span>
                                    <input type="text" id="retail_loan_split" name="loan_amount" min="0" step="1" value="0" oninput="calcRetailSplit('loan')">
                                </div>
                                <div class="split-row split-remaining-row" id="retail_remaining_row">
                                    <span class="split-label">Remaining</span>
                                    <span id="retail_remaining">—</span>
                                </div>
                            </div>
                        </div>
                        <div id="retail_loan_fields" style="display:none;">
                            <?php if ($loan_clients_arr): ?>
                            <div class="form-group">
                                <label>Existing Client</label>
                                <div class="searchable-select" id="retailClientPickerWrap">
                                    <input type="text" class="searchable-select-input" id="retail_client_picker_search"
                                        placeholder="Search registered client..." autocomplete="off">
                                    <div class="searchable-select-dropdown" id="retail_client_picker_dropdown">
                                        <?php foreach ($loan_clients_arr as $c): ?>
                                            <div class="searchable-select-option"
                                                data-client="<?php echo htmlspecialchars($c['client'], ENT_QUOTES); ?>"
                                                data-phone="<?php echo htmlspecialchars($c['phone'], ENT_QUOTES); ?>">
                                                <?php echo htmlspecialchars($c['client']); ?>
                                                <?php if ($c['phone']): ?> — <?php echo htmlspecialchars($c['phone']); ?><?php endif; ?>
                                                <small style="color:var(--secondary);"> (<?php echo $c['visits']; ?> visit<?php echo $c['visits']>1?'s':''; ?>)</small>
                                                <?php if ($c['outstanding'] > 0): ?><small style="color:#dc2626;font-weight:600;"> · Owes: RWF <?php echo number_format($c['outstanding'],0); ?></small><?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <small style="color:var(--secondary);margin-top:3px;display:block;">Pick to auto-fill, or type a new name below.</small>
                            </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label>Client Phone*</label>
                                <input type="text" id="retail_phone" name="phone" placeholder="e.g. 07XXXXXXXX" oninput="calcRetailSplit()">
                            </div>
                        </div>
                    </div>

                    <div class="sale-summary" id="retail_summary" style="display:none;">
                        <div class="summary-row"><span>Product</span><strong id="retail_sum_product"></strong></div>
                        <div class="summary-row"><span>Pieces</span><strong id="retail_sum_qty"></strong></div>
                        <div class="summary-row"><span>Price/Piece</span><strong id="retail_sum_price"></strong></div>
                        <div class="summary-row summary-total"><span>Total Amount</span><strong id="retail_sum_total"></strong></div>
                    </div>
                </div>

                <input type="hidden" id="retail_level_multiplier" name="level_multiplier" value="1">
                <button type="button" id="retail_submit_btn" class="btn btn-success" disabled onclick="handleRetailSubmit()" style="width:100%;padding:12px;">
                    Save Retail Sale
                </button>
            </form>
        </div>
    </div>
</div>

<script src="script.js"></script>
<script>
function showSaleToast(message, success) {
    var t = document.getElementById('saleToast');
    if (!t) { t = document.createElement('div'); t.id = 'saleToast'; document.body.appendChild(t); }
    t.textContent = message;
    t.className = success ? 'show toast-success' : 'show toast-error';
    clearTimeout(t._timer);
    t._timer = setTimeout(function() {
        t.style.opacity = '0';
        setTimeout(function() { t.className = ''; t.style.opacity = ''; }, 260);
    }, 4000);
}

function initSearchableSelect(wrapperId, searchInputId, dropdownId, hiddenSelectId) {
    var searchInput  = document.getElementById(searchInputId);
    var dropdown     = document.getElementById(dropdownId);
    var hiddenSelect = document.getElementById(hiddenSelectId);
    var options      = dropdown.querySelectorAll('.searchable-select-option');
    var hi = -1;

    searchInput.addEventListener('focus', function() { dropdown.classList.add('open'); filter(); });
    searchInput.addEventListener('input', function() { dropdown.classList.add('open'); hi = -1; filter(); });
    searchInput.addEventListener('keydown', function(e) {
        var vis = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
        if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi+1, vis.length-1); hl(vis); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi-1, 0); hl(vis); }
        else if (e.key === 'Enter')  { e.preventDefault(); if (hi>=0&&vis[hi]) pick(vis[hi]); }
        else if (e.key === 'Escape') { dropdown.classList.remove('open'); searchInput.blur(); }
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#' + wrapperId)) dropdown.classList.remove('open');
    });
    options.forEach(function(o) { o.addEventListener('click', function() { pick(o); }); });

    function filter() {
        var term = searchInput.value.toLowerCase();
        options.forEach(function(o) { o.classList.toggle('hidden', o.textContent.trim().toLowerCase().indexOf(term)===-1); });
    }
    function hl(vis) {
        options.forEach(function(o) { o.classList.remove('highlighted'); });
        if (vis[hi]) { vis[hi].classList.add('highlighted'); vis[hi].scrollIntoView({block:'nearest'}); }
    }
    function pick(opt) {
        searchInput.value = opt.textContent.trim();
        dropdown.classList.remove('open'); hi = -1;
        hiddenSelect.value = opt.getAttribute('data-value');
        hiddenSelect.dispatchEvent(new Event('change'));
    }
}
initSearchableSelect('retailProductSearchable', 'retail_product_search', 'retail_product_dropdown', 'retail_product_id');

function initLoanClientPicker(wrapId, searchId, dropdownId, clientInputId, phoneInputId) {
    var wrap = document.getElementById(wrapId);
    if (!wrap) return;
    var search   = document.getElementById(searchId);
    var dropdown = document.getElementById(dropdownId);
    var options  = dropdown.querySelectorAll('.searchable-select-option');
    var hi = -1;

    search.addEventListener('focus', function() { dropdown.classList.add('open'); filter(); });
    search.addEventListener('input', function() { dropdown.classList.add('open'); hi = -1; filter(); });
    search.addEventListener('keydown', function(e) {
        var vis = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
        if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi+1, vis.length-1); hl(vis); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi-1, 0); hl(vis); }
        else if (e.key === 'Enter')  { e.preventDefault(); if (hi>=0&&vis[hi]) pick(vis[hi]); }
        else if (e.key === 'Escape') dropdown.classList.remove('open');
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#' + wrapId)) dropdown.classList.remove('open');
    });
    options.forEach(function(o) { o.addEventListener('click', function() { pick(o); }); });

    function filter() {
        var term = search.value.toLowerCase();
        options.forEach(function(o) { o.classList.toggle('hidden', o.textContent.trim().toLowerCase().indexOf(term)===-1); });
    }
    function hl(vis) {
        options.forEach(function(o) { o.classList.remove('highlighted'); });
        if (vis[hi]) { vis[hi].classList.add('highlighted'); vis[hi].scrollIntoView({block:'nearest'}); }
    }
    function pick(opt) {
        document.getElementById(clientInputId).value = opt.getAttribute('data-client');
        var phoneEl = document.getElementById(phoneInputId);
        phoneEl.value = opt.getAttribute('data-phone');
        phoneEl.dispatchEvent(new Event('input'));
        search.value = opt.getAttribute('data-client');
        dropdown.classList.remove('open'); hi = -1;
    }
}
initLoanClientPicker('retailClientPickerWrap', 'retail_client_picker_search', 'retail_client_picker_dropdown', 'retail_customer', 'retail_phone');

var retailCoreValid = false;

function updateRetailProductDetails() {
    var select = document.getElementById('retail_product_id');
    var opt    = select.options[select.selectedIndex];
    if (!opt.value) {
        document.getElementById('retail_product_details').style.display = 'none';
        document.getElementById('retail_stock_info').innerHTML = '';
        document.getElementById('retail_selling_price').value = '';
        document.getElementById('pieces_sold').value = '';
        document.getElementById('pieces_sold').max = '';
        document.getElementById('retail_summary').style.display = 'none';
        document.getElementById('retail_payment_section').style.display = 'none';
        document.getElementById('retail_level_selector').style.display = 'none';
        document.getElementById('retail_level_buttons').innerHTML = '';
        document.getElementById('retail_qty_label').textContent = 'Number of Pieces*';
        document.getElementById('retail_level_multiplier').value = 1;
        document.getElementById('retail_submit_btn').disabled = true;
        return;
    }
    var price = opt.dataset.price;
    var stock = opt.dataset.stock;
    var name  = opt.dataset.productName;

    document.getElementById('retail_selling_price').value = price;
    document.getElementById('pieces_sold').value = '';
    document.getElementById('pieces_sold').max = stock;
    document.getElementById('retail_stock_info').innerHTML = 'Available: ' + stock + ' pieces';
    document.getElementById('retail_product_details').style.display = 'block';
    document.getElementById('retail_product_info').innerHTML = name + ' &mdash; Default price: RWF ' + parseFloat(price).toLocaleString() + '/piece';
    document.getElementById('retail_cash').value = 0;
    document.getElementById('retail_momo').value = 0;
    document.getElementById('retail_loan_split').value = 0;
    document.getElementById('retail_payment_section').style.display = 'none';
    calculateRetailTotal();

    var retail_pieces = parseInt(opt.dataset.stock) || 0;
    document.getElementById('retail_level_multiplier').value = 1;
    fetch('ajax_levels.php?product_id=' + opt.value)
        .then(function(r){ return r.json(); })
        .then(function(data) {
            var sel  = document.getElementById('retail_level_selector');
            var btns = document.getElementById('retail_level_buttons');
            btns.innerHTML = '';
            if (data.ok && data.levels && data.levels.length > 0) {
                var mults = new Array(data.levels.length).fill(1);
                for (var i = data.levels.length - 1; i >= 1; i--) {
                    mults[i-1] = mults[i] * (parseInt(data.levels[i].qty_per_parent)||1);
                }
                data.levels.forEach(function(lvl, i) {
                    var available = Math.floor(retail_pieces / mults[i]);
                    var btn = document.createElement('button');
                    btn.type = 'button'; btn.className = 'lvl-btn';
                    btn.innerHTML =
                        '<span class="lvl-btn-name">'  + lvl.level_name + '</span>' +
                        '<span class="lvl-btn-stock">' + available.toLocaleString() + ' avail.</span>' +
                        '<span class="lvl-btn-price">RWF ' + parseInt(lvl.selling_price).toLocaleString() + '</span>';
                    btn.dataset.price      = lvl.selling_price;
                    btn.dataset.available  = available;
                    btn.dataset.multiplier = mults[i];
                    btn.dataset.name       = lvl.level_name;
                    btn.onclick = function() {
                        btns.querySelectorAll('.lvl-btn').forEach(function(b){ b.classList.remove('active'); });
                        this.classList.add('active');
                        document.getElementById('retail_selling_price').value = this.dataset.price;
                        document.getElementById('pieces_sold').max = this.dataset.available;
                        document.getElementById('retail_level_multiplier').value = this.dataset.multiplier;
                        document.getElementById('retail_stock_info').innerHTML = 'Available: ' + parseInt(this.dataset.available).toLocaleString() + ' ' + this.dataset.name;
                        document.getElementById('retail_qty_label').textContent = 'Quantity (' + this.dataset.name + ')*';
                        calculateRetailTotal();
                    };
                    btns.appendChild(btn);
                });
                var allBtns = btns.querySelectorAll('.lvl-btn');
                allBtns[allBtns.length - 1] && allBtns[allBtns.length - 1].click();
                sel.style.display = 'block';
            } else {
                sel.style.display = 'none';
            }
        })
        .catch(function() { document.getElementById('retail_level_selector').style.display = 'none'; });
}

function setRetailDefaultPrice() {
    var activeBtn = document.querySelector('#retail_level_buttons .lvl-btn.active');
    if (activeBtn) {
        document.getElementById('retail_selling_price').value = activeBtn.dataset.price;
    } else {
        var opt = document.getElementById('retail_product_id').options[document.getElementById('retail_product_id').selectedIndex];
        if (opt.value) document.getElementById('retail_selling_price').value = opt.dataset.price;
    }
    calculateRetailTotal();
}

function calculateRetailTotal() {
    var select = document.getElementById('retail_product_id');
    var opt    = select.options[select.selectedIndex];
    if (!opt.value) return;

    var piecesInput = document.getElementById('pieces_sold');
    var stock = parseInt(piecesInput.max) > 0 ? parseInt(piecesInput.max) : (parseInt(opt.dataset.stock)||0);
    var activeBtn = document.querySelector('#retail_level_buttons .lvl-btn.active');
    var defaultPrice = activeBtn ? (parseFloat(activeBtn.dataset.price)||0) : (parseFloat(opt.dataset.price)||0);
    var qty   = parseInt(document.getElementById('pieces_sold').value) || 0;
    var price = parseFloat(document.getElementById('retail_selling_price').value) || 0;
    var total = qty * price;
    var qtyError     = document.getElementById('retail_qty_error');
    var priceWarning = document.getElementById('retail_price_warning');
    var summary      = document.getElementById('retail_summary');
    var valid = true;

    if (qty > stock) {
        qtyError.innerHTML = 'Exceeds available stock (' + stock + ' pieces)!';
        qtyError.style.display = 'block'; valid = false;
    } else {
        qtyError.style.display = 'none';
    }

    if (price > 0 && defaultPrice > 0 && price !== defaultPrice) {
        var diff = ((price - defaultPrice) / defaultPrice * 100).toFixed(1);
        priceWarning.innerHTML = 'Price is ' + (price > defaultPrice ? '+' : '') + diff + '% from default (RWF ' + defaultPrice.toLocaleString() + ')';
        priceWarning.style.display = 'block';
    } else {
        priceWarning.style.display = 'none';
    }

    if (qty < 1 || price < 1) valid = false;
    retailCoreValid = valid;

    if (valid) {
        document.getElementById('retail_sum_product').textContent = opt.dataset.productName;
        document.getElementById('retail_sum_qty').textContent = qty;
        document.getElementById('retail_sum_price').textContent = 'RWF ' + price.toLocaleString();
        document.getElementById('retail_sum_total').textContent = 'RWF ' + total.toLocaleString();
        summary.style.display = 'block';
        var isLoan = document.getElementById('retail_is_loan').checked;
        var isCash = document.getElementById('retail_is_cash').checked;
        var isMomo = document.getElementById('retail_is_momo').checked;
        var cash = parseFloat(document.getElementById('retail_cash').value)||0;
        var momo = parseFloat(document.getElementById('retail_momo').value)||0;
        var loan = parseFloat(document.getElementById('retail_loan_split').value)||0;
        if (isLoan) {
            document.getElementById('retail_cash').value = 0;
            document.getElementById('retail_momo').value = 0;
            document.getElementById('retail_loan_split').value = total;
        } else if (isCash) {
            document.getElementById('retail_cash').value = total;
            document.getElementById('retail_momo').value = 0;
            document.getElementById('retail_loan_split').value = 0;
        } else if (isMomo) {
            document.getElementById('retail_cash').value = 0;
            document.getElementById('retail_momo').value = total;
            document.getElementById('retail_loan_split').value = 0;
        } else if (cash===0 && momo===0 && loan===0) {
            document.getElementById('retail_momo').value = total;
        }
        document.getElementById('retail_payment_section').style.display = 'block';
        calcRetailSplit();
    } else {
        summary.style.display = 'none';
        document.getElementById('retail_payment_section').style.display = 'none';
        document.getElementById('retail_submit_btn').disabled = true;
    }
}

function calcRetailSplit(changed) {
    var qty   = parseInt(document.getElementById('pieces_sold').value) || 0;
    var price = parseFloat(document.getElementById('retail_selling_price').value) || 0;
    var total = qty * price;
    var cashEl = document.getElementById('retail_cash');
    var momoEl = document.getElementById('retail_momo');
    var loanEl = document.getElementById('retail_loan_split');
    var cash = parseFloat(cashEl.value)||0;
    var momo = parseFloat(momoEl.value)||0;
    var loan = parseFloat(loanEl.value)||0;

    if (changed === 'cash')      { momo = Math.max(0, total-cash-loan); momoEl.value = momo; }
    else if (changed === 'momo') { loan = Math.max(0, total-cash-momo); loanEl.value = loan; }
    else if (changed === 'loan') { momo = Math.max(0, total-cash-loan); momoEl.value = momo; }

    var remaining = Math.round(total - cash - momo - loan);
    var splitOk   = remaining === 0;
    document.getElementById('retail_remaining').textContent = 'RWF ' + remaining.toLocaleString();
    document.getElementById('retail_remaining_row').className = 'split-row split-remaining-row ' + (splitOk ? 'valid' : 'invalid');
    document.getElementById('retail_loan_fields').style.display = loan > 0 ? 'block' : 'none';

    var clientOk = loan <= 0 || (
        document.getElementById('retail_customer').value.trim().length > 0 &&
        document.getElementById('retail_phone').value.trim().length > 0
    );
    document.getElementById('retail_submit_btn').disabled = !(retailCoreValid && splitOk && clientOk);
}

function toggleRetailShortcut(type) {
    if (type !== 'loan') document.getElementById('retail_is_loan').checked = false;
    if (type !== 'cash') document.getElementById('retail_is_cash').checked = false;
    if (type !== 'momo') document.getElementById('retail_is_momo').checked = false;

    var qty   = parseInt(document.getElementById('pieces_sold').value)   || 0;
    var price = parseFloat(document.getElementById('retail_selling_price').value) || 0;
    var total = qty * price;
    var checked = document.getElementById('retail_is_' + type).checked;

    if (checked) {
        document.getElementById('retail_cash').value       = type === 'cash' ? total : 0;
        document.getElementById('retail_momo').value       = type === 'momo' ? total : 0;
        document.getElementById('retail_loan_split').value = type === 'loan' ? total : 0;
    }
    calcRetailSplit();
}

function handleRetailSubmit() {
    var opt   = document.getElementById('retail_product_id').options[document.getElementById('retail_product_id').selectedIndex];
    var qty   = document.getElementById('pieces_sold').value;
    var price = parseFloat(document.getElementById('retail_selling_price').value);
    var total = qty * price;
    var cash  = parseFloat(document.getElementById('retail_cash').value)||0;
    var momo  = parseFloat(document.getElementById('retail_momo').value)||0;
    var loan  = parseFloat(document.getElementById('retail_loan_split').value)||0;
    var parts = [];
    if (cash > 0) parts.push('Cash: RWF ' + cash.toLocaleString());
    if (momo > 0) parts.push('Momo: RWF ' + momo.toLocaleString());
    if (loan > 0) parts.push('Loan: RWF ' + loan.toLocaleString() + ' (deferred)');
    var ok = confirm(
        'Confirm Retail Sale?\n\n' +
        'Product: ' + opt.dataset.productName + '\n' +
        'Pieces: ' + qty + '\n' +
        'Price/Piece: RWF ' + price.toLocaleString() + '\n' +
        'Total: RWF ' + total.toLocaleString() + '\n' +
        'Payment: ' + parts.join(' | ') + '\n\n' +
        'This will deduct ' + qty + ' piece(s) from retail stock.'
    );
    if (!ok) return;

    var btn = document.getElementById('retail_submit_btn');
    btn.disabled = true; btn.textContent = 'Saving...';

    var fd = new FormData(document.getElementById('retailSaleForm'));
    fd.append('retail_sale', '1');
    fd.append('ajax', '1');

    fetch('sales.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            showSaleToast(res.message, res.success);
            if (res.success) {
                document.getElementById('retail_product_search').value = '';
                document.getElementById('retail_product_id').value = '';
                document.getElementById('retail_product_details').style.display = 'none';
                document.getElementById('retail_level_selector').style.display = 'none';
                document.getElementById('retail_level_buttons').innerHTML = '';
                document.getElementById('pieces_sold').value = '';
                document.getElementById('retail_selling_price').value = '';
                document.getElementById('retail_customer').value = 'client';
                document.getElementById('retail_cash').value = 0;
                document.getElementById('retail_momo').value = 0;
                document.getElementById('retail_loan_split').value = 0;
                document.getElementById('retail_is_loan').checked = false;
                document.getElementById('retail_is_cash').checked = false;
                document.getElementById('retail_is_momo').checked = false;
                document.getElementById('retail_summary').style.display = 'none';
                document.getElementById('retail_payment_section').style.display = 'none';
                document.getElementById('retail_loan_fields').style.display = 'none';
                document.getElementById('retail_level_multiplier').value = 1;
                retailCoreValid = false;
            }
            btn.textContent = 'Save Retail Sale';
            btn.disabled = true;
        })
        .catch(function() {
            showSaleToast('Network error. Please try again.', false);
            btn.textContent = 'Save Retail Sale';
            btn.disabled = false;
        });
}
</script>
</body>
</html>
