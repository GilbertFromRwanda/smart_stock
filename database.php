<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Initialize messages
$success = '';
$error = '';

// Handle Delete Product (Soft Delete)
if (isset($_GET['delete'])) {
    $id = mysqli_real_escape_string($conn, $_GET['delete']);
    $table = mysqli_real_escape_string($conn, $_GET['table'] ?? 'products');
     mysqli_query($conn, "DELETE FROM $table WHERE id = $id");
    $success = "Record deleted successfully!";
    redirect("database.php");
}

// Handle Permanent Delete
if (isset($_GET['permanent_delete'])) {
    $id = mysqli_real_escape_string($conn, $_GET['permanent_delete']);
    $table = mysqli_real_escape_string($conn, $_GET['table'] ?? 'products');
    mysqli_query($conn, "DELETE FROM $table WHERE id = $id");
    $success = "Record permanently deleted!";
    redirect("database.php?tab=$table");
}

// Handle Restore Data
if (isset($_GET['restore'])) {
    $id = mysqli_real_escape_string($conn, $_GET['restore']);
    $table = mysqli_real_escape_string($conn, $_GET['table'] ?? 'products');
    // mysqli_query($conn, "UPDATE $table SET WHERE id = $id");
    $success = "Record restored successfully!";
    redirect("database.php?tab=$table");
}

// Handle Clean Data
if (isset($_POST['clean_data'])) {
    $table = mysqli_real_escape_string($conn, $_POST['table']);
    // Remove empty records
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
  mysqli_query($conn, "TRUNCATE TABLE $table");
mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
    
    // Trim whitespace from all text fields
    $columns = mysqli_query($conn, "SHOW COLUMNS FROM $table");
    while ($col = mysqli_fetch_assoc($columns)) {
        if (strpos($col['Type'], 'varchar') !== false || strpos($col['Type'], 'text') !== false) {
            $field = $col['Field'];
            mysqli_query($conn, "UPDATE $table SET $field = TRIM($field) WHERE $field LIKE ' %' OR $field LIKE '% '");
        }
    }
    
    $success = "Data cleaned successfully for $table table!";
    redirect("database.php?tab=$table");
}

// Get all database tables
$tables_result = mysqli_query($conn, "SHOW TABLES");
$tables = [];
while ($row = mysqli_fetch_array($tables_result)) {
    $tables[] = $row[0];
}

// Get selected table
$selected_table = isset($_GET['tab']) ? mysqli_real_escape_string($conn, $_GET['tab']) : 'products';

// Get deleted records count
$deleted_count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM $selected_table "))['count'];

// Fetch records from selected table
$records = mysqli_query($conn, "SELECT * FROM $selected_table ORDER BY id DESC");

// Fetch deleted records if requested
$show_deleted = isset($_GET['show_deleted']) && $_GET['show_deleted'] == 1;
if ($show_deleted) {
    $records = mysqli_query($conn, "SELECT * FROM $selected_table WHERE  ORDER BY id DESC");
}

