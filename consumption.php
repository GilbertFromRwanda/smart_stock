<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Registered "Received By" names
$receivers_query = mysqli_query($conn, "
    SELECT done_by, COUNT(*) AS times
    FROM consumption
    WHERE done_by IS NOT NULL AND done_by != ''
    GROUP BY done_by
    ORDER BY times DESC, done_by ASC
");
$receivers_arr = [];
while ($r = mysqli_fetch_assoc($receivers_query)) $receivers_arr[] = $r;

// Get products with retail price
$products_query = mysqli_query($conn, "
    SELECT p.id, p.name, p.category,
        COALESCE(rs.retail_price, s.retail_price, 0) AS retail_price,
        COALESCE(rs.pieces_quantity, 0) AS stock_qty
    FROM products p
    LEFT JOIN retail_stock rs ON rs.product_id = p.id
    LEFT JOIN stock s ON s.product_id = p.id
    WHERE p.deleted = 0
    ORDER BY p.name
");
$products_arr = [];
while ($p = mysqli_fetch_assoc($products_query)) {
    $products_arr[] = $p;
}

// Handle Add Consumption (normal POST or AJAX)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_consumption'])) {
    $is_ajax  = !empty($_POST['ajax']);
    $product_id       = (int)$_POST['product_id'];
    $qty              = (int)$_POST['qty'];
    $amount           = mysqli_real_escape_string($conn, $_POST['amount']);
    $paid_amount      = mysqli_real_escape_string($conn, $_POST['paid_amount']);
    $done_by          = mysqli_real_escape_string($conn, trim($_POST['done_by']));
    $consumption_date = mysqli_real_escape_string($conn, $_POST['consumption_date']);

    if ($product_id <= 0 || $qty <= 0 || empty($consumption_date)) {
        $msg = "Product, quantity and date are required.";
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $msg]); exit; }
        $_SESSION['flash_error'] = $msg;
    } else {
        $insert = mysqli_query($conn, "
            INSERT INTO consumption (product_id, qty, amount, paid_amount, done_by, consumption_date)
            VALUES ('$product_id', '$qty', '$amount', '$paid_amount', '$done_by', '$consumption_date')
        ");
        if ($insert) {
            mysqli_query($conn, "
                UPDATE retail_stock SET pieces_quantity = pieces_quantity - $qty
                WHERE product_id = $product_id
            ");
            if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => true, 'message' => 'Consumption recorded successfully.']); exit; }
            $_SESSION['flash_success'] = "Consumption recorded successfully.";
        } else {
            $msg = "Error: " . mysqli_error($conn);
            if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $msg]); exit; }
            $_SESSION['flash_error'] = $msg;
        }
    }
    header("Location: consumption.php");
    exit;
}

// AJAX: Preview global payment distribution
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['preview_global_payment'])) {
    $total = (float)$_POST['total_amount'];
    $done_by_filter = mysqli_real_escape_string($conn, trim($_POST['done_by_filter'] ?? ''));

    $where_filter = "paid_amount < amount";
    if ($done_by_filter) $where_filter .= " AND done_by LIKE '%$done_by_filter%'";

    $unpaid = mysqli_query($conn, "
        SELECT c.id, p.name AS product_name, p.category, c.done_by,
               c.consumption_date, c.amount, c.paid_amount,
               (c.amount - c.paid_amount) AS balance
        FROM consumption c
        JOIN products p ON p.id = c.product_id
        WHERE $where_filter
        ORDER BY c.consumption_date ASC, c.id ASC
    ");

    $remaining = $total;
    $rows = [];
    $total_outstanding = 0;

    while ($row = mysqli_fetch_assoc($unpaid)) {
        $total_outstanding += $row['balance'];
        if ($remaining > 0) {
            $pay = min($remaining, $row['balance']);
            $rows[] = [
                'id'       => $row['id'],
                'label'    => $row['category'] . '-' . $row['product_name'],
                'done_by'  => $row['done_by'],
                'date'     => date('M d', strtotime($row['consumption_date'])),
                'balance'  => $row['balance'],
                'will_pay' => $pay,
                'full'     => ($pay >= $row['balance']),
            ];
            $remaining -= $pay;
        } else {
            $rows[] = [
                'id' => $row['id'], 'label' => $row['category'].'-'.$row['product_name'],
                'done_by' => $row['done_by'], 'date' => date('M d', strtotime($row['consumption_date'])),
                'balance' => $row['balance'], 'will_pay' => 0, 'full' => false,
            ];
        }
    }

    header('Content-Type: application/json');
    echo json_encode(['rows' => $rows, 'total_outstanding' => $total_outstanding, 'leftover' => max(0, $remaining)]);
    exit;
}

// AJAX: Execute global payment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['exec_global_payment'])) {
    $total = (float)mysqli_real_escape_string($conn, $_POST['total_amount']);
    $done_by_filter = mysqli_real_escape_string($conn, trim($_POST['done_by_filter'] ?? ''));

    if ($total <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0.']);
        exit;
    }

    $where_filter = "paid_amount < amount";
    if ($done_by_filter) $where_filter .= " AND done_by LIKE '%$done_by_filter%'";

    $unpaid = mysqli_query($conn, "
        SELECT id, amount, paid_amount, (amount - paid_amount) AS balance
        FROM consumption WHERE $where_filter
        ORDER BY consumption_date ASC, id ASC
    ");

    $remaining = $total;
    $count = 0;

    while ($row = mysqli_fetch_assoc($unpaid)) {
        if ($remaining <= 0) break;
        $pay = min($remaining, (float)$row['balance']);
        mysqli_query($conn, "UPDATE consumption SET paid_amount = paid_amount + $pay WHERE id = {$row['id']}");
        $remaining -= $pay;
        $count++;
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'count' => $count, 'applied' => $total - $remaining]);
    exit;
}

