<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');


$days  = isset($_GET['days']) && in_array((int)$_GET['days'], [7, 14, 30, 60, 90]) ? (int)$_GET['days'] : 30;
$since = mysqli_real_escape_string($conn, date('Y-m-d', strtotime("-{$days} days")));

// ── Master query ─────────────────────────────────────────────────────────────
// Correlated subqueries give us per-product sales + revenue over the period
// and last purchase info; LEFT JOINs give current stock levels.
$sql = "
SELECT
    p.id,
    p.name,
    p.category,
    p.reorder_level,

    -- Current warehouse stock
    COALESCE(s.quantity, 0)            AS wh_qty,
    COALESCE(s.pieces_per_package, 1)  AS ppp,
    COALESCE(s.package_price, 0)       AS pkg_price,

    -- Current retail stock (pieces)
    COALESCE(rs.pieces_quantity, 0)    AS rt_qty,

    -- Bulk sales in period (packages sold)
    COALESCE((
        SELECT SUM(sb.quantity)
        FROM sales_bulk sb
        WHERE sb.product_id = p.id
          AND sb.sale_date >= '$since'
          AND sb.refunded = 0
    ), 0) AS bulk_sold,

    -- Retail sales in period (pieces sold)
    COALESCE((
        SELECT SUM(sr.pieces_sold)
        FROM sales_retail sr
        WHERE sr.product_id = p.id
          AND sr.sale_date >= '$since'
          AND sr.refunded = 0
    ), 0) AS retail_sold,

    -- Total revenue in period (bulk + retail)
    COALESCE((
        SELECT SUM(sb2.total_amount)
        FROM sales_bulk sb2
        WHERE sb2.product_id = p.id
          AND sb2.sale_date >= '$since'
          AND sb2.refunded = 0
    ), 0) + COALESCE((
        SELECT SUM(sr2.total_amount)
        FROM sales_retail sr2
        WHERE sr2.product_id = p.id
          AND sr2.sale_date >= '$since'
          AND sr2.refunded = 0
    ), 0) AS revenue_period,

    -- Last purchase details
    (SELECT pu.purchase_date FROM purchases pu
     WHERE pu.product_id = p.id
     ORDER BY pu.purchase_date DESC LIMIT 1) AS last_buy_date,

    (SELECT pu.quantity FROM purchases pu
     WHERE pu.product_id = p.id
     ORDER BY pu.purchase_date DESC LIMIT 1) AS last_buy_qty,

    (SELECT pu.cost_price FROM purchases pu
     WHERE pu.product_id = p.id
     ORDER BY pu.purchase_date DESC LIMIT 1) AS last_cost,

    (SELECT ROUND(AVG(pu.quantity)) FROM purchases pu
     WHERE pu.product_id = p.id) AS avg_buy_qty,

    (SELECT pu.id FROM purchases pu
     WHERE pu.product_id = p.id
     ORDER BY pu.purchase_date DESC LIMIT 1) AS last_purchase_id

FROM products p
LEFT JOIN stock s
       ON s.product_id = p.id
LEFT JOIN retail_stock rs
       ON rs.product_id = p.id
WHERE p.deleted = 0
HAVING (bulk_sold > 0 OR retail_sold > 0 OR wh_qty > 0 OR rt_qty > 0)
ORDER BY revenue_period DESC
";

$res = mysqli_query($conn, $sql);

// ── Process each product ──────────────────────────────────────────────────────
$products      = [];
$total_revenue = 0;

