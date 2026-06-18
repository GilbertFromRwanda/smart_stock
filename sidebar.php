<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'staff';
$_lang_code = $_LANG_CODE;

$_sub_info = sub_get_latest($conn);
$_sub_days = $_sub_info ? (int)$_sub_info['days_left'] : -999;
$_sub_warn = $_sub_days >= 0 && $_sub_days <= 14;

$nav = [
    'main' => [
        'label' => t('nav_main'),
        'items' => [
            ['href' => 'dashboard.php',       'icon' => '▣',  'label' => t('nav_dashboard')],
            ['href' => 'products.php',         'icon' => '◫',  'label' => t('nav_products')],
            ['href' => 'stock.php',            'icon' => '⊞',  'label' => t('nav_stock')],
            ['href' => 'purchases.php',        'icon' => '⤵',  'label' => t('nav_purchases')],
            ['href' => 'purchase_advice.php',  'icon' => '💡', 'label' => t('nav_purchase_advice')],
            ['href' => 'sales.php',            'icon' => '⤴',  'label' => 'Sales – View All'],
            ['href' => 'sale_bulk.php',        'icon' => '⤴',  'label' => 'New Bulk Sale'],
            ['href' => 'sale_retail.php',      'icon' => '⤴',  'label' => 'New Retail Sale'],
            ['href' => 'suppliers.php',        'icon' => '⊙',  'label' => t('nav_suppliers')],
            ['href' => 'wishlist.php',         'icon' => '★',  'label' => 'Wishlist'],
            ['href' => 'notes.php',            'icon' => '📝', 'label' => 'Notes'],
        ]
    ],
    'finance' => [
        'label' => t('nav_finance'),
        'items' => [
            ['href' => 'consumption.php', 'icon' => '⌂', 'label' => t('nav_home_consumption')],
            ['href' => 'expenses.php',    'icon' => '−', 'label' => t('nav_expenses')],
            ['href' => 'loans.php',       'icon' => '⇄', 'label' => t('nav_loans')],
            ['href' => 'boaster.php',     'icon' => '↑', 'label' => t('nav_top_up')],
        ]
    ],
    'reports' => [
        'label' => t('nav_reports'),
        'roles' => ['admin', 'manager'],
        'items' => [
            ['href' => 'summary-revenue.php', 'icon' => '◈', 'label' => t('nav_revenue_summary')],
            ['href' => 'revenue.php',         'icon' => '◉', 'label' => t('nav_profit_analysis')],
        ]
    ],
    'admin' => [
        'label' => t('nav_admin'),
        'roles' => ['admin', 'manager'],
        'items' => [
            ['href' => 'users.php',        'icon' => '◎', 'label' => t('nav_users'),       'roles_item' => ['admin','manager']],
            ['href' => 'audit_log.php',    'icon' => '⊘', 'label' => 'Audit Log',           'roles_item' => ['admin','manager']],
            ['href' => 'database.php',     'icon' => '⊗', 'label' => t('nav_database'),    'roles_item' => ['admin']],
            ['href' => 'subscription.php', 'icon' => '🔑', 'label' => t('sub_nav_label'),   'roles_item' => ['admin']],
            ['href' => 'run_update.php',   'icon' => '⚙', 'label' => t('nav_run_updates'), 'roles_item' => ['admin']],
        ]
    ],
];

