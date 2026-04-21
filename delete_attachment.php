<?php
/**
 * delete_attachment.php
 * Removes an attachment record and its file from disk.
 * Supports both normal redirect and AJAX (X-Requested-With header).
 */
require_once 'config/bootstrap.php';
requireAuth();

$isAjax    = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
$attId     = (int)($_GET['id']        ?? 0);
$invoiceId = (int)($_GET['invoice']   ?? 0);
$quotationId = (int)($_GET['quotation'] ?? 0);
$docId     = $invoiceId ?: $quotationId;
$docType   = $invoiceId ? 'invoice' : 'quotation';

if (!$attId || !$docId) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false]); exit; }
    redirect('invoice.php');
}

try {
    $pdo = db();

    // Fetch from the correct table
    $table    = $docType === 'invoice' ? 'invoice_attachments' : 'quotation_attachments';
    $fkCol    = $docType === 'invoice' ? 'invoice_id'          : 'quotation_id';
    $docTable = $docType === 'invoice' ? 'invoices'            : 'quotations';
    $action   = $docType === 'invoice' ? 'UPDATE_INVOICE'      : 'UPDATE_QUOTATION';

    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ? AND {$fkCol} = ?");
    $stmt->execute([$attId, $docId]);
    $att = $stmt->fetch();

    if ($att) {
        // Delete physical file
        $filePath = APP_ROOT . '/storage/attachments/' . $att['stored_name'];
        if (file_exists($filePath)) unlink($filePath);

        // Delete DB record
        $pdo->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$attId]);

        // Log against the document so it appears in history
        auditLog($action, $docTable, $docId, [
            'old' => ['_type' => 'attachment_change'],
            'new' => ['_type' => 'attachment_change', 'removed_attachments' => [$att['original_name']]],
        ]);

        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>true]); exit; }
        flash('success', 'Attachment removed.');
    } else {
        if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false]); exit; }
    }
} catch (Exception $e) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
    flash('error', 'Could not remove attachment.');
}

$redirect = $docType === 'invoice'
    ? "invoice.php?action=edit&id={$docId}"
    : "quotation.php?action=edit&id={$docId}";
redirect($redirect);
