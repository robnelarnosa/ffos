<?php
require_once 'config.php';
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
if (empty($_SESSION['admin'])) {
    header('Location: admin_login.php');
    exit;
}

// --- Helpers ---
function handle_image_upload(string $fieldName): ?string
{
    if (empty($_FILES[$fieldName]['name'])) {
        return null;
    }

    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp'];
    if (!in_array($ext, $allowed, true)) {
        return null;
    }

    $uploadsDir = __DIR__ . '/uploads';
    if (!is_dir($uploadsDir)) {
        @mkdir($uploadsDir, 0777, true);
    }

    $newName = uniqid('prod_', true) . '.' . $ext;
    $target = $uploadsDir . '/' . $newName;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return null;
    }

    return 'uploads/' . $newName;
}

// Function to notify WebSocket clients of menu updates
function notifyMenuUpdate() {
    $payload = json_encode(['type' => 'menu_updated']);
    $fp = @fsockopen('127.0.0.1', 9001, $errno, $errstr, 1);
    if ($fp) {
        fwrite($fp, $payload . "\n");
        fclose($fp);
    }
}

// --- Handle POST actions (create/edit for category/product/bundle) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Create category
    if ($action === 'create_category') {
        $name = trim($_POST['category_name'] ?? '');
        if ($name !== '') {
            $stmt = $pdo->prepare("INSERT INTO product_categories (name) VALUES (?)");
            $stmt->execute([$name]);
            notifyMenuUpdate();
        }
    }

    // Edit category
    if ($action === 'edit_category') {
        $id   = (int)($_POST['category_id'] ?? 0);
        $name = trim($_POST['category_name'] ?? '');
        if ($id > 0 && $name !== '') {
            $stmt = $pdo->prepare("UPDATE product_categories SET name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);
            notifyMenuUpdate();
        }
    }

    // Create product
    if ($action === 'create_product') {
        $name       = trim($_POST['product_name'] ?? '');
        $code       = trim($_POST['product_code'] ?? '');
        $price      = (float)($_POST['product_price'] ?? 0);
        $categoryId = (int)($_POST['product_category_id'] ?? 0);

        if ($name !== '' && $code !== '' && $price > 0 && $categoryId > 0) {
            $imagePath = handle_image_upload('product_image');

            $stmt = $pdo->prepare(
                "INSERT INTO menu_items (code, category_id, is_bundle, name, price, image_path, is_active)
                 VALUES (?, ?, 0, ?, ?, ?, 1)"
            );
            $stmt->execute([$code, $categoryId, $name, $price, $imagePath]);
            notifyMenuUpdate();
        }
    }

    // Edit product
    if ($action === 'edit_product') {
        $id         = (int)($_POST['product_id'] ?? 0);
        $name       = trim($_POST['product_name'] ?? '');
        $code       = trim($_POST['product_code'] ?? '');
        $price      = (float)($_POST['product_price'] ?? 0);
        $categoryId = (int)($_POST['product_category_id'] ?? 0);
        $existing   = $_POST['existing_product_image'] ?? null;

        if ($id > 0 && $name !== '' && $code !== '' && $price > 0 && $categoryId > 0) {
            $imagePath = handle_image_upload('product_image');
            if ($imagePath === null) {
                $imagePath = $existing; // keep old one
            }

            $stmt = $pdo->prepare(
                "UPDATE menu_items
                 SET code = ?, category_id = ?, name = ?, price = ?, image_path = ?
                 WHERE id = ? AND is_bundle = 0"
            );
            $stmt->execute([$code, $categoryId, $name, $price, $imagePath, $id]);
            notifyMenuUpdate();
        }
    }

    // Create bundle (no category; standalone)
    if ($action === 'create_bundle') {
        $bundleName   = trim($_POST['bundle_name'] ?? '');
        $bundleCode   = trim($_POST['bundle_code'] ?? '');
        $selectedProd = $_POST['bundle_items'] ?? []; // array of product IDs
        $quantities   = $_POST['bundle_qty'] ?? [];   // keyed by product ID

        // Input validation
        if ($bundleName === '' || $bundleCode === '') {
            // Handle validation error - required fields missing
            header('Location: admin_products.php?error=missing_fields');
            exit;
        }

        if (!is_array($selectedProd) || !is_array($quantities) || empty($selectedProd)) {
            // Handle validation error - invalid product selection
            header('Location: admin_products.php?error=no_products');
            exit;
        }

        // Sanitize and validate product IDs
        $ids = array_filter(array_map('intval', $selectedProd));
        if (empty($ids)) {
            header('Location: admin_products.php?error=invalid_products');
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Check if bundle code already exists
            $checkStmt = $pdo->prepare("SELECT id FROM menu_items WHERE code = ? AND is_bundle = 1");
            $checkStmt->execute([$bundleCode]);
            if ($checkStmt->fetch()) {
                $pdo->rollBack();
                header('Location: admin_products.php?error=duplicate_code');
                exit;
            }

            // Fetch product prices securely using prepared statement
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $pdo->prepare("SELECT id, price FROM menu_items WHERE id IN ($placeholders) AND is_bundle = 0");
            $stmt->execute($ids);

            $prices = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $prices[$row['id']] = (float)$row['price'];
            }

            // Validate all selected products exist and calculate total
            $total = 0;
            $bundleComponents = [];
            $invalidProducts = [];

            foreach ($ids as $pid) {
                if (!isset($prices[$pid])) {
                    $invalidProducts[] = $pid;
                    continue;
                }

                $qty = max(1, (int)($quantities[$pid] ?? 1));
                if ($qty <= 0) $qty = 1; // Ensure positive quantity

                $price = $prices[$pid];
                if ($price <= 0) {
                    $invalidProducts[] = $pid;
                    continue;
                }

                $total += $price * $qty;
                $bundleComponents[] = ['id' => $pid, 'qty' => $qty, 'price' => $price];
            }

            // Check for invalid products
            if (!empty($invalidProducts)) {
                $pdo->rollBack();
                header('Location: admin_products.php?error=invalid_products');
                exit;
            }

            if ($total <= 0 || empty($bundleComponents)) {
                $pdo->rollBack();
                header('Location: admin_products.php?error=no_valid_items');
                exit;
            }

            // Handle image upload
            $imagePath = handle_image_upload('bundle_image');

            // Insert into bundles table
            $stmt = $pdo->prepare("INSERT INTO bundles (name, price, is_active) VALUES (?, ?, 1)");
            $stmt->execute([$bundleName, $total]);
            $bundleId = (int)$pdo->lastInsertId();

            // Insert bundle main record into menu_items
            $stmt = $pdo->prepare(
                "INSERT INTO menu_items (code, category_id, is_bundle, name, price, image_path, is_active)
                 VALUES (?, NULL, 1, ?, ?, ?, 1)"
            );
            $stmt->execute([$bundleCode, $bundleName, $total, $imagePath]);
            $bundleMenuId = (int)$pdo->lastInsertId();

            // Insert bundle components
            $stmtItem = $pdo->prepare(
                "INSERT INTO bundle_items (bundle_id, bundle_menu_item_id, menu_item_id, quantity)
                 VALUES (?, ?, ?, ?)"
            );
            foreach ($bundleComponents as $comp) {
                $stmtItem->execute([$bundleId, $bundleMenuId, $comp['id'], $comp['qty']]);
            }

            $pdo->commit();
            notifyMenuUpdate();

            // Success - redirect with success message
            header('Location: admin_products.php?success=bundle_created');
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Log error and redirect with detailed error message
            error_log("Bundle creation error: " . $e->getMessage());
            header('Location: admin_products.php?error=creation_failed_detailed&msg=' . urlencode($e->getMessage()));
            exit;
        }
    }

    // Edit bundle (including composition)
    if ($action === 'edit_bundle') {
        $bundleId     = (int)($_POST['bundle_id'] ?? 0);
        $bundleName   = trim($_POST['bundle_name'] ?? '');
        $bundleCode   = trim($_POST['bundle_code'] ?? '');
        $existingImg  = $_POST['existing_bundle_image'] ?? null;
        $selectedProd = $_POST['bundle_items'] ?? [];
        $quantities   = $_POST['bundle_qty'] ?? [];

        if ($bundleId > 0 && $bundleName !== '' && $bundleCode !== '' && !empty($selectedProd)) {
            $ids = array_map('intval', $selectedProd);
            $in  = implode(',', $ids);

            $stmt = $pdo->query("SELECT id, price FROM menu_items WHERE id IN ($in) AND is_bundle = 0");
            $prices = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $prices[$row['id']] = (float)$row['price'];
            }

            $total = 0;
            $bundleComponents = [];
            foreach ($ids as $pid) {
                $qty = max(1, (int)($quantities[$pid] ?? 1));
                if (!isset($prices[$pid])) continue;
                $total += $prices[$pid] * $qty;
                $bundleComponents[] = ['id' => $pid, 'qty' => $qty];
            }

            if ($total > 0 && !empty($bundleComponents)) {
                $imagePath = handle_image_upload('bundle_image');
                if ($imagePath === null) {
                    $imagePath = $existingImg;
                }

                // Get the bundle_id from bundle_items
                $stmtBundleId = $pdo->prepare("SELECT bundle_id FROM bundle_items WHERE bundle_menu_item_id = ? LIMIT 1");
                $stmtBundleId->execute([$bundleId]);
                $bundleIdFromItems = $stmtBundleId->fetchColumn();

                if ($bundleIdFromItems) {
                    // Update bundles table
                    $stmt = $pdo->prepare("UPDATE bundles SET name = ?, price = ? WHERE id = ?");
                    $stmt->execute([$bundleName, $total, $bundleIdFromItems]);
                }

                // Update main bundle record in menu_items
                $stmt = $pdo->prepare(
                    "UPDATE menu_items
                     SET code = ?, name = ?, price = ?, image_path = ?
                     WHERE id = ? AND is_bundle = 1"
                );
                $stmt->execute([$bundleCode, $bundleName, $total, $imagePath, $bundleId]);

                // Replace bundle items
                $stmtDel = $pdo->prepare("DELETE FROM bundle_items WHERE bundle_menu_item_id = ?");
                $stmtDel->execute([$bundleId]);

                $stmtItem = $pdo->prepare(
                    "INSERT INTO bundle_items (bundle_id, bundle_menu_item_id, menu_item_id, quantity)
                     VALUES (?, ?, ?, ?)"
                );
                foreach ($bundleComponents as $comp) {
                    $stmtItem->execute([$bundleIdFromItems, $bundleId, $comp['id'], $comp['qty']]);
                }
                notifyMenuUpdate();
            }
        }
    }

    // Delete bundle
    if ($action === 'delete_bundle') {
        $bundleId = (int)($_POST['bundle_id'] ?? 0);
        if ($bundleId > 0) {
            try {
                $pdo->beginTransaction();

                // Get the bundle_id from bundle_items to delete from bundles table
                $stmtBundleId = $pdo->prepare("SELECT bundle_id FROM bundle_items WHERE bundle_menu_item_id = ? LIMIT 1");
                $stmtBundleId->execute([$bundleId]);
                $bundleIdFromItems = $stmtBundleId->fetchColumn();

                // Delete from bundle_items
                $stmtDelItems = $pdo->prepare("DELETE FROM bundle_items WHERE bundle_menu_item_id = ?");
                $stmtDelItems->execute([$bundleId]);

                // Delete from bundles table if exists
                if ($bundleIdFromItems) {
                    $stmtDelBundle = $pdo->prepare("DELETE FROM bundles WHERE id = ?");
                    $stmtDelBundle->execute([$bundleIdFromItems]);
                }

                // Delete from menu_items
                $stmtDelMenu = $pdo->prepare("DELETE FROM menu_items WHERE id = ? AND is_bundle = 1");
                $stmtDelMenu->execute([$bundleId]);

                $pdo->commit();
                notifyMenuUpdate();

                // Success - redirect with success message
                header('Location: admin_products.php?success=bundle_deleted');
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Log error and redirect with error message
                error_log("Bundle deletion error: " . $e->getMessage());
                header('Location: admin_products.php?error=deletion_failed');
                exit;
            }
        }
    }

    // After any POST, redirect to avoid form resubmission
    header('Location: admin_products.php');
    exit;
}

