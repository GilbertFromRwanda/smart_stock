<?php
require_once 'config.php';
if (!isLoggedIn()) { http_response_code(403); echo json_encode(['ok' => false]); exit; }
header('Content-Type: application/json');

$product_id = (int)($_GET['product_id'] ?? 0);
if ($product_id < 1) { echo json_encode(['ok' => false, 'levels' => []]); exit; }

// Most recent purchase for this product
$purchase = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id FROM purchases WHERE product_id = $product_id ORDER BY id DESC LIMIT 1"
));

if (!$purchase) {
    echo json_encode(['ok' => true, 'levels' => [], 'stock_qty' => 0]);
    exit;
}

$pid    = (int)$purchase['id'];
$lq     = mysqli_query($conn,
    "SELECT level_order, level_name, qty_per_parent, selling_price
     FROM purchase_levels WHERE purchase_id = $pid ORDER BY level_order"
);
$levels = [];
while ($l = mysqli_fetch_assoc($lq)) $levels[] = $l;

$stock     = mysqli_fetch_assoc(mysqli_query($conn, "SELECT quantity FROM stock WHERE product_id = $product_id"));
$stock_qty = $stock ? (int)$stock['quantity'] : 0;

echo json_encode(['ok' => true, 'levels' => $levels, 'stock_qty' => $stock_qty]);
