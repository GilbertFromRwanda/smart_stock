<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Get products and suppliers for dropdown
$products_query = mysqli_query($conn, "SELECT id, name,category FROM products ORDER BY name");
$products_arr = [];
while ($p = mysqli_fetch_assoc($products_query)) {
    $products_arr[] = $p;
}
$suppliers_query = mysqli_query($conn, "SELECT id, name FROM suppliers ORDER BY name");
$suppliers_arr = [];
while ($s = mysqli_fetch_assoc($suppliers_query)) {
    $suppliers_arr[] = $s;
}

// Handle Edit Purchase
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_purchase'])) {
    $purchase_id = (int)$_POST['purchase_id'];
    $product_id = mysqli_real_escape_string($conn, $_POST['product_id']);

    if (empty($_POST['supplier_id'])) {
        $supplier_id = "NULL";
    } else {
        $supplier_id = "'" . mysqli_real_escape_string($conn, $_POST['supplier_id']) . "'";
    }

    $quantity = (int)$_POST['quantity'];
    $pieces_per_qty = (int)$_POST['pieces_per_qty'];
    $cost_price = mysqli_real_escape_string($conn, $_POST['cost_price']);
    $package_price = mysqli_real_escape_string($conn, $_POST['package_price']);
    $retail_price = mysqli_real_escape_string($conn, $_POST['retail_price']);
    $purchase_date = mysqli_real_escape_string($conn, $_POST['purchase_date']);

    // Get old purchase data for stock adjustment
    $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM purchases WHERE id = $purchase_id"));

    if ($old) {
        $query = "UPDATE purchases SET
            product_id = '$product_id',
            supplier_id = $supplier_id,
            quantity = '$quantity',
            pieces_per_qty = '$pieces_per_qty',
            cost_price = '$cost_price',
            package_price = '$package_price',
            retail_price = '$retail_price',
            purchase_date = '$purchase_date'
            WHERE id = $purchase_id";

        if (mysqli_query($conn, $query)) {
            $old_qty = (int)$old['quantity'];

            if ($old['product_id'] != $product_id) {
                // Product changed: revert old product stock, apply to new
                mysqli_query($conn, "UPDATE stock SET quantity = quantity - $old_qty WHERE product_id = {$old['product_id']}");
                $check = mysqli_query($conn, "SELECT * FROM stock WHERE product_id = $product_id");
                if (mysqli_num_rows($check) > 0) {
                    mysqli_query($conn, "UPDATE stock SET quantity = quantity + $quantity, pieces_per_package = $pieces_per_qty, package_price = $package_price, retail_price = $retail_price WHERE product_id = $product_id");
                } else {
                    mysqli_query($conn, "INSERT INTO stock (product_id, quantity, pieces_per_package, package_price, retail_price) VALUES ('$product_id', '$quantity', '$pieces_per_qty', '$package_price', '$retail_price')");
                }
            } else {
                // Same product: adjust by difference
                $qty_diff = $quantity - $old_qty;
                mysqli_query($conn, "UPDATE stock SET quantity = quantity + ($qty_diff), pieces_per_package = $pieces_per_qty, package_price = $package_price, retail_price = $retail_price WHERE product_id = $product_id");
            }

            require_once __DIR__ . '/stock_value.php';
            recalcStockValue($conn, (int)$product_id);
            if ($old['product_id'] != $product_id) recalcStockValue($conn, (int)$old['product_id']);
            audit_log($conn, 'UPDATE', 'purchases', $purchase_id, "Edited purchase id=$purchase_id",
                ['product_id' => $old['product_id'], 'qty' => $old['quantity'], 'cost_price' => $old['cost_price'], 'package_price' => $old['package_price'], 'retail_price' => $old['retail_price'], 'date' => $old['purchase_date']],
                ['product_id' => $product_id, 'qty' => $quantity, 'cost_price' => $cost_price, 'package_price' => $package_price, 'retail_price' => $retail_price, 'date' => $purchase_date]);
            $_SESSION['flash_success'] = t('purch_updated_ok');
        } else {
            $_SESSION['flash_error'] = t('purch_updated_err') . ': ' . mysqli_error($conn);
        }
    } else {
        $_SESSION['flash_error'] = t('purch_not_found');
    }

    header("Location: purchases.php");
    exit;
}

