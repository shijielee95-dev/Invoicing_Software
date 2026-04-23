<?php
/**
 * save_invoice.php
 * Handles CREATE and EDIT invoice.
 * Supports both traditional form POST (redirect) and AJAX (JSON response).
 */
require_once 'config/bootstrap.php';
requireAuth();

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
    redirect('invoice.php');
}

$pdo    = db();
$editId = (int)($_POST['edit_id'] ?? 0);
$isEdit = $editId > 0;

// ── Collect all inputs ─────────────────────────

$invoiceNo          = trim($_POST['invoice_no']          ?? '');
$referenceNo        = trim($_POST['reference_no']        ?? '');
$status             = $_POST['status']                    ?? 'draft';
$currency           = $_POST['currency']                  ?? 'MYR';
$rate               = (float)($_POST['rate']              ?? 1.0);
$taxMode            = $_POST['tax_mode']                  ?? 'exclusive';
$roundingAdj        = (float)($_POST['rounding_adjustment'] ?? 0);

// Date — Flatpickr submits dd/mm/yyyy, convert to yyyy-mm-dd
$rawDate            = trim($_POST['invoice_date'] ?? '');
$invoiceDate        = convertDate($rawDate);

// Customer fields
$customerName       = trim($_POST['customer_name']       ?? '');
$customerTin        = trim($_POST['customer_tin']        ?? '');
$customerRegNo      = trim($_POST['customer_reg_no']     ?? '');
$customerEmail      = trim($_POST['customer_email']      ?? '');
$customerPhone      = trim($_POST['customer_phone']      ?? '');
$customerAddr       = trim($_POST['customer_address']    ?? '');
$billingAttention   = trim($_POST['billing_attention']   ?? '');
$shippingRef        = trim($_POST['shipping_reference']  ?? '');
$shippingAttention  = trim($_POST['shipping_attention']  ?? '');
$shippingAddress    = trim($_POST['shipping_address']    ?? '');

// Amounts (computed by JS, recalculated server-side below for security)
$jsSubtotal         = (float)($_POST['subtotal']         ?? 0);
$jsDiscount         = (float)($_POST['discount_amount']  ?? 0);
$jsTaxAmount        = (float)($_POST['tax_amount']       ?? 0);
$jsTotalAmount      = (float)($_POST['total_amount']     ?? 0);

// General fields
$description        = trim($_POST['description']         ?? '');
$internalNote       = trim($_POST['internal_note']       ?? '');
$paymentMode        = in_array($_POST['payment_mode'] ?? '', ['cash','credit']) ? $_POST['payment_mode'] : 'cash';
$notes              = trim($_POST['notes']               ?? '');
$paymentTermId      = (int)($_POST['payment_term_id']    ?? 0) ?: null;

// ── Always sync invoices.payment_term_id from the first payment row ──
$paymentsPost   = array_values($_POST['payments'] ?? []);
$firstRowTermId = (int)(($paymentsPost[0]['payment_term_id'] ?? 0)) ?: null;
if ($firstRowTermId) {
    $paymentTermId = $firstRowTermId;
}
// due_date: cash = invoice_date, credit = calculated from term

// ── Normalise for change comparison (cast DB strings to typed values) ──
function normaliseItem(array $it): array {
    return [
        'description'      => (string)($it['description'] ?? ''),
        'item_description' => (string)($it['item_description'] ?? ''),
        'quantity'         => round((float)($it['quantity'] ?? 0), 4),
        'unit_price'       => round((float)($it['unit_price'] ?? 0), 2),
        'discount_pct'     => round((float)($it['discount_pct'] ?? 0), 4),
        'discount_mode'    => (string)($it['discount_mode'] ?? 'pct'),
        'tax_type'         => (string)($it['tax_type'] ?? 'none'),
        'tax_amount'       => round((float)($it['tax_amount'] ?? 0), 2),
        'line_total'       => round((float)($it['line_total'] ?? 0), 2),
        'classification'   => (string)($it['classification'] ?? ''),
        'row_type'         => (string)($it['row_type'] ?? 'item'),
    ];
}
function normalisePayment(array $p): array {
    $amt = str_replace(',', '', (string)($p['amount'] ?? 0));
    $fee = str_replace(',', '', (string)($p['fee_amount'] ?? 0));
    return [
        'term_name'    => (string)($p['term_name'] ?? ''),
        'amount'       => round((float)$amt, 2),
        'fee_amount'   => round((float)$fee, 2),
        'fee_mode'     => (string)($p['fee_mode'] ?? 'fixed'),
        'reference_no' => (string)($p['reference_no'] ?? ''),
        'notes'        => (string)($p['notes'] ?? ''),
    ];
}