// AJAX: Add Payment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_con_payment'])) {
    $con_id      = (int)$_POST['con_id'];
    $amount_paid = mysqli_real_escape_string($conn, $_POST['amount_paid']);

    if ($con_id <= 0 || $amount_paid <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid amount.']);
        exit;
    }

    $con = mysqli_fetch_assoc(mysqli_query($conn, "SELECT amount, paid_amount FROM consumption WHERE id=$con_id"));
    if (!$con) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Record not found.']);
        exit;
    }

    $balance = $con['amount'] - $con['paid_amount'];
    if ($amount_paid > $balance) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Payment exceeds balance of RWF ' . number_format($balance, 0)]);
        exit;
    }

    $upd = mysqli_query($conn, "UPDATE consumption SET paid_amount = paid_amount + $amount_paid WHERE id=$con_id");
    header('Content-Type: application/json');
    echo json_encode($upd ? ['success' => true] : ['success' => false, 'message' => mysqli_error($conn)]);
    exit;
}

// Handle Delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    // Restore stock
    $con = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM consumption WHERE id=$del_id"));
    if ($con) {
        mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity = pieces_quantity + {$con['qty']} WHERE product_id = {$con['product_id']}");
        mysqli_query($conn, "DELETE FROM consumption WHERE id=$del_id");
        $_SESSION['flash_success'] = "Consumption record deleted.";
    }
    header("Location: consumption.php");
    exit;
}

// Flash messages
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

// Date filter
$date_from = isset($_GET['date_from']) ? mysqli_real_escape_string($conn, $_GET['date_from']) : '';
$date_to   = isset($_GET['date_to'])   ? mysqli_real_escape_string($conn, $_GET['date_to'])   : '';

$where = "";
$limit = " LIMIT 100";
if ($date_from && $date_to) {
    $where = "WHERE c.consumption_date BETWEEN '$date_from' AND '$date_to'";
    $limit = "";
} elseif ($date_from) {
    $where = "WHERE c.consumption_date >= '$date_from'";
    $limit = "";
} elseif ($date_to) {
    $where = "WHERE c.consumption_date <= '$date_to'";
    $limit = "";
}

