<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'staff';
$_lang_code = $_LANG_CODE;

// Subscription expiry info (for admin badge)
$_sub_info = sub_get_latest($conn);
$_sub_days = $_sub_info ? (int)$_sub_info['days_left'] : -999;
$_sub_warn = $_sub_days >= 0 && $_sub_days <= 14; // show badge within 14 days

$nav = [
    'main' => [
        'label' => t('nav_main'),
        'items' => [
            ['href' => 'dashboard.php',  'icon' => '▣',  'label' => t('nav_dashboard')],
            ['href' => 'products.php',   'icon' => '◫',  'label' => t('nav_products')],
            ['href' => 'stock.php',      'icon' => '⊞',  'label' => t('nav_stock')],
            ['href' => 'purchases.php',  'icon' => '⤵',  'label' => t('nav_purchases')],
            ['href' => 'sales.php',      'icon' => '⤴',  'label' => t('nav_sales')],
            ['href' => 'suppliers.php',  'icon' => '⊙',  'label' => t('nav_suppliers')],
        ]
    ],
    'finance' => [
        'label' => t('nav_finance'),
        'items' => [
            ['href' => 'consumption.php','icon' => '⌂',  'label' => t('nav_home_consumption')],
            ['href' => 'expenses.php',   'icon' => '−',  'label' => t('nav_expenses')],
            ['href' => 'loans.php',      'icon' => '⇄',  'label' => t('nav_loans')],
            ['href' => 'boaster.php',    'icon' => '↑',  'label' => t('nav_top_up')],
        ]
    ],
    'reports' => [
        'label' => t('nav_reports'),
        'roles' => ['admin', 'manager'],
        'items' => [
            ['href' => 'summary-revenue.php','icon' => '◈', 'label' => t('nav_revenue_summary')],
            ['href' => 'revenue.php',        'icon' => '◉', 'label' => t('nav_profit_analysis')],
        ]
    ],
    'admin' => [
        'label' => t('nav_admin'),
        'roles' => ['admin', 'manager'],
        'items' => [
            ['href' => 'users.php',        'icon' => '◎', 'label' => t('nav_users'),       'roles_item' => ['admin','manager']],
            ['href' => 'database.php',     'icon' => '⊗', 'label' => t('nav_database'),    'roles_item' => ['admin']],
            ['href' => 'subscription.php', 'icon' => '🔑', 'label' => t('sub_nav_label'),   'roles_item' => ['admin']],
            ['href' => 'run_update.php',   'icon' => '⚙', 'label' => t('nav_run_updates'), 'roles_item' => ['admin']],
        ]
    ],
];
?>
<div class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon">S</div>
        <div>
            <div class="sidebar-brand-name">UO &amp; GN</div>
            <div class="sidebar-brand-sub">Boutique</div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <?php foreach ($nav as $section): ?>
            <?php if (isset($section['roles']) && !in_array($role, $section['roles'])) continue; ?>
            <div class="nav-section-label"><?php echo $section['label']; ?></div>
            <?php foreach ($section['items'] as $item): ?>
                <?php
                if (isset($item['roles_item']) && !in_array($role, $item['roles_item'])) continue;
                $active = $current_page === $item['href'];
                ?>
                <a href="<?php echo $item['href']; ?>" class="nav-item<?php echo $active ? ' active' : ''; ?>">
                    <span class="nav-icon"><?php echo $item['icon']; ?></span>
                    <span class="nav-label"><?php echo $item['label']; ?></span>
                    <?php if ($active): ?><span class="nav-active-dot"></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <!-- Subscription expiry warning (admin only, within 14 days) -->
    <?php if ($role === 'admin' && $_sub_warn): ?>
    <a href="subscription.php" class="sub-expiry-bar <?php echo $_sub_days <= 3 ? 'critical' : ''; ?>">
        <span>🔑</span>
        <span>
            <?php if ($_sub_days <= 0): ?>
                <?php echo t('sub_expired_label'); ?>
            <?php else: ?>
                <?php echo $_sub_days; ?> <?php echo t('sub_days_left'); ?>
            <?php endif; ?>
        </span>
    </a>
    <?php endif; ?>

    <!-- Language switcher -->
    <div class="sidebar-lang">
        <?php
        $langs = ['en' => 'EN', 'fr' => 'FR', 'rw' => 'RW'];
        foreach ($langs as $code => $label):
            $active_cls = ($_lang_code === $code) ? ' lang-active' : '';
        ?>
        <a href="set_lang.php?lang=<?php echo $code; ?>" class="lang-btn<?php echo $active_cls; ?>"
           title="<?php echo t('lang_' . $code); ?>"><?php echo $label; ?></a>
        <?php endforeach; ?>
    </div>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-avatar"><?php echo strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'U', 0, 1)); ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></div>
                <div class="sidebar-user-role"><?php echo ucfirst($role); ?></div>
            </div>
        </div>
        <a href="logout.php" class="sidebar-logout" title="<?php echo t('btn_close'); ?>">⏻</a>
    </div>
