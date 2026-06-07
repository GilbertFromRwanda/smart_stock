<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// ── Add ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_wish'])) {
    $product_name = mysqli_real_escape_string($conn, trim($_POST['product_name']));
    $client_count = max(1, (int)$_POST['client_count']);
    if ($product_name) {
        mysqli_query($conn, "INSERT INTO wishlist (product_name, client_count) VALUES ('$product_name', $client_count)");
        $_SESSION['flash_success'] = 'Wishlist item added.';
    } else {
        $_SESSION['flash_error'] = 'Product name is required.';
    }
    header('Location: wishlist.php'); exit;
}

// ── Mark purchased ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_purchased'])) {
    $id = (int)$_POST['wish_id'];
    mysqli_query($conn, "UPDATE wishlist SET status='purchased', purchased_at=NOW() WHERE id=$id");
    $_SESSION['flash_success'] = 'Marked as purchased — product is now in stock!';
    header('Location: wishlist.php'); exit;
}

// ── Edit ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_wish'])) {
    $id           = (int)$_POST['wish_id'];
    $product_name = mysqli_real_escape_string($conn, trim($_POST['product_name']));
    $client_count = max(1, (int)$_POST['client_count']);
    if ($product_name) {
        mysqli_query($conn, "UPDATE wishlist SET product_name='$product_name', client_count=$client_count WHERE id=$id");
        $_SESSION['flash_success'] = 'Wishlist item updated.';
    }
    header('Location: wishlist.php'); exit;
}

// ── Increment client count ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['increment_wish'])) {
    $id = (int)$_POST['wish_id'];
    mysqli_query($conn, "UPDATE wishlist SET client_count = client_count + 1 WHERE id=$id");
    header('Location: wishlist.php' . (isset($_GET['show']) ? '?show='.$_GET['show'] : '')); exit;
}

// ── Decrement client count (min 1) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decrement_wish'])) {
    $id = (int)$_POST['wish_id'];
    mysqli_query($conn, "UPDATE wishlist SET client_count = GREATEST(1, client_count - 1) WHERE id=$id");
    header('Location: wishlist.php' . (isset($_GET['show']) ? '?show='.$_GET['show'] : '')); exit;
}

// ── Delete ────────────────────────────────────────────────────────────────────
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    mysqli_query($conn, "DELETE FROM wishlist WHERE id=" . (int)$_GET['delete']);
    $_SESSION['flash_success'] = 'Wishlist item deleted.';
    header('Location: wishlist.php'); exit;
}

// Flash
if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

// Filter
$show = (isset($_GET['show']) && $_GET['show'] === 'all') ? 'all' : 'pending';
$where = $show === 'all' ? '' : "WHERE status='pending'";

$rows = [];
$res  = mysqli_query($conn, "SELECT * FROM wishlist $where ORDER BY status ASC, client_count DESC, created_at DESC");
while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;

$pending_count   = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM wishlist WHERE status='pending'")))['c'];
$purchased_count = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM wishlist WHERE status='purchased'")))['c'];