$records = mysqli_query($conn, "
    SELECT c.*, p.name as product_name, p.category as product_category
    FROM consumption c
    JOIN products p ON c.product_id = p.id
    $where
    ORDER BY c.consumption_date DESC, c.id DESC $limit
");

// Summary stats (always all-time)
$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(*)                          AS total_records,
        COALESCE(SUM(amount), 0)          AS total_amount,
        COALESCE(SUM(paid_amount), 0)     AS total_paid,
        SUM(CASE WHEN paid_amount >= amount AND amount > 0 THEN 1 ELSE 0 END) AS total_paid_count,
        SUM(CASE WHEN paid_amount < amount THEN 1 ELSE 0 END)                 AS total_unpaid_count
    FROM consumption
"));
$total_balance = $stats['total_amount'] - $stats['total_paid'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home Consumption</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .searchable-select { position: relative; }
        .searchable-select-input {
            width: 100%; padding: 10px 12px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius); font-size: 14px;
            background: var(--white); cursor: text;
        }
        .searchable-select-input:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
        }
        .searchable-select-dropdown {
            display: none; position: absolute; top: 100%;
            left: 0; right: 0; max-height: 200px; overflow-y: auto;
            background: var(--white); border: 1px solid var(--gray-300);
            border-top: none; border-radius: 0 0 var(--radius) var(--radius);
            z-index: 1000; box-shadow: var(--shadow-md);
        }
        .searchable-select-dropdown.open { display: block; }
        .searchable-select-option {
            padding: 9px 12px; cursor: pointer; font-size: 14px;
        }
        .searchable-select-option:hover,
        .searchable-select-option.highlighted {
            background: var(--gray-100); color: var(--primary);
        }
        .searchable-select-option.hidden { display: none; }
        .badge-unpaid {
            display: inline-block; padding: 2px 8px; border-radius: 99px;
            font-size: 11px; background: #fee2e2; color: #dc2626; font-weight: 600;
        }
        .badge-paid {
            display: inline-block; padding: 2px 8px; border-radius: 99px;
            font-size: 11px; background: #d1fae5; color: #059669; font-weight: 600;
        }
        .con-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .con-card {
            background: var(--white);
            border-radius: 14px;
            padding: 18px 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            border-top: 4px solid var(--primary);
        }
        .con-card.green  { border-top-color: var(--success); }
        .con-card.red    { border-top-color: var(--danger); }
        .con-card.orange { border-top-color: var(--warning); }
        .con-card-label {
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.6px; color: var(--secondary); margin-bottom: 8px;
        }
        .con-card-value {
            font-size: 21px; font-weight: 700; color: var(--dark); line-height: 1.2;
        }
        .con-card-value.danger  { color: var(--danger); }
        .con-card-value.success { color: var(--success); }
        .con-card-sub {
            font-size: 11px; color: var(--secondary); margin-top: 4px;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
            <h1 style="margin:0;">Home Consumption</h1>
            <div style="display:flex;gap:10px;">
                <?php if ($total_balance > 0): ?>
                <button onclick="openGlobalPay()" class="btn btn-secondary"
                    style="border-color:var(--warning);color:var(--warning);font-weight:600;">
                    Global Pay &nbsp;<span style="background:var(--warning);color:#fff;border-radius:99px;padding:1px 8px;font-size:12px;">RWF <?php echo number_format($total_balance, 0); ?></span>
                </button>
                <?php endif; ?>
                <button onclick="openModal('addConsumptionModal')" class="btn btn-primary">+ Record Consumption</button>
            </div>
        </div>

        <!-- Summary cards -->
        <div class="con-cards">
            <div class="con-card">
                <div class="con-card-label">Total Records</div>
                <div class="con-card-value"><?php echo number_format($stats['total_records']); ?></div>
                <div class="con-card-sub"><?php echo $stats['total_paid_count']; ?> paid &nbsp;·&nbsp; <?php echo $stats['total_unpaid_count']; ?> unpaid</div>
            </div>
            <div class="con-card green">
                <div class="con-card-label">Total Amount</div>
                <div class="con-card-value">RWF <?php echo number_format($stats['total_amount'], 0); ?></div>
            </div>
            <div class="con-card orange">
                <div class="con-card-label">Total Collected</div>
                <div class="con-card-value success">RWF <?php echo number_format($stats['total_paid'], 0); ?></div>
            </div>
            <div class="con-card red">
                <div class="con-card-label">Outstanding</div>
                <div class="con-card-value <?php echo $total_balance > 0 ? 'danger' : 'success'; ?>">
                    RWF <?php echo number_format($total_balance, 0); ?>
                </div>
            </div>
        </div>

        <form method="GET" class="date-filter-bar">
            <div class="filter-group">
                <label for="date_from">From</label>
                <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="filter-group">
                <label for="date_to">To</label>
                <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
            <?php if ($date_from || $date_to): ?>
                <a href="consumption.php" class="btn btn-secondary">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table" id="tblConsumption">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Amount</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Received By</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $rows = [];
                $date_totals = [];
                while ($row = mysqli_fetch_assoc($records)) {
                    $rows[] = $row;
                    $d = $row['consumption_date'];
                    if (!isset($date_totals[$d])) $date_totals[$d] = ['amount' => 0, 'paid' => 0];
                    $date_totals[$d]['amount'] += $row['amount'];
                    $date_totals[$d]['paid']   += $row['paid_amount'];
                }
                $current_date = '';
                $group_index  = 0;
                $grand_amount = 0;
                $grand_paid   = 0;
                foreach ($rows as $i => $row):
                    $row_date = $row['consumption_date'];
                    if ($row_date !== $current_date):
                        if ($current_date !== ''):
                ?>
                <tr class="date-subtotal" data-group="<?php echo $group_index; ?>">
                    <td colspan="2"><strong>Subtotal</strong></td>
                    <td><strong>RWF <?php echo number_format($date_totals[$current_date]['amount'], 0); ?></strong></td>
                    <td><strong>RWF <?php echo number_format($date_totals[$current_date]['paid'], 0); ?></strong></td>
                    <td colspan="4"></td>
                </tr>
                <?php
                        $group_index++;
                        endif;
                        $current_date = $row_date;
                        $is_first = ($group_index === 0);
                ?>
                <tr class="date-group-header <?php echo $is_first ? 'active' : ''; ?>" data-toggle="<?php echo $group_index; ?>" onclick="toggleDateGroup(this)">
                    <td colspan="6">
                        <span class="toggle-icon"><?php echo $is_first ? '&#9660;' : '&#9654;'; ?></span>
                        <?php echo date('D, M d Y', strtotime($row_date)); ?>
                    </td>
                    <td colspan="2" class="header-total">RWF <?php echo number_format($date_totals[$row_date]['amount'], 0); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="date-group-row" data-group="<?php echo $group_index; ?>" <?php if ($group_index > 0): ?>style="display:none"<?php endif; ?>>
                    <td><?php echo ++$i; ?>. &nbsp;<?php echo htmlspecialchars($row['product_category'] . '-' . $row['product_name']); ?></td>
                    <td><?php echo $row['qty']; ?></td>
                    <td>RWF <?php echo number_format($row['amount'], 0); ?></td>
                    <td>RWF <?php echo number_format($row['paid_amount'], 0); ?></td>
                    <td>RWF <?php echo number_format($row['amount'] - $row['paid_amount'], 0); ?></td>
                    <td><?php echo htmlspecialchars($row['done_by'] ?: '-'); ?></td>
                    <td>
                        <?php
                        $bal = $row['amount'] - $row['paid_amount'];
                        if ($row['paid_amount'] >= $row['amount'] && $row['amount'] > 0): ?>
                            <span class="badge-paid">Paid</span>
                        <?php elseif ($row['paid_amount'] > 0): ?>
                            <span class="badge-unpaid">Partial</span>
                        <?php else: ?>
                            <span class="badge-unpaid">Unpaid</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px;align-items:center;">
                        <?php if ($bal > 0): ?>
                            <button type="button" class="btn btn-sm btn-primary"
                                data-con-id="<?php echo $row['id']; ?>"
                                data-done-by="<?php echo htmlspecialchars($row['done_by'] ?: 'N/A', ENT_QUOTES); ?>"
                                data-balance="<?php echo $bal; ?>"
                                onclick="openConPayment(this)">Pay</button>
                        <?php endif; ?>
                        <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger"
                            onclick="return confirm('Delete this record?')">Del</a>
                        </div>
                    </td>
                </tr>
                <?php
                    $grand_amount += $row['amount'];
                    $grand_paid   += $row['paid_amount'];
                endforeach;
                if ($current_date !== ''):
                ?>
                <tr class="date-subtotal" data-group="<?php echo $group_index; ?>" <?php if ($group_index > 0): ?>style="display:none"<?php endif; ?>>
                    <td colspan="2"><strong>Subtotal</strong></td>
                    <td><strong>RWF <?php echo number_format($date_totals[$current_date]['amount'], 0); ?></strong></td>
                    <td><strong>RWF <?php echo number_format($date_totals[$current_date]['paid'], 0); ?></strong></td>
                    <td colspan="4"></td>
                </tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="grand-total">
                        <td colspan="2"><strong>Grand Total</strong></td>
                        <td><strong>RWF <?php echo number_format($grand_amount, 0); ?></strong></td>
                        <td><strong>RWF <?php echo number_format($grand_paid, 0); ?></strong></td>
                        <td><strong>RWF <?php echo number_format($grand_amount - $grand_paid, 0); ?></strong></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Add Consumption Modal -->
<div id="addConsumptionModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addConsumptionModal')">&times;</span>
        <h2>Record Consumption</h2>
        <form id="consumptionForm">
            <div id="modalAlert" class="alert" style="display:none;"></div>
            <div class="form-group">
                <label for="consumption_date">Date*</label>
                <input type="date" id="consumption_date" name="consumption_date"
                    value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label for="product_id">Product*</label>
                <div class="searchable-select" id="productSearchable">
                    <input type="hidden" id="product_id" name="product_id">
                    <input type="text" class="searchable-select-input" id="product_search"
                        placeholder="Search product..." autocomplete="off">
                    <div class="searchable-select-dropdown" id="product_dropdown">
                        <?php foreach ($products_arr as $p): ?>
                            <div class="searchable-select-option"
                                data-value="<?php echo $p['id']; ?>"
                                data-price="<?php echo $p['retail_price']; ?>"
                                data-stock="<?php echo $p['stock_qty']; ?>">
                                <?php echo htmlspecialchars($p['category'] . '-' . $p['name']); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <small id="priceHint" style="color:var(--secondary);margin-top:4px;display:block;"></small>
            </div>
            <div class="form-group">
                <label for="qty">Quantity*</label>
                <input type="number" id="qty" name="qty" min="1" required value="1">
            </div>
            <div class="form-group">
                <label for="amount">Amount (RWF)*</label>
                <input type="number" id="amount" name="amount" min="0" step="1" required value="0">
            </div>
            <div class="form-group">
                <label for="paid_amount">Paid Amount (RWF)</label>
                <input type="number" id="paid_amount" name="paid_amount" min="0" step="1" value="0">
            </div>
            <div class="form-group">
                <label for="done_by">Received By</label>
                <?php if ($receivers_arr): ?>
                <div class="searchable-select" id="receiverWrap">
                    <input type="text" class="searchable-select-input" id="receiver_search"
                        placeholder="Search or type new name..." autocomplete="off">
                    <div class="searchable-select-dropdown" id="receiver_dropdown">
                        <?php foreach ($receivers_arr as $r): ?>
                            <div class="searchable-select-option"
                                data-value="<?php echo htmlspecialchars($r['done_by'], ENT_QUOTES); ?>">
                                <?php echo htmlspecialchars($r['done_by']); ?>
                                <small style="color:var(--secondary);"> (<?php echo $r['times']; ?>×)</small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <small style="color:var(--secondary);margin-top:3px;display:block;">Pick existing or type a new name.</small>
                <?php endif; ?>
                <input type="text" id="done_by" name="done_by" placeholder="Name of person"
                    <?php echo $receivers_arr ? 'style="margin-top:6px;"' : ''; ?>>
            </div>
            <button type="submit" name="add_consumption" class="btn btn-primary">Save</button>
        </form>
    </div>
</div>

<!-- Global Payment Modal -->
<div id="globalPayModal" class="modal">
    <div class="modal-content" style="max-width:580px;">
        <span class="close" onclick="closeModal('globalPayModal')">&times;</span>
        <h2>Global Payment</h2>
        <p style="color:var(--secondary);font-size:13px;margin-bottom:20px;">
            Distributes payment across all unpaid records — oldest first.
        </p>
        <div id="globalPayAlert" class="alert" style="display:none;"></div>
        <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
            <div class="form-group" style="flex:1;min-width:160px;margin-bottom:0;">
                <label>Amount to Pay (RWF)*</label>
                <input type="number" id="global_amount" min="1" step="1" placeholder="Enter amount..."
                    oninput="schedulePreview()">
            </div>
            <div class="form-group" style="flex:1;min-width:160px;margin-bottom:0;">
                <label>Filter by Person <small style="font-weight:400;">(optional)</small></label>
                <input type="text" id="global_done_by" placeholder="Leave blank for all"
                    oninput="schedulePreview()">
            </div>
        </div>

        <!-- Preview table -->
        <div id="globalPreview" style="display:none;">
            <div style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--secondary);margin-bottom:8px;">
                Payment Distribution Preview
            </div>
            <div style="max-height:260px;overflow-y:auto;border:1px solid var(--gray-200);border-radius:10px;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="background:var(--gray-100);">
                            <th style="padding:9px 12px;text-align:left;font-size:11px;color:var(--secondary);text-transform:uppercase;">Date</th>
                            <th style="padding:9px 12px;text-align:left;font-size:11px;color:var(--secondary);text-transform:uppercase;">Product</th>
                            <th style="padding:9px 12px;text-align:left;font-size:11px;color:var(--secondary);text-transform:uppercase;">Person</th>
                            <th style="padding:9px 12px;text-align:right;font-size:11px;color:var(--secondary);text-transform:uppercase;">Balance</th>
                            <th style="padding:9px 12px;text-align:right;font-size:11px;color:var(--secondary);text-transform:uppercase;">Will Pay</th>
                        </tr>
                    </thead>
                    <tbody id="previewBody"></tbody>
                </table>
            </div>
            <div id="previewSummary" style="margin-top:10px;font-size:13px;color:var(--secondary);"></div>
        </div>

        <div style="margin-top:20px;display:flex;gap:10px;">
            <button id="globalPayBtn" class="btn btn-primary" onclick="execGlobalPay()" disabled>Apply Payment</button>
            <button class="btn btn-secondary" onclick="closeModal('globalPayModal')">Cancel</button>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div id="conPaymentModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('conPaymentModal')">&times;</span>
        <h2>Record Payment</h2>
        <div id="conPaymentAlert" class="alert" style="display:none;"></div>
        <div id="conPaymentInfo" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px 16px;margin-bottom:20px;font-size:13px;color:#1e40af;display:none;gap:24px;flex-wrap:wrap;"></div>
        <form id="conPaymentForm">
            <input type="hidden" id="pay_con_id" name="con_id">
            <div class="form-group">
                <label>Amount Paid (RWF)*</label>
                <input type="number" id="con_pay_amount" name="amount_paid" min="1" step="1" required>
            </div>
            <button type="submit" name="add_con_payment" class="btn btn-primary">Save Payment</button>
        </form>
    </div>
