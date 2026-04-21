<?php
/**
 * delete_adj_attachment.php
 * Removes an adjustment attachment record and its file from disk.
 */
require_once 'config/bootstrap.php';
requireAuth();

$attId = (int)($_GET['id']  ?? 0);
$adjId = (int)($_GET['adj'] ?? 0);

if (!$attId || !$adjId) redirect('inventory_adjustment.php');

try {
    $stmt = db()->prepare("SELECT * FROM adj_attachments WHERE id = ? AND adj_id = ?");
    $stmt->execute([$attId, $adjId]);
    $att = $stmt->fetch();

    if ($att) {
        // Delete physical file
        $filePath = APP_ROOT . '/storage/attachments/' . $att['stored_name'];
        if (file_exists($filePath)) unlink($filePath);

        // Delete DB record
        db()->prepare("DELETE FROM adj_attachments WHERE id = ?")->execute([$attId]);

        flash('success', 'Attachment removed.');
    }
} catch (Exception $e) {
    flash('error', 'Could not remove attachment.');
}

redirect("inventory_adjustment.php?action=edit&id=$adjId");
