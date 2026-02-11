<?php
session_start();

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    // Redirect to dashboard if logged in
    header('Location: dashboard.php');
} else {
    // Redirect to login page if not logged in
    header('Location: login.php');
}
exit();
?>