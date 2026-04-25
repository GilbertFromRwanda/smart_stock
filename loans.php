<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// ── AJAX: Add Loan ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_loan'])) {
    $product_id = (int)$_POST['product_id'];
    $qty        = (int)$_POST['qty'];
    $amount     = mysqli_real_escape_string($conn, $_POST['amount']);
    $client     = mysqli_real_escape_string($conn, trim($_POST['client']));
    $phone      = mysqli_real_escape_string($conn, trim($_POST['phone']));
    $loan_date  = mysqli_real_escape_string($conn, $_POST['loan_date']);

    if ($product_id <= 0 || $qty <= 0 || empty($client) || empty($loan_date) || $amount <= 0) {
        $msg = "Product, quantity, amount, client and date are required.";
        header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $msg]); exit;
    }

    $ins = mysqli_query($conn, "
        INSERT INTO loans (product_id, qty, amount, client, phone, loan_date)
        VALUES ('$product_id','$qty','$amount','$client','$phone','$loan_date')
    ");
    if ($ins) {
        // deduct from retail_stock
        mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity = pieces_quantity - $qty WHERE product_id = $product_id");
        header('Content-Type: application/json'); echo json_encode(['success' => true]); exit;
    }
    header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => mysqli_error($conn)]); exit;
}

// ── AJAX: Add Payment ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_payment'])) {
    $loan_id      = (int)$_POST['loan_id'];
    $amount_paid  = mysqli_real_escape_string($conn, $_POST['amount_paid']);
    $payment_date = mysqli_real_escape_string($conn, $_POST['payment_date']);

    if ($loan_id <= 0 || $amount_paid <= 0 || empty($payment_date)) {
        header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Loan, amount and date are required.']); exit;
    }

    // Check loan exists and get balance
    $loan = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT l.amount, COALESCE(SUM(lp.amount_paid),0) AS paid
        FROM loans l
        LEFT JOIN loan_payments lp ON lp.loan_id = l.id
        WHERE l.id = $loan_id
        GROUP BY l.id
    "));

    if (!$loan) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Loan not found.']); exit; }

    $balance = $loan['amount'] - $loan['paid'];
    if ($amount_paid > $balance) {
        header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Payment exceeds remaining balance of RWF ' . number_format($balance, 0)]); exit;
    }

    $ins = mysqli_query($conn, "INSERT INTO loan_payments (loan_id, amount_paid, payment_date) VALUES ('$loan_id','$amount_paid','$payment_date')");
    header('Content-Type: application/json'); echo json_encode($ins ? ['success' => true] : ['success' => false, 'message' => mysqli_error($conn)]); exit;
}

// ── AJAX: Preview Global Loan Payment ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['preview_global_loan_payment'])) {
    $total         = (float)$_POST['total_amount'];
    $client_filter = mysqli_real_escape_string($conn, trim($_POST['client_filter'] ?? ''));

    $having = "balance > 0";
    $client_where = $client_filter ? "AND l.client LIKE '%$client_filter%'" : "";

    $unpaid = mysqli_query($conn, "
        SELECT l.id, p.name AS product_name, p.category, l.client,
               l.loan_date, l.amount,
               COALESCE(lp_sum.paid, 0) AS total_paid,
               (l.amount - COALESCE(lp_sum.paid, 0)) AS balance
        FROM loans l
        JOIN products p ON p.id = l.product_id
        LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_sum ON lp_sum.loan_id = l.id
        WHERE l.amount > COALESCE(lp_sum.paid, 0) $client_where
        ORDER BY l.loan_date ASC, l.id ASC
    ");

    $remaining = $total;
    $rows = [];
    $total_outstanding = 0;

    while ($row = mysqli_fetch_assoc($unpaid)) {
        $total_outstanding += $row['balance'];
        if ($remaining > 0) {
            $pay = min($remaining, $row['balance']);
            $rows[] = ['id' => $row['id'], 'label' => $row['category'].'-'.$row['product_name'],
                'client' => $row['client'], 'date' => date('M d', strtotime($row['loan_date'])),
                'balance' => $row['balance'], 'will_pay' => $pay, 'full' => ($pay >= $row['balance'])];
            $remaining -= $pay;
        } else {
            $rows[] = ['id' => $row['id'], 'label' => $row['category'].'-'.$row['product_name'],
                'client' => $row['client'], 'date' => date('M d', strtotime($row['loan_date'])),
                'balance' => $row['balance'], 'will_pay' => 0, 'full' => false];
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['rows' => $rows, 'total_outstanding' => $total_outstanding, 'leftover' => max(0, $remaining)]);
    exit;
}

// ── AJAX: Execute Global Loan Payment ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['exec_global_loan_payment'])) {
    $total         = (float)mysqli_real_escape_string($conn, $_POST['total_amount']);
    $client_filter = mysqli_real_escape_string($conn, trim($_POST['client_filter'] ?? ''));
    $payment_date  = date('Y-m-d');

    if ($total <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0.']);
        exit;
    }

    $client_where = $client_filter ? "AND l.client LIKE '%$client_filter%'" : "";

    $unpaid = mysqli_query($conn, "
        SELECT l.id, l.amount,
               COALESCE(lp_sum.paid, 0) AS total_paid,
               (l.amount - COALESCE(lp_sum.paid, 0)) AS balance
        FROM loans l
        LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_sum ON lp_sum.loan_id = l.id
        WHERE l.amount > COALESCE(lp_sum.paid, 0) $client_where
        ORDER BY l.loan_date ASC, l.id ASC
    ");

    $remaining = $total;
    $count = 0;
    while ($row = mysqli_fetch_assoc($unpaid)) {
        if ($remaining <= 0) break;
        $pay = min($remaining, (float)$row['balance']);
        mysqli_query($conn, "INSERT INTO loan_payments (loan_id, amount_paid, payment_date) VALUES ({$row['id']}, $pay, '$payment_date')");
        $remaining -= $pay;
        $count++;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'count' => $count, 'applied' => $total - $remaining]);
    exit;
}

