<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Check if user is admin (you can modify this based on your user roles)
// $current_user = getCurrentUser();
// if ($current_user['role'] !== 'admin') {
//     redirect('dashboard.php');
// }

// Handle Create/Update User
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_user']) || isset($_POST['edit_user'])) {
        $username = mysqli_real_escape_string($conn, $_POST['username']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
        $role = mysqli_real_escape_string($conn, $_POST['role']);
        $status = isset($_POST['status']) ? mysqli_real_escape_string($conn, $_POST['status']) : 'active';
        
        if (isset($_POST['add_user'])) {
            // Add new user
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            
            // Check if username or email exists
            $check_query = "SELECT id FROM users WHERE username = '$username' OR email = '$email'";
            $check_result = mysqli_query($conn, $check_query);
            
            if (mysqli_num_rows($check_result) > 0) {
                $error = "Username or email already exists!";
            } else {
                $query = "INSERT INTO users (username, password, email, full_name, role, status, created_at) 
                         VALUES ('$username', '$password', '$email', '$full_name', '$role', '$status', NOW())";
                
                if (mysqli_query($conn, $query)) {
                    $success = "User added successfully!";
                    logActivity($conn, $_SESSION['user_id'], 'Add User', "Added user: $username");
                } else {
                    $error = "Error adding user: " . mysqli_error($conn);
                }
            }
        } else {
            // Edit existing user
            $user_id = mysqli_real_escape_string($conn, $_POST['user_id']);
            
            // Check if username or email exists for other users
            $check_query = "SELECT id FROM users WHERE (username = '$username' OR email = '$email') AND id != $user_id";
            $check_result = mysqli_query($conn, $check_query);
            
            if (mysqli_num_rows($check_result) > 0) {
                $error = "Username or email already exists!";
            } else {
                $query = "UPDATE users SET 
                         username = '$username',
                         email = '$email',
                         full_name = '$full_name',
                         role = '$role',
                         status = '$status'
                         WHERE id = $user_id";
                
                // Update password if provided
                if (!empty($_POST['password'])) {
                    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $query = "UPDATE users SET 
                             username = '$username',
                             password = '$password',
                             email = '$email',
                             full_name = '$full_name',
                             role = '$role',
                             status = '$status'
                             WHERE id = $user_id";
                }
                
                if (mysqli_query($conn, $query)) {
                    $success = "User updated successfully!";
                    logActivity($conn, $_SESSION['user_id'], 'Edit User', "Edited user: $username");
                } else {
                    $error = "Error updating user: " . mysqli_error($conn);
                }
            }
        }
    }
    
    // Handle Delete User
    if (isset($_POST['delete_user'])) {
        $user_id = mysqli_real_escape_string($conn, $_POST['user_id']);
        
        // Don't allow deleting yourself
        if ($user_id == $_SESSION['user_id']) {
            $error = "You cannot delete your own account!";
        } else {
            // Get username for logging
            $user_query = mysqli_query($conn, "SELECT username FROM users WHERE id = $user_id");
            $user_data = mysqli_fetch_assoc($user_query);
            $username = $user_data['username'];
            
            $query = "DELETE FROM users WHERE id = $user_id";
            if (mysqli_query($conn, $query)) {
                $success = "User deleted successfully!";
                logActivity($conn, $_SESSION['user_id'], 'Delete User', "Deleted user: $username");
            } else {
                $error = "Error deleting user: " . mysqli_error($conn);
            }
        }
    }
    
    // Handle Toggle Status
    if (isset($_POST['toggle_status'])) {
        $user_id = mysqli_real_escape_string($conn, $_POST['user_id']);
        $current_status = mysqli_real_escape_string($conn, $_POST['current_status']);
        $new_status = $current_status == 'active' ? 'inactive' : 'active';
        
        // Don't allow deactivating yourself
        if ($user_id == $_SESSION['user_id']) {
            $error = "You cannot change your own status!";
        } else {
            $query = "UPDATE users SET status = '$new_status' WHERE id = $user_id";
            if (mysqli_query($conn, $query)) {
                $success = "User status updated successfully!";
                logActivity($conn, $_SESSION['user_id'], 'Toggle Status', "Changed user $user_id status to $new_status");
            } else {
                $error = "Error updating status: " . mysqli_error($conn);
            }
        }
    }
    
    // Handle Bulk Actions
    if (isset($_POST['bulk_action'])) {
        $action = $_POST['bulk_action'];
        $user_ids = $_POST['user_ids'] ?? [];
        
        if (!empty($user_ids)) {
            $ids_string = implode(',', array_map('intval', $user_ids));
            
            // Remove current user from bulk actions
            $ids_array = explode(',', $ids_string);
            $ids_array = array_diff($ids_array, [$_SESSION['user_id']]);
            $ids_string = implode(',', $ids_array);
            
            if (empty($ids_string)) {
                $error = "Cannot perform action on your own account!";
            } else {
                if ($action == 'delete') {
                    $query = "DELETE FROM users WHERE id IN ($ids_string)";
                    $action_text = "Deleted multiple users";
                } elseif ($action == 'activate') {
                    $query = "UPDATE users SET status = 'active' WHERE id IN ($ids_string)";
                    $action_text = "Activated multiple users";
                } elseif ($action == 'deactivate') {
                    $query = "UPDATE users SET status = 'inactive' WHERE id IN ($ids_string)";
                    $action_text = "Deactivated multiple users";
                }
                
                if (mysqli_query($conn, $query)) {
                    $success = "Bulk action completed successfully!";
                    logActivity($conn, $_SESSION['user_id'], 'Bulk Action', $action_text);
                } else {
                    $error = "Error performing bulk action: " . mysqli_error($conn);
                }
            }
        } else {
            $error = "No users selected!";
        }
    }
}

