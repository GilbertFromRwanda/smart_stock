<?php
/**
 * FIFO stock value cache — cost and selling values per product.
 * Including this file auto-creates the cache table if missing.
 */
global $conn;
if (isset($conn)) {
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS stock_value_cache (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT           NOT NULL,
        cost_wh    DECIMAL(12,2) NOT NULL DEFAULT 0,
        cost_rt    DECIMAL(12,2) NOT NULL DEFAULT 0,
        sell_wh    DECIMAL(12,2) NOT NULL DEFAULT 0,
        sell_rt    DECIMAL(12,2) NOT NULL DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_product (product_id)
    )");
}

/**
 * @param mysqli   $conn
 * @param int|null $product_id  null = recalc all products
 */
function recalcStockValue(mysqli $conn, ?int $product_id = null): void {
    $pid_flt = $product_id !== null ? "AND s.product_id = $product_id" : "";

    $rows = mysqli_query($conn, "
        SELECT s.product_id,
               GREATEST(0, s.quantity)               AS wh_qty,
               GREATEST(1, s.pieces_per_package)     AS ppp,
               s.package_price,
               COALESCE(rs.pieces_quantity, 0)        AS rt_pcs,
               COALESCE(rs.retail_price, s.retail_price, 0) AS rt_price
        FROM stock s
        LEFT JOIN retail_stock rs ON rs.product_id = s.product_id
        WHERE 1=1 $pid_flt
    ");

    if (!$rows) return;

    while ($row = mysqli_fetch_assoc($rows)) {
        $pid    = (int)$row['product_id'];
        $wh_qty = max(0, (int)$row['wh_qty']);
        $rt_pcs = max(0, (int)$row['rt_pcs']);
        $ppp    = max(1, (int)$row['ppp']);

        // Selling values
        $sell_wh = round($wh_qty * (float)$row['package_price'], 2);
        $sell_rt = round($rt_pcs * (float)$row['rt_price'], 2);

        // FIFO cost
        $wh_rem  = (float)$wh_qty;
        $rt_rem  = (float)$rt_pcs / $ppp;   // in package-equivalents
        $cost_wh = 0.0;
        $cost_rt = 0.0;

        $pq = mysqli_query($conn, "
            SELECT quantity, cost_price FROM purchases
            WHERE product_id = $pid AND cost_price > 0
            ORDER BY purchase_date DESC, id DESC
        ");

        while (($wh_rem > 0 || $rt_rem > 0) && ($p = mysqli_fetch_assoc($pq))) {
            $avail = (float)$p['quantity'];
            $cp    = (float)$p['cost_price'];

            if ($wh_rem > 0 && $avail > 0) {
                $take     = min($avail, $wh_rem);
                $cost_wh += $take * $cp;
                $wh_rem  -= $take;
                $avail   -= $take;
            }
            if ($rt_rem > 0 && $avail > 0) {
                $take     = min($avail, $rt_rem);
                $cost_rt += $take * $cp;
                $rt_rem  -= $take;
            }
        }

        $cost_wh = round($cost_wh, 2);
        $cost_rt = round($cost_rt, 2);

        // Upsert
        $existing = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT id FROM stock_value_cache WHERE product_id = $pid LIMIT 1"
        ));

        if ($existing) {
            mysqli_query($conn, "
                UPDATE stock_value_cache
                   SET cost_wh = $cost_wh, cost_rt = $cost_rt,
                       sell_wh = $sell_wh, sell_rt = $sell_rt,
                       updated_at = NOW()
                 WHERE id = {$existing['id']}
            ");
        } else {
            mysqli_query($conn, "
                INSERT INTO stock_value_cache (product_id, cost_wh, cost_rt, sell_wh, sell_rt)
                VALUES ($pid, $cost_wh, $cost_rt, $sell_wh, $sell_rt)
            ");
        }
    }
}
