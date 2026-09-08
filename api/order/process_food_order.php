<?php
// process_food_order.php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

require_once '../session_config.php';
require_once '../db.php';

$userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$outletOrdersJson = $_POST['outlet_orders'] ?? '';
$orderNotes = substr($_POST['order_notes'] ?? '', 0, 1000);

// ALWAYS USE CURRENT REAL-TIME (IGNORE FRONTEND DATE)
$orderDateTime = date('Y-m-d H:i:s'); // Real-time at submission

if (empty($outletOrdersJson)) {
    echo json_encode(['success' => false, 'message' => 'No orders submitted']);
    exit;
}

$outletOrders = json_decode($outletOrdersJson, true);
if (!is_array($outletOrders)) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON order format']);
    exit;
}

$conn->begin_transaction();

try {
    $inserted = [];
    $orderCount = 0;
    $FEE_LIMIT = 70.0;

    foreach ($outletOrders as $outletId => $outletData) {
        $isCashRequest = ($outletId === 'cash_request');
        $items = $outletData['items'] ?? [];
        $outletName = $outletData['name'] ?? 'Unknown';

        if (empty($items)) continue;

        $formattedItems = [];
        foreach ($items as $item) {
            $name  = trim($item['description'] ?? '');
            $total = (float) ($item['price'] ?? 0);

            if ($name !== '' && $total > 0) {
                $formattedItems[] = "{$name} @ " . number_format($total, 2);
            }
        }

        $orderDetails = implode(",", $formattedItems);

        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += (float) ($item['price'] ?? 0);
        }

        $extraFee = max(0, $subtotal - $FEE_LIMIT);
        $dbOutletId = $isCashRequest ? 0 : intval($outletId);
        $orderType = $isCashRequest ? 'cash_request' : 'food_order';

        $stmt = $conn->prepare("
            INSERT INTO orders
            (user_id, outlet_id, order_date, order_items, total_amount, overfee_amount, order_type, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) throw new Exception("DB prepare failed: " . $conn->error);

        $stmt->bind_param(
            "iissddss",
            $userId,
            $dbOutletId,
            $orderDateTime,  // REAL-TIME
            $orderDetails,
            $subtotal,
            $extraFee,
            $orderType,
            $orderNotes
        );

        if (!$stmt->execute()) {
            throw new Exception("DB execute failed: " . $stmt->error);
        }

        $inserted[] = $stmt->insert_id;
        $orderCount++;
        $stmt->close();
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => "{$orderCount} order(s) submitted successfully at " . date('h:i A'),
        'orders_inserted' => $inserted,
        'timestamp' => $orderDateTime
    ]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("Food order error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>