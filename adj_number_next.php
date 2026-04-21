<?php
/**
 * adj_number_next.php
 * Returns the next available adjustment number for a given format ID.
 * Mirrors number_format_next.php but checks inventory_adjustments table.
 */
require_once 'config/bootstrap.php';
requireAuth();
header('Content-Type: application/json');

$format_id = (int)($_GET['format_id'] ?? 0);
if (!$format_id) {
    echo json_encode(['success' => false, 'message' => 'No format ID.']); exit;
}

try {
    $pdo = db();

    $s = $pdo->prepare("SELECT * FROM number_formats WHERE id = ? AND doc_type = 'stock_adjustment'");
    $s->execute([$format_id]);
    $fmt = $s->fetch();
    if (!$fmt) {
        echo json_encode(['success' => false, 'message' => 'Format not found.']); exit;
    }

    echo json_encode([
        'success' => true,
        'number'  => adjNextAvailable($pdo, $fmt['format']),
        'format'  => $fmt['format'],
        'id'      => $fmt['id'],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function adjNextAvailable(PDO $pdo, string $format): string {
    $now    = new DateTime();
    $year   = (int)$now->format('Y');
    $seqKey = substr(preg_replace('/\[(YYYY|YY|MM|DD)\]/', '', $format), 0, 50);

    $pdo->prepare("INSERT IGNORE INTO invoice_sequences (prefix, year, next_no) VALUES (?, ?, 1)")
        ->execute([$seqKey, $year]);

    $stmt = $pdo->prepare("SELECT next_no FROM invoice_sequences WHERE prefix = ? AND year = ?");
    $stmt->execute([$seqKey, $year]);
    $seq = (int)$stmt->fetchColumn();

    $check = $pdo->prepare("SELECT COUNT(*) FROM inventory_adjustments WHERE adj_no = ?");
    for ($i = 0; $i < 10000; $i++, $seq++) {
        $candidate = adjApplyFormat($format, $seq, $now);
        $check->execute([$candidate]);
        if (!(int)$check->fetchColumn()) {
            return $candidate;
        }
    }

    return adjApplyFormat($format, $seq, $now);
}

function adjApplyFormat(string $format, int $seq, DateTime $now): string {
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
