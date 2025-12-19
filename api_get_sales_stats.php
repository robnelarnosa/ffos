<?php
require_once 'config.php';

header('Content-Type: application/json');

// Today's date
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

// Revenue today
$stmt = $pdo->prepare("SELECT SUM(total_amount) AS revenue FROM orders WHERE DATE(created_at) = ? AND status != 'CANCELLED'");
$stmt->execute([$today]);
$revenue = (float)($stmt->fetch(PDO::FETCH_ASSOC)['revenue'] ?? 0);

// Orders today
$stmt = $pdo->prepare("SELECT COUNT(*) AS orders_count FROM orders WHERE DATE(created_at) = ? AND status != 'CANCELLED'");
$stmt->execute([$today]);
$ordersCount = (int)($stmt->fetch(PDO::FETCH_ASSOC)['orders_count'] ?? 0);

// Items in demand (top 5 by quantity sold today)
$stmt = $pdo->prepare("
    SELECT mi.name, SUM(oi.quantity) AS total_qty
    FROM order_items oi
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    JOIN orders o ON oi.order_id = o.id
    WHERE DATE(o.created_at) = ? AND o.status != 'CANCELLED'
    GROUP BY mi.id, mi.name
    ORDER BY total_qty DESC
    LIMIT 5
");
$stmt->execute([$today]);
$itemsDemand = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Orders per minute
$startOfDay = date('Y-m-d 00:00:00');
$minutesElapsed = (strtotime($now) - strtotime($startOfDay)) / 60;
$ordersPerMinute = $minutesElapsed > 0 ? $ordersCount / $minutesElapsed : 0;

echo json_encode([
    'success' => true,
    'revenue' => $revenue,
    'orders_count' => $ordersCount,
    'items_demand' => $itemsDemand,
    'orders_per_minute' => round($ordersPerMinute, 2),
]);