// ── Delete Loan ────────────────────────────────────────────────────────────────
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $loan = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM loans WHERE id=$del_id"));
    if ($loan) {
        mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity = pieces_quantity + {$loan['qty']} WHERE product_id = {$loan['product_id']}");
        mysqli_query($conn, "DELETE FROM loan_payments WHERE loan_id = $del_id");
        mysqli_query($conn, "DELETE FROM loans WHERE id = $del_id");
        $_SESSION['flash_success'] = "Loan deleted.";
    }
    header("Location: loans.php"); exit;
}

// Flash messages
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }

// Date filter
$date_from = isset($_GET['date_from']) ? mysqli_real_escape_string($conn, $_GET['date_from']) : '';
$date_to   = isset($_GET['date_to'])   ? mysqli_real_escape_string($conn, $_GET['date_to'])   : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$name_filter = isset($_GET['name']) ? mysqli_real_escape_string($conn, trim($_GET['name'])) : '';

$where_parts = [];
if ($date_from && $date_to) $where_parts[] = "l.loan_date BETWEEN '$date_from' AND '$date_to'";
elseif ($date_from)          $where_parts[] = "l.loan_date >= '$date_from'";
elseif ($date_to)            $where_parts[] = "l.loan_date <= '$date_to'";
if ($name_filter)            $where_parts[] = "l.client LIKE '%$name_filter%'";

$where = $where_parts ? "WHERE " . implode(" AND ", $where_parts) : "";
$limit = ($date_from || $date_to || $name_filter) ? "" : " LIMIT 100";