</div>

<script src="script.js"></script>
<script>
var unitPrice = 0; // retail price of selected product

function calcAmount() {
    var qty = parseInt(document.getElementById('qty').value) || 0;
    if (unitPrice > 0) {
        document.getElementById('amount').value = qty * unitPrice;
    }
}

function applyPrice(price, stock) {
    unitPrice = parseFloat(price) || 0;
    var hint = document.getElementById('priceHint');
    if (unitPrice > 0) {
        hint.textContent = 'Unit price: RWF ' + unitPrice.toLocaleString() + '  |  Stock: ' + stock + ' pcs';
    } else {
        hint.textContent = 'No price set — enter amount manually.';
    }
    calcAmount();
}

// Searchable product select
(function() {
    var hiddenInput  = document.getElementById('product_id');
    var searchInput  = document.getElementById('product_search');
    var dropdown     = document.getElementById('product_dropdown');
    var options      = dropdown.querySelectorAll('.searchable-select-option');
    var highlighted  = -1;

    searchInput.addEventListener('focus', function() {
        dropdown.classList.add('open');
        filterOpts();
    });
    searchInput.addEventListener('input', function() {
        dropdown.classList.add('open');
        highlighted = -1;
        filterOpts();
    });
    searchInput.addEventListener('keydown', function(e) {
        var visible = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
        if (e.key === 'ArrowDown') { e.preventDefault(); highlighted = Math.min(highlighted + 1, visible.length - 1); setHighlight(visible); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); highlighted = Math.max(highlighted - 1, 0); setHighlight(visible); }
        else if (e.key === 'Enter') { e.preventDefault(); if (highlighted >= 0 && visible[highlighted]) pick(visible[highlighted]); }
        else if (e.key === 'Escape') { dropdown.classList.remove('open'); }
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#productSearchable')) dropdown.classList.remove('open');
    });
    options.forEach(function(o) { o.addEventListener('click', function() { pick(o); }); });

    function filterOpts() {
        var term = searchInput.value.toLowerCase();
        options.forEach(function(o) {
            o.classList.toggle('hidden', o.textContent.trim().toLowerCase().indexOf(term) === -1);
        });
    }
    function setHighlight(visible) {
        options.forEach(function(o) { o.classList.remove('highlighted'); });
        if (visible[highlighted]) {
            visible[highlighted].classList.add('highlighted');
            visible[highlighted].scrollIntoView({ block: 'nearest' });
        }
    }
    function pick(opt) {
        hiddenInput.value = opt.getAttribute('data-value');
        searchInput.value = opt.textContent.trim();
        dropdown.classList.remove('open');
        highlighted = -1;
        applyPrice(opt.getAttribute('data-price'), opt.getAttribute('data-stock'));
    }
})();