function calcDueDate(string $invoiceDate, ?int $termId, PDO $pdo): ?string {
    if (!$termId) return null;
    $row = $pdo->prepare("SELECT type, value FROM payment_terms WHERE id = ?");
    $row->execute([$termId]);
    $term = $row->fetch();
    if (!$term) return null;

    $base  = new DateTime($invoiceDate);
    $value = (int)$term['value'];

    switch ($term['type']) {
        case 'days':
            $base->modify("+$value days");
            return $base->format('Y-m-d');
        case 'day_of_month':
            $due = new DateTime($invoiceDate);
            $due->setDate((int)$due->format('Y'), (int)$due->format('m'), $value);
            if ($due <= $base) $due->modify('+1 month');
            return $due->format('Y-m-d');
        case 'day_of_foll_month':
            $due = new DateTime($invoiceDate);
            $due->modify('+1 month');
            $due->setDate((int)$due->format('Y'), (int)$due->format('m'), $value);
            return $due->format('Y-m-d');
        case 'end_of_month':
            $due = new DateTime($invoiceDate);
            $due->modify("+$value month");
            $due->modify('last day of this month');
            return $due->format('Y-m-d');
        case 'days_after_month':
            $due = new DateTime($invoiceDate);
            $due->modify('last day of this month');
            $due->modify("+$value days");
            return $due->format('Y-m-d');
        default:
            return null;
    }
}

// Status from visible dropdown (overrides hidden if set)
$visibleStatus      = $_POST['status_visible']           ?? '';
if ($visibleStatus !== '') $status = $visibleStatus;

// ── Date conversion helper ─────────────────────
function convertDate(string $raw): string {
    if (!$raw) return date('Y-m-d');
    // Already ISO yyyy-mm-dd
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
    // dd/mm/yyyy from Flatpickr
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $m)) return "$m[3]-$m[2]-$m[1]";
    // Fallback — try strtotime
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
}

// ── Validation ─────────────────────────────────
if (!$invoiceNo || !$customerName || !$invoiceDate) {
    $msg = 'Invoice number, customer name and date are required.';
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$msg]); exit; }
    flash('error', $msg);
    redirect($isEdit ? "invoice.php?action=edit&id=$editId" : 'invoice.php?action=new');
}

$allowedStatus = ['draft','sent','paid','overdue','cancelled'];
if (!in_array($status, $allowedStatus)) $status = 'draft';

// ── Line items — recalculate server-side ───────
// Load tax rates from DB (keyed by id as string, matching what invoice.php POSTs)
$taxRates = ['none' => 0, '' => 0];
try {
    $trRows = $pdo->query("SELECT id, rate FROM tax_rates")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($trRows as $tr) {
        $taxRates[(string)$tr['id']] = (float)$tr['rate'] / 100;
    }
} catch (Exception $e) {}
$lineItems = [];
$serverSubtotal  = 0;
$serverTax       = 0;
$serverDiscount  = 0;

