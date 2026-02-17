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

// Recent activities (last 10 transactions)
$recent_activities = mysqli_query($conn, "
    (SELECT 
        'Bulk Sale' as type,
        sb.sale_date as date,
        p.name as product_name,
        CONCAT(sb.quantity, ' packages') as quantity,
        sb.total_amount as amount,
        sb.customer_name as customer
    FROM sales_bulk sb
    JOIN products p ON sb.product_id = p.id
    ORDER BY sb.created_at DESC LIMIT 5)
    
    UNION ALL
    
    (SELECT 
        'Retail Sale' as type,
        sr.sale_date as date,
        p.name as product_name,
        CONCAT(sr.pieces_sold, ' pieces') as quantity,
        sr.total_amount as amount,
        sr.customer_name as customer
    FROM sales_retail sr
    JOIN products p ON sr.product_id = p.id
    ORDER BY sr.created_at DESC LIMIT 5)
    
    UNION ALL
    
    (SELECT 
        'Purchase' as type,
        p.purchase_date as date,
        pr.name as product_name,
        CONCAT(p.quantity, ' packages') as quantity,
        p.cost_price * p.quantity as amount,
        s.name as customer
    FROM purchases p
    JOIN products pr ON p.product_id = pr.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    ORDER BY p.created_at DESC LIMIT 3)
    
    ORDER BY date DESC
    LIMIT 10
");

// Top customers
$top_customers = mysqli_query($conn, "
    SELECT 
        customer_name,
        COUNT(*) as total_transactions,
        SUM(total_amount) as total_spent,
        MAX(sale_date) as last_purchase
    FROM (
        SELECT customer_name, total_amount, sale_date FROM sales_bulk WHERE customer_name IS NOT NULL AND customer_name != ''
        UNION ALL
        SELECT customer_name, total_amount, sale_date FROM sales_retail WHERE customer_name IS NOT NULL AND customer_name != ''
    ) as all_sales
    GROUP BY customer_name
    ORDER BY total_spent DESC
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
            <div class="alerts-container" style="margin-bottom: 30px;">
                <h3 style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <span>🔔 Notifications & Alerts</span>
                    <span style="background: #667eea; color: white; padding: 2px 10px; border-radius: 20px; font-size: 12px;">
                        <?php echo count($alerts); ?> new
                    </span>
                </h3>
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
                        <h3 style="margin-bottom: 20px;">⚡ Quick Actions</h3>
                        <div class="quick-actions">
                            <a href="sales.php" class="quick-action-btn">
                                <span>💰</span>
                                New Sale
                            </a>
                            <a href="purchases.php" class="quick-action-btn" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                                <span>📦</span>
                                New Purchase
                            </a>
                            <a href="stock.php" class="quick-action-btn" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                                <span>🔄</span>
                                Move Stock
                            </a>
                            <a href="products.php" class="quick-action-btn" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                                <span>🏷️</span>
                                Add Product
                            </a>
                        </div>
                    </div>
                    
                    <!-- Stock Health -->
                    <div class="chart-container">
                        <h3 style="margin-bottom: 20px;">📦 Stock Health</h3>
                        <?php if ($low_stock_count > 0): ?>
                            <div style="margin-bottom: 20px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                    <span style="color: #6c757d;">Low Stock Items</span>
                                    <span style="color: #dc3545; font-weight: bold;"><?php echo $low_stock_count; ?> products</span>
                                </div>
                                <div style="background: #f8f9fa; border-radius: 10px; padding: 15px;">
                                    <?php while($item = mysqli_fetch_assoc($low_stock_query)): ?>
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #dee2e6;">
                                        <div>
                                            <div style="font-weight: bold;"><?php echo htmlspecialchars($item['name']); ?></div>
                                            <div style="font-size: 12px; color: #6c757d;">Reorder at: <?php echo $item['reorder_level']; ?></div>
                                        </div>
                                        <div>
                                            <span style="background: #dc3545; color: white; padding: 5px 10px; border-radius: 20px; font-size: 12px;">
                                                <?php echo $item['quantity']; ?> left
                                            </span>
                                        </div>
                                    </div>
                                    <?php endwhile; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 30px;">
                                <span style="font-size: 50px; color: #28a745;">✓</span>
                                <p style="color: #6c757d; margin-top: 10px;">All products are well stocked!</p>
                            </div>
                        <?php endif; ?>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 20px;">
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 10px; text-align: center;">
                                <div style="font-size: 24px; font-weight: bold; color: #667eea;"><?php echo number_format($movements['total_movements'] ?? 0); ?></div>
                                <div style="font-size: 12px; color: #6c757d;">Stock Movements</div>
                            </div>
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 10px; text-align: center;">
                                <div style="font-size: 24px; font-weight: bold; color: #28a745;"><?php echo number_format($total_suppliers); ?></div>
                                <div style="font-size: 12px; color: #6c757d;">Active Suppliers</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Second Dashboard Row -->
            <div class="dashboard-row">
                <!-- Top Products -->
                <div class="chart-container">
                    <h3 style="margin-bottom: 20px;">🏆 Top Selling Products</h3>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="border-bottom: 2px solid #eee;">
                                    <th style="text-align: left; padding: 12px 5px;">Product</th>
                                    <th style="text-align: center; padding: 12px 5px;">Category</th>
                                    <th style="text-align: center; padding: 12px 5px;">Bulk</th>
                                    <th style="text-align: center; padding: 12px 5px;">Retail</th>
                                    <th style="text-align: right; padding: 12px 5px;">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($top_products_query) > 0): ?>
                                    <?php while($product = mysqli_fetch_assoc($top_products_query)): ?>
                                    <tr style="border-bottom: 1px solid #eee;">
                                        <td style="padding: 12px 5px; font-weight: bold;"><?php echo htmlspecialchars($product['name']); ?></td>
                                        <td style="padding: 12px 5px; text-align: center;">
                                            <span style="background: #e9ecef; padding: 3px 10px; border-radius: 20px; font-size: 12px;">
                                                <?php echo htmlspecialchars($product['category'] ?: 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td style="padding: 12px 5px; text-align: center;"><?php echo $product['bulk_quantity']; ?> pkg</td>
                                        <td style="padding: 12px 5px; text-align: center;"><?php echo $product['retail_quantity']; ?> pcs</td>
                                        <td style="padding: 12px 5px; text-align: right; font-weight: bold; color: #28a745;">
                                            RWF <?php echo number_format($product['total_revenue'], 0); ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="padding: 30px; text-align: center; color: #6c757d;">
                                            No sales data available yet
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Top Customers -->
                <div class="chart-container">
                    <h3 style="margin-bottom: 20px;">👥 Top Customers</h3>
                    <div style="overflow-x: auto;">
                        <?php if (mysqli_num_rows($top_customers) > 0): ?>
                            <?php while($customer = mysqli_fetch_assoc($top_customers)): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 5px; border-bottom: 1px solid #eee;">
                                <div>
                                    <div style="font-weight: bold;"><?php echo htmlspecialchars($customer['customer_name']); ?></div>
                                    <div style="font-size: 11px; color: #6c757d;">
                                        <?php echo $customer['total_transactions']; ?> transactions
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-weight: bold; color: #667eea;">
                                        RWF <?php echo number_format($customer['total_spent'], 0); ?>
                                    </div>
                                    <div style="font-size: 11px; color: #6c757d;">
                                        Last: <?php echo date('M d', strtotime($customer['last_purchase'])); ?>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 30px; color: #6c757d;">
                                No customer data available
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activities -->
            <div class="recent-activities">
                <h3 style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                    <span>📋 Recent Activities</span>
                    <span style="background: #f8f9fa; padding: 5px 15px; border-radius: 20px; font-size: 13px; color: #6c757d;">
                        Last 10 transactions
                    </span>
                </h3>
                <div class="activity-timeline">
                    <?php if (mysqli_num_rows($recent_activities) > 0): ?>
                        <?php while($activity = mysqli_fetch_assoc($recent_activities)): 
                            $badge_class = '';
                            $badge_icon = '';
                            if ($activity['type'] == 'Bulk Sale') {
                                $badge_class = 'badge-bulk';
                                $badge_icon = '📦';
                            } elseif ($activity['type'] == 'Retail Sale') {
                                $badge_class = 'badge-retail';
                                $badge_icon = '🛍️';
                            } else {
                                $badge_class = 'badge-purchase';
                                $badge_icon = '📥';
                            }
                        ?>
                        <div class="activity-item">
                            <div class="activity-badge <?php echo $badge_class; ?>">
                                <?php echo $badge_icon; ?>
                            </div>
                            <div class="activity-details">
                                <div class="activity-title">
                                    <?php echo $activity['type']; ?> - <?php echo htmlspecialchars($activity['product_name']); ?>
                                </div>
                                <div class="activity-meta">
                                    <span>📅 <?php echo date('M d, H:i', strtotime($activity['date'])); ?></span>
                                    <span>📊 <?php echo $activity['quantity']; ?></span>
                                    <?php if (!empty($activity['customer']) && $activity['customer'] != 'N/A'): ?>
                                        <span>👤 <?php echo htmlspecialchars($activity['customer']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="activity-amount">
                                RWF <?php echo number_format($activity['amount'], 0); ?>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: #6c757d;">
                            <span style="font-size: 50px; display: block; margin-bottom: 20px;">📭</span>
                            No recent activities found
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Business Performance Summary -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 30px;">
                <div style="background: white; border-radius: 15px; padding: 20px; box-shadow: 0 5px 20px rgba(0,0,0,0.05);">
                    <h4 style="margin-bottom: 15px; color: #333;">📈 Performance Summary</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div>
                            <div style="font-size: 12px; color: #6c757d;">Avg. Daily Sales</div>
                            <div style="font-size: 20px; font-weight: bold; color: #333;">
                                RWF <?php 
                                $avg_daily = mysqli_fetch_assoc(mysqli_query($conn, "
                                    SELECT AVG(daily_total) as avg_sales
                                    FROM (
                                        SELECT sale_date, SUM(total_amount) as daily_total
                                        FROM (
                                            SELECT sale_date, total_amount FROM sales_bulk
                                            UNION ALL
                                            SELECT sale_date, total_amount FROM sales_retail
                                        ) as all_sales
                                        GROUP BY sale_date
                                        ORDER BY sale_date DESC
                                        LIMIT 30
                                    ) as last_30_days
                                "));
                                echo number_format($avg_daily['avg_sales'] ?? 0, 0);
                                ?>
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 12px; color: #6c757d;">Best Selling Day</div>
                            <div style="font-size: 20px; font-weight: bold; color: #333;">
                                <?php 
                                $best_day = mysqli_fetch_assoc(mysqli_query($conn, "
                                    SELECT DAYNAME(sale_date) as day_name, COUNT(*) as sales_count
                                    FROM (
                                        SELECT sale_date FROM sales_bulk
                                        UNION ALL
                                        SELECT sale_date FROM sales_retail
                                    ) as all_sales
                                    GROUP BY DAYOFWEEK(sale_date)
                                    ORDER BY sales_count DESC
                                    LIMIT 1
                                "));
                                echo $best_day['day_name'] ?? 'N/A';
                                ?>
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 12px; color: #6c757d;">Total Transactions</div>
                            <div style="font-size: 20px; font-weight: bold; color: #333;">
                                <?php 
                                $total_trans = mysqli_fetch_assoc(mysqli_query($conn, "
                                    SELECT 
                                        (SELECT COUNT(*) FROM sales_bulk) +
                                        (SELECT COUNT(*) FROM sales_retail) as total
                                "));
                                echo number_format($total_trans['total'] ?? 0);
                                ?>
                            </div>
                        </div>
                        <div>
                            <div style="font-size: 12px; color: #6c757d;">Stock Turnover</div>
                            <div style="font-size: 20px; font-weight: bold; color: #333;">
                                <?php 
                                $turnover = mysqli_fetch_assoc(mysqli_query($conn, "
                                    SELECT 
                                        COALESCE(SUM(sb.quantity), 0) + COALESCE(SUM(sr.pieces_sold), 0) as total_sold,
                                        COALESCE((SELECT SUM(quantity) FROM stock), 1) as total_stock
                                    FROM products p
                                    LEFT JOIN sales_bulk sb ON p.id = sb.product_id
                                    LEFT JOIN sales_retail sr ON p.id = sr.product_id
                                "));
                                $turnover_rate = $turnover['total_stock'] > 0 ? 
                                    ($turnover['total_sold'] / $turnover['total_stock']) * 100 : 0;
                                echo number_format($turnover_rate, 0) . '%';
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div style="background: white; border-radius: 15px; padding: 20px; box-shadow: 0 5px 20px rgba(0,0,0,0.05);">
                    <h4 style="margin-bottom: 15px; color: #333;">🎯 Quick Insights</h4>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #6c757d;">Bulk vs Retail Ratio</span>
                            <span style="font-weight: bold;">
                                <?php 
                                $total_bulk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(total_amount) as total FROM sales_bulk"))['total'] ?? 0;
                                $total_retail = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(total_amount) as total FROM sales_retail"))['total'] ?? 0;
                                $total_all = $total_bulk + $total_retail;
                                if ($total_all > 0) {
                                    echo number_format(($total_bulk / $total_all) * 100, 0) . '% Bulk / ';
                                    echo number_format(($total_retail / $total_all) * 100, 0) . '% Retail';
                                } else {
                                    echo 'No data';
                                }
                                ?>
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #6c757d;">Average Transaction Value</span>
                            <span style="font-weight: bold;">
                                RWF <?php 
                                $avg_trans = mysqli_fetch_assoc(mysqli_query($conn, "
                                    SELECT AVG(amount) as avg_amount
                                    FROM (
                                        SELECT total_amount as amount FROM sales_bulk
                                        UNION ALL
                                        SELECT total_amount as amount FROM sales_retail
                                    ) as all_sales
                                "));
                                echo number_format($avg_trans['avg_amount'] ?? 0, 0);
                                ?>
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #6c757d;">Most Active Hour</span>
                            <span style="font-weight: bold;">
                                <?php 
                                $peak_hour = mysqli_fetch_assoc(mysqli_query($conn, "
                                    SELECT HOUR(created_at) as hour, COUNT(*) as count
                                    FROM (
                                        SELECT created_at FROM sales_bulk
                                        UNION ALL
                                        SELECT created_at FROM sales_retail
                                    ) as all_sales
                                    GROUP BY HOUR(created_at)
                                    ORDER BY count DESC
                                    LIMIT 1
                                "));
                                echo $peak_hour ? date('g A', strtotime($peak_hour['hour'] . ':00')) : 'N/A';
                                ?>
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #6c757d;">Products per Sale</span>
                            <span style="font-weight: bold;">
                                <?php 
                                $avg_items = mysqli_fetch_assoc(mysqli_query($conn, "
                                    SELECT AVG(items) as avg_items
                                    FROM (
                                        SELECT quantity as items FROM sales_bulk
                                        UNION ALL
                                        SELECT pieces_sold as items FROM sales_retail
                                    ) as all_items
                                "));
                                echo number_format($avg_items['avg_items'] ?? 0, 1);
                                ?>
                            </span>
                        </div>
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