// Receiver picker
(function() {
    var wrap = document.getElementById('receiverWrap');
    if (!wrap) return;
    var search   = document.getElementById('receiver_search');
    var dropdown = document.getElementById('receiver_dropdown');
    var options  = dropdown.querySelectorAll('.searchable-select-option');
    var hi = -1;

    search.addEventListener('focus', function() { dropdown.classList.add('open'); filter(); });
    search.addEventListener('input', function() {
        dropdown.classList.add('open'); hi = -1; filter();
        document.getElementById('done_by').value = search.value; // sync typed value
    });
    search.addEventListener('keydown', function(e) {
        var vis = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
        if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi+1, vis.length-1); hl(vis); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi-1, 0); hl(vis); }
        else if (e.key === 'Enter') { e.preventDefault(); if (hi >= 0 && vis[hi]) pick(vis[hi]); }
        else if (e.key === 'Escape') dropdown.classList.remove('open');
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#receiverWrap')) dropdown.classList.remove('open');
    });
    options.forEach(function(o) { o.addEventListener('click', function() { pick(o); }); });

    function filter() {
        var term = search.value.toLowerCase();
        options.forEach(function(o) {
            o.classList.toggle('hidden', o.getAttribute('data-value').toLowerCase().indexOf(term) === -1);
        });
    }
    function hl(vis) {
        options.forEach(function(o) { o.classList.remove('highlighted'); });
        if (vis[hi]) { vis[hi].classList.add('highlighted'); vis[hi].scrollIntoView({ block: 'nearest' }); }
    }
    function pick(opt) {
        var val = opt.getAttribute('data-value');
        search.value = val;
        document.getElementById('done_by').value = val;
        dropdown.classList.remove('open'); hi = -1;
    }
})();

