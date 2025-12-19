<?php
require_once 'config.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if ($u === ADMIN_USER && $p === ADMIN_PASS) {
        $_SESSION['admin'] = true;
        header('Location: admin_dashboard.php');
        exit;
    } else {
        $error = 'Invalid credentials';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #e0f2fe; /* Pastel blue background */
            min-height: 100vh;
        }
        .card {
            background-color: #f0f9ff; /* Very light blue card */
            border: 1px solid #bae6fd;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .card-body {
            color: #1e40af;
        }
        .card-body h2 {
            color: #1e40af;
            font-weight: 600;
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
        .btn-danger {
            background-color: #1e40af;
            border-color: #1e40af;
        }
        .btn-danger:hover {
            background-color: #0f172a;
            border-color: #0f172a;
        }
        .alert-danger {
            background-color: #fecaca;
            border-color: #fca5a5;
            color: #dc2626;
        }
        .text-decoration-none {
            color: #1e40af;
        }
        .text-decoration-none:hover {
            color: #0f172a;
        }
    </style>
</head>
<body class="d-flex align-items-center" style="min-height:100vh;">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h4 mb-3 text-center">Admin Login</h2>
                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-danger w-100">Login</button>
                    </form>
                    <div class="text-center mt-3">
                        <a href="index.php" class="text-decoration-none">&larr; Back to Home</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
