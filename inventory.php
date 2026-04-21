<?php
require_once 'config/bootstrap.php';
requireAuth();
include 'includes/layout.php';
require_once 'inventory_helper.php';

$pdo    = db();
$action = $_GET['action'] ?? 'list';
$pid    = (int)($_GET['product_id'] ?? 0);
$method = inventoryMethod($pdo);

function loadProduct(PDO $pdo, int $id): array {
    $row = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $row->execute([$id]);
    $row = $row->fetch();
    if (!$row) { flash('error', 'Product not found.'); redirect('inventory.php'); }
    return $row;
}

/**
 * Format a stock quantity:
 * - Whole numbers → 0 decimals  (e.g. 10 → "10")
 * - Has decimals  → trim trailing zeros, max 5 decimal places
 *                   (e.g. 0.02 → "0.02", 0.00005 → "0.00005", 1.50 → "1.5")
 */
function fmtQtyPhp(float $qty): string {
    if ($qty == floor($qty)) return number_format($qty, 0);
    $s = number_format($qty, 5, '.', '');        // e.g. "0.02000"
    $s = rtrim($s, '0');                          // e.g. "0.02"
    $s = rtrim($s, '.');                          // safety: remove trailing dot
    // Re-apply thousands separator on the integer part
    [$int, $dec] = explode('.', $s . '.', 2);
    return number_format((float)$int, 0) . '.' . $dec;
}

// ─────────────────────────────────────────────────────────────────────────────
// LIST — stock overview, search shared with product.php filters
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'list'):
    $search = trim($_GET['search'] ?? '');
    $whereParams = [];
    $where = "WHERE p.track_inventory = 1";
    if ($search) {
        $where .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
        $whereParams = ["%$search%", "%$search%"];
    }

    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.sku, p.base_unit_label, p.low_stock_level,
               COALESCE(s.qty_on_hand, 0) AS qty_on_hand,
               COALESCE(s.avg_cost, 0)    AS avg_cost
        FROM products p
        LEFT JOIN product_stock_summary s ON s.product_id = p.id
        $where
        ORDER BY p.name
    ");
    $stmt->execute($whereParams);
    $products = $stmt->fetchAll();

    layoutOpen('Inventory', 'Stock levels across all tracked products.');
?>
<script>
document.getElementById('pageActions').innerHTML = '';
</script>

<!-- Search — same layout as product.php -->
<form method="get" class="mb-4 flex gap-2">
    <input type="hidden" name="action" value="list">
    <input type="text" name="search" value="<?= e($search) ?>"
           placeholder="Search by product name or SKU…"
           class="<?= t('input') ?>">
    <button type="submit" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Search</button>
    <?php if ($search): ?>
    <a href="inventory.php" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Clear</a>
    <?php endif; ?>
</form>

<!-- Costing method badge -->
<div class="mb-3 flex items-center gap-2">
    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold
        <?= $method === 'fifo' ? 'bg-violet-100 text-violet-700' : 'bg-sky-100 text-sky-700' ?>">
        <?= $method === 'fifo' ? 'FIFO' : 'Weighted Average' ?>
    </span>
    <span class="text-xs text-slate-400">·</span>
    <a href="inventory_settings.php" class="text-xs text-slate-400 hover:text-indigo-600 transition-colors">Change in Settings</a>
</div>