while ($r = mysqli_fetch_assoc($res)) {
    $ppp       = max(1, (int)$r['ppp']);
    $wh_pcs    = (int)$r['wh_qty'] * $ppp;
    $rt_pcs    = (int)$r['rt_qty'];
    $stock_pcs = $wh_pcs + $rt_pcs;

    // Normalise everything to pieces
    $bulk_pcs  = (int)$r['bulk_sold'] * $ppp;
    $sold_pcs  = $bulk_pcs + (int)$r['retail_sold'];

    $daily     = $days > 0 ? $sold_pcs / $days : 0;
    $days_left = ($daily > 0) ? ($stock_pcs / $daily) : PHP_INT_MAX;

    // Suggested reorder: enough to bring stock to 30 days of coverage
    $target_pcs  = $daily * 30;
    $needed_pcs  = max(0, $target_pcs - $stock_pcs);
    $needed_pkgs = $ppp > 0 ? (int)ceil($needed_pcs / $ppp) : 0;
    $avg_buy     = max(1, (int)($r['avg_buy_qty'] ?? 1));
    // Suggest at least the historical average when stock is critical
    $suggested   = ($days_left <= 14 && $needed_pkgs < $avg_buy)
                   ? $avg_buy : $needed_pkgs;

    // Urgency tier
    if ($stock_pcs === 0 && $sold_pcs > 0) {
        $tier = 4; $badge = 'OUT OF STOCK'; $cls = 'out';
    } elseif ($days_left <= 7) {
        $tier = 3; $badge = 'ORDER NOW';    $cls = 'urgent';
    } elseif ($days_left <= 14) {
        $tier = 2; $badge = 'ORDER SOON';   $cls = 'soon';
    } elseif ($days_left <= 30) {
        $tier = 1; $badge = 'MONITOR';      $cls = 'monitor';
    } else {
        $tier = 0; $badge = 'WELL STOCKED'; $cls = 'ok';
    }

    $rev = (float)$r['revenue_period'];
    $total_revenue += $rev;

    $products[] = [
        'id'          => (int)$r['id'],
        'name'        => $r['name'],
        'category'    => $r['category'],
        'ppp'         => $ppp,
        'wh_qty'      => (int)$r['wh_qty'],
        'wh_pcs'      => $wh_pcs,
        'rt_pcs'      => $rt_pcs,
        'stock_pcs'   => $stock_pcs,
        'bulk_sold'   => (int)$r['bulk_sold'],
        'retail_sold' => (int)$r['retail_sold'],
        'sold_pcs'    => $sold_pcs,
        'daily'       => $daily,
        'days_left'   => $days_left,
        'suggested'   => $suggested,
        'revenue'     => $rev,
        'last_buy'    => $r['last_buy_date'],
        'last_qty'    => (int)$r['last_buy_qty'],
        'last_cost'   => (float)$r['last_cost'],
        'pkg_price'   => (float)$r['pkg_price'],
        'tier'            => $tier,
        'badge'           => $badge,
        'cls'             => $cls,
        'last_purchase_id'=> $r['last_purchase_id'] ? (int)$r['last_purchase_id'] : null,
    ];
}

// Sort: tier desc → revenue desc
usort($products, fn($a, $b) =>
    $b['tier'] !== $a['tier'] ? $b['tier'] - $a['tier'] : $b['revenue'] <=> $a['revenue']
);

// Revenue share
foreach ($products as &$p) {
    $p['rev_share'] = $total_revenue > 0 ? round($p['revenue'] / $total_revenue * 100, 1) : 0;
}
unset($p);

$max_rev_share = $products ? max(1, max(array_column($products, 'rev_share'))) : 1;

