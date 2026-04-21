<?php
require_once 'config/bootstrap.php';
requireAuth();
include 'includes/layout.php';
require_once 'inventory_helper.php';

$pdo    = db();
$method = inventoryMethod($pdo);

// ── Save ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = in_array($_POST['inventory_method'] ?? '', ['fifo', 'average'])
           ? $_POST['inventory_method'] : 'average';

    $pdo->prepare("
        INSERT INTO app_settings (`key`, `value`) VALUES ('inventory_method', ?)
        ON DUPLICATE KEY UPDATE `value` = ?
    ")->execute([$new, $new]);

    $label = $new === 'fifo' ? 'FIFO' : 'Weighted Average';
    flash('success', "Costing method set to {$label}.");
    redirect('inventory_settings.php');
}

layoutOpen('Inventory Settings', 'Configure how stock costs are calculated.');
?>

<div class="bg-white rounded-xl border border-slate-200 mb-6">

    <form method="post">

        <!-- Costing Method section — same grid as product.php sections -->
        <div class="grid grid-cols-[200px_1fr]">
            <div class="p-6 border-r border-slate-100">
                <h3 class="text-sm font-semibold text-slate-800 mb-1">Costing Method</h3>
                <p class="text-xs text-slate-400 leading-relaxed">
                    Determines how the cost of goods sold (COGS) is calculated when stock is consumed.
                </p>
            </div>
            <div class="p-6 space-y-3" x-data="{ method: '<?= $method ?>' }">

                <!-- Weighted Average option -->
                <label class="flex items-start gap-3 p-4 border-2 rounded-xl cursor-pointer transition-colors"
                       :class="method === 'average' ? 'border-indigo-500 bg-indigo-50/40' : 'border-slate-200 hover:border-slate-300'">
                    <input type="radio" name="inventory_method" value="average"
                           x-model="method"
                           class="mt-0.5 text-indigo-600 border-slate-300 focus:ring-indigo-500">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-slate-800">Weighted Average</span>
                            <span class="text-[10px] font-bold text-sky-700 bg-sky-100 px-1.5 py-0.5 rounded">Recommended</span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                            Recalculates a rolling average cost every time stock is received.
                            Simpler and suits most businesses — cost is smoothed across all purchases.
                        </p>
                        <p class="text-xs text-slate-400 mt-2 font-mono bg-slate-50 rounded-lg px-3 py-2 inline-block">
                            Avg Cost = (Old Value + New Qty × New Cost) ÷ Total Qty
                        </p>
                    </div>
                </label>

                <!-- FIFO option -->
                <label class="flex items-start gap-3 p-4 border-2 rounded-xl cursor-pointer transition-colors"
                       :class="method === 'fifo' ? 'border-indigo-500 bg-indigo-50/40' : 'border-slate-200 hover:border-slate-300'">
                    <input type="radio" name="inventory_method" value="fifo"
                           x-model="method"
                           class="mt-0.5 text-indigo-600 border-slate-300 focus:ring-indigo-500">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-slate-800">FIFO</span>
                            <span class="text-xs text-slate-400 font-normal">First In, First Out</span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                            Oldest stock layers are consumed first. Tracks individual purchase batches and their costs separately.
                            Better suited when purchase prices fluctuate significantly.
                        </p>
                        <p class="text-xs text-slate-400 mt-2 font-mono bg-slate-50 rounded-lg px-3 py-2 inline-block">
                            COGS = Cost of the oldest available batch first
                        </p>
                    </div>
                </label>

                <!-- Warning note -->
                <div class="flex items-start gap-2.5 px-4 py-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-700">
                    <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    <span>Changing the costing method takes effect on the <strong>next movement</strong>. Existing stock summaries will be recalculated automatically.</span>
                </div>

            </div>
        </div>

        <!-- Sticky footer — matches product.php pattern exactly -->
        <div class="fixed bottom-0 right-0 bg-white border-t border-slate-200 z-20 flex items-center justify-end gap-3 px-8 py-3" style="left:256px">
            <button type="submit" class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-9">Save Settings</button>
        </div>

    </form>

</div>

<?php layoutClose(); ?>
