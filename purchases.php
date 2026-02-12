<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Get products and suppliers for dropdown
$products = mysqli_query($conn, "SELECT id, name,category FROM products ORDER BY name");
$suppliers = mysqli_query($conn, "SELECT id, name FROM suppliers ORDER BY name");

// Handle Add Purchase
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_purchase'])) {

    $product_id = mysqli_real_escape_string($conn, $_POST['product_id']);

    // ✅ supplier can be NULL
    if (empty($_POST['supplier_id'])) {
        $supplier_id = "NULL"; // no quotes later
    } else {
        $supplier_id = "'" . mysqli_real_escape_string($conn, $_POST['supplier_id']) . "'";
    }

    $quantity = mysqli_real_escape_string($conn, $_POST['quantity']);
    $pieces_per_qty = mysqli_real_escape_string($conn, $_POST['pieces_per_qty']);
    $cost_price = mysqli_real_escape_string($conn, $_POST['cost_price']);
    $package_price = mysqli_real_escape_string($conn, $_POST['package_price']);
    $retail_price = mysqli_real_escape_string($conn, $_POST['retail_price']);
    $purchase_date = mysqli_real_escape_string($conn, $_POST['purchase_date']);

    // Insert purchase
    $query = "
        INSERT INTO purchases (
            product_id, supplier_id, quantity, pieces_per_qty,
            cost_price, package_price, retail_price, purchase_date
        ) VALUES (
            '$product_id', $supplier_id, '$quantity', '$pieces_per_qty',
            '$cost_price', '$package_price', '$retail_price', '$purchase_date'
        )
    ";

    if (mysqli_query($conn, $query)) {

        $check_stock = mysqli_query($conn, "SELECT * FROM stock WHERE product_id = $product_id");

        if (mysqli_num_rows($check_stock) > 0) {
            $update = "
                UPDATE stock SET
                    quantity = quantity + $quantity,
                    pieces_per_package = $pieces_per_qty,
                    package_price = $package_price,
                    retail_price = $retail_price
                WHERE product_id = $product_id
            ";
        } else {
            $update = "
                INSERT INTO stock (
                    product_id, quantity, pieces_per_package,
                    package_price, retail_price
                ) VALUES (
                    '$product_id', '$quantity', '$pieces_per_qty',
                    '$package_price', '$retail_price'
                )
            ";
        }

        mysqli_query($conn, $update);
        $success = "Purchase added successfully and stock updated";
        $_SESSION['flash_success'] =$success;
    } else {
        $error = "Error adding purchase: " . mysqli_error($conn);
         $_SESSION['flash_error']=$error;
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
    SELECT p.*, pr.name as product_name, s.name as supplier_name
    FROM purchases p
    JOIN products pr ON p.product_id = pr.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    $where_clause
    ORDER BY p.purchase_date DESC, p.id DESC $limit
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchases - Small Stock Management</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .searchable-select {
            position: relative;
        }
        .searchable-select-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 14px;
            background: var(--white);
            cursor: text;
        }
        .searchable-select-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .searchable-select-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 200px;
            overflow-y: auto;
            background: var(--white);
            border: 1px solid var(--gray-300);
            border-top: none;
            border-radius: 0 0 var(--radius) var(--radius);
            z-index: 1000;
            box-shadow: var(--shadow-md);
        }
        .searchable-select-dropdown.open {
            display: block;
        }
        .searchable-select-option {
            padding: 9px 12px;
            cursor: pointer;
            font-size: 14px;
        }
        .searchable-select-option:hover,
        .searchable-select-option.highlighted {
            background: var(--gray-100);
            color: var(--primary);
        }
        .searchable-select-option.hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content">
            <h1>Purchase Management</h1>
            
            <div class="action-bar">
                <button onclick="openModal('addPurchaseModal')" class="btn btn-primary">New Purchase</button>
                <a href="suppliers.php" class="btn btn-secondary">Manage Suppliers</a>
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
                    <a href="purchases.php" class="btn btn-secondary">Clear</a>
                <?php endif; ?>
            </form>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="table" id="tblPurchases">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Supplier</th>
                            <th>Quantity</th>
                            <th>Pieces/Qty</th>
                            <th>Cost Price</th>
                            <th>Package Price</th>
                            <th>Detaye Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $current_date = '';
                        $day_total = 0;
                        $grand_total = 0;
                        $group_index = 0;
                        $rows = [];
                        // Pre-calculate subtotals per date
                        $date_totals = [];
                        while ($row = mysqli_fetch_assoc($purchases)) {
                            $rows[] = $row;
                            $d = date('Y-m-d', strtotime($row['purchase_date']));
                            if (!isset($date_totals[$d])) $date_totals[$d] = 0;
                            $date_totals[$d] += $row['cost_price'] * $row['quantity'];
                        }

                        foreach ($rows as $i => $row):
                            $row_date = date('Y-m-d', strtotime($row['purchase_date']));
                            $row_cost_total = $row['cost_price'] * $row['quantity'];

                            if ($row_date !== $current_date):
                                // Print subtotal for previous date group
                                if ($current_date !== ''):
                        ?>
                        <tr class="date-subtotal" data-group="<?php echo $group_index; ?>">
                            <td colspan="4"><strong>Subtotal</strong></td>
                            <td colspan="3"><strong>RWF <?php echo number_format($day_total, 0); ?></strong></td>
                        </tr>
                        <?php
                                    $group_index++;
                                endif;
                                $current_date = $row_date;
                                $day_total = 0;
                                $is_first = ($group_index === 0);
                        ?>
                        <tr class="date-group-header <?php echo $is_first ? 'active' : ''; ?>" data-toggle="<?php echo $group_index; ?>" onclick="toggleDateGroup(this)">
                            <td colspan="5">
                                <span class="toggle-icon"><?php echo $is_first ? '&#9660;' : '&#9654;'; ?></span>
                                <?php echo date('D, M d Y', strtotime($row_date)); ?>
                            </td>
                            <td colspan="2" class="header-total">RWF <?php echo number_format($date_totals[$row_date], 0); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="date-group-row" data-group="<?php echo $group_index; ?>" <?php if ($group_index > 0): ?>style="display:none"<?php endif; ?>>
                            <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['supplier_name'] ?? 'N/A'); ?></td>
                            <td><?php echo $row['quantity']; ?></td>
                            <td><?php echo $row['pieces_per_qty']; ?></td>
                            <td>RWF <?php echo number_format($row['cost_price'], 0); ?></td>
                            <td>RWF <?php echo number_format($row['package_price'], 0); ?></td>
                            <td>RWF <?php echo number_format($row['retail_price'], 0); ?></td>
                        </tr>
                        <?php
                            $day_total += $row_cost_total;
                            $grand_total += $row_cost_total;
                        endforeach;

                        // Print subtotal for last date group
                        if ($current_date !== ''):
                        ?>
                        <tr class="date-subtotal" data-group="<?php echo $group_index; ?>" <?php if ($group_index > 0): ?>style="display:none"<?php endif; ?>>
                            <td colspan="4"><strong>Subtotal</strong></td>
                            <td colspan="3"><strong>RWF <?php echo number_format($day_total, 0); ?></strong></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="grand-total">
                            <td colspan="4"><strong>Grand Total</strong></td>
                            <td colspan="3"><strong>RWF <?php echo number_format($grand_total, 0); ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Add Purchase Modal -->
    <div id="addPurchaseModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addPurchaseModal')">&times;</span>
            <h2>New Purchase</h2>
            
            <form method="POST" action="" id="purchaseForm">
                 <div class="form-group">
                    <label for="purchase_date">Purchase Date*</label>
                    <input type="date" id="purchase_date" name="purchase_date" value="" required>
                </div>
                <div class="form-group">
                    <label for="product_id">Product*</label>
                    <div class="searchable-select" id="productSearchable">
                        <input type="hidden" id="product_id" name="product_id" required>
                        <input type="text" class="searchable-select-input" id="product_search" placeholder="Search product..." autocomplete="off">
                        <div class="searchable-select-dropdown" id="product_dropdown">
                            <?php while($row = mysqli_fetch_assoc($products)): ?>
                                <div class="searchable-select-option" data-value="<?php echo $row['id']; ?>">
                                    <?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="quantity">Quantity (Number of packages)*</label>
                    <input type="number" id="quantity" value="" name="quantity" required min="1">
                </div>
                
                <div class="form-group">
                    <label for="pieces_per_qty">Pieces per Quantity(Qty Imwe Ingana ite?)*</label>
                    <input type="number" id="pieces_per_qty" name="pieces_per_qty" required min="1" value="">
                </div>
                
                <div class="form-group">
                    <label for="cost_price">Cost Price (per package)(Uko waranguye)*</label>
                    <input type="number" id="cost_price" name="cost_price" step="0.01" required>
                </div>
                
                <div class="form-group">
                    <label for="package_price">Kuranguza Price (Uko Uzaranguza)*</label>
                    <input type="number" id="package_price" name="package_price" step="0.01" required>
                </div>
                
                <div class="form-group">
                    <label for="retail_price">Detaye Price (per piece)*</label>
                    <input type="number" id="retail_price" name="retail_price" step="0.01" required>
                </div>
                  <div class="form-group">
                    <label for="supplier_id">Supplier</label>
                    <select id="supplier_id" name="supplier_id">
                        <option value="">Select Supplier</option>
                        <?php 
                        mysqli_data_seek($suppliers, 0);
                        while($row = mysqli_fetch_assoc($suppliers)): ?>
                            <option value="<?php echo $row['id']; ?>"><?php echo htmlspecialchars($row['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
               
                
                <button type="submit" name="add_purchase" class="btn btn-primary">Save Purchase</button>
            </form>
        </div>
    </div>
    
    <script src="script.js"></script>
    <script>
        createAdvancedTableSearch('txtSearchPurchases', 'tblPurchases', []);

        // Searchable product select
        (function() {
            var hiddenInput = document.getElementById('product_id');
            var searchInput = document.getElementById('product_search');
            var dropdown = document.getElementById('product_dropdown');
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
                if (!e.target.closest('#productSearchable')) {
                    dropdown.classList.remove('open');
                }
            });

            options.forEach(function(opt) {
                opt.addEventListener('click', function() {
                    selectOption(opt);
                });
            });

            function filterOptions() {
                var term = searchInput.value.toLowerCase();
                options.forEach(function(opt) {
                    if (opt.textContent.trim().toLowerCase().indexOf(term) > -1) {
                        opt.classList.remove('hidden');
                    } else {
                        opt.classList.add('hidden');
                    }
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
    </script>
</body>
</html>