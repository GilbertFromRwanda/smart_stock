<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

function abbr_money(float $n): string {
    $n   = (float)$n;
    $abs = abs($n);
    $sign = $n < 0 ? '-' : '';
    if ($abs >= 1_000_000) $abbr = $sign . rtrim(rtrim(number_format($abs / 1_000_000, 1), '0'), '.') . 'M';
    elseif ($abs >= 1_000) $abbr = $sign . rtrim(rtrim(number_format($abs / 1_000, 1), '0'), '.') . 'K';
    else                   $abbr = $sign . number_format($abs, 0);
    return $abbr;
}

// Get current date information
$today = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$month_start = date('Y-m-01');
$month_end = date('Y-m-t');

// ============== STOCK STATISTICS ==============
// Total products
$total_products = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM products"))['count'];

// Total stock value (main warehouse)
$stock_value_query = mysqli_query($conn, "
    SELECT SUM(quantity * package_price) as total_value 
    FROM stock
");
$total_stock_value = mysqli_fetch_assoc($stock_value_query)['total_value'] ?? 0;

// Total retail stock value
$retail_value_query = mysqli_query($conn, "
    SELECT SUM(pieces_quantity * retail_price) as total_value 
    FROM retail_stock
");
$total_retail_value = mysqli_fetch_assoc($retail_value_query)['total_value'] ?? 0;

// Total retail pieces
$total_retail_pieces = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT SUM(pieces_quantity) as total
    FROM retail_stock
"))['total'] ?? 0;

// Stock value at selling price (bulk warehouse + retail shop)
$selling_stock_value = ($total_stock_value ?? 0) + ($total_retail_value ?? 0);

// Stock value at purchase cost (weighted average cost × qty on hand)
$purchase_stock_value = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(s.quantity * avg_cost.wac), 0) AS purchase_value
    FROM stock s
    JOIN (
        SELECT product_id,
               SUM(quantity * cost_price) / NULLIF(SUM(quantity), 0) AS wac
        FROM purchases
        WHERE cost_price IS NOT NULL
        GROUP BY product_id
    ) avg_cost ON avg_cost.product_id = s.product_id
"))['purchase_value'] ?? 0;

$purchase_retail_value = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(rs.pieces_quantity * (avg_cost.wac / NULLIF(st.pieces_per_package, 1))), 0) AS purchase_value
    FROM retail_stock rs
    JOIN (
        SELECT product_id,
               SUM(quantity * cost_price) / NULLIF(SUM(quantity), 0) AS wac
        FROM purchases
        WHERE cost_price IS NOT NULL
        GROUP BY product_id
    ) avg_cost ON avg_cost.product_id = rs.product_id
    LEFT JOIN stock st ON st.product_id = rs.product_id
"))['purchase_value'] ?? 0;

$total_purchase_stock_value = $purchase_stock_value + $purchase_retail_value;

// Low stock products (below reorder level)
$low_stock_query = mysqli_query($conn, "
    SELECT p.name, s.quantity, p.reorder_level 
    FROM stock s
    JOIN products p ON s.product_id = p.id
    WHERE s.quantity <= p.reorder_level
    ORDER BY s.quantity ASC
    LIMIT 5
");

// Payment method breakdown (today)
$today_payment = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COALESCE(SUM(cash_amount), 0) as cash_total,
        COALESCE(SUM(momo_amount), 0) as momo_total,
        COALESCE(SUM(loan_amount), 0) as loan_total
    FROM (
        SELECT cash_amount, momo_amount, loan_amount FROM sales_bulk   WHERE sale_date = '$today'
        UNION ALL
        SELECT cash_amount, momo_amount, loan_amount FROM sales_retail WHERE sale_date = '$today'
    ) as combined
"));
$today_cash      = $today_payment['cash_total']  ?? 0;
$today_momo      = $today_payment['momo_total']  ?? 0;
$today_loan      = $today_payment['loan_total']  ?? 0;
$today_pay_total = $today_cash + $today_momo + $today_loan;

// Total outstanding loans
$outstanding_loans = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COALESCE(SUM(amount), 0) as total FROM loans
"))['total'] ?? 0;

// Users for collection filter (admin only)
$users_for_filter = mysqli_query($conn, "SELECT id, full_name FROM users ORDER BY full_name ASC");

// ============== SALES STATISTICS ==============
// Today's sales
$today_sales_query = mysqli_query($conn, "
    SELECT
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_bulk   WHERE sale_date = '$today' AND refunded = 0 AND has_loan = 0), 0) +
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_retail WHERE sale_date = '$today' AND refunded = 0 AND has_loan = 0), 0) as total,
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_bulk   WHERE sale_date = '$today' AND refunded = 0 AND has_loan = 0), 0) as bulk_total,
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_retail WHERE sale_date = '$today' AND refunded = 0 AND has_loan = 0), 0) as retail_total
");
$today_sales = mysqli_fetch_assoc($today_sales_query);