$records = mysqli_query($conn, "
    SELECT l.*, p.name AS product_name, p.category AS product_category,
        COALESCE(SUM(lp.amount_paid), 0) AS total_paid
    FROM loans l
    JOIN products p ON p.id = l.product_id
    LEFT JOIN loan_payments lp ON lp.loan_id = l.id
    $where
    GROUP BY l.id
    ORDER BY l.loan_date DESC, l.id DESC $limit
");

// Products for dropdown
$products_query = mysqli_query($conn, "
    SELECT p.id, p.name, p.category,
        COALESCE(rs.retail_price, s.retail_price, 0) AS retail_price,
        COALESCE(rs.pieces_quantity, 0) AS stock_qty
    FROM products p
    LEFT JOIN retail_stock rs ON rs.product_id = p.id
    LEFT JOIN stock s ON s.product_id = p.id
    WHERE p.deleted = 0 ORDER BY p.name
");
$products_arr = [];
while ($p = mysqli_fetch_assoc($products_query)) $products_arr[] = $p;

// Registered clients (distinct, ordered by most recent)
$clients_query = mysqli_query($conn, "
    SELECT client, phone, MAX(loan_date) AS last_visit, COUNT(*) AS visits
    FROM loans
    GROUP BY client, phone
    ORDER BY last_visit DESC
");
$clients_arr = [];
while ($c = mysqli_fetch_assoc($clients_query)) $clients_arr[] = $c;

// Summary stats (always full, ignoring date filter)
$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(DISTINCT l.id)                          AS total_loans,
        COUNT(DISTINCT l.client)                      AS total_clients,
        COALESCE(SUM(l.amount), 0)                    AS total_amount,
        COALESCE(SUM(lp_sum.paid), 0)                 AS total_paid
    FROM loans l
    LEFT JOIN (
        SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id
    ) lp_sum ON lp_sum.loan_id = l.id
"));
$stats_outstanding = $stats['total_amount'] - $stats['total_paid'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loans</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loans.css">
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">

        <!-- Header -->
        <div class="loans-header">
            <h1>Loans</h1>
            <div style="display:flex;gap:10px;">
                <?php if ($stats_outstanding > 0): ?>
                <button onclick="openGlobalLoanPay()" class="btn btn-secondary"
                    style="border-color:var(--warning);color:var(--warning);font-weight:600;">
                    Global Pay &nbsp;<span style="background:var(--warning);color:#fff;border-radius:99px;padding:1px 8px;font-size:12px;">RWF <?php echo number_format($stats_outstanding, 0); ?></span>
                </button>
                <?php endif; ?>
                <button onclick="openModal('addLoanModal')" class="btn btn-primary">+ New Loan</button>
            </div>
        </div>

        <!-- Summary cards -->
        <div class="loans-summary">
            <div class="loan-card">
                <div class="loan-card-label">Total Loans</div>
                <div class="loan-card-value"><?php echo number_format($stats['total_loans']); ?></div>
                <div class="loan-card-sub"><?php echo $stats['total_clients']; ?> client<?php echo $stats['total_clients'] != 1 ? 's' : ''; ?></div>
            </div>
            <div class="loan-card green">
                <div class="loan-card-label">Total Loaned</div>
                <div class="loan-card-value">RWF <?php echo number_format($stats['total_amount'], 0); ?></div>
            </div>
            <div class="loan-card orange">
                <div class="loan-card-label">Total Collected</div>
                <div class="loan-card-value success">RWF <?php echo number_format($stats['total_paid'], 0); ?></div>
            </div>
            <div class="loan-card red">
                <div class="loan-card-label">Outstanding</div>
                <div class="loan-card-value <?php echo $stats_outstanding > 0 ? 'danger' : 'success'; ?>">
                    RWF <?php echo number_format($stats_outstanding, 0); ?>
                </div>
            </div>
        </div>

        <!-- Filter bar -->
        <form method="GET" class="loans-filter">
            <div class="filter-group">
                <label>From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="filter-group">
                <label>To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="filter-group">
                <label>Client Name</label>
                <input type="text" id="liveClientSearch" name="name" value="<?php echo htmlspecialchars($name_filter); ?>" placeholder="Search name..." autocomplete="off">
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
            <?php if ($date_from || $date_to || $name_filter): ?>
                <a href="loans.php" class="btn btn-secondary">Clear</a>
            <?php endif; ?>
        </form>

        <div id="pageAlert" class="alert" style="display:none;"></div>
        <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <!-- Client folder cards -->
        <?php
        // Group all records by client
        $clients_data = [];
        $grand_amount = 0; $grand_paid = 0;
        while ($row = mysqli_fetch_assoc($records)) {
            $c = $row['client'];
            if (!isset($clients_data[$c])) {
                $clients_data[$c] = ['phone' => $row['phone'], 'total_amount' => 0, 'total_paid' => 0, 'loans' => []];
            }
            $clients_data[$c]['total_amount'] += $row['amount'];
            $clients_data[$c]['total_paid']   += $row['total_paid'];
            $clients_data[$c]['loans'][]       = $row;
            $grand_amount += $row['amount'];
            $grand_paid   += $row['total_paid'];
        }
        // Sort by outstanding desc
        uasort($clients_data, function($a, $b) {
            return ($b['total_amount'] - $b['total_paid']) - ($a['total_amount'] - $a['total_paid']);
        });
        ?>

        <style>
        .client-folders { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .client-folder {
            background: var(--white); border-radius: 14px; border: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm); overflow: hidden; cursor: pointer;
            transition: box-shadow .15s, border-color .15s;
        }
        .client-folder:hover { box-shadow: var(--shadow-md); border-color: var(--primary); }
        .client-folder-header {
            display: flex; align-items: center; gap: 14px; padding: 16px 18px;
            border-top: 4px solid var(--primary);
        }
        .client-folder.fully-paid .client-folder-header { border-top-color: var(--success); }
        .client-folder.partial .client-folder-header    { border-top-color: var(--warning); }
        .client-folder-icon {
            font-size: 28px; flex-shrink: 0; line-height: 1;
        }
        .client-folder-info { flex: 1; min-width: 0; }
        .client-folder-name { font-size: 15px; font-weight: 700; color: var(--dark); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .client-folder-phone { font-size: 12px; color: var(--secondary); margin-top: 2px; }
        .client-folder-meta { display: flex; gap: 10px; margin-top: 6px; flex-wrap: wrap; }
        .client-folder-badge {
            font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 99px;
            background: var(--gray-100); color: var(--secondary);
        }
        .client-folder-outstanding {
            font-size: 13px; font-weight: 700; color: var(--danger); white-space: nowrap;
        }
        .client-folder-outstanding.cleared { color: var(--success); }
        .client-folder-toggle { font-size: 12px; color: var(--secondary); transition: transform .2s; flex-shrink: 0; }
        .client-folder.open .client-folder-toggle { transform: rotate(90deg); }

        .client-folder-body { display: none; border-top: 1px solid var(--gray-200); overflow-x: auto; }
        .client-folder.open .client-folder-body { display: block; }
        .client-loan-table { width: 100%; min-width: 560px; border-collapse: collapse; font-size: 13px; }
        .client-loan-table th {
            padding: 8px 10px; background: var(--gray-100);
            font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--secondary);
            text-align: left; font-weight: 600; white-space: nowrap;
        }
        .client-loan-table td { padding: 8px 10px; border-bottom: 1px solid var(--gray-100); vertical-align: middle; white-space: nowrap; }
        .client-loan-table tr:last-child td { border-bottom: none; }
        .client-loan-table .has-balance { color: var(--danger); font-weight: 600; }
        .client-loan-table .cleared     { color: var(--success); font-weight: 600; }

        .loan-folders-empty { text-align: center; padding: 48px 24px; color: var(--secondary); }
        .loan-grand-total { background: var(--gray-100); border-radius: 10px; padding: 12px 18px; display: flex; gap: 24px; flex-wrap: wrap; font-size: 13px; margin-top: 8px; }
        .loan-grand-total span { color: var(--secondary); } .loan-grand-total strong { color: var(--dark); }
        </style>

        <?php if (empty($clients_data)): ?>
            <div class="loan-folders-empty">No loan records found.</div>
        <?php else: ?>
        <div class="client-folders">
        <?php foreach ($clients_data as $client_name => $client):
            $outstanding = $client['total_amount'] - $client['total_paid'];
            $loan_count  = count($client['loans']);
            $folder_class = ($outstanding <= 0) ? 'fully-paid' : (($client['total_paid'] > 0) ? 'partial' : '');
            $folder_id = 'cf_' . md5($client_name);
        ?>
        <div class="client-folder <?php echo $folder_class; ?>" id="<?php echo $folder_id; ?>"
             onclick="toggleFolder('<?php echo $folder_id; ?>')">
            <div class="client-folder-header">
                <div class="client-folder-icon">📁</div>
                <div class="client-folder-info">
                    <div class="client-folder-name" title="<?php echo htmlspecialchars($client_name); ?>">
                        <?php echo htmlspecialchars($client_name); ?>
                    </div>
                    <?php if ($client['phone']): ?>
                    <div class="client-folder-phone"><?php echo htmlspecialchars($client['phone']); ?></div>
                    <?php endif; ?>
                    <div class="client-folder-meta">
                        <span class="client-folder-badge"><?php echo $loan_count; ?> loan<?php echo $loan_count != 1 ? 's' : ''; ?></span>
                        <span class="client-folder-outstanding <?php echo $outstanding <= 0 ? 'cleared' : ''; ?>">
                            <?php echo $outstanding > 0 ? 'Owed: RWF ' . number_format($outstanding, 0) : 'Cleared'; ?>
                        </span>
                    </div>
                </div>
                <?php if ($outstanding > 0): ?>
                <button type="button" class="btn-pay"
                    style="flex-shrink:0;"
                    onclick="event.stopPropagation(); openGlobalLoanPayFor('<?php echo htmlspecialchars($client_name, ENT_QUOTES); ?>', <?php echo $outstanding; ?>)">
                    Pay All
                </button>
                <?php endif; ?>
                <div class="client-folder-toggle">&#9654;</div>
            </div>
            <div class="client-folder-body">
                <table class="client-loan-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Amount</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($client['loans'] as $loan):
                        $bal = $loan['amount'] - $loan['total_paid'];
                        if ($loan['total_paid'] >= $loan['amount'])   { $badge = 'badge-paid'; $st = 'Paid'; }
                        elseif ($loan['total_paid'] > 0)               { $badge = 'badge-partial'; $st = 'Partial'; }
                        else                                            { $badge = 'badge-unpaid'; $st = 'Unpaid'; }
                    ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($loan['loan_date'])); ?></td>
                        <td><?php echo htmlspecialchars($loan['product_category'] . '-' . $loan['product_name']); ?></td>
                        <td><?php echo $loan['qty']; ?></td>
                        <td>RWF <?php echo number_format($loan['amount'], 0); ?></td>
                        <td>RWF <?php echo number_format($loan['total_paid'], 0); ?></td>
                        <td class="<?php echo $bal > 0 ? 'has-balance' : 'cleared'; ?>">RWF <?php echo number_format($bal, 0); ?></td>
                        <td onclick="event.stopPropagation()">
                            <div class="loan-actions">
                                <?php if ($bal > 0): ?>
                                <button type="button" class="btn-pay"
                                    data-loan-id="<?php echo $loan['id']; ?>"
                                    data-client="<?php echo htmlspecialchars($loan['client'], ENT_QUOTES); ?>"
                                    data-balance="<?php echo $bal; ?>"
                                    onclick="openPayment(this)">Pay</button>
                                <?php endif; ?>
                                <a href="loans.php?delete=<?php echo $loan['id']; ?>" class="btn-del"
                                    onclick="return confirm('Delete this loan?')">Del</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <div class="loan-grand-total">
            <span>Grand Total &mdash;</span>
            <span>Loaned: <strong>RWF <?php echo number_format($grand_amount, 0); ?></strong></span>
            <span>Collected: <strong>RWF <?php echo number_format($grand_paid, 0); ?></strong></span>
            <span>Outstanding: <strong style="color:var(--danger);">RWF <?php echo number_format($grand_amount - $grand_paid, 0); ?></strong></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Global Loan Payment Modal -->
<div id="globalLoanPayModal" class="modal">
    <div class="modal-content" style="max-width:600px;">
        <span class="close" onclick="closeModal('globalLoanPayModal')">&times;</span>
        <h2>Global Loan Payment</h2>
        <p style="color:var(--secondary);font-size:13px;margin-bottom:20px;">
            Distributes payment across all unpaid loans — oldest first.
        </p>
        <div id="globalLoanPayAlert" class="alert" style="display:none;"></div>
        <div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
            <div class="form-group" style="flex:1;min-width:160px;margin-bottom:0;">
                <label>Amount to Pay (RWF)*</label>
                <input type="number" id="gloan_amount" min="1" step="1" placeholder="Enter amount..."
                    oninput="scheduleLoanPreview()">
            </div>
            <div class="form-group" style="flex:1;min-width:160px;margin-bottom:0;">
                <label>Filter by Client <small style="font-weight:400;">(optional)</small></label>
                <input type="text" id="gloan_client" placeholder="Leave blank for all"
                    oninput="scheduleLoanPreview()">
            </div>
        </div>
        <div id="globalLoanPreview" style="display:none;">
            <div style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--secondary);margin-bottom:8px;">
                Payment Distribution Preview
            </div>
            <div style="max-height:280px;overflow-y:auto;border:1px solid var(--gray-200);border-radius:10px;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="background:var(--gray-100);">
                            <th style="padding:9px 12px;text-align:left;font-size:11px;color:var(--secondary);text-transform:uppercase;">Date</th>
                            <th style="padding:9px 12px;text-align:left;font-size:11px;color:var(--secondary);text-transform:uppercase;">Product</th>
                            <th style="padding:9px 12px;text-align:left;font-size:11px;color:var(--secondary);text-transform:uppercase;">Client</th>
                            <th style="padding:9px 12px;text-align:right;font-size:11px;color:var(--secondary);text-transform:uppercase;">Balance</th>
                            <th style="padding:9px 12px;text-align:right;font-size:11px;color:var(--secondary);text-transform:uppercase;">Will Pay</th>
                        </tr>
                    </thead>
                    <tbody id="loanPreviewBody"></tbody>
                </table>
            </div>
            <div id="loanPreviewSummary" style="margin-top:10px;font-size:13px;color:var(--secondary);"></div>
        </div>
        <div style="margin-top:20px;display:flex;gap:10px;">
            <button id="globalLoanPayBtn" class="btn btn-primary" onclick="execGlobalLoanPay()" disabled>Apply Payment</button>
            <button class="btn btn-secondary" onclick="closeModal('globalLoanPayModal')">Cancel</button>
        </div>
    </div>
</div>

<!-- Add Loan Modal -->
<div id="addLoanModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addLoanModal')">&times;</span>
        <h2>New Loan</h2>
        <div id="addLoanAlert" class="alert" style="display:none;"></div>
        <form id="addLoanForm">
            <div class="form-group">
                <label>Date*</label>
                <input type="date" id="loan_date" name="loan_date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label>Product*</label>
                <div class="searchable-select" id="loanProductWrap">
                    <input type="hidden" id="loan_product_id" name="product_id">
                    <input type="text" class="searchable-select-input" id="loan_product_search" placeholder="Search product..." autocomplete="off">
                    <div class="searchable-select-dropdown" id="loan_product_dropdown">
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
                <small id="loanPriceHint" style="color:var(--secondary);margin-top:4px;display:block;"></small>
            </div>
            <div class="form-group">
                <label>Quantity*</label>
                <input type="number" id="loan_qty" name="qty" min="1" required value="1">
            </div>
            <div class="form-group">
                <label>Amount (RWF)*</label>
                <input type="text" id="loan_amount" name="amount" min="1" step="1" required value="0">
            </div>
            <?php if ($clients_arr): ?>
            <div class="form-group">
                <label>Existing Client</label>
                <div class="searchable-select" id="clientPickerWrap">
                    <input type="text" class="searchable-select-input" id="client_picker_search"
                        placeholder="Search registered client..." autocomplete="off">
                    <div class="searchable-select-dropdown" id="client_picker_dropdown">
                        <?php foreach ($clients_arr as $c): ?>
                            <div class="searchable-select-option"
                                data-client="<?php echo htmlspecialchars($c['client'], ENT_QUOTES); ?>"
                                data-phone="<?php echo htmlspecialchars($c['phone'], ENT_QUOTES); ?>">
                                <?php echo htmlspecialchars($c['client']); ?>
                                <?php if ($c['phone']): ?> — <?php echo htmlspecialchars($c['phone']); ?><?php endif; ?>
                                <small style="color:var(--secondary);"> (<?php echo $c['visits']; ?> visit<?php echo $c['visits']>1?'s':''; ?>)</small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <small style="color:var(--secondary);margin-top:3px;display:block;">Pick to auto-fill, or type a new name below.</small>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label>Client Name*</label>
                <input type="text" id="loan_client" name="client" required placeholder="Full name">
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input type="text" id="loan_phone" name="phone" placeholder="e.g. 07XXXXXXXX">
            </div>
            <button type="submit" name="add_loan" class="btn btn-primary">Save Loan</button>
        </form>
    </div>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('paymentModal')">&times;</span>
        <h2>Record Payment</h2>
        <div id="paymentAlert" class="alert" style="display:none;"></div>
        <div id="paymentInfo" class="payment-info-box" style="display:none;"></div>
        <form id="paymentForm">
            <input type="hidden" id="pay_loan_id" name="loan_id">
            <div class="form-group">
                <label>Payment Date*</label>
                <input type="date" id="payment_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label>Amount Paid (RWF)*</label>
                <input type="number" id="pay_amount" name="amount_paid" min="1" step="1" required>
            </div>
            <button type="submit" name="add_payment" class="btn btn-primary">Save Payment</button>
        </form>
    </div>
</div>

<script src="script.js"></script>
<script>
var loanUnitPrice = 0;

function calcLoanAmount() {
    var qty = parseInt(document.getElementById('loan_qty').value) || 0;
    if (loanUnitPrice > 0) document.getElementById('loan_amount').value = qty * loanUnitPrice;
}

// Searchable select for loan product
(function() {
    var hidden   = document.getElementById('loan_product_id');
    var search   = document.getElementById('loan_product_search');
    var dropdown = document.getElementById('loan_product_dropdown');
    var options  = dropdown.querySelectorAll('.searchable-select-option');
    var hi = -1;

    search.addEventListener('focus', function() { dropdown.classList.add('open'); filter(); });
    search.addEventListener('input', function() { dropdown.classList.add('open'); hi = -1; filter(); });
    search.addEventListener('keydown', function(e) {
        var vis = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
        if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi+1, vis.length-1); hl(vis); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi-1,0); hl(vis); }
        else if (e.key === 'Enter') { e.preventDefault(); if (hi>=0&&vis[hi]) pick(vis[hi]); }
        else if (e.key === 'Escape') dropdown.classList.remove('open');
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#loanProductWrap')) dropdown.classList.remove('open');
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
        hidden.value = opt.getAttribute('data-value');
        search.value = opt.textContent.trim();
        dropdown.classList.remove('open'); hi = -1;
        loanUnitPrice = parseFloat(opt.getAttribute('data-price')) || 0;
        var stock = opt.getAttribute('data-stock');
        var hint = document.getElementById('loanPriceHint');
        hint.textContent = loanUnitPrice > 0
            ? 'Unit price: RWF ' + loanUnitPrice.toLocaleString() + '  |  Stock: ' + stock + ' pcs'
            : 'No price set — enter amount manually.';
        calcLoanAmount();
    }
})();

