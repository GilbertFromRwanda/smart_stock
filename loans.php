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

    $given_by   = (int)$_SESSION['user_id'];
    $unit_price = $qty > 0 ? round($amount / $qty, 2) : 0;
    $ph         = $phone !== '' ? "'$phone'" : 'NULL';

    mysqli_begin_transaction($conn);
    $ok = true;

    $ok = (bool)mysqli_query($conn, "INSERT INTO sales_retail (product_id, pieces_sold, retail_price, total_amount, sale_date, customer_name, has_loan, amount, sold_by)
                         VALUES ($product_id, $qty, $unit_price, $amount, '$loan_date', '$client', 1, $amount, $given_by)");
    $retail_id = $ok ? (int)mysqli_insert_id($conn) : 0;

    if ($ok) $ok = (bool)mysqli_query($conn, "
        INSERT INTO loans (product_id, qty, amount, client, phone, loan_date, given_by, retail_id)
        VALUES ($product_id, $qty, $amount, '$client', '$phone', '$loan_date', $given_by, $retail_id)
    ");
    $loan_id = $ok ? (int)mysqli_insert_id($conn) : 0;

    if ($ok) $ok = (bool)mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity = pieces_quantity - $qty WHERE product_id = $product_id");

    if ($ok) $ok = (bool)mysqli_query($conn, "
        INSERT INTO loan_clients (name, phone, total_loans, paid_amount, unpaid_amount)
        VALUES ('$client', $ph, 1, 0, $amount)
        ON DUPLICATE KEY UPDATE
            id            = LAST_INSERT_ID(id),
            total_loans   = total_loans + 1,
            unpaid_amount = unpaid_amount + $amount
    ");
    $client_id = $ok ? (int)mysqli_insert_id($conn) : 0;

    if ($ok) $ok = (bool)mysqli_query($conn, "UPDATE loans SET client_id = $client_id WHERE id = $loan_id");

    if ($ok) { mysqli_commit($conn); header('Content-Type: application/json'); echo json_encode(['success' => true]); exit; }
    mysqli_rollback($conn);
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

    $loan = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT l.amount, l.client_id, l.bulk_id, l.retail_id,
               COALESCE(SUM(lp.amount_paid),0) AS paid
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

    $received_by  = (int)$_SESSION['user_id'];
    $lc_id        = (int)$loan['client_id'];
    $fully_paid   = (($loan['paid'] + $amount_paid) >= $loan['amount']);

    mysqli_begin_transaction($conn);
    $ok = true;

    $ok = (bool)mysqli_query($conn, "INSERT INTO loan_payments (loan_id, amount_paid, payment_date, received_by) VALUES ('$loan_id','$amount_paid','$payment_date',$received_by)");

    if ($ok) $ok = (bool)mysqli_query($conn, "
        UPDATE loan_clients SET paid_amount = paid_amount + $amount_paid, unpaid_amount = unpaid_amount - $amount_paid WHERE id = $lc_id
    ");

    if ($ok && $loan['bulk_id']) {
        $q = $fully_paid
            ? "UPDATE sales_bulk SET loan_amount = 0, has_loan = 0 WHERE id = {$loan['bulk_id']}"
            : "UPDATE sales_bulk SET loan_amount = GREATEST(0, loan_amount - $amount_paid) WHERE id = {$loan['bulk_id']}";
        $ok = (bool)mysqli_query($conn, $q);
    }
    if ($ok && $loan['retail_id']) {
        $q = $fully_paid
            ? "UPDATE sales_retail SET loan_amount = 0, has_loan = 0 WHERE id = {$loan['retail_id']}"
            : "UPDATE sales_retail SET loan_amount = GREATEST(0, loan_amount - $amount_paid) WHERE id = {$loan['retail_id']}";
        $ok = (bool)mysqli_query($conn, $q);
    }

    if ($ok) { mysqli_commit($conn); header('Content-Type: application/json'); echo json_encode(['success' => true]); exit; }
    mysqli_rollback($conn);
    header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => mysqli_error($conn)]); exit;
}

// ── AJAX: Preview Global Loan Payment ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['preview_global_loan_payment'])) {
    $total         = (float)$_POST['total_amount'];
    $client_filter = mysqli_real_escape_string($conn, trim($_POST['client_filter'] ?? ''));
    $client_where  = $client_filter ? "AND l.client LIKE '%$client_filter%'" : "";

    $unpaid = mysqli_query($conn, "
        SELECT l.id,
               COALESCE(p.name, l.product_name, '—') AS product_name,
               COALESCE(p.category, 'External')       AS category,
               l.client, l.loan_date, l.amount,
               COALESCE(lp_sum.paid, 0) AS total_paid,
               (l.amount - COALESCE(lp_sum.paid, 0))  AS balance
        FROM loans l
        LEFT JOIN products p ON p.id = l.product_id
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
        SELECT l.id, l.amount, l.client_id, l.bulk_id, l.retail_id,
               COALESCE(lp_sum.paid, 0) AS total_paid,
               (l.amount - COALESCE(lp_sum.paid, 0)) AS balance
        FROM loans l
        LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_sum ON lp_sum.loan_id = l.id
        WHERE l.amount > COALESCE(lp_sum.paid, 0) $client_where
        ORDER BY l.loan_date ASC, l.id ASC
    ");

    $remaining     = $total;
    $count         = 0;
    $client_deltas = [];
    $received_by   = (int)$_SESSION['user_id'];

    $rows = [];
    while ($row = mysqli_fetch_assoc($unpaid)) $rows[] = $row;

    mysqli_begin_transaction($conn);
    $ok = true;

    foreach ($rows as $row) {
        if (!$ok || $remaining <= 0) break;
        $pay        = min($remaining, (float)$row['balance']);
        $fully_paid = ($pay >= (float)$row['balance']);

        $ok = (bool)mysqli_query($conn, "INSERT INTO loan_payments (loan_id, amount_paid, payment_date, received_by) VALUES ({$row['id']}, $pay, '$payment_date', $received_by)");

        if ($ok && $row['bulk_id']) {
            $q = $fully_paid
                ? "UPDATE sales_bulk SET loan_amount = 0, has_loan = 0 WHERE id = {$row['bulk_id']}"
                : "UPDATE sales_bulk SET loan_amount = GREATEST(0, loan_amount - $pay) WHERE id = {$row['bulk_id']}";
            $ok = (bool)mysqli_query($conn, $q);
        }
        if ($ok && $row['retail_id']) {
            $q = $fully_paid
                ? "UPDATE sales_retail SET loan_amount = 0, has_loan = 0 WHERE id = {$row['retail_id']}"
                : "UPDATE sales_retail SET loan_amount = GREATEST(0, loan_amount - $pay) WHERE id = {$row['retail_id']}";
            $ok = (bool)mysqli_query($conn, $q);
        }

        if ($ok) {
            $remaining -= $pay;
            $count++;
            $cid = (int)$row['client_id'];
            $client_deltas[$cid] = ($client_deltas[$cid] ?? 0.0) + $pay;
        }
    }

    foreach ($client_deltas as $cid => $d) {
        if (!$ok) break;
        $ok = (bool)mysqli_query($conn, "UPDATE loan_clients SET paid_amount = paid_amount + $d, unpaid_amount = unpaid_amount - $d WHERE id = $cid");
    }

    if ($ok) {
        mysqli_commit($conn);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'count' => $count, 'applied' => $total - $remaining]);
    } else {
        mysqli_rollback($conn);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Payment failed. No changes were applied.']);
    }
    exit;
}

