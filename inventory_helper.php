<?php
/**
 * inventory_helper.php
 * Core inventory logic: FIFO and Weighted Average costing.
 *
 * All functions accept a live PDO connection so they can participate
 * in the caller's transaction if needed.
 */

/**
 * Read the configured costing method from app_settings.
 * Returns 'fifo' or 'average' (default: 'average').
 */
function inventoryMethod(PDO $pdo): string {
    try {
        $row = $pdo->query("SELECT `value` FROM app_settings WHERE `key` = 'inventory_method' LIMIT 1")->fetch();
        return ($row && $row['value'] === 'fifo') ? 'fifo' : 'average';
    } catch (Exception $e) {
        return 'average';
    }
}

/**
 * Get or initialise the stock summary row for a product.
 */
function getStockSummary(PDO $pdo, int $productId): array {
    $row = $pdo->prepare("SELECT * FROM product_stock_summary WHERE product_id = ?");
    $row->execute([$productId]);
    $row = $row->fetch();
    if (!$row) {
        $pdo->prepare("INSERT IGNORE INTO product_stock_summary (product_id, qty_on_hand, avg_cost) VALUES (?,0,0)")
            ->execute([$productId]);
        return ['product_id' => $productId, 'qty_on_hand' => 0, 'avg_cost' => 0];
    }
    return $row;
}

/**
 * Record a STOCK-IN movement (opening balance, purchase, or positive adjustment).
 *
 * @param PDO    $pdo
 * @param int    $productId
 * @param string $type       'opening' | 'purchase' | 'adjustment'
 * @param float  $qty        must be > 0
 * @param float  $unitCost   cost per base unit
 * @param string $reference  e.g. "PO-001", "Manual Adj"
 * @param string $notes
 * @param int|null $createdBy
 * @return int  movement id
 */
