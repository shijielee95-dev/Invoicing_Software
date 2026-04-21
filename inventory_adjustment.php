<?php
require_once 'config/bootstrap.php';
requireAuth();
include 'includes/layout.php';
include 'includes/dropdown.php';
require_once 'inventory_helper.php';

$pdo    = db();
$action = $_GET['action'] ?? 'list';
$user   = authUser();

// ── Ensure adj_items table exists (auto-migrate) ──────────────────────────────
try { $pdo->exec("ALTER TABLE inventory_adjustments MODIFY COLUMN product_id INT UNSIGNED DEFAULT NULL"); } catch (Exception $e) {}
$pdo->exec("
    CREATE TABLE IF NOT EXISTS adj_items (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        adj_id      INT UNSIGNED NOT NULL,
        product_id  INT UNSIGNED NOT NULL,
        qty_before  DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
        qty_after   DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
        qty_change  DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
        INDEX idx_adj_items_adj (adj_id),
        INDEX idx_adj_items_prod (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// Migrate existing single-product records that haven't been migrated yet
$pdo->exec("
    INSERT IGNORE INTO adj_items (adj_id, product_id, qty_before, qty_after, qty_change)
    SELECT a.id, a.product_id, a.qty_before, a.qty_after, a.qty_change
    FROM inventory_adjustments a
    WHERE a.product_id IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM adj_items ai WHERE ai.adj_id = a.id)
");

function fmtQtyPhp(float $n): string {
    $s = number_format($n, 5, '.', '');
    $s = rtrim($s, '0');
    $s = rtrim($s, '.');
    return $s;
}

// ── Helper: load items for an adjustment ─────────────────────────────────────
function loadAdjItems(PDO $pdo, int $adjId): array {
    $stmt = $pdo->prepare("
        SELECT ai.*, p.name AS product_name, p.sku, p.base_unit_label,
               COALESCE(s.qty_on_hand, 0) AS current_qty
        FROM adj_items ai
        JOIN products p ON p.id = ai.product_id
        LEFT JOIN product_stock_summary s ON s.product_id = ai.product_id
        WHERE ai.adj_id = ?
        ORDER BY ai.id
    ");
    $stmt->execute([$adjId]);
    return $stmt->fetchAll();
}

// ─────────────────────────────────────────────────────────────────────────────
// LIST
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'list'):

$search    = trim($_GET['search']    ?? '');
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to']   ?? '');
$direction = trim($_GET['direction'] ?? '');

$where  = ['1=1'];
$params = [];
if ($search !== '') {
    $where[]  = '(a.adj_no LIKE ? OR EXISTS (SELECT 1 FROM adj_items ai JOIN products px ON px.id=ai.product_id WHERE ai.adj_id=a.id AND (px.name LIKE ? OR px.sku LIKE ?)) OR a.reference LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%";
    $params[] = "%$search%"; $params[] = "%$search%";
}
if ($dateFrom !== '') { $where[] = 'DATE(a.created_at) >= ?'; $params[] = $dateFrom; }
if ($dateTo   !== '') { $where[] = 'DATE(a.created_at) <= ?'; $params[] = $dateTo; }
if ($direction === 'in')  { $where[] = 'EXISTS (SELECT 1 FROM adj_items ai WHERE ai.adj_id=a.id AND ai.qty_change > 0)'; }
if ($direction === 'out') { $where[] = 'EXISTS (SELECT 1 FROM adj_items ai WHERE ai.adj_id=a.id AND ai.qty_change < 0)'; }

$stmt = $pdo->prepare("
    SELECT a.id, a.adj_no, a.reference, a.notes, a.created_at,
           u.name AS created_by_name,
           COUNT(ai.id) AS item_count
    FROM inventory_adjustments a
    LEFT JOIN adj_items ai ON ai.adj_id = a.id
    LEFT JOIN users u ON u.id = a.created_by
    WHERE " . implode(' AND ', $where) . "
    GROUP BY a.id, a.adj_no, a.reference, a.notes, a.created_at, u.name
    ORDER BY a.created_at DESC, a.id DESC
");
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format for JS
$entriesForJs = array_map(function($e) {
    return [
        'id'         => (int)$e['id'],
        'adj_no'     => $e['adj_no'],
        'date'       => date('d M Y', strtotime($e['created_at'])),
        'reference'  => $e['reference'] ?? '',
        'notes'      => $e['notes'] ?? '',
        'created_by' => $e['created_by_name'] ?? '',
    ];
}, $entries);
$entriesJson = json_encode(array_values($entriesForJs), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

layoutOpen('Stock Adjustments', 'Manage your stock count corrections.');
?>
<style>
/* ── Date picker ── */
.dp-popup{position:fixed;z-index:9999;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.12);padding:16px;width:284px;font-family:'Inter',sans-serif;display:none}
.dp-popup.is-open{display:block}
.dp-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.dp-nav-btn{width:28px;height:28px;border-radius:7px;border:none;background:transparent;cursor:pointer;color:#64748b;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.dp-nav-btn:hover{background:#f1f5f9;color:#4f46e5}
.dp-title-btn{background:transparent;border:none;font-size:13px;font-weight:600;color:#1e293b;cursor:pointer;padding:3px 8px;border-radius:7px;letter-spacing:0.01em}
.dp-title-btn:hover{background:#eef2ff;color:#4f46e5}
.dp-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
.dp-dow{font-size:10px;font-weight:600;color:#94a3b8;text-align:center;padding:4px 0;text-transform:uppercase}
.dp-day{width:32px;height:32px;border-radius:8px;border:none;background:transparent;font-size:12px;color:#374151;cursor:pointer;display:flex;align-items:center;justify-content:center;margin:0 auto}
.dp-day:hover:not(.dp-other):not(.dp-sel){background:#eef2ff;color:#4f46e5}
.dp-day.dp-sel{background:#4f46e5;color:#fff;font-weight:600}
.dp-day.dp-today:not(.dp-sel){color:#4f46e5;font-weight:600}
.dp-day.dp-other{color:#cbd5e1;cursor:default}
.dp-month-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:4px 0}
.dp-mon-cell{height:36px;border-radius:9px;border:none;background:transparent;font-size:12px;font-weight:500;color:#374151;cursor:pointer;display:flex;align-items:center;justify-content:center}
.dp-mon-cell:hover:not(.dp-mon-sel){background:#eef2ff;color:#4f46e5}
.dp-mon-cell.dp-mon-sel{background:#4f46e5;color:#fff;font-weight:600}
.dp-mon-cell.dp-mon-cur:not(.dp-mon-sel){color:#4f46e5;font-weight:600}
.dp-year-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:4px 0}
.dp-yr-cell{height:36px;border-radius:9px;border:none;background:transparent;font-size:12px;font-weight:500;color:#374151;cursor:pointer;display:flex;align-items:center;justify-content:center}
.dp-yr-cell:hover:not(.dp-yr-sel):not(.dp-yr-out){background:#eef2ff;color:#4f46e5}
.dp-yr-cell.dp-yr-sel{background:#4f46e5;color:#fff;font-weight:600}
.dp-yr-cell.dp-yr-cur:not(.dp-yr-sel){color:#4f46e5;font-weight:600}
.dp-yr-cell.dp-yr-out{color:#cbd5e1;cursor:default}
.dp-footer{border-top:1px solid #f1f5f9;margin-top:10px;padding-top:8px;text-align:center}
.dp-today-btn{font-size:12px;font-weight:500;color:#4f46e5;background:none;border:none;cursor:pointer;padding:2px 10px;border-radius:6px}
.dp-today-btn:hover{background:#eef2ff}
/* ── Table ── */
#adjDataScroll::-webkit-scrollbar{display:none}
#adjDataScroll{scrollbar-width:none;-ms-overflow-style:none}
#adjActionsScroll::-webkit-scrollbar{display:none}
#adjActionsScroll{scrollbar-width:none;-ms-overflow-style:none}
#adjBody tr,#adjActionsBody tr{border-bottom:1px solid #e2e8f0}
#adjBody td,#adjActionsBody td{border-bottom:none!important}
</style>

<script>
document.getElementById('pageActions').innerHTML =
    '<a href="inventory_adjustment.php?action=new" class="<?= t('btn_base').' '.t('btn_primary') ?> h-9">' +
    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>' +
    'New Adjustment</a>';
document.querySelector('main').style.overflow      = 'hidden';
document.querySelector('main').style.display       = 'flex';
document.querySelector('main').style.flexDirection = 'column';
</script>

<!-- Filter bar -->
<form id="filterForm" method="GET" class="<?= t('card') ?> flex flex-wrap items-end gap-3 mb-5"
      onkeydown="if(event.key==='Enter'&&event.target.id!=='filterSearch'){event.preventDefault();}">
    <input type="hidden" name="action" value="list">
    <div class="flex-1 min-w-48">
        <label class="<?= t('label') ?>">Search</label>
        <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            <input type="text" name="search" id="filterSearch" value="<?= e($search) ?>"
                   placeholder="Adj #, product, reference…"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();document.getElementById('filterForm').submit();}"
                   class="<?= t('input') ?> pl-8">
        </div>
    </div>
    <div>
        <label class="<?= t('label') ?>">From</label>
        <div class="relative dp-wrap">
            <input type="text" id="filterDateFrom" readonly
                   value="<?= $dateFrom ? date('d/m/Y', strtotime($dateFrom)) : '' ?>"
                   placeholder="DD/MM/YYYY"
                   class="<?= t('input') ?> cursor-pointer pr-8">
            <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        </div>
        <input type="hidden" name="date_from" id="filterDateFromIso" value="<?= e($dateFrom) ?>">
    </div>
    <div>
        <label class="<?= t('label') ?>">To</label>
        <div class="relative dp-wrap">
            <input type="text" id="filterDateTo" readonly
                   value="<?= $dateTo ? date('d/m/Y', strtotime($dateTo)) : '' ?>"
                   placeholder="DD/MM/YYYY"
                   class="<?= t('input') ?> cursor-pointer pr-8">
            <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        </div>
        <input type="hidden" name="date_to" id="filterDateToIso" value="<?= e($dateTo) ?>">
    </div>
    <div>
        <label class="<?= t('label') ?>">Direction</label>
        <div id="directionWrap">
        <?php renderDropdown('direction', ['' => 'All', 'in' => 'Stock In (+)', 'out' => 'Stock Out (−)'], $direction); ?>
        </div>
    </div>
    <div class="flex gap-2">
        <button type="submit" class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-9">Filter</button>
        <?php if ($search || $dateFrom || $dateTo || $direction): ?>
        <a href="inventory_adjustment.php" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Clear</a>
        <?php endif; ?>
    </div>
    <div class="ml-auto">
        <button type="button" onclick="openColPanel()"
                class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9 gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/>
            </svg>
            Columns
        </button>
    </div>
</form>

<!-- Table wrapper -->
<div id="adjWrap" class="flex flex-col flex-1 min-h-0">
    <div class="bg-white rounded-xl border border-slate-200 flex-1 min-h-0 flex flex-row overflow-hidden">

        <!-- LEFT: data columns — scrolls horizontally and vertically -->
        <div class="flex flex-col flex-1 min-w-0 min-h-0">
            <!-- Data thead -->
            <div class="shrink-0 bg-slate-50 border-b border-slate-200 overflow-x-hidden">
                <table class="text-sm" style="table-layout:fixed;width:100%">
                    <thead id="adjThead">
                        <tr class="flex w-full">
                            <th class="<?= t('th') ?>" style="flex:12 1 0;min-width:80px" data-col="date">Date</th>
                            <th class="<?= t('th') ?>" style="flex:14 1 0;min-width:80px" data-col="adj_no">Adj #</th>
                            <th class="<?= t('th') ?>" style="flex:14 1 0;min-width:70px" data-col="reference">Reference</th>
                            <th class="<?= t('th') ?>" style="flex:26 1 0;min-width:100px" data-col="notes">Notes</th>
                            <th class="<?= t('th') ?>" style="flex:10 1 0;min-width:50px" data-col="created_by">By</th>
                        </tr>
                    </thead>
                </table>
            </div>
            <!-- Data tbody — scrolls both axes -->
            <div id="adjDataScroll" class="flex-1 overflow-y-auto overflow-x-auto">
                <table class="text-sm w-full" style="table-layout:fixed">
                    <tbody id="adjBody" class="block w-full">
                        <!-- Populated by JS -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- RIGHT: actions column — always visible, never scrolls horizontally -->
        <div class="flex flex-col shrink-0" style="width:90px">
            <!-- Actions thead -->
            <div id="adjActionsThead" class="shrink-0 bg-slate-50 border-b border-slate-200 flex items-center justify-center text-center" style="height:41px">
                <span class="<?= t('th') ?> w-full text-center">Actions</span>
            </div>
            <!-- Actions tbody — vertical scroll only, synced with data -->
            <div id="adjActionsScroll" class="flex-1 overflow-y-hidden overflow-x-hidden">
                <table class="text-sm" style="table-layout:fixed;width:90px">
                    <tbody id="adjActionsBody" class="block w-full">
                        <!-- Synced with adjBody by JS -->
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- Pagination bar -->
    <div id="adjPager" class="flex items-center justify-between mt-3 shrink-0 h-9">
        <div class="flex items-center gap-3 h-full">
            <span id="adjInfo" class="text-sm text-slate-500 whitespace-nowrap"></span>
            <div class="flex items-center gap-1.5 h-full"
                 x-data="{open:false,value:'20',options:[{v:'20',l:'20'},{v:'50',l:'50'},{v:'100',l:'100'}]}"
                 x-init="$watch('value', function(v){ adjPerPage=parseInt(v,10); adjPage=1; adjRender(); })">
                <span class="text-xs text-slate-400">Show</span>
                <div class="relative">
                    <button type="button" @click="open=!open" @keydown.escape="open=false"
                            class="h-9 px-3 rounded-lg bg-white border border-slate-300 flex items-center gap-2 text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-400" style="min-width:4rem">
                        <span x-text="value" class="text-slate-700"></span>
                        <svg class="w-3.5 h-3.5 text-slate-400 shrink-0 transition-transform" :class="open?'rotate-180':''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open" @click.outside="open=false" style="display:none"
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="opacity-0 scale-95"
                         x-transition:enter-end="opacity-100 scale-100"
                         class="absolute left-0 bottom-full mb-1 bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden z-50" style="min-width:4rem">
                        <ul class="py-1">
                            <template x-for="opt in options" :key="opt.v">
                                <li>
                                    <button type="button" @click="value=opt.v; open=false"
                                            class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                            :class="value===opt.v ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-700 hover:bg-slate-50'">
                                        <span x-text="opt.l"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
                <span class="text-xs text-slate-400">records</span>
            </div>
        </div>
        <div id="adjPages" class="flex items-center gap-1 h-full"></div>
    </div>
</div>

<!-- Delete modal -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeDelete()"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-slate-800">Delete Adjustment</h3>
                <p class="text-xs text-slate-400 mt-0.5">Stock levels will be recalculated.</p>
            </div>
        </div>
        <p class="text-sm text-slate-600 mb-6">Delete <strong id="deleteAdjNo" class="text-slate-900"></strong>?</p>
        <div class="flex gap-2 justify-end">
            <button onclick="closeDelete()" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Cancel</button>
            <button id="confirmDeleteBtn" class="<?= t('btn_base') ?> <?= t('btn_danger') ?> h-9">Delete</button>
        </div>
    </div>
</div>

<!-- Column selector backdrop -->
<div id="colBackdrop" onclick="closeColPanel()"
     style="opacity:0;pointer-events:none;transition:opacity 0.25s ease"
     class="fixed inset-0 bg-black/30 z-[9998]"></div>

<!-- Column selector panel -->
<div id="colPanel"
     style="transform:translateX(100%);transition:transform 0.3s cubic-bezier(0.4,0,0.2,1)"
     class="fixed top-0 right-0 h-screen w-64 bg-white shadow-2xl z-[9999] flex flex-col border-l border-slate-200 invisible">
    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 shrink-0">
        <h2 class="text-sm font-semibold text-slate-800">Select Columns</h2>
        <button type="button" onclick="closeColPanel()"
                class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors text-xl font-light">&times;</button>
    </div>
    <!-- View All -->
    <div class="border-b border-slate-100 px-5 py-3 shrink-0">
        <label class="flex items-center gap-3 cursor-pointer select-none">
            <span id="colCbAll" class="inline-flex w-4 h-4 rounded border-2 border-slate-300 bg-white items-center justify-center shrink-0 transition-colors"></span>
            <input type="checkbox" id="colToggleAll" class="sr-only">
            <span class="text-sm font-medium text-slate-700">View All Columns</span>
        </label>
    </div>
    <div class="flex-1 overflow-y-auto py-2 px-5">
        <!-- Always-on: Adj # -->
        <div class="flex items-center gap-3 py-2.5 select-none">
            <span class="inline-flex w-4 h-4 rounded border-2 border-indigo-300 bg-indigo-100 items-center justify-center shrink-0">
                <svg style="width:9px;height:9px" fill="#6366f1" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </span>
            <span class="text-sm text-slate-400">Adj #</span>
            <span class="ml-auto text-xs text-slate-300">Always</span>
        </div>
        <!-- Toggleable columns -->
        <label class="flex items-center gap-3 py-2.5 cursor-pointer rounded-lg hover:bg-slate-50 -mx-1 px-1 transition-colors" onclick="colToggleClick('date');return false;">
            <span id="colCb_date" class="col-cb inline-flex w-4 h-4 rounded border-2 border-slate-300 bg-white items-center justify-center shrink-0 transition-colors" data-col="date"></span>
            <input type="checkbox" class="col-toggle sr-only" data-col="date">
            <span class="text-sm text-slate-700">Date</span>
        </label>
        <label class="flex items-center gap-3 py-2.5 cursor-pointer rounded-lg hover:bg-slate-50 -mx-1 px-1 transition-colors" onclick="colToggleClick('reference');return false;">
            <span id="colCb_reference" class="col-cb inline-flex w-4 h-4 rounded border-2 border-slate-300 bg-white items-center justify-center shrink-0 transition-colors" data-col="reference"></span>
            <input type="checkbox" class="col-toggle sr-only" data-col="reference">
            <span class="text-sm text-slate-700">Reference</span>
        </label>
        <label class="flex items-center gap-3 py-2.5 cursor-pointer rounded-lg hover:bg-slate-50 -mx-1 px-1 transition-colors" onclick="colToggleClick('notes');return false;">
            <span id="colCb_notes" class="col-cb inline-flex w-4 h-4 rounded border-2 border-slate-300 bg-white items-center justify-center shrink-0 transition-colors" data-col="notes"></span>
            <input type="checkbox" class="col-toggle sr-only" data-col="notes">
            <span class="text-sm text-slate-700">Notes</span>
        </label>
        <label class="flex items-center gap-3 py-2.5 cursor-pointer rounded-lg hover:bg-slate-50 -mx-1 px-1 transition-colors" onclick="colToggleClick('created_by');return false;">
            <span id="colCb_created_by" class="col-cb inline-flex w-4 h-4 rounded border-2 border-slate-300 bg-white items-center justify-center shrink-0 transition-colors" data-col="created_by"></span>
            <input type="checkbox" class="col-toggle sr-only" data-col="created_by">
            <span class="text-sm text-slate-700">By</span>
        </label>
        <!-- Always-on: Actions -->
        <div class="flex items-center gap-3 py-2.5 select-none">
            <span class="inline-flex w-4 h-4 rounded border-2 border-indigo-300 bg-indigo-100 items-center justify-center shrink-0">
                <svg style="width:9px;height:9px" fill="#6366f1" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </span>
            <span class="text-sm text-slate-400">Actions</span>
            <span class="ml-auto text-xs text-slate-300">Always</span>
        </div>
    </div>
    <div class="shrink-0 border-t border-slate-100 px-5 py-3 flex items-center justify-between bg-white">
        <button type="button" onclick="resetCols()" class="text-xs text-slate-400 hover:text-indigo-600 transition-colors">Reset to default</button>
        <button type="button" onclick="closeColPanel()" class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-8 text-xs px-4">Done</button>
    </div>
</div>

<script>
var ADJ_DATA   = <?= $entriesJson ?>;
var ADJ_TOTAL  = ADJ_DATA.length;
var adjPage    = 1;
var adjPerPage = 20;

function adjEsc(s) { return s == null ? '' : String(s); }

function adjRender() {
    var start = (adjPage - 1) * adjPerPage;
    var end   = Math.min(start + adjPerPage, ADJ_TOTAL);
    var slice = ADJ_DATA.slice(start, end);
    var tbody = document.getElementById('adjBody');

    if (ADJ_TOTAL === 0) {
        tbody.innerHTML =
            '<tr class="flex w-full"><td colspan="8" class="flex-1 px-4 py-12 text-center">' +
            '<div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center mx-auto mb-3">' +
            '<svg class="w-5 h-5 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>' +
            '</div><p class="text-sm text-slate-400 mb-1">No adjustment entries found.</p>' +
            '<a href="inventory_adjustment.php?action=new" class="text-sm text-indigo-600 hover:underline">Create the first one \u2192</a>' +
            '</td></tr>';
        adjRenderPager(0, 0);
        var actBodyEmpty = document.getElementById('adjActionsBody');
        if (actBodyEmpty) actBodyEmpty.innerHTML = '';
        return;
    }

    var td = '<?= t('td') ?>';
    var dataRows   = [];
    var actionRows = [];

    slice.forEach(function(a) {
        var adjNoSafe = adjEsc(a.adj_no).replace(/'/g, "\'").replace(/"/g, '&quot;');

        dataRows.push(
            '<tr class="flex w-full items-center hover:bg-slate-50 transition-colors cursor-pointer" data-row="' + a.id + '" data-href="inventory_adjustment.php?action=view&id=' + a.id + '">' +
            '<td class="' + td + ' whitespace-nowrap text-slate-600 overflow-hidden" style="flex:12 1 0;min-width:80px" data-col="date">' + adjEsc(a.date) + '</td>' +
            '<td class="' + td + ' whitespace-nowrap overflow-hidden" style="flex:14 1 0;min-width:80px" data-col="adj_no">' +
                '<a href="inventory_adjustment.php?action=view&id=' + a.id + '" class="font-semibold text-indigo-600 hover:text-indigo-800 transition-colors">' + adjEsc(a.adj_no) + '</a>' +
            '</td>' +
            '<td class="' + td + ' text-slate-500 text-xs truncate overflow-hidden whitespace-nowrap" style="flex:14 1 0;min-width:70px" data-col="reference">' + (adjEsc(a.reference) || '\u2014') + '</td>' +
            '<td class="' + td + ' text-slate-500 text-xs truncate overflow-hidden whitespace-nowrap" style="flex:26 1 0;min-width:100px" data-col="notes">' + (adjEsc(a.notes) || '\u2014') + '</td>' +
            '<td class="' + td + ' text-xs text-slate-400 truncate overflow-hidden whitespace-nowrap" style="flex:10 1 0;min-width:50px" data-col="created_by">' + (adjEsc(a.created_by) || '\u2014') + '</td>' +
            '</tr>'
        );

        actionRows.push(
            '<tr class="flex w-full items-center justify-center hover:bg-slate-50 transition-colors" data-row="' + a.id + '">' +
            '<td class="flex items-center justify-center gap-1 py-2.5 px-1 w-full">' +
                '<a href="inventory_adjustment.php?action=view&id=' + a.id + '" title="View" class="w-7 h-7 flex items-center justify-center rounded-md text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors">' +
                    '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>' +
                '</a>' +
                '<a href="inventory_adjustment.php?action=edit&id=' + a.id + '" title="Edit" class="w-7 h-7 flex items-center justify-center rounded-md text-slate-400 hover:text-amber-600 hover:bg-amber-50 transition-colors">' +
                    '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>' +
                '</a>' +
                '<button type="button" onclick="confirmDelete(' + a.id + ',\'' + adjNoSafe + '\')" title="Delete" class="w-7 h-7 flex items-center justify-center rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors">' +
                    '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>' +
                '</button>' +
            '</td>' +
            '</tr>'
        );
    });

    tbody.innerHTML = dataRows.join('');
    var actBody = document.getElementById('adjActionsBody');
    if (actBody) actBody.innerHTML = actionRows.join('');

    adjRenderPager(start + 1, end);
    applyColPrefs(loadColPrefs());

    // Sync row heights and scroll after render
    requestAnimationFrame(function() { requestAnimationFrame(function() { adjSyncRows(); }); });
}

function adjSyncRows() {
    // Sync thead height
    var dataThead = document.getElementById('adjThead');
    var actThead  = document.getElementById('adjActionsThead');
    if (dataThead && actThead) {
        actThead.style.height = dataThead.closest('div').offsetHeight + 'px';
    }
    // Sync heights: measure data rows, apply to action rows
    // Use getBoundingClientRect for accurate post-layout height
    document.querySelectorAll('#adjBody tr[data-row]').forEach(function(tr) {
        var actTr = document.querySelector('#adjActionsBody tr[data-row="' + tr.dataset.row + '"]');
        if (actTr) {
            var h = tr.getBoundingClientRect().height;
            actTr.style.height = (h || tr.offsetHeight) + 'px';
            actTr.style.minHeight = (h || tr.offsetHeight) + 'px';
        }
    });
    // Wire scroll sync (once)
    var ds = document.getElementById('adjDataScroll');
    var as = document.getElementById('adjActionsScroll');
    if (ds && as && !ds._syncBound) {
        ds._syncBound = true;
        ds.addEventListener('scroll', function() { as.scrollTop = ds.scrollTop; });
    }
    // Wire hover sync + row click
    document.querySelectorAll('#adjBody tr[data-row]').forEach(function(tr) {
        var actTr = document.querySelector('#adjActionsBody tr[data-row="' + tr.dataset.row + '"]');
        if (!actTr) return;
        tr.addEventListener('mouseenter',  function() { actTr.classList.add('bg-slate-50'); });
        tr.addEventListener('mouseleave',  function() { actTr.classList.remove('bg-slate-50'); });
        actTr.addEventListener('mouseenter', function() { tr.classList.add('bg-slate-50'); actTr.classList.add('bg-slate-50'); });
        actTr.addEventListener('mouseleave', function() { tr.classList.remove('bg-slate-50'); actTr.classList.remove('bg-slate-50'); });
        // Click row to navigate to view (skip if clicking a link/button)
        if (!tr._clickBound && tr.dataset.href) {
            tr._clickBound = true;
            tr.addEventListener('click', function(e) {
                if (e.target.closest('a, button')) return;
                window.location.href = tr.dataset.href;
            });
        }
    });
}

function adjRenderPager(from, to) {
    var totalPages = Math.max(1, Math.ceil(ADJ_TOTAL / adjPerPage));
    document.getElementById('adjInfo').textContent =
        ADJ_TOTAL === 0 ? '' : from + '\u2013' + to + ' of ' + ADJ_TOTAL + ' items';
    if (totalPages <= 1) { document.getElementById('adjPages').innerHTML = ''; return; }
    var B = 'w-8 h-8 flex items-center justify-center rounded-lg border text-xs transition-colors cursor-pointer ';
    var active   = B + 'bg-indigo-600 border-indigo-600 text-white font-semibold';
    var normal   = B + 'border-slate-200 hover:border-indigo-400 hover:text-indigo-600';
    var disabled = B + 'border-slate-100 text-slate-300 cursor-default';
    var cL = '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>';
    var cR = '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>';
    var h = '';
    h += adjPage > 1 ? '<span class="' + normal + '" onclick="adjGo(' + (adjPage-1) + ')">' + cL + '</span>'
                     : '<span class="' + disabled + '">' + cL + '</span>';
    var ps = Math.max(1, adjPage-2), pe = Math.min(totalPages, adjPage+2);
    if (ps > 1) { h += '<span class="' + normal + '" onclick="adjGo(1)">1</span>'; if (ps > 2) h += '<span class="px-1 text-slate-300">\u2026</span>'; }
    for (var p = ps; p <= pe; p++)
        h += '<span class="' + (p===adjPage ? active : normal) + '"' + (p!==adjPage ? ' onclick="adjGo(' + p + ')"' : '') + '>' + p + '</span>';
    if (pe < totalPages) { if (pe < totalPages-1) h += '<span class="px-1 text-slate-300">\u2026</span>'; h += '<span class="' + normal + '" onclick="adjGo(' + totalPages + ')">' + totalPages + '</span>'; }
    h += adjPage < totalPages ? '<span class="' + normal + '" onclick="adjGo(' + (adjPage+1) + ')">' + cR + '</span>'
                              : '<span class="' + disabled + '">' + cR + '</span>';
    document.getElementById('adjPages').innerHTML = h;
}

function adjGo(p) { adjPage = p; adjRender(); }

function adjSetHeight() {
    var adjWrap = document.getElementById('adjWrap');
    if (!adjWrap) return;
    var wrapTop = adjWrap.getBoundingClientRect().top;
    var thead   = document.getElementById('adjThead');
    var theadH  = thead ? thead.closest('div').offsetHeight : 41;
    var pagerH  = 48;
    var mainPad = 24;
    var h = Math.max(60, window.innerHeight - wrapTop - theadH - pagerH - mainPad) + 'px';
    var ds = document.getElementById('adjDataScroll');
    var as = document.getElementById('adjActionsScroll');
    if (ds) ds.style.height = h;
    if (as) as.style.height = h;
}

// ── Delete ───────────────────────────────────────────────────────
var _deleteId = null;
function confirmDelete(id, no) {
    _deleteId = id;
    document.getElementById('deleteAdjNo').textContent = no;
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
}
function closeDelete() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
    _deleteId = null;
}
document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (!_deleteId) return;
    var btn = this;
    btn.disabled = true; btn.textContent = 'Deleting\u2026';
    fetch('inventory_save.php', {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=delete_adjustment&id='+_deleteId
    }).then(r=>r.json()).then(d=>{
        closeDelete(); btn.disabled=false; btn.textContent='Delete';
        if (d.success) { showToast('Adjustment deleted.', true); setTimeout(()=>location.reload(), 700); }
        else showToast(d.message||'Failed.', false);
    }).catch(()=>{ closeDelete(); btn.disabled=false; btn.textContent='Delete'; showToast('Server error.', false); });
});

// ── Column visibility ─────────────────────────────────────────────
var COL_KEY      = 'adj_col_prefs_v2';
var COL_DEFAULTS = {date:true,reference:true,notes:true,created_by:true};

function loadColPrefs() {
    try { var s = localStorage.getItem(COL_KEY); return s ? JSON.parse(s) : Object.assign({}, COL_DEFAULTS); }
    catch(e) { return Object.assign({}, COL_DEFAULTS); }
}
function saveColPrefs(prefs) {
    try { localStorage.setItem(COL_KEY, JSON.stringify(prefs)); } catch(e) {}
}
function applyColPrefs(prefs) {
    Object.keys(prefs).forEach(function(col) {
        var vis = prefs[col];
        document.querySelectorAll('[data-col="' + col + '"]').forEach(function(el) {
            if (!el.classList.contains('col-cb')) el.style.display = vis ? '' : 'none';
        });
        var cb = document.querySelector('.col-toggle[data-col="' + col + '"]');
        if (cb) cb.checked = vis;
        updateColCb(col, vis);
    });
    updateViewAllCb();
}
function updateColCb(col, checked) {
    var box = document.getElementById('colCb_' + col);
    if (!box) return;
    if (checked) {
        box.style.background = '#4f46e5'; box.style.borderColor = '#4f46e5';
        box.innerHTML = '<svg style="width:9px;height:9px" fill="white" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
    } else {
        box.style.background = 'white'; box.style.borderColor = '#cbd5e1'; box.innerHTML = '';
    }
}
function updateViewAllCb() {
    var cbs   = document.querySelectorAll('.col-toggle');
    var total = cbs.length, checked = 0;
    cbs.forEach(function(cb) { if (cb.checked) checked++; });
    var box = document.getElementById('colCbAll');
    if (!box) return;
    if (checked === total) {
        box.style.background = '#4f46e5'; box.style.borderColor = '#4f46e5';
        box.innerHTML = '<svg style="width:9px;height:9px" fill="white" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
    } else if (checked > 0) {
        box.style.background = '#4f46e5'; box.style.borderColor = '#4f46e5';
        box.innerHTML = '<svg style="width:9px;height:9px" fill="white" viewBox="0 0 24 24"><path stroke="white" stroke-width="2.5" d="M5 12h14"/></svg>';
    } else {
        box.style.background = 'white'; box.style.borderColor = '#cbd5e1'; box.innerHTML = '';
    }
}
function colToggleClick(col) {
    var cb = document.querySelector('.col-toggle[data-col="' + col + '"]');
    if (!cb) return;
    cb.checked = !cb.checked;
    var prefs = loadColPrefs();
    prefs[col] = cb.checked;
    saveColPrefs(prefs);
    applyColPrefs(prefs);
}
// View All click — bound in DOMContentLoaded so DOM is ready
function bindViewAll() {
    var viewAllEl = document.getElementById('colCbAll');
    if (!viewAllEl) return;
    var viewAllLabel = viewAllEl.closest('label');
    if (!viewAllLabel) return;
    viewAllLabel.addEventListener('click', function(e) {
        e.preventDefault();
        var cbs   = document.querySelectorAll('.col-toggle');
        var allOn = Array.from(cbs).every(function(cb) { return cb.checked; });
        var newVal = !allOn;
        var prefs = loadColPrefs();
        cbs.forEach(function(cb) { cb.checked = newVal; prefs[cb.dataset.col] = newVal; });
        saveColPrefs(prefs);
        applyColPrefs(prefs);
    });
}
function resetCols() { saveColPrefs(COL_DEFAULTS); applyColPrefs(COL_DEFAULTS); }

function openColPanel() {
    var bd = document.getElementById('colBackdrop'), p = document.getElementById('colPanel');
    bd.style.pointerEvents = 'auto'; p.classList.remove('invisible');
    requestAnimationFrame(function() { requestAnimationFrame(function() {
        bd.style.opacity = '1'; p.style.transform = 'translateX(0)';
    }); });
}
function closeColPanel() {
    var bd = document.getElementById('colBackdrop'), p = document.getElementById('colPanel');
    bd.style.opacity = '0'; bd.style.pointerEvents = 'none';
    p.style.transform = 'translateX(100%)';
    setTimeout(function() { p.classList.add('invisible'); }, 300);
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeColPanel(); });

// ── Direction dropdown auto-submit ────────────────────────────────
document.addEventListener('alpine:initialized', function () {
    var wrap = document.getElementById('directionWrap');
    if (!wrap) return;
    var alpineEl = wrap.querySelector('[x-data]');
    if (!alpineEl) return;
    var data = Alpine.$data(alpineEl);
    if (!data) return;
    var skipFirst = true;
    Alpine.effect(function () {
        var val = data.value;
        if (skipFirst) { skipFirst = false; return; }
        document.getElementById('filterForm').submit();
    });
});

// ── Date picker ───────────────────────────────────────────────────
(function() {
    var MONTHS_LONG  = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var DAYS = ['Su','Mo','Tu','We','Th','Fr','Sa'];
    function pad(n) { return String(n).padStart(2,'0'); }
    function toDMY(d) { return pad(d.getDate())+'/'+pad(d.getMonth()+1)+'/'+d.getFullYear(); }
    function toISO(d) { return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()); }
    function parseISO(s) { if (!s) return new Date(); var p=s.split('-'); return p.length===3?new Date(+p[0],+p[1]-1,+p[2]):new Date(); }
    var chevL='<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>';
    var chevR='<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>';
    var dblL='<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M11 17l-5-5 5-5"/><path d="M18 17l-5-5 5-5"/></svg>';
    var dblR='<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M13 17l5-5-5-5"/><path d="M6 17l5-5-5-5"/></svg>';
    function makePicker(inputId, hiddenId, onChange) {
        var input=document.getElementById(inputId), hidden=document.getElementById(hiddenId);
        if (!input||!hidden) return;
        var current=hidden.value?parseISO(hidden.value):null;
        var viewing=current?new Date(current.getFullYear(),current.getMonth(),1):new Date(new Date().getFullYear(),new Date().getMonth(),1);
        var view='day', decadeStart=0;
        var popup=document.createElement('div'); popup.className='dp-popup'; document.body.appendChild(popup);
        function pos(){var r=input.getBoundingClientRect();popup.style.top=(r.bottom+6)+'px';popup.style.left=r.left+'px';}
        function renderDay(){
            var today=new Date(), y=viewing.getFullYear(), mo=viewing.getMonth();
            var first=new Date(y,mo,1).getDay(), dim=new Date(y,mo+1,0).getDate(), prev=new Date(y,mo,0).getDate();
            var cells='';
            for(var i=first-1;i>=0;i--) cells+='<button type="button" class="dp-day dp-other">'+(prev-i)+'</button>';
            for(var d=1;d<=dim;d++){
                var isT=(d===today.getDate()&&mo===today.getMonth()&&y===today.getFullYear());
                var isS=current&&(d===current.getDate()&&mo===current.getMonth()&&y===current.getFullYear());
                cells+='<button type="button" class="dp-day'+(isT?' dp-today':'')+(isS?' dp-sel':'')+'" data-day="'+d+'">'+d+'</button>';
            }
            var rem=(first+dim)%7; if(rem>0) for(var d2=1;d2<=7-rem;d2++) cells+='<button type="button" class="dp-day dp-other">'+d2+'</button>';
            popup.innerHTML='<div class="dp-head"><button type="button" class="dp-nav-btn" data-nav="-1">'+chevL+'</button><button type="button" class="dp-title-btn" data-view="month">'+MONTHS_LONG[mo]+' '+y+'</button><button type="button" class="dp-nav-btn" data-nav="1">'+chevR+'</button></div><div class="dp-grid">'+DAYS.map(function(d){return'<div class="dp-dow">'+d+'</div>';}).join('')+cells+'</div><div class="dp-footer"><button type="button" class="dp-today-btn" data-today>Today</button>'+(current?'<button type="button" class="dp-today-btn" style="color:#94a3b8" data-clear>Clear</button>':'')+'</div>';
            popup.querySelectorAll('[data-day]').forEach(function(btn){btn.addEventListener('click',function(){current=new Date(viewing.getFullYear(),viewing.getMonth(),+btn.dataset.day);input.value=toDMY(current);hidden.value=toISO(current);close();if(onChange)onChange();});});
            popup.querySelectorAll('[data-nav]').forEach(function(btn){btn.addEventListener('click',function(){viewing.setMonth(viewing.getMonth()+(+btn.dataset.nav));render();});});
            var vb=popup.querySelector('[data-view]'); if(vb)vb.addEventListener('click',function(){view='month';render();});
            var tb=popup.querySelector('[data-today]'); if(tb)tb.addEventListener('click',function(){current=new Date();viewing=new Date(current.getFullYear(),current.getMonth(),1);input.value=toDMY(current);hidden.value=toISO(current);close();if(onChange)onChange();});
            var cb=popup.querySelector('[data-clear]'); if(cb)cb.addEventListener('click',function(){current=null;input.value='';hidden.value='';close();});
        }
        function renderMonth(){
            var y=viewing.getFullYear(), todayM=new Date().getMonth(), todayY=new Date().getFullYear();
            var cells=MONTHS_SHORT.map(function(m,i){var isSel=current&&(i===current.getMonth()&&y===current.getFullYear());var isCur=(i===todayM&&y===todayY);return'<button type="button" class="dp-mon-cell'+(isSel?' dp-mon-sel':'')+((!isSel&&isCur)?' dp-mon-cur':'')+'" data-month="'+i+'">'+m+'</button>';}).join('');
            popup.innerHTML='<div class="dp-head"><button type="button" class="dp-nav-btn" data-ystep="-1">'+chevL+'</button><button type="button" class="dp-title-btn" data-view="year">'+y+'</button><button type="button" class="dp-nav-btn" data-ystep="1">'+chevR+'</button></div><div class="dp-month-grid">'+cells+'</div>';
            popup.querySelectorAll('[data-month]').forEach(function(btn){btn.addEventListener('click',function(){viewing.setMonth(+btn.dataset.month);view='day';render();});});
            popup.querySelectorAll('[data-ystep]').forEach(function(btn){btn.addEventListener('click',function(){viewing.setFullYear(viewing.getFullYear()+(+btn.dataset.ystep));render();});});
            var vb=popup.querySelector('[data-view]'); if(vb)vb.addEventListener('click',function(){view='year';decadeStart=Math.floor(viewing.getFullYear()/10)*10;render();});
        }
        function renderYear(){
            if(!decadeStart)decadeStart=Math.floor(viewing.getFullYear()/10)*10;
            var todayY=new Date().getFullYear(), cells='';
            for(var yr=decadeStart-1;yr<=decadeStart+10;yr++){var isOut=(yr<decadeStart||yr>decadeStart+9);var isSel=current&&yr===current.getFullYear();var isCur=(yr===todayY&&!isSel);cells+='<button type="button" class="dp-yr-cell'+(isSel?' dp-yr-sel':'')+(isCur?' dp-yr-cur':'')+(isOut?' dp-yr-out':'')+'"'+(isOut?'':' data-year="'+yr+'"')+'>'+yr+'</button>';}
            popup.innerHTML='<div class="dp-head"><button type="button" class="dp-nav-btn" data-decade="-1">'+dblL+'</button><span class="dp-title-btn" style="cursor:default">'+decadeStart+'\u2013'+(decadeStart+9)+'</span><button type="button" class="dp-nav-btn" data-decade="1">'+dblR+'</button></div><div class="dp-year-grid">'+cells+'</div>';
            popup.querySelectorAll('[data-year]').forEach(function(btn){btn.addEventListener('click',function(){viewing.setFullYear(+btn.dataset.year);view='month';render();});});
            popup.querySelectorAll('[data-decade]').forEach(function(btn){btn.addEventListener('click',function(){decadeStart+=(+btn.dataset.decade)*10;render();});});
        }
        function render(){if(view==='day')renderDay();else if(view==='month')renderMonth();else renderYear();}
        function close(){popup.classList.remove('is-open');view='day';}
        popup.addEventListener('click',function(e){e.stopPropagation();});
        input.addEventListener('click',function(e){e.stopPropagation();document.querySelectorAll('.dp-popup.is-open').forEach(function(p){if(p!==popup)p.classList.remove('is-open');});if(popup.classList.contains('is-open')){close();}else{view='day';pos();render();popup.classList.add('is-open');}});
        document.addEventListener('click',function(){if(popup.classList.contains('is-open'))close();});
        window.addEventListener('scroll',function(){if(popup.classList.contains('is-open'))pos();},true);
    }
    function autoSubmitIfValid() {
        var fromVal=document.getElementById('filterDateFromIso').value;
        var toVal=document.getElementById('filterDateToIso').value;
        // Submit if at least one date is set; if both set, from must not be after to
        if (!fromVal && !toVal) return;
        if (fromVal && toVal && fromVal > toVal) return;
        document.getElementById('filterForm').submit();
    }
    makePicker('filterDateFrom','filterDateFromIso',autoSubmitIfValid);
    makePicker('filterDateTo','filterDateToIso',autoSubmitIfValid);
})();

// ── Init ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    bindViewAll();
    applyColPrefs(loadColPrefs());
    requestAnimationFrame(function() { requestAnimationFrame(function() { requestAnimationFrame(function() {
        adjSetHeight();
        adjRender();
    }); }); });
});
window.addEventListener('resize', function() { adjSetHeight(); setTimeout(adjSyncRows, 50); });
</script>

<?php layoutClose();

// ─────────────────────────────────────────────────────────────────────────────
// VIEW
// ─────────────────────────────────────────────────────────────────────────────
elseif ($action === 'view' && !empty($_GET['id'])):
    $id  = (int)$_GET['id'];
    $adj = $pdo->prepare("
        SELECT a.*, u.name AS created_by_name
        FROM inventory_adjustments a
        LEFT JOIN users u ON u.id = a.created_by
        WHERE a.id = ?
    ");
    $adj->execute([$id]);
    $adj = $adj->fetch();
    if (!$adj) { flash('error', 'Adjustment not found.'); redirect('inventory_adjustment.php'); }

    $items = loadAdjItems($pdo, $id);
    $attachments = [];
    try {
        $aStmt = $pdo->prepare("SELECT * FROM adj_attachments WHERE adj_id = ? ORDER BY uploaded_at");
        $aStmt->execute([$id]);
        $attachments = $aStmt->fetchAll();
    } catch (Exception $e) {}

    layoutOpen('Adjustment — ' . e($adj['adj_no']), count($items) . ' product' . (count($items) !== 1 ? 's' : ''));
?>
<script>
document.getElementById('pageActions').innerHTML =
    '<a href="inventory_adjustment.php" class="<?= t('btn_base').' '.t('btn_ghost') ?> h-9">← Back</a>' +
    ' <a href="inventory_adjustment.php?action=edit&id=<?= $id ?>" class="<?= t('btn_base').' '.t('btn_ghost') ?> h-9 ml-2">Edit</a>';
</script>

<div class="bg-white rounded-xl border border-slate-200">

    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <div>
            <div class="text-base font-bold text-slate-800"><?= e($adj['adj_no']) ?></div>
            <div class="text-xs text-slate-400 mt-0.5"><?= date('d M Y, H:i', strtotime($adj['created_at'])) ?></div>
        </div>
        <div class="text-xs text-slate-500"><?= e($adj['created_by_name'] ?? '—') ?></div>
    </div>

    <!-- Items table -->
    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Products</h3>
            <p class="text-xs text-slate-400 leading-relaxed">All products adjusted in this entry.</p>
        </div>
        <div class="p-4">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100">
                        <th class="<?= t('th') ?> text-left">Product</th>
                        <th class="<?= t('th') ?> text-right">Before</th>
                        <th class="<?= t('th') ?> text-right">Change</th>
                        <th class="<?= t('th') ?> text-right">After</th>
                        <th class="<?= t('th') ?>">Ledger</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item):
                    $chg   = (float)$item['qty_change'];
                    $isIn  = $chg > 0;
                    $chgCls = $isIn ? 'text-green-700' : 'text-red-600';
                ?>
                <tr class="border-b border-slate-50 last:border-0">
                    <td class="<?= t('td') ?>">
                        <div class="font-medium text-slate-800"><?= e($item['product_name']) ?></div>
                        <?php if ($item['sku']): ?><div class="text-xs text-slate-400"><?= e($item['sku']) ?></div><?php endif; ?>
                    </td>
                    <td class="<?= t('td') ?> text-right font-mono text-slate-600 whitespace-nowrap">
                        <?= fmtQtyPhp((float)$item['qty_before']) ?>
                        <span class="text-slate-400 text-xs ml-1"><?= e($item['base_unit_label']) ?></span>
                    </td>
                    <td class="<?= t('td') ?> text-right font-semibold whitespace-nowrap <?= $chgCls ?>">
                        <?= $isIn ? '+' : '' ?><?= fmtQtyPhp($chg) ?>
                    </td>
                    <td class="<?= t('td') ?> text-right font-mono text-slate-700 whitespace-nowrap">
                        <?= fmtQtyPhp((float)$item['qty_after']) ?>
                        <span class="text-slate-400 text-xs ml-1"><?= e($item['base_unit_label']) ?></span>
                    </td>
                    <td class="<?= t('td') ?>">
                        <a href="inventory.php?action=ledger&product_id=<?= $item['product_id'] ?>"
                           class="text-xs text-indigo-600 hover:underline">View →</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100"><h3 class="text-sm font-semibold text-slate-800 mb-1">Details</h3></div>
        <div class="p-6 grid grid-cols-2 gap-4">
            <div>
                <div class="text-xs text-slate-400 mb-0.5">Reference</div>
                <div class="text-sm text-slate-700"><?= e($adj['reference']) ?: '—' ?></div>
            </div>
            <?php if ($adj['notes']): ?>
            <div class="col-span-2">
                <div class="text-xs text-slate-400 mb-0.5">Notes</div>
                <div class="text-sm text-slate-700 whitespace-pre-wrap"><?= e($adj['notes']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($attachments)): ?>
    <div class="border-t border-slate-100"></div>
    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100"><h3 class="text-sm font-semibold text-slate-800 mb-1">Attachments</h3></div>
        <div class="p-6 space-y-2">
            <?php foreach ($attachments as $att):
                $attUrl  = APP_URL . '/storage/attachments/' . rawurlencode($att['stored_name']);
                $ext     = strtoupper(pathinfo($att['original_name'], PATHINFO_EXTENSION));
                $canView = in_array($ext, ['PDF','JPG','JPEG','PNG']);
            ?>
            <div class="flex items-center gap-3 px-3 py-2.5 bg-slate-50 rounded-lg border border-slate-200 group">
                <div class="w-9 h-9 rounded-lg bg-indigo-100 flex items-center justify-center shrink-0">
                    <span class="text-[9px] font-bold text-indigo-600"><?= e($ext) ?></span>
                </div>
                <div class="flex-1 min-w-0">
                    <?php if ($canView): ?>
                    <a href="<?= e($attUrl) ?>" target="_blank" class="text-xs font-medium text-indigo-600 hover:underline truncate block"><?= e($att['original_name']) ?></a>
                    <?php else: ?>
                    <div class="text-xs font-medium text-slate-700 truncate"><?= e($att['original_name']) ?></div>
                    <?php endif; ?>
                    <div class="text-[10px] text-slate-400"><?= date('d M Y', strtotime($att['uploaded_at'])) ?></div>
                </div>
                <?php if ($canView): ?>
                <a href="<?= e($attUrl) ?>" target="_blank" class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-indigo-600 hover:bg-indigo-50 transition-colors opacity-0 group-hover:opacity-100" title="Open">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php layoutClose();

// ─────────────────────────────────────────────────────────────────────────────
// EDIT
// ─────────────────────────────────────────────────────────────────────────────
elseif ($action === 'edit' && !empty($_GET['id'])):
    $id  = (int)$_GET['id'];
    $adj = $pdo->prepare("SELECT a.*, u.name AS created_by_name FROM inventory_adjustments a LEFT JOIN users u ON u.id = a.created_by WHERE a.id = ?");
    $adj->execute([$id]); $adj = $adj->fetch();
    if (!$adj) { flash('error', 'Adjustment not found.'); redirect('inventory_adjustment.php'); }

    $items = loadAdjItems($pdo, $id);
    $attachments = [];
    try { $aStmt = $pdo->prepare("SELECT * FROM adj_attachments WHERE adj_id = ? ORDER BY uploaded_at"); $aStmt->execute([$id]); $attachments = $aStmt->fetchAll(); } catch (Exception $e) {}

    $products = $pdo->query("SELECT p.id, p.name, p.sku, p.base_unit_label, COALESCE(s.qty_on_hand,0) AS qty_on_hand FROM products p LEFT JOIN product_stock_summary s ON s.product_id=p.id WHERE p.track_inventory=1 ORDER BY p.name")->fetchAll();

    layoutOpen('Edit Adjustment — ' . e($adj['adj_no']), e($adj['adj_no']));
?>
<script>document.getElementById('pageActions').innerHTML='<a href="inventory_adjustment.php?action=view&id=<?= $id ?>" class="<?= t('btn_base').' '.t('btn_ghost') ?> h-9">← Back</a>';</script>

<form id="adjForm" enctype="multipart/form-data">
<input type="hidden" name="action" value="update_adjustment">
<input type="hidden" name="id" value="<?= $id ?>">

<div class="bg-white rounded-xl border border-slate-200 mb-24">

    <!-- General Info -->
    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">General Info</h3>
            <p class="text-xs text-slate-400 leading-relaxed">Adjustment number is locked.</p>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="<?= t('label') ?>">Adjustment Number</label>
                    <div class="<?= t('input') ?> font-mono text-slate-700 bg-slate-50 flex items-center justify-between">
                        <span><?= e($adj['adj_no']) ?></span>
                        <svg class="w-4 h-4 text-slate-300 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    </div>
                </div>
                <div>
                    <label class="<?= t('label') ?>">Reference</label>
                    <input type="text" name="reference" value="<?= e($adj['reference']) ?>" placeholder="e.g. Stock Count June 2026" class="<?= t('input') ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- Line Items -->
    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Products &amp; Quantities</h3>
            <p class="text-xs text-slate-400 leading-relaxed">
                Search and add products.<br>
                Use <code class="bg-slate-100 px-1 rounded">-50</code> to reduce,
                <code class="bg-slate-100 px-1 rounded">+50</code> to add.
            </p>
        </div>
        <div class="p-4">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-100">
                    <tr>
                        <th class="px-3 py-2.5 text-left text-[10px] font-semibold text-slate-700 uppercase tracking-wide">Product</th>
                        <th class="px-2 py-2.5 text-right text-[10px] font-semibold text-slate-700 uppercase tracking-wide" style="width:110px">Stock Now</th>
                        <th class="px-2 py-2.5 text-right text-[10px] font-semibold text-slate-700 uppercase tracking-wide" style="width:130px">Change</th>
                        <th class="px-2 py-2.5 text-right text-[10px] font-semibold text-slate-700 uppercase tracking-wide" style="width:110px">After</th>
                        <th class="px-2 py-2.5" style="width:36px"></th>
                    </tr>
                </thead>
                <tbody id="itemsBody">
                <?php foreach ($items as $idx => $item): ?>
                <tr class="item-row border-b border-slate-50 hover:bg-slate-50/30 transition-colors">
                    <td class="px-3 py-2">
                        <div class="relative">
                            <input type="text" value="<?= e($item['product_name']) ?>"
                                   placeholder="Product" autocomplete="off"
                                   class="w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-slate-800 focus:outline-none focus:border-indigo-500 transition item-prod-input"
                                   onfocus="adjDdOpen(this)" oninput="adjDdFilter(this)" onblur="adjDdBlur(this)" onkeydown="adjDdKey(event,this)">
                            <div class="item-dd-panel fixed z-[9996] bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden" style="display:none">
                                <ul class="item-dd-list max-h-52 overflow-y-auto py-1"></ul>
                            </div>
                        </div>
                        <input type="hidden" name="items[<?= $idx ?>][product_id]" class="item-prod-id" value="<?= $item['product_id'] ?>">
                        <input type="hidden" name="items[<?= $idx ?>][item_id]" value="<?= $item['id'] ?>">
                    </td>
                    <td class="px-2 py-2 text-right font-mono text-slate-500 text-xs whitespace-nowrap item-stock-now">
                        <?= fmtQtyPhp((float)$item['current_qty']) ?> <span class="text-slate-400"><?= e($item['base_unit_label']) ?></span>
                    </td>
                    <td class="px-2 py-2 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <input type="number" name="items[<?= $idx ?>][qty_change]"
                                   value="<?= fmtQtyPhp((float)$item['qty_change']) ?>"
                                   step="0.0001" placeholder="+50 or -50"
                                   data-current="<?= (float)$item['current_qty'] ?>"
                                   data-unit="<?= e($item['base_unit_label']) ?>"
                                   class="item-qty-input w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right font-mono focus:outline-none focus:border-indigo-500 transition">
                        </div>
                    </td>
                    <td class="px-2 py-2 text-right font-mono text-xs item-after-preview whitespace-nowrap"
                        data-current="<?= (float)$item['current_qty'] ?>" data-unit="<?= e($item['base_unit_label']) ?>">
                        <?php $chg=(float)$item['qty_change']; $after=(float)$item['qty_after']; ?>
                        <span class="<?= $chg>=0?'text-green-700':'text-red-600' ?>"><?= fmtQtyPhp($after) ?></span>
                        <span class="text-slate-400 ml-1"><?= e($item['base_unit_label']) ?></span>
                    </td>
                    <td class="px-2 py-2 text-center">
                        <button type="button" onclick="adjRemoveRow(this)" class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors mx-auto">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="px-3 py-3 border-t border-slate-50">
                <button type="button" onclick="adjAddRow()" class="flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-800 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                    Add Product
                </button>
            </div>
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- Notes -->
    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100"><h3 class="text-sm font-semibold text-slate-800 mb-1">Notes</h3></div>
        <div class="p-6">
            <textarea name="notes" rows="3" placeholder="Reason for adjustment…" class="<?= t('input') ?> h-auto py-2 resize-none"><?= e($adj['notes']) ?></textarea>
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- Attachments -->
    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Attachments</h3>
            <p class="text-xs text-slate-400 leading-relaxed">PDF, JPG, PNG, DOC up to 10MB each.</p>
        </div>
        <div class="p-6">
            <div id="editDropZone" onclick="document.getElementById('editFileInput').click()"
                 ondragover="adjDragOver(event,'editDropZone')" ondragleave="adjDragLeave(event,'editDropZone')" ondrop="adjFileDrop(event,'editDropZone','editFileInput','editFileList')"
                 class="border-2 border-dashed border-slate-200 rounded-xl p-8 text-center cursor-pointer hover:border-indigo-300 hover:bg-indigo-50/30 transition-colors">
                <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center mx-auto mb-3">
                    <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <p class="text-sm font-medium text-slate-600 mb-1">Drop files to upload</p>
                <p class="text-xs text-slate-400">or <span class="text-indigo-500 font-medium">click to browse</span></p>
            </div>
            <input type="file" id="editFileInput" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden" onchange="adjHandleFiles(this.files,'editFileList','editFileInput')">
            <div id="editFileList" class="mt-3 space-y-2">
                <?php foreach ($attachments as $att):
                    $attUrl=APP_URL.'/storage/attachments/'.rawurlencode($att['stored_name']);
                    $ext=strtoupper(pathinfo($att['original_name'],PATHINFO_EXTENSION));
                    $canView=in_array($ext,['PDF','JPG','JPEG','PNG']);
                ?>
                <div class="flex items-center gap-3 px-3 py-2.5 bg-slate-50 rounded-lg border border-slate-200 group">
                    <div class="w-9 h-9 rounded-lg bg-indigo-100 flex items-center justify-center shrink-0"><span class="text-[9px] font-bold text-indigo-600"><?= e($ext) ?></span></div>
                    <div class="flex-1 min-w-0">
                        <?php if($canView):?><a href="<?=e($attUrl)?>" target="_blank" class="text-xs font-medium text-indigo-600 hover:underline truncate block"><?=e($att['original_name'])?></a><?php else:?><div class="text-xs font-medium text-slate-700 truncate"><?=e($att['original_name'])?></div><?php endif;?>
                        <div class="text-[10px] text-slate-400"><?=date('d M Y',strtotime($att['uploaded_at']))?></div>
                    </div>
                    <a href="delete_adj_attachment.php?id=<?=$att['id']?>&adj=<?=$id?>" onclick="return confirm('Remove?')" class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors opacity-0 group-hover:opacity-100">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>
</form>

<div class="fixed bottom-0 right-0 bg-white border-t border-slate-200 z-20 flex items-center justify-end gap-3 px-8 py-3" style="left:256px">
    <a href="inventory_adjustment.php?action=view&id=<?= $id ?>" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Cancel</a>
    <button type="button" id="adjSaveBtn" onclick="submitAdjForm()" class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-9">Save Changes</button>
</div>

<script>
var ADJ_PRODUCTS = <?= json_encode(array_values($products), JSON_HEX_TAG|JSON_HEX_QUOT) ?>;
var _adjRowIdx   = <?= count($items) ?>;
// Init existing rows with their product data
(function(){
    var rows = document.querySelectorAll('#itemsBody .item-row');
    rows.forEach(function(tr, i) {
        var inp = tr.querySelector('.item-prod-input');
        var pid = parseInt(tr.querySelector('.item-prod-id').value);
        var prod = ADJ_PRODUCTS.find(function(p){ return p.id === pid; });
        if (inp && prod) inp._itemSelected = prod;
    });
})();
</script>
<?= adjFormScript(false) ?>
<?php layoutClose();

// ─────────────────────────────────────────────────────────────────────────────
// NEW
// ─────────────────────────────────────────────────────────────────────────────
elseif ($action === 'new'):

    $products = $pdo->query("SELECT p.id, p.name, p.sku, p.base_unit_label, COALESCE(s.qty_on_hand,0) AS qty_on_hand FROM products p LEFT JOIN product_stock_summary s ON s.product_id=p.id WHERE p.track_inventory=1 ORDER BY p.name")->fetchAll();

    $adjFormats = $pdo->query("SELECT * FROM number_formats WHERE doc_type = 'stock_adjustment' ORDER BY id")->fetchAll();
    $defaultFormat   = $adjFormats[0] ?? null;
    $defaultFormatId = $defaultFormat ? (int)$defaultFormat['id'] : 0;
    $nextNo = '';
    if ($defaultFormat) {
        $now = new DateTime(); $year = (int)$now->format('Y');
        $seqKey = substr(preg_replace('/\[(YYYY|YY|MM|DD)\]/', '', $defaultFormat['format']), 0, 50);
        $pdo->prepare("INSERT IGNORE INTO invoice_sequences (prefix, year, next_no) VALUES (?, ?, 1)")->execute([$seqKey, $year]);
        $stmt = $pdo->prepare("SELECT next_no FROM invoice_sequences WHERE prefix=? AND year=?"); $stmt->execute([$seqKey, $year]); $seq = (int)$stmt->fetchColumn();
        $check = $pdo->prepare("SELECT COUNT(*) FROM inventory_adjustments WHERE adj_no=?");
        for ($i=0; $i<10000; $i++, $seq++) {
            $out = str_replace(['[YYYY]','[YY]','[MM]','[DD]'],[$now->format('Y'),$now->format('y'),$now->format('m'),$now->format('d')],$defaultFormat['format']);
            for ($n=2;$n<=8;$n++) $out=str_replace("[{$n}DIGIT]",str_pad((string)$seq,$n,'0',STR_PAD_LEFT),$out);
            $check->execute([$out]); if(!(int)$check->fetchColumn()){$nextNo=$out;break;}
        }
    }

    layoutOpen('New Adjustment', 'Record a stock count correction for multiple products.');
?>
<script>document.getElementById('pageActions').innerHTML='<a href="inventory_adjustment.php" class="<?= t('btn_base').' '.t('btn_ghost') ?> h-9">Cancel</a>';</script>

<form id="adjForm" enctype="multipart/form-data">
<input type="hidden" name="action" value="create_adjustment">
<input type="hidden" name="adj_format_id" id="adjFormatId" value="<?= $defaultFormatId ?>">

<div class="bg-white rounded-xl border border-slate-200 mb-24">

    <!-- General Info -->
    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">General Info</h3>
            <p class="text-xs text-slate-400 leading-relaxed">Adjustment number and reference.</p>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="<?= t('label') ?>">Adjustment Number</label>
                    <?php if (empty($adjFormats)): ?>
                    <div class="<?= t('input') ?> text-slate-400">No format configured</div>
                    <p class="text-xs text-amber-600 mt-1">Add a <strong>Stock Adjustment</strong> format in <a href="number_formats.php" class="underline">Number Formats</a>.</p>
                    <input type="hidden" name="adj_no" id="adjNoValue" value="">
                    <?php elseif (count($adjFormats) === 1): ?>
                    <div class="<?= t('input') ?> font-mono text-slate-700 bg-slate-50 flex items-center justify-between">
                        <span id="adjNoDisplay"><?= e($nextNo) ?></span>
                        <svg class="w-4 h-4 text-slate-300 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                    </div>
                    <input type="hidden" name="adj_no" id="adjNoValue" value="<?= e($nextNo) ?>">
                    <?php else: ?>
                    <div id="adjNoDd" class="relative">
                        <button type="button" id="adjNoBtn" onclick="adjNoDdToggle()" style="outline:none" class="<?= t('input') ?> text-left flex items-center justify-between font-mono">
                            <span id="adjNoDisplay"><?= e($nextNo) ?></span>
                            <svg id="adjNoChevron" class="w-4 h-4 text-slate-400 shrink-0 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div id="adjNoDdPanel" style="display:none" class="absolute z-50 left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">
                            <ul class="py-1">
                                <?php foreach ($adjFormats as $f): ?>
                                <li><button type="button" onclick="adjNoDdSelect(<?= $f['id'] ?>)" id="adjNoOpt_<?= $f['id'] ?>" class="w-full text-left px-4 py-2.5 text-sm font-mono transition-colors <?= $f['id']==$defaultFormatId?'bg-indigo-50 text-indigo-700 font-semibold':'text-slate-800 hover:bg-slate-50' ?>"><?= e($f['format']) ?></button></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <input type="hidden" name="adj_no" id="adjNoValue" value="<?= e($nextNo) ?>">
                    </div>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="<?= t('label') ?>">Reference</label>
                    <input type="text" name="reference" placeholder="e.g. Stock Count June 2026" class="<?= t('input') ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- Line Items -->
    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Products &amp; Quantities</h3>
            <p class="text-xs text-slate-400 leading-relaxed">
                Search and add products.<br>
                Use <code class="bg-slate-100 px-1 rounded">-50</code> to reduce,
                <code class="bg-slate-100 px-1 rounded">+50</code> to add.
            </p>
        </div>
        <div class="p-4">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-100">
                    <tr>
                        <th class="px-3 py-2.5 text-left text-[10px] font-semibold text-slate-700 uppercase tracking-wide">Product</th>
                        <th class="px-2 py-2.5 text-right text-[10px] font-semibold text-slate-700 uppercase tracking-wide" style="width:110px">Stock Now</th>
                        <th class="px-2 py-2.5 text-right text-[10px] font-semibold text-slate-700 uppercase tracking-wide" style="width:130px">Change</th>
                        <th class="px-2 py-2.5 text-right text-[10px] font-semibold text-slate-700 uppercase tracking-wide" style="width:110px">After</th>
                        <th class="px-2 py-2.5" style="width:36px"></th>
                    </tr>
                </thead>
                <tbody id="itemsBody">
                    <!-- Rows added by JS -->
                </tbody>
            </table>
            <div class="px-3 py-3 border-t border-slate-50">
                <button type="button" onclick="adjAddRow()" class="flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-800 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                    Add Product
                </button>
            </div>
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- Notes -->
    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100"><h3 class="text-sm font-semibold text-slate-800 mb-1">Notes</h3></div>
        <div class="p-6">
            <textarea name="notes" rows="3" placeholder="e.g. Physical count discrepancy." class="<?= t('input') ?> h-auto py-2 resize-none"></textarea>
        </div>
    </div>

    <div class="border-t border-slate-100"></div>

    <!-- Attachments -->
    <div class="grid grid-cols-[200px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Attachments</h3>
            <p class="text-xs text-slate-400 leading-relaxed">PDF, JPG, PNG, DOC up to 10MB each.</p>
        </div>
        <div class="p-6">
            <div id="newDropZone" onclick="document.getElementById('newFileInput').click()"
                 ondragover="adjDragOver(event,'newDropZone')" ondragleave="adjDragLeave(event,'newDropZone')" ondrop="adjFileDrop(event,'newDropZone','newFileInput','newFileList')"
                 class="border-2 border-dashed border-slate-200 rounded-xl p-8 text-center cursor-pointer hover:border-indigo-300 hover:bg-indigo-50/30 transition-colors">
                <div class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center mx-auto mb-3">
                    <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <p class="text-sm font-medium text-slate-600 mb-1">Drop files to upload</p>
                <p class="text-xs text-slate-400">or <span class="text-indigo-500 font-medium">click to browse</span></p>
            </div>
            <input type="file" id="newFileInput" name="attachments[]" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden" onchange="adjHandleFiles(this.files,'newFileList','newFileInput')">
            <div id="newFileList" class="mt-3 space-y-2"></div>
        </div>
    </div>

</div>

<div class="fixed bottom-0 right-0 bg-white border-t border-slate-200 z-20 flex items-center justify-end gap-3 px-8 py-3" style="left:256px">
    <a href="inventory_adjustment.php" class="<?= t('btn_base') ?> <?= t('btn_ghost') ?> h-9">Cancel</a>
    <button type="button" id="adjSaveBtn" onclick="submitAdjForm()" class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-9">Save Adjustment</button>
</div>
</form>

<script>
var ADJ_PRODUCTS = <?= json_encode(array_values($products), JSON_HEX_TAG|JSON_HEX_QUOT) ?>;
var _adjRowIdx   = 0;
// Adj number dropdown
var _adjNoDdOpen=false, _adjNoDdActiveId=<?= $defaultFormatId ?: 0 ?>;
function adjNoDdToggle(){var p=document.getElementById('adjNoDdPanel'),c=document.getElementById('adjNoChevron');if(!p)return;_adjNoDdOpen=!_adjNoDdOpen;p.style.display=_adjNoDdOpen?'block':'none';if(c)c.style.transform=_adjNoDdOpen?'rotate(180deg)':'';}
function adjNoDdSelect(id){_adjNoDdActiveId=id;document.querySelectorAll('[id^="adjNoOpt_"]').forEach(function(b){b.className='w-full text-left px-4 py-2.5 text-sm font-mono transition-colors text-slate-800 hover:bg-slate-50';});var a=document.getElementById('adjNoOpt_'+id);if(a)a.className='w-full text-left px-4 py-2.5 text-sm font-mono transition-colors bg-indigo-50 text-indigo-700 font-semibold';var d=document.getElementById('adjNoDisplay'),h=document.getElementById('adjNoValue'),f=document.getElementById('adjFormatId');if(d)d.textContent='Loading…';if(f)f.value=id;adjNoDdToggle();fetch('adj_number_next.php?format_id='+id).then(function(r){return r.json();}).then(function(r){if(r.success){if(d)d.textContent=r.number;if(h)h.value=r.number;}else{if(d)d.textContent='—';showToast(r.message||'Failed.',false);}});}
document.addEventListener('click',function(e){if(!_adjNoDdOpen)return;var dd=document.getElementById('adjNoDd');if(dd&&!dd.contains(e.target)){_adjNoDdOpen=false;var p=document.getElementById('adjNoDdPanel'),c=document.getElementById('adjNoChevron');if(p)p.style.display='none';if(c)c.style.transform='';}});
</script>
<?= adjFormScript(true) ?>
<script>
// Add first row automatically for new form (no focus)
adjAddRow(true);
</script>
<?php layoutClose();
endif;

// ── Shared JS for both edit and new ──────────────────────────────────────────
function adjFormScript(bool $isNew): string {
    return <<<'JS'
<script>
function fmtQtyJs(n){if(isNaN(n))return'0';var s=parseFloat(n).toFixed(5);return s.replace(/\.?0+$/,'');}
function adjEscH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── Product dropdown (invoice.php style) ─────────────────────────────────────
var _adjDdActive=null,_adjDdIdx=-1,_adjDdTimer=null;
function adjDdGetPanel(inp){return inp.parentElement.querySelector('.item-dd-panel');}
function adjDdGetList(inp){return inp.parentElement.querySelector('.item-dd-list');}
function adjDdPos(inp){var p=adjDdGetPanel(inp);if(!p)return;var r=inp.getBoundingClientRect();p.style.top=(r.bottom+2)+'px';p.style.left=r.left+'px';p.style.width=Math.max(r.width,300)+'px';}

function adjDdRender(inp,q){
    var list=adjDdGetList(inp),panel=adjDdGetPanel(inp);
    q=(q||'').trim().toLowerCase();
    var filtered=q?ADJ_PRODUCTS.filter(function(p){return p.name.toLowerCase().includes(q)||(p.sku&&p.sku.toLowerCase().includes(q));}).slice(0,20):ADJ_PRODUCTS.slice(0,20);
    var sel=inp._itemSelected;
    var html=filtered.length?filtered.map(function(p,i){
        var active=sel&&sel.id===p.id;
        return '<li data-idx="'+i+'" style="background:'+(active?'#eef2ff':'')+'" class="flex items-center justify-between px-3 py-2 cursor-pointer hover:bg-slate-50 transition-colors select-none">'+
            '<div class="min-w-0"><div class="text-sm font-medium text-slate-800 truncate">'+adjEscH(p.name)+'</div>'+(p.sku?'<div class="text-xs text-slate-400">'+adjEscH(p.sku)+'</div>':'')+
            '</div><div class="text-xs font-mono text-slate-500 ml-3 shrink-0">'+fmtQtyJs(p.qty_on_hand)+' '+adjEscH(p.base_unit_label)+'</div></li>';
    }).join(''):'<li class="px-3 py-2 text-sm text-slate-400 italic select-none">No products found</li>';
    list.innerHTML=html;
    if(filtered.length){
        list.querySelectorAll('li[data-idx]').forEach(function(li,i){
            li.addEventListener('mouseover',function(){list.querySelectorAll('li[data-idx]').forEach(function(x){x.style.background='';});li.style.background='#f8fafc';_adjDdIdx=i;});
            li.addEventListener('mouseleave',function(){li.style.background=(inp._itemSelected&&inp._itemSelected.id===filtered[i].id)?'#eef2ff':'';_adjDdIdx=-1;});
            li.addEventListener('mousedown',function(e){e.preventDefault();adjDdSelect(inp,filtered[i]);});
        });
    }
    _adjDdIdx=-1;adjDdPos(inp);panel.style.display='block';
}

function adjDdSelect(inp,prod){
    inp._itemSelected=prod;
    inp.value=prod.name;
    inp.placeholder='Product';
    // Fill hidden product_id
    var td=inp.closest('td');
    if(td){var hidden=td.querySelector('.item-prod-id');if(hidden)hidden.value=prod.id;}
    // Fill stock now cell
    var tr=inp.closest('tr');
    if(tr){
        var stockCell=tr.querySelector('.item-stock-now');
        if(stockCell)stockCell.innerHTML=fmtQtyJs(prod.qty_on_hand)+' <span class="text-slate-400">'+adjEscH(prod.base_unit_label)+'</span>';
        var qtyInp=tr.querySelector('.item-qty-input');
        if(qtyInp){qtyInp.dataset.current=prod.qty_on_hand;qtyInp.dataset.unit=prod.base_unit_label;qtyInp.value='';qtyInp.focus();}
        var afterCell=tr.querySelector('.item-after-preview');
        if(afterCell){afterCell.dataset.current=prod.qty_on_hand;afterCell.dataset.unit=prod.base_unit_label;afterCell.innerHTML='<span class="text-slate-400">—</span>';}
    }
    adjDdClose(inp);
}
function adjDdOpen(inp){
    if(_adjDdTimer){clearTimeout(_adjDdTimer);_adjDdTimer=null;}
    if(_adjDdActive&&_adjDdActive!==inp)adjDdClose(_adjDdActive);
    _adjDdActive=inp;
    if(inp._itemSelected){inp.placeholder=inp._itemSelected.name;inp.value='';}
    adjDdRender(inp,'');
}
function adjDdFilter(inp){if(_adjDdTimer){clearTimeout(_adjDdTimer);_adjDdTimer=null;}_adjDdActive=inp;adjDdRender(inp,inp.value);}
function adjDdClose(inp){var p=adjDdGetPanel(inp);if(p)p.style.display='none';_adjDdIdx=-1;if(inp===_adjDdActive)_adjDdActive=null;}
function adjDdBlur(inp){_adjDdTimer=setTimeout(function(){if(inp._itemSelected){inp.value=inp._itemSelected.name;}else{inp.value='';}inp.placeholder='Product';adjDdClose(inp);},160);}
function adjDdKey(e,inp){
    var panel=adjDdGetPanel(inp),isOpen=panel&&panel.style.display!=='none';
    if(!isOpen){if(e.key==='ArrowDown'||e.key==='Enter'){e.preventDefault();adjDdOpen(inp);}return;}
    var items=panel.querySelectorAll('li[data-idx]');
    if(e.key==='ArrowDown'){e.preventDefault();_adjDdIdx=Math.min(_adjDdIdx+1,items.length-1);items.forEach(function(li,i){li.style.background=i===_adjDdIdx?'#eef2ff':'';});if(items[_adjDdIdx])items[_adjDdIdx].scrollIntoView({block:'nearest'});}
    else if(e.key==='ArrowUp'){e.preventDefault();_adjDdIdx=Math.max(_adjDdIdx-1,0);items.forEach(function(li,i){li.style.background=i===_adjDdIdx?'#eef2ff':'';});if(items[_adjDdIdx])items[_adjDdIdx].scrollIntoView({block:'nearest'});}
    else if(e.key==='Enter'){e.preventDefault();if(_adjDdIdx>=0&&items[_adjDdIdx])items[_adjDdIdx].dispatchEvent(new MouseEvent('mousedown'));}
    else if(e.key==='Escape'){if(inp._itemSelected)inp.value=inp._itemSelected.name;inp.placeholder='Product';adjDdClose(inp);}
}
(function(){var s=document.querySelector('main')||window;s.addEventListener('scroll',function(){if(_adjDdActive)adjDdPos(_adjDdActive);},{passive:true});})();

// ── Live "after" preview ─────────────────────────────────────────────────────
document.addEventListener('input',function(e){
    if(!e.target.classList.contains('item-qty-input'))return;
    var tr=e.target.closest('tr'),prev=tr&&tr.querySelector('.item-after-preview');
    if(!prev)return;
    var cur=parseFloat(e.target.dataset.current)||0,delta=parseFloat(e.target.value),unit=e.target.dataset.unit||'';
    if(isNaN(delta)){prev.innerHTML='<span class="text-slate-400">—</span>';return;}
    var after=cur+delta,cls=delta>=0?'text-green-700':'text-red-600';
    prev.innerHTML='<span class="'+cls+'">'+fmtQtyJs(after)+'</span> <span class="text-slate-400 ml-1">'+adjEscH(unit)+'</span>';
});

// ── Add / Remove rows ────────────────────────────────────────────────────────
function adjAddRow(noFocus){
    var tbody=document.getElementById('itemsBody'),idx=_adjRowIdx++;
    var tr=document.createElement('tr');
    tr.className='item-row border-b border-slate-50 hover:bg-slate-50/30 transition-colors';
    tr.innerHTML=
        '<td class="px-3 py-2"><div class="relative">'+
            '<input type="text" placeholder="Product" autocomplete="off" class="w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-slate-800 focus:outline-none focus:border-indigo-500 transition item-prod-input" onfocus="adjDdOpen(this)" oninput="adjDdFilter(this)" onblur="adjDdBlur(this)" onkeydown="adjDdKey(event,this)">'+
            '<div class="item-dd-panel fixed z-[9996] bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden" style="display:none"><ul class="item-dd-list max-h-52 overflow-y-auto py-1"></ul></div>'+
        '</div><input type="hidden" name="items['+idx+'][product_id]" class="item-prod-id" value=""><input type="hidden" name="items['+idx+'][item_id]" value=""></td>'+
        '<td class="px-2 py-2 text-right font-mono text-slate-500 text-xs whitespace-nowrap item-stock-now">—</td>'+
        '<td class="px-2 py-2 text-right"><input type="number" name="items['+idx+'][qty_change]" step="0.0001" placeholder="+50 or -50" data-current="0" data-unit="" class="item-qty-input w-full h-8 border border-slate-200 rounded-lg px-2.5 text-sm text-right font-mono focus:outline-none focus:border-indigo-500 transition"></td>'+
        '<td class="px-2 py-2 text-right font-mono text-xs item-after-preview whitespace-nowrap" data-current="0" data-unit=""><span class="text-slate-400">—</span></td>'+
        '<td class="px-2 py-2 text-center"><button type="button" onclick="adjRemoveRow(this)" class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors mx-auto"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg></button></td>';
    tbody.appendChild(tr);
    if (!noFocus) tr.querySelector('.item-prod-input').focus();
}

function adjRemoveRow(btn){
    var rows=document.querySelectorAll('#itemsBody .item-row');
    if(rows.length<=1){showToast('At least one product is required.',false);return;}
    btn.closest('tr').remove();
    // Renumber hidden name indices
    document.querySelectorAll('#itemsBody .item-row').forEach(function(tr,i){
        tr.querySelectorAll('[name^="items["]').forEach(function(el){el.name=el.name.replace(/items\[\d+\]/,'items['+i+']');});
    });
    _adjRowIdx=document.querySelectorAll('#itemsBody .item-row').length;
}

// ── File attachments ─────────────────────────────────────────────────────────
var _adjFileDts={};
function adjDragOver(e,zoneId){e.preventDefault();document.getElementById(zoneId).classList.add('border-indigo-400','bg-indigo-50/40');}
function adjDragLeave(e,zoneId){document.getElementById(zoneId).classList.remove('border-indigo-400','bg-indigo-50/40');}
function adjFileDrop(e,zoneId,inputId,listId){e.preventDefault();adjDragLeave(e,zoneId);adjHandleFiles(e.dataTransfer.files,listId,inputId);}
function adjHandleFiles(files,listId,inputId){
    if(!_adjFileDts[inputId])_adjFileDts[inputId]=new DataTransfer();
    var dt=_adjFileDts[inputId],list=document.getElementById(listId);
    Array.from(files).forEach(function(file){
        dt.items.add(file);
        var ext=file.name.split('.').pop().toUpperCase(),size=file.size<1048576?Math.round(file.size/1024)+'KB':(file.size/1048576).toFixed(1)+'MB';
        var objUrl=URL.createObjectURL(file),canView=['PDF','JPG','JPEG','PNG'].includes(ext);
        var div=document.createElement('div');div.className='flex items-center gap-3 px-3 py-2.5 bg-slate-50 rounded-lg border border-slate-200 group';
        div.innerHTML='<div class="w-9 h-9 rounded-lg bg-indigo-100 flex items-center justify-center shrink-0"><span class="text-[9px] font-bold text-indigo-600">'+ext+'</span></div>'+
            '<div class="flex-1 min-w-0">'+(canView?'<a href="'+objUrl+'" target="_blank" class="text-xs font-medium text-indigo-600 hover:underline truncate block">'+file.name+'</a>':'<div class="text-xs font-medium text-slate-700 truncate">'+file.name+'</div>')+'<div class="text-[10px] text-slate-400">'+size+'</div></div>'+
            '<button type="button" title="Remove" class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors opacity-0 group-hover:opacity-100"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg></button>';
        list.appendChild(div);
        (function(f,el,inp){div.querySelector('button[title="Remove"]').addEventListener('click',function(){var nd=new DataTransfer();Array.from(dt.files).forEach(function(x){if(x!==f)nd.items.add(x);});_adjFileDts[inp]=nd;document.getElementById(inp).files=nd.files;el.remove();});})(file,div,inputId);
    });
    document.getElementById(inputId).files=dt.files;
}

// ── Submit ────────────────────────────────────────────────────────────────────
function submitAdjForm(){
    // Validate adj_no for new form
    var adjNoEl=document.getElementById('adjNoValue');
    if(adjNoEl&&!adjNoEl.value){showToast('Please configure a Stock Adjustment number format first.',false);return;}
    // Validate rows
    var rows=document.querySelectorAll('#itemsBody .item-row');
    if(rows.length===0){showToast('Please add at least one product.',false);return;}
    var valid=true;
    rows.forEach(function(tr){
        var pid=tr.querySelector('.item-prod-id');
        var qty=tr.querySelector('.item-qty-input');
        if(!pid||!pid.value){showToast('Please select a product for all rows.',false);valid=false;if(tr.querySelector('.item-prod-input'))tr.querySelector('.item-prod-input').focus();}
        else if(!qty||qty.value===''||isNaN(parseFloat(qty.value))||parseFloat(qty.value)===0){showToast('Please enter a non-zero quantity change for all products.',false);valid=false;if(qty)qty.focus();}
    });
    if(!valid)return;
    var btn=document.getElementById('adjSaveBtn');
    btn.disabled=true;btn.textContent='Saving…';
    fetch('inventory_save.php',{method:'POST',body:new FormData(document.getElementById('adjForm'))})
    .then(function(r){return r.json();})
    .then(function(d){
        if(d.success){showToast('Saved.',true);setTimeout(function(){window.location.href=d.redirect||'inventory_adjustment.php';},600);}
        else{showToast(d.message||'Save failed.',false);btn.disabled=false;btn.textContent=btn.textContent.includes('Changes')?'Save Changes':'Save Adjustment';}
    })
    .catch(function(){showToast('Server error.',false);btn.disabled=false;btn.textContent=btn.textContent.includes('Changes')?'Save Changes':'Save Adjustment';});
}
</script>
JS;
}
