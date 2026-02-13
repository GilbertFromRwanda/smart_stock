<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Handle Add Product
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $reorder_level = mysqli_real_escape_string($conn, $_POST['reorder_level']);
    $unit_measure = mysqli_real_escape_string($conn, $_POST['unit_measure']);
    $unit_price = mysqli_real_escape_string($conn, $_POST['unit_price']);
    
    $query = "INSERT INTO products (name, category, reorder_level, unit_measure, unit_price) 
              VALUES ('$name', '$category', '$reorder_level', '$unit_measure', '$unit_price')";
    
    if (mysqli_query($conn, $query)) {
        $success = "Product added successfully";
          $_SESSION['flash_success'] =$success;
        
    } else {
        $error = "Error adding product: " . mysqli_error($conn);
         $_SESSION['flash_error']=$error;
    }
      header("Location: products.php");
      exit(0);
}
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Handle Edit Product
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_product'])) {
    $id = mysqli_real_escape_string($conn, $_POST['edit_id']);
    $name = mysqli_real_escape_string($conn, $_POST['edit_name']);
    $category = mysqli_real_escape_string($conn, $_POST['edit_category']);
    $reorder_level = mysqli_real_escape_string($conn, $_POST['edit_reorder_level']);
    $unit_measure = mysqli_real_escape_string($conn, $_POST['edit_unit_measure']);
    $unit_price = mysqli_real_escape_string($conn, $_POST['edit_unit_price']);

    $query = "UPDATE products SET name='$name', category='$category', reorder_level='$reorder_level',
              unit_measure='$unit_measure', unit_price='$unit_price' WHERE id=$id";

    if (mysqli_query($conn, $query)) {
        $_SESSION['flash_success'] = "Product updated successfully";
    } else {
        $_SESSION['flash_error'] = "Error updating product: " . mysqli_error($conn);
    }
    header("Location: products.php");
    exit(0);
}

// Handle Delete Product
if (isset($_GET['delete'])) {
    $id = mysqli_real_escape_string($conn, $_GET['delete']);
    mysqli_query($conn, "update products SET deleted=1  WHERE id = $id");
    redirect('products.php');
}

// Fetch all products
$products = mysqli_query($conn, "SELECT * FROM products where deleted=0 ORDER BY name asc");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - Small Stock Management</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content">
            <h1>Product Management</h1>
            
            <div class="action-bar">
                <button onclick="openModal('addProductModal')" class="btn btn-primary">Add New Product</button>
            </div>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
         
            <div class="table-responsive">
                <table class="table" id="tblProducts">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Reorder Level</th>
                            <th>Unit Measure</th>
                            <th>Unit Price</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i=1;
                        while($row = mysqli_fetch_assoc($products)): ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['category']); ?></td>
                            <td><?php echo $row['reorder_level']; ?></td>
                            <td><?php echo htmlspecialchars($row['unit_measure']); ?></td>
                            <td>RWF <?php echo number_format($row['unit_price'], 0); ?></td>
                            <td>
                                <a href="#" class="btn btn-sm btn-warning" onclick="editProduct(<?php echo $row['id']; ?>, '<?php echo addslashes(htmlspecialchars($row['name'])); ?>', '<?php echo addslashes(htmlspecialchars($row['category'])); ?>', <?php echo $row['reorder_level']; ?>, '<?php echo addslashes(htmlspecialchars($row['unit_measure'])); ?>', <?php echo $row['unit_price']; ?>)">Edit</a>
                                <a href="?delete=<?php echo $row['id']; ?>" 
                                   onclick="return confirm('Are you sure?')" 
                                   class="btn btn-sm btn-danger">Delete</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Add Product Modal -->
    <div id="addProductModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addProductModal')">&times;</span>
            <h2>Add New Product</h2>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="name">Product Name*</label>
                    <input type="text" id="name" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="category">Category</label>
                    <input type="text" id="category" name="category">
                </div>
                
                <div class="form-group">
                    <label for="reorder_level">Reorder Level*</label>
                    <input type="number" id="reorder_level" name="reorder_level" value="2" required>
                </div>
                
                <div class="form-group">
                    <label for="unit_measure">Unit Measure (e.g., KG, Box, Piece)*</label>
                    <input type="text" id="unit_measure" name="unit_measure" required value="Box">
                </div>
                
                <div class="form-group">
                    <label for="unit_price">Unit Price (RWF)*</label>
                    <input type="number" id="unit_price" name="unit_price" step="0.01" required>
                </div>
                
                <button type="submit" name="add_product" class="btn btn-primary">Add Product</button>
            </form>
        </div>
    </div>
    
    <!-- Edit Product Modal -->
    <div id="editProductModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editProductModal')">&times;</span>
            <h2>Edit Product</h2>

            <form method="POST" action="">
                <input type="hidden" id="edit_id" name="edit_id">

                <div class="form-group">
                    <label for="edit_name">Product Name*</label>
                    <input type="text" id="edit_name" name="edit_name" required>
                </div>

                <div class="form-group">
                    <label for="edit_category">Category</label>
                    <input type="text" id="edit_category" name="edit_category">
                </div>

                <div class="form-group">
                    <label for="edit_reorder_level">Reorder Level*</label>
                    <input type="number" id="edit_reorder_level" name="edit_reorder_level" required>
                </div>

                <div class="form-group">
                    <label for="edit_unit_measure">Unit Measure (e.g., KG, Box, Piece)*</label>
                    <input type="text" id="edit_unit_measure" name="edit_unit_measure" required>
                </div>

                <div class="form-group">
                    <label for="edit_unit_price">Unit Price (RWF)*</label>
                    <input type="number" id="edit_unit_price" name="edit_unit_price" step="0.01" required>
                </div>

                <button type="submit" name="edit_product" class="btn btn-primary">Update Product</button>
            </form>
        </div>
    </div>

    <script src="script.js"></script>
    <script>
function editProduct(id, name, category, reorderLevel, unitMeasure, unitPrice) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_category').value = category;
    document.getElementById('edit_reorder_level').value = reorderLevel;
    document.getElementById('edit_unit_measure').value = unitMeasure;
    document.getElementById('edit_unit_price').value = unitPrice;
    openModal('editProductModal');
}

createAdvancedTableSearch('txtSearchProduct', 'tblProducts', [
    { index: 0, name: 'ID' },
    { index: 1, name: 'Name' },
    { index: 2, name: 'Category' },
]);
    </script>
</body>
</html>