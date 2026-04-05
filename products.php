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
        $_SESSION['flash_success'] = "Product added successfully";
    } else {
        $_SESSION['flash_error'] = "Error adding product: " . mysqli_error($conn);
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
        $_SESSION['flash_success'] = "Product updated successfully";
    } else {
        $_SESSION['flash_error'] = "Error updating product: " . mysqli_error($conn);
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
        $html .= "<tr>
            <td>{$i}</td>
            <td>{$name}</td>
            <td>{$cat}</td>
            <td>{$row['reorder_level']}</td>
            <td>{$um}</td>
            <td>RWF " . number_format($row['unit_price'], 0) . "</td>
            <td>
                <a href='#' class='btn btn-sm btn-warning'
                    onclick=\"editProduct({$row['id']},'$js_name','$js_cat',{$row['reorder_level']},'$js_um',{$row['unit_price']})\">Edit</a>
                <a href='products.php?delete={$row['id']}' onclick=\"return confirm('Are you sure?')\"
                    class='btn btn-sm btn-danger'>Delete</a>
            </td>
        </tr>";
        $i++;
    }
    if ($html === '') $html = "<tr><td colspan='7' style='text-align:center;color:var(--secondary);padding:32px;'>No products found.</td></tr>";
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
        'info'       => "Showing {$from}–{$to} of " . number_format($total) . " products",
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - Small Stock Management</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .products-toolbar { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
        .search-wrap { position:relative; flex:1; min-width:200px; max-width:360px; }
        .search-wrap input { width:100%; padding:8px 36px 8px 12px; border:1px solid var(--gray-200); border-radius:8px; font-size:14px; box-sizing:border-box; }
        .search-wrap .search-clear { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:16px; color:var(--secondary); display:none; line-height:1; }
        .search-wrap input:not(:placeholder-shown) ~ .search-clear { display:block; }
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
        .pagination-info { font-size:13px; color:var(--secondary); margin-top:8px; }
        #tblProducts tbody { transition: opacity .15s; }
        #tblProducts tbody.loading { opacity: .4; pointer-events:none; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'sidebar.php'; ?>

    <div class="main-content">
        <h1>Product Management</h1>

        <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
        <?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

        <div class="products-toolbar">
            <button onclick="openModal('addProductModal')" class="btn btn-primary">Add New Product</button>
            <div class="search-wrap">
                <input type="text" id="productSearch" placeholder="Search name or category..."
                    value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                <button class="search-clear" onclick="clearSearch()" title="Clear">&times;</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table" id="tblProducts">
                <thead>
                    <tr>
                        <th>No</th><th>Name</th><th>Category</th>
                        <th>Reorder Level</th><th>Unit Measure</th><th>Unit Price</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody id="productsBody">
                    <?php echo build_rows($result, $offset); ?>
                </tbody>
            </table>
        </div>

        <div class="pagination" id="productsPagination">
            <?php echo build_pagination($page, $total_pages); ?>
        </div>
        <div class="pagination-info" id="productsInfo">
            <?php
            $from = $total > 0 ? $offset + 1 : 0;
            echo "Showing {$from}–" . min($offset + $per_page, $total) . " of " . number_format($total) . " products";
            ?>
        </div>
    </div>
</div>

<!-- Add Product Modal -->
<div id="addProductModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addProductModal')">&times;</span>
        <h2>Add New Product</h2>
        <form method="POST">
            <div class="form-group"><label>Product Name*</label><input type="text" name="name" required></div>
            <div class="form-group"><label>Category</label><input type="text" name="category"></div>
            <div class="form-group"><label>Reorder Level*</label><input type="number" name="reorder_level" value="2" required></div>
            <div class="form-group"><label>Unit Measure*</label><input type="text" name="unit_measure" value="Box" required></div>
            <div class="form-group"><label>Unit Price (RWF)*</label><input type="number" name="unit_price" step="0.01" required></div>
            <button type="submit" name="add_product" class="btn btn-primary">Add Product</button>
        </form>
    </div>
</div>

<!-- Edit Product Modal -->
<div id="editProductModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('editProductModal')">&times;</span>
        <h2>Edit Product</h2>
        <form method="POST">
            <input type="hidden" id="edit_id" name="edit_id">
            <div class="form-group"><label>Product Name*</label><input type="text" id="edit_name" name="edit_name" required></div>
            <div class="form-group"><label>Category</label><input type="text" id="edit_category" name="edit_category"></div>
            <div class="form-group"><label>Reorder Level*</label><input type="number" id="edit_reorder_level" name="edit_reorder_level" required></div>
            <div class="form-group"><label>Unit Measure*</label><input type="text" id="edit_unit_measure" name="edit_unit_measure" required></div>
            <div class="form-group"><label>Unit Price (RWF)*</label><input type="number" id="edit_unit_price" name="edit_unit_price" step="0.01" required></div>
            <button type="submit" name="edit_product" class="btn btn-primary">Update Product</button>
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