// This week's sales
$week_sales_query = mysqli_query($conn, "
    SELECT
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_bulk   WHERE sale_date BETWEEN '$week_start' AND '$week_end' AND refunded = 0 AND has_loan = 0), 0) +
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_retail WHERE sale_date BETWEEN '$week_start' AND '$week_end' AND refunded = 0 AND has_loan = 0), 0) as total
");
$week_sales = mysqli_fetch_assoc($week_sales_query)['total'] ?? 0;

// This month's sales
$month_sales_query = mysqli_query($conn, "
    SELECT
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_bulk   WHERE sale_date BETWEEN '$month_start' AND '$month_end' AND refunded = 0 AND has_loan = 0), 0) +
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_retail WHERE sale_date BETWEEN '$month_start' AND '$month_end' AND refunded = 0 AND has_loan = 0), 0) as total
");
$month_sales = mysqli_fetch_assoc($month_sales_query)['total'] ?? 0;

// ============== PROFIT STATISTICS ==============
// Today's profit (with cost calculation)
$today_profit_query = mysqli_query($conn, "
    SELECT
        -- Bulk sales profit (cost divided by level_divisor for sub-level sales)
        COALESCE((
            SELECT SUM(sb.total_amount - (pu.cost_price * sb.quantity / COALESCE(NULLIF(sb.level_divisor, 0), 1)))
            FROM sales_bulk sb
            JOIN purchases pu ON pu.product_id = sb.product_id
            WHERE sb.sale_date = '$today'
              AND sb.refunded = 0
              AND sb.has_loan = 0
            AND pu.id = (
                SELECT id FROM purchases p2
                WHERE p2.product_id = sb.product_id
                AND p2.purchase_date <= sb.sale_date
                ORDER BY p2.purchase_date DESC LIMIT 1
            )
        ), 0) +
        -- Retail sales profit
        COALESCE((
            SELECT SUM(sr.total_amount - ((pu.cost_price / NULLIF(s.pieces_per_package, 1)) * sr.pieces_sold))
            FROM sales_retail sr
            JOIN purchases pu ON pu.product_id = sr.product_id
            LEFT JOIN stock s ON sr.product_id = s.product_id
            WHERE sr.sale_date = '$today'
              AND sr.refunded = 0
              AND sr.has_loan = 0
            AND pu.id = (
                SELECT id FROM purchases p2
                WHERE p2.product_id = sr.product_id
                AND p2.purchase_date <= sr.sale_date
                ORDER BY p2.purchase_date DESC LIMIT 1
            )
        ), 0) as total_profit
");
$today_profit = mysqli_fetch_assoc($today_profit_query)['total_profit'] ?? 0;

// This week's profit
$week_profit_query = mysqli_query($conn, "
    SELECT COALESCE(SUM(total_profit), 0) as total_profit
    FROM weekly_revenue
    WHERE week_start_date = '$week_start'
");
$week_profit = mysqli_fetch_assoc($week_profit_query)['total_profit'] ?? 0;

// ============== TRENDING DATA ==============
// Last 7 days sales for chart
$last_7_days = mysqli_query($conn, "
    SELECT
        dates.date,
        COALESCE(SUM(CASE WHEN sb.has_loan = 0 AND sb.refunded = 0 THEN sb.total_amount ELSE 0 END), 0) as bulk_sales,
        COALESCE(SUM(CASE WHEN sr.has_loan = 0 AND sr.refunded = 0 THEN sr.total_amount ELSE 0 END), 0) as retail_sales
    FROM (
        SELECT CURDATE() - INTERVAL (a.a + (10 * b.a) + (100 * c.a)) DAY as date
        FROM (SELECT 0 as a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) as a
        CROSS JOIN (SELECT 0 as a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) as b
        CROSS JOIN (SELECT 0 as a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) as c
    ) as dates
    LEFT JOIN sales_bulk sb ON sb.sale_date = dates.date
    LEFT JOIN sales_retail sr ON sr.sale_date = dates.date
    WHERE dates.date BETWEEN CURDATE() - INTERVAL 6 DAY AND CURDATE()
    GROUP BY dates.date
    ORDER BY dates.date
");

// Top selling products (by quantity)
$top_products_query = mysqli_query($conn, "
    SELECT 
        p.name,
        p.category,
        COALESCE(SUM(sb.quantity), 0) as bulk_quantity,
        COALESCE(SUM(sr.pieces_sold), 0) as retail_quantity,
        COALESCE(SUM(sb.total_amount), 0) + COALESCE(SUM(sr.total_amount), 0) as total_revenue,
        COUNT(DISTINCT sb.id) + COUNT(DISTINCT sr.id) as total_sales
    FROM products p
    LEFT JOIN sales_bulk sb ON p.id = sb.product_id
    LEFT JOIN sales_retail sr ON p.id = sr.product_id
    GROUP BY p.id
    ORDER BY total_revenue DESC
    LIMIT 5
");

// Stock movement summary
$stock_movements_summary = mysqli_query($conn, "
    SELECT 
        COUNT(*) as total_movements,
        SUM(pieces_moved) as total_pieces_moved
    FROM stock_movements
    WHERE moved_date BETWEEN '$week_start' AND '$week_end'
");
$movements = mysqli_fetch_assoc($stock_movements_summary);

// Get total suppliers
$total_suppliers = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as count FROM suppliers"))['count'];

// Calculate profit margin
$profit_margin = $week_sales > 0 ? ($week_profit / $week_sales) * 100 : 0;

// Get alerts and notifications
$alerts = [];

// Low stock alerts
$low_stock_count = mysqli_num_rows($low_stock_query);
if ($low_stock_count > 0) {
    $alerts[] = [
        'type' => 'warning',
        'icon' => '⚠️',
        'title' => 'Low Stock Alert',
        'message' => "You have {$low_stock_count} product(s) below reorder level. Check stock management.",
        'link' => 'stock.php'
    ];
}

// No stock in retail
$retail_empty = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) as count FROM retail_stock WHERE pieces_quantity = 0
"))['count'];
if ($retail_empty > 0) {
    $alerts[] = [
        'type' => 'info',
        'icon' => '🛒',
        'title' => 'Retail Stock Empty',
        'message' => "{$retail_empty} product(s) have no pieces in retail shop. Move stock from warehouse.",
        'link' => 'stock.php'
    ];
}

// No sales today
if (($today_sales['total'] ?? 0) == 0) {
    $alerts[] = [
        'type' => 'info',
        'icon' => '📉',
        'title' => 'No Sales Today',
        'message' => 'You haven\'t recorded any sales today. Start selling!',
        'link' => 'sales.php'
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Small Stock Management</title>
    <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/dashboard.css">
    
</head>
<body>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content">
            <!-- Welcome Message -->
            <div class="welcome-message">
                <div>
                    <h2>Welcome back, <?php echo explode(' ', $_SESSION['full_name'] ?? $_SESSION['username'])[0]; ?>! 👋</h2>
                    <p><?php echo date('l, F j, Y'); ?> - Here's your business overview</p>
                </div>
                <div class="welcome-time">
                    <?php 
                    $hour = date('H');
                    if ($hour < 12) echo 'Good Morning';
                    elseif ($hour < 17) echo 'Good Afternoon';
                    else echo 'Good Evening';
                    ?>
                </div>
            </div>
            
            <!-- Key Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">📦</div>
                    <div class="stat-label">Total Products</div>
                    <div class="stat-number"><?php echo number_format($total_products); ?></div>
                    <div class="stat-trend">
                        <span>Active in inventory</span>
                    </div>
                    <div class="stat-footer">
                        <a href="products.php" style="color: #667eea; text-decoration: none;">View all products →</a>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">🏷️</div>
                    <div class="stat-label">Stock Value (Selling)</div>
                    <div class="stat-number">RWF <?php echo number_format($selling_stock_value, 0); ?></div>
                    <div class="stat-trend">
                        <span class="stock-status">
                            <span class="stock-dot green"></span>
                            At selling price
                        </span>
                    </div>
                    <div class="stat-footer">
                        Warehouse: RWF <?php echo number_format($total_stock_value, 0); ?> &nbsp;|&nbsp; Retail: RWF <?php echo number_format($total_retail_value, 0); ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">🧾</div>
                    <div class="stat-label">Stock Value (Purchase)</div>
                    <div class="stat-number">RWF <?php echo number_format($total_purchase_stock_value, 0); ?></div>
                    <div class="stat-trend">
                        <span class="stock-status">
                            <span class="stock-dot <?php
                                $margin = $selling_stock_value > 0 ? ($selling_stock_value - $total_purchase_stock_value) / $selling_stock_value * 100 : 0;
                                echo $margin >= 15 ? 'green' : ($margin >= 5 ? 'yellow' : 'red');
                            ?>"></span>
                            At cost price &nbsp;·&nbsp; Margin <?php echo number_format($margin, 1); ?>%
                        </span>
                    </div>
                    <div class="stat-footer">
                        Potential profit: RWF <?php echo number_format($selling_stock_value - $total_purchase_stock_value, 0); ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">🛒</div>
                    <div class="stat-label">Today's Sales</div>
                    <div class="stat-number">RWF <?php echo number_format($today_sales['total'] ?? 0, 0); ?></div>
                    <div class="stat-trend">
                        <?php 
                        $yesterday_query = mysqli_query($conn, "
                            SELECT
                                COALESCE((SELECT SUM(total_amount) FROM sales_bulk WHERE sale_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND has_loan = 0), 0) +
                                COALESCE((SELECT SUM(total_amount) FROM sales_retail WHERE sale_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND has_loan = 0), 0) as total
                        ");
                        $yesterday_total = mysqli_fetch_assoc($yesterday_query)['total'] ?? 0;
                        
                        $trend = ($today_sales['total'] ?? 0) - $yesterday_total;
                        $trend_percent = $yesterday_total > 0 ? ($trend / $yesterday_total) * 100 : 0;
                        ?>
                        <?php if ($trend > 0): ?>
                            <span class="trend-up">↑ <?php echo number_format($trend_percent, 1); ?>% vs yesterday</span>
                        <?php elseif ($trend < 0): ?>
                            <span class="trend-down">↓ <?php echo number_format(abs($trend_percent), 1); ?>% vs yesterday</span>
                        <?php else: ?>
                            <span>→ Same as yesterday</span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-footer">
                        Bulk: RWF <?php echo number_format($today_sales['bulk_total'] ?? 0, 0); ?> | 
                        Retail: RWF <?php echo number_format($today_sales['retail_total'] ?? 0, 0); ?>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">💵</div>
                    <div class="stat-label">Today's Revenue</div>
                    <div class="stat-number">RWF <?php echo number_format($today_profit, 0); ?></div>
                    <div class="stat-trend">
                        <?php
                        $today_sales_total = $today_sales['total'] ?? 0;
                        $today_margin = $today_sales_total > 0 ? ($today_profit / $today_sales_total) * 100 : 0;
                        ?>
                        <?php if ($today_profit > 0): ?>
                            <span class="trend-up">Margin: <?php echo number_format($today_margin, 1); ?>%</span>
                        <?php elseif ($today_profit < 0): ?>
                            <span class="trend-down">Loss today</span>
                        <?php else: ?>
                            <span>No profit yet</span>
                        <?php endif; ?>
                    </div>
                    <div class="stat-footer">
                        Sales: RWF <?php echo number_format($today_sales_total, 0); ?> |
                        Cost: RWF <?php echo number_format($today_sales_total - $today_profit, 0); ?>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-label">This Week</div>
                    <div class="stat-number">RWF <?php echo number_format($week_sales, 0); ?></div>
                    <div class="stat-trend">
                        <span>Sales revenue</span>
                    </div>
                    <div class="stat-footer">
                        Profit: RWF <?php echo number_format($week_profit, 0); ?> |
                        Margin: <span class="<?php echo $profit_margin >= 20 ? 'trend-up' : ($profit_margin >= 10 ? '' : 'trend-down'); ?>">
                            <?php echo number_format($profit_margin, 1); ?>%
                        </span>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">📈</div>
                    <div class="stat-label">This Month</div>
                    <div class="stat-number">RWF <?php echo number_format($month_sales, 0); ?></div>
                    <div class="stat-trend">
                        <span>Monthly target: RWF <?php echo number_format($month_sales * 1.2, 0); ?></span>
                    </div>
                    <div class="stat-footer">
                        <?php 
                        $days_in_month = date('t');
                        $current_day = date('j');
                        $progress = ($current_day / $days_in_month) * 100;
                        ?>
                        Month progress: <?php echo number_format($progress, 0); ?>%
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">🏪</div>
                    <div class="stat-label">Retail Shop</div>
                    <div class="stat-number"><?php echo number_format($total_retail_pieces); ?> pcs</div>
                    <div class="stat-trend">
                        <span>Pieces available for retail</span>
                    </div>
                    <div class="stat-footer">
                        Value: RWF <?php echo number_format($total_retail_value, 0); ?>
                    </div>
                </div>
            </div>
            
            <!-- Alerts Section -->
            <?php if (!empty($alerts)): ?>
            <div class="alerts-container">
                <h3 onclick="toggleAlerts()" style="cursor:pointer;user-select:none;">
                    <span style="display:flex;align-items:center;gap:10px;">
                        Notifications &amp; Alerts
                        <span class="badge-count"><?php echo count($alerts); ?> new</span>
                    </span>
                    <span id="alertsChevron" style="font-size:12px;color:var(--secondary);transition:transform .2s;">&#9654;</span>
                </h3>
                <div id="alertsBody" style="display:none;">
                    <?php foreach ($alerts as $alert): ?>
                    <div class="alert-item <?php echo $alert['type']; ?>">
                        <div class="alert-icon"><?php echo $alert['icon']; ?></div>
                        <div class="alert-content">
                            <div class="alert-title"><?php echo $alert['title']; ?></div>
                            <div class="alert-message"><?php echo $alert['message']; ?></div>
                            <a href="<?php echo $alert['link']; ?>" class="alert-link">Take action →</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <script>
            function toggleAlerts() {
                var body    = document.getElementById('alertsBody');
                var chevron = document.getElementById('alertsChevron');
                var open    = body.style.display !== 'none';
                body.style.display    = open ? 'none' : 'block';
                chevron.style.transform = open ? '' : 'rotate(90deg)';
            }
            </script>
            <?php endif; ?>
            
            <!-- Payment Collection Breakdown -->
            <?php
                $init_cash_pct = $today_pay_total > 0 ? round($today_cash / $today_pay_total * 100) : 0;
                $init_momo_pct = $today_pay_total > 0 ? round($today_momo / $today_pay_total * 100) : 0;
                $init_loan_pct = $today_pay_total > 0 ? 100 - $init_cash_pct - $init_momo_pct : 0;
            ?>
            <div style="margin-bottom:24px;">
                <div class="chart-container" style="padding:20px 24px;">

                    <!-- Header -->
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
                        <div>
                            <h3 style="margin:0 0 2px;">Collection Breakdown</h3>
                            <p id="coll-subtitle" style="font-size:12px;color:var(--secondary);margin:0;">Today</p>
                        </div>
                        <form id="coll-form" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <input type="date" id="coll-from" value="<?php echo $today; ?>"
                                style="padding:6px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;">
                            <span style="font-size:12px;color:var(--secondary);">to</span>
                            <input type="date" id="coll-to" value="<?php echo $today; ?>"
                                style="padding:6px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;">
                            <?php if ($_SESSION['role'] === 'admin'): ?>
                            <select id="coll-user" style="padding:6px 10px;border:1px solid var(--gray-300);border-radius:var(--radius);font-size:13px;background:var(--white);">
                                <option value="0">— All users —</option>
                                <?php while ($u = mysqli_fetch_assoc($users_for_filter)): ?>
                                <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['full_name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                            <?php else: ?>
                            <input type="hidden" id="coll-user" value="<?php echo (int)$_SESSION['user_id']; ?>">
                            <?php endif; ?>
                            <button type="button" onclick="fetchCollection()" style="padding:6px 14px;background:var(--primary);color:#fff;border:none;border-radius:var(--radius);font-size:13px;cursor:pointer;">Filter</button>
                            <button type="button" id="coll-today-btn" onclick="fetchCollection('<?php echo $today; ?>','<?php echo $today; ?>',0)"
                                style="display:none;padding:6px 10px;background:var(--gray-200);color:var(--dark);border:none;border-radius:var(--radius);font-size:13px;cursor:pointer;">Today</button>
                            <span id="coll-loader" style="display:none;font-size:12px;color:var(--secondary);">Loading…</span>
                        </form>
                    </div>

                    <!-- Has-data state -->
                    <div id="coll-data" style="display:<?php echo $today_pay_total > 0 ? 'block' : 'none'; ?>">
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px;">

                            <!-- Cash -->
                            <div style="background:#f0fdf4;border-radius:12px;padding:16px;border-left:4px solid #22c55e;">
                                <div style="display:flex;align-items:center;gap:7px;margin-bottom:10px;">
                                    <span style="font-size:18px;">💵</span>
                                    <span style="font-size:12px;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:.5px;">Cash</span>
                                </div>
                                <div id="coll-cash-amount" style="font-size:18px;font-weight:800;color:#111;margin-bottom:10px;">
                                    RWF <?php echo abbr_money($today_cash); ?>
                                </div>
                                <div style="background:#dcfce7;border-radius:99px;height:6px;margin-bottom:5px;">
                                    <div id="coll-cash-bar" style="background:#22c55e;height:6px;border-radius:99px;width:<?php echo $init_cash_pct; ?>%;transition:width .4s;"></div>
                                </div>
                                <div id="coll-cash-pct" style="font-size:11px;color:#15803d;font-weight:600;"><?php echo $init_cash_pct; ?>% of total</div>
                            </div>

                            <!-- Momo -->
                            <div style="background:#eff6ff;border-radius:12px;padding:16px;border-left:4px solid #3b82f6;">
                                <div style="display:flex;align-items:center;gap:7px;margin-bottom:10px;">
                                    <span style="font-size:18px;">📱</span>
                                    <span style="font-size:12px;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.5px;">Momo</span>
                                </div>
                                <div id="coll-momo-amount" style="font-size:18px;font-weight:800;color:#111;margin-bottom:10px;">
                                    RWF <?php echo abbr_money($today_momo); ?>
                                </div>
                                <div style="background:#dbeafe;border-radius:99px;height:6px;margin-bottom:5px;">
                                    <div id="coll-momo-bar" style="background:#3b82f6;height:6px;border-radius:99px;width:<?php echo $init_momo_pct; ?>%;transition:width .4s;"></div>
                                </div>
                                <div id="coll-momo-pct" style="font-size:11px;color:#1d4ed8;font-weight:600;"><?php echo $init_momo_pct; ?>% of total</div>
                            </div>

                            <!-- Loan -->
                            <div style="background:#fffbeb;border-radius:12px;padding:16px;border-left:4px solid #f59e0b;">
                                <div style="display:flex;align-items:center;gap:7px;margin-bottom:10px;">
                                    <span style="font-size:18px;">🔖</span>
                                    <span style="font-size:12px;font-weight:700;color:#b45309;text-transform:uppercase;letter-spacing:.5px;">Loan</span>
                                </div>
                                <div id="coll-loan-amount" style="font-size:18px;font-weight:800;color:#111;margin-bottom:10px;">
                                    RWF <?php echo abbr_money($today_loan); ?>
                                </div>
                                <div style="background:#fef3c7;border-radius:99px;height:6px;margin-bottom:5px;">
                                    <div id="coll-loan-bar" style="background:#f59e0b;height:6px;border-radius:99px;width:<?php echo $init_loan_pct; ?>%;transition:width .4s;"></div>
                                </div>
                                <div id="coll-loan-pct" style="font-size:11px;color:#b45309;font-weight:600;"><?php echo $init_loan_pct; ?>% deferred</div>
                            </div>
                        </div>

                        <!-- Outstanding loans row -->
                        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#fafafa;border-radius:10px;border:1px solid #f3f4f6;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="font-size:16px;">⚠️</span>
                                <div>
                                    <div style="font-size:13px;font-weight:600;color:#374151;">Total Outstanding Loans</div>
                                    <div style="font-size:11px;color:var(--secondary);">All unpaid client balances</div>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <div id="coll-outstanding-amount" style="font-size:18px;font-weight:800;color:#f59e0b;">RWF <?php echo abbr_money($outstanding_loans); ?></div>
                                <a href="loans.php" style="font-size:11px;color:var(--primary);text-decoration:none;">View details →</a>
                            </div>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div id="coll-empty" style="text-align:center;padding:32px 0;color:var(--secondary);display:<?php echo $today_pay_total > 0 ? 'none' : 'block'; ?>">
                        <div style="font-size:32px;margin-bottom:8px;">💳</div>
                        <div style="font-size:13px;">No sales recorded for this period</div>
                        <div id="coll-empty-loans" style="display:<?php echo $outstanding_loans > 0 ? 'inline-flex' : 'none'; ?>;margin-top:16px;padding:12px 16px;background:#fffbeb;border-radius:10px;border:1px solid #fde68a;gap:12px;align-items:center;">
                            <span style="font-size:13px;color:#92400e;">⚠️ Outstanding Loans:</span>
                            <strong id="coll-empty-outstanding" style="color:#f59e0b;">RWF <?php echo abbr_money($outstanding_loans); ?></strong>
                            <a href="loans.php" style="font-size:11px;color:var(--primary);">View →</a>
                        </div>
                    </div>

                </div>
            </div>

            <script>
            (function () {
                var collToday = '<?php echo $today; ?>';

                function fmt(n) {
                    n = Math.round(n);
                    var abs = Math.abs(n), sign = n < 0 ? '-' : '';
                    if (abs >= 1000000) return sign + parseFloat((abs/1000000).toFixed(1)) + 'M';
                    if (abs >= 1000)    return sign + parseFloat((abs/1000).toFixed(1)) + 'K';
                    return sign + abs.toLocaleString();
                }

                function dateLabel(from, to) {
                    if (from === to) {
                        if (from === collToday) return 'Today';
                        return new Date(from + 'T12:00:00').toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
                    }
                    var f = new Date(from + 'T12:00:00').toLocaleDateString('en-US', {month:'short', day:'numeric'});
                    var t = new Date(to   + 'T12:00:00').toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
                    return f + ' – ' + t;
                }

                function render(d) {
                    var total  = d.cash + d.momo + d.loan;
                    var isToday = d.from === collToday && d.to === collToday;

                    document.getElementById('coll-subtitle').textContent = dateLabel(d.from, d.to);
                    document.getElementById('coll-today-btn').style.display = isToday ? 'none' : '';
                    document.getElementById('coll-outstanding-amount').textContent = 'RWF ' + fmt(d.outstanding);
                    document.getElementById('coll-empty-outstanding').textContent  = 'RWF ' + fmt(d.outstanding);
                    document.getElementById('coll-empty-loans').style.display = d.outstanding > 0 ? 'inline-flex' : 'none';

                    if (total > 0) {
                        var cPct = Math.round(d.cash / total * 100);
                        var mPct = Math.round(d.momo / total * 100);
                        var lPct = 100 - cPct - mPct;

                        document.getElementById('coll-cash-amount').textContent = 'RWF ' + fmt(d.cash);
                        document.getElementById('coll-cash-bar').style.width    = cPct + '%';
                        document.getElementById('coll-cash-pct').textContent    = cPct + '% of total';

                        document.getElementById('coll-momo-amount').textContent = 'RWF ' + fmt(d.momo);
                        document.getElementById('coll-momo-bar').style.width    = mPct + '%';
                        document.getElementById('coll-momo-pct').textContent    = mPct + '% of total';

                        document.getElementById('coll-loan-amount').textContent = 'RWF ' + fmt(d.loan);
                        document.getElementById('coll-loan-bar').style.width    = lPct + '%';
                        document.getElementById('coll-loan-pct').textContent    = lPct + '% deferred';

                        document.getElementById('coll-data').style.display  = 'block';
                        document.getElementById('coll-empty').style.display = 'none';
                    } else {
                        document.getElementById('coll-data').style.display  = 'none';
                        document.getElementById('coll-empty').style.display = 'block';
                    }
                }

                window.fetchCollection = function (from, to, userId) {
                    from   = from   !== undefined ? from   : document.getElementById('coll-from').value;
                    to     = to     !== undefined ? to     : document.getElementById('coll-to').value;
                    userId = userId !== undefined ? userId : document.getElementById('coll-user').value;
                    document.getElementById('coll-from').value = from;
                    document.getElementById('coll-to').value   = to;
                    document.getElementById('coll-user').value = userId;
                    document.getElementById('coll-loader').style.display = 'inline';

                    fetch('ajax_collection.php?coll_from=' + from + '&coll_to=' + to + '&user_id=' + userId)
                        .then(function (r) { return r.json(); })
                        .then(function (d) { render(d); })
                        .catch(function () {})
                        .finally(function () {
                            document.getElementById('coll-loader').style.display = 'none';
                        });
                };
            })();
            </script>

            <!-- Main Dashboard Row -->
            <div class="dashboard-row">
                <!-- Sales Chart -->
                <div class="chart-container">
                    <h3>
                        Sales Trend (Last 7 Days)
                        <small>Bulk vs Retail</small>
                    </h3>
                    <div class="chart-wrapper" style="height: 300px;">
                        <canvas id="salesChart"></canvas>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div>
                    <div class="chart-container" style="margin-bottom: 20px;">
                        <h3>Quick Actions</h3>
                        <div class="quick-actions">
                            <a href="sales.php" class="quick-action-btn"><span>💰</span>New Sale</a>
                            <a href="purchases.php" class="quick-action-btn"><span>📦</span>Purchase</a>
                            <a href="stock.php" class="quick-action-btn"><span>🔄</span>Move Stock</a>
                            <a href="products.php" class="quick-action-btn"><span>🏷️</span>Add Product</a>
                        </div>
                    </div>
                    
                    <!-- Stock Health -->
                    <div class="chart-container">
                        <h3>Stock Health</h3>
                        <?php if ($low_stock_count > 0): ?>
                            <?php while($item = mysqli_fetch_assoc($low_stock_query)): ?>
                            <div class="low-stock-item">
                                <div>
                                    <div class="low-stock-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                    <div class="low-stock-sub">Reorder at <?php echo $item['reorder_level']; ?></div>
                                </div>
                                <span class="low-stock-badge"><?php echo $item['quantity']; ?> left</span>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="text-align:center;padding:24px 0;color:var(--success);">
                                <div style="font-size:36px;">✓</div>
                                <div style="font-size:13px;color:var(--secondary);margin-top:6px;">All products well stocked</div>
                            </div>
                        <?php endif; ?>
                        <div class="mini-stats">
                            <div class="mini-stat">
                                <div class="mini-stat-value"><?php echo number_format($movements['total_movements'] ?? 0); ?></div>
                                <div class="mini-stat-label">Movements this week</div>
                            </div>
                            <div class="mini-stat">
                                <div class="mini-stat-value" style="color:var(--success);"><?php echo number_format($total_suppliers); ?></div>
                                <div class="mini-stat-label">Active Suppliers</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Top Products -->
            <div style="margin-bottom:24px;">
                <div class="chart-container">
                    <h3>Top Selling Products</h3>
                    <div style="overflow-x:auto;">
                        <table class="top-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th style="text-align:center;">Bulk</th>
                                    <th style="text-align:center;">Retail</th>
                                    <th style="text-align:right;">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($top_products_query) > 0): ?>
                                    <?php while($product = mysqli_fetch_assoc($top_products_query)): ?>
                                    <tr>
                                        <td style="font-weight:600;"><?php echo htmlspecialchars($product['name']); ?></td>
                                        <td><span class="cat-badge"><?php echo htmlspecialchars($product['category'] ?: 'N/A'); ?></span></td>
                                        <td style="text-align:center;color:var(--secondary);"><?php echo $product['bulk_quantity']; ?> pkg</td>
                                        <td style="text-align:center;color:var(--secondary);"><?php echo $product['retail_quantity']; ?> pcs</td>
                                        <td style="text-align:right;font-weight:700;color:var(--success);">RWF <?php echo number_format($product['total_revenue'], 0); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" style="padding:30px;text-align:center;color:var(--secondary);">No sales data yet</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
            </div>
            
            <!-- Business Performance Summary -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:8px;">
                <div class="perf-box">
                    <h4>Performance Summary</h4>
                    <div class="perf-grid">
                        <div class="perf-item">
                            <div class="perf-item-label">Avg. Daily Sales</div>
                            <div class="perf-item-value">RWF <?php
                                $avg_daily = mysqli_fetch_assoc(mysqli_query($conn, "
                                    SELECT AVG(daily_total) as avg_sales FROM (
                                        SELECT sale_date, SUM(total_amount) as daily_total FROM (
                                            SELECT sale_date, total_amount FROM sales_bulk WHERE has_loan = 0
                                            UNION ALL SELECT sale_date, total_amount FROM sales_retail WHERE has_loan = 0
                                        ) as s GROUP BY sale_date ORDER BY sale_date DESC LIMIT 30
                                    ) as t"));
                                echo number_format($avg_daily['avg_sales'] ?? 0, 0); ?></div>
                        </div>
                        <div class="perf-item">
                            <div class="perf-item-label">Best Day</div>
                            <div class="perf-item-value"><?php
                                $best_day = mysqli_fetch_assoc(mysqli_query($conn, "
                                    SELECT DAYNAME(sale_date) as day_name, COUNT(*) as c FROM (
                                        SELECT sale_date FROM sales_bulk UNION ALL SELECT sale_date FROM sales_retail
                                    ) as s GROUP BY DAYOFWEEK(sale_date) ORDER BY c DESC LIMIT 1"));
                                echo $best_day['day_name'] ?? 'N/A'; ?></div>
                        </div>
                        <div class="perf-item">
                            <div class="perf-item-label">Total Transactions</div>
                            <div class="perf-item-value"><?php
                                $total_trans = mysqli_fetch_assoc(mysqli_query($conn, "SELECT (SELECT COUNT(*) FROM sales_bulk)+(SELECT COUNT(*) FROM sales_retail) as total"));
                                echo number_format($total_trans['total'] ?? 0); ?></div>
                        </div>
                        <div class="perf-item">
                            <div class="perf-item-label">Stock Turnover</div>
                            <div class="perf-item-value"><?php
                                $turnover = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(sb.quantity),0)+COALESCE(SUM(sr.pieces_sold),0) as sold, COALESCE((SELECT SUM(quantity) FROM stock),1) as stk FROM products p LEFT JOIN sales_bulk sb ON p.id=sb.product_id LEFT JOIN sales_retail sr ON p.id=sr.product_id"));
                                echo number_format($turnover['stk'] > 0 ? ($turnover['sold']/$turnover['stk'])*100 : 0, 0) . '%'; ?></div>
                        </div>
                    </div>
                </div>

                <div class="perf-box">
                    <h4>Quick Insights</h4>
                    <?php
                    $total_bulk_all   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount),0) as t FROM sales_bulk WHERE has_loan = 0"))['t'] ?? 0;
                    $total_retail_all = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount),0) as t FROM sales_retail WHERE has_loan = 0"))['t'] ?? 0;
                    $total_all = $total_bulk_all + $total_retail_all;
                    $avg_trans = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(amount) as a FROM (SELECT total_amount as amount FROM sales_bulk WHERE has_loan = 0 UNION ALL SELECT total_amount FROM sales_retail WHERE has_loan = 0) as s"));
                    $peak_hour = mysqli_fetch_assoc(mysqli_query($conn, "SELECT HOUR(created_at) as h, COUNT(*) as c FROM (SELECT created_at FROM sales_bulk UNION ALL SELECT created_at FROM sales_retail) as s GROUP BY h ORDER BY c DESC LIMIT 1"));
                    $avg_items = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(items) as a FROM (SELECT quantity as items FROM sales_bulk UNION ALL SELECT pieces_sold FROM sales_retail) as s"));
                    ?>
                    <div class="insight-row">
                        <span class="insight-label">Bulk vs Retail</span>
                        <span class="insight-value"><?php echo $total_all > 0 ? number_format(($total_bulk_all/$total_all)*100,0).'% / '.number_format(($total_retail_all/$total_all)*100,0).'%' : 'N/A'; ?></span>
                    </div>
                    <div class="insight-row">
                        <span class="insight-label">Avg. Transaction</span>
                        <span class="insight-value">RWF <?php echo number_format($avg_trans['a'] ?? 0, 0); ?></span>
                    </div>
                    <div class="insight-row">
                        <span class="insight-label">Peak Hour</span>
                        <span class="insight-value"><?php echo $peak_hour ? date('g A', strtotime($peak_hour['h'].':00')) : 'N/A'; ?></span>
                    </div>
                    <div class="insight-row">
                        <span class="insight-label">Items per Sale</span>
                        <span class="insight-value"><?php echo number_format($avg_items['a'] ?? 0, 1); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
  <script src="chart.js"></script>
<script>
    (function() {
        'use strict';
        
        // Wait for DOM to be ready
        function initChart() {
            const canvas = document.getElementById('salesChart');
            if (!canvas) return;
            
            // Remove any existing chart instance
            if (canvas.__chartjs_instance) {
                canvas.__chartjs_instance.destroy();
                delete canvas.__chartjs_instance;
            }
            
            // Clear canvas
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            // Prepare data
            const dates = [];
            const bulkSales = [];
            const retailSales = [];
            
            <?php 
            if ($last_7_days && mysqli_num_rows($last_7_days) > 0) {
                mysqli_data_seek($last_7_days, 0);
                while($row = mysqli_fetch_assoc($last_7_days)) {
                    echo "dates.push('" . date('D', strtotime($row['date'])) . "');\n";
                    echo "bulkSales.push(" . ($row['bulk_sales'] ?? 0) . ");\n";
                    echo "retailSales.push(" . ($row['retail_sales'] ?? 0) . ");\n";
                }
            }
            ?>
            
            // Create new chart
            try {
                const chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: dates,
                        datasets: [
                            {
                                label: 'Bulk Sales',
                                data: bulkSales,
                                borderColor: 'rgba(102, 126, 234, 1)',
                                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                borderWidth: 2,
                                tension: 0.4,
                                pointRadius: 4,
                                pointBackgroundColor: 'rgba(102, 126, 234, 1)',
                            },
                            {
                                label: 'Retail Sales',
                                data: retailSales,
                                borderColor: 'rgba(245, 87, 108, 1)',
                                backgroundColor: 'rgba(245, 87, 108, 0.1)',
                                borderWidth: 2,
                                tension: 0.4,
                                pointRadius: 4,
                                pointBackgroundColor: 'rgba(245, 87, 108, 1)',
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: {
                            duration: 0 // Disable animation to prevent flashing
                        },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top',
                            }
                        }
                    }
                });
                
                // Store chart instance on canvas
                canvas.__chartjs_instance = chart;
                
            } catch (e) {
                console.error('Chart initialization error:', e);
            }
        }
        
        // Run initialization when DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initChart);
        } else {
            initChart();
        }
        
        // Prevent multiple initializations
        window.removeEventListener('load', initChart);
        window.addEventListener('load', initChart);
        
    })();
</script>
    
    <script src="script.js"></script>
</body>
</html>