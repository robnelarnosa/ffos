<?php
session_start();
require_once 'config.php';
$customerTerminalId = $_SESSION['terminal_id'] ?? null;

header('Content-Type: application/json');

// CSRF Protection: Validate CSRF token before processing request
$submittedToken = $_POST['csrf_token'] ?? '';
if (!validate_csrf_token($submittedToken)) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$itemsJson = $_POST['items'] ?? '';
if (!$itemsJson) {
    echo json_encode(['success' => false, 'error' => 'No items']);
    exit;
}

$items = json_decode($itemsJson, true);
if (!is_array($items) || !count($items)) {
    echo json_encode(['success' => false, 'error' => 'Invalid items']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Compute total
    $total = 0;
    foreach ($items as $it) {
        $price = (float)($it['price'] ?? 0);
        $qty   = (int)($it['qty'] ?? 0);
        if ($qty <= 0 || $price < 0) continue;
        $total += $price * $qty;
    }
    if ($total <= 0) {
        throw new Exception('Total cannot be zero.');
    }

    // Compute next display_number for today
    $today = date('Y-m-d');
    $maxStmt = $pdo->prepare("
        SELECT MAX(display_number) AS max_dn
        FROM orders
        WHERE DATE(created_at) = ?
    ");
    $maxStmt->execute([$today]);
    $maxDnRow = $maxStmt->fetch(PDO::FETCH_ASSOC);
    $nextDn   = (int)($maxDnRow['max_dn'] ?? 0) + 1;

    $now = date('Y-m-d H:i:s');

    // -------------------------------
    // PATCH: Assign a hardcoded teller ID
    $tellerTerminalId = 1; // set this to the ID of your teller
    // -------------------------------

    // Insert order (only change: teller_terminal_id)
    $insOrder = $pdo->prepare("
        INSERT INTO orders (display_number, total_amount, status, created_at, updated_at, terminal_id, teller_terminal_id)
        VALUES (?, ?, 'UNPAID', ?, ?, ?, ?)
    ");
    $insOrder->execute([
        $nextDn,
        $total,
        $now,
        $now,
        $customerTerminalId,
        $tellerTerminalId
    ]);
    $orderId = (int)$pdo->lastInsertId();

    // Insert items (unchanged)
    $insItem = $pdo->prepare("
        INSERT INTO order_items (order_id, menu_item_id, quantity, price, source)
        VALUES (?, ?, ?, ?, 'CUSTOMER')
    ");

    foreach ($items as $it) {
        $menuId = (int)($it['id'] ?? 0);
        $qty    = (int)($it['qty'] ?? 0);
        $price  = (float)($it['price'] ?? 0);
        if ($menuId <= 0 || $qty <= 0 || $price < 0) continue;
        $insItem->execute([$orderId, $menuId, $qty, $price]);
    }

    $pdo->commit();

    // WebSocket notification (unchanged)
    if (function_exists('send_ws_message')) {
        send_ws_message([
            'type'      => 'order_created',
            'order_id'  => $orderId,
            'order_num' => $nextDn,
        ]);
    }

    echo json_encode([
        'success'       => true,
        'order_id'      => $orderId,
        'order_number'  => str_pad($nextDn, 4, '0', STR_PAD_LEFT),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // show real error message for debugging
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