function inventoryStockIn(
    PDO $pdo,
    int $productId,
    string $type,
    float $qty,
    float $unitCost,
    string $reference = '',
    string $notes = '',
    ?int $createdBy = null,
    ?int $invoiceId = null
): int {
    if ($qty <= 0) throw new InvalidArgumentException("Stock-in qty must be > 0");

    $method = inventoryMethod($pdo);

    // 1. Write movement record
    $stmt = $pdo->prepare("
        INSERT INTO inventory_movements (product_id, invoice_id, type, qty, unit_cost, reference, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$productId, $invoiceId, $type, $qty, $unitCost, $reference, $notes, $createdBy]);
    $movementId = (int)$pdo->lastInsertId();

    // 2. Update stock summary
    $summary = getStockSummary($pdo, $productId);
    $newQty  = (float)$summary['qty_on_hand'] + $qty;

    if ($method === 'fifo') {
        // FIFO: push a new layer
        $pdo->prepare("
            INSERT INTO inventory_fifo_layers (product_id, movement_id, qty_remaining, unit_cost)
            VALUES (?, ?, ?, ?)
        ")->execute([$productId, $movementId, $qty, $unitCost]);

        // avg_cost = simple recalc from remaining layers (used as display only in FIFO)
        $totalValue = recalcFifoValue($pdo, $productId);
        $newAvg     = $newQty > 0 ? $totalValue / $newQty : 0;
    } else {
        // Weighted Average: recalculate avg_cost
        $oldValue = (float)$summary['qty_on_hand'] * (float)$summary['avg_cost'];
        $addValue = $qty * $unitCost;
        $newAvg   = $newQty > 0 ? ($oldValue + $addValue) / $newQty : 0;
    }

    $pdo->prepare("
        INSERT INTO product_stock_summary (product_id, qty_on_hand, avg_cost)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE qty_on_hand = ?, avg_cost = ?
    ")->execute([$productId, $newQty, $newAvg, $newQty, $newAvg]);

    return $movementId;
}

/**
 * Record a STOCK-OUT movement (sale or negative adjustment).
 *
 * @param float $qty  must be > 0 (will be stored as negative internally)
 * @return int  movement id
 */
function inventoryStockOut(
    PDO $pdo,
    int $productId,
    string $type,
    float $qty,
    string $reference = '',
    string $notes = '',
    ?int $createdBy = null,
    ?int $invoiceId = null
): int {
    if ($qty <= 0) throw new InvalidArgumentException("Stock-out qty must be > 0");

    $method  = inventoryMethod($pdo);
    $summary = getStockSummary($pdo, $productId);

    // Determine the cost we're consuming
    if ($method === 'fifo') {
        $unitCost = consumeFifoLayers($pdo, $productId, $qty);
    } else {
        $unitCost = (float)$summary['avg_cost'];
    }

    // Write movement (negative qty)
    $stmt = $pdo->prepare("
        INSERT INTO inventory_movements (product_id, invoice_id, type, qty, unit_cost, reference, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$productId, $invoiceId, $type, -$qty, $unitCost, $reference, $notes, $createdBy]);
    $movementId = (int)$pdo->lastInsertId();

    // Update qty on hand — allow negative so over-sold stock is visible
    $newQty = (float)$summary['qty_on_hand'] - $qty;
    $newAvg = $method === 'fifo' && $newQty > 0
        ? (recalcFifoValue($pdo, $productId) / $newQty)
        : (float)$summary['avg_cost'];

    $pdo->prepare("
        INSERT INTO product_stock_summary (product_id, qty_on_hand, avg_cost)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE qty_on_hand = ?, avg_cost = ?
    ")->execute([$productId, $newQty, $newAvg, $newQty, $newAvg]);

    return $movementId;
}

/**
 * Consume FIFO layers for a stock-out, returns the weighted average cost consumed.
 */
function consumeFifoLayers(PDO $pdo, int $productId, float $qty): float {
    $layers = $pdo->prepare("
        SELECT * FROM inventory_fifo_layers
        WHERE product_id = ? AND qty_remaining > 0
        ORDER BY created_at ASC, id ASC
        FOR UPDATE
    ");
    $layers->execute([$productId]);
    $layers = $layers->fetchAll();

    $remaining    = $qty;
    $totalCost    = 0.0;
    $totalConsumed = 0.0;

    foreach ($layers as $layer) {
        if ($remaining <= 0) break;
        $take       = min((float)$layer['qty_remaining'], $remaining);
        $totalCost += $take * (float)$layer['unit_cost'];
        $totalConsumed += $take;
        $remaining     -= $take;

        $newQtyRemaining = (float)$layer['qty_remaining'] - $take;
        $pdo->prepare("UPDATE inventory_fifo_layers SET qty_remaining = ? WHERE id = ?")
            ->execute([$newQtyRemaining, $layer['id']]);
    }

    return $totalConsumed > 0 ? $totalCost / $totalConsumed : 0;
}

/**
 * Recalculate total value from remaining FIFO layers (for avg_cost display).
 */
function recalcFifoValue(PDO $pdo, int $productId): float {
    $row = $pdo->prepare("
        SELECT COALESCE(SUM(qty_remaining * unit_cost), 0) AS total_value
        FROM inventory_fifo_layers
        WHERE product_id = ? AND qty_remaining > 0
    ");
    $row->execute([$productId]);
    return (float)$row->fetchColumn();
}

/**
 * Delete a movement and fully rebuild the product's stock summary + FIFO layers.
 * Used when a movement is deleted from the ledger.
 */
function inventoryDeleteMovement(PDO $pdo, int $movementId): void {
    $mov = $pdo->prepare("SELECT * FROM inventory_movements WHERE id = ?");
    $mov->execute([$movementId]);
    $mov = $mov->fetch();
    if (!$mov) return;

    $productId = (int)$mov['product_id'];

    // Delete the movement (cascade handles fifo_layers row)
    $pdo->prepare("DELETE FROM inventory_movements WHERE id = ?")->execute([$movementId]);

    // Rebuild from scratch
    rebuildStockSummary($pdo, $productId);
}

/**
 * Full rebuild of product_stock_summary and inventory_fifo_layers
 * by replaying all movements in chronological order.
 * Call this after any destructive change.
 */
function rebuildStockSummary(PDO $pdo, int $productId): void {
    $method = inventoryMethod($pdo);

    // Wipe FIFO layers for this product
    $pdo->prepare("DELETE FROM inventory_fifo_layers WHERE product_id = ?")->execute([$productId]);
    $pdo->prepare("DELETE FROM product_stock_summary WHERE product_id = ?")->execute([$productId]);

    $movements = $pdo->prepare("
        SELECT * FROM inventory_movements WHERE product_id = ? ORDER BY created_at ASC, id ASC
    ");
    $movements->execute([$productId]);
    $movements = $movements->fetchAll();

    $qtyOnHand = 0.0;
    $avgCost   = 0.0;

    foreach ($movements as $m) {
        $qty  = (float)$m['qty'];
        $cost = (float)$m['unit_cost'];

        if ($qty > 0) {
            // Stock-in
            if ($method === 'fifo') {
                $pdo->prepare("
                    INSERT INTO inventory_fifo_layers (product_id, movement_id, qty_remaining, unit_cost, created_at)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$productId, $m['id'], $qty, $cost, $m['created_at']]);
            }
            $oldValue  = $qtyOnHand * $avgCost;
            $qtyOnHand += $qty;
            $avgCost   = $qtyOnHand > 0 ? ($oldValue + $qty * $cost) / $qtyOnHand : 0;
        } else {
            // Stock-out — allow negative
            $outQty = abs($qty);
            if ($method === 'fifo') {
                consumeFifoLayers($pdo, $productId, $outQty);
            }
            $qtyOnHand -= $outQty;
        }
    }

    $pdo->prepare("
        INSERT INTO product_stock_summary (product_id, qty_on_hand, avg_cost)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE qty_on_hand = ?, avg_cost = ?
    ")->execute([$productId, $qtyOnHand, $avgCost, $qtyOnHand, $avgCost]);
}

/**
 * Convenience: get qty_on_hand for a product (0 if not tracked).
 */
function getQtyOnHand(PDO $pdo, int $productId): float {
    $row = $pdo->prepare("SELECT qty_on_hand FROM product_stock_summary WHERE product_id = ?");
    $row->execute([$productId]);
    $row = $row->fetch();
    return $row ? (float)$row['qty_on_hand'] : 0.0;
}

/**
 * Reverse all inventory movements linked to an invoice.
 * Deletes the movements and rebuilds stock summaries for affected products.
 * Call this inside a transaction before re-applying new movements (edit mode)
 * or when deleting an invoice.
 */
function reverseInvoiceInventory(PDO $pdo, int $invoiceId): void {
    // Find all affected products
    $stmt = $pdo->prepare("SELECT DISTINCT product_id FROM inventory_movements WHERE invoice_id = ?");
    $stmt->execute([$invoiceId]);
    $productIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($productIds)) return;

    // Delete all movements for this invoice
    $pdo->prepare("DELETE FROM inventory_movements WHERE invoice_id = ?")->execute([$invoiceId]);

    // Rebuild stock summary for each affected product
    foreach ($productIds as $pid) {
        rebuildStockSummary($pdo, (int)$pid);
    }
}
