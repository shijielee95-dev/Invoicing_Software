<?php
require_once 'config/bootstrap.php';
requireAuth();

$pdo = db();
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// ── Delete ────────────────────────────────────────────────────────
if (!empty($_POST['delete'])) {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
    try {
        $pdo->prepare("DELETE FROM payment_terms WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
    redirect('payment_terms.php');
}

// ── Collect ───────────────────────────────────────────────────────
$id   = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$desc = trim($_POST['description'] ?? '');

$allowedTypes = ['days','day_of_month','day_of_foll_month','end_of_month','days_after_month'];
$type  = in_array($_POST['type'] ?? '', $allowedTypes) ? $_POST['type'] : 'days';
$value = max(0, (int)($_POST['value'] ?? 0));
$paymentMode = in_array($_POST['payment_mode'] ?? '', ['cash','credit']) ? $_POST['payment_mode'] : 'cash';

$lateActive = (int)(!empty($_POST['late_interest_active']));
$lateRate   = trim($_POST['late_interest_rate'] ?? '');
$lateRate   = $lateRate !== '' ? round((float)$lateRate, 4) : null;

if ($name === '') {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Name is required.']); exit; }
    flash('error', 'Name is required.');
    redirect($id ? "payment_terms.php?action=edit&id=$id" : 'payment_terms.php?action=new');
}

// ── Save ──────────────────────────────────────────────────────────
try {
    if ($id > 0) {
        $pdo->prepare("
            UPDATE payment_terms
               SET name=?, description=?, type=?, value=?, payment_mode=?,
                   late_interest_active=?, late_interest_rate=?
             WHERE id=?
        ")->execute([$name, $desc, $type, $value, $paymentMode, $lateActive, $lateRate, $id]);
        flash('success', 'Payment term updated.');
    } else {
        $pdo->prepare("
            INSERT INTO payment_terms (name, description, type, value, payment_mode, late_interest_active, late_interest_rate)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$name, $desc, $type, $value, $paymentMode, $lateActive, $lateRate]);
        $id = (int)$pdo->lastInsertId();
        flash('success', 'Payment term created.');
    }
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>true,'message'=>$id ? 'Payment term updated.' : 'Payment term created.','id'=>$id]); exit; }
    redirect("payment_terms.php?action=edit&id=$id");
} catch (Exception $e) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Save failed: '.$e->getMessage()]); exit; }
    flash('error', 'Save failed: ' . $e->getMessage());
    redirect($id ? "payment_terms.php?action=edit&id=$id" : 'payment_terms.php?action=new');
}
