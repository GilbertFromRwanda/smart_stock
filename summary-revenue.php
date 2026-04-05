<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Default: current month
$from = isset($_GET['from']) && $_GET['from'] ? mysqli_real_escape_string($conn, $_GET['from']) : date('Y-m-01');
$to   = isset($_GET['to'])   && $_GET['to']   ? mysqli_real_escape_string($conn, $_GET['to'])   : date('Y-m-d');

// ── Bulk sales total ───────────────────────────────────────────────────────────
$bulk = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COALESCE(SUM(sb.total_amount), 0)                                           AS revenue,
        COALESCE(SUM(
            (SELECT pu.cost_price FROM purchases pu
             WHERE pu.product_id = sb.product_id
               AND pu.purchase_date <= sb.sale_date
             ORDER BY pu.purchase_date DESC LIMIT 1) * sb.quantity
        ), 0)                                                                        AS cost
    FROM sales_bulk sb
    WHERE sb.sale_date BETWEEN '$from' AND '$to'
"));

// ── Retail sales total ─────────────────────────────────────────────────────────
$retail = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT
        COALESCE(SUM(sr.total_amount), 0)                                            AS revenue,
        COALESCE(SUM(
            (SELECT pu.cost_price / NULLIF(s.pieces_per_package, 0)
             FROM purchases pu
             JOIN stock s ON s.product_id = pu.product_id
             WHERE pu.product_id = sr.product_id
               AND pu.purchase_date <= sr.sale_date
             ORDER BY pu.purchase_date DESC LIMIT 1) * sr.pieces_sold
        ), 0)                                                                        AS cost
    FROM sales_retail sr
    WHERE sr.sale_date BETWEEN '$from' AND '$to'
"));

// ── Expenses total ─────────────────────────────────────────────────────────────
$exp_check = mysqli_query($conn, "SHOW TABLES LIKE 'expenses'");
$total_expenses = 0;
if (mysqli_num_rows($exp_check) > 0) {
    $exp = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COALESCE(SUM(amount), 0) AS total
        FROM expenses
        WHERE expense_date BETWEEN '$from' AND '$to'
    "));
    $total_expenses = $exp['total'];
}

// ── Consumption total ──────────────────────────────────────────────────────────
$con_check = mysqli_query($conn, "SHOW TABLES LIKE 'consumption'");
$total_consumption = 0;
$total_consumption_unpaid = 0;
if (mysqli_num_rows($con_check) > 0) {
    $con = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT COALESCE(SUM(amount), 0)                          AS total,
               COALESCE(SUM(amount - paid_amount), 0)            AS unpaid
        FROM consumption
        WHERE consumption_date BETWEEN '$from' AND '$to'
    "));
    $total_consumption        = $con['total'];
    $total_consumption_unpaid = $con['unpaid'];
}

// ── Derived totals ─────────────────────────────────────────────────────────────
$total_revenue      = $bulk['revenue']  + $retail['revenue'];
$total_cost         = $bulk['cost']     + $retail['cost'];
$gross_profit       = $total_revenue    - $total_cost;
$net_profit         = $gross_profit     - $total_expenses - $total_consumption_unpaid;
$profit_margin      = $total_revenue > 0 ? round(($gross_profit / $total_revenue) * 100, 1) : 0;
$net_margin         = $total_revenue > 0 ? round(($net_profit   / $total_revenue) * 100, 1) : 0;