if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Date filter
$date_from = isset($_GET['date_from']) ? mysqli_real_escape_string($conn, $_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? mysqli_real_escape_string($conn, $_GET['date_to']) : '';

$where_clause = "";
$limit=" limit 50";
if ($date_from && $date_to) {
    $limit=" ";
    $where_clause = "WHERE p.purchase_date BETWEEN '$date_from' AND '$date_to'";
} elseif ($date_from) {
     $limit=" ";
    $where_clause = "WHERE p.purchase_date >= '$date_from'";
} elseif ($date_to) {
     $limit=" ";
    $where_clause = "WHERE p.purchase_date <= '$date_to'";
}

// Fetch all purchases with details
$purchases = mysqli_query($conn, "
    SELECT p.*, pr.name as product_name, pr.category as product_category, s.name as supplier_name
    FROM purchases p
    JOIN products pr ON p.product_id = pr.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    $where_clause
    ORDER BY p.purchase_date DESC, p.id DESC $limit
");

// Pre-fetch all rows to allow purchase_levels lookup
$all_rows = [];
while ($row = mysqli_fetch_assoc($purchases)) {
    $all_rows[] = $row;
}

// Pre-fetch packaging levels for all listed purchases
$levels_map = [];
if (!empty($all_rows)) {
    $pids = implode(',', array_map(fn($r) => (int)$r['id'], $all_rows));
    $lq   = mysqli_query($conn, "SELECT * FROM purchase_levels WHERE purchase_id IN ($pids) ORDER BY purchase_id, level_order");
    if ($lq) while ($l = mysqli_fetch_assoc($lq)) $levels_map[$l['purchase_id']][] = $l;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('page_purchases'); ?> - Smart Stock</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* ── Page header ──────────────────────────────────────────────────── */
        .page-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            flex-wrap: wrap; gap: 16px; margin-bottom: 24px;
        }
        .page-header h1 { font-size: 24px; font-weight: 700; color: var(--dark); margin: 0; }
        .page-subtitle  { font-size: 14px; color: var(--secondary); margin: 4px 0 0; }
        .ph-actions     { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        /* ── Inline date filter ───────────────────────────────────────────── */
        .filter-inline {
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
            background: var(--white); border: 1px solid var(--gray-200);
            border-radius: var(--radius); padding: 6px 12px;
        }
        .filter-inline input[type="date"] {
            border: none; outline: none; font-size: 13px; color: var(--dark);
            background: transparent; padding: 2px 0;
        }
        .filter-sep { font-size: 12px; color: var(--gray-300); font-weight: 600; }
        .btn-clear {
            font-size: 12px; color: var(--secondary); text-decoration: none;
            padding: 4px 8px; border-radius: var(--radius);
            background: none; border: none; cursor: pointer;
        }
        .btn-clear:hover { color: var(--danger); }

        /* ── Date jump bar ────────────────────────────────────────────────── */
        .date-jump-bar {
            display: flex; align-items: center; gap: 10px; margin-bottom: 14px;
        }
        .date-jump-label { font-size: 12px; color: var(--secondary); font-weight: 600; }
        .date-jump-select {
            padding: 6px 12px; border: 1px solid var(--gray-200);
            border-radius: var(--radius); font-size: 13px;
            background: var(--white); color: var(--dark);
            min-width: 200px; cursor: pointer;
        }

        /* ── Level chain ──────────────────────────────────────────────────── */
        .lv-chain { display: flex; flex-wrap: wrap; align-items: center; gap: 5px; }
        .lv-arrow { color: #cbd5e1; font-size: 13px; padding: 0 2px; align-self: center; }
        .lv-node {
            display: inline-flex; flex-direction: column; align-items: center;
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
            padding: 5px 10px; min-width: 64px; text-align: center; gap: 2px;
        }
        .lv-name {
            font-size: 11px; font-weight: 700; color: #475569;
            text-transform: uppercase; letter-spacing: .3px;
        }
        .lv-name em { font-style: normal; color: #94a3b8; font-weight: 400; text-transform: none; }
        .lv-price   { font-size: 11px; font-weight: 700; color: #3b82f6; }

        /* ── Date subtotal row ────────────────────────────────────────────── */
        tr.date-subtotal td {
            background: #f8fafc !important; font-size: 12px; color: var(--secondary);
            padding: 7px 20px !important; border-top: 1px dashed var(--gray-200);
        }
        tr.date-subtotal td strong { color: var(--dark); font-size: 13px; }

        /* ── Searchable select ────────────────────────────────────────────── */
        .searchable-select { position: relative; }
        .searchable-select-input {
            width: 100%; padding: 10px 12px;
            border: 1px solid var(--gray-300); border-radius: var(--radius);
            font-size: 14px; background: var(--white); cursor: text;
        }
        .searchable-select-input:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .searchable-select-dropdown {
            display: none; position: absolute; top: 100%; left: 0; right: 0;
            max-height: 200px; overflow-y: auto; background: var(--white);
            border: 1px solid var(--gray-300); border-top: none;
            border-radius: 0 0 var(--radius) var(--radius);
            z-index: 1000; box-shadow: var(--shadow-md);
        }
        .searchable-select-dropdown.open { display: block; }
        .searchable-select-option { padding: 9px 12px; cursor: pointer; font-size: 14px; }
        .searchable-select-option:hover,
        .searchable-select-option.highlighted { background: var(--gray-100); color: var(--primary); }
        .searchable-select-option.hidden { display: none; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1><?php echo t('purch_title'); ?></h1>
                    <p class="page-subtitle"><?php echo t('purch_subtitle'); ?> &nbsp;·&nbsp; <?php echo count($all_rows); ?> <?php echo count($all_rows) != 1 ? t('purch_records') : t('purch_record'); ?></p>
                </div>
                <div class="ph-actions">
                    <form method="GET" class="filter-inline">
                        <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        <span class="filter-sep">–</span>
                        <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        <button type="submit" class="btn btn-sm btn-secondary"><?php echo t('btn_filter'); ?></button>
                        <?php if ($date_from || $date_to): ?>
                            <a href="purchases.php" class="btn-clear">✕ <?php echo t('btn_clear'); ?></a>
                        <?php endif; ?>
                    </form>
                    <a href="new-purchase.php" class="btn btn-primary"><?php echo t('purch_btn_new'); ?></a>
                    <a href="suppliers.php" class="btn btn-secondary"><?php echo t('purch_btn_suppliers'); ?></a>
                </div>
            </div>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                <input type="text" id="txtSearchPurchases"
                    style="flex:1;min-width:200px;max-width:340px;padding:8px 12px;border:1px solid var(--gray-200);border-radius:var(--radius);font-size:13px;background:var(--gray-100);"
                    placeholder="<?php echo t('purch_search_ph'); ?>"
                    oninput="searchPurchases(this.value)">
                <div id="date-group-filters" class="date-jump-bar" style="display:none;margin-bottom:0;">
                    <span class="date-jump-label"><?php echo t('purch_jump_to'); ?></span>
                    <select id="date-group-select" class="date-jump-select">
                        <option value="all"><?php echo t('purch_all_dates'); ?></option>
                    </select>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table" id="tblPurchases">
                    <thead>
                        <tr>
                            <th><?php echo t('purch_col_product'); ?></th>
                            <th><?php echo t('purch_col_qty'); ?></th>
                            <th><?php echo t('purch_col_cost'); ?></th>
                            <th><?php echo t('purch_col_packaging'); ?></th>
                            <th><?php echo t('purch_col_supplier'); ?></th>
                            <th><?php echo t('purch_col_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $current_date = '';
                        $day_total = 0;
                        $grand_total = 0;
                        $group_index = 0;

                        // Pre-calculate subtotals per date
                        $date_totals = [];
                        foreach ($all_rows as $row) {
                            $d = date('Y-m-d', strtotime($row['purchase_date']));
                            if (!isset($date_totals[$d])) $date_totals[$d] = 0;
                            $date_totals[$d] += $row['cost_price'] * $row['quantity'];
                        }

                        foreach ($all_rows as $i => $row):
                            $row_date = date('Y-m-d', strtotime($row['purchase_date']));
                            $row_cost_total = $row['cost_price'] * $row['quantity'];

                            if ($row_date !== $current_date):
                                // Print subtotal for previous date group
                                if ($current_date !== ''):
                        ?>
                        <tr class="date-subtotal" data-group="<?php echo $group_index; ?>">
                            <td colspan="3"><strong><?php echo t('purch_subtotal'); ?></strong></td>
                            <td colspan="3"><strong>RWF <?php echo number_format($day_total, 0); ?></strong></td>
                        </tr>
                        <?php
                                    $group_index++;
                                endif;
                                $current_date = $row_date;
                                $day_total = 0;
                                $is_first = ($group_index === 0);
                        ?>
                        <tr class="date-group-header <?php echo $is_first ? 'active' : ''; ?>" data-toggle="<?php echo $group_index; ?>" data-date="<?php echo $row_date; ?>" onclick="toggleDateGroup(this)">
                            <td colspan="4">
                                <span class="toggle-icon"><?php echo $is_first ? '&#9660;' : '&#9654;'; ?></span>
                                <?php echo date('D, M d Y', strtotime($row_date)); ?>
                            </td>
                            <td colspan="2" class="header-total">RWF <?php echo number_format($date_totals[$row_date], 0); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="date-group-row" data-group="<?php echo $group_index; ?>" <?php if ($group_index > 0): ?>style="display:none"<?php endif; ?>>
                            <td><?=++$i; ?>. &nbsp; <?php echo htmlspecialchars($row['product_name']); ?></td>
                            <td><?php echo $row['quantity']; ?></td>
                            <td>RWF <?php echo number_format($row['cost_price'], 0); ?></td>
                            <td>
                                <?php if (!empty($levels_map[$row['id']])): ?>
                                    <div class="lv-chain">
                                    <?php foreach ($levels_map[$row['id']] as $j => $lvl): ?>
                                        <?php if ($j > 0): ?><span class="lv-arrow">→</span><?php endif; ?>
                                        <span class="lv-node">
                                            <span class="lv-name"><?= htmlspecialchars($lvl['level_name']) ?><?php if ($lvl['qty_per_parent'] > 1): ?> <em>×<?= $lvl['qty_per_parent'] ?></em><?php endif; ?></span>
                                            <span class="lv-price">RWF <?= number_format($lvl['selling_price'], 0) ?></span>
                                        </span>
                                    <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    Bulk: RWF <?= number_format($row['package_price'], 0) ?><br>
                                    <small>Retail: RWF <?= number_format($row['retail_price'], 0) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $row['supplier_name'] ? htmlspecialchars($row['supplier_name']) : '<span style="color:var(--gray-300);">—</span>'; ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-secondary"
                                    data-id="<?php echo $row['id']; ?>"
                                    data-product-id="<?php echo $row['product_id']; ?>"
                                    data-product-name="<?php echo htmlspecialchars($row['product_category'].'-'.$row['product_name']); ?>"
                                    data-supplier-id="<?php echo $row['supplier_id'] ?? ''; ?>"
                                    data-quantity="<?php echo $row['quantity']; ?>"
                                    data-pieces-per-qty="<?php echo $row['pieces_per_qty']; ?>"
                                    data-cost-price="<?php echo $row['cost_price']; ?>"
                                    data-package-price="<?php echo $row['package_price']; ?>"
                                    data-retail-price="<?php echo $row['retail_price']; ?>"
                                    data-date="<?php echo date('Y-m-d', strtotime($row['purchase_date'])); ?>"
                                    onclick="openEditPurchase(this)"><?php echo t('purch_btn_edit'); ?></button>
                                <a href="new-purchase.php?repeat=<?= $row['id'] ?>" class="btn btn-sm btn-primary" style="margin-left:4px;"><?php echo t('purch_btn_repeat'); ?></a>
                            </td>
                        </tr>
                        <?php
                            $day_total += $row_cost_total;
                            $grand_total += $row_cost_total;
                        endforeach;

                        // Print subtotal for last date group
                        if ($current_date !== ''):
                        ?>
                        <tr class="date-subtotal" data-group="<?php echo $group_index; ?>" <?php if ($group_index > 0): ?>style="display:none"<?php endif; ?>>
                            <td colspan="3"><strong><?php echo t('purch_subtotal'); ?></strong></td>
                            <td colspan="3"><strong>RWF <?php echo number_format($day_total, 0); ?></strong></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="grand-total">
                            <td colspan="3"><strong><?php echo t('purch_grand_total'); ?></strong></td>
                            <td colspan="3"><strong>RWF <?php echo number_format($grand_total, 0); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Edit Purchase Modal -->
    <div id="editPurchaseModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editPurchaseModal')">&times;</span>
            <h2><?php echo t('purch_edit_modal'); ?></h2>

            <form method="POST" action="" id="editPurchaseForm">
                <input type="hidden" id="edit_purchase_id" name="purchase_id">
                <div class="form-group">
                    <label for="edit_purchase_date"><?php echo t('purch_field_date'); ?></label>
                    <input type="date" id="edit_purchase_date" name="purchase_date" required>
                </div>
                <div class="form-group">
                    <label for="edit_product_id"><?php echo t('purch_field_product'); ?></label>
                    <div class="searchable-select" id="editProductSearchable">
                        <input type="hidden" id="edit_product_id" name="product_id" required>
                        <input type="text" class="searchable-select-input" id="edit_product_search" placeholder="<?php echo t('purch_search_product'); ?>" autocomplete="off">
                        <div class="searchable-select-dropdown" id="edit_product_dropdown">
                            <?php foreach($products_arr as $row): ?>
                                <div class="searchable-select-option" data-value="<?php echo $row['id']; ?>">
                                    <?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="edit_quantity"><?php echo t('purch_field_qty'); ?></label>
                    <input type="text" id="edit_quantity" name="quantity" required min="1">
                </div>
                <div class="form-group">
                    <label for="edit_pieces_per_qty"><?php echo t('purch_field_pieces'); ?></label>
                    <input type="text" id="edit_pieces_per_qty" name="pieces_per_qty" required min="1">
                </div>
                <div class="form-group">
                    <label for="edit_cost_price"><?php echo t('purch_field_cost'); ?></label>
                    <input type="text" id="edit_cost_price" name="cost_price" step="0.01" required>
                </div>
                <div class="form-group">
                    <label for="edit_package_price"><?php echo t('purch_field_pkg_price'); ?></label>
                    <input type="text" id="edit_package_price" name="package_price" step="0.01" required>
                </div>
                <div class="form-group">
                    <label for="edit_retail_price"><?php echo t('purch_field_retail'); ?></label>
                    <input type="text" id="edit_retail_price" name="retail_price" step="0.01" required>
                </div>
                <div class="form-group">
                    <label for="edit_supplier_id"><?php echo t('purch_field_supplier'); ?></label>
                    <select id="edit_supplier_id" name="supplier_id">
                        <option value=""><?php echo t('purch_select_supplier'); ?></option>
                        <?php foreach($suppliers_arr as $row): ?>
                            <option value="<?php echo $row['id']; ?>"><?php echo htmlspecialchars($row['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" name="edit_purchase" class="btn btn-primary"><?php echo t('purch_btn_update'); ?></button>
            </form>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        function searchPurchases(term) {
            term = (term || '').toLowerCase().trim();
            var table    = document.getElementById('tblPurchases');
            var headers  = table.querySelectorAll('tr.date-group-header');
            var dataRows = table.querySelectorAll('tr.date-group-row');
            var subRows  = table.querySelectorAll('tr.date-subtotal');

            if (!term) {
                // Restore default: all headers visible, only group 0 expanded
                headers.forEach(function(h) { h.style.display = ''; });
                dataRows.forEach(function(r) {
                    r.style.display = r.getAttribute('data-group') === '0' ? '' : 'none';
                });
                subRows.forEach(function(r) {
                    r.style.display = r.getAttribute('data-group') === '0' ? '' : 'none';
                });
                return;
            }

            // Find which groups have at least one matching row
            var matchGroups = {};
            dataRows.forEach(function(r) {
                if (r.textContent.toLowerCase().includes(term))
                    matchGroups[r.getAttribute('data-group')] = true;
            });

            // Show/hide date headers
            headers.forEach(function(h) {
                h.style.display = matchGroups[h.getAttribute('data-toggle')] ? '' : 'none';
            });

            // Show/hide data rows (only matching rows in matching groups)
            dataRows.forEach(function(r) {
                var g = r.getAttribute('data-group');
                r.style.display = (matchGroups[g] && r.textContent.toLowerCase().includes(term)) ? '' : 'none';
            });

            // Show/hide subtotals for matching groups
            subRows.forEach(function(r) {
                r.style.display = matchGroups[r.getAttribute('data-group')] ? '' : 'none';
            });
        }

        // Open edit purchase modal with data
        function openEditPurchase(btn) {
            var data = btn.dataset;
            document.getElementById('edit_purchase_id').value = data.id;
            document.getElementById('edit_purchase_date').value = data.date;
            document.getElementById('edit_product_id').value = data.productId;
            document.getElementById('edit_product_search').value = data.productName;
            document.getElementById('edit_quantity').value = data.quantity;
            document.getElementById('edit_pieces_per_qty').value = data.piecesPerQty;
            document.getElementById('edit_cost_price').value = data.costPrice;
            document.getElementById('edit_package_price').value = data.packagePrice;
            document.getElementById('edit_retail_price').value = data.retailPrice;
            document.getElementById('edit_supplier_id').value = data.supplierId || '';
            openModal('editPurchaseModal');
        }

        // Searchable product select for edit modal
        (function() {
            var hiddenInput = document.getElementById('edit_product_id');
            var searchInput = document.getElementById('edit_product_search');
            var dropdown = document.getElementById('edit_product_dropdown');
            var options = dropdown.querySelectorAll('.searchable-select-option');
            var highlightedIndex = -1;

            searchInput.addEventListener('focus', function() {
                dropdown.classList.add('open');
                filterOptions();
            });

            searchInput.addEventListener('input', function() {
                dropdown.classList.add('open');
                highlightedIndex = -1;
                filterOptions();
            });

            searchInput.addEventListener('keydown', function(e) {
                var visible = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    highlightedIndex = Math.min(highlightedIndex + 1, visible.length - 1);
                    updateHighlight(visible);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    highlightedIndex = Math.max(highlightedIndex - 1, 0);
                    updateHighlight(visible);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (highlightedIndex >= 0 && visible[highlightedIndex]) {
                        selectOption(visible[highlightedIndex]);
                    }
                } else if (e.key === 'Escape') {
                    dropdown.classList.remove('open');
                    searchInput.blur();
                }
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest('#editProductSearchable')) {
                    dropdown.classList.remove('open');
                }
            });

            options.forEach(function(opt) {
                opt.addEventListener('click', function() { selectOption(opt); });
            });

            function filterOptions() {
                var term = searchInput.value.toLowerCase();
                options.forEach(function(opt) {
                    opt.classList.toggle('hidden', opt.textContent.trim().toLowerCase().indexOf(term) === -1);
                });
            }

            function updateHighlight(visible) {
                options.forEach(function(o) { o.classList.remove('highlighted'); });
                if (visible[highlightedIndex]) {
                    visible[highlightedIndex].classList.add('highlighted');
                    visible[highlightedIndex].scrollIntoView({ block: 'nearest' });
                }
            }

            function selectOption(opt) {
                hiddenInput.value = opt.getAttribute('data-value');
                searchInput.value = opt.textContent.trim();
                dropdown.classList.remove('open');
                highlightedIndex = -1;
            }
        })();

        // ── Date-group filter dropdown ────────────────────────────────────────────
        (function() {
            var wrap    = document.getElementById('date-group-filters');
            var select  = document.getElementById('date-group-select');
            var headers = document.querySelectorAll('.date-group-header');
            if (headers.length < 2) return;

            headers.forEach(function(header) {
                var date = header.getAttribute('data-date');
                var raw  = header.querySelector('td').textContent.trim().replace(/^.\s*/, '').trim();
                var opt  = document.createElement('option');
                opt.value       = date;
                opt.textContent = raw;
                select.appendChild(opt);
            });

            wrap.style.display = '';

            select.addEventListener('change', function() {
                var selectedDate = this.value;
                headers.forEach(function(header) {
                    var date      = header.getAttribute('data-date');
                    var groupId   = header.getAttribute('data-toggle');
                    var groupRows = document.querySelectorAll('tr[data-group="' + groupId + '"]');

                    if (selectedDate === 'all') {
                        header.style.display = '';
                        var expanded = header.classList.contains('active');
                        groupRows.forEach(function(r) { r.style.display = expanded ? '' : 'none'; });
                    } else if (date === selectedDate) {
                        header.style.display = '';
                        header.classList.add('active');
                        header.querySelector('.toggle-icon').innerHTML = '&#9660;';
                        groupRows.forEach(function(r) { r.style.display = ''; });
                    } else {
                        header.style.display = 'none';
                        groupRows.forEach(function(r) { r.style.display = 'none'; });
                    }
                });
            });
        })();

        function toggleDateGroup(header) {
            var groupId = header.getAttribute('data-toggle');
            var rows = document.querySelectorAll('tr[data-group="' + groupId + '"]');
            var isActive = header.classList.contains('active');
            var icon = header.querySelector('.toggle-icon');

            if (isActive) {
                header.classList.remove('active');
                icon.innerHTML = '&#9654;';
                rows.forEach(function(row) { row.style.display = 'none'; });
            } else {
                header.classList.add('active');
                icon.innerHTML = '&#9660;';
                rows.forEach(function(row) { row.style.display = ''; });
            }
        }

        // Loading state on edit form submit
        document.getElementById('editPurchaseForm').addEventListener('submit', function() {
            var btn = this.querySelector('button[type="submit"]');
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = btn.name;
            hidden.value = '1';
            this.appendChild(hidden);
            btn.disabled = true;
            btn.textContent = '<?php echo addslashes(t("purch_updating")); ?>';
        });
    </script>
</body>
</html>