// ── AJAX: Get Client Loans ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['get_client_loans'])) {
    $client_id = (int)$_POST['client_id'];
    if ($client_id <= 0) { header('Content-Type: application/json'); echo json_encode([]); exit; }

    $loans_q = mysqli_query($conn, "
        SELECT l.*,
            COALESCE(p.name, l.product_name, '—') AS product_name,
            COALESCE(p.category, '')               AS product_category,
            COALESCE(lp_sum.paid, 0)               AS total_paid,
            u.full_name                            AS given_by_name
        FROM loans l
        LEFT JOIN products p ON p.id = l.product_id
        LEFT JOIN users u    ON l.given_by = u.id
        LEFT JOIN (
            SELECT loan_id, SUM(amount_paid) AS paid
            FROM loan_payments
            GROUP BY loan_id
        ) lp_sum ON lp_sum.loan_id = l.id
        WHERE l.client_id = $client_id
        ORDER BY l.loan_date ASC, l.id ASC
    ");
    $rows = [];
    while ($r = mysqli_fetch_assoc($loans_q)) $rows[] = $r;
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

// ── AJAX: Get Client Payments ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['get_client_payments'])) {
    $client_id = (int)$_POST['client_id'];
    $date_from = mysqli_real_escape_string($conn, $_POST['date_from'] ?? date('Y-m-d', strtotime('-1 month')));
    $date_to   = mysqli_real_escape_string($conn, $_POST['date_to']   ?? date('Y-m-d'));
    if ($client_id <= 0) { header('Content-Type: application/json'); echo json_encode([]); exit; }

    $q = mysqli_query($conn, "
        SELECT lp.id, lp.amount_paid, lp.payment_date,
               COALESCE(p.name, l.product_name)  AS product_name,
               COALESCE(p.category, '')            AS product_category,
               l.amount AS loan_amount,
               u.full_name AS received_by_name,
               lp.created_at
        FROM loan_payments lp
        JOIN loans l ON l.id = lp.loan_id
        LEFT JOIN products p ON p.id = l.product_id
        LEFT JOIN users u ON u.id = lp.received_by
        WHERE l.client_id = $client_id
          AND lp.payment_date BETWEEN '$date_from' AND '$date_to'
        ORDER BY lp.payment_date DESC, lp.id DESC
    ");
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}

// ── Delete Loan ────────────────────────────────────────────────────────────────
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $loan   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM loans WHERE id=$del_id"));
    if ($loan) {
        $del_client_id = (int)$loan['client_id'];

        mysqli_begin_transaction($conn);
        $ok = true;

        if ($loan['product_id']) {
            $ok = (bool)mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity = pieces_quantity + {$loan['qty']} WHERE product_id = {$loan['product_id']}");
        }
        if ($ok) $ok = (bool)mysqli_query($conn, "DELETE FROM loan_payments WHERE loan_id = $del_id");
        if ($ok) $ok = (bool)mysqli_query($conn, "DELETE FROM loans WHERE id = $del_id");
        if ($ok && $del_client_id) $ok = (bool)mysqli_query($conn, "
            UPDATE loan_clients lc
            JOIN (
                SELECT COUNT(DISTINCT l.id)       AS cnt,
                       COALESCE(SUM(l.amount),0)  AS loaned,
                       COALESCE(SUM(lp_s.paid),0) AS paid_sum
                FROM loans l
                LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_s
                       ON lp_s.loan_id = l.id
                WHERE l.client_id = $del_client_id
            ) agg
            SET lc.total_loans   = agg.cnt,
                lc.paid_amount   = agg.paid_sum,
                lc.unpaid_amount = agg.loaned - agg.paid_sum
            WHERE lc.id = $del_client_id
        ");

        if ($ok) { mysqli_commit($conn); $_SESSION['flash_success'] = t('loan_no_records'); }
        else      { mysqli_rollback($conn); $_SESSION['flash_error'] = "Could not delete loan."; }
    }
    header("Location: loans.php"); exit;
}

// Flash messages
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }

