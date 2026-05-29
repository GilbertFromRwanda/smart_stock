<?php
require_once 'config.php';
if (!isLoggedIn()) { http_response_code(403); exit; }

header('Content-Type: application/json');

$today = date('Y-m-d');
$from  = mysqli_real_escape_string($conn, $_GET['coll_from'] ?? $today);
$to    = mysqli_real_escape_string($conn, $_GET['coll_to']   ?? $today);

if ($_SESSION['role'] !== 'admin') {
    $user_id = (int)$_SESSION['user_id'];
} else {
    $user_id = max(0, (int)($_GET['user_id'] ?? 0));
}

$user_where_bulk   = $user_id > 0 ? "AND sold_by = $user_id" : "";
$user_where_retail = $user_id > 0 ? "AND sold_by = $user_id" : "";
$user_where_loans  = $user_id > 0 ? "AND given_by = $user_id" : "";

$row = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COALESCE(SUM(cash_amount), 0) as cash_total,
        COALESCE(SUM(momo_amount), 0) as momo_total,
        COALESCE(SUM(loan_amount), 0) as loan_total
    FROM (
        SELECT cash_amount, momo_amount, loan_amount FROM sales_bulk   WHERE sale_date BETWEEN '$from' AND '$to' $user_where_bulk
        UNION ALL
        SELECT cash_amount, momo_amount, loan_amount FROM sales_retail WHERE sale_date BETWEEN '$from' AND '$to' $user_where_retail
    ) as combined
"));

$outstanding = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(amount), 0) as total FROM loans WHERE 1=1 $user_where_loans
"))['total'] ?? 0;

echo json_encode([
    'cash'        => (float)$row['cash_total'],
    'momo'        => (float)$row['momo_total'],
    'loan'        => (float)$row['loan_total'],
    'outstanding' => (float)$outstanding,
    'from'        => $from,
    'to'          => $to,
    'user_id'     => $user_id,
]);
