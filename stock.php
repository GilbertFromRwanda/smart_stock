<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Handle move to retail shop - PRG pattern to prevent duplicates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['move_to_retail'])) {
    $product_id = (int)$_POST['product_id'];
    $move_type = $_POST['move_type'] ?? 'pieces';

    $stock_check = mysqli_query($conn, "SELECT * FROM stock WHERE product_id = $product_id");
    $stock = mysqli_fetch_assoc($stock_check);

    $available_pieces = $stock['quantity'] * $stock['pieces_per_package'];
    $available_packages = $stock['quantity'];

    if ($move_type === 'packages') {
        $packages_to_move = (int)$_POST['packages_to_move'];
        $pieces_to_move = $packages_to_move * $stock['pieces_per_package'];
        $packages_to_remove = $packages_to_move;
    } else {
        $pieces_to_move = (int)$_POST['pieces_to_move'];
        $packages_to_remove = ceil($pieces_to_move / $stock['pieces_per_package']);
    }

    if ($pieces_to_move > 0 && $pieces_to_move <= $available_pieces) {
        mysqli_query($conn, "UPDATE stock SET quantity = quantity - $packages_to_remove WHERE product_id = $product_id");

        $retail_check = mysqli_query($conn, "SELECT * FROM retail_stock WHERE product_id = $product_id");

        if (mysqli_num_rows($retail_check) > 0) {
            mysqli_query($conn, "UPDATE retail_stock SET pieces_quantity = pieces_quantity + $pieces_to_move,
                                 retail_price = {$stock['retail_price']}
                                 WHERE product_id = $product_id");
        } else {
            mysqli_query($conn, "INSERT INTO retail_stock (product_id, pieces_quantity, retail_price)
                                 VALUES ($product_id, $pieces_to_move, {$stock['retail_price']})");
        }

        $note = $move_type === 'packages'
            ? "Moved $packages_to_remove package(s) = $pieces_to_move pieces to retail shop"
            : "Moved $pieces_to_move pieces to retail shop";
        $note = mysqli_real_escape_string($conn, $note);
        mysqli_query($conn, "INSERT INTO stock_movements (product_id, pieces_moved, moved_date, notes)
                             VALUES ($product_id, $pieces_to_move, CURDATE(), '$note')");

        $_SESSION['flash_success'] = $move_type === 'packages'
            ? "Moved $packages_to_remove package(s) ($pieces_to_move pieces) to retail shop successfully"
            : "Moved $pieces_to_move pieces to retail shop successfully";
    } else {
        $_SESSION['flash_error'] = "Not enough stock available or invalid quantity";
    }
    header("Location: stock.php");
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

// Get main stock with product details
$main_stock = mysqli_query($conn, "
    SELECT s.*, p.name, p.category, p.reorder_level 
    FROM stock s
    JOIN products p ON s.product_id = p.id
    WHERE s.quantity > 0
");

// Get retail stock
$retail_stock = mysqli_query($conn, "
    SELECT r.*, p.name, p.category 
    FROM retail_stock r
    JOIN products p ON r.product_id = p.id
    WHERE r.pieces_quantity > 0
");

// Get products for dropdown
$products = mysqli_query($conn, "SELECT id, name FROM products ORDER BY name");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Management - Small Stock Management</title>
        <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content">
            <h1>Stock Management</h1>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="stock-container">
                <div class="stock-section">
                    <h2 class="collapsible-header" onclick="toggleSection(this)">
                        Main Warehouse Stock
                        <span class="collapse-icon">&#9660;</span>
                    </h2>
                    <div class="collapsible-body">
                    <div class="table-responsive">
                        <table class="table" id="tblStock">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Packages</th>
                                    <th>Pieces/Package</th>
                                    <th>Total Pieces/KG</th>
                                    <th>Kuranguza Price</th>
                                    <th>Detaye Price </th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = mysqli_fetch_assoc($main_stock)): 
                                    $total_pieces = $row['quantity'] * $row['pieces_per_package'];
                                    $status = $row['quantity'] <= $row['reorder_level'] ? 'Low Stock' : 'In Stock';
                                    $status_class = $row['quantity'] <= $row['reorder_level'] ? 'danger' : 'success';
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['category']); ?></td>
                                    <td><?php echo $row['quantity']; ?></td>
                                    <td><?php echo $row['pieces_per_package']; ?></td>
                                    <td><?php echo $total_pieces; ?></td>
                                    <td>RWF <?php echo number_format($row['package_price'], 0); ?></td>
                                    <td>RWF <?php echo number_format($row['retail_price'], 0); ?></td>
                                    <td><span class="badge badge-<?php echo $status_class; ?>"><?php echo $status; ?></span></td>
                                    <td>
                                        <button onclick="openMoveModal(<?php echo $row['product_id']; ?>, '<?php echo htmlspecialchars($row['name']); ?>', <?php echo $total_pieces; ?>, <?php echo $row['pieces_per_package']; ?>, <?php echo $row['quantity']; ?>)"
                                                class="btn btn-sm btn-primary">Move to Detaye</button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    </div>
                </div>

                <div class="stock-section">
                    <h2 class="collapsible-header collapsed" onclick="toggleSection(this)">
                        Retail Shop Stock
                        <span class="collapse-icon">&#9660;</span>
                    </h2>
                    <div class="collapsible-body collapsed">
                    <div class="table-responsive">
                        <table class="table"id="tblRetail">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Pieces/KG/Box Available</th>
                                    <th>Retail Price</th>
                                    <th>Total Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = mysqli_fetch_assoc($retail_stock)): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['category']); ?></td>
                                    <td><?php echo $row['pieces_quantity']; ?></td>
                                    <td>RWF <?php echo number_format($row['retail_price'], 0); ?></td>
                                    <td>RWF <?php echo number_format($row['pieces_quantity'] * $row['retail_price'], 0); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Move to Retail Modal -->
    <div id="moveStockModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('moveStockModal')">&times;</span>
            <h2>Move Stock to Retail Shop</h2>

            <form method="POST" action="" id="moveStockForm">
                <input type="hidden" id="move_product_id" name="product_id">
                <input type="hidden" id="move_pieces_per_package" name="pieces_per_package_val">

                <div class="form-group">
                    <label>Product</label>
                    <p id="move_product_name" class="form-text"></p>
                </div>

                <div class="form-group">
                    <label>Available Stock</label>
                    <p id="available_stock_info" class="form-text"></p>
                </div>

                <div class="form-group">
                    <label>Move By*</label>
                    <div style="display:flex; gap:10px; margin-bottom:8px;">
                        <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
                            <input type="radio" name="move_type" value="packages" id="move_type_packages" checked onchange="toggleMoveType()"> Packages
                        </label>
                        <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
                            <input type="radio" name="move_type" value="pieces" id="move_type_pieces" onchange="toggleMoveType()"> Pieces
                        </label>
                    </div>
                </div>

                <div class="form-group" id="packages_input_group">
                    <label for="packages_to_move">Number of Packages*</label>
                    <input type="number" id="packages_to_move" name="packages_to_move" min="1" oninput="calculateFromPackages()">
                </div>

                <div class="form-group" id="pieces_input_group" style="display:none;">
                    <label for="pieces_to_move">Number of Pieces*</label>
                    <input type="number" id="pieces_to_move" name="pieces_to_move" min="1" oninput="calculateFromPieces()">
                </div>

                <div class="form-group" id="move_summary" style="display:none; background:#f0f7ff; padding:10px; border-radius:5px; border-left:3px solid #007bff;">
                    <p id="move_calculation" style="margin:0; font-weight:500;"></p>
                </div>

                <button type="submit" name="move_to_retail" class="btn btn-primary">Move Stock</button>
            </form>
        </div>
    </div>
    
    <script src="script.js"></script>
    <script>
createAdvancedTableSearch('txtSearchStock', 'tblStock', []);
createAdvancedTableSearch('txtSearchRetail', 'tblRetail', []);

function toggleSection(header) {
    const body = header.nextElementSibling;
    header.classList.toggle('collapsed');
    body.classList.toggle('collapsed');
}
    </script>
</body>
</html>