<div class="<?= t('table_wrap') ?>">
    <table class="w-full text-sm" style="table-layout:fixed">
        <colgroup>
            <col style="width:30%">
            <col style="width:12%">
            <col style="width:17%">
            <col style="width:14%">
            <col style="width:14%">
            <col style="width:13%">
        </colgroup>
        <thead>
            <tr>
                <th class="<?= t('th') ?>">Product</th>
                <th class="<?= t('th') ?>">SKU</th>
                <th class="<?= t('th') ?> text-right">Qty on Hand</th>
                <th class="<?= t('th') ?> text-right"><?= $method === 'fifo' ? 'Avg Layer Cost' : 'Avg Cost / Unit' ?></th>
                <th class="<?= t('th') ?> text-right">Stock Value</th>
                <th class="<?= t('th') ?> text-center">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php if (empty($products)): ?>
            <tr><td colspan="6" class="px-4 py-12 text-center text-slate-400">
                No tracked products found.
                <?php if (!$search): ?>
                Enable <strong class="text-slate-600">Track Inventory</strong> on a
                <a href="product.php" class="text-indigo-600 hover:underline">product</a> to get started.
                <?php endif; ?>
            </td></tr>
            <?php else: ?>
            <?php foreach ($products as $p):
                $qty        = (float)$p['qty_on_hand'];
                $isNegative = $qty < 0;
                $isZero     = $qty == 0;
                $isLow      = !$isNegative && !$isZero && $p['low_stock_level'] !== null && $qty <= (float)$p['low_stock_level'];
                $stockValue = $qty * (float)$p['avg_cost'];
                $qtyClass   = ($isNegative || $isZero) ? 'text-red-600' : ($isLow ? 'text-amber-600' : 'text-slate-800');
            ?>
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="<?= t('td') ?> font-medium">
                    <div class="flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full shrink-0
                            <?= ($isNegative || $isZero) ? 'bg-red-400' : ($isLow ? 'bg-amber-400' : 'bg-green-400') ?>"></span>
                        <?= e($p['name']) ?>
                    </div>
                </td>
                <td class="<?= t('td') ?> font-mono text-slate-500 text-xs"><?= e($p['sku']) ?: '—' ?></td>
                <td class="<?= t('td') ?> text-right">
                    <span class="font-semibold <?= $qtyClass ?>">
                        <?= fmtQtyPhp($qty) ?>
                    </span>
                    <span class="text-slate-400 text-xs ml-1"><?= e($p['base_unit_label']) ?></span>
                    <?php if ($isNegative): ?>
                        <span class="ml-1 text-[10px] font-semibold text-red-600 bg-red-50 border border-red-200 rounded px-1">Negative</span>
                    <?php elseif ($isZero): ?>
                        <span class="ml-1 text-[10px] font-semibold text-red-600 bg-red-50 border border-red-200 rounded px-1">Out</span>
                    <?php elseif ($isLow): ?>
                        <span class="ml-1 text-[10px] font-semibold text-amber-600 bg-amber-50 border border-amber-200 rounded px-1">Low</span>
                    <?php endif; ?>
                </td>
                <td class="<?= t('td') ?> text-right text-slate-600">
                    <?= (float)$p['avg_cost'] > 0 ? number_format((float)$p['avg_cost'], 4) : '<span class="text-slate-300">—</span>' ?>
                </td>
                <td class="<?= t('td') ?> text-right font-medium text-slate-700">
                    <?= $stockValue > 0 ? number_format($stockValue, 2) : '<span class="text-slate-300">—</span>' ?>
                </td>
                <td class="<?= t('td') ?> text-center">
                    <a href="inventory.php?action=ledger&product_id=<?= $p['id'] ?>"
                       class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-7 text-xs px-3">Ledger</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <?php if (!empty($products)): ?>
        <tfoot>
            <tr class="bg-slate-50">
                <td colspan="4" class="<?= t('td') ?> font-semibold text-slate-600 text-right">Total Stock Value</td>
                <td class="<?= t('td') ?> text-right font-bold text-slate-800">
                    <?php
                    $total = array_sum(array_map(fn($p) => (float)$p['qty_on_hand'] * (float)$p['avg_cost'], $products));
                    echo 'MYR ' . number_format($total, 2);
                    ?>
                </td>
                <td class="<?= t('td') ?>"></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<?php layoutClose();

