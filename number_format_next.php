<?php
/**
 * number_format_next.php
 * Returns the next available number for a given format.
 * Uses the correct sequence table and document table based on doc_type.
 */
require_once 'config/bootstrap.php';
requireAuth();
header('Content-Type: application/json');

$format_id = (int)($_GET['format_id'] ?? 0);
if (!$format_id) {
    echo json_encode(['success'=>false,'message'=>'No format ID.']); exit;
}

// Map doc_type to sequence table and document table
$tableMap = [
    'invoice'          => ['seq' => 'invoice_sequences',   'doc' => 'invoices',   'col' => 'invoice_no'],
    'quotation'        => ['seq' => 'quotation_sequences', 'doc' => 'quotations', 'col' => 'quotation_no'],
    'sales_order'      => ['seq' => 'invoice_sequences',   'doc' => 'invoices',   'col' => 'invoice_no'],
    'delivery_order'   => ['seq' => 'invoice_sequences',   'doc' => 'invoices',   'col' => 'invoice_no'],
    'credit_note'      => ['seq' => 'invoice_sequences',   'doc' => 'invoices',   'col' => 'invoice_no'],
    'purchase_order'   => ['seq' => 'invoice_sequences',   'doc' => 'invoices',   'col' => 'invoice_no'],
    'goods_received'   => ['seq' => 'invoice_sequences',   'doc' => 'invoices',   'col' => 'invoice_no'],
    'bill'             => ['seq' => 'invoice_sequences',   'doc' => 'invoices',   'col' => 'invoice_no'],
    'purchase_credit'  => ['seq' => 'invoice_sequences',   'doc' => 'invoices',   'col' => 'invoice_no'],
    'official_receipt' => ['seq' => 'invoice_sequences',   'doc' => 'invoices',   'col' => 'invoice_no'],
    'payment_voucher'  => ['seq' => 'invoice_sequences',   'doc' => 'invoices',   'col' => 'invoice_no'],
    'bank_transfer'    => ['seq' => 'invoice_sequences',   'doc' => 'invoices',   'col' => 'invoice_no'],
    'stock_adjustment' => ['seq' => 'invoice_sequences',   'doc' => 'invoices',   'col' => 'invoice_no'],
];

try {
    $pdo = db();

    $s = $pdo->prepare("SELECT * FROM number_formats WHERE id=?");
    $s->execute([$format_id]);
    $fmt = $s->fetch();
    if (!$fmt) { echo json_encode(['success'=>false,'message'=>'Format not found.']); exit; }

    $docType = $fmt['doc_type'];
    $tables  = $tableMap[$docType] ?? ['seq' => 'invoice_sequences', 'doc' => 'invoices', 'col' => 'invoice_no'];

    echo json_encode([
        'success' => true,
        'number'  => nextAvailableNumber($pdo, $fmt['format'], $tables),
        'format'  => $fmt['format'],
    ]);

} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}

function nextAvailableNumber(PDO $pdo, string $format, array $tables): string {
    $now    = new DateTime();
    $year   = (int)$now->format('Y');
    $seqKey = substr(preg_replace('/\[(YYYY|YY|MM|DD)\]/', '', $format), 0, 50);

    $seqTable = $tables['seq'];
    $docTable = $tables['doc'];
    $docCol   = $tables['col'];

    // Ensure sequence row exists
    $pdo->prepare("INSERT IGNORE INTO {$seqTable} (prefix, year, next_no) VALUES (?, ?, 1)")
        ->execute([$seqKey, $year]);

    // Read current sequence value
    $stmt = $pdo->prepare("SELECT next_no FROM {$seqTable} WHERE prefix=? AND year=?");
    $stmt->execute([$seqKey, $year]);
    $seq = (int)$stmt->fetchColumn();

    // Walk forward until candidate doesn't exist in the document table
    $check = $pdo->prepare("SELECT COUNT(*) FROM {$docTable} WHERE {$docCol}=?");
    for ($i = 0; $i < 10000; $i++, $seq++) {
        $candidate = applyFormat($format, $seq, $now);
        $check->execute([$candidate]);
        if ((int)$check->fetchColumn() === 0) {
            return $candidate;
        }
    }

    return applyFormat($format, $seq, $now);
}

function applyFormat(string $format, int $seq, DateTime $now): string {
    $out = str_replace(
        ['[YYYY]', '[YY]', '[MM]', '[DD]'],
        [$now->format('Y'), $now->format('y'), $now->format('m'), $now->format('d')],
        $format
    );
    for ($n = 2; $n <= 8; $n++) {
        $out = str_replace("[{$n}DIGIT]", str_pad((string)$seq, $n, '0', STR_PAD_LEFT), $out);
    }
    return $out;
}