// --- Fetch data for UI ---
$categories = $pdo->query("SELECT id, name FROM product_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$products   = $pdo->query(
    "SELECT m.id, m.code, m.name, m.price, m.is_bundle, m.image_path,
            m.category_id,
            c.name AS category_name
     FROM menu_items m
     LEFT JOIN product_categories c ON c.id = m.category_id
     ORDER BY m.is_bundle ASC, c.name ASC, m.name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Separate lists
$singleProducts = array_values(array_filter($products, fn($p) => (int)$p['is_bundle'] === 0));
$bundles        = array_values(array_filter($products, fn($p) => (int)$p['is_bundle'] === 1));

// Bundle details for "View" modal and Edit prefill
$bundleItemsByBundle = [];
$stmtDetails = $pdo->query(
    "SELECT bi.bundle_menu_item_id, bi.menu_item_id, bi.quantity,
            m.name, m.price
     FROM bundle_items bi
     JOIN menu_items m ON m.id = bi.menu_item_id
     ORDER BY bi.bundle_menu_item_id, m.name"
);
while ($row = $stmtDetails->fetch(PDO::FETCH_ASSOC)) {
    $bid = (int)$row['bundle_menu_item_id'];
    $mid = (int)$row['menu_item_id'];
    $qty = (int)$row['quantity'];
    $price = (float)$row['price'];
    $bundleItemsByBundle[$bid][] = [
        'id'       => $mid,
        'name'     => $row['name'],
        'quantity' => $qty,
        'price'    => $price,
        'subtotal' => $qty * $price
    ];
}

// Stats / insights
$stats = [
    'categories' => count($categories),
    'products'   => count($singleProducts),
    'bundles'    => count($bundles)
];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Products Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	
<style>


/* Page background */
body {
    background-color: #f4f9fd !important; /* very light pastel blue */
}

/* Navbar */
.navbar.bg-dark {
    background-color: #6fa8dc !important; /* pastel blue */
}

/* Cards */
.card {
    border: none;
    border-radius: 12px;
}

.card-header {
    border-bottom: none;
    font-weight: 600;
}

/* Card header colors */
.card-header.bg-secondary {
    background-color: #9fc5e8 !important;
    color: #083358 !important;
}

.card-header.bg-warning {
    background-color: #cfe2f3 !important;
    color: #083358 !important;
}

.card-header.bg-info {
    background-color: #b6d7f2 !important;
    color: #083358 !important;
}

.card-header.bg-success {
    background-color: #a4c2f4 !important;
    color: #083358 !important;
}

/* Buttons */
.btn-primary {
    background-color: #6fa8dc;
    border-color: #6fa8dc;
}

.btn-primary:hover {
    background-color: #5b9bd5;
    border-color: #5b9bd5;
}

.btn-outline-primary {
    color: #6fa8dc;
    border-color: #6fa8dc;
}

.btn-outline-primary:hover {
    background-color: #6fa8dc;
    color: #fff;
}

.btn-danger {
    background-color: #f4cccc;
    border-color: #f4cccc;
    color: #7a1f1f;
}

.btn-danger:hover {
    background-color: #ea9999;
    border-color: #ea9999;
}

/* Tables */
.table thead th {
    background-color: #eaf2fb !important;
    color: #083358;
}

.table tbody tr:hover {
    background-color: #f0f6fc;
}

/* Badges */
.badge.bg-primary {
    background-color: #6fa8dc !important;
}

.badge.bg-success {
    background-color: #9fc5e8 !important;
    color: #083358;
}

.badge.bg-warning {
    background-color: #cfe2f3 !important;
    color: #083358;
}

/* Modals */
.modal-content {
    border-radius: 14px;
}

.modal-header {
    background-color: #eaf2fb;
    border-bottom: none;
}

.modal-footer {
    border-top: none;
}

/* Inputs */
.form-control,
.form-select {
    border-radius: 8px;
}

.form-control:focus,
.form-select:focus {
    border-color: #6fa8dc;
    box-shadow: 0 0 0 0.15rem rgba(111, 168, 220, 0.3);
}

/* Sticky table header fix (keep yours) */
.table-sticky-header thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background-color: #eaf2fb;
}
</style>
</head>
<body class="bg-light" style="font-size:0.875rem;">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3">
    <div class="container-fluid">
        <span class="navbar-brand">Products Management</span>
        <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm ms-auto">Back to Dashboard</a>
    </div>