document.getElementById('loan_qty').addEventListener('input', calcLoanAmount);

// Client picker
(function() {
    var wrap     = document.getElementById('clientPickerWrap');
    if (!wrap) return;
    var search   = document.getElementById('client_picker_search');
    var dropdown = document.getElementById('client_picker_dropdown');
    var options  = dropdown.querySelectorAll('.searchable-select-option');
    var hi = -1;

    search.addEventListener('focus', function() { dropdown.classList.add('open'); filter(); });
    search.addEventListener('input', function() { dropdown.classList.add('open'); hi = -1; filter(); });
    search.addEventListener('keydown', function(e) {
        var vis = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
        if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi+1, vis.length-1); hl(vis); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi-1,0); hl(vis); }
        else if (e.key === 'Enter') { e.preventDefault(); if (hi>=0&&vis[hi]) pick(vis[hi]); }
        else if (e.key === 'Escape') dropdown.classList.remove('open');
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#clientPickerWrap')) dropdown.classList.remove('open');
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
        document.getElementById('loan_client').value = opt.getAttribute('data-client');
        document.getElementById('loan_phone').value  = opt.getAttribute('data-phone');
        search.value = opt.getAttribute('data-client');
        dropdown.classList.remove('open'); hi = -1;
    }
})();

// Generic AJAX form helper
function ajaxForm(formId, alertId, actionName, onSuccess) {
    document.getElementById(formId).addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        var btn = form.querySelector('button[type="submit"]');
        var alertBox = document.getElementById(alertId);
        var orig = btn.textContent;
        btn.disabled = true; btn.textContent = 'Saving...';
        alertBox.style.display = 'none';

        var data = new FormData(form);
        data.append(actionName, '1');

        fetch('loans.php', { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) { onSuccess(); }
                else {
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
}

ajaxForm('addLoanForm', 'addLoanAlert', 'add_loan', function() {
    closeModal('addLoanModal');
    document.getElementById('addLoanForm').reset();
    loanUnitPrice = 0;
    document.getElementById('loanPriceHint').textContent = '';
    document.getElementById('loan_product_search').value = '';
    document.getElementById('loan_product_id').value = '';
    location.reload();
});

ajaxForm('paymentForm', 'paymentAlert', 'add_payment', function() {
    closeModal('paymentModal');
    location.reload();
});

function openPayment(btn) {
    var d = btn.dataset;
    document.getElementById('pay_loan_id').value = d.loanId;
    document.getElementById('pay_amount').value  = d.balance;
    document.getElementById('pay_amount').max    = d.balance;
    var info = document.getElementById('paymentInfo');
    info.innerHTML =
        '<span><strong>Client</strong>' + d.client + '</span>' +
        '<span><strong>Balance</strong>RWF ' + parseFloat(d.balance).toLocaleString() + '</span>';
    info.style.display = 'flex';
    document.getElementById('paymentAlert').style.display = 'none';
    document.getElementById('payment_date').value = new Date().toISOString().split('T')[0];
    openModal('paymentModal');
}

// ── Live client search ────────────────────────────────────────────────────────
(function() {
    var input = document.getElementById('liveClientSearch');
    if (!input) return;
    input.addEventListener('input', function() {
        var term = this.value.trim().toLowerCase();
        var folders = document.querySelectorAll('.client-folder');
        var visible = 0;
        folders.forEach(function(card) {
            var name = (card.querySelector('.client-folder-name') || {}).textContent || '';
            var phone = (card.querySelector('.client-folder-phone') || {}).textContent || '';
            var match = !term || name.toLowerCase().indexOf(term) !== -1 || phone.toLowerCase().indexOf(term) !== -1;
            card.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        // show/hide empty state
        var empty = document.querySelector('.loan-folders-empty');
        if (empty) empty.style.display = visible === 0 ? '' : 'none';
    });
})();

// ── Client folder toggle ───────────────────────────────────────────────────────
function toggleFolder(id) {
    var el = document.getElementById(id);
    if (!el) return;
    var wasOpen = el.classList.contains('open');
    el.classList.toggle('open');
    if (!wasOpen) {
        setTimeout(function() {
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 50);
    }
}

// ── Global Loan Payment ────────────────────────────────────────────────────────
function openGlobalLoanPay() {
    document.getElementById('gloan_amount').value  = '';
    document.getElementById('gloan_client').value  = '';
    document.getElementById('globalLoanPreview').style.display = 'none';
    document.getElementById('globalLoanPayAlert').style.display = 'none';
    document.getElementById('globalLoanPayBtn').disabled = true;
    openModal('globalLoanPayModal');
}

function openGlobalLoanPayFor(clientName, outstanding) {
    document.getElementById('gloan_amount').value  = outstanding;
    document.getElementById('gloan_client').value  = clientName;
    document.getElementById('globalLoanPreview').style.display = 'none';
    document.getElementById('globalLoanPayAlert').style.display = 'none';
    document.getElementById('globalLoanPayBtn').disabled = true;
    openModal('globalLoanPayModal');
    scheduleLoanPreview();
}

var loanPreviewTimer = null;
function scheduleLoanPreview() {
    clearTimeout(loanPreviewTimer);
    loanPreviewTimer = setTimeout(loadLoanPreview, 400);
}

function loadLoanPreview() {
    var amount  = parseFloat(document.getElementById('gloan_amount').value) || 0;
    var client  = document.getElementById('gloan_client').value.trim();
    var preview = document.getElementById('globalLoanPreview');
    var btn     = document.getElementById('globalLoanPayBtn');

    if (amount <= 0) { preview.style.display = 'none'; btn.disabled = true; return; }

    var data = new FormData();
    data.append('preview_global_loan_payment', '1');
    data.append('total_amount', amount);
    data.append('client_filter', client);

    fetch('loans.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            var body    = document.getElementById('loanPreviewBody');
            var summary = document.getElementById('loanPreviewSummary');
            body.innerHTML = '';

            if (!res.rows || res.rows.length === 0) {
                body.innerHTML = '<tr><td colspan="5" style="padding:16px;text-align:center;color:var(--secondary);">No unpaid loans found.</td></tr>';
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
                    '<td style="padding:9px 12px;color:var(--secondary);">' + (row.client || '-') + '</td>' +
                    '<td style="padding:9px 12px;text-align:right;">RWF ' + parseFloat(row.balance).toLocaleString() + '</td>' +
                    '<td style="padding:9px 12px;text-align:right;font-weight:600;color:' + statusColor + ';">' + payLabel + '</td>';
                body.appendChild(tr);
                if (willPay >= row.balance) covered++;
                else if (willPay > 0)       partial++;
                else                        skipped++;
            });

            var applied = amount - res.leftover;
            var parts = [];
            if (covered) parts.push('<span style="color:var(--success);">&#10003; ' + covered + ' fully paid</span>');
            if (partial) parts.push('<span style="color:var(--warning);">~ ' + partial + ' partial</span>');
            if (skipped) parts.push('<span style="color:var(--secondary);">' + skipped + ' not covered</span>');
            if (res.leftover > 0) parts.push('<span style="color:var(--danger);">RWF ' + parseFloat(res.leftover).toLocaleString() + ' leftover</span>');

            summary.innerHTML = parts.join('&nbsp;&nbsp;&middot;&nbsp;&nbsp;') +
                '<br><small>Total applied: <strong>RWF ' + applied.toLocaleString() + '</strong> of RWF ' + parseFloat(res.total_outstanding).toLocaleString() + ' outstanding</small>';

            preview.style.display = 'block';
            btn.disabled = (applied <= 0);
        });
}

function execGlobalLoanPay() {
    var amount  = parseFloat(document.getElementById('gloan_amount').value) || 0;
    var client  = document.getElementById('gloan_client').value.trim();
    var btn     = document.getElementById('globalLoanPayBtn');
    var alertBox = document.getElementById('globalLoanPayAlert');

    if (amount <= 0) return;
    btn.disabled = true; btn.textContent = 'Applying...';
    alertBox.style.display = 'none';

    var data = new FormData();
    data.append('exec_global_loan_payment', '1');
    data.append('total_amount', amount);
    data.append('client_filter', client);

    fetch('loans.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                closeModal('globalLoanPayModal');
                location.reload();
            } else {
                alertBox.className = 'alert alert-danger';
                alertBox.textContent = res.message || 'An error occurred.';
                alertBox.style.display = 'block';
                btn.disabled = false; btn.textContent = 'Apply Payment';
            }
        });
}
</script>
</body>
</html>
