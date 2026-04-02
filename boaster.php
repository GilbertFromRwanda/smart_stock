<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// // ── Create boaster table if not exists ─────────────────────────────────────────
// $create_table = "
// CREATE TABLE IF NOT EXISTS boaster (
//     id INT AUTO_INCREMENT PRIMARY KEY,
//     giver VARCHAR(255) NOT NULL,
//     amount DECIMAL(12,0) NOT NULL,
//     date DATE NOT NULL,
//     description TEXT,
//     phone VARCHAR(50),
//     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
// )";
// mysqli_query($conn, $create_table);

// ── AJAX: Add Boaster Entry ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_boaster'])) {
    $giver      = mysqli_real_escape_string($conn, trim($_POST['giver']));
    $amount     = mysqli_real_escape_string($conn, $_POST['amount']);
    $date       = mysqli_real_escape_string($conn, $_POST['date']);
    $description= mysqli_real_escape_string($conn, trim($_POST['description']));
    $phone      = mysqli_real_escape_string($conn, trim($_POST['phone']));

    if (empty($giver) || $amount <= 0 || empty($date)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Giver name, amount and date are required.']);
        exit;
    }

    $ins = mysqli_query($conn, "
        INSERT INTO boaster (giver, amount, date, description, phone)
        VALUES ('$giver', '$amount', '$date', '$description', '$phone')
    ");
    
    if ($ins) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    }
    exit;
}

// ── Delete Entry ────────────────────────────────────────────────────────────────
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM boaster WHERE id = $del_id");
    $_SESSION['flash_success'] = "Entry deleted.";
    header("Location: boaster.php");
    exit;
}

// Flash message
if (isset($_SESSION['flash_success'])) { 
    $success = $_SESSION['flash_success']; 
    unset($_SESSION['flash_success']); 
}

// ── Date filter ─────────────────────────────────────────────────────────────────
$date_from = isset($_GET['date_from']) ? mysqli_real_escape_string($conn, $_GET['date_from']) : '';
$date_to   = isset($_GET['date_to'])   ? mysqli_real_escape_string($conn, $_GET['date_to'])   : '';
$giver_filter = isset($_GET['giver']) ? mysqli_real_escape_string($conn, trim($_GET['giver'])) : '';

$where_parts = [];
if ($date_from && $date_to) {
    $where_parts[] = "date BETWEEN '$date_from' AND '$date_to'";
} elseif ($date_from) {
    $where_parts[] = "date >= '$date_from'";
} elseif ($date_to) {
    $where_parts[] = "date <= '$date_to'";
}
if ($giver_filter) {
    $where_parts[] = "giver LIKE '%$giver_filter%'";
}

$where = $where_parts ? "WHERE " . implode(" AND ", $where_parts) : "";
$limit = ($date_from || $date_to || $giver_filter) ? "" : " LIMIT 100";