$products_list = [];
$pq = mysqli_query($conn, "SELECT id, name, category FROM products WHERE deleted=0 ORDER BY category, name");
while ($p = mysqli_fetch_assoc($pq)) $products_list[] = $p;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wishlist - Smart Stock</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .page-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            flex-wrap: wrap; gap: 16px; margin-bottom: 24px;
        }
        .page-header h1 { font-size: 24px; font-weight: 700; color: var(--dark); margin: 0; }
        .page-subtitle  { font-size: 14px; color: var(--secondary); margin: 4px 0 0; }
        .ph-actions     { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        .tab-bar {
            display: flex; gap: 6px; margin-bottom: 18px;
        }
        .tab-btn {
            padding: 7px 18px; border-radius: var(--radius);
            font-size: 13px; font-weight: 600; text-decoration: none;
            border: 1px solid var(--gray-200); background: var(--white);
            color: var(--secondary); transition: all .15s;
        }
        .tab-btn:hover { background: var(--gray-100); color: var(--dark); }
        .tab-btn.active { background: var(--primary); border-color: var(--primary); color: #fff; }
        .tab-btn .badge {
            display: inline-block; background: rgba(255,255,255,.28);
            border-radius: 99px; font-size: 11px; font-weight: 700;
            padding: 1px 7px; margin-left: 5px;
        }
        .tab-btn:not(.active) .badge {
            background: var(--gray-200); color: var(--secondary);
        }

        .client-count-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #eff6ff; color: #2563eb;
            border: 1px solid #bfdbfe;
            border-radius: 99px; padding: 3px 10px;
            font-size: 12px; font-weight: 700;
        }
        .client-count-badge .icon { font-size: 13px; }

        .status-badge {
            display: inline-block; padding: 3px 10px; border-radius: 99px;
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
        }
        .status-pending   { background: #fef9c3; color: #854d0e; }
        .status-purchased { background: #dcfce7; color: #166534; }

        .btn-purchased {
            background: #16a34a; color: #fff; border: none;
            padding: 6px 14px; border-radius: var(--radius);
            font-size: 12px; font-weight: 600; cursor: pointer;
            transition: background .15s;
        }
        .btn-purchased:hover { background: #15803d; }

        tr.row-purchased td { opacity: .6; }

        /* Searchable select */
        .searchable-select { position: relative; }
        .searchable-select-input {
            width: 100%; padding: 10px 36px 10px 12px;
            border: 1px solid var(--gray-300); border-radius: var(--radius);
            font-size: 14px; background: var(--white); cursor: text; box-sizing: border-box;
        }
        .ss-clear-btn {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: var(--gray-300); font-size: 16px; line-height: 1; padding: 2px 4px;
            display: none;
        }
        .ss-clear-btn:hover { color: var(--danger); }
        .or-divider {
            text-align: center; color: var(--secondary); font-size: 12px; font-weight: 600;
            margin: 4px 0 12px; letter-spacing: .5px;
        }
        .searchable-select-input:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,.15);
        }
        .searchable-select-dropdown {
            display: none; position: absolute; top: 100%; left: 0; right: 0;
            max-height: 200px; overflow-y: auto; background: var(--white);
            border: 1px solid var(--gray-300); border-top: none;
            border-radius: 0 0 var(--radius) var(--radius);
            z-index: 1000; box-shadow: var(--shadow-md);
        }
        .searchable-select-dropdown.open { display: block; }
        .searchable-select-option { padding: 9px 12px; cursor: pointer; font-size: 14px; }
        .searchable-select-option:hover,
        .searchable-select-option.highlighted { background: var(--gray-100); color: var(--primary); }
        .searchable-select-option.hidden { display: none; }
        .ss-no-results { padding: 9px 12px; font-size: 13px; color: var(--secondary); font-style: italic; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <div>
                <h1>★ Wishlist</h1>
                <p class="page-subtitle">Products clients want — mark them purchased when stock arrives</p>
            </div>
            <div class="ph-actions">
                <button type="button" class="btn btn-primary" onclick="openModal('addWishModal')">+ Add Item</button>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="tab-bar">
            <a href="wishlist.php?show=pending" class="tab-btn <?php echo $show === 'pending' ? 'active' : ''; ?>">
                Pending <span class="badge"><?php echo $pending_count; ?></span>
            </a>
            <a href="wishlist.php?show=all" class="tab-btn <?php echo $show === 'all' ? 'active' : ''; ?>">
                All <span class="badge"><?php echo $pending_count + $purchased_count; ?></span>
            </a>
        </div>

        <div style="margin-bottom:12px;">
            <input type="text" id="wishSearchInput"
                style="width:100%;max-width:340px;padding:8px 12px;border:1px solid var(--gray-200);border-radius:var(--radius);font-size:13px;background:var(--gray-100);"
                placeholder="Search wishlist…"
                oninput="searchWishlist(this.value)">
        </div>

        <div class="table-responsive">
            <table class="table" id="tblWishlist">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Product Name</th>
                        <th>Clients Waiting</th>
                        <th>Status</th>
                        <th>Added</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center;color:var(--secondary);padding:40px;">
                            <?php echo $show === 'pending' ? 'No pending wishlist items.' : 'Wishlist is empty.'; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $i => $row): ?>
                    <tr class="<?php echo $row['status'] === 'purchased' ? 'row-purchased' : ''; ?>">
                        <td><?php echo $i + 1; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['product_name']); ?></strong></td>
                        <td>
                            <span class="client-count-badge">
                                <span class="icon">👤</span>
                                <?php echo $row['client_count']; ?> client<?php echo $row['client_count'] != 1 ? 's' : ''; ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo $row['status']; ?>">
                                <?php echo $row['status'] === 'purchased' ? '✓ Purchased' : 'Pending'; ?>
                            </span>
                        </td>
                        <td style="font-size:12px;color:var(--secondary);">
                            <?php echo date('M d, Y', strtotime($row['created_at'])); ?>
                            <?php if ($row['purchased_at']): ?>
                                <br><span style="color:#16a34a;">Stocked <?php echo date('M d', strtotime($row['purchased_at'])); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <?php if ($row['status'] === 'pending'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="wish_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="decrement_wish" class="btn btn-sm" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;" title="One less client" <?php echo $row['client_count'] <= 1 ? 'disabled' : ''; ?>>−1</button>
                            </form>
                            <form method="POST" style="display:inline;margin-left:2px;">
                                <input type="hidden" name="wish_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="increment_wish" class="btn btn-sm" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;" title="One more client wants this">+1</button>
                            </form>
                            <form method="POST" style="display:inline;margin-left:4px;" onsubmit="return confirm('Mark \'<?php echo addslashes(htmlspecialchars($row['product_name'])); ?>\' as purchased?')">
                                <input type="hidden" name="wish_id" value="<?php echo $row['id']; ?>">
                                <button type="submit" name="mark_purchased" class="btn-purchased">✓ I Purchased</button>
                            </form>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-secondary" style="margin-left:4px;"
                                data-id="<?php echo $row['id']; ?>"
                                data-name="<?php echo htmlspecialchars($row['product_name'], ENT_QUOTES); ?>"
                                data-count="<?php echo $row['client_count']; ?>"
                                onclick="openEditWish(this)">Edit</button>
                            <a href="wishlist.php?delete=<?php echo $row['id']; ?>"
                                class="btn btn-sm btn-danger" style="margin-left:4px;"
                                onclick="return confirm('Delete this item?')">Del</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div id="addWishModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addWishModal')">&times;</span>
        <h2>Add Wishlist Item</h2>
        <form method="POST" onsubmit="return prepareWishName('add')">
            <input type="hidden" id="add_product_name" name="product_name">
            <div class="form-group">
                <label>Pick from product list</label>
                <div class="searchable-select" id="addProductWrap">
                    <input type="text" class="searchable-select-input" id="add_product_search"
                           placeholder="Search products…" autocomplete="off">
                    <button type="button" class="ss-clear-btn" id="add_clear_btn" title="Clear" onclick="addSS.clearSelect()">×</button>
                    <div class="searchable-select-dropdown" id="add_product_dropdown">
                        <?php foreach ($products_list as $p):
                            $val   = $p['category'] ? $p['category'].'-'.$p['name'] : $p['name'];
                            $label = $p['category'] ? $p['category'].' — '.$p['name'] : $p['name'];
                        ?>
                        <div class="searchable-select-option" data-value="<?php echo htmlspecialchars($val); ?>">
                            <?php echo htmlspecialchars($label); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="or-divider">— or —</div>
            <div class="form-group">
                <label>Type a custom name</label>
                <input type="text" id="add_manual_name" class="searchable-select-input"
                       placeholder="e.g. Coca Cola 1L" autocomplete="off">
            </div>
            <div class="form-group">
                <label>Number of Clients Waiting</label>
                <input type="number" name="client_count" value="1" min="1" required>
            </div>
            <button type="submit" name="add_wish" class="btn btn-primary">Add to Wishlist</button>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editWishModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('editWishModal')">&times;</span>
        <h2>Edit Wishlist Item</h2>
        <form method="POST" onsubmit="return prepareWishName('edit')">
            <input type="hidden" id="edit_wish_id" name="wish_id">
            <input type="hidden" id="edit_wish_name" name="product_name">
            <div class="form-group">
                <label>Pick from product list</label>
                <div class="searchable-select" id="editProductWrap">
                    <input type="text" class="searchable-select-input" id="edit_product_search"
                           placeholder="Search products…" autocomplete="off">
                    <button type="button" class="ss-clear-btn" id="edit_clear_btn" title="Clear" onclick="editSS.clearSelect()">×</button>
                    <div class="searchable-select-dropdown" id="edit_product_dropdown">
                        <?php foreach ($products_list as $p):
                            $val   = $p['category'] ? $p['category'].'-'.$p['name'] : $p['name'];
                            $label = $p['category'] ? $p['category'].' — '.$p['name'] : $p['name'];
                        ?>
                        <div class="searchable-select-option" data-value="<?php echo htmlspecialchars($val); ?>">
                            <?php echo htmlspecialchars($label); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="or-divider">— or —</div>
            <div class="form-group">
                <label>Type a custom name</label>
                <input type="text" id="edit_manual_name" class="searchable-select-input"
                       placeholder="Custom product name…" autocomplete="off">
            </div>
            <div class="form-group">
                <label>Number of Clients Waiting</label>
                <input type="number" id="edit_wish_count" name="client_count" min="1" required>
            </div>
            <button type="submit" name="edit_wish" class="btn btn-primary">Save Changes</button>
        </form>
    </div>
</div>

<script src="script.js"></script>
<script>
function initSearchableSelect(searchId, dropdownId, hiddenId, wrapId, clearBtnId) {
    var searchInput = document.getElementById(searchId);
    var dropdown    = document.getElementById(dropdownId);
    var hidden      = document.getElementById(hiddenId);
    var clearBtn    = document.getElementById(clearBtnId);
    var options     = dropdown.querySelectorAll('.searchable-select-option');
    var hi          = -1;

    function toggleClear() {
        clearBtn.style.display = searchInput.value ? '' : 'none';
    }

    function filter() {
        var term = searchInput.value.toLowerCase().trim();
        options.forEach(function(o) {
            o.classList.toggle('hidden', o.textContent.trim().toLowerCase().indexOf(term) === -1);
        });
        toggleClear();
    }

    function highlight(vis) {
        options.forEach(function(o) { o.classList.remove('highlighted'); });
        if (vis[hi]) { vis[hi].classList.add('highlighted'); vis[hi].scrollIntoView({ block: 'nearest' }); }
    }

    function pick(opt) {
        hidden.value      = opt.getAttribute('data-value');
        searchInput.value = opt.textContent.trim();
        dropdown.classList.remove('open');
        toggleClear();
        hi = -1;
    }

    searchInput.addEventListener('focus', function() { dropdown.classList.add('open'); filter(); });
    searchInput.addEventListener('input', function() {
        hidden.value = '';
        dropdown.classList.add('open');
        hi = -1;
        filter();
    });
    searchInput.addEventListener('keydown', function(e) {
        var vis = dropdown.querySelectorAll('.searchable-select-option:not(.hidden)');
        if      (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, vis.length - 1); highlight(vis); }
        else if (e.key === 'ArrowUp')   { e.preventDefault(); hi = Math.max(hi - 1, 0); highlight(vis); }
        else if (e.key === 'Enter')     { e.preventDefault(); if (hi >= 0 && vis[hi]) pick(vis[hi]); }
        else if (e.key === 'Escape')    { dropdown.classList.remove('open'); }
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#' + wrapId)) dropdown.classList.remove('open');
    });
    options.forEach(function(o) { o.addEventListener('click', function() { pick(o); }); });

    return {
        set: function(val, text) {
            hidden.value      = val;
            searchInput.value = text || val;
            toggleClear();
        },
        clearSelect: function() {
            hidden.value = '';
            searchInput.value = '';
            toggleClear();
            options.forEach(function(o) { o.classList.remove('hidden'); });
        }
    };
}

var addSS  = initSearchableSelect('add_product_search',  'add_product_dropdown',  'add_product_name', 'addProductWrap',  'add_clear_btn');
var editSS = initSearchableSelect('edit_product_search', 'edit_product_dropdown', 'edit_wish_name',   'editProductWrap', 'edit_clear_btn');

// Submit: select takes priority over manual input
function prepareWishName(mode) {
    var hidden    = document.getElementById(mode === 'add' ? 'add_product_name' : 'edit_wish_name');
    var manualId  = mode === 'add' ? 'add_manual_name' : 'edit_manual_name';
    var manual    = document.getElementById(manualId).value.trim();
    // hidden already set if user picked from select; otherwise fall back to manual input
    if (!hidden.value && manual) hidden.value = manual;
    if (!hidden.value) { alert('Please pick a product or type a custom name.'); return false; }
    return true;
}

function openEditWish(btn) {
    var name = btn.dataset.name;
    document.getElementById('edit_wish_id').value    = btn.dataset.id;
    document.getElementById('edit_wish_count').value = btn.dataset.count;

    // Try to match a product option
    var matched = false;
    document.querySelectorAll('#edit_product_dropdown .searchable-select-option').forEach(function(o) {
        if (o.getAttribute('data-value') === name || o.textContent.trim() === name) {
            editSS.set(o.getAttribute('data-value'), o.textContent.trim());
            matched = true;
        }
    });
    if (!matched) {
        editSS.clearSelect();
        document.getElementById('edit_manual_name').value = name;
    } else {
        document.getElementById('edit_manual_name').value = '';
    }
    openModal('editWishModal');
}

document.querySelector('[onclick="openModal(\'addWishModal\')"]').addEventListener('click', function() {
    addSS.clearSelect();
    document.getElementById('add_manual_name').value = '';
});

function searchWishlist(term) {
    term = term.toLowerCase().trim();
    document.querySelectorAll('#tblWishlist tbody tr').forEach(function(row) {
        row.style.display = (!term || row.textContent.toLowerCase().includes(term)) ? '' : 'none';
    });
}
</script>
</body>
</html>