// Get table columns
$columns = mysqli_query($conn, "SHOW COLUMNS FROM $selected_table");
$column_names = [];
while ($col = mysqli_fetch_assoc($columns)) {
    $column_names[] = $col['Field'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Management - Small Stock Management</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .tables-sidebar {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .table-badge {
            display: inline-block;
            padding: 8px 15px;
            margin: 5px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 20px;
            text-decoration: none;
            color: #333;
        }
        .table-badge.active {
            background: #007bff;
            color: #fff;
            border-color: #007bff;
        }
        .table-badge:hover {
            background: #e9ecef;
        }
        .table-badge.active:hover {
            background: #0056b3;
        }
        .data-cleaner {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .btn-clean {
            background: #ffc107;
            color: #000;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
        }
        .btn-clean:hover {
            background: #e0a800;
        }
        .btn-restore {
            background: #28a745;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            text-decoration: none;
        }
        .deleted-badge {
            background: #dc3545;
            color: white;
            padding: 5px 10px;
            border-radius: 3px;
            display: inline-block;
        }
        .table-stats {
            margin-bottom: 15px;
            padding: 10px;
            background: #e9ecef;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content">
            <h1>Database Management</h1>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <!-- Database Tables -->
            <div class="tables-sidebar">
                <h3>Database Tables</h3>
                <?php foreach ($tables as $table): ?>
                    <a href="?tab=<?php echo $table; ?>" 
                       class="table-badge <?php echo $selected_table == $table ? 'active' : ''; ?>">
                        <?php echo $table; ?>
                        <span style="margin-left: 5px; background: rgba(0,0,0,0.1); padding: 2px 6px; border-radius: 10px;">
                            <?php 
                            $count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM $table "));
                            echo $count['c'];
                            ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <!-- Data Cleaner Section -->
            <div class="data-cleaner">
                <h3>🧹 Data Cleaning Tools - <?php echo $selected_table; ?></h3>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="table" value="<?php echo $selected_table; ?>">
                    <input type="hidden" name="clean_data" value="1">
                    <button type="submit" class="btn-clean" onclick="return confirm('Clean data for <?php echo $selected_table; ?>? This will remove duplicates and trim whitespace.')">
                        Clean <?php echo $selected_table; ?> Data
                    </button>
                </form>
                
                <div class="table-stats">
                    <strong>Active Records:</strong> <?php 
                    $active = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM $selected_table "));
                    echo $active['c'];
                    ?> 
                    <?php if ($show_deleted): ?>
                        <a href="?tab=<?php echo $selected_table; ?>" style="margin-left: 10px;">
                            Back to Active Records
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Data Table -->
            <div class="table-responsive">
                <h3><?php echo $show_deleted ? 'Deleted Records - ' : ''; ?><?php echo ucfirst($selected_table); ?></h3>
                
                <!-- Search Box -->
                <input type="text" id="txtSearchTables" placeholder="Search records..." style="margin-bottom: 15px; padding: 8px; width: 300px;">
                
                <table class="table" id="tblTables">
                    <thead>
                        <tr>
                            <?php foreach ($column_names as $column): ?>
                                <?php if ($column != 'deleted'): ?>
                                    <th><?php echo ucfirst(str_replace('_', ' ', $column)); ?></th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($records) > 0): ?>
                            <?php while($row = mysqli_fetch_assoc($records)): ?>
                            <tr>
                                <?php foreach ($column_names as $column): ?>
                                    <?php if ($column != 'deleted'): ?>
                                        <td>
                                            <?php 
                                            if (strpos($column, 'price') !== false || strpos($column, 'cost') !== false) {
                                                echo '$' . number_format($row[$column], 2);
                                            } elseif (strpos($column, 'date') !== false || $column == 'created_at' || $column == 'updated_at') {
                                                echo date('Y-m-d H:i', strtotime($row[$column]));
                                            } else {
                                                echo htmlspecialchars(substr($row[$column] ?? '', 0, 50));
                                            }
                                            ?>
                                        </td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <td>
                                    <?php if ($show_deleted): ?>
                                        <a href="?restore=<?php echo $row['id']; ?>&table=<?php echo $selected_table; ?>" 
                                           class="btn btn-sm btn-success" 
                                           onclick="return confirm('Restore this record?')">Restore</a>
                                        <a href="?permanent_delete=<?php echo $row['id']; ?>&table=<?php echo $selected_table; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('Permanently delete this record? This cannot be undone!')">Delete Permanently</a>
                                    <?php else: ?>
                                        <a href="?delete=<?php echo $row['id']; ?>&table=<?php echo $selected_table; ?>" 
                                           onclick="return confirm('Are you sure?')" 
                                           class="btn btn-sm btn-danger">Delete</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?php echo count($column_names); ?>" style="text-align: center;">
                                    No records found in <?php echo $selected_table; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Data Cleanup Summary -->
            <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 5px;">
                <h4>📊 Database Summary</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">
                    <?php foreach ($tables as $table): ?>
                        <?php 
                        $total = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM $table"));
                        $active = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM $table "));
                        $deleted = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as c FROM $table"));
                        ?>
                        <div style="background: white; padding: 15px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                            <strong><?php echo $table; ?></strong><br>
                            <span style="color: #28a745;">Active: <?php echo $active['c']; ?></span><br>
                            <span style="color: #dc3545;">Deleted: <?php echo $deleted['c']; ?></span><br>
                            <span style="color: #6c757d;">Total: <?php echo $total['c']; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="script.js"></script>
    <script>
    // Enhanced table search
    function createAdvancedTableSearch(inputId, tableId, columns) {
        const input = document.getElementById(inputId);
        const table = document.getElementById(tableId);
        
        if (!input || !table) return;
        
        input.addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = table.getElementsByTagName('tr');
            
            for (let i = 1; i < rows.length; i++) {
                let visible = false;
                const cells = rows[i].getElementsByTagName('td');
                
                for (let j = 0; j < cells.length - 1; j++) {
                    const cell = cells[j];
                    if (cell) {
                        const textValue = cell.textContent || cell.innerText;
                        if (textValue.toLowerCase().indexOf(filter) > -1) {
                            visible = true;
                            break;
                        }
                    }
                }
                rows[i].style.display = visible ? '' : 'none';
            }
        });
    }

    // Initialize search for the current table
    createAdvancedTableSearch('txtSearchTables', 'tblTables', []);
    
    // Auto-refresh message timeout
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            alert.style.display = 'none';
        });
    }, 5000);
    </script>
</body>
</html>