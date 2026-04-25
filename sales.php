<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Handle Bulk Sale (Full Package) - PRG pattern to prevent duplicates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_sale'])) {
    $product_id = mysqli_real_escape_string($conn, $_POST['product_id']);
    $quantity = mysqli_real_escape_string($conn, $_POST['quantity']);
    $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $selling_price = mysqli_real_escape_string($conn, $_POST['selling_price']);

    $stock_query = mysqli_query($conn, "SELECT * FROM stock WHERE product_id = $product_id");
    $stock = mysqli_fetch_assoc($stock_query);

    if ($stock && $stock['quantity'] >= $quantity) {
        $total_amount = $quantity * $selling_price;

        $sale_query = "INSERT INTO sales_bulk (product_id, quantity, package_price, total_amount, sale_date, customer_name)
                       VALUES ($product_id, $quantity, $selling_price, $total_amount, CURDATE(), '$customer_name')";

        if (mysqli_query($conn, $sale_query)) {
            mysqli_query($conn, "UPDATE stock SET quantity = quantity - $quantity WHERE product_id = $product_id");
            $_SESSION['flash_success'] = "Bulk sale recorded successfully with price: RWF " . number_format($selling_price, 0);
            $_SESSION['flash_sale_type'] = 'bulk';
        }
    } else {
        $_SESSION['flash_error'] = "Insufficient stock available";
    }
    header("Location: sales.php");
    exit;
}

// Handle Retail Sale (Piece by piece) - PRG pattern to prevent duplicates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['retail_sale'])) {
    $product_id = mysqli_real_escape_string($conn, $_POST['product_id']);
    $pieces_sold = mysqli_real_escape_string($conn, $_POST['pieces_sold']);
    $customer_name = mysqli_real_escape_string($conn, $_POST['customer_name']);
    $selling_price = mysqli_real_escape_string($conn, $_POST['selling_price']);

    $retail_query = mysqli_query($conn, "SELECT * FROM retail_stock WHERE product_id = $product_id");
    $retail = mysqli_fetch_assoc($retail_query);

    if ($retail && $retail['pieces_quantity'] >= $pieces_sold) {
        $total_amount = $pieces_sold * $selling_price;

        $sale_query = "INSERT INTO sales_retail (product_id, pieces_sold, retail_price, total_amount, sale_date, customer_name)
                       VALUES ($product_id, $pieces_sold, $selling_price, $total_amount, CURDATE(), '$customer_name')";

        if (mysqli_query($conn, $sale_query)) {
            mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity = pieces_quantity - $pieces_sold WHERE product_id = $product_id");
            $_SESSION['flash_success'] = "Retail sale recorded successfully with price: RWF " . number_format($selling_price, 0) . " per piece";
            $_SESSION['flash_sale_type'] = 'retail';
        }
    } else {
        $_SESSION['flash_error'] = "Insufficient retail stock available";
    }
    header("Location: sales.php");
    exit;
}

// Read flash messages from session
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
$last_sale_type = $_SESSION['flash_sale_type'] ?? null;
unset($_SESSION['flash_sale_type']);