// All loan clients from aggregate table
$clients_qr = mysqli_query($conn, "
    SELECT id AS client_id, name, phone, created_at AS registered_at,
           total_loans AS loan_count,
           paid_amount + unpaid_amount AS total_loaned,
           paid_amount AS total_paid,
           updated_at
    FROM loan_clients
    ORDER BY unpaid_amount DESC
");
$clients_data = [];
while ($row = mysqli_fetch_assoc($clients_qr)) $clients_data[] = $row;

// Products for new loan modal
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

// Existing clients for the add-loan picker
$clients_query = mysqli_query($conn, "
    SELECT name AS client, phone, updated_at AS last_visit, total_loans AS visits
    FROM loan_clients
    ORDER BY updated_at DESC, name ASC
");
$clients_arr = [];
while ($c = mysqli_fetch_assoc($clients_query)) $clients_arr[] = $c;

// Summary stats
$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(DISTINCT l.id)               AS total_loans,
        COUNT(DISTINCT l.client)           AS total_clients,
        COALESCE(SUM(l.amount), 0)         AS total_amount,
        COALESCE(SUM(lp_sum.paid), 0)      AS total_paid
    FROM loans l
    LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_sum ON lp_sum.loan_id = l.id
"));
$stats_outstanding = $stats['total_amount'] - $stats['total_paid'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('page_loans'); ?> - Smart Stock</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loans.css">
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">

        <!-- Header -->
        <div class="loans-header">
            <h1><?php echo t('loan_title'); ?></h1>
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

        <!-- Search bar + status filter -->
        <div style="margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input type="text" id="clientTableSearch" placeholder="Search client name or phone..."
                   oninput="filterClientTable()"
                   style="flex:1;min-width:200px;max-width:360px;padding:8px 12px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:14px;">
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <button class="filter-pill active" data-status="unpaid-partial" onclick="setStatusFilter(this)">Unpaid &amp; Partial</button>
                <button class="filter-pill"        data-status="all"            onclick="setStatusFilter(this)">All</button>
                <button class="filter-pill"        data-status="unpaid"         onclick="setStatusFilter(this)">Unpaid</button>
                <button class="filter-pill"        data-status="partial"        onclick="setStatusFilter(this)">Partial</button>
                <button class="filter-pill"        data-status="paid"           onclick="setStatusFilter(this)">Paid</button>
            </div>
            <span id="clientCountBadge" style="font-size:13px;color:var(--secondary);"></span>
        </div>
        <style>
            .filter-pill { padding:5px 14px;border:1px solid var(--gray-300);border-radius:99px;font-size:13px;background:var(--white);cursor:pointer;color:var(--dark); }
            .filter-pill:hover { border-color:var(--primary);color:var(--primary); }
            .filter-pill.active { background:var(--primary);color:#fff;border-color:var(--primary); }
        </style>

        <div id="pageAlert" class="alert" style="display:none;"></div>
        <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <!-- Loan clients table -->
        <?php if (empty($clients_data)): ?>
            <div style="text-align:center;padding:48px;color:var(--secondary);">No loan clients found.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="table" id="tbl-loan-clients" style="min-width:700px;">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Client</th>
                    <th>Phone</th>
                    <th>Loans</th>
                    <th>Total Loaned</th>
                    <th>Paid</th>
                    <th>Outstanding</th>
                    <th>Status</th>
                    <th>Last Update</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($clients_data as $i => $c):
                $outstanding = $c['total_loaned'] - $c['total_paid'];
                if ($outstanding <= 0)           { $status = 'Paid';    $badge_cls = 'badge-paid'; }
                elseif ($c['total_paid'] > 0)    { $status = 'Partial'; $badge_cls = 'badge-partial'; }
                else                             { $status = 'Unpaid';  $badge_cls = 'badge-unpaid'; }
            ?>
            <tr data-status="<?php echo strtolower($status); ?>">
                <td style="color:var(--secondary);"><?php echo $i + 1; ?></td>
                <td style="font-weight:600;"><?php echo htmlspecialchars($c['name']); ?></td>
                <td style="color:var(--secondary);"><?php echo htmlspecialchars($c['phone'] ?: '—'); ?></td>
                <td><?php echo $c['loan_count']; ?></td>
                <td>RWF <?php echo number_format($c['total_loaned'], 0); ?></td>
                <td>RWF <?php echo number_format($c['total_paid'], 0); ?></td>
                <td class="<?php echo $outstanding > 0 ? 'has-balance' : 'cleared'; ?>">
                    <strong>RWF <?php echo number_format(max(0, $outstanding), 0); ?></strong>
                </td>
                <td><span class="<?php echo $badge_cls; ?>"><?php echo $status; ?></span></td>
                <td style="color:var(--secondary);font-size:12px;white-space:nowrap;">
                    <?php echo date('M d, Y', strtotime($c['updated_at'])); ?>
                </td>
                <td style="white-space:nowrap;">
                    <button class="btn-pay" style="margin-right:4px;"
                        onclick="viewClientLoans(<?php echo htmlspecialchars(json_encode($c['name']), ENT_QUOTES); ?>, <?php echo (int)$c['client_id']; ?>)">
                        View
                    </button>
                    <button class="btn-pay" style="margin-right:4px;background:#0891b2;"
                        onclick="viewClientPayments(<?php echo (int)$c['client_id']; ?>, <?php echo htmlspecialchars(json_encode($c['name']), ENT_QUOTES); ?>)">
                        Payments
                    </button>
                    <?php if ($outstanding > 0): ?>
                    <button class="btn-pay" style="background:var(--warning,#f59e0b);"
                        onclick="openGlobalLoanPayFor(<?php echo htmlspecialchars(json_encode($c['name']), ENT_QUOTES); ?>, <?php echo (float)$outstanding; ?>)">
                        Pay
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
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

<!-- Client Loans Modal -->
<div id="clientLoansModal" class="modal">
    <div class="modal-content" style="max-width:800px;">
        <span class="close" onclick="closeModal('clientLoansModal')">&times;</span>
        <h2 id="clientLoansTitle">Loans</h2>
        <div id="clientLoansFilter" style="display:none;margin:12px 0 8px;gap:6px;align-items:center;flex-wrap:wrap;">
            <button class="filter-pill active" data-status="unpaid" onclick="setClientLoanFilter(this)">Unpaid</button>
            <button class="filter-pill" data-status="all" onclick="setClientLoanFilter(this)">All</button>
            <span id="clientLoansCount" style="font-size:13px;color:var(--secondary);margin-left:4px;"></span>
        </div>
        <div id="clientLoansBody" style="overflow-x:auto;max-height:420px;overflow-y:auto;"></div>
        <div id="clientLoansPagination" style="display:none;margin-top:10px;gap:6px;justify-content:center;align-items:center;flex-wrap:wrap;"></div>
    </div>
</div>

<!-- Client Payments Modal -->
<div id="clientPaymentsModal" class="modal">
    <div class="modal-content" style="max-width:720px;">
        <span class="close" onclick="closeModal('clientPaymentsModal')">&times;</span>
        <h2 id="clientPaymentsTitle">Payments</h2>
        <div style="display:flex;gap:10px;margin:12px 0 8px;align-items:flex-end;flex-wrap:wrap;">
            <div class="form-group" style="margin:0;min-width:140px;">
                <label style="font-size:12px;">From</label>
                <input type="date" id="cpay_from">
            </div>
            <div class="form-group" style="margin:0;min-width:140px;">
                <label style="font-size:12px;">To</label>
                <input type="date" id="cpay_to">
            </div>
            <button class="btn btn-primary" style="padding:7px 18px;" onclick="loadClientPayments()">Find</button>
            <span id="clientPaymentsCount" style="font-size:13px;color:var(--secondary);padding-bottom:6px;"></span>
        </div>
        <div id="clientPaymentsBody" style="overflow-x:auto;max-height:420px;overflow-y:auto;"></div>
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
                                <small style="color:var(--secondary);"> (<?php echo $c['visits']; ?> loan<?php echo $c['visits']>1?'s':''; ?>)</small>
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

// ── Filter client table ────────────────────────────────────────────────────────
var _activeStatus = 'unpaid-partial';

function setStatusFilter(btn) {
    document.querySelectorAll('.filter-pill').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    _activeStatus = btn.getAttribute('data-status');
    filterClientTable();
}

function filterClientTable() {
    var term = (document.getElementById('clientTableSearch').value || '').trim().toLowerCase();
    var rows = document.querySelectorAll('#tbl-loan-clients tbody tr');
    var visible = 0;
    rows.forEach(function(row) {
        var name   = (row.cells[1] || {}).textContent || '';
        var phone  = (row.cells[2] || {}).textContent || '';
        var status = row.getAttribute('data-status') || '';
        var matchText   = !term || name.toLowerCase().indexOf(term) !== -1 || phone.toLowerCase().indexOf(term) !== -1;
        var matchStatus = _activeStatus === 'all' ||
                          (_activeStatus === 'unpaid-partial' ? (status === 'unpaid' || status === 'partial') : status === _activeStatus);
        var show = matchText && matchStatus;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    var badge = document.getElementById('clientCountBadge');
    if (badge) {
        var isFiltered = term || _activeStatus !== 'all';
        badge.textContent = isFiltered ? (visible + ' match' + (visible !== 1 ? 'es' : '')) : '';
    }
}
filterClientTable();

// ── View client loans modal ────────────────────────────────────────────────────
var _clLoans = [], _clFilter = 'unpaid', _clPage = 1, _clPageSize = 10, _clName = '';

function viewClientLoans(clientName, clientId) {
    _clName   = clientName;
    _clFilter = 'unpaid';
    _clPage   = 1;
    _clLoans  = [];
    document.getElementById('clientLoansTitle').textContent = clientName + ' — Loans';
    document.querySelectorAll('#clientLoansFilter [data-status]').forEach(function(b) {
        b.classList.toggle('active', b.getAttribute('data-status') === 'unpaid');
    });
    document.getElementById('clientLoansFilter').style.display = 'none';
    document.getElementById('clientLoansPagination').style.display = 'none';
    var body = document.getElementById('clientLoansBody');
    body.innerHTML = '<p style="text-align:center;padding:20px;color:var(--secondary);">Loading...</p>';
    openModal('clientLoansModal');

    var data = new FormData();
    data.append('get_client_loans', '1');
    data.append('client_id', clientId);

    fetch('loans.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(rows) {
            if (!rows || rows.length === 0) {
                body.innerHTML = '<p style="text-align:center;padding:24px;color:var(--secondary);">No loans found for this client.</p>';
                return;
            }
            _clLoans = rows;
            document.getElementById('clientLoansFilter').style.display = 'flex';
            renderClientLoans();
        })
        .catch(function() {
            body.innerHTML = '<p style="color:var(--danger);padding:20px;">Failed to load loans. Please try again.</p>';
        });
}

function setClientLoanFilter(btn) {
    document.querySelectorAll('#clientLoansFilter [data-status]').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    _clFilter = btn.getAttribute('data-status');
    _clPage = 1;
    renderClientLoans();
}

function clientLoanGoPage(p) { _clPage = p; renderClientLoans(); }

function renderClientLoans() {
    var today = new Date(); today.setHours(0,0,0,0);
    var filtered = _clLoans.filter(function(l) {
        return _clFilter === 'all' || (parseFloat(l.amount) - parseFloat(l.total_paid)) > 0;
    });

    var totalPages = Math.max(1, Math.ceil(filtered.length / _clPageSize));
    if (_clPage > totalPages) _clPage = totalPages;
    var start = (_clPage - 1) * _clPageSize;
    var pageRows = filtered.slice(start, start + _clPageSize);

    document.getElementById('clientLoansCount').textContent =
        filtered.length + ' loan' + (filtered.length !== 1 ? 's' : '');

    var body = document.getElementById('clientLoansBody');
    if (filtered.length === 0) {
        body.innerHTML = '<p style="text-align:center;padding:24px;color:var(--secondary);">No unpaid loans for this client.</p>';
        document.getElementById('clientLoansPagination').style.display = 'none';
        return;
    }

    var totalLoaned = 0, totalPaid = 0;
    filtered.forEach(function(l) { totalLoaned += parseFloat(l.amount); totalPaid += parseFloat(l.total_paid); });

    var html = '<table class="table" style="font-size:13px;min-width:720px;">' +
        '<thead><tr><th>#</th><th>Loan Date</th><th>Days</th><th>Product</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr></thead><tbody>';

    pageRows.forEach(function(l, idx) {
        var balance  = parseFloat(l.amount) - parseFloat(l.total_paid);
        var loanDate = new Date(l.loan_date); loanDate.setHours(0,0,0,0);
        var days     = Math.floor((today - loanDate) / 86400000);
        var daysColor = balance <= 0 ? 'var(--secondary)' : (days > 30 ? 'var(--danger)' : (days > 7 ? 'var(--warning)' : 'var(--secondary)'));
        var statusCls = balance <= 0 ? 'badge-paid' : (parseFloat(l.total_paid) > 0 ? 'badge-partial' : 'badge-unpaid');
        var statusTxt = balance <= 0 ? 'Paid' : (parseFloat(l.total_paid) > 0 ? 'Partial' : 'Unpaid');
        var saleTab = l.bulk_id ? 'bulk' : (l.retail_id ? 'retail' : '');
        var saleId  = l.bulk_id || l.retail_id || 0;
        var saleLink = saleId
            ? '<a href="sales.php?tab=' + saleTab + '&highlight=' + saleId + '" target="_blank" ' +
              'style="font-size:12px;color:var(--primary);text-decoration:none;padding:3px 6px;border:1px solid var(--primary);border-radius:4px;margin-right:4px;">' +
              saleTab.charAt(0).toUpperCase() + saleTab.slice(1) + ' ↗</a>' : '';
        html += '<tr>' +
            '<td style="color:var(--secondary);">' + (start + idx + 1) + '</td>' +
            '<td style="white-space:nowrap;">' + l.loan_date + '</td>' +
            '<td style="font-weight:600;color:' + daysColor + ';white-space:nowrap;">' + days + 'd</td>' +
            '<td>' + (l.product_category ? l.product_category + '-' : '') + (l.product_name || '—') + '</td>' +
            '<td>RWF ' + parseFloat(l.amount).toLocaleString() + '</td>' +
            '<td>RWF ' + parseFloat(l.total_paid).toLocaleString() + '</td>' +
            '<td class="' + (balance > 0 ? 'has-balance' : 'cleared') + '"><strong>RWF ' + Math.abs(balance).toLocaleString() + '</strong></td>' +
            '<td><span class="' + statusCls + '">' + statusTxt + '</span></td>' +
            '<td style="white-space:nowrap;">' + saleLink +
            (balance > 0 ? '<button class="btn-pay" data-loan-id="' + l.id + '" data-balance="' + balance + '" data-client="' + _clName.replace(/"/g,'&quot;') + '" onclick="openPayment(this)" style="margin-right:4px;">Pay</button>' : '') +
            '<a href="loans.php?delete=' + l.id + '" onclick="return confirm(\'Delete this loan?\');" style="font-size:12px;color:var(--danger);text-decoration:none;padding:3px 6px;border:1px solid var(--danger);border-radius:4px;">Del</a>' +
            '</td></tr>';
    });

    var outstanding = totalLoaned - totalPaid;
    html += '</tbody><tfoot><tr style="font-weight:600;background:var(--gray-50);">' +
        '<td colspan="4" style="padding:10px 12px;">Total (' + filtered.length + ')</td>' +
        '<td>RWF ' + totalLoaned.toLocaleString() + '</td>' +
        '<td>RWF ' + totalPaid.toLocaleString() + '</td>' +
        '<td class="' + (outstanding > 0 ? 'has-balance' : 'cleared') + '"><strong>RWF ' + Math.abs(outstanding).toLocaleString() + '</strong></td>' +
        '<td colspan="2"></td></tr></tfoot></table>';
    body.innerHTML = html;

    var pag = document.getElementById('clientLoansPagination');
    if (totalPages <= 1) { pag.style.display = 'none'; return; }
    pag.style.display = 'flex';
    var ph = '<button onclick="clientLoanGoPage(' + Math.max(1, _clPage-1) + ')" ' + (_clPage<=1?'disabled ':'') +
        'style="padding:4px 10px;border:1px solid var(--gray-300);border-radius:6px;cursor:pointer;background:#fff;">&#8249;</button>';
    var last = 0;
    for (var p = 1; p <= totalPages; p++) {
        if (p===1||p===totalPages||(p>=_clPage-2&&p<=_clPage+2)) {
            if (last && p-last>1) ph += '<span style="padding:4px 6px;">…</span>';
            var isAct = p===_clPage;
            ph += '<button onclick="clientLoanGoPage('+p+')" style="padding:4px 10px;border:1px solid '+(isAct?'var(--primary)':'var(--gray-300)')+';border-radius:6px;cursor:pointer;background:'+(isAct?'var(--primary)':'#fff')+';color:'+(isAct?'#fff':'inherit')+';">'+p+'</button>';
            last=p;
        }
    }
    ph += '<button onclick="clientLoanGoPage('+Math.min(totalPages,_clPage+1)+')" '+(_clPage>=totalPages?'disabled ':'')+'style="padding:4px 10px;border:1px solid var(--gray-300);border-radius:6px;cursor:pointer;background:#fff;">&#8250;</button>'+
        '<span style="font-size:13px;color:var(--secondary);">Page '+_clPage+' of '+totalPages+'</span>';
    pag.innerHTML = ph;
}

// ── Client Payments Modal ──────────────────────────────────────────────────────
var _cpayClientId = 0;

function viewClientPayments(clientId, clientName) {
    _cpayClientId = clientId;
    document.getElementById('clientPaymentsTitle').textContent = clientName + ' — Payments';
    document.getElementById('clientPaymentsCount').textContent = '';

    var today = new Date();
    var from  = new Date(today.getFullYear(), today.getMonth(), 1);
    document.getElementById('cpay_to').value   = today.toISOString().split('T')[0];
    document.getElementById('cpay_from').value = from.toISOString().split('T')[0];

    openModal('clientPaymentsModal');
    loadClientPayments();
}

function loadClientPayments() {
    var body = document.getElementById('clientPaymentsBody');
    body.innerHTML = '<p style="text-align:center;padding:20px;color:var(--secondary);">Loading...</p>';

    var data = new FormData();
    data.append('get_client_payments', '1');
    data.append('client_id',  _cpayClientId);
    data.append('date_from',  document.getElementById('cpay_from').value);
    data.append('date_to',    document.getElementById('cpay_to').value);

    fetch('loans.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(rows) {
            document.getElementById('clientPaymentsCount').textContent =
                rows.length + ' payment' + (rows.length !== 1 ? 's' : '');

            if (!rows.length) {
                body.innerHTML = '<p style="text-align:center;padding:24px;color:var(--secondary);">No payments in this period.</p>';
                return;
            }

            var total = 0;
            var html = '<table class="table" style="font-size:13px;min-width:620px;">' +
                '<thead><tr><th>#</th><th>Date</th><th>Product</th><th>Amount Paid</th><th>Received By</th><th>Created At</th></tr></thead><tbody>';

            rows.forEach(function(p, i) {
                total += parseFloat(p.amount_paid);
                var createdAt = p.created_at ? p.created_at.replace('T',' ').substring(0,16) : '—';
                html += '<tr>' +
                    '<td style="color:var(--secondary);">' + (i+1) + '</td>' +
                    '<td style="white-space:nowrap;">' + p.payment_date + '</td>' +
                    '<td>' + (p.product_category ? p.product_category+'-' : '') + (p.product_name || '—') + '</td>' +
                    '<td style="font-weight:600;color:var(--success);">RWF ' + parseFloat(p.amount_paid).toLocaleString() + '</td>' +
                    '<td style="color:var(--secondary);">' + (p.received_by_name || '—') + '</td>' +
                    '<td style="color:var(--secondary);font-size:12px;white-space:nowrap;">' + createdAt + '</td>' +
                    '</tr>';
            });

            html += '</tbody><tfoot><tr style="font-weight:600;background:var(--gray-50);">' +
                '<td colspan="3" style="padding:10px 12px;">Total</td>' +
                '<td style="color:var(--success);">RWF ' + total.toLocaleString() + '</td>' +
                '<td colspan="2"></td></tr></tfoot></table>';
            body.innerHTML = html;
        })
        .catch(function() {
            body.innerHTML = '<p style="color:var(--danger);padding:20px;">Failed to load payments.</p>';
        });
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
    var amount   = parseFloat(document.getElementById('gloan_amount').value) || 0;
    var client   = document.getElementById('gloan_client').value.trim();
    var btn      = document.getElementById('globalLoanPayBtn');
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