</nav>

<div class="container mb-1">

    <!-- SUCCESS/ERROR MESSAGES -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <strong>Success!</strong>
            <?php if ($_GET['success'] === 'bundle_created'): ?>
                Bundle has been created successfully.
            <?php elseif ($_GET['success'] === 'bundle_deleted'): ?>
                Bundle has been deleted successfully.
            <?php endif; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Error!</strong>
            <?php
            $errorMsg = '';
            switch ($_GET['error']) {
                case 'missing_fields':
                    $errorMsg = 'Please fill in all required fields.';
                    break;
                case 'no_products':
                    $errorMsg = 'Please select at least one product for the bundle.';
                    break;
                case 'invalid_products':
                    $errorMsg = 'Some selected products are invalid or do not exist.';
                    break;
                case 'duplicate_code':
                    $errorMsg = 'A bundle with this code already exists.';
                    break;
                case 'no_valid_items':
                    $errorMsg = 'No valid items found for the bundle.';
                    break;
                case 'creation_failed':
                    $errorMsg = 'Failed to create bundle. Please try again.';
                    break;
                case 'creation_failed_detailed':
                    $errorMsg = 'Failed to create bundle: ' . htmlspecialchars($_GET['msg'] ?? 'Unknown error');
                    break;
                default:
                    $errorMsg = 'An unknown error occurred.';
            }
            echo htmlspecialchars($errorMsg);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- ROW 1: Insights + Products -->
    <div class="row g-3 mb-4">
        <!-- Insights / Stats col-4 -->
        <div class="col-md-4">
            <div class="card shadow-sm" style="max-height:380px;">
                <div class="card-header bg-secondary text-white py-1">
                    <strong style="font-size:0.9rem;">Insights & Statistics</strong>
                </div>
                <div class="card-body py-2 small" style="max-height:250px; overflow-y:auto;">
                    <ul class="list-group list-group-flush mb-3">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Categories
                            <span class="badge bg-primary rounded-pill"><?= (int)$stats['categories'] ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Products
                            <span class="badge bg-success rounded-pill"><?= (int)$stats['products'] ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            Bundles
                            <span class="badge bg-warning text-dark rounded-pill"><?= (int)$stats['bundles'] ?></span>
                        </li>
                    </ul>
                    <div class="text-muted">
                        <small>
                            These stats update automatically as you add or edit categories, products, and bundles.
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Products col-8 -->
        <div class="col-md-8">
            <div class="card shadow-sm" style="max-height:380px;">
                <div class="card-header bg-warning py-1 d-flex justify-content-between align-items-center">
                    <strong style="font-size:0.9rem;">Products</strong>
                    <button type="button" class="btn btn-sm btn-danger py-0"
                            onclick="openProductModal('create')">
                        + Add Product
                    </button>
                </div>
                <div class="card-body p-0" style="max-height:250px; overflow-y:auto;">
                    <table class="table table-sm mb-0 align-middle table-sticky-header">
                            <thead class="table-light small">
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th style="width:50px;">Img</th>
                                <th>Name</th>
                                <th>Code</th>
                                <th>Category</th>
                                <th class="text-end">Price</th>
                                <th style="width:80px;" class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody class="small">
                            <?php foreach ($singleProducts as $p): ?>
                                <tr>
                                    <td><?= (int)$p['id'] ?></td>
                                    <td>
                                        <?php if (!empty($p['image_path'])): ?>
                                            <img src="<?= htmlspecialchars($p['image_path'] ?? '') ?>" alt=""
                                                 style="width:38px;height:38px;object-fit:cover;border-radius:4px;cursor:pointer;"
                                                 onclick="openImageView('<?= htmlspecialchars($p['image_path'] ?? '', ENT_QUOTES) ?>')">
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($p['name'] ?? '') ?></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($p['code'] ?? '') ?></span></td>
                                    <td><?= htmlspecialchars($p['category_name'] ?? '') ?></td>
                                    <td class="text-end">₱<?= number_format((float)$p['price'], 2) ?></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick="openProductModal('edit',
                                                    <?= (int)$p['id'] ?>,
                                                    '<?= htmlspecialchars($p['name'] ?? '', ENT_QUOTES) ?>',
                                                    '<?= htmlspecialchars($p['code'] ?? '', ENT_QUOTES) ?>',
                                                    '<?= htmlspecialchars((string)($p['price'] ?? ''), ENT_QUOTES) ?>',
                                                    <?= (int)($p['category_id'] ?? 0) ?>,
                                                    '<?= htmlspecialchars($p['image_path'] ?? '', ENT_QUOTES) ?>'
                                                )">
                                            Edit
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$singleProducts): ?>
                                <tr><td colspan="7" class="text-center text-muted py-3">No products yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ROW 2: Categories + Bundles -->
    <div class="row g-3">
        <!-- Categories col-4 -->
        <div class="col-md-4">
            <div class="card shadow-sm" style="max-height:380px;">
                <div class="card-header bg-info py-1 d-flex justify-content-between align-items-center">
                    <strong style="font-size:0.9rem;">Categories</strong>
                    <button type="button" class="btn btn-sm btn-primary py-0"
                            onclick="openCategoryModal('create')">
                        + Add Category
                    </button>
                </div>
                <div class="card-body p-0" style="max-height:200px; overflow-y:auto;">
                    <table class="table table-sm mb-0 align-middle table-sticky-header">
                        <thead class="table-light small">
                        <tr>
                            <th style="width:60px;">ID</th>
                            <th>Name</th>
                            <th style="width:80px;" class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody class="small">
                        <?php foreach ($categories as $c): ?>
                            <tr>
                                <td><?= (int)$c['id'] ?></td>
                                <td><?= htmlspecialchars($c['name'] ?? '') ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            onclick="openCategoryModal('edit',
                                                <?= (int)$c['id'] ?>,
                                                '<?= htmlspecialchars($c['name'] ?? '', ENT_QUOTES) ?>'
                                            )">
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$categories): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">No categories yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Bundles col-8 -->
        <div class="col-md-8">
            <div class="card shadow-sm" style="max-height:380px;">
                <div class="card-header bg-success text-white py-1 d-flex justify-content-between align-items-center">
                    <strong style="font-size:0.9rem;">Bundles</strong>
                    <button type="button" class="btn btn-sm btn-light text-success py-0"
                            onclick="openBundleModal('create')">
                        + Add Bundle
                    </button>
                </div>
                <div class="card-body p-0" style="max-height:200px; overflow-y:auto;">
                    <table class="table table-sm mb-0 align-middle table-sticky-header">
                            <thead class="table-light small">
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th style="width:50px;">Img</th>
                                <th>Name</th>
                                <th>Code</th>
                                <th class="text-end">Price</th>
                                <th style="width:160px;" class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody class="small">
                            <?php foreach ($bundles as $b): ?>
                                <tr>
                                    <td><?= (int)$b['id'] ?></td>
                                    <td>
                                        <?php if (!empty($b['image_path'])): ?>
                                            <img src="<?= htmlspecialchars($b['image_path'] ?? '') ?>" alt=""
                                                 style="width:38px;height:38px;object-fit:cover;border-radius:4px;cursor:pointer;"
                                                 onclick="openImageView('<?= htmlspecialchars($b['image_path'] ?? '', ENT_QUOTES) ?>')">
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($b['name'] ?? '') ?></td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($b['code'] ?? '') ?></span></td>
                                    <td class="text-end">₱<?= number_format((float)$b['price'], 2) ?></td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-1">
                                            <button type="button" class="btn btn-sm btn-outline-info"
                                                    onclick="openBundleViewModal(
                                                        <?= (int)$b['id'] ?>,
                                                        '<?= htmlspecialchars($b['name'] ?? '', ENT_QUOTES) ?>',
                                                        '<?= htmlspecialchars($b['code'] ?? '', ENT_QUOTES) ?>',
                                                        <?= (float)$b['price'] ?>
                                                    )">
                                                View
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                    onclick="openBundleModal('edit',
                                                        <?= (int)$b['id'] ?>,
                                                        '<?= htmlspecialchars($b['name'] ?? '', ENT_QUOTES) ?>',
                                                        '<?= htmlspecialchars($b['code'] ?? '', ENT_QUOTES) ?>',
                                                        '<?= htmlspecialchars($b['image_path'] ?? '', ENT_QUOTES) ?>'
                                                    )">
                                                Edit
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    onclick="deleteBundle(<?= (int)$b['id'] ?>, '<?= htmlspecialchars($b['name'] ?? '', ENT_QUOTES) ?>')">
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$bundles): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No bundles yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                </div>
            </div>
        </div>
    </div>

