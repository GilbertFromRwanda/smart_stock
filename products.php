<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Handle Add Product
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_product'])) {
    $name          = mysqli_real_escape_string($conn, $_POST['name']);
    $category      = mysqli_real_escape_string($conn, $_POST['category']);
    $reorder_level = mysqli_real_escape_string($conn, $_POST['reorder_level']);
    $unit_measure  = mysqli_real_escape_string($conn, $_POST['unit_measure']);
    $unit_price    = mysqli_real_escape_string($conn, $_POST['unit_price']);

    if (mysqli_query($conn, "INSERT INTO products (name, category, reorder_level, unit_measure, unit_price)
                              VALUES ('$name','$category','$reorder_level','$unit_measure','$unit_price')")) {
        $_SESSION['flash_success'] = t('prod_added_ok');
    } else {
        $_SESSION['flash_error'] = t('prod_added_err') . ': ' . mysqli_error($conn);
    }
    header("Location: products.php");
    exit;
}

if (isset($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
if (isset($_SESSION['flash_error']))   { $error   = $_SESSION['flash_error'];   unset($_SESSION['flash_error']); }

// Handle Edit Product
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_product'])) {
    $id            = (int)$_POST['edit_id'];
    $name          = mysqli_real_escape_string($conn, $_POST['edit_name']);
    $category      = mysqli_real_escape_string($conn, $_POST['edit_category']);
    $reorder_level = mysqli_real_escape_string($conn, $_POST['edit_reorder_level']);
    $unit_measure  = mysqli_real_escape_string($conn, $_POST['edit_unit_measure']);
    $unit_price    = mysqli_real_escape_string($conn, $_POST['edit_unit_price']);

    if (mysqli_query($conn, "UPDATE products SET name='$name', category='$category', reorder_level='$reorder_level',
                              unit_measure='$unit_measure', unit_price='$unit_price' WHERE id=$id")) {
        $_SESSION['flash_success'] = t('prod_updated_ok');
    } else {
        $_SESSION['flash_error'] = t('prod_updated_err') . ': ' . mysqli_error($conn);
    }
    header("Location: products.php");
    exit;
}

// Handle Delete Product
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    mysqli_query($conn, "UPDATE products SET deleted=1 WHERE id=$id");
    header("Location: products.php");
    exit;
}

// ── Shared: pagination + search logic ─────────────────────────────────────────
$per_page       = 20;
$page           = max(1, (int)($_GET['page'] ?? 1));
$search         = trim($_GET['search'] ?? '');
$search_esc     = mysqli_real_escape_string($conn, $search);

$where = "WHERE deleted = 0" . ($search_esc !== '' ? " AND (name LIKE '%$search_esc%' OR category LIKE '%$search_esc%')" : "");

$total      = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM products $where"))['cnt'];
$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$result = mysqli_query($conn, "SELECT * FROM products $where ORDER BY name ASC LIMIT $per_page OFFSET $offset");

// ── Build rows HTML ────────────────────────────────────────────────────────────
function build_rows($result, $offset) {
    $html = '';
    $i = $offset + 1;
    while ($row = mysqli_fetch_assoc($result)) {
        $name     = htmlspecialchars($row['name']);
        $cat      = htmlspecialchars($row['category']);
        $um       = htmlspecialchars($row['unit_measure']);
        $js_name  = addslashes($name);
        $js_cat   = addslashes($cat);
        $js_um    = addslashes($um);
        $cat_badge = $cat ? "<span class='cat-badge'>{$cat}</span>" : "<span style='color:var(--gray-300);'>—</span>";
        $html .= "<tr>
            <td style='color:var(--secondary);font-size:12px;'>{$i}</td>
            <td style='font-weight:600;'>{$name}</td>
            <td>{$cat_badge}</td>
            <td>{$row['reorder_level']}</td>
            <td>{$um}</td>
            <td>RWF " . number_format($row['unit_price'], 0) . "</td>
            <td style='white-space:nowrap;'>
                <a href='#' class='btn btn-sm btn-warning'
                    onclick=\"editProduct({$row['id']},'$js_name','$js_cat',{$row['reorder_level']},'$js_um',{$row['unit_price']})\">". t('prod_btn_edit') ."</a>
                <a href='products.php?delete={$row['id']}' onclick=\"return confirm('". addslashes(t('prod_delete_confirm')) ."')\"
                    class='btn btn-sm btn-danger'>". t('prod_btn_delete') ."</a>
            </td>
        </tr>";
        $i++;
    }
    if ($html === '') $html = "<tr><td colspan='7' style='text-align:center;color:var(--secondary);padding:40px;font-size:14px;'>" . t('prod_none_found') . "</td></tr>";
    return $html;
}

// ── Build pagination HTML ──────────────────────────────────────────────────────
function build_pagination($page, $total_pages) {
    if ($total_pages <= 1) return '';
    $btn = function($p, $label, $disabled = false, $active = false) {
        if ($disabled) return "<span class='disabled'>$label</span>";
        if ($active)   return "<span class='active'>$label</span>";
        return "<a href='#' data-page='$p'>$label</a>";
    };
    $html  = $btn(1,       '&laquo;', $page <= 1);
    $html .= $btn($page-1, '&lsaquo;', $page <= 1);
    $start = max(1, $page - 2);
    $end   = min($total_pages, $page + 2);
    if ($start > 1) $html .= "<span class='disabled'>&hellip;</span>";
    for ($p = $start; $p <= $end; $p++) {
        $html .= $btn($p, $p, false, $p === $page);
    }
    if ($end < $total_pages) $html .= "<span class='disabled'>&hellip;</span>";
    $html .= $btn($page+1, '&rsaquo;', $page >= $total_pages);
    $html .= $btn($total_pages, '&raquo;', $page >= $total_pages);
    return $html;
}

// ── AJAX response ──────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    $from = $total > 0 ? $offset + 1 : 0;
    $to   = min($offset + $per_page, $total);
    header('Content-Type: application/json');
    echo json_encode([
        'rows'       => build_rows($result, $offset),
        'pagination' => build_pagination($page, $total_pages),
        'info'       => t('label_search') . ": {$from}–{$to} / " . number_format($total),
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('page_products'); ?> - Smart Stock</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Page header */
        .page-header { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:16px; margin-bottom:24px; }
        .page-header h1 { font-size:24px; font-weight:700; color:var(--dark); margin:0; }
        .page-subtitle  { font-size:14px; color:var(--secondary); margin:4px 0 0; }

        /* Product card */
        .prod-card {
            background:var(--white); border-radius:16px;
            border:1px solid var(--gray-200);
            box-shadow:0 1px 4px rgba(0,0,0,.06);
            padding:20px 24px;
        }

        /* Toolbar */
        .products-toolbar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
        .search-wrap { position:relative; flex:1; min-width:200px; max-width:340px; }
        .search-wrap input {
            width:100%; padding:8px 36px 8px 12px;
            border:1px solid var(--gray-200); border-radius:8px; font-size:13px;
            background:var(--gray-100); box-sizing:border-box; transition:border-color .15s, background .15s;
        }
        .search-wrap input:focus { outline:none; border-color:var(--primary); background:var(--white); box-shadow:0 0 0 3px rgba(37,99,235,.08); }
        .search-wrap .search-clear { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:16px; color:var(--secondary); display:none; line-height:1; }
        .search-wrap input:not(:placeholder-shown) ~ .search-clear { display:block; }

        /* Category badge */
        .cat-badge {
            display:inline-block; padding:2px 10px; border-radius:99px;
            background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:600;
            white-space:nowrap;
        }

        /* Pagination */
        .pagination { display:flex; align-items:center; gap:4px; flex-wrap:wrap; margin-top:16px; }
        .pagination a, .pagination span {
            display:inline-flex; align-items:center; justify-content:center;
            min-width:34px; height:34px; padding:0 10px;
            border-radius:8px; font-size:13px; font-weight:600; text-decoration:none;
            border:1px solid var(--gray-200); color:var(--dark); background:var(--white);
            cursor:pointer; transition:background .15s, border-color .15s;
        }
        .pagination a:hover { background:var(--gray-100); border-color:var(--primary); color:var(--primary); }
        .pagination span.active   { background:var(--primary); color:#fff; border-color:var(--primary); }
        .pagination span.disabled { color:var(--secondary); background:var(--gray-100); cursor:default; }
        .pagination-footer { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-top:14px; }
        .pagination-info   { font-size:13px; color:var(--secondary); }

        /* Table tweaks */
        #tblProducts { margin-top:0; }
        #tblProducts tbody { transition: opacity .15s; }
        #tblProducts tbody.loading { opacity:.4; pointer-events:none; }
        #tblProducts tr:last-child td { border-bottom:none; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <div>
                <h1><?php echo t('prod_title'); ?></h1>
                <p class="page-subtitle"><?php echo t('prod_subtitle'); ?> &nbsp;·&nbsp; <?php echo number_format($total); ?> <?php echo t('label_total'); ?></p>
            </div>
            <button onclick="openModal('addProductModal')" class="btn btn-primary"><?php echo t('prod_btn_add'); ?></button>
        </div>

        <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
        <?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

        <div class="prod-card">
            <div class="products-toolbar">
                <div class="search-wrap">
                    <input type="text" id="productSearch" placeholder="<?php echo t('prod_search_ph'); ?>"
                        value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                    <button class="search-clear" onclick="clearSearch()" title="Clear">&times;</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table" id="tblProducts">
                    <thead>
                        <tr>
                            <th><?php echo t('prod_col_num'); ?></th>
                            <th><?php echo t('prod_col_name'); ?></th>
                            <th><?php echo t('prod_col_category'); ?></th>
                            <th><?php echo t('prod_col_reorder'); ?></th>
                            <th><?php echo t('prod_col_unit'); ?></th>
                            <th><?php echo t('prod_col_price'); ?></th>
                            <th><?php echo t('prod_col_actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="productsBody">
                        <?php echo build_rows($result, $offset); ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-footer">
                <div class="pagination" id="productsPagination">
                    <?php echo build_pagination($page, $total_pages); ?>
                </div>
                <div class="pagination-info" id="productsInfo">
                    <?php
                    $from = $total > 0 ? $offset + 1 : 0;
                    $to   = min($offset + $per_page, $total);
                    echo "{$from}–{$to} / " . number_format($total) . " " . t('prod_title');
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Product Modal -->
<div id="addProductModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addProductModal')">&times;</span>
        <h2><?php echo t('prod_add_modal'); ?></h2>
        <form method="POST">
            <div class="form-group"><label><?php echo t('prod_field_name'); ?></label><input type="text" name="name" required></div>
            <div class="form-group"><label><?php echo t('prod_field_cat'); ?></label><input type="text" name="category"></div>
            <div class="form-group"><label><?php echo t('prod_field_reorder'); ?></label><input type="number" name="reorder_level" value="2" required></div>
            <div class="form-group"><label><?php echo t('prod_field_unit'); ?></label><input type="text" name="unit_measure" value="Box" required></div>
            <div class="form-group"><label><?php echo t('prod_field_price'); ?></label><input type="number" name="unit_price" step="0.01" required></div>
            <button type="submit" name="add_product" class="btn btn-primary"><?php echo t('prod_btn_add_modal'); ?></button>
        </form>
    </div>
</div>

<!-- Edit Product Modal -->
<div id="editProductModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('editProductModal')">&times;</span>
        <h2><?php echo t('prod_edit_modal'); ?></h2>
        <form method="POST">
            <input type="hidden" id="edit_id" name="edit_id">
            <div class="form-group"><label><?php echo t('prod_field_name'); ?></label><input type="text" id="edit_name" name="edit_name" required></div>
            <div class="form-group"><label><?php echo t('prod_field_cat'); ?></label><input type="text" id="edit_category" name="edit_category"></div>
            <div class="form-group"><label><?php echo t('prod_field_reorder'); ?></label><input type="number" id="edit_reorder_level" name="edit_reorder_level" required></div>
            <div class="form-group"><label><?php echo t('prod_field_unit'); ?></label><input type="text" id="edit_unit_measure" name="edit_unit_measure" required></div>
            <div class="form-group"><label><?php echo t('prod_field_price'); ?></label><input type="number" id="edit_unit_price" name="edit_unit_price" step="0.01" required></div>
            <button type="submit" name="edit_product" class="btn btn-primary"><?php echo t('prod_btn_update'); ?></button>
        </form>
    </div>
</div>

<script src="script.js"></script>
<script>
function editProduct(id, name, category, reorderLevel, unitMeasure, unitPrice) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_category').value = category;
    document.getElementById('edit_reorder_level').value = reorderLevel;
    document.getElementById('edit_unit_measure').value = unitMeasure;
    document.getElementById('edit_unit_price').value = unitPrice;
    openModal('editProductModal');
}

// ── AJAX search + pagination ───────────────────────────────────────────────────
let currentPage   = <?php echo $page; ?>;
let currentSearch = <?php echo json_encode($search); ?>;
let debounceTimer = null;

const tbody      = document.getElementById('productsBody');
const pagination = document.getElementById('productsPagination');
const infoEl     = document.getElementById('productsInfo');
const searchInput = document.getElementById('productSearch');

function fetchProducts(page, search) {
    currentPage   = page;
    currentSearch = search;
    tbody.classList.add('loading');

    const url = 'products.php?ajax=1&page=' + page + (search ? '&search=' + encodeURIComponent(search) : '');
    fetch(url)
        .then(r => r.json())
        .then(data => {
            tbody.innerHTML      = data.rows;
            pagination.innerHTML = data.pagination;
            infoEl.textContent   = data.info;
            tbody.classList.remove('loading');
            bindPagination();
        })
        .catch(() => tbody.classList.remove('loading'));
}

function bindPagination() {
    pagination.querySelectorAll('a[data-page]').forEach(a => {
        a.addEventListener('click', e => {
            e.preventDefault();
            fetchProducts(parseInt(a.dataset.page), currentSearch);
        });
    });
}

function clearSearch() {
    searchInput.value = '';
    fetchProducts(1, '');
    searchInput.focus();
}

searchInput.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        fetchProducts(1, searchInput.value.trim());
    }, 300);
});

// prevent form submit on Enter
searchInput.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });

bindPagination();
</script>
</body>
</html>
