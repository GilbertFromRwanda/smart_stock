<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<div class="sidebar">
    <h2>UO & GN Boutique</h2>
    <ul class="nav-menu">
        <li><a href="dashboard.php" <?php echo $current_page == 'dashboard.php' ? 'class="active"' : ''; ?>>Dashboard</a></li>
        <li><a href="products.php" <?php echo $current_page == 'products.php' ? 'class="active"' : ''; ?>>Products/Items</a></li>
        <li><a href="purchases.php" <?php echo $current_page == 'purchases.php' ? 'class="active"' : ''; ?>>Purchases/Kurangura</a></li>
        <li><a href="sales.php" <?php echo $current_page == 'sales.php' ? 'class="active"' : ''; ?>>Sales/Gucuruza</a></li>
        <li><a href="stock.php" <?php echo $current_page == 'stock.php' ? 'class="active"' : ''; ?>>Stock Management</a></li>
        
        <li><a href="suppliers.php" <?php echo $current_page == 'suppliers.php' ? 'class="active"' : ''; ?>>Suppliers</a></li>
        <?php 
        if( in_array($_SESSION['role'],['admin','manager'])){
        ?> 
        <li><a href="revenue.php" <?php echo $current_page == 'revenue.php' ? 'class="active"' : ''; ?>>Profit Analysis</a></li>
        <li><a href="users.php" <?php echo $current_page == 'users.php' ? 'class="active"' : ''; ?>>Users</a></li>
      <?php 
        if( in_array($_SESSION['role'],['admin'])){
        ?> 
                <li><a href="database.php" <?php echo $current_page == 'database.php' ? 'class="active"' : ''; ?>>Database</a></li>
        <?php } ?>
        
      
      
        <?php } ?>
        <li><a href="logout.php">Logout</a></li>
    </ul>
    <div class="user-info">
        <div>👤 <?php echo $_SESSION['full_name'] ?? $_SESSION['username']; ?></div>
        <div style="font-size: 12px; margin-top: 5px;"><?php echo $_SESSION['role'] ?? 'Staff'; ?></div>
    </div>
</div>
