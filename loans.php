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
            <button onclick="openModal('addLoanModal')" class="btn btn-primary">+ New Loan</button>
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
                <input type="text" name="name" value="<?php echo htmlspecialchars($name_filter); ?>" placeholder="Search name...">
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
            <?php if ($date_from || $date_to || $name_filter): ?>
                <a href="loans.php" class="btn btn-secondary">Clear</a>
            <?php endif; ?>
        </form>

        <div id="pageAlert" class="alert" style="display:none;"></div>
        <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <!-- Table -->
        <div class="loans-table-wrap">
            <table class="table" id="tblLoans">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Amount</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $grand_amount = 0; $grand_paid = 0; $i = 0;
                while ($row = mysqli_fetch_assoc($records)):
                    $balance = $row['amount'] - $row['total_paid'];
                    if ($row['total_paid'] >= $row['amount'])   { $status = 'Paid';    $badge = 'badge-paid'; }
                    elseif ($row['total_paid'] > 0)              { $status = 'Partial'; $badge = 'badge-partial'; }
                    else                                         { $status = 'Unpaid';  $badge = 'badge-unpaid'; }
                    $grand_amount += $row['amount'];
                    $grand_paid   += $row['total_paid'];
                ?>
                <tr>
                    <td><?php echo ++$i; ?></td>
                    <td><?php echo date('M d, Y', strtotime($row['loan_date'])); ?></td>
                    <td class="client-cell">
                        <strong><?php echo htmlspecialchars($row['client']); ?></strong>
                        <?php if ($row['phone']): ?>
                            <span><?php echo htmlspecialchars($row['phone']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($row['product_category'] . '-' . $row['product_name']); ?></td>
                    <td><?php echo $row['qty']; ?></td>
                    <td class="amount-cell">RWF <?php echo number_format($row['amount'], 0); ?></td>
                    <td class="amount-cell">RWF <?php echo number_format($row['total_paid'], 0); ?></td>
                    <td class="balance-cell <?php echo $balance > 0 ? 'has-balance' : 'cleared'; ?>">
                        RWF <?php echo number_format($balance, 0); ?>
                    </td>
                    <td><span class="badge <?php echo $badge; ?>"><?php echo $status; ?></span></td>
                    <td>
                        <div class="loan-actions">
                            <?php if ($balance > 0): ?>
                            <button type="button" class="btn-pay"
                                data-loan-id="<?php echo $row['id']; ?>"
                                data-client="<?php echo htmlspecialchars($row['client'], ENT_QUOTES); ?>"
                                data-balance="<?php echo $balance; ?>"
                                onclick="openPayment(this)">Pay</button>
                            <?php endif; ?>
                            <a href="loans.php?delete=<?php echo $row['id']; ?>" class="btn-del"
                                onclick="return confirm('Delete this loan?')">Del</a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr class="grand-total">
                        <td colspan="5">Total</td>
                        <td>RWF <?php echo number_format($grand_amount, 0); ?></td>
                        <td>RWF <?php echo number_format($grand_paid, 0); ?></td>
                        <td>RWF <?php echo number_format($grand_amount - $grand_paid, 0); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
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
                <input type="number" id="loan_amount" name="amount" min="1" step="1" required value="0">
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
</script>
</body>
</html>