// Determine which section is active
$active_section = null;
$sales_pages = ['sales.php','sale_bulk.php','sale_retail.php'];
foreach ($nav as $key => $section) {
    if (isset($section['roles']) && !in_array($role, $section['roles'])) continue;
    foreach ($section['items'] as $item) {
        if (isset($item['roles_item']) && !in_array($role, $item['roles_item'])) continue;
        if ($current_page === $item['href']) { $active_section = $key; break 2; }
    }
}
?>
<div class="topbar" id="topbar">

    <!-- Brand -->
    <a href="dashboard.php" class="topbar-brand">
        <div class="topbar-brand-icon">S</div>
        <div>
            <span class="topbar-brand-name">UO &amp; GN</span>
            <span class="topbar-brand-sub">Boutique</span>
        </div>
    </a>

    <!-- Mobile hamburger -->
    <button class="topbar-hamburger" onclick="tbToggleMobile()" aria-label="Menu">☰</button>

    <!-- Nav groups -->
    <nav class="topbar-nav" id="topbarNav">
        <?php foreach ($nav as $sec_key => $section): ?>
        <?php if (isset($section['roles']) && !in_array($role, $section['roles'])) continue; ?>
        <?php $sec_active = ($active_section === $sec_key); ?>
        <div class="tb-group">
            <button type="button"
                    class="tb-btn<?php echo $sec_active ? ' active' : ''; ?>"
                    onclick="tbToggle(this)">
                <?php echo $section['label']; ?>
                <?php if ($sec_key === 'admin' && $_sub_warn): ?>
                <span class="tb-sub-dot<?php echo $_sub_days <= 3 ? ' critical' : ''; ?>"></span>
                <?php endif; ?>
                <span class="tb-chevron">▾</span>
            </button>
            <div class="tb-dropdown">
                <?php foreach ($section['items'] as $item): ?>
                <?php if (isset($item['roles_item']) && !in_array($role, $item['roles_item'])) continue; ?>
                <a href="<?php echo $item['href']; ?>"
                   class="tb-drop-item<?php echo $current_page === $item['href'] ? ' active' : ''; ?>">
                    <span class="tb-drop-icon"><?php echo $item['icon']; ?></span>
                    <?php echo $item['label']; ?>
                </a>
                <?php endforeach; ?>
                <?php if ($sec_key === 'admin' && $_sub_warn): ?>
                <div class="tb-sub-warn <?php echo $_sub_days <= 3 ? 'critical' : ''; ?>">
                    🔑 <?php echo $_sub_days <= 0 ? t('sub_expired_label') : $_sub_days . ' ' . t('sub_days_left'); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </nav>

    <!-- Right: lang + user -->
    <div class="topbar-right" id="topbarRight">
        <div class="tb-lang">
            <?php foreach (['en' => 'EN', 'fr' => 'FR', 'rw' => 'RW'] as $code => $lbl): ?>
            <a href="set_lang.php?lang=<?php echo $code; ?>"
               class="lang-btn<?php echo $_lang_code === $code ? ' lang-active' : ''; ?>"><?php echo $lbl; ?></a>
            <?php endforeach; ?>
        </div>

        <div class="tb-group">
            <button type="button" class="tb-user-btn" onclick="tbToggle(this)">
                <span class="tb-avatar"><?php echo strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'U', 0, 1)); ?></span>
                <span class="tb-uname"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></span>
                <span class="tb-chevron">▾</span>
            </button>
            <div class="tb-dropdown tb-dropdown-right">
                <div class="tb-drop-label"><?php echo ucfirst($role); ?></div>
                <a href="logout.php" class="tb-drop-item danger">⏻ <?php echo t('btn_close') ?: 'Logout'; ?></a>
            </div>
        </div>
    </div>
</div>

<!-- ── Quick menu ── -->
<div class="quick-menu">
    <?php
    $quick = [
        ['href' => 'dashboard.php',      'icon' => '▣', 'label' => t('nav_dashboard')],
        ['href' => 'sale_bulk.php',      'icon' => '⤴', 'label' => 'Bulk Sale'],
        ['href' => 'sale_retail.php',    'icon' => '⤴', 'label' => 'Retail Sale'],
        ['href' => 'sales.php',          'icon' => '⤴', 'label' => t('nav_sales')],
        ['href' => 'purchases.php',      'icon' => '⤵', 'label' => t('nav_purchases')],
        ['href' => 'stock.php',          'icon' => '⊞', 'label' => t('nav_stock')],
        ['href' => 'stock_adjust.php',   'icon' => '✏', 'label' => 'Adjust Stock'],
        ['href' => 'purchase_advice.php','icon' => '💡', 'label' => t('nav_purchase_advice')],
        ['href' => 'expenses.php',       'icon' => '−', 'label' => t('nav_expenses')],
        ['href' => 'loans.php',          'icon' => '⇄', 'label' => t('nav_loans')],
    ];
    foreach ($quick as $q):
        $a = $current_page === $q['href'] ? ' active' : '';
    ?>
    <a href="<?php echo $q['href']; ?>" class="qm-link<?php echo $a; ?>">
        <span class="qm-icon"><?php echo $q['icon']; ?></span>
        <span class="qm-label"><?php echo $q['label']; ?></span>
    </a>
    <?php endforeach; ?>