// Recalculate when qty changes
document.getElementById('qty').addEventListener('input', calcAmount);

// AJAX form submit
document.getElementById('consumptionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var form = this;
    var btn  = form.querySelector('button[type="submit"]');
    var alertBox = document.getElementById('modalAlert');

    if (!document.getElementById('product_id').value) {
        alertBox.className = 'alert alert-danger';
        alertBox.textContent = 'Please select a product.';
        alertBox.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Saving...';
    alertBox.style.display = 'none';

    var data = new FormData(form);
    data.append('add_consumption', '1');
    data.append('ajax', '1');

    fetch('consumption.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                closeModal('addConsumptionModal');
                // Reset form
                form.reset();
                unitPrice = 0;
                document.getElementById('priceHint').textContent = '';
                document.getElementById('product_search').value = '';
                document.getElementById('product_id').value = '';
                // Reload page to show new row
                location.reload();
            } else {
                alertBox.className = 'alert alert-danger';
                alertBox.textContent = res.message;
                alertBox.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Save';
            }
        })
        .catch(function() {
            alertBox.className = 'alert alert-danger';
            alertBox.textContent = 'Network error. Please try again.';
            alertBox.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Save';
        });
});

// ── Global Payment ────────────────────────────────────────────────────────────
function openGlobalPay() {
    document.getElementById('global_amount').value  = '';
    document.getElementById('global_done_by').value = '';
    document.getElementById('globalPreview').style.display = 'none';
    document.getElementById('globalPayAlert').style.display = 'none';
    document.getElementById('globalPayBtn').disabled = true;
    openModal('globalPayModal');
}