$sectionTotal = 0; // running total for subtotal rows
foreach ($_POST['items'] ?? [] as $i => $item) {
    $rowType   = in_array($item['row_type'] ?? '', ['subtitle','subtotal']) ? $item['row_type'] : 'item';
    $desc      = trim($item['description']      ?? '');
    $descNote  = trim($item['item_description'] ?? '');

    // ── Subtitle row: just text, no numbers ──────────────────────
    if ($rowType === 'subtitle') {
        if (!$desc) continue;
        $lineItems[] = [
            'product_id'       => null,
            'description'      => $desc,
            'item_description' => '',
            'quantity'         => 0,
            'unit_price'       => 0,
            'discount_pct'     => 0,
            'discount_mode'    => 'pct',
            'tax_type'         => '',
            'tax_amount'       => 0,
            'line_total'       => 0,
            'sort_order'       => $i,
            'classification'   => '',
            'row_type'         => 'subtitle',
        ];
        continue;
    }

    // ── Subtotal row: sum of items above since last subtotal ──────
    if ($rowType === 'subtotal') {
        $lineItems[] = [
            'product_id'       => null,
            'description'      => 'Subtotal',
            'item_description' => '',
            'quantity'         => 0,
            'unit_price'       => 0,
            'discount_pct'     => 0,
            'discount_mode'    => 'pct',
            'tax_type'         => '',
            'tax_amount'       => 0,
            'line_total'       => round($sectionTotal, 2),
            'sort_order'       => $i,
            'classification'   => '',
            'row_type'         => 'subtotal',
        ];
        $sectionTotal = 0; // reset for next section
        continue;
    }

    // ── Normal item row ───────────────────────────────────────────
    $qty       = (float)($item['quantity']       ?? 1);
    $price     = (float)($item['unit_price']     ?? 0);
    $prodId    = (int)($item['product_id']       ?? 0) ?: null;
    $taxType   = array_key_exists((string)($item['tax_type'] ?? ''), $taxRates) ? (string)($item['tax_type'] ?? '') : 'none';
    $discMode  = ($item['discount_mode'] ?? 'pct') === 'fixed' ? 'fixed' : 'pct';

    $discPct   = (float)($item['discount_pct'] ?? 0);
    $discRaw   = trim($item['discount_raw'] ?? $item['discount_raw_num'] ?? '');
    if ($discRaw !== '' && $discPct == 0) {
        if (str_ends_with($discRaw, '%')) {
            $discPct  = (float)rtrim($discRaw, '%');
            $discMode = 'pct';
        } else {
            $discPct  = (float)$discRaw;
            $discMode = 'fixed';
        }
    }

    if (!$desc || $qty <= 0) continue;

    $gross   = $qty * $price;
    $discAmt = $discMode === 'fixed' ? $discPct : $gross * ($discPct / 100);
    $base    = $gross - $discAmt;
    $taxRate = $taxRates[$taxType] ?? 0;

    if ($taxMode === 'inclusive') {
        $taxAmt    = $taxRate > 0 ? $base - ($base / (1 + $taxRate)) : 0;
        $lineTotal = $base;
    } else {
        $taxAmt    = $base * $taxRate;
        $lineTotal = $base + $taxAmt;
    }

    $preTax          = ($taxMode === 'inclusive' && $taxRate > 0) ? ($base / (1 + $taxRate)) : $base;
    $serverSubtotal += $preTax;
    $serverTax      += $taxAmt;
    $serverDiscount += $discAmt;
    $sectionTotal   += $preTax; // accumulate for next subtotal row

    $lineItems[] = [
        'product_id'       => $prodId,
        'description'      => $desc,
        'item_description' => $descNote,
        'quantity'         => $qty,
        'unit_price'       => $price,
        'discount_pct'     => $discPct,
        'discount_mode'    => $discMode,
        'tax_type'         => $taxType,
        'tax_amount'       => round($taxAmt, 2),
        'line_total'       => round($lineTotal, 2),
        'sort_order'       => $i,
        'classification'   => trim($item['classification'] ?? ''),
        'row_type'         => 'item',
    ];
}

$hasRealItems = !empty(array_filter($lineItems, fn($li) => ($li['row_type'] ?? 'item') === 'item'));
if (!$hasRealItems) {
    $msg = 'At least one valid line item is required.';
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$msg]); exit; }
    flash('error', $msg);
    redirect($isEdit ? "invoice.php?action=edit&id=$editId" : 'invoice.php?action=new');
}

// Final totals (server-calculated — always trust server over JS)
$serverSubtotal  = round($serverSubtotal, 2);
$serverTax       = round($serverTax, 2);
$serverDiscount  = round($serverDiscount, 2);
$serverTotal     = round($serverSubtotal + $serverTax + $roundingAdj, 2);

// ── Overall tax type ───────────────────────────
$usedTaxTypes = array_unique(array_column($lineItems, 'tax_type'));
$usedTaxTypes = array_filter($usedTaxTypes, fn($t) => $t !== 'none');
$invTaxType   = !empty($usedTaxTypes) ? reset($usedTaxTypes) : 'none';

// ── Customer ID lookup ─────────────────────────
$customerId = null;
$cStmt = $pdo->prepare("SELECT id FROM customers WHERE customer_name = ? LIMIT 1");
$cStmt->execute([$customerName]);
$cRow = $cStmt->fetch();
if ($cRow) $customerId = $cRow['id'];