// Summary counts
$counts = [4 => 0, 3 => 0, 2 => 0, 1 => 0, 0 => 0];
$est_invest = 0;
foreach ($products as $p) {
    $counts[$p['tier']]++;
    if ($p['tier'] >= 2) {
        $est_invest += $p['suggested'] * $p['last_cost'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Advice</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* ── Period tabs ── */
        .period-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:24px; }
        .period-tab {
            padding:6px 16px; border-radius:20px; font-size:12px; font-weight:600;
            border:1px solid var(--gray-200); background:var(--white);
            color:var(--secondary); text-decoration:none; transition:all .15s;
        }
        .period-tab:hover { background:var(--gray-100); }
        .period-tab.active { background:var(--primary); color:#fff; border-color:var(--primary); }

        /* ── Summary cards ── */
        .adv-summary {
            display:grid;
            grid-template-columns: repeat(auto-fill, minmax(150px,1fr));
            gap:14px; margin-bottom:28px;
        }
        .adv-card {
            background:var(--white); border:1px solid var(--gray-200);
            border-radius:14px; padding:16px 14px;
            box-shadow:var(--shadow-sm); text-align:center;
            border-top:4px solid var(--gray-200);
        }
        .adv-card.out    { border-top-color:#7c3aed; }
        .adv-card.urgent { border-top-color:#ef4444; }
        .adv-card.soon   { border-top-color:#f59e0b; }
        .adv-card.monitor{ border-top-color:#3b82f6; }
        .adv-card.ok     { border-top-color:#10b981; }
        .adv-card.invest { border-top-color:#6366f1; }
        .adv-card-num  { font-size:26px; font-weight:800; color:var(--dark); line-height:1.1; }
        .adv-card-lbl  { font-size:10px; font-weight:700; text-transform:uppercase;
                         letter-spacing:.6px; color:var(--secondary); margin-top:4px; }

        /* ── Table ── */
        .adv-table th { white-space:nowrap; }
        .adv-table td { vertical-align:middle; }

        /* ── Status badges ── */
        .badge {
            display:inline-block; padding:3px 9px; border-radius:20px;
            font-size:10px; font-weight:700; letter-spacing:.4px; white-space:nowrap;
        }
        .badge-out     { background:#f3e8ff; color:#7c3aed; }
        .badge-urgent  { background:#fee2e2; color:#dc2626; }
        .badge-soon    { background:#fef3c7; color:#b45309; }
        .badge-monitor { background:#dbeafe; color:#1d4ed8; }
        .badge-ok      { background:#d1fae5; color:#065f46; }

        /* ── Advice column ── */
        .advice-what  { font-size:12px; font-weight:600; color:var(--dark); }
        .advice-how   { font-size:11px; color:var(--secondary); margin-top:2px; }
        .advice-when  { font-size:11px; font-weight:600; margin-top:3px; }
        .when-out     { color:#7c3aed; }
        .when-urgent  { color:#dc2626; }
        .when-soon    { color:#b45309; }
        .when-monitor { color:#1d4ed8; }
        .when-ok      { color:#065f46; }

        /* ── Days bar ── */
        .days-bar-wrap { display:flex; align-items:center; gap:7px; }
        .days-bar-bg {
            flex:1; height:6px; background:var(--gray-200); border-radius:3px; overflow:hidden;
        }
        .days-bar-fill { height:100%; border-radius:3px; }
        .fill-out      { background:#7c3aed; width:100%; }
        .fill-urgent   { background:#ef4444; }
        .fill-soon     { background:#f59e0b; }
        .fill-monitor  { background:#3b82f6; }
        .fill-ok       { background:#10b981; width:100%; }
        .days-label    { font-size:11px; font-weight:700; white-space:nowrap; min-width:36px; text-align:right; }

        /* ── Rev share bar ── */
        .rev-bar-wrap  { display:flex; align-items:center; gap:6px; font-size:11px; }
        .rev-bar-bg    { flex:1; height:5px; background:var(--gray-200); border-radius:3px; overflow:hidden; }
        .rev-bar-fill  { height:100%; background:var(--primary); border-radius:3px; }

        .no-data { text-align:center; padding:60px 20px; color:var(--secondary); }
        .no-data-icon { font-size:48px; opacity:.35; margin-bottom:12px; }

        /* ── Budget planner ── */
        .budget-box {
            background:var(--white); border:1px solid var(--gray-200);
            border-radius:14px; padding:20px 22px; margin-bottom:28px;
            box-shadow:var(--shadow-sm);
        }
        .budget-box h3 { margin:0 0 14px; font-size:15px; }
        .budget-input-row {
            display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;
        }
        .budget-input-row .form-group { margin:0; flex:1; min-width:180px; }
        .budget-result { margin-top:20px; }
        .budget-result-header {
            display:flex; justify-content:space-between; align-items:center;
            margin-bottom:12px; flex-wrap:wrap; gap:8px;
        }
        .budget-result-header h4 { margin:0; font-size:14px; }
        .budget-pills { display:flex; gap:8px; flex-wrap:wrap; }
        .budget-pill {
            padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700;
        }
        .pill-spend  { background:#dbeafe; color:#1d4ed8; }
        .pill-left   { background:#d1fae5; color:#065f46; }
        .pill-count  { background:#f3f4f6; color:#374151; }

        .plan-table td, .plan-table th { font-size:12px; padding:8px 10px; }
        .plan-row-full    { background:#f0fdf4; }
        .plan-row-partial { background:#fffbeb; }
        .plan-row-skip    { background:#fafafa; opacity:.55; }
        .qty-full    { color:#065f46; font-weight:700; }
        .qty-partial { color:#b45309; font-weight:700; }
        .qty-skip    { color:#9ca3af; }
        .skip-reason { font-size:10px; color:#9ca3af; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>
    <div class="main-content">

        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
            <div>
                <h1 style="margin:0;">Purchase Advice</h1>
                <p style="margin:4px 0 0;color:var(--secondary);font-size:13px;">
                    What to buy · How much · When — based on sales demand, purchase history &amp; revenue.
                </p>
            </div>
        </div>

        <!-- Period selector -->
        <div class="period-tabs">
            <?php foreach ([7 => 'Last 7 days', 14 => 'Last 14 days', 30 => 'Last 30 days', 60 => 'Last 60 days', 90 => 'Last 90 days'] as $d => $lbl): ?>
            <a href="?days=<?php echo $d; ?>"
               class="period-tab<?php echo $days === $d ? ' active' : ''; ?>"><?php echo $lbl; ?></a>
            <?php endforeach; ?>
        </div>

        <!-- Summary cards -->
        <div class="adv-summary">
            <div class="adv-card out">
                <div class="adv-card-num"><?php echo $counts[4]; ?></div>
                <div class="adv-card-lbl">🟣 Out of Stock</div>
            </div>
            <div class="adv-card urgent">
                <div class="adv-card-num"><?php echo $counts[3]; ?></div>
                <div class="adv-card-lbl">🔴 Order Now</div>
            </div>
            <div class="adv-card soon">
                <div class="adv-card-num"><?php echo $counts[2]; ?></div>
                <div class="adv-card-lbl">🟡 Order Soon</div>
            </div>
            <div class="adv-card monitor">
                <div class="adv-card-num"><?php echo $counts[1]; ?></div>
                <div class="adv-card-lbl">🔵 Monitor</div>
            </div>
            <div class="adv-card ok">
                <div class="adv-card-num"><?php echo $counts[0]; ?></div>
                <div class="adv-card-lbl">🟢 Well Stocked</div>
            </div>
            <div class="adv-card invest">
                <div class="adv-card-num" style="font-size:16px;">
                    RWF <?php echo number_format($est_invest, 0); ?>
                </div>
                <div class="adv-card-lbl">💰 Est. Investment</div>
            </div>
        </div>

        <!-- ── Budget Planner ─────────────────────────────────────────── -->
        <div class="budget-box">
            <h3>💰 Budget Planner — What can I buy today?</h3>
            <div class="budget-input-row">
                <div class="form-group">
                    <label style="font-size:12px;font-weight:600;">Amount you have (RWF)</label>
                    <input type="number" id="budgetInput" min="0" step="1"
                           placeholder="e.g. 500000"
                           style="width:100%;">
                </div>
                <button class="btn btn-primary" onclick="runBudgetPlan()">Plan My Shopping</button>
                <button class="btn btn-secondary" onclick="clearPlan()" id="clearBtn" style="display:none;">Clear</button>
            </div>
            <div class="budget-result" id="budgetResult" style="display:none;"></div>
        </div>

        <?php if (empty($products)): ?>
        <div class="no-data">
            <div class="no-data-icon">📦</div>
            <p>No product data found for this period.</p>
        </div>
        <?php else: ?>

        <!-- Filter bar -->
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
            <input type="text" id="advSearch" placeholder="Search product…"
                   oninput="filterTable()"
                   style="flex:1;min-width:160px;max-width:280px;padding:7px 12px;border:1px solid var(--gray-200);border-radius:8px;font-size:13px;">
            <select id="advCat" onchange="filterTable()"
                    style="padding:7px 12px;border:1px solid var(--gray-200);border-radius:8px;font-size:13px;background:var(--white);">
                <option value="">All categories</option>
                <?php
                $cats = array_unique(array_filter(array_column($products, 'category')));
                sort($cats);
                foreach ($cats as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="advStatus" onchange="filterTable()"
                    style="padding:7px 12px;border:1px solid var(--gray-200);border-radius:8px;font-size:13px;background:var(--white);">
                <option value="">All statuses</option>
                <option value="out">Out of Stock</option>
                <option value="urgent">Order Now</option>
                <option value="soon">Order Soon</option>
                <option value="monitor">Monitor</option>
                <option value="ok">Well Stocked</option>
            </select>
            <select id="advSort" onchange="sortTable()"
                    style="padding:7px 12px;border:1px solid var(--gray-200);border-radius:8px;font-size:13px;background:var(--white);">
                <option value="revenue-desc">Revenue ↓</option>
                <option value="urgency-desc">Urgency ↓</option>
                <option value="revshare-desc">Rev Share ↓</option>
                <option value="daily-desc">Daily Sales ↓</option>
                <option value="days-asc">Stock Left ↑ (critical first)</option>
                <option value="revenue-asc">Revenue ↑</option>
            </select>
            <button class="btn btn-secondary" onclick="exportCSV()" style="margin-left:auto;font-size:12px;">⬇ Export CSV</button>
        </div>

        <div class="table-responsive">
            <table class="table adv-table" id="advTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product</th>
                        <th>Stock Left</th>
                        <th>Daily Sales</th>
                        <th>Revenue (<?php echo $days; ?>d)</th>
                        <th>Rev Share</th>
                        <th>Status</th>
                        <th>Advice</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $i => $p):
                    // Days bar width (capped at 60 days = 100%)
                    $bar_pct = $p['days_left'] === PHP_INT_MAX
                        ? 100
                        : min(100, round($p['days_left'] / 60 * 100));

                    // Days left label
                    if ($p['days_left'] === PHP_INT_MAX) {
                        $days_lbl = '∞';
                    } elseif ($p['days_left'] >= 1) {
                        $days_lbl = round($p['days_left']) . 'd';
                    } else {
                        $days_lbl = '0d';
                    }

                    // WHAT advice
                    $what = htmlspecialchars($p['name']);

                    // HOW MUCH advice
                    if ($p['suggested'] > 0) {
                        $how = $p['suggested'] . ' pkg' . ($p['suggested'] > 1 ? 's' : '');
                        if ($p['last_cost'] > 0) {
                            $how .= ' ≈ RWF ' . number_format($p['suggested'] * $p['last_cost'], 0);
                        }
                    } else {
                        $how = 'No restock needed';
                    }

                    // WHEN advice
                    $when_cls = 'when-' . $p['cls'];
                    switch ($p['tier']) {
                        case 4: $when = 'Immediately — out of stock'; break;
                        case 3: $when = 'Order within ' . max(1, round($p['days_left'])) . ' day(s)'; break;
                        case 2: $when = 'Order within ~' . max(1, round($p['days_left'])) . ' day(s)'; break;
                        case 1: $when = 'Plan order this month'; break;
                        default: $when = 'Not urgent'; break;
                    }
                ?>
                <tr data-name="<?php echo strtolower(htmlspecialchars($p['name'])); ?>"
                    data-cat="<?php echo strtolower(htmlspecialchars($p['category'] ?? '')); ?>"
                    data-cls="<?php echo $p['cls']; ?>"
                    data-revenue="<?php echo $p['revenue']; ?>"
                    data-revshare="<?php echo $p['rev_share']; ?>"
                    data-daily="<?php echo round($p['daily'], 2); ?>"
                    data-days="<?php echo $p['days_left'] === PHP_INT_MAX ? 9999999 : round($p['days_left'], 1); ?>"
                    data-tier="<?php echo $p['tier']; ?>">
                    <td style="color:var(--secondary);font-size:12px;"><?php echo $i + 1; ?></td>
                    <td>
                        <div style="font-weight:600;font-size:13px;"><?php echo htmlspecialchars($p['name']); ?></div>
                        <div style="font-size:11px;color:var(--secondary);"><?php echo htmlspecialchars($p['category'] ?: '—'); ?></div>
                    </td>
                    <td style="min-width:120px;">
                        <div class="days-bar-wrap">
                            <div class="days-bar-bg">
                                <div class="days-bar-fill fill-<?php echo $p['cls']; ?>"
                                     style="width:<?php echo $bar_pct; ?>%"></div>
                            </div>
                            <span class="days-label" style="color:<?php
                                echo match($p['cls']) {
                                    'out'     => '#7c3aed',
                                    'urgent'  => '#dc2626',
                                    'soon'    => '#b45309',
                                    'monitor' => '#1d4ed8',
                                    default   => '#065f46',
                                };
                            ?>"><?php echo $days_lbl; ?></span>
                        </div>
                        <div style="font-size:10px;color:var(--secondary);margin-top:3px;">
                            <?php echo number_format($p['stock_pcs']); ?> pcs in stock
                        </div>
                    </td>
                    <td>
                        <div style="font-size:13px;font-weight:600;">
                            <?php echo $p['daily'] > 0 ? number_format($p['daily'], 1) : '—'; ?> pcs/day
                        </div>
                        <div style="font-size:11px;color:var(--secondary);">
                            <?php if ($p['bulk_sold']): ?>Bulk: <?php echo number_format($p['bulk_sold']); ?> pkg<?php endif; ?>
                            <?php if ($p['bulk_sold'] && $p['retail_sold']): ?> · <?php endif; ?>
                            <?php if ($p['retail_sold']): ?>Retail: <?php echo number_format($p['retail_sold']); ?> pcs<?php endif; ?>
                            <?php if (!$p['bulk_sold'] && !$p['retail_sold']): ?>No sales<?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-size:13px;font-weight:600;">
                            <?php echo $p['revenue'] > 0 ? 'RWF ' . number_format($p['revenue'], 0) : '—'; ?>
                        </div>
                    </td>
                    <td style="min-width:90px;">
                        <div class="rev-bar-wrap">
                            <div class="rev-bar-bg">
                                <div class="rev-bar-fill" style="width:<?php echo min(100, round($p['rev_share'] / $max_rev_share * 100)); ?>%"></div>
                            </div>
                            <span><?php echo $p['rev_share']; ?>%</span>
                        </div>
                    </td>
                    <td>
                        <span class="badge badge-<?php echo $p['cls']; ?>">
                            <?php echo $p['badge']; ?>
                        </span>
                        <?php if ($p['last_buy']): ?>
                        <div style="font-size:10px;color:var(--secondary);margin-top:4px;">
                            Last bought: <?php echo date('M d, Y', strtotime($p['last_buy'])); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td style="min-width:200px;">
                        <div class="advice-what">📦 <?php echo $what; ?></div>
                        <?php if ($p['suggested'] > 0): ?>
                        <div class="advice-how">🛒 <?php echo $how; ?></div>
                        <?php else: ?>
                        <div class="advice-how" style="color:var(--secondary);">🛒 <?php echo $how; ?></div>
                        <?php endif; ?>
                        <div class="advice-when <?php echo $when_cls; ?>">⏰ <?php echo $when; ?></div>
                    </td>
                    <td>
                        <?php if ($p['last_purchase_id']): ?>
                        <a href="new-purchase.php?repeat=<?php echo $p['last_purchase_id']; ?>"
                           class="btn btn-secondary"
                           style="font-size:11px;padding:4px 10px;white-space:nowrap;">
                            🛒 Buy
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:12px;font-size:11px;color:var(--secondary);">
            * Stock left = current stock ÷ average daily sales over last <?php echo $days; ?> days.
            Suggested quantity targets 30 days of coverage from today.
        </div>
        <?php endif; ?>

    </div>
</div>
<script src="script.js"></script>
<script>
var PRODUCTS = <?php
    echo json_encode(array_map(fn($p) => [
        'id'        => $p['id'],
        'name'      => $p['name'],
        'category'  => $p['category'],
        'suggested' => $p['suggested'],
        'last_cost' => $p['last_cost'],
        'pkg_price' => $p['pkg_price'],
        'tier'      => $p['tier'],
        'badge'     => $p['badge'],
        'cls'       => $p['cls'],
        'revenue'   => $p['revenue'],
        'rev_share' => $p['rev_share'],
    ], $products), JSON_HEX_TAG | JSON_HEX_AMP);
?>;

function fmt(n) {
    return 'RWF ' + Math.round(n).toLocaleString();
}

function runBudgetPlan() {
    var budget = parseFloat(document.getElementById('budgetInput').value);
    if (!budget || budget <= 0) {
        alert('Please enter a valid budget amount.');
        return;
    }

    var remaining  = budget;
    var plan       = [];   // { product, buy, cost, full }
    var skipped    = [];   // { product, reason }

    // Products are already sorted by tier desc → revenue desc (from PHP)
    // Only consider products that need restocking and have a known cost
    PRODUCTS.forEach(function(p) {
        if (p.suggested <= 0 || p.last_cost <= 0) return;

        var can_afford = Math.floor(remaining / p.last_cost);
        if (can_afford <= 0) {
            skipped.push({ p: p, reason: 'Budget exhausted' });
            return;
        }

        var buy  = Math.min(p.suggested, can_afford);
        var cost = buy * p.last_cost;
        remaining -= cost;

        plan.push({
            p:    p,
            buy:  buy,
            cost: cost,
            full: buy >= p.suggested
        });
    });

    renderPlan(budget, remaining, plan, skipped);
    document.getElementById('clearBtn').style.display = '';
}

function renderPlan(budget, remaining, plan, skipped) {
    var spent = budget - remaining;
    var box   = document.getElementById('budgetResult');

    if (plan.length === 0 && skipped.length === 0) {
        box.innerHTML = '<p style="color:var(--secondary);">No products need restocking at the moment.</p>';
        box.style.display = '';
        return;
    }

    var clsMap = { out:'badge-out', urgent:'badge-urgent', soon:'badge-soon', monitor:'badge-monitor', ok:'badge-ok' };

    var rows = '';
    var cumulative = 0;

    plan.forEach(function(item, i) {
        cumulative += item.cost;
        var rowCls  = item.full ? 'plan-row-full' : 'plan-row-partial';
        var qtyCls  = item.full ? 'qty-full'      : 'qty-partial';
        var qtyTxt  = item.buy + ' pkg' + (item.buy !== 1 ? 's' : '');
        if (!item.full) qtyTxt += ' <span style="color:#9ca3af;font-weight:400;">of ' + item.p.suggested + '</span>';
        rows += '<tr class="' + rowCls + '">'
            + '<td>' + (i + 1) + '</td>'
            + '<td><strong>' + esc(item.p.name) + '</strong>'
            + '<div style="font-size:10px;color:var(--secondary);">' + esc(item.p.category || '') + '</div></td>'
            + '<td><span class="badge ' + clsMap[item.p.cls] + '">' + item.p.badge + '</span></td>'
            + '<td class="' + qtyCls + '">' + qtyTxt + '</td>'
            + '<td>' + fmt(item.p.last_cost) + '/pkg</td>'
            + '<td style="font-weight:700;">' + fmt(item.cost) + '</td>'
            + '<td style="color:var(--secondary);">' + fmt(cumulative) + '</td>'
            + '</tr>';
    });

    // Skipped rows
    skipped.forEach(function(item) {
        rows += '<tr class="plan-row-skip">'
            + '<td>—</td>'
            + '<td>' + esc(item.p.name) + '</td>'
            + '<td><span class="badge ' + clsMap[item.p.cls] + '">' + item.p.badge + '</span></td>'
            + '<td class="qty-skip">' + item.p.suggested + ' pkg' + (item.p.suggested !== 1 ? 's' : '') + '</td>'
            + '<td>' + fmt(item.p.last_cost) + '/pkg</td>'
            + '<td class="skip-reason">' + item.reason + '</td>'
            + '<td class="skip-reason">' + fmt(item.p.suggested * item.p.last_cost) + ' needed</td>'
            + '</tr>';
    });

    box.innerHTML =
        '<div class="budget-result-header">'
        + '<h4>Shopping Plan</h4>'
        + '<div class="budget-pills">'
        + '<span class="budget-pill pill-count">' + plan.length + ' product' + (plan.length !== 1 ? 's' : '') + ' selected</span>'
        + '<span class="budget-pill pill-spend">Spend: ' + fmt(spent) + '</span>'
        + '<span class="budget-pill pill-left">Remaining: ' + fmt(remaining) + '</span>'
        + '</div>'
        + '</div>'
        + '<div class="table-responsive">'
        + '<table class="table plan-table">'
        + '<thead><tr>'
        + '<th>#</th><th>Product</th><th>Status</th>'
        + '<th>Qty to Buy</th><th>Cost/Pkg</th><th>Total Cost</th><th>Cumulative</th>'
        + '</tr></thead>'
        + '<tbody>' + rows + '</tbody>'
        + '<tfoot><tr>'
        + '<td colspan="5"><strong>Total</strong></td>'
        + '<td><strong>' + fmt(spent) + '</strong></td>'
        + '<td style="color:var(--secondary);">/ ' + fmt(budget) + '</td>'
        + '</tr></tfoot>'
        + '</table></div>'
        + (skipped.length > 0
            ? '<p style="font-size:11px;color:var(--secondary);margin-top:6px;">Greyed rows = budget ran out before these could be included.</p>'
            : '');

    box.style.display = '';
}

function clearPlan() {
    document.getElementById('budgetResult').style.display = 'none';
    document.getElementById('budgetInput').value = '';
    document.getElementById('clearBtn').style.display = 'none';
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function sortTable() {
    var val   = document.getElementById('advSort').value;
    var tbody = document.querySelector('#advTable tbody');
    var rows  = Array.from(tbody.querySelectorAll('tr'));

    rows.sort(function(a, b) {
        var d = a.dataset, e = b.dataset;
        switch (val) {
            case 'revenue-desc':  return parseFloat(e.revenue)  - parseFloat(d.revenue);
            case 'revenue-asc':   return parseFloat(d.revenue)  - parseFloat(e.revenue);
            case 'revshare-desc': return parseFloat(e.revshare) - parseFloat(d.revshare);
            case 'daily-desc':    return parseFloat(e.daily)    - parseFloat(d.daily);
            case 'days-asc':      return parseFloat(d.days)     - parseFloat(e.days);
            case 'urgency-desc':
                var td = parseInt(e.tier) - parseInt(d.tier);
                return td !== 0 ? td : parseFloat(e.revenue) - parseFloat(d.revenue);
            default: return 0;
        }
    });
    rows.forEach(function(r) { tbody.appendChild(r); });
    filterTable();
}

function filterTable() {
    var search = document.getElementById('advSearch').value.toLowerCase();
    var cat    = document.getElementById('advCat').value.toLowerCase();
    var status = document.getElementById('advStatus').value;
    var rows   = document.querySelectorAll('#advTable tbody tr');
    var idx    = 1;
    rows.forEach(function(row) {
        var name = row.dataset.name || '';
        var rc   = row.dataset.cat  || '';
        var cls  = row.dataset.cls  || '';
        var show = (!search || name.includes(search))
                && (!cat    || rc === cat)
                && (!status || cls === status);
        row.style.display = show ? '' : 'none';
        if (show) row.cells[0].textContent = idx++;
    });
}

function exportCSV() {
    var headers = ['#','Product','Category','Stock (pcs)','Daily Sales (pcs/day)','Revenue','Rev Share %','Status','Suggested (pkgs)','Cost/pkg'];
    var rows = [headers];
    PRODUCTS.forEach(function(p, i) {
        rows.push([
            i + 1,
            p.name,
            p.category || '',
            '',
            '',
            p.revenue,
            p.rev_share !== undefined ? p.rev_share : '',
            p.badge,
            p.suggested,
            p.last_cost
        ]);
    });
    var csv = rows.map(function(r) {
        return r.map(function(c) {
            var s = String(c);
            return s.includes(',') || s.includes('"') ? '"' + s.replace(/"/g,'""') + '"' : s;
        }).join(',');
    }).join('\n');
    var a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = 'purchase_advice.csv';
    a.click();
}
</script>
</body>
</html>