</div> <!-- container -->

<!-- CATEGORY MODAL -->
<div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" id="categoryForm">
        <div class="modal-header py-2">
          <h5 class="modal-title" id="categoryModalTitle">Category</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body small">
            <input type="hidden" name="action" id="categoryAction" value="create_category">
            <input type="hidden" name="category_id" id="categoryId">
            <div class="mb-2">
                <label class="form-label mb-1">
                    Category Name <span class="text-danger">*</span>
                </label>
                <input type="text" name="category_name" id="categoryName"
                       class="form-control form-control-sm" required>
            </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- PRODUCT MODAL -->
<div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data" id="productForm">
        <div class="modal-header py-2">
          <h5 class="modal-title" id="productModalTitle">Product</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body small">
            <input type="hidden" name="action" id="productAction" value="create_product">
            <input type="hidden" name="product_id" id="productId">
            <input type="hidden" name="existing_product_image" id="productExistingImage">

            <div class="row g-2">
                <div class="col-md-8">
                    <div class="mb-2">
                        <label class="form-label mb-1">
                            Product Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="product_name" id="productName"
                               class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label mb-1">
                            Code <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="product_code" id="productCode"
                               class="form-control form-control-sm" required placeholder="e.g. BIGMAC">
                    </div>
                    <div class="mb-2">
                        <label class="form-label mb-1">
                            Category <span class="text-danger">*</span>
                        </label>
                        <select name="product_category_id" id="productCategoryId"
                                class="form-select form-select-sm" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label mb-1">
                            Price <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">₱</span>
                            <input type="number" step="0.01" name="product_price" id="productPrice"
                                   class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mb-2">
                        <label class="form-label mb-1">Image (optional)</label>
                        <input type="file" name="product_image" class="form-control form-control-sm">
                    </div>
                    <div class="border rounded p-2 text-center small">
                        <div class="text-muted mb-1">Current Image</div>
                        <img src="" id="productPreviewImg" alt=""
                             style="max-width:100%;max-height:120px;object-fit:cover;border-radius:4px;display:none;cursor:pointer;"
                             onclick="if(this.src) openImageView(this.src)">
                        <div id="productNoImg" class="text-muted" style="font-size:0.75rem;">No image</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- BUNDLE MODAL (Create/Edit) -->