$records = mysqli_query($conn, "
    SELECT * FROM boaster
    $where
    ORDER BY date DESC, id DESC
    $limit
");

// ── Summary Stats ───────────────────────────────────────────────────────────────
$stats = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT 
        COUNT(*) AS total_entries,
        COUNT(DISTINCT giver) AS unique_givers,
        COALESCE(SUM(amount), 0) AS total_amount
    FROM boaster
"));

$filtered_stats = ['total_amount' => 0, 'total_entries' => 0];
if ($date_from || $date_to || $giver_filter) {
    $filtered = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COALESCE(SUM(amount), 0) AS total_amount, COUNT(*) AS total_entries
        FROM boaster $where
    "));
    $filtered_stats = $filtered;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boaster - Contributions Register</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Additional styles specific to boaster page */
        .boaster-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid var(--primary);
        }
        .stat-card.green { border-left-color: #10b981; }
        .stat-card.orange { border-left-color: #f59e0b; }
        .stat-card.purple { border-left-color: #8b5cf6; }
        .stat-label {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6b7280;
            margin-bottom: 0.5rem;
        }
        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1f2937;
        }
        .stat-sub {
            font-size: 0.75rem;
            color: #9ca3af;
            margin-top: 0.5rem;
        }
        .filter-bar {
            background: white;
            padding: 1.25rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .filter-group label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
        }
        .filter-group input {
            padding: 0.5rem 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }
        .amount-positive {
            color: #10b981;
            font-weight: 600;
        }
        .giver-cell {
            font-weight: 600;
            color: #374151;
        }
        .table-wrapper {
            background: white;
            border-radius: 12px;
            overflow-x: auto;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        .btn-delete {
            color: #ef4444;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            transition: all 0.2s;
        }
        .btn-delete:hover {
            background: #fee2e2;
        }
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .grand-total {
            background: #f9fafb;
            font-weight: 700;
            border-top: 2px solid #e5e7eb;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">

        <div class="header-actions">
            <h1>📋 Boaster Register</h1>
            <button onclick="openModal('addBoasterModal')" class="btn btn-primary">+ New Entry</button>
        </div>

        <!-- Summary Cards -->
        <div class="boaster-summary">
            <div class="stat-card">
                <div class="stat-label">Total Entries</div>
                <div class="stat-value"><?php echo number_format($stats['total_entries']); ?></div>
                <div class="stat-sub"><?php echo $stats['unique_givers']; ?> unique givers</div>
            </div>
            <div class="stat-card green">
                <div class="stat-label">Total Amount</div>
                <div class="stat-value">RWF <?php echo number_format($stats['total_amount'], 0); ?></div>
                <div class="stat-sub">All time contributions</div>
            </div>
            <?php if ($filtered_stats['total_entries'] > 0 && ($date_from || $date_to || $giver_filter)): ?>
            <div class="stat-card orange">
                <div class="stat-label">Filtered Total</div>
                <div class="stat-value">RWF <?php echo number_format($filtered_stats['total_amount'], 0); ?></div>
                <div class="stat-sub"><?php echo $filtered_stats['total_entries']; ?> entries in range</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Flash Message -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success" style="margin-bottom: 1rem;"><?php echo $success; ?></div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <form method="GET" class="filter-bar">
            <div class="filter-group">
                <label>From Date</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="filter-group">
                <label>To Date</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="filter-group">
                <label>Giver Name</label>
                <input type="text" name="giver" value="<?php echo htmlspecialchars($giver_filter); ?>" placeholder="Search giver...">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <?php if ($date_from || $date_to || $giver_filter): ?>
                <a href="boaster.php" class="btn btn-secondary btn-sm">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Data Table -->
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Giver</th>
                        <th>Phone</th>
                        <th>Amount (RWF)</th>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $total_display = 0;
                $counter = 0;
                if (mysqli_num_rows($records) > 0):
                    while ($row = mysqli_fetch_assoc($records)):
                        $total_display += $row['amount'];
                        $counter++;
                ?>
                    <tr>
                        <td><?php echo $counter; ?></td>
                        <td><?php echo date('M d, Y', strtotime($row['date'])); ?></td>
                        <td class="giver-cell"><?php echo htmlspecialchars($row['giver']); ?></td>
                        <td><?php echo htmlspecialchars($row['phone'] ?: '-'); ?></td>
                        <td class="amount-positive">RWF <?php echo number_format($row['amount'], 0); ?></td>
                        <td><?php echo htmlspecialchars($row['description'] ?: '-'); ?></td>
                        <td>
                            <div class="action-buttons">
                                <a href="boaster.php?delete=<?php echo $row['id']; ?>" 
                                   class="btn-delete"
                                   onclick="return confirm('Delete this entry?')">Delete</a>
                            </div>
                        </td>
                    </tr>
                <?php 
                    endwhile;
                else:
                ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 2rem;">No entries found</td>
                    </tr>
                <?php endif; ?>
                </tbody>
                <?php if ($total_display > 0): ?>
                <tfoot>
                    <tr class="grand-total">
                        <td colspan="4"><strong>Total</strong></td>
                        <td><strong>RWF <?php echo number_format($total_display, 0); ?></strong></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- Add Boaster Modal -->
<div id="addBoasterModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addBoasterModal')">&times;</span>
        <h2>New Boaster Entry</h2>
        <div id="addBoasterAlert" class="alert" style="display:none;"></div>
        <form id="addBoasterForm">
            <div class="form-group">
                <label>Date*</label>
                <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label>Giver Name*</label>
                <input type="text" name="giver" required placeholder="Full name">
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="phone" placeholder="Optional contact number">
            </div>
            <div class="form-group">
                <label>Amount (RWF)*</label>
                <input type="number" name="amount" min="1" step="1" required placeholder="0">
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="3" placeholder="Optional notes..."></textarea>
            </div>
            <button type="submit" name="add_boaster" class="btn btn-primary">Save Entry</button>
        </form>
    </div>
</div>

<script src="script.js"></script>
<script>
// AJAX Form Handler
document.getElementById('addBoasterForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var form = this;
    var btn = form.querySelector('button[type="submit"]');
    var alertBox = document.getElementById('addBoasterAlert');
    var originalText = btn.textContent;
    
    btn.disabled = true;
    btn.textContent = 'Saving...';
    alertBox.style.display = 'none';
    
    var formData = new FormData(form);
    formData.append('add_boaster', '1');
    
    fetch('boaster.php', {
        method: 'POST',
        body: formData
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (data.success) {
            closeModal('addBoasterModal');
            form.reset();
            location.reload();
        } else {
            alertBox.className = 'alert alert-danger';
            alertBox.textContent = data.message || 'An error occurred';
            alertBox.style.display = 'block';
            btn.disabled = false;
            btn.textContent = originalText;
        }
    })
    .catch(function(error) {
        alertBox.className = 'alert alert-danger';
        alertBox.textContent = 'Network error. Please try again.';
        alertBox.style.display = 'block';
        btn.disabled = false;
        btn.textContent = originalText;
    });
});

function openModal(modalId) {
    document.getElementById(modalId).style.display = 'flex';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>
</body>
</html>