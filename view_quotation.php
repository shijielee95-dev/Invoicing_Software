<?php
require_once 'config/bootstrap.php';
requireAuth();
include 'includes/layout.php';

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) redirect('quotation.php');

// ── Fetch invoice ──────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ?");
$stmt->execute([$id]);
$inv = $stmt->fetch();
if (!$inv) redirect('quotation.php');

// ── Fetch items ────────────────────────────────
$items = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order, id");
$items->execute([$id]);
$items = $items->fetchAll();

// ── Fetch payments with payment term name ──────
$payments = [];
try {
    $pStmt = $pdo->prepare("
        SELECT ip.*, COALESCE(pt.name, '') AS term_name
        FROM quotation_payments ip
        LEFT JOIN payment_terms pt ON pt.id = ip.payment_term_id
        WHERE ip.quotation_id = ?
        ORDER BY ip.id
    ");
    $pStmt->execute([$id]);
    $payments = $pStmt->fetchAll();
} catch (PDOException $e) { $payments = []; }

// ── Fetch invoice-level payment term name ──────
$invPaymentTermName = '';
if (!empty($inv['payment_term_id'])) {
    try {
        $ptStmt = $pdo->prepare("SELECT name FROM payment_terms WHERE id = ?");
        $ptStmt->execute([$inv['payment_term_id']]);
        $ptRow = $ptStmt->fetch();
        if ($ptRow) $invPaymentTermName = $ptRow['name'];
    } catch (PDOException $e) {}
}

// No LHDN for quotations
$lhdn = null;

// ── Fetch company profile ──────────────────────
$company = $pdo->query("SELECT * FROM company_profiles WHERE id = 1")->fetch();

// ── Fetch attachments ──────────────────────────
$attachments = [];
try {
    $aStmt = $pdo->prepare("SELECT * FROM quotation_attachments WHERE quotation_id = ? ORDER BY created_at");
    $aStmt->execute([$id]);
    $attachments = $aStmt->fetchAll();
} catch (PDOException $e) {}

// ── Fetch history (audit logs) ─────────────────
$history = [];
try {
    $hStmt = $pdo->prepare("
        SELECT action, user_name, old_value, new_value, created_at
        FROM audit_logs WHERE table_name='quotations' AND record_id=?
        ORDER BY created_at DESC LIMIT 20
    ");
    $hStmt->execute([$id]);
    $history = $hStmt->fetchAll();
} catch (PDOException $e) {}

// ── Helpers ────────────────────────────────────
// LHDN classification descriptions
$lhdnDesc = ['001'=>'Breastfeeding equipment','002'=>'Child care centres and kindergartens fees','003'=>'Computer, smartphone or tablet','004'=>'Consolidated e-Invoice','005'=>'Construction materials','006'=>'Disbursement','007'=>'Donation','008'=>'e-Commerce - e-Invoice to buyer/purchaser','009'=>'e-Commerce - Self-billed e-Invoice','010'=>'Education fees','011'=>'Goods on consignment (Consignor)','012'=>'Goods on consignment (Consignee)','013'=>'Gym membership','014'=>'Insurance - Education and medical benefits','015'=>'Insurance - Takaful or life insurance','016'=>'Interest and financing expenses','017'=>'Internet subscription','018'=>'Land and building','019'=>'Medical examination for learning disabilities','020'=>'Medical examination or vaccination expenses','021'=>'Medical expenses for serious diseases','022'=>'Others','023'=>'Petroleum operations','024'=>'Private retirement scheme','025'=>'Motor vehicle','026'=>'Subscription of books/journals/magazines','027'=>'Reimbursement','028'=>'Rental of motor vehicle','029'=>'EV charging facilities','030'=>'Repair and maintenance','031'=>'Research and development','032'=>'Foreign income','033'=>'Self-billed - Betting and gaming','034'=>'Self-billed - Importation of goods','035'=>'Self-billed - Importation of services','036'=>'Self-billed - Others','037'=>'Self-billed - Monetary payment to agents','038'=>'Sports equipment and facilities','039'=>'Supporting equipment for disabled person','040'=>'Voluntary contribution to approved provident fund','041'=>'Dental examination or treatment','042'=>'Fertility treatment','043'=>'Treatment and home care nursing','044'=>'Vouchers, gift cards, loyalty points','045'=>'Self-billed - Non-monetary payment to agents'];

// Build tax label map from DB (id => 'Name Rate%')
$taxLabels = ['none' => '—', '' => '—'];
try {
    $trRows = db()->query("SELECT id, name, rate FROM tax_rates ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($trRows as $tr) {
        $taxLabels[(string)$tr['id']] = $tr['name'] . ' (' . number_format((float)$tr['rate'], 2) . '%)';
    }
} catch (Exception $e) {}
$paidTotal = 0;
foreach ($payments as $p) $paidTotal += (float)$p['amount'];
$balance = (float)$inv['total_amount'] - $paidTotal;

function fmtQtyView(float $n): string {
    $s = number_format($n, 4, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}

layoutOpen($inv['quotation_no'], 'Quotation details');
?>

<!-- Actions -->
<script>
document.getElementById('pageActions').innerHTML = `
    <a href="quotation.php" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        Back
    </a>
    <a href="quotation.php?action=edit&id=<?= $id ?>" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Edit
    </a>
    <button onclick="window.print()" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print
    </button>`;
</script>

<div class="flex gap-5 items-start">

    <!-- ══ LEFT: Main content ══ -->
    <div class="flex-1 min-w-0 space-y-5">

        <!-- ── Invoice document card ── -->
        <div class="<?= t('card') ?>" id="quotationPrintArea">

            <!-- Header: company + quotation meta -->
            <div class="flex justify-between items-start mb-8">
                <div>
                    <?php if (!empty($company['logo_path']) && file_exists($company['logo_path'])): ?>
                    <img src="<?= e($company['logo_path']) ?>" alt="Logo" class="h-12 object-contain mb-3">
                    <?php else: ?>
                    <div class="text-base font-bold text-slate-800 mb-1"><?= e($company['company_name'] ?: 'Your Company') ?></div>
                    <?php endif; ?>
                    <div class="text-xs text-slate-500 space-y-0.5">
                        <?php if ($company['company_tin']): ?>
                        <div>TIN: <?= e($company['company_tin']) ?></div>
                        <?php endif; ?>
                        <?php if ($company['sst_no']): ?>
                        <div>SST: <?= e($company['sst_no']) ?></div>
                        <?php endif; ?>
                        <?php
                        $addr = array_filter([
                            $company['address_line_0'] ?? '',
                            $company['address_line_1'] ?? '',
                            $company['city'] ?? '',
                            $company['postal_code'] ?? '',
                        ]);
                        if ($addr): ?>
                        <div><?= e(implode(', ', $addr)) ?></div>
                        <?php endif; ?>
                        <?php if ($company['phone']): ?>
                        <div><?= e($company['phone']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="text-right">
                    <div class="text-2xl font-bold text-slate-800 mb-1"><?= e($inv['quotation_no']) ?></div>
                    <span class="<?= badge($inv['status']) ?>"><?= ucfirst($inv['status']) ?></span>
                    <div class="text-xs text-slate-500 mt-3 space-y-0.5">
                        <div><span class="text-slate-400">Date:</span> <?= fmtDate($inv['quotation_date']) ?></div>
                        <?php if ($inv['due_date']): ?>
                        <div><span class="text-slate-400">Due:</span> <?= fmtDate($inv['due_date']) ?></div>
                        <?php endif; ?>
                        <div><span class="text-slate-400">Currency:</span> <?= e($inv['currency']) ?></div>
                        <div><span class="text-slate-400">Mode:</span> <?= ucfirst($inv['payment_mode'] ?? 'cash') ?> Sales</div>
                    </div>
                </div>
            </div>

            <!-- Bill To / Ship To -->
            <div class="grid <?= $inv['shipping_address'] ? 'grid-cols-2' : 'grid-cols-1' ?> gap-4 mb-8">
                <div class="p-4 bg-slate-50 rounded-xl">
                    <div class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-2">Bill To</div>
                    <?php if ($inv['billing_attention']): ?>
                    <div class="text-xs font-medium text-slate-600 mb-0.5">Attn: <?= e($inv['billing_attention']) ?></div>
                    <?php endif; ?>
                    <div class="font-semibold text-slate-800 text-sm"><?= e($inv['customer_name']) ?></div>
                    <div class="text-xs text-slate-500 mt-1 space-y-0.5">
                        <?php if ($inv['customer_tin']): ?>
                        <div>TIN: <?= e($inv['customer_tin']) ?></div>
                        <?php endif; ?>
                        <?php if ($inv['customer_reg_no']): ?>
                        <div>Reg No: <?= e($inv['customer_reg_no']) ?></div>
                        <?php endif; ?>
                        <?php if ($inv['customer_email']): ?>
                        <div><?= e($inv['customer_email']) ?></div>
                        <?php endif; ?>
                        <?php if ($inv['customer_phone']): ?>
                        <div><?= e($inv['customer_phone']) ?></div>
                        <?php endif; ?>
                        <?php if ($inv['customer_address']): ?>
                        <div><?= nl2br(e($inv['customer_address'])) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($inv['shipping_address']): ?>
                <div class="p-4 bg-slate-50 rounded-xl">
                    <div class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-2">Ship To</div>
                    <?php if ($inv['shipping_attention']): ?>
                    <div class="text-xs font-medium text-slate-600 mb-0.5">Attn: <?= e($inv['shipping_attention']) ?></div>
                    <?php endif; ?>
                    <div class="text-xs text-slate-500 mt-1 space-y-0.5">
                        <div><?= nl2br(e($inv['shipping_address'])) ?></div>
                        <?php if ($inv['shipping_reference']): ?>
                        <div class="mt-1">Ref: <?= e($inv['shipping_reference']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Line items table -->
            <?php
            $hasDiscount = false;
            foreach ($items as $it) { if ((float)($it['discount_pct'] ?? 0) > 0) { $hasDiscount = true; break; } }
            ?>
            <table class="w-full text-sm mb-6">
                <thead>
                    <tr class="border-b-2 border-slate-200">
                        <th class="text-left pb-2 text-xs font-semibold text-slate-500 w-8">#</th>
                        <th class="text-left pb-2 text-xs font-semibold text-slate-500">Item</th>
                        <th class="text-right pb-2 text-xs font-semibold text-slate-500">Qty</th>
                        <th class="text-right pb-2 text-xs font-semibold text-slate-500">Unit Price</th>
                        <?php if ($hasDiscount): ?>
                        <th class="text-right pb-2 text-xs font-semibold text-slate-500">Discount</th>
                        <?php endif; ?>
                        <th class="text-right pb-2 text-xs font-semibold text-slate-500">Tax</th>
                        <th class="text-right pb-2 text-xs font-semibold text-slate-500">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($items as $idx => $item):
                        $discPct  = (float)($item['discount_pct'] ?? 0);
                        $discMode = $item['discount_mode'] ?? 'pct';
                    ?>
                    <tr>
                        <td class="py-2.5 pr-2 text-slate-400 text-xs"><?= $idx + 1 ?></td>
                        <td class="py-2.5 pr-4">
                            <div class="text-slate-700"><?= e($item['description']) ?></div>
                            <?php if (!empty($item['item_description'])): ?>
                            <div class="text-xs text-slate-400 mt-0.5"><?= e($item['item_description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-2.5 text-right text-slate-500"><?= fmtQtyView((float)$item['quantity']) ?></td>
                        <td class="py-2.5 text-right text-slate-500"><?= rm((float)$item['unit_price']) ?></td>
                        <?php if ($hasDiscount): ?>
                        <td class="py-2.5 text-right text-slate-500">
                            <?php if ($discPct > 0): ?>
                                <?= $discMode === 'fixed' ? rm($discPct) : number_format($discPct, 2) . '%' ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td class="py-2.5 text-right text-slate-400 text-xs"><?= $taxLabels[$item['tax_type']] ?? '—' ?></td>
                        <td class="py-2.5 text-right font-medium text-slate-800"><?= rm((float)$item['line_total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Totals -->
            <div class="flex justify-end">
                <div class="w-64 space-y-1.5 text-sm">
                    <div class="flex justify-between text-slate-600">
                        <span>Subtotal</span>
                        <span><?= rm((float)$inv['subtotal']) ?></span>
                    </div>
                    <?php if ((float)$inv['discount_amount'] > 0): ?>
                    <div class="flex justify-between text-slate-600">
                        <span>Discount</span>
                        <span class="text-red-500">− <?= rm((float)$inv['discount_amount']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ((float)$inv['tax_amount'] > 0): ?>
                    <div class="flex justify-between text-slate-600">
                        <span>Tax <?= $inv['tax_mode'] === 'inclusive' ? '(incl.)' : '' ?></span>
                        <span><?= rm((float)$inv['tax_amount']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ((float)$inv['rounding_adjustment'] != 0): ?>
                    <div class="flex justify-between text-slate-600">
                        <span>Rounding</span>
                        <span><?= rm((float)$inv['rounding_adjustment']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between font-bold text-slate-900 text-base border-t border-slate-200 pt-2 mt-2">
                        <span>Total</span>
                        <span><?= rm((float)$inv['total_amount']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Description & Notes -->
            <?php if ($inv['description'] || $inv['notes']): ?>
            <div class="mt-6 pt-5 border-t border-slate-100 space-y-3">
                <?php if ($inv['description']): ?>
                <div>
                    <div class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-1">Description</div>
                    <p class="text-xs text-slate-600"><?= nl2br(e($inv['description'])) ?></p>
                </div>
                <?php endif; ?>
                <?php if ($inv['notes']): ?>
                <div>
                    <div class="text-[10px] font-semibold text-slate-400 uppercase tracking-wide mb-1">Notes</div>
                    <p class="text-xs text-slate-600"><?= nl2br(e($inv['notes'])) ?></p>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div><!-- end quotation card -->

        <!-- ── Payment Received card ── -->
        <?php if (!empty($payments)): ?>
        <div class="<?= t('card') ?>">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Payment Received</h3>
                <div class="text-xs">
                    <?php if ($balance < 0.005): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-green-50 text-green-700 text-xs font-medium">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
                        Fully Paid
                    </span>
                    <?php else: ?>
                    <span class="text-slate-400">Balance:</span>
                    <span class="font-semibold text-slate-800 ml-1"><?= rm($balance) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100">
                        <th class="text-left pb-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">#</th>
                        <th class="text-left pb-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Payment Term</th>
                        <th class="text-right pb-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Amount</th>
                        <th class="text-left pb-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Reference</th>
                        <th class="text-left pb-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php foreach ($payments as $pi => $pmt): ?>
                    <tr>
                        <td class="py-2.5 text-xs text-slate-400"><?= $pi + 1 ?></td>
                        <td class="py-2.5 text-slate-700"><?= e($pmt['term_name'] ?: '—') ?></td>
                        <td class="py-2.5 text-right font-medium text-slate-800"><?= rm((float)$pmt['amount']) ?></td>
                        <td class="py-2.5 text-slate-500 text-xs"><?= e($pmt['reference_no'] ?: '—') ?></td>
                        <td class="py-2.5 text-slate-500 text-xs"><?= e($pmt['notes'] ?: '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="border-t border-slate-200">
                        <td colspan="2" class="py-2.5 text-xs font-semibold text-slate-600">Total Paid</td>
                        <td class="py-2.5 text-right font-bold text-slate-900"><?= rm($paidTotal) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>

        <!-- ── History card ── -->
        <?php if (!empty($history)): ?>
        <div class="<?= t('card') ?>">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide pb-3 mb-4 border-b border-slate-100">History</h3>
            <div class="overflow-y-auto" style="max-height:300px">
            <table class="w-full text-sm">
                <thead class="sticky top-0 bg-white z-10">
                    <tr class="border-b border-slate-100">
                        <th class="text-left pb-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Date</th>
                        <th class="text-left pb-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">User</th>
                        <th class="text-left pb-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Action</th>
                        <th class="text-center pb-2 text-[10px] font-semibold text-slate-400 uppercase tracking-wide">Detail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php
                    $actionLabels = ['CREATE_QUOTATION'=>'Created','UPDATE_QUOTATION'=>'Updated','DELETE_QUOTATION'=>'Deleted'];
                    foreach ($history as $h):
                        $label     = $actionLabels[$h['action']] ?? $h['action'];
                        $hasDetail = !empty($h['old_value']) || !empty($h['new_value']);
                        // Build attachment info for the modal
                        $attForModal = [];
                        foreach ($attachments as $att) {
                            $attForModal[] = [
                                'name' => $att['original_name'],
                                'ext'  => strtoupper(pathinfo($att['original_name'], PATHINFO_EXTENSION)),
                                'url'  => ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
                                . '://' . $_SERVER['HTTP_HOST']
                                . str_replace(rtrim($_SERVER['DOCUMENT_ROOT'],'/'), '', APP_ROOT)
                                . '/storage/attachments/' . rawurlencode($att['stored_name']),
                                'date' => fmtDate($att['created_at'], 'd M Y'),
                            ];
                        }
                        $histData  = json_encode([
                            'old'         => $h['old_value'] ? json_decode($h['old_value'], true) : null,
                            'new'         => $h['new_value'] ? json_decode($h['new_value'], true) : null,
                            'attachments' => $attForModal,
                        ]);
                    ?>
                    <tr class="hover:bg-slate-50/50 transition-colors">
                        <td class="py-2.5 text-xs text-slate-500 whitespace-nowrap"><?= fmtDate($h['created_at'], 'd/m/Y g:i a') ?></td>
                        <td class="py-2.5 text-xs font-medium text-slate-700"><?= e($h['user_name']) ?></td>
                        <td class="py-2.5">
                            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-slate-100 text-xs text-slate-600">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/></svg>
                                <?= e($label) ?>
                            </span>
                        </td>
                        <td class="py-2.5 text-center">
                            <?php if ($hasDetail): ?>
                            <button type="button" onclick='showHistory(<?= htmlspecialchars($histData, ENT_QUOTES) ?>, <?= json_encode($h['action']) ?>, <?= json_encode($h['user_name']) ?>, <?= json_encode(fmtDate($h['created_at'], 'd/m/Y g:i a')) ?>)'
                                    class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors mx-auto">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                            <?php else: ?>
                            <span class="text-slate-300 text-xs">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- end left column -->

    <!-- ══ RIGHT: Sidebar panels ══ -->
    <div class="w-72 shrink-0 space-y-4">



        <!-- Invoice Info -->
        <div class="<?= t('card') ?>">
            <h3 class="<?= t('card_title') ?>">Invoice Info</h3>
            <div class="space-y-2.5 text-xs">
                <div class="flex justify-between">
                    <span class="text-slate-400">Status</span>
                    <span class="<?= badge($inv['status']) ?>"><?= ucfirst($inv['status']) ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Payment Mode</span>
                    <span class="text-slate-700 font-medium"><?= ucfirst($inv['payment_mode'] ?? 'cash') ?></span>
                </div>
                <?php if ($invPaymentTermName): ?>
                <div class="flex justify-between">
                    <span class="text-slate-400">Payment Term</span>
                    <span class="text-slate-700 font-medium"><?= e($invPaymentTermName) ?></span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between">
                    <span class="text-slate-400">Currency</span>
                    <span class="text-slate-700 font-mono"><?= e($inv['currency']) ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Tax Mode</span>
                    <span class="text-slate-700"><?= ucfirst($inv['tax_mode'] ?? 'exclusive') ?></span>
                </div>
                <?php if ($inv['reference_no']): ?>
                <div class="flex justify-between">
                    <span class="text-slate-400">Reference No.</span>
                    <span class="text-slate-700"><?= e($inv['reference_no']) ?></span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between">
                    <span class="text-slate-400">Created</span>
                    <span class="text-slate-700"><?= fmtDate($inv['created_at'], 'd M Y, g:i a') ?></span>
                </div>
                <?php if ($inv['updated_at'] && $inv['updated_at'] !== $inv['created_at']): ?>
                <div class="flex justify-between">
                    <span class="text-slate-400">Updated</span>
                    <span class="text-slate-700"><?= fmtDate($inv['updated_at'], 'd M Y, g:i a') ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Internal Note -->
        <?php if ($inv['internal_note']): ?>
        <div class="<?= t('card') ?>">
            <h3 class="<?= t('card_title') ?>">Internal Note</h3>
            <p class="text-xs text-slate-600"><?= nl2br(e($inv['internal_note'])) ?></p>
        </div>
        <?php endif; ?>

        <!-- Attachments -->
        <?php if (!empty($attachments)): ?>
        <div class="<?= t('card') ?>">
            <h3 class="<?= t('card_title') ?>">Attachments</h3>
            <div class="space-y-2">
                <?php foreach ($attachments as $att):
                    $_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $_base   = $_scheme . '://' . $_SERVER['HTTP_HOST'];
                $_relPath = str_replace(rtrim($_SERVER['DOCUMENT_ROOT'], '/'), '', APP_ROOT);
                $attUrl  = $_base . $_relPath . '/storage/attachments/' . rawurlencode($att['stored_name']);
                    $ext     = strtoupper(pathinfo($att['original_name'], PATHINFO_EXTENSION));
                    $canView = in_array($ext, ['PDF','JPG','JPEG','PNG']);
                ?>
                <div class="flex items-center gap-2.5 p-2.5 bg-slate-50 rounded-lg border border-slate-100 group">
                    <div class="w-8 h-8 rounded-lg bg-indigo-100 flex items-center justify-center shrink-0">
                        <span class="text-[8px] font-bold text-indigo-600"><?= e($ext) ?></span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-medium text-slate-700 truncate"><?= e($att['original_name']) ?></div>
                        <div class="text-[10px] text-slate-400"><?= fmtDate($att['created_at'], 'd M Y') ?></div>
                    </div>
                    <?php if ($canView): ?>
                    <a href="<?= e($attUrl) ?>" target="_blank"
                       class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors" title="Open">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
                            <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- end right sidebar -->
</div>

<!-- Audit Trail Modal — mirrors the quotation edit form layout, all fields read-only -->
<div id="historyModal" class="fixed inset-0 z-[10002] hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeHistoryModal()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-6xl mx-4 max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 shrink-0">
            <div class="flex items-center gap-3">
                <h3 class="text-base font-semibold text-slate-800">Quotation Audit Trail</h3>
                <span id="auditActionBadge" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600"></span>
            </div>
            <div class="flex items-center gap-3">
                <span id="auditMeta" class="text-xs text-slate-400"></span>
                <button type="button" onclick="closeHistoryModal()"
                        class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors text-xl">&times;</button>
            </div>
        </div>
        <div id="historyContent" class="overflow-y-auto flex-1"></div>
    </div>
</div>

<script>
// Tax label map for audit modal (id => display name)
const TAX_LABELS  = <?= json_encode($taxLabels,  JSON_HEX_TAG|JSON_HEX_QUOT) ?>;
const LHDN_LABELS = <?= json_encode($lhdnDesc,   JSON_HEX_TAG|JSON_HEX_QUOT) ?>;
function taxLabel(type)  { return TAX_LABELS[String(type||'')] || '—'; }
function classLabel(code) {
    if (!code) return '';
    var desc = LHDN_LABELS[String(code)];
    return desc ? code + ' - ' + desc : code;
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmtN(v) { var n = parseFloat(v); return isNaN(n) ? '0.00' : n.toFixed(2); }
function fmtQ(v) { var n = parseFloat(v); if(isNaN(n)) return '0'; var s = n.toFixed(4); return s.replace(/\.?0+$/,''); }

function roField(label, value, opts) {
    opts = opts || {};
    var cls = opts.changed ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-slate-50';
    var h = '<div>';
    h += '<label class="block text-xs font-medium text-slate-500 mb-1">' + esc(label) + '</label>';
    h += '<div class="w-full h-9 border rounded-lg px-3 text-sm flex items-center cursor-default ' + cls + (opts.mono ? ' font-mono' : '') + '">';
    if (opts.changed && opts.oldVal !== undefined) {
        h += '<span class="line-through text-red-400 mr-2 text-xs">' + esc(opts.oldVal) + '</span>';
    }
    h += '<span class="text-slate-700">' + esc(value || '—') + '</span></div></div>';
    return h;
}

function showHistory(data, action, userName, dateStr) {
    var o = data.old || {};
    var n = data.new || {};
    // Attachment-only entry is flagged with _type='attachment_change'
    var isAttachmentOnly = (o._type === 'attachment_change') || (n._type === 'attachment_change');
    var isCreate = !data.old && !isAttachmentOnly;
    var isUpdate = !!data.old && !!data.new && !isAttachmentOnly;
    var items = n.items || o.items || [];
    var payments = n.payments || o.payments || [];
    var oldItems = o.items || [];
    var oldPayments = o.payments || [];

    // Header
    var badge = document.getElementById('auditActionBadge');
    var meta = document.getElementById('auditMeta');
    var labels = { CREATE_QUOTATION:'Created', UPDATE_QUOTATION:'Updated', DELETE_QUOTATION:'Deleted' };
    badge.textContent = labels[action] || action || '';
    badge.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ' +
        (action==='CREATE_QUOTATION' ? 'bg-emerald-50 text-emerald-700' :
         action==='DELETE_QUOTATION' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700');
    meta.textContent = (userName ? userName + ' · ' : '') + (dateStr || '');

    var html = '';
    var cap  = function(s) { return s ? s.charAt(0).toUpperCase()+s.slice(1) : '—'; };
    var ch   = function(field) { return isUpdate && (o[field]||'') !== (n[field]||''); };
    var val  = function(field) { return n[field] !== undefined ? n[field] : o[field]; };
    var rf   = function(label, field, extra) {
        extra = extra || {};
        var v = val(field);
        var changed = ch(field);
        var opts = changed ? {changed:true, oldVal:o[field]} : {};
        if (extra.mono) opts.mono = true;
        if (extra.fmt) v = extra.fmt(v);
        if (extra.fmt && changed) opts.oldVal = extra.fmt(o[field]);
        return roField(label, v, opts);
    };

    // ── roArea: textarea-style field ──
    function roArea(label, value, opts) {
        opts = opts || {};
        var cls = opts.changed ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-slate-50';
        var h = '<div>';
        h += '<label class="block text-xs font-medium text-slate-500 mb-1">' + esc(label) + '</label>';
        h += '<div class="w-full min-h-[64px] border rounded-lg px-3 py-2 text-sm cursor-default whitespace-pre-wrap ' + cls + '">';
        if (opts.changed && opts.oldVal) {
            h += '<div class="line-through text-red-400 text-xs mb-1">' + esc(opts.oldVal) + '</div>';
        }
        h += '<span class="text-slate-700">' + esc(value || '—') + '</span></div></div>';
        return h;
    }

    if (!isAttachmentOnly) {

    // ═══ SECTION 1: Billing & Shipping ═══
    html += '<div class="border-b border-slate-100"><div class="grid grid-cols-[160px_1fr]">';
    html += '<div class="p-5 border-r border-slate-100"><h3 class="text-sm font-semibold text-slate-800 mb-1">Billing &amp; Shipping</h3><p class="text-xs text-slate-400">Billing &amp; shipping parties for the transaction.</p></div>';
    html += '<div class="p-5"><div class="grid grid-cols-2 gap-4">';
    // Row 1: Customer | Shipping Reference
    html += rf('Customer', 'customer');
    html += rf('Shipping Reference', 'shipping_reference');
    // Row 2: Billing Attention | Shipping Attention
    html += rf('Billing Attention', 'billing_attention');
    html += rf('Shipping Attention', 'shipping_attention');
    // Row 3: Billing Address | Shipping Address
    html += roArea('Billing Address', val('customer_address'), ch('customer_address') ? {changed:true, oldVal:o.customer_address} : {});
    html += roArea('Shipping Address', val('shipping_address'), ch('shipping_address') ? {changed:true, oldVal:o.shipping_address} : {});
    html += '</div></div></div></div>';

    // ═══ SECTION 2: General Info ═══
    html += '<div class="border-b border-slate-100"><div class="grid grid-cols-[160px_1fr]">';
    html += '<div class="p-5 border-r border-slate-100"><h3 class="text-sm font-semibold text-slate-800 mb-1">General Info</h3><p class="text-xs text-slate-400">Quotation number, date and general information.</p></div>';
    html += '<div class="p-5"><div class="grid grid-cols-2 gap-3">';
    html += rf('Quotation Number', 'quotation_no', {mono:true});
    html += rf('Reference No.', 'reference_no');
    html += rf('Date', 'quotation_date');
    html += rf('Currency', 'currency');
    html += rf('Description', 'description');
    html += rf('Internal Note', 'internal_note');
    html += rf('Payment Mode', 'payment_mode', {fmt: function(v){ return cap(v||'cash') + ' Sales'; }});
    html += rf('Status', 'status', {fmt: cap});
    html += '</div></div></div></div>';

    // ═══ SECTION 2: Items ═══
    if (items.length || oldItems.length) {
        html += '<div class="border-b border-slate-100"><div class="grid grid-cols-[160px_1fr]">';
        html += '<div class="p-5 border-r border-slate-100"><h3 class="text-sm font-semibold text-slate-800 mb-1">Items</h3><p class="text-xs text-slate-400">Line items.</p></div>';
        html += '<div class="p-5">';
        var taxModeVal = val('tax_mode') || 'exclusive';
        var taxModeChg = ch('tax_mode');
        html += '<div class="flex items-center gap-2 mb-3">';
        html += '<span class="text-xs text-slate-500">Tax Mode:</span>';
        if (taxModeChg) {
            html += '<span class="line-through text-red-400 text-xs">' + (o.tax_mode==='inclusive'?'Tax Inclusive':'Tax Exclusive') + '</span>';
            html += '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">' + (taxModeVal==='inclusive'?'Tax Inclusive':'Tax Exclusive') + '</span>';
        } else {
            html += '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-700">' + (taxModeVal==='inclusive'?'Tax Inclusive':'Tax Exclusive') + '</span>';
        }
        html += '</div>';
        html += '<table class="w-full text-sm border border-slate-100 rounded-lg overflow-hidden"><colgroup><col style="width:36px"><col><col style="width:65px"><col style="width:90px"><col style="width:90px"><col style="width:85px"><col style="width:70px"></colgroup>';
        html += '<thead class="bg-slate-50"><tr>' +
            '<th class="px-3 py-2 text-center text-[10px] font-semibold text-slate-500 uppercase">#</th>' +
            '<th class="px-3 py-2 text-left text-[10px] font-semibold text-slate-500 uppercase">Item / Description</th>' +
            '<th class="px-3 py-2 text-right text-[10px] font-semibold text-slate-500 uppercase">Qty</th>' +
            '<th class="px-3 py-2 text-right text-[10px] font-semibold text-slate-500 uppercase">Unit Price</th>' +
            '<th class="px-3 py-2 text-right text-[10px] font-semibold text-slate-500 uppercase">Amount</th>' +
            '<th class="px-3 py-2 text-right text-[10px] font-semibold text-slate-500 uppercase">Discount</th>' +
            '<th class="px-3 py-2 text-right text-[10px] font-semibold text-slate-500 uppercase">Tax</th>' +
            '</tr></thead><tbody>';

        function itemRow(it, i, type) {
            var cls = '', numCls = '', prefix = (i+1);
            if (type === 'added')   { cls = 'bg-emerald-50'; numCls = 'text-emerald-500 font-bold'; prefix = '+'; }
            if (type === 'removed') { cls = 'bg-red-50'; numCls = 'text-red-400'; prefix = '−'; }
            if (type === 'normal')  { numCls = 'text-slate-400'; }
            var td = type === 'removed' ? ' line-through' : '';
            var tc = type === 'added' ? 'text-emerald-700 font-medium' : (type === 'removed' ? 'text-red-500' : 'text-slate-700');
            var nc = type === 'added' ? 'text-emerald-600' : (type === 'removed' ? 'text-red-400' : 'text-slate-500');
            var ac = type === 'added' ? 'text-emerald-600 font-medium' : (type === 'removed' ? 'text-red-400' : 'text-slate-700');
            var discStr = it.discount_pct > 0
                ? (it.discount_mode === 'fixed' ? fmtN(it.discount_pct) : fmtQ(it.discount_pct) + '%')
                : '—';
            var taxStr = taxLabel(it.tax_type);
            // Row 1: #, item name, qty, unit price, amount, discount, tax
            var row1 = '<tr class="'+cls+'">' +
                '<td class="px-3 pt-2 pb-0 text-center text-xs '+numCls+'">'+prefix+'</td>' +
                '<td class="px-3 pt-2 pb-0 '+tc+td+'">'+esc(it.description)+'</td>' +
                '<td class="px-3 pt-2 pb-0 text-right '+nc+td+'">'+fmtQ(it.quantity)+'</td>' +
                '<td class="px-3 pt-2 pb-0 text-right '+nc+td+'">'+fmtN(it.unit_price)+'</td>' +
                '<td class="px-3 pt-2 pb-0 text-right '+ac+td+'">'+fmtN(it.line_total)+'</td>' +
                '<td class="px-3 pt-2 pb-0 text-right '+nc+td+'">'+discStr+'</td>' +
                '<td class="px-3 pt-2 pb-0 text-right '+nc+td+'">'+taxStr+'</td>' +
                '</tr>';
            // Row 2: empty #, item description (left), classification (right spans remaining)
            var row2 = '<tr class="border-b border-slate-100 '+cls+'">' +
                '<td class="px-3 pt-0 pb-2"></td>' +
                '<td class="px-3 pt-0 pb-2 text-[11px] '+(type==='removed'?'text-red-300':'text-slate-400')+td+'">' + (it.item_description ? esc(it.item_description) : '&nbsp;') + '</td>' +
                '<td colspan="5" class="px-3 pt-0 pb-2 text-[11px] '+(type==='removed'?'text-red-300':'text-slate-400')+td+'">' + (it.classification ? classLabel(it.classification) : '') + '</td>' +
                '</tr>';
            return row1 + row2;
        }

        if (isUpdate) {
            // Removed items
            oldItems.forEach(function(it, i) {
                var m = (n.items||[])[i];
                if (!m || m.description !== it.description) html += itemRow(it, i, 'removed');
            });
            // Added / changed / unchanged
            (n.items||[]).forEach(function(it, i) {
                var m = oldItems[i];
                var added = !m || m.description !== it.description;
                var changed = m && m.description === it.description &&
                    (fmtQ(m.quantity)!==fmtQ(it.quantity) || fmtN(m.unit_price)!==fmtN(it.unit_price));
                if (added) {
                    html += itemRow(it, i, 'added');
                } else if (changed) {
                    var mDiscStr = m.discount_pct > 0 ? (m.discount_mode==='fixed'?fmtN(m.discount_pct):fmtQ(m.discount_pct)+'%') : '—';
                    var iDiscStr = it.discount_pct > 0 ? (it.discount_mode==='fixed'?fmtN(it.discount_pct):fmtQ(it.discount_pct)+'%') : '—';
                    var mTaxStr = taxLabel(m.tax_type);
                    var iTaxStr = taxLabel(it.tax_type);
                    html +=
                        '<tr class="bg-amber-50">' +
                            '<td class="px-3 pt-2 pb-0 text-center text-xs text-amber-500">'+(i+1)+'</td>' +
                            '<td class="px-3 pt-2 pb-0 text-slate-700">'+esc(it.description)+'</td>' +
                            '<td class="px-3 pt-2 pb-0 text-right"><div class="line-through text-red-400 text-xs">'+fmtQ(m.quantity)+'</div><div class="text-emerald-600 font-medium">'+fmtQ(it.quantity)+'</div></td>' +
                            '<td class="px-3 pt-2 pb-0 text-right"><div class="line-through text-red-400 text-xs">'+fmtN(m.unit_price)+'</div><div class="text-emerald-600 font-medium">'+fmtN(it.unit_price)+'</div></td>' +
                            '<td class="px-3 pt-2 pb-0 text-right"><div class="line-through text-red-400 text-xs">'+fmtN(m.line_total)+'</div><div class="text-emerald-600 font-medium">'+fmtN(it.line_total)+'</div></td>' +
                            '<td class="px-3 pt-2 pb-0 text-right"><div class="line-through text-red-400 text-xs">'+mDiscStr+'</div><div class="text-emerald-600 font-medium">'+iDiscStr+'</div></td>' +
                            '<td class="px-3 pt-2 pb-0 text-right"><div class="line-through text-red-400 text-xs">'+mTaxStr+'</div><div class="text-emerald-600 font-medium">'+iTaxStr+'</div></td>' +
                        '</tr>' +
                        '<tr class="border-b border-slate-100 bg-amber-50">' +
                            '<td class="px-3 pt-0 pb-2"></td>' +
                            '<td class="px-3 pt-0 pb-2 text-[11px] text-slate-400">'+(it.item_description ? esc(it.item_description) : '')+'</td>' +
                            '<td colspan="5" class="px-3 pt-0 pb-2 text-[11px] text-slate-400">'+(it.classification ? classLabel(it.classification) : '')+'</td>' +
                        '</tr>';
                } else {
                    html += itemRow(it, i, 'normal');
                }
            });
        } else {
            items.forEach(function(it, i) { html += itemRow(it, i, 'normal'); });
        }
        html += '</tbody></table>';

        // Totals
        var tf = [['Sub Total','subtotal'],['Discount (−)','discount'],['Tax (+)','tax'],['Rounding Adjustment','rounding'],['TOTAL','total_amount']];
        html += '<div class="flex justify-end mt-4"><table class="text-sm" style="width:320px">';
        if (isUpdate) {
            html += '<thead><tr class="border-b border-slate-200"><th class="text-left pb-1.5 text-xs font-semibold text-slate-500"></th><th class="text-right pb-1.5 text-xs font-semibold text-slate-500 w-24">Before</th><th class="text-right pb-1.5 text-xs font-semibold text-slate-500 w-24">After</th></tr></thead>';
        }
        html += '<tbody>';
        tf.forEach(function(f) {
            var ov = parseFloat(o[f[1]]) || 0, nv = parseFloat((n[f[1]]!==undefined?n[f[1]]:o[f[1]])) || 0;
            var isT = f[1]==='total_amount', ch = isUpdate && ov.toFixed(2)!==nv.toFixed(2);
            var bg = isT ? 'bg-amber-50' : (ch ? 'bg-amber-50/50' : '');
            html += '<tr class="border-b border-slate-100 '+bg+'"><td class="py-1.5 px-2 text-slate-600 '+(isT?'font-bold':'')+'">'+f[0]+'</td>';
            if (isUpdate) html += '<td class="py-1.5 px-2 text-right '+(ch?'text-red-400':'text-slate-400')+'">'+fmtN(ov)+'</td>';
            html += '<td class="py-1.5 px-2 text-right '+(isT?'font-bold text-slate-900':'text-slate-700 font-medium')+'">'+fmtN(nv)+'</td></tr>';
        });
        html += '</tbody></table></div>';
        html += '</div></div></div>';
    }

    // ═══ SECTION 4: Additional Info ═══
    html += '<div class="border-b border-slate-100"><div class="grid grid-cols-[160px_1fr]">';
    html += '<div class="p-5 border-r border-slate-100"><h3 class="text-sm font-semibold text-slate-800 mb-1">Additional Info</h3><p class="text-xs text-slate-400">Remarks and terms.</p></div>';
    html += '<div class="p-5">';
    html += roArea('Remarks', val('notes'), ch('notes') ? {changed:true, oldVal:o.notes} : {});
    html += '</div></div></div>';

    // ═══ SECTION 5: Payment Received ═══
    if (true) { // always show payment section
        html += '<div class="border-b border-slate-100 grid grid-cols-[160px_1fr]">';
        html += '<div class="p-5 border-r border-slate-100"><h3 class="text-sm font-semibold text-slate-800 mb-1">Payment Received</h3><p class="text-xs text-slate-400">Payment records.</p></div>';
        html += '<div class="p-5"><table class="w-full text-sm border border-slate-100 rounded-lg overflow-hidden"><thead class="bg-slate-50"><tr>' +
            '<th class="px-3 py-2 text-left text-[10px] font-semibold text-slate-500 uppercase">Term</th>' +
            '<th class="px-3 py-2 text-right text-[10px] font-semibold text-slate-500 uppercase">Amount</th>' +
            '<th class="px-3 py-2 text-left text-[10px] font-semibold text-slate-500 uppercase">Reference</th>' +
            '<th class="px-3 py-2 text-left text-[10px] font-semibold text-slate-500 uppercase">Notes</th></tr></thead><tbody>';
        // Show removed rows (genuinely removed, count decreased)
        if (isUpdate && oldPayments.length > payments.length) {
            oldPayments.slice(payments.length).forEach(function(p) {
                html += '<tr class="bg-red-50 border-b border-red-100">' +
                    '<td class="px-3 py-2 text-red-500 line-through">'+esc(p.term_name||'—')+'</td>' +
                    '<td class="px-3 py-2 text-right text-red-400 line-through">'+fmtN(p.amount)+'</td>' +
                    '<td class="px-3 py-2 text-red-400 line-through">'+esc(p.reference_no||'—')+'</td>' +
                    '<td class="px-3 py-2 text-red-400 line-through">'+esc(p.notes||'—')+'</td></tr>';
            });
        }
        // Show all current payment rows
        var srcPayments = payments.length ? payments : oldPayments;
        srcPayments.forEach(function(p, i) {
            var m = isUpdate ? (oldPayments[i] || null) : null;
            var changed = m && (m.term_name !== p.term_name || fmtN(m.amount) !== fmtN(p.amount));
            var added   = isUpdate && !m;
            var rowCls  = added ? 'bg-emerald-50 ' : (changed ? 'bg-amber-50 ' : '');
            var tc      = added ? 'text-emerald-700 font-medium' : (changed ? 'text-amber-700' : 'text-slate-600');
            var ac      = added ? 'text-emerald-600 font-medium' : (changed ? 'text-amber-700' : 'text-slate-500');
            html += '<tr class="'+rowCls+'border-b border-slate-100">';
            if (changed) {
                html += '<td class="px-3 py-2"><div class="line-through text-red-400 text-xs">'+esc(m.term_name||'—')+'</div><div class="'+tc+'">'+esc(p.term_name||'—')+'</div></td>';
                html += '<td class="px-3 py-2 text-right"><div class="line-through text-red-400 text-xs">'+fmtN(m.amount)+'</div><div class="'+ac+'">'+fmtN(p.amount)+'</div></td>';
            } else {
                html += '<td class="px-3 py-2 '+tc+'">'+esc(p.term_name||'—')+'</td>';
                html += '<td class="px-3 py-2 text-right '+ac+'">'+fmtN(p.amount)+'</td>';
            }
            html += '<td class="px-3 py-2 text-slate-400">'+esc(p.reference_no||'—')+'</td>';
            html += '<td class="px-3 py-2 text-slate-400">'+esc(p.notes||'—')+'</td>';
            html += '</tr>';
        });
        html += '</tbody></table></div></div>';
    }

    } // end !isAttachmentOnly

    // ═══ SECTION 6: Attachments ═══
    var atts        = data.attachments || [];
    var addedAtts   = (n.added_attachments   || []);
    var removedAtts = (n.removed_attachments || []);
    var hasAttChange = addedAtts.length > 0 || removedAtts.length > 0;

    html += '<div class="grid grid-cols-[160px_1fr]">';
    html += '<div class="p-5 border-r border-slate-100"><h3 class="text-sm font-semibold text-slate-800 mb-1">Attachments</h3><p class="text-xs text-slate-400">Files attached to this quotation.</p></div>';
    html += '<div class="p-5">';

    if (hasAttChange) {
        function attBadge(name, type) {
            var ext = name.split('.').pop().toUpperCase();
            var bg  = type === 'added'   ? 'bg-emerald-50 border-emerald-200' : 'bg-red-50 border-red-200';
            var ibg = type === 'added'   ? 'bg-emerald-100' : 'bg-red-100';
            var tc  = type === 'added'   ? 'text-emerald-600' : 'text-red-500';
            var nc  = type === 'added'   ? 'text-emerald-700' : 'text-red-600';
            var td  = type === 'removed' ? ' line-through' : '';
            return '<div class="flex items-center gap-2 px-3 py-2 ' + bg + ' rounded-lg border">' +
                '<div class="w-7 h-7 rounded-md ' + ibg + ' flex items-center justify-center shrink-0">' +
                    '<span class="text-[9px] font-bold ' + tc + '">' + esc(ext) + '</span>' +
                '</div>' +
                '<span class="text-xs font-medium ' + nc + td + ' truncate">' + esc(name) + '</span>' +
                '</div>';
        }
        if (addedAtts.length > 0) {
            html += '<p class="text-xs font-semibold text-emerald-600 mb-1.5">Added:</p>';
            html += '<div class="space-y-1.5 mb-3">';
            addedAtts.forEach(function(f) { html += attBadge(f, 'added'); });
            html += '</div>';
        }
        if (removedAtts.length > 0) {
            html += '<p class="text-xs font-semibold text-red-500 mb-1.5">Removed:</p>';
            html += '<div class="space-y-1.5 mb-3">';
            removedAtts.forEach(function(f) { html += attBadge(f, 'removed'); });
            html += '</div>';
        }
        if (atts.length > 0) {
            html += '<p class="text-xs font-semibold text-slate-500 mb-1.5">Current attachments:</p>';
        }
    }
    if (atts.length === 0) {
        html += '<p class="text-sm text-slate-400 italic">No attachments.</p>';
    } else {
        html += '<div class="space-y-2">';
        atts.forEach(function(att) {
            var canView = ['PDF','JPG','JPEG','PNG'].indexOf(att.ext) >= 0;
            html += '<div class="flex items-center gap-3 px-3 py-2.5 bg-slate-50 rounded-lg border border-slate-200">' +
                '<div class="w-9 h-9 rounded-lg bg-indigo-100 flex items-center justify-center shrink-0">' +
                    '<span class="text-[9px] font-bold text-indigo-600">' + esc(att.ext) + '</span>' +
                '</div>' +
                '<div class="flex-1 min-w-0">' +
                    (canView
                        ? '<a href="' + esc(att.url) + '" target="_blank" class="text-xs font-medium text-indigo-600 hover:underline truncate block">' + esc(att.name) + '</a>'
                        : '<div class="text-xs font-medium text-slate-700 truncate">' + esc(att.name) + '</div>') +
                    '<div class="text-[10px] text-slate-400">' + esc(att.date) + '</div>' +
                '</div>' +
                (canView ? '<a href="' + esc(att.url) + '" target="_blank" class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors" title="Open"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></a>' : '') +
                '</div>';
        });
        html += '</div>';
    }
    html += '</div></div></div>';


    document.getElementById('historyContent').innerHTML = html;
    var m = document.getElementById('historyModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}

function closeHistoryModal() {
    var m = document.getElementById('historyModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}

</script>

<?php layoutClose(); ?>
