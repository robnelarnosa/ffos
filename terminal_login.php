<?php
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin = $_POST['pin'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM terminals WHERE pin_code = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$pin]);
    $terminal = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($terminal) {
        $_SESSION['terminal_id'] = $terminal['id'];
        $_SESSION['terminal_type'] = $terminal['type'];
        $_SESSION['employee_name'] = $terminal['employee_name'];

        switch ($terminal['type']) {
            case 'CUSTOMER':
                header('Location: customer_kiosk.php');
                break;
            case 'TELLER':
                header('Location: teller_dashboard.php');
                break;
            case 'KITCHEN':
                header('Location: kitchen_dashboard.php');
                break;
            case 'CLAIM':
                header('Location: claim_display.php');
                break;
        }
        exit;
    } else {
        $error = 'Invalid PIN or inactive terminal.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Terminal Login</title>
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
            font-size: 1.25rem;
        }
        .form-control:focus {
            border-color: #7dd3fc;
            box-shadow: 0 0 0 0.2rem rgba(186, 230, 253, 0.25);
        }
        .btn-warning {
            background-color: #1e40af;
            border-color: #1e40af;
        }
        .btn-warning:hover {
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
                    <h2 class="h4 mb-3 text-center">Terminal Login</h2>
                    <?php if ($error): ?>
                        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <div class="mb-3">
                            <label for="pin" class="form-label">Terminal PIN</label>
                            <input type="password" name="pin" id="pin" maxlength="6"
                                   class="form-control form-control-lg text-center"
                                   autofocus required>
                        </div>
                        <button type="submit" class="btn btn-warning w-100">Login</button>
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