// Get products for bulk sale
$bulk_products = mysqli_query($conn, "
    SELECT s.*, p.name, p.unit_measure ,p.category
    FROM stock s
    JOIN products p ON s.product_id = p.id
    WHERE s.quantity > 0
");

// Get products for retail sale
$retail_products = mysqli_query($conn, "
    SELECT r.*, p.name, p.unit_measure,p.category
    FROM retail_stock r
    JOIN products p ON r.product_id = p.id
    WHERE r.pieces_quantity > 0
");

// Date filter (default: today)
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$date_from_safe = mysqli_real_escape_string($conn, $date_from);
$date_to_safe = mysqli_real_escape_string($conn, $date_to);

// Get sales filtered by date
$recent_bulk_sales = mysqli_query($conn, "
    SELECT sb.*, p.name
    FROM sales_bulk sb
    JOIN products p ON sb.product_id = p.id
    WHERE sb.sale_date BETWEEN '$date_from_safe' AND '$date_to_safe'
    ORDER BY sb.created_at DESC
");

$recent_retail_sales = mysqli_query($conn, "
    SELECT sr.*, p.name
    FROM sales_retail sr
    JOIN products p ON sr.product_id = p.id
    WHERE sr.sale_date BETWEEN '$date_from_safe' AND '$date_to_safe'
    ORDER BY sr.created_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales - Small Stock Management</title>
        <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/sales.css">
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
            <h1>Sales Management</h1>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="sale-action-buttons">
                <button class="btn btn-primary btn-lg" onclick="openModal('bulkSaleModal')">+ Kuranguza</button>
                <button class="btn btn-success btn-lg" onclick="openModal('retailSaleModal')">+ Gucuruza Detaye</button>
            </div>
            
            <div class="recent-sales">
                <h2>Recent Sales</h2>
                <form method="GET" class="date-filter-form">
                    <div class="date-filter-group">
                        <label for="date_from">From</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="date-filter-group">
                        <label for="date_to">To</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="sales.php" class="btn btn-sm" style="background:var(--gray-200);color:var(--dark);">Today</a>
                </form>
                <div class="sales-tables">
                    <div class="sales-table-section">
                        <h3 class="collapsible-header<?php echo ($last_sale_type === 'retail') ? ' collapsed' : ''; ?>" onclick="toggleSection(this)">
                            Ibyaranguwe
                            <span class="collapse-icon">&#9660;</span>
                        </h3>
                        <div class="collapsible-body<?php echo ($last_sale_type === 'retail') ? ' collapsed' : ''; ?>">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Product</th>
                                    <th>Qty</th>
                                    <th>Unit Price</th>
                                    <th>Default Price</th>
                                    <th>Difference</th>
                                    <th>Total</th>
                                    <th>Customer</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $bulk_grand_total = 0; while($row = mysqli_fetch_assoc($recent_bulk_sales)):
                                    $bulk_grand_total += $row['total_amount'];
                                    $default_price_query = mysqli_query($conn, "SELECT package_price FROM stock WHERE product_id = {$row['product_id']}");
                                    $default_price = mysqli_fetch_assoc($default_price_query)['package_price'];
                                    $price_diff = $row['package_price'] - $default_price;
                                    $diff_class = $price_diff > 0 ? 'text-danger' : ($price_diff < 0 ? 'text-success' : '');
                                ?>
                                <tr>
                                    <td><?php echo date('Y-m-d', strtotime($row['sale_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td><?php echo $row['quantity']; ?></td>
                                    <td><strong>RWF <?php echo number_format($row['package_price'], 0); ?></strong></td>
                                    <td>RWF <?php echo number_format($default_price, 0); ?></td>
                                    <td class="<?php echo $diff_class; ?>">
                                        <?php if($price_diff != 0): ?>
                                            <?php echo ($price_diff > 0 ? '+' : '') . number_format($price_diff, 0); ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>RWF <?php echo number_format($row['total_amount'], 0); ?></td>
                                    <td><?php echo htmlspecialchars($row['customer_name'] ?? 'N/A'); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-total-row">
                                    <td colspan="6" style="text-align:right;"><strong>Total:</strong></td>
                                    <td><strong>RWF <?php echo number_format($bulk_grand_total, 0); ?></strong></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                        </div>
                    </div>

                    <div class="sales-table-section">
                        <h3 class="collapsible-header<?php echo ($last_sale_type !== 'retail') ? ' collapsed' : ''; ?>" onclick="toggleSection(this)">
                            Ibyacurujwe detaye
                            <span class="collapse-icon">&#9660;</span>
                        </h3>
                        <div class="collapsible-body<?php echo ($last_sale_type !== 'retail') ? ' collapsed' : ''; ?>">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Product</th>
                                    <th>Pieces</th>
                                    <th>Price/Piece</th>
                                    <th>Default Price</th>
                                    <th>Difference</th>
                                    <th>Total</th>
                                    <th>Customer</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $retail_grand_total = 0; while($row = mysqli_fetch_assoc($recent_retail_sales)):
                                    $retail_grand_total += $row['total_amount'];
                                    $default_price_query = mysqli_query($conn, "SELECT retail_price FROM retail_stock WHERE product_id = {$row['product_id']}");
                                    $default_price = mysqli_fetch_assoc($default_price_query)['retail_price'];
                                    $price_diff = $row['retail_price'] - $default_price;
                                    $diff_class = $price_diff > 0 ? 'text-danger' : ($price_diff < 0 ? 'text-success' : '');
                                ?>
                                <tr>
                                    <td><?php echo date('Y-m-d', strtotime($row['sale_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td><?php echo $row['pieces_sold']; ?></td>
                                    <td><strong>RWF <?php echo number_format($row['retail_price'], 0); ?></strong></td>
                                    <td>RWF <?php echo number_format($default_price, 0); ?></td>
                                    <td class="<?php echo $diff_class; ?>">
                                        <?php if($price_diff != 0): ?>
                                            <?php echo ($price_diff > 0 ? '+' : '') . number_format($price_diff, 0); ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>RWF <?php echo number_format($row['total_amount'], 0); ?></td>
                                    <td><?php echo htmlspecialchars($row['customer_name'] ?? 'N/A'); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-total-row">
                                    <td colspan="6" style="text-align:right;"><strong>Total:</strong></td>
                                    <td><strong>RWF <?php echo number_format($retail_grand_total, 0); ?></strong></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Sale Modal -->
    <div id="bulkSaleModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('bulkSaleModal')">&times;</span>
            <h2>New Bulk Sale (Full Package)</h2>
            <form method="POST" action="" id="bulkSaleForm" onsubmit="return confirmBulkSale()">
                <div class="form-group">
                    <label for="bulk_product_id">Select Product*</label>
                    <select id="bulk_product_id" name="product_id" required onchange="updateBulkProductDetails()" style="display:none">
                        <option value="">Choose product...</option>
                        <?php
                        mysqli_data_seek($bulk_products, 0);
                        while($row = mysqli_fetch_assoc($bulk_products)):
                        ?>
                            <option value="<?php echo $row['product_id']; ?>"
                                    data-price="<?php echo $row['package_price']; ?>"
                                    data-stock="<?php echo $row['quantity']; ?>"
                                    data-product-name="<?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>"
                                    data-unit="<?php echo htmlspecialchars($row['unit_measure']); ?>">
                                <?php echo htmlspecialchars($row['category']).'- '.  htmlspecialchars($row['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <div class="searchable-select" id="bulkProductSearchable">
                        <input type="text" class="searchable-select-input" id="bulk_product_search" placeholder="Search product..." autocomplete="off">
                        <div class="searchable-select-dropdown" id="bulk_product_dropdown">
                            <?php
                            mysqli_data_seek($bulk_products, 0);
                            while($row = mysqli_fetch_assoc($bulk_products)):
                            ?>
                                <div class="searchable-select-option"
                                     data-value="<?php echo $row['product_id']; ?>"
                                     data-price="<?php echo $row['package_price']; ?>"
                                     data-stock="<?php echo $row['quantity']; ?>"
                                     data-product-name="<?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>"
                                     data-unit="<?php echo htmlspecialchars($row['unit_measure']); ?>">
                                    <?php echo htmlspecialchars($row['category'].'-'.$row['name']); ?>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>

                <div id="bulk_product_details" class="price-history" style="display: none;">
                    <strong>Product Info:</strong>
                    <span id="bulk_product_info"></span>
                </div>

                <div class="form-group">
                    <label for="bulk_quantity">Quantity (Packages)*</label>
                    <input type="text" id="bulk_quantity" name="quantity" required min="1" oninput="calculateBulkTotal()">
                    <small id="bulk_stock_info" class="field-hint"></small>
                    <small id="bulk_qty_error" class="field-error"></small>
                </div>

                <div class="form-group">
                    <label for="bulk_selling_price">Selling Price (per package)*</label>
                    <div class="price-input-group">
                        <input type="text" id="bulk_selling_price" name="selling_price"
                               step="1" required min="1"
                               oninput="calculateBulkTotal()">
                        <span class="default-price-badge" onclick="setBulkDefaultPrice()">Use Default</span>
                    </div>
                    <div id="bulk_price_warning" class="price-warning"></div>
                </div>

                <div class="form-group">
                    <label for="bulk_customer">Customer Name</label>
                    <input type="text" id="bulk_customer" name="customer_name" value="client"
                           placeholder="Enter customer name">
                </div>

                <div class="sale-summary" id="bulk_summary" style="display:none;">
                    <div class="summary-row"><span>Product</span><strong id="bulk_sum_product"></strong></div>
                    <div class="summary-row"><span>Packages</span><strong id="bulk_sum_qty"></strong></div>
                    <div class="summary-row"><span>Price/Package</span><strong id="bulk_sum_price"></strong></div>
                    <div class="summary-row summary-total"><span>Total Amount</span><strong id="bulk_sum_total"></strong></div>
                </div>

                <button type="submit" name="bulk_sale" id="bulk_submit_btn" class="btn btn-primary" disabled>Save Bulk Sale</button>
            </form>
        </div>
    </div>

    <!-- Retail Sale Modal -->
    <div id="retailSaleModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('retailSaleModal')">&times;</span>
            <h2>New Retail Sale (Piece by Piece)</h2>
            <form method="POST" action="" id="retailSaleForm" onsubmit="return confirmRetailSale()">
                <div class="form-group">
                    <label for="retail_product_id">Select Product*</label>
                    <select id="retail_product_id" name="product_id" required onchange="updateRetailProductDetails()" style="display:none">
                        <option value="">Choose product...</option>
                        <?php
                        mysqli_data_seek($retail_products, 0);
                        while($row = mysqli_fetch_assoc($retail_products)):
                        ?>
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
                            while($row = mysqli_fetch_assoc($retail_products)):
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

                <div id="retail_product_details" class="price-history" style="display: none;">
                    <strong>Product Info:</strong>
                    <span id="retail_product_info"></span>
                </div>

                <div class="form-group">
                    <label for="pieces_sold">Number of Pieces*</label>
                    <input type="text" id="pieces_sold" name="pieces_sold" required min="1" oninput="calculateRetailTotal()">
                    <small id="retail_stock_info" class="field-hint"></small>
                    <small id="retail_qty_error" class="field-error"></small>
                </div>

                <div class="form-group">
                    <label for="retail_selling_price">Selling Price (per piece)*</label>
                    <div class="price-input-group">
                        <input type="text" id="retail_selling_price" name="selling_price"
                               step="1" required min="1"
                               oninput="calculateRetailTotal()">
                        <span class="default-price-badge" onclick="setRetailDefaultPrice()">Use Default</span>
                    </div>
                    <div id="retail_price_warning" class="price-warning"></div>
                </div>

                <div class="form-group">
                    <label for="retail_customer">Customer Name</label>
                    <input type="text" id="retail_customer" name="customer_name" value="client"
                           placeholder="Enter customer name">
                </div>

                <div class="sale-summary" id="retail_summary" style="display:none;">
                    <div class="summary-row"><span>Product</span><strong id="retail_sum_product"></strong></div>
                    <div class="summary-row"><span>Pieces</span><strong id="retail_sum_qty"></strong></div>
                    <div class="summary-row"><span>Price/Piece</span><strong id="retail_sum_price"></strong></div>
                    <div class="summary-row summary-total"><span>Total Amount</span><strong id="retail_sum_total"></strong></div>
                </div>

                <button type="submit" name="retail_sale" id="retail_submit_btn" class="btn btn-primary" disabled>Save Retail Sale</button>
            </form>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
        // --- Searchable Select Init ---
        function initSearchableSelect(wrapperId, searchInputId, dropdownId, hiddenSelectId) {
            var wrapper = document.getElementById(wrapperId);
            var searchInput = document.getElementById(searchInputId);
            var dropdown = document.getElementById(dropdownId);
            var hiddenSelect = document.getElementById(hiddenSelectId);
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
                if (!e.target.closest('#' + wrapperId)) {
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
                var value = opt.getAttribute('data-value');
                searchInput.value = opt.textContent.trim();
                dropdown.classList.remove('open');
                highlightedIndex = -1;

                // Sync hidden select and trigger its onchange
                hiddenSelect.value = value;
                hiddenSelect.dispatchEvent(new Event('change'));
            }
        }

        initSearchableSelect('bulkProductSearchable', 'bulk_product_search', 'bulk_product_dropdown', 'bulk_product_id');
        initSearchableSelect('retailProductSearchable', 'retail_product_search', 'retail_product_dropdown', 'retail_product_id');

        // --- Bulk Sale ---
        function updateBulkProductDetails() {
            const select = document.getElementById('bulk_product_id');
            const opt = select.options[select.selectedIndex];
            if (!opt.value) {
                document.getElementById('bulk_product_details').style.display = 'none';
                document.getElementById('bulk_stock_info').innerHTML = '';
                document.getElementById('bulk_selling_price').value = '';
                document.getElementById('bulk_quantity').value = '';
                document.getElementById('bulk_summary').style.display = 'none';
                document.getElementById('bulk_submit_btn').disabled = true;
                return;
            }
            const price = opt.dataset.price;
            const stock = opt.dataset.stock;
            const name = opt.dataset.productName;

            document.getElementById('bulk_selling_price').value = price;
            document.getElementById('bulk_quantity').value = '';
            document.getElementById('bulk_quantity').max = stock;
            document.getElementById('bulk_stock_info').innerHTML = 'Available: ' + stock + ' packages';
            document.getElementById('bulk_product_details').style.display = 'block';
            document.getElementById('bulk_product_info').innerHTML = name + ' &mdash; Default price: RWF ' + parseFloat(price).toLocaleString();
            calculateBulkTotal();
        }

        function setBulkDefaultPrice() {
            const opt = document.getElementById('bulk_product_id').options[document.getElementById('bulk_product_id').selectedIndex];
            if (opt.value) {
                document.getElementById('bulk_selling_price').value = opt.dataset.price;
                calculateBulkTotal();
            }
        }

        function calculateBulkTotal() {
            const select = document.getElementById('bulk_product_id');
            const opt = select.options[select.selectedIndex];
            if (!opt.value) return;

            const stock = parseInt(opt.dataset.stock) || 0;
            const defaultPrice = parseFloat(opt.dataset.price) || 0;
            const qty = parseInt(document.getElementById('bulk_quantity').value) || 0;
            const price = parseFloat(document.getElementById('bulk_selling_price').value) || 0;
            const total = qty * price;
            const qtyError = document.getElementById('bulk_qty_error');
            const priceWarning = document.getElementById('bulk_price_warning');
            const summary = document.getElementById('bulk_summary');
            const submitBtn = document.getElementById('bulk_submit_btn');
            let valid = true;

            // Quantity check
            if (qty > stock) {
                qtyError.innerHTML = 'Exceeds available stock (' + stock + ')!';
                qtyError.style.display = 'block';
                valid = false;
            } else {
                qtyError.style.display = 'none';
            }

            // Price warning
            if (price > 0 && defaultPrice > 0 && price !== defaultPrice) {
                const diff = ((price - defaultPrice) / defaultPrice * 100).toFixed(1);
                priceWarning.innerHTML = 'Price is ' + (price > defaultPrice ? '+' : '') + diff + '% from default (RWF ' + defaultPrice.toLocaleString() + ')';
                priceWarning.style.display = 'block';
            } else {
                priceWarning.style.display = 'none';
            }

            if (qty < 1 || price < 1) valid = false;

            // Update summary
            if (valid) {
                document.getElementById('bulk_sum_product').textContent = opt.dataset.productName;
                document.getElementById('bulk_sum_qty').textContent = qty;
                document.getElementById('bulk_sum_price').textContent = 'RWF ' + price.toLocaleString();
                document.getElementById('bulk_sum_total').textContent = 'RWF ' + total.toLocaleString();
                summary.style.display = 'block';
            } else {
                summary.style.display = 'none';
            }
            submitBtn.disabled = !valid;
        }

        function confirmBulkSale() {
            const opt = document.getElementById('bulk_product_id').options[document.getElementById('bulk_product_id').selectedIndex];
            const qty = document.getElementById('bulk_quantity').value;
            const price = parseFloat(document.getElementById('bulk_selling_price').value);
            const total = qty * price;
            const name = opt.dataset.productName;

            return confirm(
                'Confirm Bulk Sale?\n\n' +
                'Product: ' + name + '\n' +
                'Packages: ' + qty + '\n' +
                'Price/Package: RWF ' + price.toLocaleString() + '\n' +
                'Total: RWF ' + total.toLocaleString() + '\n\n' +
                'This will deduct ' + qty + ' package(s) from warehouse stock.'
            );
        }

        // --- Retail Sale ---
        function updateRetailProductDetails() {
            const select = document.getElementById('retail_product_id');
            const opt = select.options[select.selectedIndex];
            if (!opt.value) {
                document.getElementById('retail_product_details').style.display = 'none';
                document.getElementById('retail_stock_info').innerHTML = '';
                document.getElementById('retail_selling_price').value = '';
                document.getElementById('pieces_sold').value = '';
                document.getElementById('retail_summary').style.display = 'none';
                document.getElementById('retail_submit_btn').disabled = true;
                return;
            }
            const price = opt.dataset.price;
            const stock = opt.dataset.stock;
            const name = opt.dataset.productName;

            document.getElementById('retail_selling_price').value = price;
            document.getElementById('pieces_sold').value = '';
            document.getElementById('pieces_sold').max = stock;
            document.getElementById('retail_stock_info').innerHTML = 'Available: ' + stock + ' pieces';
            document.getElementById('retail_product_details').style.display = 'block';
            document.getElementById('retail_product_info').innerHTML = name + ' &mdash; Default price: RWF ' + parseFloat(price).toLocaleString() + '/piece';
            calculateRetailTotal();
        }

        function setRetailDefaultPrice() {
            const opt = document.getElementById('retail_product_id').options[document.getElementById('retail_product_id').selectedIndex];
            if (opt.value) {
                document.getElementById('retail_selling_price').value = opt.dataset.price;
                calculateRetailTotal();
            }
        }

        function calculateRetailTotal() {
            const select = document.getElementById('retail_product_id');
            const opt = select.options[select.selectedIndex];
            if (!opt.value) return;

            const stock = parseInt(opt.dataset.stock) || 0;
            const defaultPrice = parseFloat(opt.dataset.price) || 0;
            const qty = parseInt(document.getElementById('pieces_sold').value) || 0;
            const price = parseFloat(document.getElementById('retail_selling_price').value) || 0;
            const total = qty * price;
            const qtyError = document.getElementById('retail_qty_error');
            const priceWarning = document.getElementById('retail_price_warning');
            const summary = document.getElementById('retail_summary');
            const submitBtn = document.getElementById('retail_submit_btn');
            let valid = true;

            // Quantity check
            if (qty > stock) {
                qtyError.innerHTML = 'Exceeds available stock (' + stock + ' pieces)!';
                qtyError.style.display = 'block';
                valid = false;
            } else {
                qtyError.style.display = 'none';
            }

            // Price warning
            if (price > 0 && defaultPrice > 0 && price !== defaultPrice) {
                const diff = ((price - defaultPrice) / defaultPrice * 100).toFixed(1);
                priceWarning.innerHTML = 'Price is ' + (price > defaultPrice ? '+' : '') + diff + '% from default (RWF ' + defaultPrice.toLocaleString() + ')';
                priceWarning.style.display = 'block';
            } else {
                priceWarning.style.display = 'none';
            }

            if (qty < 1 || price < 1) valid = false;

            // Update summary
            if (valid) {
                document.getElementById('retail_sum_product').textContent = opt.dataset.productName;
                document.getElementById('retail_sum_qty').textContent = qty;
                document.getElementById('retail_sum_price').textContent = 'RWF ' + price.toLocaleString();
                document.getElementById('retail_sum_total').textContent = 'RWF ' + total.toLocaleString();
                summary.style.display = 'block';
            } else {
                summary.style.display = 'none';
            }
            submitBtn.disabled = !valid;
        }

        function confirmRetailSale() {
            const opt = document.getElementById('retail_product_id').options[document.getElementById('retail_product_id').selectedIndex];
            const qty = document.getElementById('pieces_sold').value;
            const price = parseFloat(document.getElementById('retail_selling_price').value);
            const total = qty * price;
            const name = opt.dataset.productName;

            return confirm(
                'Confirm Retail Sale?\n\n' +
                'Product: ' + name + '\n' +
                'Pieces: ' + qty + '\n' +
                'Price/Piece: RWF ' + price.toLocaleString() + '\n' +
                'Total: RWF ' + total.toLocaleString() + '\n\n' +
                'This will deduct ' + qty + ' piece(s) from retail stock.'
            );
        }

        // --- Shared ---
        function toggleSection(header) {
            const body = header.nextElementSibling;
            header.classList.toggle('collapsed');
            body.classList.toggle('collapsed');
        }

        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(a) {
                setTimeout(function() {
                    a.style.opacity = '0';
                    setTimeout(function() { a.style.display = 'none'; }, 300);
                }, 5000);
            });
        });
    </script>
</body>
</html>