// ── Handle file attachments ─────────────────────
// Files are uploaded via attachments[] — save to storage/attachments/
$attachmentPaths = [];
// IDs of existing attachments marked for deletion by the user
$deleteAttachmentIds = array_filter(array_map('intval', $_POST['delete_attachment_ids'] ?? []));
if (!empty($_FILES['attachments']['name'][0])) {
    $attachDir = APP_ROOT . '/storage/attachments';
    if (!is_dir($attachDir)) mkdir($attachDir, 0755, true);

    $allowedMime = ['application/pdf','image/jpeg','image/png','application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    $maxSize     = 10 * 1024 * 1024; // 10MB

    foreach ($_FILES['attachments']['tmp_name'] as $k => $tmp) {
        if ($_FILES['attachments']['error'][$k] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['attachments']['size'][$k] > $maxSize) continue;

        $mime = mime_content_type($tmp);
        if (!in_array($mime, $allowedMime)) continue;

        $ext      = pathinfo($_FILES['attachments']['name'][$k], PATHINFO_EXTENSION);
        $safeName = uniqid('att_', true) . '.' . strtolower($ext);
        $dest     = $attachDir . '/' . $safeName;

        if (move_uploaded_file($tmp, $dest)) {
            $attachmentPaths[] = [
                'original' => $_FILES['attachments']['name'][$k],
                'stored'   => $safeName,
            ];
        }
    }
}

// ── Save to database ───────────────────────────
try {
    $pdo->beginTransaction();

    $fields = [
        'invoice_no'          => $invoiceNo,
        'invoice_format_id'   => (int)($_POST['invoice_format_id'] ?? 0) ?: null,
        'reference_no'        => $referenceNo,
        'customer_id'         => $customerId,
        'customer_name'       => $customerName,
        'customer_tin'        => $customerTin,
        'customer_reg_no'     => $customerRegNo,
        'customer_email'      => $customerEmail,
        'customer_phone'      => $customerPhone,
        'customer_address'    => $customerAddr,
        'billing_attention'   => $billingAttention,
        'shipping_reference'  => $shippingRef,
        'shipping_attention'  => $shippingAttention,
        'shipping_address'    => $shippingAddress,
        'invoice_date'        => $invoiceDate,
        'due_date'            => ($paymentMode === 'cash') ? $invoiceDate : calcDueDate($invoiceDate, $firstRowTermId, $pdo),
        'subtotal'            => $serverSubtotal,
        'discount_amount'     => $serverDiscount,
        'tax_type'            => $invTaxType,
        'tax_amount'          => $serverTax,
        'rounding_adjustment' => $roundingAdj,
        'total_amount'        => $serverTotal,
        'currency'            => $currency,
        'rate'                => $rate,
        'tax_mode'            => $taxMode,
        'description'         => $description,
        'internal_note'       => $internalNote,
        'notes'               => $notes,
        'payment_mode'        => $paymentMode,
        'payment_term_id'     => $paymentTermId,
        'status'              => $status,
    ];

    if ($isEdit) {
        // Snapshot old data for audit trail
        $oldStmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $oldStmt->execute([$editId]);
        $oldData = $oldStmt->fetch();

        // Snapshot old items
        $oldItemsStmt = $pdo->prepare("SELECT description, item_description, quantity, unit_price, discount_pct, discount_mode, tax_type, tax_amount, line_total, classification, row_type FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order, id");
        $oldItemsStmt->execute([$editId]);
        $oldItems = $oldItemsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Snapshot old attachment names for audit
        $oldAttStmt = $pdo->prepare("SELECT id, original_name FROM invoice_attachments WHERE invoice_id=? ORDER BY id");
        $oldAttStmt->execute([$editId]);
        $oldAttRows = $oldAttStmt->fetchAll(PDO::FETCH_ASSOC);
        $oldAttachmentNames = array_column($oldAttRows, 'original_name');

        // Snapshot old payments
        $oldPmtStmt = $pdo->prepare("SELECT ip.amount, ip.reference_no, ip.notes, COALESCE(pt.name,'') AS term_name FROM invoice_payments ip LEFT JOIN payment_terms pt ON pt.id=ip.payment_term_id WHERE ip.invoice_id=? ORDER BY ip.id");
        $oldPmtStmt->execute([$editId]);
        $oldPayments = $oldPmtStmt->fetchAll(PDO::FETCH_ASSOC);

        $set    = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $vals   = array_values($fields);
        $vals[] = $editId;
        $pdo->prepare("UPDATE invoices SET $set WHERE id = ?")->execute($vals);
        $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$editId]);
        $invoiceId = $editId;

        // Build removed attachment names for audit
        $removedAttachmentNames = [];
        foreach ($oldAttRows as $row) {
            if (in_array($row['id'], $deleteAttachmentIds)) {
                $removedAttachmentNames[] = $row['original_name'];
            }
        }

        // Build new payments snapshot for audit
        $newPaymentsAudit = [];
        foreach ($paymentsPost as $pmt) {
            $pmtAmtRaw = str_replace(',', '', (string)($pmt['amount'] ?? 0));
            $pmtAmt    = round((float)$pmtAmtRaw, 2);
            if ($pmtAmt <= 0) continue;

            $pmtFeeRaw = str_replace(',', '', (string)($pmt['fee_amount'] ?? 0));
            $pmtFee    = round((float)$pmtFeeRaw, 2);
            $pmtMode   = in_array($pmt['fee_mode'] ?? '', ['fixed', 'pct']) ? $pmt['fee_mode'] : 'fixed';

            $pmtTermId = (int)($pmt['payment_term_id'] ?? 0) ?: null;
            $termName  = '';
            if ($pmtTermId) {
                try {
                    $ts = $pdo->prepare("SELECT name FROM payment_terms WHERE id=?");
                    $ts->execute([$pmtTermId]);
                    $termName = $ts->fetchColumn() ?: '';
                } catch (Exception $e) {}
            }
            $newPaymentsAudit[] = [
                'term_name'    => $termName,
                'amount'       => $pmtAmt,
                'fee_amount'   => $pmtFee,
                'fee_mode'     => $pmtMode,
                'reference_no' => trim($pmt['reference_no'] ?? ''),
                'notes'        => trim($pmt['notes'] ?? ''),
            ];
        }

        // Build new items snapshot for audit
        $newItemsAudit = [];
        foreach ($lineItems as $li) {
            $newItemsAudit[] = [
                'description'      => $li['description'],
                'item_description' => $li['item_description'],
                'quantity'         => $li['quantity'],
                'unit_price'       => $li['unit_price'],
                'discount_pct'     => $li['discount_pct'],
                'discount_mode'    => $li['discount_mode'],
                'tax_type'         => $li['tax_type'],
                'tax_amount'       => $li['tax_amount'],
                'line_total'       => $li['line_total'],
                'classification'   => $li['classification'],
                'row_type'         => $li['row_type'] ?? 'item',
            ];
        }

        // ── Only log if something actually changed ─────────────────
        $auditOld = [
                'invoice_no'       => $oldData['invoice_no'],
                'customer'         => $oldData['customer_name'],
                'reference_no'     => $oldData['reference_no'] ?? '',
                'invoice_date'     => $oldData['invoice_date'] ?? '',
                'due_date'         => $oldData['due_date'] ?? '',
                'currency'         => $oldData['currency'] ?? 'MYR',
                'rate'             => round((float)($oldData['rate'] ?? 1.0), 5),
                'status'           => $oldData['status'],
                'payment_mode'     => $oldData['payment_mode'] ?? 'cash',
                'payment_term_id'  => $oldData['payment_term_id'] ?? null,
                'description'      => $oldData['description'] ?? '',
                'internal_note'    => $oldData['internal_note'] ?? '',
                'notes'            => $oldData['notes'] ?? '',
                'billing_attention'   => $oldData['billing_attention'] ?? '',
                'customer_address'    => $oldData['customer_address'] ?? '',
                'shipping_reference'  => $oldData['shipping_reference'] ?? '',
                'shipping_attention'  => $oldData['shipping_attention'] ?? '',
                'shipping_address'    => $oldData['shipping_address'] ?? '',
                'subtotal'         => round((float)$oldData['subtotal'], 2),
                'discount'         => round((float)$oldData['discount_amount'], 2),
                'tax'              => round((float)$oldData['tax_amount'], 2),
                'rounding'         => round((float)($oldData['rounding_adjustment'] ?? 0), 2),
                'total_amount'     => round((float)$oldData['total_amount'], 2),
                'tax_mode'         => $oldData['tax_mode'] ?? 'exclusive',
                'items'            => $oldItems,
                'payments'         => $oldPayments,

        ];
        $auditDue = ($paymentMode === 'cash') ? $invoiceDate : calcDueDate($invoiceDate, $firstRowTermId, $pdo);
        $auditNew = [
                'invoice_no'       => $invoiceNo,
                'customer'         => $customerName,
                'reference_no'     => $referenceNo,
                'invoice_date'     => $invoiceDate,
                'due_date'         => $auditDue,
                'currency'         => $currency,
                'rate'             => $rate,
                'status'           => $status,
                'payment_mode'     => $paymentMode,
                'payment_term_id'  => $paymentTermId,
                'description'      => $description,
                'internal_note'    => $internalNote,
                'notes'            => $notes,
                'billing_attention'   => $billingAttention,
                'customer_address'    => $customerAddr,
                'shipping_reference'  => $shippingRef,
                'shipping_attention'  => $shippingAttention,
                'shipping_address'    => $shippingAddress,
                'subtotal'         => $serverSubtotal,
                'discount'         => $serverDiscount,
                'tax'              => $serverTax,
                'rounding'         => $roundingAdj,
                'total_amount'     => $serverTotal,
                'tax_mode'         => $taxMode,
                'items'            => $newItemsAudit,
                'payments'         => $newPaymentsAudit,
                'added_attachments'   => array_column($attachmentPaths, 'original'),
                'removed_attachments' => $removedAttachmentNames,
        ];
        // Compare: strip items/payments for scalar comparison, then compare items separately
        // Cast all scalar fields to string for type-safe comparison
        $normaliseScalar = function(array $data): array {
            $skip = ['items', 'payments', 'added_attachments', 'removed_attachments'];
            $out = [];
            foreach ($data as $k => $v) {
                if (in_array($k, $skip)) continue;
                $out[$k] = is_float($v) ? number_format($v, 2, '.', '') : (string)($v ?? '');
            }
            return $out;
        };
        $oldScalar = $normaliseScalar($auditOld);
        $newScalar = $normaliseScalar($auditNew);
        $itemsChanged    = json_encode(array_map('normaliseItem', $oldItems))
                        !== json_encode(array_map('normaliseItem', $newItemsAudit));
        $paymentsChanged = json_encode(array_map('normalisePayment', $oldPayments))
                        !== json_encode(array_map('normalisePayment', $newPaymentsAudit));
        // New attachments uploaded = always a change
        $attachmentsChanged = !empty($attachmentPaths) || !empty($deleteAttachmentIds);
        $hasChanges = ($oldScalar !== $newScalar) || $itemsChanged || $paymentsChanged || $attachmentsChanged;

        if ($hasChanges) {
            auditLog('UPDATE_INVOICE', 'invoices', $invoiceId, [
                'old' => $auditOld,
                'new' => $auditNew,
            ]);
        }

    } else {
        // ── Generate invoice number atomically ─────────────────────────────────
        // Never trust the POSTed invoice_no — always generate fresh inside the
        // transaction with FOR UPDATE to prevent duplicate entries under concurrency.
        $year            = date('Y');
        $invoiceFormatId = (int)($_POST['invoice_format_id'] ?? 0);

        // Load format
        $fmtRow = false;
        if ($invoiceFormatId > 0) {
            $s = $pdo->prepare("SELECT format FROM number_formats WHERE id=?");
            $s->execute([$invoiceFormatId]);
            $fmtRow = $s->fetch();
        }
        // Fallback: use first invoice format available
        if (!$fmtRow) {
            $fmtRow = $pdo->query("SELECT format FROM number_formats WHERE doc_type='invoice' ORDER BY id LIMIT 1")->fetch();
        }

        if ($fmtRow) {
            $format = $fmtRow['format'];
            $seqKey = substr(preg_replace('/\[(YYYY|YY|MM|DD)\]/', '', $format), 0, 50);

            // Ensure row exists, then lock it exclusively for this transaction
            $pdo->prepare("INSERT IGNORE INTO invoice_sequences (prefix, year, next_no) VALUES (?, ?, 1)")
                ->execute([$seqKey, $year]);
            $lockStmt = $pdo->prepare("SELECT next_no FROM invoice_sequences WHERE prefix=? AND year=? FOR UPDATE");
            $lockStmt->execute([$seqKey, $year]);
            $seq = (int)$lockStmt->fetchColumn();

            // Walk forward past any numbers already in invoices (handles gaps from manual entry etc.)
            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_no=?");
            $now = new DateTime();
            for ($i = 0; $i < 10000; $i++, $seq++) {
                $candidate = str_replace(
                    ['[YYYY]','[YY]','[MM]','[DD]'],
                    [$now->format('Y'),$now->format('y'),$now->format('m'),$now->format('d')],
                    $format
                );
                for ($n = 2; $n <= 8; $n++) {
                    $candidate = str_replace("[{$n}DIGIT]", str_pad((string)$seq, $n, '0', STR_PAD_LEFT), $candidate);
                }
                $checkStmt->execute([$candidate]);
                if ((int)$checkStmt->fetchColumn() === 0) break;
            }
            $invoiceNo = $candidate;
        } else {
            // No format at all — safe unique fallback
            $invoiceNo = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            $seqKey    = null;
        }

        // Inject generated number into fields
        $fields['invoice_no'] = $invoiceNo;

        $cols   = implode(', ', array_keys($fields));
        $pholds = implode(', ', array_fill(0, count($fields), '?'));
        $pdo->prepare("INSERT INTO invoices ($cols) VALUES ($pholds)")->execute(array_values($fields));
        $invoiceId = (int)$pdo->lastInsertId();

        // Bump sequence to one past what we just used
        if (!empty($seqKey)) {
            $pdo->prepare("UPDATE invoice_sequences SET next_no=? WHERE prefix=? AND year=?")
                ->execute([$seq + 1, $seqKey, $year]);
        }

        // Build new items snapshot for audit
        $newItemsAuditCreate = [];
        foreach ($lineItems as $li) {
            $newItemsAuditCreate[] = [
                'description'      => $li['description'],
                'item_description' => $li['item_description'],
                'quantity'         => $li['quantity'],
                'unit_price'       => $li['unit_price'],
                'discount_pct'     => $li['discount_pct'],
                'discount_mode'    => $li['discount_mode'],
                'tax_type'         => $li['tax_type'],
                'tax_amount'       => $li['tax_amount'],
                'line_total'       => $li['line_total'],
                'classification'   => $li['classification'],
                'row_type'         => $li['row_type'] ?? 'item',
            ];
        }

        auditLog('CREATE_INVOICE', 'invoices', $invoiceId, [
            'new' => [
                'invoice_no'       => $invoiceNo,
                'customer'         => $customerName,
                'reference_no'     => $referenceNo,
                'invoice_date'     => $invoiceDate,
                'due_date'         => ($paymentMode === 'cash') ? $invoiceDate : calcDueDate($invoiceDate, $firstRowTermId, $pdo),
                'currency'         => $currency,
                'status'           => $status,
                'payment_mode'     => $paymentMode,
                'payment_term_id'  => $paymentTermId,
                'description'      => $description,
                'internal_note'    => $internalNote,
                'notes'            => $notes,
                'billing_attention'   => $billingAttention,
                'customer_address'    => $customerAddr,
                'shipping_reference'  => $shippingRef,
                'shipping_attention'  => $shippingAttention,
                'shipping_address'    => $shippingAddress,
                'subtotal'         => $serverSubtotal,
                'discount'         => $serverDiscount,
                'tax'              => $serverTax,
                'rounding'         => $roundingAdj,
                'total_amount'     => $serverTotal,
                'items'            => $newItemsAuditCreate,
            ],
        ]);
    }

    // ── Insert line items ──────────────────────
    $iStmt = $pdo->prepare("
        INSERT INTO invoice_items
            (invoice_id, product_id, description, item_description, quantity, unit_price,
             discount_pct, discount_mode, tax_type, tax_amount, line_total, sort_order, classification, row_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($lineItems as $item) {
        $iStmt->execute([
            $invoiceId,
            $item['product_id'],
            $item['description'],
            $item['item_description'],
            $item['quantity'],
            $item['unit_price'],
            $item['discount_pct'],
            $item['discount_mode'],
            $item['tax_type'],
            $item['tax_amount'],
            $item['line_total'],
            $item['sort_order'],
            $item['classification'],
            $item['row_type'] ?? 'item',
        ]);
    }

    // ── Inventory: deduct stock for tracked products ──────────
    require_once __DIR__ . '/inventory_helper.php';

    // For edit mode, reverse old movements first so we can re-apply fresh
    if ($isEdit) {
        reverseInvoiceInventory($pdo, $invoiceId);
    }

    // Only process inventory for non-cancelled invoices
    if ($status !== 'cancelled') {
        $userId = authUser()['id'] ?? null;
        foreach ($lineItems as $item) {
            if (!$item['product_id']) continue;

            // Check if this product tracks inventory
            $chk = $pdo->prepare("SELECT track_inventory FROM products WHERE id = ?");
            $chk->execute([$item['product_id']]);
            $trackRow = $chk->fetch();
            if (!$trackRow || !(int)$trackRow['track_inventory']) continue;

            inventoryStockOut(
                $pdo,
                (int)$item['product_id'],
                'sale',
                (float)$item['quantity'],
                $invoiceNo,               // reference
                '',                        // notes
                $userId,
                $invoiceId
            );
        }
    }

    // ── Save payment records ───────────────────
    // Delete existing payments for this invoice then re-insert
    $pdo->prepare("DELETE FROM invoice_payments WHERE invoice_id=?")->execute([$invoiceId]);

    $pmtStmt = $pdo->prepare("INSERT INTO invoice_payments (invoice_id, payment_term_id, amount, fee_amount, fee_mode, reference_no, notes) VALUES (?,?,?,?,?,?,?)");
    foreach ($_POST['payments'] ?? [] as $pmt) {
        $pmtTermId = (int)($pmt['payment_term_id'] ?? 0) ?: null;
        $pmtAmtRaw = str_replace(',', '', (string)($pmt['amount'] ?? 0));
        $pmtFeeRaw = str_replace(',', '', (string)($pmt['fee_amount'] ?? 0));
        $pmtAmt    = round((float)$pmtAmtRaw, 2);
        $pmtFee    = round((float)$pmtFeeRaw, 2);
        $pmtMode   = in_array($pmt['fee_mode'] ?? '', ['fixed', 'pct']) ? $pmt['fee_mode'] : 'fixed';
        $pmtRef    = trim($pmt['reference_no'] ?? '');
        $pmtNotes  = trim($pmt['notes'] ?? '');
        if ($pmtAmt <= 0) continue; // skip zero-amount rows
        $pmtStmt->execute([$invoiceId, $pmtTermId, $pmtAmt, $pmtFee, $pmtMode, $pmtRef, $pmtNotes]);
    }

    // ── Delete marked attachments ────────────────
    if (!empty($deleteAttachmentIds)) {
        foreach ($deleteAttachmentIds as $delId) {
            $delStmt = $pdo->prepare("SELECT stored_name FROM invoice_attachments WHERE id=? AND invoice_id=?");
            $delStmt->execute([$delId, $invoiceId]);
            $delAtt = $delStmt->fetchColumn();
            if ($delAtt) {
                $filePath = APP_ROOT . '/storage/attachments/' . $delAtt;
                if (file_exists($filePath)) unlink($filePath);
                $pdo->prepare("DELETE FROM invoice_attachments WHERE id=?")->execute([$delId]);
            }
        }
    }

    // ── Save attachment records (if table exists) ──
    if (!empty($attachmentPaths)) {
        // Check if invoice_attachments table exists first
        $tblCheck = $pdo->query("SHOW TABLES LIKE 'invoice_attachments'")->fetchColumn();
        if ($tblCheck) {
            $aStmt = $pdo->prepare("
                INSERT INTO invoice_attachments (invoice_id, original_name, stored_name, uploaded_by)
                VALUES (?, ?, ?, ?)
            ");
            $userId = authUser()['id'];
            foreach ($attachmentPaths as $att) {
                $aStmt->execute([$invoiceId, $att['original'], $att['stored'], $userId]);
            }
        }
    }

    $pdo->commit();

    $successMsg = $isEdit ? 'Invoice updated successfully.' : 'Invoice created successfully.';

    // ── Save custom field values (must run before any exit) ───────
    $customFieldData = $_POST['custom_field'] ?? [];
    if (!empty($customFieldData)) {
        $cfUpsert = $pdo->prepare("
            INSERT INTO custom_field_values (custom_field_id, record_type, record_id, field_value)
            VALUES (?, 'invoice', ?, ?)
            ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)
        ");
        foreach ($customFieldData as $cfId => $cfVal) {
            $cfId = (int)$cfId;
            if ($cfId > 0) $cfUpsert->execute([$cfId, $invoiceId, trim($cfVal)]);
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>true, 'message'=>$successMsg, 'id'=>$invoiceId, 'invoice_no'=>$invoiceNo]);
        exit;
    }

    flash('success', $successMsg);
    redirect("invoice.php?action=edit&id=$invoiceId&saved=1");

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errMsg = 'Save failed: ' . $e->getMessage();
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false, 'message'=>$errMsg]);
        exit;
    }
    flash('error', $errMsg);
    redirect($isEdit ? "invoice.php?action=edit&id=$editId" : 'invoice.php?action=new');
}