</div>

<style>
/* ── Top bar ─────────────────────────────────────────────────────────────── */
.topbar {
    position: fixed; top: 0; left: 0; right: 0; height: 56px;
    background: #0f172a;
    display: flex; align-items: center;
    padding: 0 14px; gap: 4px;
    z-index: 1000;
    box-shadow: 0 2px 16px rgba(0,0,0,.3);
}

/* Brand */
.topbar-brand {
    display: flex; align-items: center; gap: 10px;
    text-decoration: none; flex-shrink: 0;
    padding-right: 14px;
    border-right: 1px solid rgba(255,255,255,.1);
    margin-right: 4px;
}
.topbar-brand-icon {
    width: 32px; height: 32px; border-radius: 8px;
    background: linear-gradient(135deg,#3b82f6,#6366f1);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 800; color: #fff; flex-shrink: 0;
}
.topbar-brand-name { font-size: 13px; font-weight: 700; color: #f1f5f9; display: block; line-height: 1.15; }
.topbar-brand-sub  { font-size: 10px; color: #64748b; display: block; }

/* Nav */
.topbar-nav {
    display: flex; align-items: center; gap: 2px; flex: 1;
}

/* Groups / buttons */
.tb-group { position: relative; }

.tb-btn {
    display: flex; align-items: center; gap: 5px;
    padding: 6px 11px; border-radius: 7px;
    background: none; border: none; cursor: pointer; font-family: inherit;
    font-size: 13px; font-weight: 500; color: #94a3b8;
    transition: background .14s, color .14s; white-space: nowrap;
}
.tb-btn:hover { background: rgba(255,255,255,.08); color: #f1f5f9; }
.tb-btn.active { color: #93c5fd; }

.tb-user-btn {
    display: flex; align-items: center; gap: 7px;
    padding: 5px 10px 5px 5px; border-radius: 8px;
    background: none; border: none; cursor: pointer; font-family: inherit;
    color: #94a3b8; transition: background .14s, color .14s;
}
.tb-user-btn:hover { background: rgba(255,255,255,.08); color: #f1f5f9; }

.tb-chevron { font-size: 10px; opacity: .55; pointer-events: none; }

/* Subscription dot on admin btn */
.tb-sub-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: #f59e0b; flex-shrink: 0;
}
.tb-sub-dot.critical { background: #ef4444; }

/* Dropdown */
.tb-dropdown {
    display: none;
    position: absolute; top: calc(100% + 8px); left: 0;
    background: #1e293b; border: 1px solid rgba(255,255,255,.1);
    border-radius: 10px; padding: 6px;
    min-width: 210px;
    box-shadow: 0 10px 36px rgba(0,0,0,.4);
    z-index: 2000;
}
.tb-dropdown-right { left: auto; right: 0; }
/* hover open — desktop */
.tb-group:hover .tb-dropdown { display: block; }
/* invisible bridge fills the gap between button and dropdown so mouse-move doesn't close it */
.tb-dropdown::before {
    content: ''; position: absolute; top: -8px; left: 0; right: 0; height: 8px;
}

.tb-drop-item {
    display: flex; align-items: center; gap: 9px;
    padding: 8px 12px; border-radius: 7px;
    font-size: 13px; font-weight: 500; color: #94a3b8;
    text-decoration: none;
    transition: background .12s, color .12s; white-space: nowrap;
}
.tb-drop-item:hover { background: rgba(255,255,255,.07); color: #f1f5f9; }
.tb-drop-item.active { color: #93c5fd; background: rgba(59,130,246,.15); }
.tb-drop-item.danger:hover { color: #f87171; background: rgba(248,113,113,.1); }

.tb-drop-icon { font-size: 13px; width: 18px; text-align: center; opacity: .75; flex-shrink: 0; }

.tb-drop-label {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .8px; color: #475569; padding: 5px 12px 3px;
}

.tb-sub-warn {
    margin: 4px 6px 2px; padding: 6px 10px; border-radius: 7px;
    font-size: 11px; font-weight: 700; color: #fbbf24;
    background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.2);
}
.tb-sub-warn.critical { color: #f87171; background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.2); }

/* Right side */
.topbar-right {
    display: flex; align-items: center; gap: 8px;
    flex-shrink: 0; margin-left: auto;
    padding-left: 12px;
    border-left: 1px solid rgba(255,255,255,.09);
}

/* Lang */
.tb-lang { display: flex; align-items: center; gap: 4px; }
.lang-btn {
    font-size: 11px; font-weight: 700; color: #64748b;
    text-decoration: none; padding: 3px 7px; border-radius: 5px;
    border: 1px solid rgba(255,255,255,.08);
    transition: background .14s, color .14s, border-color .14s; letter-spacing: .5px;
}
.lang-btn:hover { color: #f1f5f9; background: rgba(255,255,255,.08); }
.lang-btn.lang-active { color: #93c5fd; background: rgba(59,130,246,.18); border-color: rgba(59,130,246,.4); }

/* Avatar */
.tb-avatar {
    width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg,#3b82f6,#6366f1);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700; color: #fff;
}
.tb-uname {
    font-size: 13px; font-weight: 500; color: inherit;
    max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

/* Hamburger (mobile only) */
.topbar-hamburger {
    display: none; background: none; border: none; cursor: pointer;
    font-size: 20px; color: #94a3b8; padding: 4px 8px; margin-left: auto;
}

/* ── Quick menu ──────────────────────────────────────────────────────────── */
.quick-menu {
    position: fixed; top: 56px; left: 0; right: 0;
    height: 38px;
    background: #fff; border-bottom: 1px solid var(--gray-200);
    display: flex; align-items: center;
    padding: 0 20px; gap: 2px;
    z-index: 999;
    overflow-x: auto; overflow-y: hidden;
    scrollbar-width: none;
}
.quick-menu::-webkit-scrollbar { display: none; }

.qm-link {
    display: flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 6px;
    font-size: 12px; font-weight: 500; color: var(--secondary);
    text-decoration: none; white-space: nowrap;
    transition: background .13s, color .13s;
    flex-shrink: 0;
}
.qm-link:hover { background: var(--gray-100); color: var(--dark); }
.qm-link.active { background: #eff6ff; color: #2563eb; font-weight: 600; }

.qm-icon { font-size: 12px; opacity: .7; }
.qm-label { }

/* ── Layout reset ────────────────────────────────────────────────────────── */
.dashboard-container { display: block !important; }
.main-content { margin-left: 0 !important; margin-top: 94px; padding: 28px; }

/* ── Mobile ──────────────────────────────────────────────────────────────── */
@media (max-width: 840px) {
    .topbar { flex-wrap: wrap; height: auto; padding: 10px 14px 0; gap: 0; }
    .topbar-hamburger { display: flex; margin-left: auto; margin-bottom: 10px; }
    .topbar-nav, .topbar-right {
        display: none; width: 100%; flex-direction: column; align-items: stretch;
        border: none; padding: 6px 0 10px; gap: 2px;
    }
    .topbar-right { border-left: none; border-top: 1px solid rgba(255,255,255,.08); padding-top: 10px; flex-direction: row; flex-wrap: wrap; }
    .topbar-nav.mob-open, .topbar-right.mob-open { display: flex; }
    .tb-group { width: 100%; }
    .tb-btn, .tb-user-btn { width: 100%; justify-content: flex-start; }
    .tb-group:hover .tb-dropdown { display: none; }
    .tb-group.open  .tb-dropdown { display: block; }
    .tb-dropdown { position: static; box-shadow: none; border: none;
        background: rgba(0,0,0,.25); border-radius: 7px; margin-top: 2px; min-width: 0; }
    .tb-dropdown-right { right: auto; }
    .main-content { margin-top: 94px; padding: 16px; }
    .quick-menu { top: 56px; }
    .topbar-brand { margin-bottom: 10px; }
}
</style>
<script>
/* Mobile: tap to open/close (hover doesn't work on touch) */
function tbToggle(btn) {
    var group = btn.closest('.tb-group');
    var wasOpen = group.classList.contains('open');
    document.querySelectorAll('.tb-group.open').forEach(function(g){ g.classList.remove('open'); });
    if (!wasOpen) group.classList.add('open');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.tb-group')) {
        document.querySelectorAll('.tb-group.open').forEach(function(g){ g.classList.remove('open'); });
    }
});

function tbToggleMobile() {
    document.getElementById('topbarNav').classList.toggle('mob-open');
    document.getElementById('topbarRight').classList.toggle('mob-open');
}
</script>