<div class="modal fade" id="bundleModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data" id="bundleForm">
        <div class="modal-header py-2">
          <h5 class="modal-title" id="bundleModalTitle">Bundle</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body small">
            <input type="hidden" name="action" id="bundleAction" value="create_bundle">
            <input type="hidden" name="bundle_id" id="bundleId">
            <input type="hidden" name="existing_bundle_image" id="bundleExistingImage">

            <div class="row g-2">
                <div class="col-md-8">
                    <div class="mb-2">
                        <label class="form-label mb-1">
                            Bundle Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="bundle_name" id="bundleName"
                               class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label mb-1">
                            Bundle Code <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="bundle_code" id="bundleCode"
                               class="form-control form-control-sm" required placeholder="e.g. BDL_MEAL1">
                    </div>
                    <div class="mb-2">
                        <label class="form-label mb-1">
                            Select Products & Quantities <span class="text-danger">*</span>
                        </label>
                        <div class="border rounded p-2"
                             style="max-height:260px;overflow-y:auto;">
                            <?php foreach ($singleProducts as $p): ?>
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <div class="form-check flex-grow-1">
                                        <input class="form-check-input bundle-prod-checkbox" type="checkbox"
                                               value="<?= (int)$p['id'] ?>"
                                               id="bprod_<?= (int)$p['id'] ?>"
                                               name="bundle_items[]"
                                               onchange="updateBundleTotal()">
                                        <label class="form-check-label" for="bprod_<?= (int)$p['id'] ?>">
                                            <?= htmlspecialchars($p['name'] ?? '') ?>
                                            (₱<?= number_format((float)$p['price'], 2) ?>)
                                        </label>
                                    </div>
                                    <input type="number"
                                           name="bundle_qty[<?= (int)$p['id'] ?>]"
                                           class="form-control form-control-sm ms-2 bundle-qty-input"
                                           value="1" min="1" style="width:70px;"
                                           onchange="updateBundleTotal()">
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$singleProducts): ?>
                                <div class="text-muted small">No base products yet.</div>
                            <?php endif; ?>
                        </div>
                        <div class="form-text">
                            Bundle price = sum of (product price × quantity). Computed automatically.
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mb-2">
                        <label class="form-label mb-1">Bundle Image (optional)</label>
                        <input type="file" name="bundle_image" class="form-control form-control-sm">
                    </div>
                    <div class="border rounded p-2 text-center small mb-2">
                        <div class="text-muted mb-1">Current Image</div>
                        <img src="" id="bundlePreviewImg" alt=""
                             style="max-width:100%;max-height:120px;object-fit:cover;border-radius:4px;display:none;cursor:pointer;"
                             onclick="if(this.src) openImageView(this.src)">
                        <div id="bundleNoImg" class="text-muted" style="font-size:0.75rem;">No image</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label mb-1">
                            Computed Bundle Total <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">₱</span>
                            <input type="text" class="form-control" id="bundleTotal" readonly value="0.00">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- BUNDLE VIEW MODAL -->