// Get all users
$users_query = "SELECT * FROM users ORDER BY created_at DESC";
$users_result = mysqli_query($conn, $users_query);


// Get recent activities
// $activities_query = "SELECT * FROM user_activities ORDER BY created_at DESC LIMIT 10";
$activities_result = [];

// Get user for editing
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = mysqli_real_escape_string($conn, $_GET['edit']);
    $edit_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $edit_id");
    $edit_user = mysqli_fetch_assoc($edit_query);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Small Stock Management</title>
    <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/user.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content">
            <div class="dashboard-header">
                <h1>User Management</h1>
                <div class="date-display">
                    <strong>📅 <?php echo date('l, F j, Y'); ?></strong>
                </div>
            </div>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

        

            <!-- User Management Grid -->
            <div class="user-management-container">
                <!-- Add/Edit User Form -->
                <div class="user-form-card">
                    <div class="user-form-header">
                        <div class="form-icon">
                            <?php echo $edit_user ? '✏️' : '➕'; ?>
                        </div>
                        <h2><?php echo $edit_user ? 'Edit User' : 'Add New User'; ?></h2>
                    </div>
                    
                    <form method="POST" action="" id="userForm" onsubmit="return validateUserForm()">
                        <?php if ($edit_user): ?>
                            <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                        <?php endif; ?>
                        
                    
                            <div class="form-group">
                                <label><i>👤</i> Username*</label>
                                <input type="text" name="username" id="username" required 
                                       value="<?php echo $edit_user ? htmlspecialchars($edit_user['username']) : ''; ?>"
                                       placeholder="Enter username">
                            </div>
                            
                            <div class="form-group">
                                <label><i>📧</i> Email*</label>
                                <input type="email" name="email" id="email" required
                                       value="<?php echo $edit_user ? htmlspecialchars($edit_user['email']) : ''; ?>"
                                       placeholder="user@example.com">
                            </div>
                        
                        
                        <div class="form-group">
                            <label><i>🏷️</i> Full Name*</label>
                            <input type="text" name="full_name" id="full_name" required 
                                   value="<?php echo $edit_user ? htmlspecialchars($edit_user['full_name']) : ''; ?>"
                                   placeholder="Enter full name">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label><i>🔑</i> <?php echo $edit_user ? 'New Password' : 'Password*'; ?></label>
                                <input type="password" name="password" id="password" 
                                       <?php echo $edit_user ? '' : 'required'; ?>
                                       placeholder="<?php echo $edit_user ? 'Leave blank to keep current' : 'Enter password'; ?>">
                                <?php if ($edit_user): ?>
                                    <div class="password-hint">
                                        ⓘ Leave blank to keep current password
                                    </div>
                                <?php else: ?>
                                    <div class="password-hint">
                                        ⓘ Minimum 6 characters
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group">
                                <label><i>🎭</i> Role*</label>
                                <select name="role" id="role" required>
                                    <option value="user" <?php echo ($edit_user && $edit_user['role'] == 'user') ? 'selected' : ''; ?>>User</option>
                                    <option value="manager" <?php echo ($edit_user && $edit_user['role'] == 'manager') ? 'selected' : ''; ?>>Manager</option>
                                    <option value="admin" <?php echo ($edit_user && $edit_user['role'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label><i>⚡</i> Status</label>
                                <select name="status" id="status">
                                    <option value="active" <?php echo ($edit_user && $edit_user['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($edit_user && $edit_user['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="suspended" <?php echo ($edit_user && $edit_user['status'] == 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                                </select>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 12px; margin-top: 24px;">
                            <button type="submit" name="<?php echo $edit_user ? 'edit_user' : 'add_user'; ?>" 
                                    class="btn btn-primary" style="flex: 1;">
                                <?php echo $edit_user ? '✏️ Update User' : '➕ Create User'; ?>
                            </button>
                            <?php if ($edit_user): ?>
                                <a href="users.php" class="btn btn-secondary">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <!-- Users Table -->
                <div class="user-table-container">
                    <div class="user-table-header">
                        <h2>
                            <span>👥 Users List</span>
                            <span style="font-size: 14px; font-weight: normal; color: var(--gray-500); background: var(--gray-100); padding: 4px 12px; border-radius: 20px;">
                                <?php echo mysqli_num_rows($users_result); ?> total
                            </span>
                        </h2>
                        
                        <div class="table-actions">
                          
                            
                            <select class="filter-dropdown" id="roleFilter" onchange="filterUsers()">
                                <option value="all">All Roles</option>
                                <option value="admin">Admin</option>
                                <option value="manager">Manager</option>
                                <option value="user">User</option>
                            </select>
                            
                            <select class="filter-dropdown" id="statusFilter" onchange="filterUsers()">
                                <option value="all">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                    </div>
                    
                    <form method="POST" action="" id="bulkActionForm">
                        <div class="table-responsive">
                            <table class="user-table" id="usersTable">
                                <thead>
                                    <tr>
                                        <th width="40">
                                            <input type="checkbox" id="selectAll" onclick="toggleSelectAll()">
                                        </th>
                                        <th>User</th>
                                         <th>UserName</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Joined</th>
                                        <th>Last Login</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    mysqli_data_seek($users_result, 0);
                                    while($user = mysqli_fetch_assoc($users_result)): 
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <input type="checkbox" name="user_ids[]" value="<?php echo $user['id']; ?>" class="user-checkbox">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                              <?php echo strtoupper(substr($user['full_name'], 0, 10)); ?>
                                        </td>
                                         <td>
                                              <?php echo $user['username']; ?>
                                        </td>
                                        <td>
                                            <span class="role-badge <?php echo $user['role']; ?>">
                                                <?php 
                                                if ($user['role'] == 'admin') echo '👑 Admin';
                                                elseif ($user['role'] == 'manager') echo '📊 Manager';
                                                else echo '👤 User';
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo $user['status']; ?>">
                                                <?php 
                                                if ($user['status'] == 'active') echo '✅ Active';
                                                elseif ($user['status'] == 'inactive') echo '⏸️ Inactive';
                                                else echo '🚫 Suspended';
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="display: flex; flex-direction: column;">
                                                <span style="font-weight: 500;"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></span>
                                                <span style="font-size: 11px; color: var(--gray-500);"><?php echo date('H:i', strtotime($user['created_at'])); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($user['last_login']): ?>
                                                <div style="display: flex; flex-direction: column;">
                                                    <span style="font-weight: 500;"><?php echo date('M d, Y', strtotime($user['last_login'])); ?></span>
                                                    <span style="font-size: 11px; color: var(--gray-500);"><?php echo date('H:i', strtotime($user['last_login'])); ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--gray-400);">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="?edit=<?php echo $user['id']; ?>" class="btn-icon edit" title="Edit User">
                                                    ✏️
                                                </a>
                                                
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirmDelete('<?php echo addslashes($user['full_name']); ?>')">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" name="delete_user" class="btn-icon delete" title="Delete User">
                                                            🗑️
                                                        </button>
                                                    </form>
                                                    
                                                    <form method="POST" action="" style="display: inline;">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <input type="hidden" name="current_status" value="<?php echo $user['status']; ?>">
                                                        <button type="submit" name="toggle_status" class="btn-icon toggle" title="<?php echo $user['status'] == 'active' ? 'Deactivate' : 'Activate'; ?> User">
                                                            <?php echo $user['status'] == 'active' ? '⏸️' : '▶️'; ?>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="btn-icon" style="opacity: 0.5; cursor: not-allowed;" title="Current User">
                                                        👤
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Bulk Actions -->
                        <div class="bulk-actions">
                            <div class="select-all">
                                <input type="checkbox" id="selectAllBottom" onclick="toggleSelectAll()">
                                <label for="selectAllBottom">Select All</label>
                            </div>
                            
                            <select name="bulk_action" id="bulkAction" class="filter-dropdown" required>
                                <option value="">Bulk Actions</option>
                                <option value="activate">✅ Activate Selected</option>
                                <option value="deactivate">⏸️ Deactivate Selected</option>
                                <option value="delete">🗑️ Delete Selected</option>
                            </select>
                            
                            <button type="submit" name="bulk_action_submit" class="btn btn-primary" onclick="return confirmBulkAction()">
                                Apply
                            </button>
                        </div>
                    </form>
                
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>🗑️ Confirm Delete</h3>
                <span class="modal-close" onclick="closeDeleteModal()">&times;</span>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 16px; font-size: 16px;">
                    Are you sure you want to delete user <strong id="deleteUserName"></strong>?
                </p>
                <p style="color: var(--danger); background: var(--danger-light); padding: 12px; border-radius: var(--radius); font-size: 14px;">
                    ⚠️ This action cannot be undone!
                </p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button class="btn btn-danger" id="confirmDeleteBtn">Delete User</button>
            </div>
        </div>
    </div>
     <script src="script.js"></script>
           <script>
createAdvancedTableSearch('txtSearchusersTable', 'usersTable', []);
    </script>
    
    <script>
        // Form Validation
        function validateUserForm() {
            const username = document.getElementById('username').value.trim();
            const email = document.getElementById('email').value.trim();
            const fullName = document.getElementById('full_name').value.trim();
            const password = document.getElementById('password').value;
            
            if (username.length < 3) {
                showToast('Username must be at least 3 characters', 'error');
                return false;
            }
            
            // if (!isValidEmail(email)) {
            //     showToast('Please enter a valid email address', 'error');
            //     return false;
            // }
            
            if (fullName.length < 2) {
                showToast('Please enter a valid full name', 'error');
                return false;
            }
            
            <?php if (!$edit_user): ?>
                if (password.length < 6) {
                    showToast('Password must be at least 6 characters', 'error');
                    return false;
                }
            <?php endif; ?>
            
            return true;
        }
        
        // Email validation
        function isValidEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }
        
        // Search users
        function searchUsers() {
            const searchTerm = document.getElementById('userSearch').value.toLowerCase();
            const roleFilter = document.getElementById('roleFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;
            const table = document.getElementById('usersTable');
            const rows = table.querySelectorAll('tbody tr');
            
            let visibleCount = 0;
            
            rows.forEach(row => {
                const userName = row.querySelector('.user-name')?.textContent.toLowerCase() || '';
                const userEmail = row.querySelector('.user-email')?.textContent.toLowerCase() || '';
                const username = row.querySelector('[style*="font-size: 11px"]')?.textContent.toLowerCase() || '';
                const role = row.querySelector('.role-badge')?.className || '';
                const status = row.querySelector('.status-badge')?.className || '';
                
                let matchesSearch = searchTerm === '' || 
                                  userName.includes(searchTerm) || 
                                  userEmail.includes(searchTerm) || 
                                  username.includes(searchTerm);
                
                let matchesRole = roleFilter === 'all' || role.includes(roleFilter);
                let matchesStatus = statusFilter === 'all' || status.includes(statusFilter);
                
                if (matchesSearch && matchesRole && matchesStatus) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Show no results message
            updateNoResultsMessage(visibleCount);
        }
        
        // Filter users
        function filterUsers() {
            searchUsers();
        }
        
        // Update no results message
        function updateNoResultsMessage(visibleCount) {
            const table = document.getElementById('usersTable');
            let noResultsRow = document.getElementById('noResultsRow');
            
            if (visibleCount === 0) {
                if (!noResultsRow) {
                    noResultsRow = document.createElement('tr');
                    noResultsRow.id = 'noResultsRow';
                    noResultsRow.innerHTML = `
                        <td colspan="7" style="text-align: center; padding: 60px;">
                            <span style="font-size: 48px; display: block; margin-bottom: 16px;">🔍</span>
                            <h3 style="margin-bottom: 8px; color: var(--gray-700);">No users found</h3>
                            <p style="color: var(--gray-500);">Try adjusting your search or filter criteria</p>
                        </td>
                    `;
                    table.querySelector('tbody').appendChild(noResultsRow);
                }
            } else {
                if (noResultsRow) {
                    noResultsRow.remove();
                }
            }
        }
        
        // Select all checkboxes
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const selectAllBottom = document.getElementById('selectAllBottom');
            const checkboxes = document.querySelectorAll('.user-checkbox');
            
            const isChecked = selectAll.checked;
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
            
            if (selectAllBottom) selectAllBottom.checked = isChecked;
        }
        
        // Confirm delete
        let pendingDeleteForm = null;

        function confirmDelete(userName) {
            document.getElementById('deleteUserName').textContent = userName;
            document.getElementById('deleteModal').style.display = 'block';
            // Store the form that triggered the delete so we can submit it on confirm
            pendingDeleteForm = event.target.closest('form');
            return false;
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
            pendingDeleteForm = null;
        }

        // Wire up the confirm delete button
        document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
            if (pendingDeleteForm) {
                pendingDeleteForm.submit();
            }
        });
        
        // Confirm bulk action
        function confirmBulkAction() {
            const action = document.getElementById('bulkAction').value;
            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            
            if (checkboxes.length === 0) {
                showToast('Please select at least one user', 'warning');
                return false;
            }
            
            let message = '';
            if (action === 'delete') {
                message = `Are you sure you want to delete ${checkboxes.length} user(s)? This cannot be undone!`;
            } else if (action === 'activate') {
                message = `Are you sure you want to activate ${checkboxes.length} user(s)?`;
            } else if (action === 'deactivate') {
                message = `Are you sure you want to deactivate ${checkboxes.length} user(s)?`;
            } else {
                showToast('Please select an action', 'warning');
                return false;
            }
            
            return confirm(message);
        }
        
        // Toast notifications
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `
                <span style="font-size: 20px;">
                    ${type === 'success' ? '✅' : type === 'error' ? '❌' : '⚠️'}
                </span>
                <span>${message}</span>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.style.display = 'none', 300);
                }, 5000);
            });
            
            // Add user search listener
            const searchInput = document.getElementById('userSearch');
            if (searchInput) {
                searchInput.addEventListener('keyup', searchUsers);
            }
            
            // Close modal when clicking outside
            window.onclick = function(event) {
                const modal = document.getElementById('deleteModal');
                if (event.target == modal) {
                    closeDeleteModal();
                }
            };
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Alt+A - Focus on add user form
            if (e.altKey && e.key === 'a') {
                e.preventDefault();
                document.getElementById('username').focus();
            }
            
            // Alt+S - Focus on search
            if (e.altKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('userSearch').focus();
            }
            
            // Escape - Close modal
            if (e.key === 'Escape') {
                closeDeleteModal();
            }
        });
    </script>
    
    <?php
    // Helper function for time ago
    function timeAgo($datetime) {
        $time = strtotime($datetime);
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M d, Y', $time);
        }
    }
    
    // Log activity function
    function logActivity($conn, $user_id, $action, $description) {
        // $query = "INSERT INTO user_activities (user_id, action, description, created_at) 
        //           VALUES ($user_id, '$action', '$description', NOW())";
        // mysqli_query($conn, $query);
    }
    ?>
</body>
</html>