// ─────────────────────────────────────────────────────────────────────────────
// LEDGER — movement history for a single product
// ─────────────────────────────────────────────────────────────────────────────
elseif ($action === 'ledger' && $pid > 0):
    $product   = loadProduct($pdo, $pid);
    $summary   = getStockSummary($pdo, $pid);
    $movements = $pdo->prepare("
        SELECT m.*, u.name AS created_by_name
        FROM inventory_movements m
        LEFT JOIN users u ON u.id = m.created_by
        WHERE m.product_id = ?
        ORDER BY m.created_at DESC, m.id DESC
    ");
    $movements->execute([$pid]);
    $movements = $movements->fetchAll();

    $typeLabels = [
        'opening'    => ['Opening Balance', 'bg-slate-100 text-slate-600'],
        'purchase'   => ['Purchase',        'bg-green-100 text-green-700'],
        'sale'       => ['Sale',            'bg-blue-100 text-blue-700'],
        'adjustment' => ['Adjustment',      'bg-amber-100 text-amber-700'],
    ];

    layoutOpen('Stock Ledger — ' . e($product['name']), 'Full movement history for this product.');
?>
<script>
document.getElementById('pageActions').innerHTML =
    '<a href="inventory.php" class="<?= t('btn_base').' '.t('btn_ghost') ?> h-9">← Back</a>' +
    ' <a href="inventory_adjustment.php?action=adjust&product_id=<?= $pid ?>" class="<?= t('btn_base') ?> h-9 ml-2 bg-amber-50 text-amber-700 hover:bg-amber-100 border border-amber-200 rounded-lg px-4">Adjust Stock</a>';
</script>

<!-- Summary cards — full width, 3 columns -->
<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="bg-white border border-slate-200 rounded-xl p-5">
        <div class="text-xs text-slate-400 mb-1.5">Qty on Hand</div>
        <div class="text-2xl font-bold <?= (float)$summary['qty_on_hand'] < 0 ? 'text-red-600' : ((float)$summary['qty_on_hand'] == 0 ? 'text-red-600' : 'text-slate-800') ?>">
            <?= fmtQtyPhp((float)$summary['qty_on_hand']) ?>
            <span class="text-sm font-normal text-slate-400 ml-1"><?= e($product['base_unit_label']) ?></span>
        </div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-5">
        <div class="text-xs text-slate-400 mb-1.5"><?= $method === 'fifo' ? 'Avg Layer Cost' : 'Avg Cost / Unit' ?></div>
        <div class="text-2xl font-bold text-slate-800">
            <?= number_format((float)$summary['avg_cost'], 4) ?>
        </div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-5">
        <div class="text-xs text-slate-400 mb-1.5">Total Stock Value</div>
        <div class="text-2xl font-bold text-slate-800">
            MYR <?= number_format((float)$summary['qty_on_hand'] * (float)$summary['avg_cost'], 2) ?>
        </div>
    </div>
</div>

<div class="<?= t('table_wrap') ?>">
    <table class="w-full text-sm" style="table-layout:fixed">
        <colgroup>
            <col style="width:14%">
            <col style="width:12%">
            <col style="width:11%">
            <col style="width:11%">
            <col style="width:14%">
            <col style="width:26%">
            <col style="width:9%">
            <col style="width:3%">
        </colgroup>
        <thead>
            <tr>
                <th class="<?= t('th') ?>">Date / Time</th>
                <th class="<?= t('th') ?>">Type</th>
                <th class="<?= t('th') ?> text-right">Qty</th>
                <th class="<?= t('th') ?> text-right">Unit Cost</th>
                <th class="<?= t('th') ?>">Reference</th>
                <th class="<?= t('th') ?>">Notes</th>
                <th class="<?= t('th') ?>">By</th>
                <th class="<?= t('th') ?>"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php if (empty($movements)): ?>
            <tr><td colspan="8" class="px-4 py-12 text-center text-slate-400">
                No movements yet.
            </td></tr>
            <?php else: ?>
            <?php foreach ($movements as $m):
                [$typeLabel, $typeCls] = $typeLabels[$m['type']] ?? ['Unknown', 'bg-slate-100 text-slate-500'];
                $qty   = (float)$m['qty'];
                $isIn  = $qty > 0;
            ?>
            <tr class="hover:bg-slate-50 transition-colors">
                <td class="<?= t('td') ?> text-xs font-mono text-slate-500">
                    <?= date('Y-m-d', strtotime($m['created_at'])) ?><br>
                    <span class="text-slate-400"><?= date('H:i', strtotime($m['created_at'])) ?></span>
                </td>
                <td class="<?= t('td') ?>">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium <?= $typeCls ?>">
                        <?= $typeLabel ?>
                    </span>
                </td>
                <td class="<?= t('td') ?> text-right font-semibold <?= $isIn ? 'text-green-700' : 'text-red-600' ?>">
                    <?= $isIn ? '+' : '' ?><?= fmtQtyPhp($qty) ?>
                </td>
                <td class="<?= t('td') ?> text-right text-slate-600">
                    <?= (float)$m['unit_cost'] > 0 ? number_format((float)$m['unit_cost'], 4) : '—' ?>
                </td>
                <td class="<?= t('td') ?> font-mono text-xs text-slate-600"><?= e($m['reference']) ?: '—' ?></td>
                <td class="<?= t('td') ?> text-xs text-slate-500 truncate"><?= e($m['notes']) ?: '—' ?></td>
                <td class="<?= t('td') ?> text-xs text-slate-400"><?= e($m['created_by_name'] ?? '—') ?></td>
                <td class="<?= t('td') ?> text-center">
                    <?php if ($m['type'] !== 'sale'): ?>
                    <button type="button"
                            onclick="confirmDeleteMovement(<?= $m['id'] ?>, '<?= $typeLabel ?>', '<?= fmtQtyPhp($qty) ?>')"
                            class="w-6 h-6 flex items-center justify-center rounded text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors mx-auto">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Delete movement modal -->
<div id="delMovModal" class="fixed inset-0 z-50 hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeDelMov()"></div>
    <div class="relative bg-white w-full max-w-sm rounded-2xl shadow-2xl p-6 mx-4">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-slate-800">Delete Movement</h3>
                <p class="text-xs text-slate-400 mt-0.5">Stock summary will be fully recalculated.</p>
            </div>
        </div>
        <p class="text-sm text-slate-600 mb-6">Delete <strong id="delMovDesc" class="text-slate-900"></strong>?</p>
        <div class="flex gap-2 justify-end">
            <button onclick="closeDelMov()" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Cancel</button>
            <button id="delMovBtn" class="<?= t('btn_base') ?> <?= t('btn_danger') ?> h-9">Delete</button>
        </div>
    </div>
</div>
<script>
var _delMovId = null;
function confirmDeleteMovement(id, type, qty) {
    _delMovId = id;
    document.getElementById('delMovDesc').textContent = type + ' of ' + qty;
    var m = document.getElementById('delMovModal');
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeDelMov() {
    _delMovId = null;
    var m = document.getElementById('delMovModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
document.getElementById('delMovBtn').addEventListener('click', function() {
    if (!_delMovId) return;
    fetch('inventory_save.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete_movement&movement_id=' + _delMovId
    }).then(r => r.json()).then(d => {
        closeDelMov();
        if (d.success) { showToast('Movement deleted.', true); setTimeout(() => location.reload(), 600); }
        else showToast(d.message || 'Failed.', false);
    }).catch(() => { closeDelMov(); showToast('Server error.', false); });
});
</script>

<?php layoutClose();
endif;
?>