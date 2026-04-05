<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
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

// Low stock products (below reorder level)
$low_stock_query = mysqli_query($conn, "
    SELECT p.name, s.quantity, p.reorder_level 
    FROM stock s
    JOIN products p ON s.product_id = p.id
    WHERE s.quantity <= p.reorder_level
    ORDER BY s.quantity ASC
    LIMIT 5
");

// ============== SALES STATISTICS ==============
// Today's sales
$today_sales_query = mysqli_query($conn, "
    SELECT 
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_bulk WHERE sale_date = '$today'), 0) +
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_retail WHERE sale_date = '$today'), 0) as total,
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_bulk WHERE sale_date = '$today'), 0) as bulk_total,
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_retail WHERE sale_date = '$today'), 0) as retail_total
");
$today_sales = mysqli_fetch_assoc($today_sales_query);

// This week's sales
$week_sales_query = mysqli_query($conn, "
    SELECT 
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_bulk WHERE sale_date BETWEEN '$week_start' AND '$week_end'), 0) +
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_retail WHERE sale_date BETWEEN '$week_start' AND '$week_end'), 0) as total
");
$week_sales = mysqli_fetch_assoc($week_sales_query)['total'] ?? 0;

// This month's sales
$month_sales_query = mysqli_query($conn, "
    SELECT 
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_bulk WHERE sale_date BETWEEN '$month_start' AND '$month_end'), 0) +
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_retail WHERE sale_date BETWEEN '$month_start' AND '$month_end'), 0) as total
");
$month_sales = mysqli_fetch_assoc($month_sales_query)['total'] ?? 0;

// ============== PROFIT STATISTICS ==============
// Today's profit (with cost calculation)
$today_profit_query = mysqli_query($conn, "
    SELECT 
        -- Bulk sales profit
        COALESCE((
            SELECT SUM(sb.total_amount - (pu.cost_price * sb.quantity))
            FROM sales_bulk sb
            JOIN purchases pu ON pu.product_id = sb.product_id
            WHERE sb.sale_date = '$today'
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
        COALESCE(SUM(sb.total_amount), 0) as bulk_sales,
        COALESCE(SUM(sr.total_amount), 0) as retail_sales
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
                    <div class="stat-icon">💰</div>
                    <div class="stat-label">Stock Value</div>
                    <div class="stat-number">RWF <?php echo number_format($total_stock_value, 0); ?></div>
                    <div class="stat-trend">
                        <span class="stock-status">
                            <span class="stock-dot <?php 
                                if ($total_stock_value > 1000000) echo 'green';
                                elseif ($total_stock_value > 500000) echo 'yellow';
                                else echo 'red';
                            ?>"></span>
                            Warehouse value
                        </span>
                    </div>
                    <div class="stat-footer">
                        Retail: RWF <?php echo number_format($total_retail_value, 0); ?>
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
                                COALESCE(SUM(total_amount), 0) as total 
                            FROM sales_bulk 
                            WHERE sale_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                        ");
                        $yesterday_bulk = mysqli_fetch_assoc($yesterday_query)['total'] ?? 0;
                        
                        $yesterday_query = mysqli_query($conn, "
                            SELECT 
                                COALESCE(SUM(total_amount), 0) as total 
                            FROM sales_retail 
                            WHERE sale_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                        ");
                        $yesterday_retail = mysqli_fetch_assoc($yesterday_query)['total'] ?? 0;
                        $yesterday_total = $yesterday_bulk + $yesterday_retail;
                        
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
                                            SELECT sale_date, total_amount FROM sales_bulk
                                            UNION ALL SELECT sale_date, total_amount FROM sales_retail
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
                    $total_bulk_all   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount),0) as t FROM sales_bulk"))['t'] ?? 0;
                    $total_retail_all = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount),0) as t FROM sales_retail"))['t'] ?? 0;
                    $total_all = $total_bulk_all + $total_retail_all;
                    $avg_trans = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(amount) as a FROM (SELECT total_amount as amount FROM sales_bulk UNION ALL SELECT total_amount FROM sales_retail) as s"));
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