var previewTimer = null;
function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(loadPreview, 400);
}

function loadPreview() {
    var amount  = parseFloat(document.getElementById('global_amount').value) || 0;
    var doneBy  = document.getElementById('global_done_by').value.trim();
    var preview = document.getElementById('globalPreview');
    var btn     = document.getElementById('globalPayBtn');

    if (amount <= 0) {
        preview.style.display = 'none';
        btn.disabled = true;
        return;
    }

    var data = new FormData();
    data.append('preview_global_payment', '1');
    data.append('total_amount', amount);
    data.append('done_by_filter', doneBy);

    fetch('consumption.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            var body    = document.getElementById('previewBody');
            var summary = document.getElementById('previewSummary');
            body.innerHTML = '';

            if (!res.rows || res.rows.length === 0) {
                body.innerHTML = '<tr><td colspan="5" style="padding:16px;text-align:center;color:var(--secondary);">No unpaid records found.</td></tr>';
                btn.disabled = true;
                preview.style.display = 'block';
                return;
            }

            var covered = 0, partial = 0, skipped = 0;
            res.rows.forEach(function(row) {
                var willPay = parseFloat(row.will_pay);
                var tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid var(--gray-200)';

                var statusColor = willPay <= 0 ? '#94a3b8' : (row.full ? 'var(--success)' : 'var(--warning)');
                var payLabel    = willPay <= 0 ? '—' : 'RWF ' + willPay.toLocaleString();

                tr.innerHTML =
                    '<td style="padding:9px 12px;">' + row.date + '</td>' +
                    '<td style="padding:9px 12px;">' + row.label + '</td>' +
                    '<td style="padding:9px 12px;color:var(--secondary);">' + (row.done_by || '-') + '</td>' +
                    '<td style="padding:9px 12px;text-align:right;">RWF ' + parseFloat(row.balance).toLocaleString() + '</td>' +
                    '<td style="padding:9px 12px;text-align:right;font-weight:600;color:' + statusColor + ';">' + payLabel + '</td>';
                body.appendChild(tr);

                if (willPay >= row.balance)      covered++;
                else if (willPay > 0)             partial++;
                else                              skipped++;
            });

            var applied = amount - res.leftover;
            var summaryParts = [];
            if (covered)  summaryParts.push('<span style="color:var(--success);">✓ ' + covered + ' fully paid</span>');
            if (partial)  summaryParts.push('<span style="color:var(--warning);">~ ' + partial + ' partial</span>');
            if (skipped)  summaryParts.push('<span style="color:var(--secondary);">' + skipped + ' not covered</span>');
            if (res.leftover > 0) summaryParts.push('<span style="color:var(--danger);">RWF ' + parseFloat(res.leftover).toLocaleString() + ' leftover</span>');

            summary.innerHTML = summaryParts.join('&nbsp;&nbsp;·&nbsp;&nbsp;') +
                '<br><small>Total applied: <strong>RWF ' + applied.toLocaleString() + '</strong> of RWF ' + parseFloat(res.total_outstanding).toLocaleString() + ' outstanding</small>';

            preview.style.display  = 'block';
            btn.disabled = (applied <= 0);
        });
}

