<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// ── AJAX: Add ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_expense'])) {
    $is_ajax      = !empty($_POST['ajax']);
    $description  = mysqli_real_escape_string($conn, trim($_POST['description']));
    $category     = mysqli_real_escape_string($conn, trim($_POST['category']));
    $amount       = mysqli_real_escape_string($conn, $_POST['amount']);
    $expense_date = mysqli_real_escape_string($conn, $_POST['expense_date']);

    if (empty($description) || empty($expense_date) || $amount <= 0) {
        $msg = "Description, amount, and date are required.";
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $msg]); exit; }
        $_SESSION['flash_error'] = $msg;
    } else {
        $ins = mysqli_query($conn, "INSERT INTO expenses (description, category, amount, expense_date) VALUES ('$description','$category','$amount','$expense_date')");
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode($ins ? ['success' => true] : ['success' => false, 'message' => mysqli_error($conn)]); exit; }
        $_SESSION['flash_success'] = $ins ? "Expense added." : "Error: " . mysqli_error($conn);
    }
    header("Location: expenses.php"); exit;
}

// ── AJAX: Edit ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_expense'])) {
    $is_ajax      = !empty($_POST['ajax']);
    $id           = (int)$_POST['expense_id'];
    $description  = mysqli_real_escape_string($conn, trim($_POST['description']));
    $category     = mysqli_real_escape_string($conn, trim($_POST['category']));
    $amount       = mysqli_real_escape_string($conn, $_POST['amount']);
    $expense_date = mysqli_real_escape_string($conn, $_POST['expense_date']);

    $upd = mysqli_query($conn, "UPDATE expenses SET description='$description', category='$category', amount='$amount', expense_date='$expense_date' WHERE id=$id");
    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode($upd ? ['success' => true] : ['success' => false, 'message' => mysqli_error($conn)]); exit; }
    $_SESSION['flash_success'] = $upd ? "Expense updated." : "Error: " . mysqli_error($conn);
    header("Location: expenses.php"); exit;
}

// ── Delete ────────────────────────────────────────────────────────────────────
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    mysqli_query($conn, "DELETE FROM expenses WHERE id=" . (int)$_GET['delete']);
    $_SESSION['flash_success'] = "Expense deleted.";
    header("Location: expenses.php"); exit;
}

// Flash messages
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

// Date filter
$date_from = isset($_GET['date_from']) ? mysqli_real_escape_string($conn, $_GET['date_from']) : '';
$date_to   = isset($_GET['date_to'])   ? mysqli_real_escape_string($conn, $_GET['date_to'])   : '';

$where = ""; $limit = " LIMIT 100";
if ($date_from && $date_to)  { $where = "WHERE expense_date BETWEEN '$date_from' AND '$date_to'"; $limit = ""; }
elseif ($date_from)           { $where = "WHERE expense_date >= '$date_from'"; $limit = ""; }
elseif ($date_to)             { $where = "WHERE expense_date <= '$date_to'";   $limit = ""; }

$records = mysqli_query($conn, "SELECT * FROM expenses $where ORDER BY expense_date DESC, id DESC $limit");

// Summary stats (all-time)
$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COUNT(*)                                                        AS total_records,
        COALESCE(SUM(amount), 0)                                       AS total_amount,
        COALESCE(SUM(CASE WHEN MONTH(expense_date)=MONTH(CURDATE()) AND YEAR(expense_date)=YEAR(CURDATE()) THEN amount ELSE 0 END), 0) AS this_month,
        COALESCE(SUM(CASE WHEN expense_date = CURDATE() THEN amount ELSE 0 END), 0) AS today
    FROM expenses
