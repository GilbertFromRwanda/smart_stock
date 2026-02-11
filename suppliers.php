<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Handle Add Supplier
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_supplier'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $contact_person = mysqli_real_escape_string($conn, $_POST['contact_person']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    
    $query = "INSERT INTO suppliers (name, contact_person, phone, email, address) 
              VALUES ('$name', '$contact_person', '$phone', '$email', '$address')";
    
    if (mysqli_query($conn, $query)) {
        $success = "Supplier added successfully";
    } else {
        $error = "Error adding supplier: " . mysqli_error($conn);
    }
}

// Fetch all suppliers
$suppliers = mysqli_query($conn, "SELECT * FROM suppliers ORDER BY name");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers - Small Stock Management</title>
       <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content">
            <h1>Supplier Management</h1>
            
            <div class="action-bar">
                <button onclick="openModal('addSupplierModal')" class="btn btn-primary">Add New Supplier</button>
                <a href="purchases.php" class="btn btn-secondary">Back to Purchases</a>
            </div>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="table-responsive">
                <table class="table" id="tblSupplier">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Company Name</th>
                            <th>Contact Person</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Address</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = mysqli_fetch_assoc($suppliers)): ?>
                        <tr>
                            <td><?php echo $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo htmlspecialchars($row['contact_person']); ?></td>
                            <td><?php echo htmlspecialchars($row['phone']); ?></td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td><?php echo htmlspecialchars($row['address']); ?></td>
                            <td>
                                <a href="#" class="btn btn-sm btn-warning">Edit</a>
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
    
    <!-- Add Supplier Modal -->
    <div id="addSupplierModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addSupplierModal')">&times;</span>
            <h2>Add New Supplier</h2>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="name">Company Name*</label>
                    <input type="text" id="name" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="contact_person">Contact Person</label>
                    <input type="text" id="contact_person" name="contact_person">
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone">
                </div>
                
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email">
                </div>
                
                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" rows="3"></textarea>
                </div>
                
                <button type="submit" name="add_supplier" class="btn btn-primary">Add Supplier</button>
            </form>
        </div>
    </div>
    
    <script src="script.js"></script>
    <script>
        createAdvancedTableSearch('txtSearchSupplier', 'tblSupplier', []);
    </script>
</body>
</html>