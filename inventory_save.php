<?php
ob_start();
require_once 'config/bootstrap.php';
requireAuth();
require_once 'inventory_helper.php';

$pdo    = db();
$action = $_POST['action'] ?? '';
$user   = authUser();

header('Content-Type: application/json');

function jsonOk(string $msg = 'OK', array $extra = []): void {
    ob_end_clean();
    echo json_encode(array_merge(['success' => true, 'message' => $msg], $extra));
    exit;
}
function jsonFail(string $msg): void {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// ── Ensure tables exist + nullable product_id (DDL outside transaction) ──────
try {
    // Allow product_id to be NULL for multi-product adjustments (header row)
    $pdo->exec("ALTER TABLE inventory_adjustments MODIFY COLUMN product_id INT UNSIGNED DEFAULT NULL");
} catch (Exception $e) {} // Ignore if already nullable or column doesn't exist
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS adj_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        adj_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        qty_before DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
        qty_after  DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
        qty_change DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
        INDEX idx_adj_items_adj (adj_id),
        INDEX idx_adj_items_prod (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS adj_attachments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        adj_id INT UNSIGNED NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        uploaded_by INT UNSIGNED DEFAULT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_adj_att (adj_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

function saveAdjAttachments(PDO $pdo, int $adjId, int $userId): void {
    if (empty($_FILES['attachments']['name'][0])) return;
    $attachDir   = APP_ROOT . '/storage/attachments';
    if (!is_dir($attachDir)) mkdir($attachDir, 0755, true);
    $allowedMime = ['application/pdf','image/jpeg','image/png','application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $maxSize = 10 * 1024 * 1024;
    $stmt = $pdo->prepare("INSERT INTO adj_attachments (adj_id, original_name, stored_name, uploaded_by) VALUES (?, ?, ?, ?)");
    foreach ($_FILES['attachments']['tmp_name'] as $k => $tmp) {
        if ($_FILES['attachments']['error'][$k] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['attachments']['size'][$k] > $maxSize) continue;
        if (!in_array(mime_content_type($tmp), $allowedMime)) continue;
        $ext = pathinfo($_FILES['attachments']['name'][$k], PATHINFO_EXTENSION);
        $safeName = uniqid('att_', true) . '.' . strtolower($ext);
        if (move_uploaded_file($tmp, $attachDir . '/' . $safeName))
            $stmt->execute([$adjId, $_FILES['attachments']['name'][$k], $safeName, $userId]);
    }
}

function parseItems(PDO $pdo): array {
    $raw = $_POST['items'] ?? [];
    if (!is_array($raw) || empty($raw)) jsonFail('Please add at least one product.');
    $items = [];
    foreach ($raw as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $qtyChange = $row['qty_change'] ?? '';
        if ($productId <= 0) jsonFail('Invalid product in items.');
        if ($qtyChange === '' || !is_numeric($qtyChange)) jsonFail('Please enter a valid quantity change for all products.');
        $qtyChange = (float)$qtyChange;
        if (abs($qtyChange) < 0.00001) jsonFail('Quantity change cannot be zero for any product.');
        $prod = $pdo->prepare("SELECT id, track_inventory FROM products WHERE id = ?");
        $prod->execute([$productId]);
        $prod = $prod->fetch();
        if (!$prod) jsonFail('Product ID ' . $productId . ' not found.');
        if (!$prod['track_inventory']) jsonFail('Inventory tracking not enabled for product ID ' . $productId . '.');
        $items[] = ['product_id' => $productId, 'qty_change' => $qtyChange, 'item_id' => (int)($row['item_id'] ?? 0)];
    }
    $pids = array_column($items, 'product_id');
    if (count($pids) !== count(array_unique($pids))) jsonFail('Each product can only appear once per adjustment.');
    return $items;
}

// ── CREATE ────────────────────────────────────────────────────────────────────
if ($action === 'create_adjustment') {
    $adjNo     = trim($_POST['adj_no']    ?? '');
    $reference = trim($_POST['reference'] ?? '');
    $notes     = trim($_POST['notes']     ?? '');
    if (!$adjNo) jsonFail('Adjustment number is missing.');
    $dup = $pdo->prepare("SELECT COUNT(*) FROM inventory_adjustments WHERE adj_no = ?");
    $dup->execute([$adjNo]);
    if ((int)$dup->fetchColumn()) jsonFail('Adjustment number already exists. Please refresh and try again.');

    $items = parseItems($pdo);
    foreach ($items as &$item) {
        $s = getStockSummary($pdo, $item['product_id']);
        $item['qty_before'] = (float)$s['qty_on_hand'];
        $item['qty_after']  = $item['qty_before'] + $item['qty_change'];
        $item['avg_cost']   = (float)$s['avg_cost'];
        if ($item['qty_after'] < -0.00001) jsonFail('Product ID ' . $item['product_id'] . ' would result in negative stock (' . number_format($item['qty_after'], 4) . ').');
    }
    unset($item);

    $adjId = 0;
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO inventory_adjustments (adj_no, product_id, qty_before, qty_after, qty_change, reference, notes, created_by) VALUES (?, NULL, 0, 0, 0, ?, ?, ?)")
            ->execute([$adjNo, $reference, $notes, $user['id'] ?? null]);
        $adjId = (int)$pdo->lastInsertId();
        $iStmt = $pdo->prepare("INSERT INTO adj_items (adj_id, product_id, qty_before, qty_after, qty_change) VALUES (?, ?, ?, ?, ?)");
        foreach ($items as $item) {
            $iStmt->execute([$adjId, $item['product_id'], $item['qty_before'], $item['qty_after'], $item['qty_change']]);
            if ($item['qty_change'] > 0)
                inventoryStockIn($pdo, $item['product_id'], 'adjustment', $item['qty_change'], $item['avg_cost'], $adjNo, $notes ?: 'Adjustment '.$adjNo, $user['id'] ?? null);
            else
                inventoryStockOut($pdo, $item['product_id'], 'adjustment', abs($item['qty_change']), $adjNo, $notes ?: 'Adjustment '.$adjNo, $user['id'] ?? null);
        }
        $formatId = (int)($_POST['adj_format_id'] ?? 0);
        if ($formatId > 0) {
            $fmtRow = $pdo->prepare("SELECT format FROM number_formats WHERE id = ? AND doc_type = 'stock_adjustment'");
            $fmtRow->execute([$formatId]); $fmtRow = $fmtRow->fetch();
            if ($fmtRow) {
                $seqKey = substr(preg_replace('/\[(YYYY|YY|MM|DD)\]/', '', $fmtRow['format']), 0, 50);
                $pdo->prepare("UPDATE invoice_sequences SET next_no = next_no + 1 WHERE prefix = ? AND year = ?")->execute([$seqKey, (int)date('Y')]);
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonFail('Error: ' . $e->getMessage());
    }
    saveAdjAttachments($pdo, $adjId, $user['id'] ?? 0);
    flash('success', 'Adjustment ' . $adjNo . ' saved.');
    jsonOk('Adjustment saved.', ['redirect' => 'inventory_adjustment.php?action=view&id=' . $adjId]);
}

// ── UPDATE ────────────────────────────────────────────────────────────────────
if ($action === 'update_adjustment') {
    $id        = (int)($_POST['id']        ?? 0);
    $reference = trim($_POST['reference']  ?? '');
    $notes     = trim($_POST['notes']      ?? '');
    if ($id <= 0) jsonFail('Invalid ID.');
    $adj = $pdo->prepare("SELECT * FROM inventory_adjustments WHERE id = ?");
    $adj->execute([$id]); $adj = $adj->fetch();
    if (!$adj) jsonFail('Adjustment not found.');

    $items = parseItems($pdo);

    try {
        $pdo->beginTransaction();

        // Reverse all existing movements for this adjustment
        $existingItems = $pdo->prepare("SELECT product_id FROM adj_items WHERE adj_id = ?");
        $existingItems->execute([$id]);
        foreach ($existingItems->fetchAll() as $ei) {
            $mov = $pdo->prepare("SELECT id FROM inventory_movements WHERE product_id = ? AND reference = ? AND type = 'adjustment' ORDER BY created_at DESC LIMIT 1");
            $mov->execute([$ei['product_id'], $adj['adj_no']]);
            $movRow = $mov->fetch();
            if ($movRow) inventoryDeleteMovement($pdo, (int)$movRow['id']);
        }
        // Also legacy single-product
        if ($adj['product_id']) {
            $mov = $pdo->prepare("SELECT id FROM inventory_movements WHERE product_id = ? AND reference = ? AND type = 'adjustment' ORDER BY created_at DESC LIMIT 1");
            $mov->execute([$adj['product_id'], $adj['adj_no']]);
            $movRow = $mov->fetch();
            if ($movRow) inventoryDeleteMovement($pdo, (int)$movRow['id']);
        }

        $pdo->prepare("DELETE FROM adj_items WHERE adj_id = ?")->execute([$id]);

        $iStmt = $pdo->prepare("INSERT INTO adj_items (adj_id, product_id, qty_before, qty_after, qty_change) VALUES (?, ?, ?, ?, ?)");
        foreach ($items as $item) {
            $s = getStockSummary($pdo, $item['product_id']);
            $qtyBefore = (float)$s['qty_on_hand'];
            $qtyAfter  = $qtyBefore + $item['qty_change'];
            if ($qtyAfter < -0.00001) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                jsonFail('Product ID ' . $item['product_id'] . ' would result in negative stock (' . number_format($qtyAfter, 4) . ').');
            }
            $iStmt->execute([$id, $item['product_id'], $qtyBefore, $qtyAfter, $item['qty_change']]);
            if ($item['qty_change'] > 0)
                inventoryStockIn($pdo, $item['product_id'], 'adjustment', $item['qty_change'], (float)$s['avg_cost'], $adj['adj_no'], $notes ?: 'Adjustment '.$adj['adj_no'], $user['id'] ?? null);
            else
                inventoryStockOut($pdo, $item['product_id'], 'adjustment', abs($item['qty_change']), $adj['adj_no'], $notes ?: 'Adjustment '.$adj['adj_no'], $user['id'] ?? null);
        }
        $pdo->prepare("UPDATE inventory_adjustments SET reference = ?, notes = ? WHERE id = ?")->execute([$reference, $notes, $id]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonFail('Error: ' . $e->getMessage());
    }
    saveAdjAttachments($pdo, $id, $user['id'] ?? 0);
    jsonOk('Changes saved.');
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete_adjustment') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) jsonFail('Invalid ID.');
    $adj = $pdo->prepare("SELECT * FROM inventory_adjustments WHERE id = ?");
    $adj->execute([$id]); $adj = $adj->fetch();
    if (!$adj) jsonFail('Adjustment not found.');
    try {
        $atts = $pdo->prepare("SELECT stored_name FROM adj_attachments WHERE adj_id = ?");
        $atts->execute([$id]);
        foreach ($atts->fetchAll() as $att) { $fp = APP_ROOT.'/storage/attachments/'.$att['stored_name']; if(file_exists($fp)) unlink($fp); }
    } catch (Exception $e) {}
    try {
        $pdo->beginTransaction();
        $eitems = $pdo->prepare("SELECT product_id FROM adj_items WHERE adj_id = ?");
        $eitems->execute([$id]);
        foreach ($eitems->fetchAll() as $ei) {
            $mov = $pdo->prepare("SELECT id FROM inventory_movements WHERE product_id=? AND reference=? AND type='adjustment' ORDER BY created_at DESC LIMIT 1");
            $mov->execute([$ei['product_id'], $adj['adj_no']]);
            $mr = $mov->fetch(); if ($mr) inventoryDeleteMovement($pdo, (int)$mr['id']);
        }
        if ($adj['product_id']) {
            $mov = $pdo->prepare("SELECT id FROM inventory_movements WHERE product_id=? AND reference=? AND type='adjustment' ORDER BY created_at DESC LIMIT 1");
            $mov->execute([$adj['product_id'], $adj['adj_no']]);
            $mr = $mov->fetch(); if ($mr) inventoryDeleteMovement($pdo, (int)$mr['id']);
        }
        $pdo->prepare("DELETE FROM adj_items WHERE adj_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM adj_attachments WHERE adj_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM inventory_adjustments WHERE id=?")->execute([$id]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonFail('Error: ' . $e->getMessage());
    }
    jsonOk('Adjustment deleted.');
}

// ── STOCK IN ──────────────────────────────────────────────────────────────────
if ($action === 'stock_in') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $type      = in_array($_POST['type'] ?? '', ['purchase','opening']) ? $_POST['type'] : 'purchase';
    $qty       = (float)($_POST['qty'] ?? 0);
    $unitCost  = (float)($_POST['unit_cost'] ?? 0);
    $reference = trim($_POST['reference'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');
    if ($productId <= 0) jsonFail('Invalid product.');
    if ($qty <= 0) jsonFail('Quantity must be greater than 0.');
    $prod = $pdo->prepare("SELECT id FROM products WHERE id = ?"); $prod->execute([$productId]);
    if (!$prod->fetch()) jsonFail('Product not found.');
    try { $pdo->beginTransaction(); inventoryStockIn($pdo, $productId, $type, $qty, $unitCost, $reference, $notes, $user['id'] ?? null); $pdo->commit(); }
    catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); jsonFail('Error: ' . $e->getMessage()); }
    flash('success', 'Stock recorded successfully.');
    jsonOk('Stock recorded.', ['redirect' => 'inventory.php?action=ledger&product_id=' . $productId]);
}

// ── DELETE MOVEMENT ───────────────────────────────────────────────────────────
if ($action === 'delete_movement') {
    $movementId = (int)($_POST['movement_id'] ?? 0);
    if ($movementId <= 0) jsonFail('Invalid movement ID.');
    $mov = $pdo->prepare("SELECT * FROM inventory_movements WHERE id = ?"); $mov->execute([$movementId]); $mov = $mov->fetch();
    if (!$mov) jsonFail('Movement not found.');
    if ($mov['type'] === 'sale') jsonFail('Sale movements cannot be deleted here.');
    try { $pdo->beginTransaction(); inventoryDeleteMovement($pdo, $movementId); $pdo->commit(); }
    catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); jsonFail('Error: ' . $e->getMessage()); }
    jsonOk('Movement deleted.');
}

ob_end_clean();
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
exit;