<div class="modal fade" id="bundleViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title" id="bundleViewTitle">Bundle Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body small">
        <p class="mb-2">
            <strong>Bundle Code:</strong> <span id="bundleViewCode"></span><br>
            <strong>Total Price:</strong> ₱<span id="bundleViewTotal"></span>
        </p>
        <div class="table-responsive" style="max-height:260px;overflow-y:auto;">
            <table class="table table-sm align-middle mb-0 table-sticky-header">
                <thead class="table-light small">
                    <tr>
                        <th>Product</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Subtotal</th>
                    </tr>
                </thead>
                <tbody id="bundleViewBody"></tbody>
            </table>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- IMAGE VIEW MODAL -->
<div class="modal fade" id="imageViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-body p-2 text-center">
        <img src="" id="imageViewImg" alt=""
             style="max-width:100%;max-height:80vh;object-fit:contain;">
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// --- JS-side price map for auto computing bundle total ---
const bundlePrices = {
    <?php
    $pairs = [];
    foreach ($singleProducts as $p) {
        $pairs[] = (int)$p['id'] . ':' . (float)$p['price'];
    }
    echo implode(',', $pairs);
    ?>
};

// Bundle details for view & edit
const bundleDetails = <?=
    json_encode($bundleItemsByBundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
?>;

let categoryModal, productModal, bundleModal, bundleViewModal, imageViewModal;

document.addEventListener('DOMContentLoaded', () => {
    categoryModal   = new bootstrap.Modal(document.getElementById('categoryModal'));
    productModal    = new bootstrap.Modal(document.getElementById('productModal'));
    bundleModal     = new bootstrap.Modal(document.getElementById('bundleModal'));
    bundleViewModal = new bootstrap.Modal(document.getElementById('bundleViewModal'));
    imageViewModal  = new bootstrap.Modal(document.getElementById('imageViewModal'));
});

// --- Image zoom modal ---
function openImageView(src) {
    const img = document.getElementById('imageViewImg');
    img.src = src;
    imageViewModal.show();
}

// --- Category modal open ---
function openCategoryModal(mode, id = null, name = '') {
    const title   = document.getElementById('categoryModalTitle');
    const action  = document.getElementById('categoryAction');
    const idInput = document.getElementById('categoryId');
    const nameInp = document.getElementById('categoryName');

    if (mode === 'create') {
        title.textContent = 'Add Category';
        action.value = 'create_category';
        idInput.value = '';
        nameInp.value = '';
    } else {
        title.textContent = 'Edit Category';
        action.value = 'edit_category';
        idInput.value = id || '';
        nameInp.value = name || '';
    }
    categoryModal.show();
}

// --- Product modal open ---
function openProductModal(mode, id = null, name = '', code = '', price = '', catId = 0, imgPath = '') {
    const title = document.getElementById('productModalTitle');
    const action = document.getElementById('productAction');
    const idInput = document.getElementById('productId');
    const nameInp = document.getElementById('productName');
    const codeInp = document.getElementById('productCode');
    const priceInp = document.getElementById('productPrice');
    const catSel = document.getElementById('productCategoryId');
    const existingImg = document.getElementById('productExistingImage');
    const previewImg = document.getElementById('productPreviewImg');
    const noImg = document.getElementById('productNoImg');

    if (mode === 'create') {
        title.textContent = 'Add Product';
        action.value = 'create_product';
        idInput.value = '';
        nameInp.value = '';
        codeInp.value = '';
        priceInp.value = '';
        catSel.value = '';
        existingImg.value = '';
        previewImg.style.display = 'none';
        noImg.style.display = 'block';
    } else {
        title.textContent = 'Edit Product';
        action.value = 'edit_product';
        idInput.value = id || '';
        nameInp.value = name || '';
        codeInp.value = code || '';
        priceInp.value = price || '';
        catSel.value = catId || '';
        existingImg.value = imgPath || '';
        if (imgPath) {
            previewImg.src = imgPath;
            previewImg.style.display = 'block';
            noImg.style.display = 'none';
        } else {
            previewImg.style.display = 'none';
            noImg.style.display = 'block';
        }
    }
    productModal.show();
}

// --- Compute bundle total from checked items ---
function updateBundleTotal() {
    let total = 0;
    document.querySelectorAll('.bundle-prod-checkbox').forEach(cb => {
        if (!cb.checked) return;
        const id = parseInt(cb.value);
        const price = bundlePrices[id] || 0;
        const qtyInput = document.querySelector(`input[name="bundle_qty[${id}]"]`);
        const qty = qtyInput ? Math.max(1, parseInt(qtyInput.value) || 1) : 1;
        total += price * qty;
    });
    document.getElementById('bundleTotal').value = total.toFixed(2);
}

// --- Bundle modal open (create/edit) ---
function openBundleModal(mode, id = null, name = '', code = '', imgPath = '') {
    const title = document.getElementById('bundleModalTitle');
    const action = document.getElementById('bundleAction');
    const idInput = document.getElementById('bundleId');
    const nameInp = document.getElementById('bundleName');
    const codeInp = document.getElementById('bundleCode');
    const existingImg = document.getElementById('bundleExistingImage');
    const previewImg = document.getElementById('bundlePreviewImg');
    const noImg = document.getElementById('bundleNoImg');

    // Reset all checkboxes and qtys
    document.querySelectorAll('.bundle-prod-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.bundle-qty-input').forEach(inp => inp.value = 1);

    if (mode === 'create') {
        title.textContent = 'Add Bundle';
        action.value = 'create_bundle';
        idInput.value = '';
        nameInp.value = '';
        codeInp.value = '';
        existingImg.value = '';
        previewImg.style.display = 'none';
        noImg.style.display = 'block';
    } else {
        title.textContent = 'Edit Bundle';
        action.value = 'edit_bundle';
        idInput.value = id || '';
        nameInp.value = name || '';
        codeInp.value = code || '';
        existingImg.value = imgPath || '';
        if (imgPath) {
            previewImg.src = imgPath;
            previewImg.style.display = 'block';
            noImg.style.display = 'none';
        } else {
            previewImg.style.display = 'none';
            noImg.style.display = 'block';
        }

        // Pre-check items based on bundleDetails
        const details = bundleDetails[id] || [];
        details.forEach(it => {
            const cb = document.querySelector(`.bundle-prod-checkbox[value="${it.id}"]`);
            if (cb) {
                cb.checked = true;
                const qtyInput = document.querySelector(`input[name="bundle_qty[${it.id}]"]`);
                if (qtyInput) qtyInput.value = it.quantity;
            }
        });
    }

    updateBundleTotal();
    bundleModal.show();
}

// --- Bundle VIEW modal open ---
function openBundleViewModal(id, name, code, total) {
    const title = document.getElementById('bundleViewTitle');
    const codeSpan = document.getElementById('bundleViewCode');
    const totalSpan = document.getElementById('bundleViewTotal');
    const tbody = document.getElementById('bundleViewBody');

    title.textContent = name || 'Bundle Details';
    codeSpan.textContent = code || '';
    totalSpan.textContent = (total || 0).toFixed(2);

    tbody.innerHTML = '';
    const items = bundleDetails[id] || [];
    if (!items.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-2">No items found for this bundle.</td></tr>';
    } else {
        items.forEach(it => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${it.name}</td>
                <td class="text-center">${it.quantity}</td>
                <td class="text-end">₱${parseFloat(it.price).toFixed(2)}</td>
                <td class="text-end">₱${parseFloat(it.subtotal).toFixed(2)}</td>
            `;
            tbody.appendChild(tr);
        });
    }

    bundleViewModal.show();
}

// --- Delete bundle ---
function deleteBundle(id, name) {
    if (confirm(`Are you sure you want to delete the bundle "${name}"? This action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'post';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_bundle">
            <input type="hidden" name="bundle_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
</body>
</html>