"));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .exp-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .exp-card {
            background: var(--white);
            border-radius: 14px;
            padding: 18px 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            border-top: 4px solid var(--primary);
        }
        .exp-card.red    { border-top-color: var(--danger); }
        .exp-card.orange { border-top-color: var(--warning); }
        .exp-card.purple { border-top-color: #7c3aed; }
        .exp-card-label {
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.6px; color: var(--secondary); margin-bottom: 8px;
        }
        .exp-card-value {
            font-size: 21px; font-weight: 700; color: var(--dark); line-height: 1.2;
        }
        .exp-card-sub { font-size: 11px; color: var(--secondary); margin-top: 4px; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
            <h1 style="margin:0;">Expenses</h1>
            <button onclick="openModal('addExpenseModal')" class="btn btn-primary">+ Add Expense</button>
        </div>

        <div class="exp-cards">
            <div class="exp-card">
                <div class="exp-card-label">Total Records</div>
                <div class="exp-card-value"><?php echo number_format($stats['total_records']); ?></div>
                <div class="exp-card-sub">all time</div>
            </div>
            <div class="exp-card red">
                <div class="exp-card-label">Total Spent</div>
                <div class="exp-card-value">RWF <?php echo number_format($stats['total_amount'], 0); ?></div>
                <div class="exp-card-sub">all time</div>
            </div>
            <div class="exp-card orange">
                <div class="exp-card-label">This Month</div>
                <div class="exp-card-value">RWF <?php echo number_format($stats['this_month'], 0); ?></div>
                <div class="exp-card-sub"><?php echo date('F Y'); ?></div>
            </div>
            <div class="exp-card purple">
                <div class="exp-card-label">Today</div>
                <div class="exp-card-value">RWF <?php echo number_format($stats['today'], 0); ?></div>
                <div class="exp-card-sub"><?php echo date('M d, Y'); ?></div>
            </div>
        </div>

        <form method="GET" class="date-filter-bar">
            <div class="filter-group">
                <label>From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="filter-group">
                <label>To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
            <?php if ($date_from || $date_to): ?>
                <a href="expenses.php" class="btn btn-secondary">Clear</a>
            <?php endif; ?>
        </form>

        <div id="pageAlert" class="alert" style="display:none;"></div>
        <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
        <?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

        <div class="table-responsive">
            <table class="table" id="tblExpenses">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Amount</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $rows = []; $date_totals = [];
                while ($row = mysqli_fetch_assoc($records)) {
                    $rows[] = $row;
                    $d = $row['expense_date'];
                    if (!isset($date_totals[$d])) $date_totals[$d] = 0;
                    $date_totals[$d] += $row['amount'];
                }
                $current_date = ''; $group_index = 0; $grand_total = 0; $row_num = 0;
                foreach ($rows as $row):
                    $row_date = $row['expense_date'];
                    if ($row_date !== $current_date):
                        if ($current_date !== ''):
                ?>
                <tr class="date-subtotal" data-group="<?php echo $group_index; ?>">
                    <td colspan="3"><strong>Subtotal</strong></td>
                    <td colspan="2"><strong>RWF <?php echo number_format($date_totals[$current_date], 0); ?></strong></td>
                </tr>
                <?php $group_index++; endif; $current_date = $row_date; $is_first = ($group_index === 0); ?>
                <tr class="date-group-header <?php echo $is_first ? 'active' : ''; ?>" data-toggle="<?php echo $group_index; ?>" onclick="toggleDateGroup(this)">
                    <td colspan="4">
                        <span class="toggle-icon"><?php echo $is_first ? '&#9660;' : '&#9654;'; ?></span>
                        <?php echo date('D, M d Y', strtotime($row_date)); ?>
                    </td>
                    <td class="header-total">RWF <?php echo number_format($date_totals[$row_date], 0); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="date-group-row" data-group="<?php echo $group_index; ?>" <?php if ($group_index > 0): ?>style="display:none"<?php endif; ?>>
                    <td><?php echo ++$row_num; ?></td>
                    <td><?php echo htmlspecialchars($row['description']); ?></td>
                    <td><?php echo htmlspecialchars($row['category'] ?: '-'); ?></td>
                    <td>RWF <?php echo number_format($row['amount'], 0); ?></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-secondary"
                            data-id="<?php echo $row['id']; ?>"
                            data-description="<?php echo htmlspecialchars($row['description'], ENT_QUOTES); ?>"
                            data-category="<?php echo htmlspecialchars($row['category'], ENT_QUOTES); ?>"
                            data-amount="<?php echo $row['amount']; ?>"
                            data-date="<?php echo $row['expense_date']; ?>"
                            onclick="openEditExpense(this)">Edit</button>
                        <a href="?delete=<?php echo $row['id']; ?>" class="btn btn-sm btn-danger"
                            onclick="return confirm('Delete this expense?')">Delete</a>
                    </td>
                </tr>
                <?php $grand_total += $row['amount']; endforeach;
                if ($current_date !== ''): ?>
                <tr class="date-subtotal" data-group="<?php echo $group_index; ?>" <?php if ($group_index > 0): ?>style="display:none"<?php endif; ?>>
                    <td colspan="3"><strong>Subtotal</strong></td>
                    <td colspan="2"><strong>RWF <?php echo number_format($date_totals[$current_date], 0); ?></strong></td>
                </tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="grand-total">
                        <td colspan="3"><strong>Grand Total</strong></td>
                        <td colspan="2"><strong>RWF <?php echo number_format($grand_total, 0); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div id="addExpenseModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addExpenseModal')">&times;</span>
        <h2>Add Expense</h2>
        <div id="addAlert" class="alert" style="display:none;"></div>
        <form id="addExpenseForm">
            <div class="form-group">
                <label>Date*</label>
                <input type="date" id="expense_date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label>Description*</label>
                <input type="text" id="description" name="description" required placeholder="e.g. Electricity bill, Rent...">
            </div>
            <div class="form-group">
                <label>Category</label>
                <input type="text" id="category" name="category" placeholder="e.g. Utilities, Rent..." list="categoryList">
                <datalist id="categoryList">
                    <option value="Rent"><option value="Utilities"><option value="Salary">
                    <option value="Transport"><option value="Maintenance"><option value="Other">
                </datalist>
            </div>
            <div class="form-group">
                <label>Amount (RWF)*</label>
                <input type="number" id="exp_amount" name="amount" min="1" step="1" required>
            </div>
            <button type="submit" name="add_expense" class="btn btn-primary">Save Expense</button>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editExpenseModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('editExpenseModal')">&times;</span>
        <h2>Edit Expense</h2>
        <div id="editAlert" class="alert" style="display:none;"></div>
        <form id="editExpenseForm">
            <input type="hidden" id="edit_expense_id" name="expense_id">
            <div class="form-group">
                <label>Date*</label>
                <input type="date" id="edit_expense_date" name="expense_date" required>
            </div>
            <div class="form-group">
                <label>Description*</label>
                <input type="text" id="edit_description" name="description" required>
            </div>
            <div class="form-group">
                <label>Category</label>
                <input type="text" id="edit_category" name="category" list="categoryList">
            </div>
            <div class="form-group">
                <label>Amount (RWF)*</label>
                <input type="number" id="edit_amount" name="amount" min="1" step="1" required>
            </div>
            <button type="submit" name="edit_expense" class="btn btn-primary">Update Expense</button>
        </form>
    </div>
</div>

<script src="script.js"></script>
<script>
function openEditExpense(btn) {
    var d = btn.dataset;
    document.getElementById('edit_expense_id').value   = d.id;
    document.getElementById('edit_expense_date').value = d.date;
    document.getElementById('edit_description').value  = d.description;
    document.getElementById('edit_category').value     = d.category;
    document.getElementById('edit_amount').value       = d.amount;
    document.getElementById('editAlert').style.display = 'none';
    openModal('editExpenseModal');
}

function toggleDateGroup(header) {
    var groupId = header.getAttribute('data-toggle');
    var rows = document.querySelectorAll('tr[data-group="' + groupId + '"]');
    var isActive = header.classList.contains('active');
    header.classList.toggle('active', !isActive);
    header.querySelector('.toggle-icon').innerHTML = isActive ? '&#9654;' : '&#9660;';
    rows.forEach(function(r) { r.style.display = isActive ? 'none' : ''; });
}

function ajaxForm(formId, alertId, actionName, onSuccess) {
    document.getElementById(formId).addEventListener('submit', function(e) {
        e.preventDefault();
        var form = this;
        var btn = form.querySelector('button[type="submit"]');
        var alertBox = document.getElementById(alertId);
        var origText = btn.textContent;
        btn.disabled = true; btn.textContent = 'Saving...';
        alertBox.style.display = 'none';

        var data = new FormData(form);
        data.append(actionName, '1');
        data.append('ajax', '1');

        fetch('expenses.php', { method: 'POST', body: data })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) { onSuccess(); }
                else {
                    alertBox.className = 'alert alert-danger';
                    alertBox.textContent = res.message || 'An error occurred.';
                    alertBox.style.display = 'block';
                    btn.disabled = false; btn.textContent = origText;
                }
            })
            .catch(function() {
                alertBox.className = 'alert alert-danger';
                alertBox.textContent = 'Network error. Please try again.';
                alertBox.style.display = 'block';
                btn.disabled = false; btn.textContent = origText;
            });
    });
}

ajaxForm('addExpenseForm', 'addAlert', 'add_expense', function() {
    closeModal('addExpenseModal');
    document.getElementById('addExpenseForm').reset();
    location.reload();
});

ajaxForm('editExpenseForm', 'editAlert', 'edit_expense', function() {
    closeModal('editExpenseModal');
    location.reload();
});
</script>
</body>
</html>