</div>

<style>
/* ── Sidebar redesign ─────────────────────────────────────────────────────── */
.sidebar {
    width: 240px;
    background: #0f172a;
    color: #cbd5e1;
    padding: 0;
    position: fixed;
    height: 100vh;
    display: flex;
    flex-direction: column;
    box-shadow: 4px 0 24px rgba(0,0,0,.18);
    border-right: none;
    z-index: 100;
}

/* Brand */
.sidebar-brand {
    display: flex; align-items: center; gap: 12px;
    padding: 22px 20px 18px;
    border-bottom: 1px solid rgba(255,255,255,.07);
}
.sidebar-brand-icon {
    width: 38px; height: 38px; border-radius: 10px;
    background: linear-gradient(135deg,#3b82f6,#6366f1);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 800; color: #fff; flex-shrink: 0;
}
.sidebar-brand-name {
    font-size: 15px; font-weight: 700; color: #f1f5f9; line-height: 1.1;
}
.sidebar-brand-sub {
    font-size: 11px; color: #64748b; margin-top: 1px;
}

/* Nav */
.sidebar-nav {
    flex: 1; overflow-y: auto; padding: 12px 10px;
    scrollbar-width: thin; scrollbar-color: #1e293b transparent;
}
.sidebar-nav::-webkit-scrollbar { width: 4px; }
.sidebar-nav::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 4px; }

.nav-section-label {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 1px; color: #475569;
    padding: 14px 10px 6px;
}
.nav-section-label:first-child { padding-top: 4px; }

.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 12px; border-radius: 8px; margin-bottom: 2px;
    color: #94a3b8; text-decoration: none;
    font-size: 13.5px; font-weight: 500;
    transition: background .15s, color .15s;
    position: relative;
}
.nav-item:hover {
    background: rgba(255,255,255,.06);
    color: #f1f5f9;
}
.nav-item.active {
    background: linear-gradient(90deg,rgba(59,130,246,.22),rgba(99,102,241,.12));
    color: #93c5fd;
}
.nav-icon {
    font-size: 15px; width: 20px; text-align: center;
    flex-shrink: 0; opacity: .85;
}
.nav-label { flex: 1; }
.nav-active-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: #3b82f6; flex-shrink: 0;
}

/* Subscription expiry bar */
.sub-expiry-bar {
    display: flex; align-items: center; gap: 8px; justify-content: center;
    padding: 7px 14px;
    background: rgba(245,158,11,.12);
    border-top: 1px solid rgba(245,158,11,.25);
    font-size: 12px; font-weight: 700; color: #fbbf24;
    text-decoration: none; transition: background .15s;
}
.sub-expiry-bar:hover { background: rgba(245,158,11,.2); }
.sub-expiry-bar.critical { background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.25); color: #f87171; }
.sub-expiry-bar.critical:hover { background: rgba(239,68,68,.2); }

/* Language switcher */
.sidebar-lang {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    padding: 8px 14px;
    border-top: 1px solid rgba(255,255,255,.07);
}
.lang-btn {
    font-size: 11px; font-weight: 700; color: #64748b;
    text-decoration: none; padding: 4px 9px; border-radius: 6px;
    border: 1px solid rgba(255,255,255,.08);
    transition: background .15s, color .15s, border-color .15s;
    letter-spacing: .5px;
}
.lang-btn:hover { color: #f1f5f9; background: rgba(255,255,255,.08); border-color: rgba(255,255,255,.2); }
.lang-btn.lang-active { color: #93c5fd; background: rgba(59,130,246,.18); border-color: rgba(59,130,246,.4); }

/* Footer */
.sidebar-footer {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 14px;
    border-top: 1px solid rgba(255,255,255,.07);
    background: rgba(0,0,0,.15);
}
.sidebar-avatar {
    width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg,#3b82f6,#6366f1);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; color: #fff;
}
.sidebar-user { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
.sidebar-user-info { min-width: 0; }
.sidebar-user-name {
    font-size: 13px; font-weight: 600; color: #f1f5f9;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sidebar-user-role { font-size: 11px; color: #64748b; margin-top: 1px; }
.sidebar-logout {
    font-size: 18px; color: #64748b; text-decoration: none;
    flex-shrink: 0; padding: 4px; border-radius: 6px;
    transition: color .15s, background .15s;
    line-height: 1;
}
.sidebar-logout:hover { color: #f87171; background: rgba(248,113,113,.1); }

/* Adjust main content margin */
.main-content { margin-left: 240px; }

@media (max-width: 768px) {
    .sidebar { width: 100%; height: auto; position: relative; flex-direction: column; }
    .sidebar-nav { max-height: 60vh; }
    .main-content { margin-left: 0; }
}
</style>