function execGlobalPay() {
    var amount  = parseFloat(document.getElementById('global_amount').value) || 0;
    var doneBy  = document.getElementById('global_done_by').value.trim();
    var btn     = document.getElementById('globalPayBtn');
    var alertBox = document.getElementById('globalPayAlert');

    if (amount <= 0) return;
    btn.disabled = true;
    btn.textContent = 'Applying...';
    alertBox.style.display = 'none';

    var data = new FormData();
    data.append('exec_global_payment', '1');
    data.append('total_amount', amount);
    data.append('done_by_filter', doneBy);

    fetch('consumption.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                closeModal('globalPayModal');
                location.reload();
            } else {
                alertBox.className = 'alert alert-danger';
                alertBox.textContent = res.message || 'An error occurred.';
                alertBox.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Apply Payment';
            }
        });
}

function openConPayment(btn) {
    var d = btn.dataset;
    document.getElementById('pay_con_id').value     = d.conId;
    document.getElementById('con_pay_amount').value = d.balance;
    document.getElementById('con_pay_amount').max   = d.balance;
    var info = document.getElementById('conPaymentInfo');
    info.innerHTML =
        '<span><strong style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.5px;opacity:.7;margin-bottom:2px;">Received By</strong>' + d.doneBy + '</span>' +
        '<span><strong style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.5px;opacity:.7;margin-bottom:2px;">Balance</strong>RWF ' + parseFloat(d.balance).toLocaleString() + '</span>';
    info.style.display = 'flex';
    document.getElementById('conPaymentAlert').style.display = 'none';
    openModal('conPaymentModal');
}

document.getElementById('conPaymentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var form = this;
    var btn  = form.querySelector('button[type="submit"]');
    var alertBox = document.getElementById('conPaymentAlert');
    var orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Saving...';
    alertBox.style.display = 'none';

    var data = new FormData(form);
    data.append('add_con_payment', '1');

    fetch('consumption.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                closeModal('conPaymentModal');
                location.reload();
            } else {
                alertBox.className = 'alert alert-danger';
                alertBox.textContent = res.message || 'An error occurred.';
                alertBox.style.display = 'block';
                btn.disabled = false; btn.textContent = orig;
            }
        })
        .catch(function() {
            alertBox.className = 'alert alert-danger';
            alertBox.textContent = 'Network error. Please try again.';
            alertBox.style.display = 'block';
            btn.disabled = false; btn.textContent = orig;
        });
});

function toggleDateGroup(header) {
    var groupId = header.getAttribute('data-toggle');
    var rows = document.querySelectorAll('tr[data-group="' + groupId + '"]');
    var isActive = header.classList.contains('active');
    var icon = header.querySelector('.toggle-icon');
    if (isActive) {
        header.classList.remove('active');
        icon.innerHTML = '&#9654;';
        rows.forEach(function(r) { r.style.display = 'none'; });
    } else {
        header.classList.add('active');
        icon.innerHTML = '&#9660;';
        rows.forEach(function(r) { r.style.display = ''; });
    }
}
</script>
</body>
</html>
