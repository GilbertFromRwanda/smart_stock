<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// First, check if the weekly_revenue table has the profit columns
$check_columns = mysqli_query($conn, "SHOW COLUMNS FROM weekly_revenue LIKE 'total_cost'");
if (mysqli_num_rows($check_columns) == 0) {
    // Add missing columns
    mysqli_query($conn, "ALTER TABLE weekly_revenue 
        ADD COLUMN total_cost DECIMAL(10,2) DEFAULT 0 AFTER total_revenue,
        ADD COLUMN total_profit DECIMAL(10,2) DEFAULT 0 AFTER total_cost,
        ADD COLUMN profit_margin DECIMAL(5,2) DEFAULT 0 AFTER total_profit");
}

// Calculate and store weekly revenue with profit analysis
function updateWeeklyRevenue($conn) {
    // Get current week dates
    $week_start = date('Y-m-d', strtotime('monday this week'));
    $week_end = date('Y-m-d', strtotime('sunday this week'));
    
    // Calculate bulk sales with cost analysis
    $bulk_query = mysqli_query($conn, "
        SELECT 
            sb.*,
            p.name as product_name,
            (SELECT cost_price FROM purchases 
             WHERE product_id = sb.product_id 
             ORDER BY purchase_date DESC LIMIT 1) as last_cost_price,
            (SELECT package_price FROM stock WHERE product_id = sb.product_id) as default_price
        FROM sales_bulk sb
        JOIN products p ON sb.product_id = p.id
        WHERE sb.sale_date BETWEEN '$week_start' AND '$week_end'
    ");
    
    $bulk_total = 0;
    $bulk_cost_total = 0;
    $bulk_profit = 0;
    
    while ($sale = mysqli_fetch_assoc($bulk_query)) {
        $bulk_total += $sale['total_amount'];
        $cost = ($sale['last_cost_price'] ?? 0) * $sale['quantity'];
        $bulk_cost_total += $cost;
        $bulk_profit += ($sale['total_amount'] - $cost);
    }
    
    // Calculate retail sales with cost analysis
    $retail_query = mysqli_query($conn, "
        SELECT 
            sr.*,
            p.name as product_name,
            (SELECT cost_price FROM purchases 
             WHERE product_id = sr.product_id 
             ORDER BY purchase_date DESC LIMIT 1) as last_cost_price,
            COALESCE(s.pieces_per_package, 1) as pieces_per_package,
            s.retail_price as default_price
        FROM sales_retail sr
        JOIN products p ON sr.product_id = p.id
        LEFT JOIN stock s ON sr.product_id = s.product_id
        WHERE sr.sale_date BETWEEN '$week_start' AND '$week_end'
    ");
    
    $retail_total = 0;
    $retail_cost_total = 0;
    $retail_profit = 0;
    
    while ($sale = mysqli_fetch_assoc($retail_query)) {
        $retail_total += $sale['total_amount'];
        // Calculate cost per piece
        $pieces_per_package = $sale['pieces_per_package'] ?? 1;
        $cost_per_piece = ($sale['last_cost_price'] ?? 0) / $pieces_per_package;
        $cost = $cost_per_piece * $sale['pieces_sold'];
        $retail_cost_total += $cost;
        $retail_profit += ($sale['total_amount'] - $cost);
    }
    
    $total_revenue = $bulk_total + $retail_total;
    $total_cost = $bulk_cost_total + $retail_cost_total;
    $total_profit = $bulk_profit + $retail_profit;
    $profit_margin = $total_revenue > 0 ? ($total_profit / $total_revenue) * 100 : 0;
    
    // Check if record exists for this week
    $check = mysqli_query($conn, "
        SELECT id FROM weekly_revenue 
        WHERE week_start_date = '$week_start'
    ");
    
    if (mysqli_num_rows($check) > 0) {
        // Update existing record with profit data
        mysqli_query($conn, "
            UPDATE weekly_revenue 
            SET bulk_sales_total = $bulk_total,
                retail_sales_total = $retail_total,
                total_revenue = $total_revenue,
                total_cost = $total_cost,
                total_profit = $total_profit,
                profit_margin = $profit_margin
            WHERE week_start_date = '$week_start'
        ");
    } else {
        // Insert new record with profit data
        mysqli_query($conn, "
            INSERT INTO weekly_revenue (
                week_start_date, week_end_date, 
                bulk_sales_total, retail_sales_total, 
                total_revenue, total_cost, total_profit, profit_margin
            )
            VALUES (
                '$week_start', '$week_end', 
                $bulk_total, $retail_total, 
                $total_revenue, $total_cost, $total_profit, $profit_margin
            )
        ");
    }
}

// Update revenue data
updateWeeklyRevenue($conn);

// Get weekly revenue data for the last 8 weeks
$weekly_data = mysqli_query($conn, "
    SELECT * FROM weekly_revenue 
    ORDER BY week_start_date DESC 
    LIMIT 8
");

// Get current week revenue with detailed profit analysis
$current_week_query = mysqli_query($conn, "
    SELECT * FROM weekly_revenue 
    WHERE week_start_date = '" . date('Y-m-d', strtotime('monday this week')) . "'
");
$current_week = mysqli_fetch_assoc($current_week_query);

// If no current week data, initialize with zeros
if (!$current_week) {
    $current_week = [
        'total_revenue' => 0,
        'bulk_sales_total' => 0,
        'retail_sales_total' => 0,
        'total_cost' => 0,
        'total_profit' => 0,
        'profit_margin' => 0
    ];
}

// Get detailed sales with profit analysis for current week
$current_week_sales = mysqli_query($conn, "
    -- Bulk sales with profit
    SELECT 
        'Bulk' as sale_type,
        sb.sale_date,
        p.name as product_name,
        sb.quantity as quantity,
        sb.package_price as selling_price,
        sb.total_amount,
        COALESCE(pu.cost_price, 0) as purchase_price,
        COALESCE(pu.cost_price * sb.quantity, 0) as total_cost,
        (sb.total_amount - COALESCE(pu.cost_price * sb.quantity, 0)) as profit,
        ROUND(
            CASE 
                WHEN sb.total_amount > 0 
                THEN ((sb.total_amount - COALESCE(pu.cost_price * sb.quantity, 0)) / sb.total_amount * 100) 
                ELSE 0 
            END, 2
        ) as margin,
        sb.customer_name
    FROM sales_bulk sb
    JOIN products p ON sb.product_id = p.id
    LEFT JOIN purchases pu ON pu.product_id = sb.product_id
    WHERE sb.sale_date BETWEEN '" . date('Y-m-d', strtotime('monday this week')) . "' 
        AND '" . date('Y-m-d', strtotime('sunday this week')) . "'
    AND pu.id = (
        SELECT id FROM purchases p2 
        WHERE p2.product_id = sb.product_id 
        AND p2.purchase_date <= sb.sale_date 
        ORDER BY p2.purchase_date DESC LIMIT 1
    )
    
    UNION ALL
    
    -- Retail sales with profit
    SELECT 
        'Retail' as sale_type,
        sr.sale_date,
        p.name as product_name,
        sr.pieces_sold as quantity,
        sr.retail_price as selling_price,
        sr.total_amount,
        COALESCE(pu.cost_price, 0) as purchase_price,
        COALESCE((pu.cost_price / NULLIF(s.pieces_per_package, 0)) * sr.pieces_sold, 0) as total_cost,
        (sr.total_amount - COALESCE((pu.cost_price / NULLIF(s.pieces_per_package, 0)) * sr.pieces_sold, 0)) as profit,
        ROUND(
            CASE 
                WHEN sr.total_amount > 0 
                THEN ((sr.total_amount - COALESCE((pu.cost_price / NULLIF(s.pieces_per_package, 0)) * sr.pieces_sold, 0)) / sr.total_amount * 100) 
                ELSE 0 
            END, 2
        ) as margin,
        sr.customer_name
    FROM sales_retail sr
    JOIN products p ON sr.product_id = p.id
    LEFT JOIN purchases pu ON pu.product_id = sr.product_id
    LEFT JOIN stock s ON sr.product_id = s.product_id
    WHERE sr.sale_date BETWEEN '" . date('Y-m-d', strtotime('monday this week')) . "' 
        AND '" . date('Y-m-d', strtotime('sunday this week')) . "'
    AND pu.id = (
        SELECT id FROM purchases p2 
        WHERE p2.product_id = sr.product_id 
        AND p2.purchase_date <= sr.sale_date 
        ORDER BY p2.purchase_date DESC LIMIT 1
    )
    ORDER BY sale_date DESC
");

// Get product profitability analysis
$product_profitability = mysqli_query($conn, "
    SELECT 
        p.id,
        p.name,
        p.category,
        COUNT(DISTINCT sb.id) as bulk_sales_count,
        COALESCE(SUM(sb.quantity), 0) as total_packages_sold,
        COALESCE(SUM(sb.total_amount), 0) as bulk_revenue,
        COUNT(DISTINCT sr.id) as retail_sales_count,
        COALESCE(SUM(sr.pieces_sold), 0) as total_pieces_sold,
        COALESCE(SUM(sr.total_amount), 0) as retail_revenue,
        (
            SELECT pu.cost_price FROM purchases pu 
            WHERE pu.product_id = p.id 
            ORDER BY pu.purchase_date DESC LIMIT 1
        ) as latest_cost_price,
        (
            SELECT COALESCE(s.pieces_per_package, 1) FROM stock s 
            WHERE s.product_id = p.id 
            LIMIT 1
        ) as pieces_per_package,
        COALESCE(SUM(sb.total_amount), 0) + COALESCE(SUM(sr.total_amount), 0) as total_revenue,
        COALESCE(
            (COALESCE(SUM(sb.quantity), 0) * (
                SELECT pu.cost_price FROM purchases pu 
                WHERE pu.product_id = p.id 
                ORDER BY pu.purchase_date DESC LIMIT 1
            )), 0
        ) + COALESCE(
            (COALESCE(SUM(sr.pieces_sold), 0) * (
                SELECT pu.cost_price / NULLIF(s.pieces_per_package, 1) 
                FROM purchases pu, stock s
                WHERE pu.product_id = p.id 
                AND s.product_id = p.id
                ORDER BY pu.purchase_date DESC LIMIT 1
            )), 0
        ) as total_cost,
        (
            (COALESCE(SUM(sb.total_amount), 0) + COALESCE(SUM(sr.total_amount), 0)) -
            COALESCE(
                (COALESCE(SUM(sb.quantity), 0) * (
                    SELECT pu.cost_price FROM purchases pu 
                    WHERE pu.product_id = p.id 
                    ORDER BY pu.purchase_date DESC LIMIT 1
                )), 0
            ) - COALESCE(
                (COALESCE(SUM(sr.pieces_sold), 0) * (
                    SELECT pu.cost_price / NULLIF(s.pieces_per_package, 1) 
                    FROM purchases pu, stock s
                    WHERE pu.product_id = p.id 
                    AND s.product_id = p.id
                    ORDER BY pu.purchase_date DESC LIMIT 1
                )), 0
            )
        ) as total_profit
    FROM products p
    LEFT JOIN sales_bulk sb ON p.id = sb.product_id
    LEFT JOIN sales_retail sr ON p.id = sr.product_id
    GROUP BY p.id
    HAVING total_revenue > 0
    ORDER BY total_profit DESC
");

// Get total revenue and profit all time - FIXED VERSION
$total_all_time_query = mysqli_query($conn, "
    SELECT 
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_bulk), 0) +
        COALESCE((SELECT COALESCE(SUM(total_amount),0) FROM sales_retail), 0) as total_revenue,
        
        COALESCE(
            (SELECT COALESCE(SUM(pu.cost_price * sb.quantity),0) 
             FROM sales_bulk sb
             JOIN purchases pu ON pu.product_id = sb.product_id
             WHERE pu.id = (
                SELECT id FROM purchases p2 
                WHERE p2.product_id = sb.product_id 
                AND p2.purchase_date <= sb.sale_date 
                ORDER BY p2.purchase_date DESC LIMIT 1
             )), 0
        ) as total_bulk_cost,
         
        COALESCE(
            (SELECT COALESCE(SUM((pu.cost_price / NULLIF(s.pieces_per_package, 1)) * sr.pieces_sold),0)
             FROM sales_retail sr
             JOIN purchases pu ON pu.product_id = sr.product_id
             LEFT JOIN stock s ON sr.product_id = s.product_id
             WHERE pu.id = (
                SELECT id FROM purchases p2 
                WHERE p2.product_id = sr.product_id 
                AND p2.purchase_date <= sr.sale_date 
                ORDER BY p2.purchase_date DESC LIMIT 1
             )), 0
        ) as total_retail_cost
");

$total_all_time = mysqli_fetch_assoc($total_all_time_query);

// Calculate total cost and profit
$total_all_time['total_cost'] = ($total_all_time['total_bulk_cost'] ?? 0) + ($total_all_time['total_retail_cost'] ?? 0);
$total_all_time['total_profit'] = ($total_all_time['total_revenue'] ?? 0) - $total_all_time['total_cost'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit & Revenue Analysis - Small Stock Management</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/revenue.css">
   
</head>
<body>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="main-content">
            <h1>Profit & Revenue Analysis</h1>
            
            <!-- Current Week Profit Summary -->
            <div class="profit-summary">
                <div class="profit-card">
                    <h4>📊 This Week's Revenue</h4>
                    <div class="profit-amount">RWF <?php echo number_format($current_week['total_revenue'] ?? 0, 0); ?></div>
                    <div class="profit-stats">
                        <span>Bulk: RWF <?php echo number_format($current_week['bulk_sales_total'] ?? 0, 0); ?></span>
                        <span>Retail: RWF <?php echo number_format($current_week['retail_sales_total'] ?? 0, 0); ?></span>
                    </div>
                </div>
                
                <div class="profit-card">
                    <h4>💰 This Week's Cost</h4>
                    <div class="profit-amount" style="color: #dc3545;">
                        RWF <?php echo number_format($current_week['total_cost'] ?? 0, 0); ?>
                    </div>
                    <div class="profit-stats">
                        <span>Purchase cost of goods sold</span>
                    </div>
                </div>
                
                <div class="profit-card">
                    <h4>💵 This Week's Profit</h4>
                    <div class="profit-amount <?php echo ($current_week['total_profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                        RWF <?php echo number_format($current_week['total_profit'] ?? 0, 0); ?>
                    </div>
                    <div class="profit-stats">
                        <span>Margin: 
                            <span class="margin-badge <?php 
                                $margin = $current_week['profit_margin'] ?? 0;
                                if($margin >= 30) echo 'margin-high';
                                elseif($margin >= 15) echo 'margin-medium';
                                else echo 'margin-low';
                            ?>">
                                <?php echo number_format($margin, 1); ?>%
                            </span>
                        </span>
                    </div>
                </div>
                
                <div class="profit-card">
                    <h4>📈 All Time Performance</h4>
                    <div class="profit-amount">RWF <?php echo number_format($total_all_time['total_revenue'] ?? 0, 0); ?></div>
                    <div class="profit-stats">
                        <span>Profit: RWF <?php echo number_format($total_all_time['total_profit'] ?? 0, 0); ?></span>
                        <span class="<?php echo ($total_all_time['total_profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                            <?php 
                                $all_time_margin = ($total_all_time['total_revenue'] ?? 0) > 0 
                                    ? (($total_all_time['total_profit'] ?? 0) / ($total_all_time['total_revenue'] ?? 1)) * 100 
                                    : 0;
                                echo number_format($all_time_margin, 1); ?>%
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Revenue vs Cost Chart -->
            <div class="chart-container">
                <h2>Revenue vs Cost Analysis</h2>
                <div class="chart-wrapper">
                    <canvas id="revenueCostChart"></canvas>
                </div>
            </div>
            
            <!-- Current Week Detailed Sales with Profit -->
            <div class="revenue-table">
                <h2>This Week's Sales - Profit Analysis</h2>
                <div class="table-responsive">
                    <table class="table comparison-table" id="tblProfit">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Selling Price</th>
                                <th>Purchase Cost</th>
                                <th>Total Revenue</th>
                                <th>Total Cost</th>
                                <th>Profit</th>
                                <th>Margin</th>
                                <th>Customer</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_profit_display = 0;
                            if ($current_week_sales && mysqli_num_rows($current_week_sales) > 0):
                                while($sale = mysqli_fetch_assoc($current_week_sales)): 
                                    $profit_class = ($sale['profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative';
                                    $total_profit_display += $sale['profit'] ?? 0;
                            ?>
                            <tr class="<?php echo ($sale['profit'] ?? 0) >= 0 ? 'highlight-profit' : 'highlight-loss'; ?>">
                                <td><?php echo date('M d', strtotime($sale['sale_date'])); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $sale['sale_type'] == 'Bulk' ? 'primary' : 'info'; ?>">
                                        <?php echo $sale['sale_type']; ?>
                                    </span>
                                </td>
                                <td><strong><?php echo htmlspecialchars($sale['product_name'] ?? ''); ?></strong></td>
                                <td><?php echo $sale['quantity'] ?? 0; ?> <?php echo ($sale['sale_type'] ?? '') == 'Bulk' ? 'pkg' : 'pcs'; ?></td>
                                <td>RWF <?php echo number_format($sale['selling_price'] ?? 0, 0); ?></td>
                                <td class="cost-value">RWF <?php echo number_format($sale['purchase_price'] ?? 0, 0); ?></td>
                                <td class="revenue-value">RWF <?php echo number_format($sale['total_amount'] ?? 0, 0); ?></td>
                                <td class="cost-value">RWF <?php echo number_format($sale['total_cost'] ?? 0, 0); ?></td>
                                <td class="<?php echo $profit_class; ?>">
                                    RWF <?php echo number_format($sale['profit'] ?? 0, 0); ?>
                                </td>
                                <td>
                                    <span class="margin-badge <?php 
                                        $margin_value = $sale['margin'] ?? 0;
                                        if($margin_value >= 30) echo 'margin-high';
                                        elseif($margin_value >= 15) echo 'margin-medium';
                                        else echo 'margin-low';
                                    ?>">
                                        <?php echo number_format($margin_value, 1); ?>%
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'N/A'); ?></td>
                            </tr>
                            <?php 
                                endwhile; 
                            else: 
                            ?>
                            <tr>
                                <td colspan="11" style="text-align: center; padding: 30px;">
                                    No sales recorded this week
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f8f9fa; font-weight: bold;">
                                <td colspan="6"></td>
                                <td class="revenue-value">Total Revenue</td>
                                <td class="cost-value">Total Cost</td>
                                <td class="<?php echo ($current_week['total_profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                    Total Profit
                                </td>
                                <td colspan="2"></td>
                            </tr>
                            <tr style="background: #f8f9fa; font-weight: bold;">
                                <td colspan="6"></td>
                                <td>RWF <?php echo number_format($current_week['total_revenue'] ?? 0, 0); ?></td>
                                <td>RWF <?php echo number_format($current_week['total_cost'] ?? 0, 0); ?></td>
                                <td class="<?php echo ($current_week['total_profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                    RWF <?php echo number_format($current_week['total_profit'] ?? 0, 0); ?>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            
            <!-- Product Profitability Analysis -->
            <?php if ($product_profitability && mysqli_num_rows($product_profitability) > 0): ?>
            <div class="revenue-table" style="margin-top: 30px;">
                <h2>Product Profitability Analysis</h2>
                <div class="table-responsive">
                    <table class="table" id="tblProductRevenue">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Bulk Sales</th>
                                <th>Retail Sales</th>
                                <th>Total Revenue</th>
                                <th>Total Cost</th>
                                <th>Total Profit</th>
                                <th>Margin</th>
                                <th>Cost Price</th>
                                <th>Selling Price (Avg)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($product = mysqli_fetch_assoc($product_profitability)): 
                                $avg_selling_price = 0;
                                $pieces_per_package = $product['pieces_per_package'] ?? 1;
                                $total_units = $product['total_packages_sold'] + ($product['total_pieces_sold'] / $pieces_per_package);
                                $margin_percentage = ($product['total_revenue'] ?? 0) > 0 ? 
                                    (($product['total_profit'] ?? 0) / ($product['total_revenue'] ?? 1) * 100) : 0;
                                
                                // Calculate average selling price
                                if(($product['total_packages_sold'] > 0 || $product['total_pieces_sold'] > 0) && $total_units > 0) {
                                    $bulk_value = $product['bulk_revenue'] ?? 0;
                                    $retail_value = $product['retail_revenue'] ?? 0;
                                    $avg_selling_price = ($bulk_value + $retail_value) / $total_units;
                                }
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($product['name'] ?? ''); ?></strong></td>
                                <td><?php echo htmlspecialchars($product['category'] ?? ''); ?></td>
                                <td>
                                    <?php echo $product['total_packages_sold'] ?? 0; ?> pkg<br>
                                    <small>RWF <?php echo number_format($product['bulk_revenue'] ?? 0, 0); ?></small>
                                </td>
                                <td>
                                    <?php echo $product['total_pieces_sold'] ?? 0; ?> pcs<br>
                                    <small>RWF <?php echo number_format($product['retail_revenue'] ?? 0, 0); ?></small>
                                </td>
                                <td class="revenue-value">RWF <?php echo number_format($product['total_revenue'] ?? 0, 0); ?></td>
                                <td class="cost-value">RWF <?php echo number_format($product['total_cost'] ?? 0, 0); ?></td>
                                <td class="<?php echo ($product['total_profit'] ?? 0) >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                    RWF <?php echo number_format($product['total_profit'] ?? 0, 0); ?>
                                </td>
                                <td>
                                    <span class="margin-badge <?php 
                                        if($margin_percentage >= 30) echo 'margin-high';
                                        elseif($margin_percentage >= 15) echo 'margin-medium';
                                        else echo 'margin-low';
                                    ?>">
                                        <?php echo number_format($margin_percentage, 1); ?>%
                                    </span>
                                </td>
                                <td>RWF <?php echo number_format($product['latest_cost_price'] ?? 0, 0); ?>/pkg</td>
                                <td>
                                    RWF <?php echo number_format($avg_selling_price, 0); ?>/pkg<br>
                                    <small>Retail: RWF <?php echo number_format(($product['latest_cost_price'] ?? 0) / $pieces_per_package, 0); ?>/pc</small>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Weekly Performance Summary -->
            <div class="revenue-table" style="margin-top: 30px;">
                <h2>Weekly Performance Summary</h2>
                <div class="table-responsive">
                    <table class="table" >
                        <thead>
                            <tr>
                                <th>Week</th>
                                <th>Period</th>
                                <th>Bulk Sales</th>
                                <th>Retail Sales</th>
                                <th>Total Revenue</th>
                                <th>Total Cost</th>
                                <th>Profit</th>
                                <th>Margin</th>
                                <th>Trend</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $previous_profit = 0;
                            if ($weekly_data && mysqli_num_rows($weekly_data) > 0):
                                while($week = mysqli_fetch_assoc($weekly_data)): 
                                    $week_profit = $week['total_profit'] ?? ($week['total_revenue'] * 0.2);
                                    $trend = $week_profit - $previous_profit;
                                    $trend_class = $trend > 0 ? 'profit-positive' : ($trend < 0 ? 'profit-negative' : 'profit-neutral');
                                    $previous_profit = $week_profit;
                            ?>
                            <tr>
                                <td><strong>Week <?php echo date('W', strtotime($week['week_start_date'])); ?></strong></td>
                                <td>
                                    <?php echo date('M d', strtotime($week['week_start_date'])); ?> - 
                                    <?php echo date('M d', strtotime($week['week_end_date'])); ?>
                                </td>
                                <td>RWF <?php echo number_format($week['bulk_sales_total'], 0); ?></td>
                                <td>RWF <?php echo number_format($week['retail_sales_total'], 0); ?></td>
                                <td class="revenue-value">RWF <?php echo number_format($week['total_revenue'], 0); ?></td>
                                <td class="cost-value">RWF <?php echo number_format($week['total_cost'] ?? 0, 0); ?></td>
                                <td class="<?php echo $week_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                                    RWF <?php echo number_format($week_profit, 0); ?>
                                </td>
                                <td>
                                    <span class="margin-badge <?php 
                                        $margin = $week['profit_margin'] ?? (($week['total_revenue'] > 0 ? ($week_profit / $week['total_revenue'] * 100) : 0));
                                        if($margin >= 30) echo 'margin-high';
                                        elseif($margin >= 15) echo 'margin-medium';
                                        else echo 'margin-low';
                                    ?>">
                                        <?php echo number_format($margin, 1); ?>%
                                    </span>
                                </td>
                                <td class="<?php echo $trend_class; ?>">
                                    <?php if($trend > 0): ?>
                                        ↑ +RWF <?php echo number_format($trend, 0); ?>
                                    <?php elseif($trend < 0): ?>
                                        ↓ RWF <?php echo number_format(abs($trend), 0); ?>
                                    <?php else: ?>
                                        →
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php 
                                endwhile; 
                            else:
                            ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 30px;">
                                    No weekly data available
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script src="chart.js"></script>
<script>
    (function() {
        'use strict';
        
        let revenueChart = null;
        
        function initRevenueChart() {
            const canvas = document.getElementById('revenueCostChart');
            if (!canvas) return;
            
            // Destroy existing chart
            if (revenueChart) {
                revenueChart.destroy();
                revenueChart = null;
            }
            
            // Clear canvas
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            // Prepare chart data
            const weekLabels = [];
            const revenueData = [];
            const costData = [];
            const profitData = [];
            
            <?php
            if (isset($weekly_data) && mysqli_num_rows($weekly_data) > 0) {
                mysqli_data_seek($weekly_data, 0);
                $chart_data = mysqli_fetch_all($weekly_data, MYSQLI_ASSOC);
                $chart_data = array_reverse($chart_data);
                
                foreach($chart_data as $row): 
                    $week_profit = $row['total_profit'] ?? ($row['total_revenue'] * 0.2);
                    $week_cost = $row['total_cost'] ?? ($row['total_revenue'] * 0.8);
                ?>
                    weekLabels.push('Week <?php echo date('W', strtotime($row['week_start_date'])); ?>');
                    revenueData.push(<?php echo $row['total_revenue'] ?? 0; ?>);
                    costData.push(<?php echo $week_cost; ?>);
                    profitData.push(<?php echo $week_profit; ?>);
                <?php 
                endforeach; 
            }
            ?>
            
            // Only create chart if we have data
            if (weekLabels.length > 0) {
                try {
                    revenueChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: weekLabels,
                            datasets: [
                                {
                                    label: 'Revenue',
                                    data: revenueData,
                                    backgroundColor: 'rgba(40, 167, 69, 0.7)',
                                    borderColor: 'rgba(40, 167, 69, 1)',
                                    borderWidth: 1
                                },
                                {
                                    label: 'Cost',
                                    data: costData,
                                    backgroundColor: 'rgba(220, 53, 69, 0.7)',
                                    borderColor: 'rgba(220, 53, 69, 1)',
                                    borderWidth: 1
                                },
                                {
                                    label: 'Profit',
                                    data: profitData,
                                    type: 'line',
                                    borderColor: 'rgba(102, 126, 234, 1)',
                                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                    borderWidth: 3,
                                    pointRadius: 5,
                                    pointBackgroundColor: 'rgba(102, 126, 234, 1)',
                                    tension: 0.1,
                                    yAxisID: 'y1'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: {
                                duration: 0
                            },
                            interaction: {
                                mode: 'index',
                                intersect: false,
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    position: 'left',
                                    title: {
                                        display: true,
                                        text: 'Amount (RWF)'
                                    },
                                    ticks: {
                                        callback: function(value) {
                                            return 'RWF ' + value.toLocaleString();
                                        }
                                    }
                                },
                                y1: {
                                    beginAtZero: true,
                                    position: 'right',
                                    title: {
                                        display: true,
                                        text: 'Profit (RWF)'
                                    },
                                    ticks: {
                                        callback: function(value) {
                                            return 'RWF ' + value.toLocaleString();
                                        }
                                    },
                                    grid: {
                                        drawOnChartArea: false,
                                    },
                                }
                            }
                        }
                    });
                } catch (e) {
                    console.error('Revenue chart error:', e);
                }
            }
        }
        
        // Initialize when DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initRevenueChart);
        } else {
            initRevenueChart();
        }
        
    })();
</script>
    <script src="script.js"></script>
         <script>
createAdvancedTableSearch('txtSearchProductRevenue', 'tblProductRevenue', []);
createAdvancedTableSearch('txtSearchProfit', 'tblProfit', []);
    </script>
</body>
</html>