// ── Daily breakdown ────────────────────────────────────────────────────────────
$daily_bulk = [];
$bulk_daily_q = mysqli_query($conn, "
    SELECT sale_date, SUM(total_amount) AS total
    FROM sales_bulk
    WHERE sale_date BETWEEN '$from' AND '$to'
    GROUP BY sale_date
");
while ($r = mysqli_fetch_assoc($bulk_daily_q)) $daily_bulk[$r['sale_date']] = $r['total'];

$daily_retail = [];
$retail_daily_q = mysqli_query($conn, "
    SELECT sale_date, SUM(total_amount) AS total
    FROM sales_retail
    WHERE sale_date BETWEEN '$from' AND '$to'
    GROUP BY sale_date
");
while ($r = mysqli_fetch_assoc($retail_daily_q)) $daily_retail[$r['sale_date']] = $r['total'];

$daily_exp = [];
if (mysqli_num_rows($exp_check) > 0) {
    $exp_daily_q = mysqli_query($conn, "
        SELECT expense_date AS d, SUM(amount) AS total
        FROM expenses
        WHERE expense_date BETWEEN '$from' AND '$to'
        GROUP BY expense_date
    ");
    while ($r = mysqli_fetch_assoc($exp_daily_q)) $daily_exp[$r['d']] = $r['total'];
}

$daily_con = [];
$daily_con_unpaid = [];
if (mysqli_num_rows($con_check) > 0) {
    $con_daily_q = mysqli_query($conn, "
        SELECT consumption_date AS d,
               SUM(amount) AS total,
               SUM(amount - paid_amount) AS unpaid
        FROM consumption
        WHERE consumption_date BETWEEN '$from' AND '$to'
        GROUP BY consumption_date
    ");
    while ($r = mysqli_fetch_assoc($con_daily_q)) {
        $daily_con[$r['d']]        = $r['total'];
        $daily_con_unpaid[$r['d']] = $r['unpaid'];
    }
}

// Merge all dates
$all_dates = array_unique(array_merge(
    array_keys($daily_bulk),
    array_keys($daily_retail),
    array_keys($daily_exp),
    array_keys($daily_con)
));
rsort($all_dates); // newest first
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Summary</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .summary-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px 18px;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary);
        }
        .summary-card.green  { border-left-color: var(--success); }
        .summary-card.red    { border-left-color: var(--danger); }
        .summary-card.orange { border-left-color: var(--warning); }
        .summary-card.purple { border-left-color: #7c3aed; }
        .summary-card label  { font-size: 12px; color: var(--secondary); text-transform: uppercase; letter-spacing: .5px; }
        .summary-card .val   { font-size: 22px; font-weight: 700; color: var(--dark); margin-top: 6px; }
        .summary-card .sub   { font-size: 11px; color: var(--secondary); margin-top: 4px; }
        .val.neg             { color: var(--danger); }
        .tbl-day td, .tbl-day th { font-size: 13px; }
        .tbl-day tfoot td    { font-weight: 700; background: var(--light); }
        @media print {
            .sidebar, .action-bar, .date-filter-bar, .no-print { display: none !important; }
            .main-content { margin: 0 !important; }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">
        <h1>Revenue Summary</h1>

        <!-- Date filter -->
        <form method="GET" class="date-filter-bar">
            <div class="filter-group">
                <label>From</label>
                <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>">
            </div>
            <div class="filter-group">
                <label>To</label>
                <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>">
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
            <button type="button" class="btn btn-secondary no-print" onclick="window.print()">Print</button>
        </form>

        <p style="color:var(--secondary);font-size:13px;margin-bottom:20px;">
            Period: <strong><?php echo date('M d, Y', strtotime($from)); ?></strong>
            &mdash;
            <strong><?php echo date('M d, Y', strtotime($to)); ?></strong>
        </p>

        <!-- Summary cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <label>Bulk Sales</label>
                <div class="val">RWF <?php echo number_format($bulk['revenue'], 0); ?></div>
            </div>
            <div class="summary-card">
                <label>Retail Sales</label>
                <div class="val">RWF <?php echo number_format($retail['revenue'], 0); ?></div>
            </div>
            <div class="summary-card green">
                <label>Total Revenue</label>
                <div class="val">RWF <?php echo number_format($total_revenue, 0); ?></div>
            </div>
            <div class="summary-card red">
                <label>Total Cost</label>
                <div class="val">RWF <?php echo number_format($total_cost, 0); ?></div>
            </div>
            <div class="summary-card green">
                <label>Gross Profit</label>
                <div class="val <?php echo $gross_profit < 0 ? 'neg' : ''; ?>">
                    RWF <?php echo number_format($gross_profit, 0); ?>
                </div>
                <div class="sub">Margin <?php echo $profit_margin; ?>%</div>
            </div>
            <div class="summary-card orange">
                <label>Expenses</label>
                <div class="val">RWF <?php echo number_format($total_expenses, 0); ?></div>
            </div>
            <div class="summary-card orange">
                <label>Consumption</label>
                <div class="val">RWF <?php echo number_format($total_consumption, 0); ?></div>
                <div class="sub" style="color:var(--danger);font-weight:600;">
                    Unpaid: RWF <?php echo number_format($total_consumption_unpaid, 0); ?>
                </div>
                <div class="sub">Paid: RWF <?php echo number_format($total_consumption - $total_consumption_unpaid, 0); ?></div>
            </div>
            <div class="summary-card purple">
                <label>Net Profit</label>
                <div class="val <?php echo $net_profit < 0 ? 'neg' : ''; ?>">
                    RWF <?php echo number_format($net_profit, 0); ?>
                </div>
                <div class="sub">Margin <?php echo $net_margin; ?>%</div>
            </div>
        </div>

        <!-- Daily breakdown -->
        <?php if (count($all_dates) > 0): ?>
        <div class="table-responsive">
            <table class="table tbl-day">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Bulk Sales</th>
                        <th>Retail Sales</th>
                        <th>Total Revenue</th>
                        <th>Expenses</th>
                        <th>Consumption</th>
                        <th>Con. Unpaid</th>
                        <th>Net</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $gt_bulk = $gt_retail = $gt_exp = $gt_con = $gt_con_unpaid = 0;
                foreach ($all_dates as $d):
                    $d_bulk       = $daily_bulk[$d]       ?? 0;
                    $d_retail     = $daily_retail[$d]      ?? 0;
                    $d_exp        = $daily_exp[$d]         ?? 0;
                    $d_con        = $daily_con[$d]         ?? 0;
                    $d_con_unpaid = $daily_con_unpaid[$d]  ?? 0;
                    $d_rev        = $d_bulk + $d_retail;
                    $d_net        = $d_rev  - $d_exp - $d_con_unpaid;
                    $gt_bulk       += $d_bulk;
                    $gt_retail     += $d_retail;
                    $gt_exp        += $d_exp;
                    $gt_con        += $d_con;
                    $gt_con_unpaid += $d_con_unpaid;
                ?>
                <tr>
                    <td><?php echo date('D, M d', strtotime($d)); ?></td>
                    <td><?php echo $d_bulk   ? 'RWF ' . number_format($d_bulk, 0)   : '-'; ?></td>
                    <td><?php echo $d_retail ? 'RWF ' . number_format($d_retail, 0) : '-'; ?></td>
                    <td><strong>RWF <?php echo number_format($d_rev, 0); ?></strong></td>
                    <td><?php echo $d_exp ? 'RWF ' . number_format($d_exp, 0) : '-'; ?></td>
                    <td><?php echo $d_con ? 'RWF ' . number_format($d_con, 0) : '-'; ?></td>
                    <td style="<?php echo $d_con_unpaid > 0 ? 'color:var(--danger);font-weight:600;' : 'color:var(--secondary);'; ?>">
                        <?php echo $d_con_unpaid > 0 ? 'RWF ' . number_format($d_con_unpaid, 0) : '-'; ?>
                    </td>
                    <td style="color:<?php echo $d_net >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;font-weight:600;">
                        RWF <?php echo number_format($d_net, 0); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td>RWF <?php echo number_format($gt_bulk, 0); ?></td>
                        <td>RWF <?php echo number_format($gt_retail, 0); ?></td>
                        <td>RWF <?php echo number_format($gt_bulk + $gt_retail, 0); ?></td>
                        <td>RWF <?php echo number_format($gt_exp, 0); ?></td>
                        <td>RWF <?php echo number_format($gt_con, 0); ?></td>
                        <td style="<?php echo $gt_con_unpaid > 0 ? 'color:var(--danger);font-weight:600;' : ''; ?>">
                            <?php echo $gt_con_unpaid > 0 ? 'RWF ' . number_format($gt_con_unpaid, 0) : '-'; ?>
                        </td>
                        <td style="color:<?php echo $net_profit >= 0 ? 'var(--success)' : 'var(--danger)'; ?>">
                            RWF <?php echo number_format($net_profit, 0); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
            <p style="color:var(--secondary);text-align:center;padding:40px;">
                No data found for the selected period.
            </p>
        <?php endif; ?>
    </div>
</div>
<script src="script.js"></script>
</body>
</html>
