<?php
/**
 * stock_check.php
 * AJAX endpoint: checks if saving an invoice would cause any tracked products
 * to go into negative stock.
 *
 * Accepts JSON body: { edit_id: 0, items: [{product_id: 1, quantity: 10, name: "..."}] }
 * Returns JSON:      { success: true, warnings: [...] }
 */
require_once 'config/bootstrap.php';

// ── AJAX-safe auth guard ──────────────────────────────────────────────────────
// requireAuth() calls redirect() which breaks AJAX — check manually instead.
header('Content-Type: application/json');

$token = $_COOKIE['login_token'] ?? null;
if (!$token) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please refresh the page.', 'auth_error' => true]);
    exit;
}
$authStmt = db()->prepare("
    SELECT u.id FROM user_sessions us
    JOIN users u ON u.id = us.user_id
    WHERE us.token = ? AND us.expire_at > NOW()
    LIMIT 1
");
$authStmt->execute([$token]);
if (!$authStmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please refresh the page.', 'auth_error' => true]);
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data || !isset($data['items']) || !is_array($data['items'])) {
        echo json_encode(['success' => true, 'warnings' => []]);
        exit;
    }

    $pdo      = db();
    $editId   = (int)($data['edit_id'] ?? 0);
    $warnings = [];

    // Aggregate qty per product_id (same product may appear in multiple rows)
    $productQtys  = [];
    $productNames = [];
    foreach ($data['items'] as $item) {
        $pid = (int)($item['product_id'] ?? 0);
        $qty = (float)($item['quantity'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        if (!isset($productQtys[$pid])) {
            $productQtys[$pid]  = 0;
            $productNames[$pid] = $item['name'] ?? '';
        }
        $productQtys[$pid] += $qty;
    }

    foreach ($productQtys as $pid => $totalQty) {
        $stmt = $pdo->prepare("
            SELECT name, track_inventory,
                   COALESCE(image_path, '')          AS image_path,
                   COALESCE(base_unit_label, 'unit') AS unit_label
            FROM products WHERE id = ?
        ");
        $stmt->execute([$pid]);
        $prod = $stmt->fetch();
        if (!$prod || !(int)$prod['track_inventory']) continue;

        // Current stock on hand
        $stmt2 = $pdo->prepare("SELECT qty_on_hand FROM product_stock_summary WHERE product_id = ?");
        $stmt2->execute([$pid]);
        $row        = $stmt2->fetch();
        $currentQty = $row ? (float)$row['qty_on_hand'] : 0;

        // Edit mode: add back what this invoice previously consumed so we don't double-count
        if ($editId > 0) {
            try {
                $stmt3 = $pdo->prepare(
                    "SELECT COALESCE(SUM(quantity), 0)
                     FROM invoice_items
                     WHERE invoice_id = ? AND product_id = ?"
                );
                $stmt3->execute([$editId, $pid]);
                $currentQty += (float)$stmt3->fetchColumn();
            } catch (\PDOException $e) {
                // product_id column may not exist in older DBs — skip rollback
            }
        }

        $finalQty = $currentQty - $totalQty;

        if ($finalQty < 0) {
            $warnings[] = [
                'name'        => $prod['name'],
                'image_path'  => $prod['image_path'],
                'unit_label'  => $prod['unit_label'],
                'current_qty' => $currentQty,
                'qty'         => $totalQty,
                'final_qty'   => $finalQty,
            ];
        }
    }

    echo json_encode(['success' => true, 'warnings' => $warnings]);

} catch (\Exception $e) {
    echo json_encode(['success' => false, 'warnings' => [], 'message' => 'Stock check failed: ' . $e->getMessage()]);
}
