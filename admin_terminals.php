<?php
require_once 'config.php';
if (empty($_SESSION['admin'])) {
    header('Location: admin_login.php');
    exit;
}

function random_pin() {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

// Handle create/update/regenerate
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create'])) {
        $name = $_POST['name'] ?? '';
        $type = $_POST['type'] ?? '';
        $employee = $_POST['employee'] ?? '';
        $pin = random_pin();

        $stmt = $pdo->prepare("INSERT INTO terminals (name, type, employee_name, pin_code) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $type, $employee, $pin]);
    } elseif (isset($_POST['regen'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pin = random_pin();
            $stmt = $pdo->prepare("UPDATE terminals SET pin_code = ? WHERE id = ?");
            $stmt->execute([$pin, $id]);
        }
    }
}

$stmt = $pdo->query("SELECT * FROM terminals ORDER BY id");
$terminals = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Terminal Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #e0f2fe; /* Pastel blue background */
        }
        .navbar {
            background-color: #bae6fd !important; /* Soft blue navbar */
            border-bottom: 2px solid #7dd3fc;
        }
        .navbar-brand {
            color: #1e40af !important; /* Darker blue for text */
            font-weight: 600;
        }
        .btn-outline-light {
            border-color: #1e40af;
            color: #1e40af;
        }
        .btn-outline-light:hover {
            background-color: #1e40af;
            border-color: #1e40af;
        }
        .card {
            background-color: #f0f9ff; /* Very light blue card */
            border: 1px solid #bae6fd;
        }
        .card-header {
            background-color: #bae6fd !important; /* Soft blue header */
            color: #1e40af !important;
            border-bottom: 1px solid #7dd3fc;
        }
        .card-body {
            color: #1e40af;
        }
        .form-label {
            color: #1e40af;
            font-weight: 500;
        }
        .form-control {
            border-color: #bae6fd;
            background-color: #f0f9ff;
            color: #1e40af;
        }
        .form-control:focus {
            border-color: #7dd3fc;
            box-shadow: 0 0 0 0.2rem rgba(186, 230, 253, 0.25);
        }
        .form-select {
            border-color: #bae6fd;
            background-color: #f0f9ff;
            color: #1e40af;
        }
        .btn-danger {
            background-color: #1e40af;
            border-color: #1e40af;
        }
        .btn-danger:hover {
            background-color: #0f172a;
            border-color: #0f172a;
        }
        .btn-outline-primary {
            border-color: #1e40af;
            color: #1e40af;
        }
        .btn-outline-primary:hover {
            background-color: #1e40af;
            border-color: #1e40af;
        }
        .table {
            color: #1e40af;
        }
        .table-light th {
            background-color: #f0f9ff !important;
            color: #1e40af;
            border-color: #bae6fd;
        }
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: #f0f9ff;
        }
        .badge {
            background-color: #1e40af !important;
        }
        .text-muted {
            color: #64748b !important;
        }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3">
    <div class="container-fluid">
        <span class="navbar-brand">Terminal Management</span>
        <a href="admin_dashboard.php" class="btn btn-outline-light btn-sm ms-auto">Back to Dashboard</a>
    </div>
</nav>

<div class="container">
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-danger text-white">
                    <strong>Create Terminal</strong>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="create" value="1">
                        <div class="mb-3">
                            <label class="form-label">Terminal Name</label>
                            <input type="text" name="name" class="form-control" required placeholder="e.g. Kiosk 1">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <option value="CUSTOMER">CUSTOMER</option>
                                <option value="TELLER">TELLER</option>
                                <option value="KITCHEN">KITCHEN</option>
                                <option value="CLAIM">CLAIM</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Employee Name</label>
                            <input type="text" name="employee" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-danger w-100">Create</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-warning">
                    <strong>Existing Terminals</strong>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Employee</th>
                                <th>PIN</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($terminals as $t): ?>
                                <tr>
                                    <td><?= (int)$t['id'] ?></td>
                                    <td><?= htmlspecialchars($t['name']) ?></td>
                                    <td><?= htmlspecialchars($t['type']) ?></td>
                                    <td><?= htmlspecialchars($t['employee_name']) ?></td>
                                    <td><span class="badge bg-dark"><?= htmlspecialchars($t['pin_code']) ?></span></td>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                            <button type="submit" name="regen" value="1"
                                                    class="btn btn-sm btn-outline-primary">
                                                Regenerate PIN
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$terminals): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No terminals yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
