<?php
require_once 'config/bootstrap.php';
requireAuth();
include 'includes/layout.php';

$pdo    = db();
$action = $_GET['action'] ?? 'list';
$cfid   = (int)($_GET['id'] ?? 0);

// ── Available modules (only invoice & quotation enabled) ──────────
$allModules = [
    'Sales' => [
        'invoice'   => ['label' => 'Invoice',         'enabled' => true],
        'quotation' => ['label' => 'Quotation',        'enabled' => true],
        'sales_order'     => ['label' => 'Sales Order',      'enabled' => false],
        'delivery_order'  => ['label' => 'Delivery Order',   'enabled' => false],
        'credit_note'     => ['label' => 'Credit Note',      'enabled' => false],
        'payment_sales'   => ['label' => 'Payment',          'enabled' => false],
        'refund_sales'    => ['label' => 'Refund',           'enabled' => false],
    ],
    'Purchases' => [
        'purchase_order'  => ['label' => 'Purchase Order',   'enabled' => false],
        'grn'             => ['label' => 'Goods Received Note','enabled' => false],
        'bill'            => ['label' => 'Bill',             'enabled' => false],
        'credit_note_pur' => ['label' => 'Credit Note',      'enabled' => false],
        'payment_pur'     => ['label' => 'Payment',          'enabled' => false],
        'refund_pur'      => ['label' => 'Refund',           'enabled' => false],
    ],
    'Bank' => [
        'money_in'   => ['label' => 'Money In',  'enabled' => false],
        'money_out'  => ['label' => 'Money Out', 'enabled' => false],
    ],
    'Contacts' => [
        'statement'  => ['label' => 'Statement of Account', 'enabled' => false],
    ],
];

$dataTypes = [
    'text'     => 'Text',
    'date'     => 'Date',
    'dropdown' => 'Dropdown',
];

// ── Default field ────────────────────────────────────────────────
$cf = [
    'id' => '', 'name' => '', 'field_type' => 'contact',
    'data_type' => 'text', 'is_required' => 0,
];
$cfModules = [];
$cfOptions = [];

if (($action === 'edit' || $action === 'view') && $cfid > 0) {
    $row = $pdo->prepare("SELECT * FROM custom_fields WHERE id = ?");
    $row->execute([$cfid]);
    $row = $row->fetch();
    if (!$row) { flash('error', 'Custom field not found.'); redirect('custom_fields.php'); }
    $cf = array_merge($cf, $row);

    $modStmt = $pdo->prepare("SELECT module FROM custom_field_modules WHERE custom_field_id = ?");
    $modStmt->execute([$cfid]);
    $cfModules = array_column($modStmt->fetchAll(PDO::FETCH_ASSOC), 'module');

    $optStmt = $pdo->prepare("SELECT id, option_value FROM custom_field_options WHERE custom_field_id = ? ORDER BY sort_order, id");
    $optStmt->execute([$cfid]);
    $cfOptions = $optStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ══════════════════════════════════════════════════════════════════
// SAVE (new or edit)
// ══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name'] ?? '');
    $fieldType  = in_array($_POST['field_type'] ?? '', ['contact','transaction']) ? $_POST['field_type'] : 'contact';
    $dataType   = in_array($_POST['data_type'] ?? '', array_keys($dataTypes)) ? $_POST['data_type'] : 'text';
    $isRequired = isset($_POST['is_required']) ? 1 : 0;
    $modules    = array_filter($_POST['modules'] ?? []);
    $options    = array_filter(array_map('trim', $_POST['dropdown_options'] ?? []), fn($v) => $v !== '');

    if (!$name) { flash('error', 'Name is required.'); redirect("custom_fields.php?action=" . ($cfid ? "edit&id=$cfid" : 'new')); }

    if ($cfid) {
        $pdo->prepare("UPDATE custom_fields SET name=?, field_type=?, data_type=?, is_required=? WHERE id=?")
            ->execute([$name, $fieldType, $dataType, $isRequired, $cfid]);
        $pdo->prepare("DELETE FROM custom_field_modules WHERE custom_field_id=?")->execute([$cfid]);
        $pdo->prepare("DELETE FROM custom_field_options WHERE custom_field_id=?")->execute([$cfid]);
        $id = $cfid;
    } else {
        $pdo->prepare("INSERT INTO custom_fields (name, field_type, data_type, is_required) VALUES (?,?,?,?)")
            ->execute([$name, $fieldType, $dataType, $isRequired]);
        $id = (int)$pdo->lastInsertId();
    }

    // Save modules
    $mStmt = $pdo->prepare("INSERT IGNORE INTO custom_field_modules (custom_field_id, module) VALUES (?,?)");
    foreach ($modules as $mod) $mStmt->execute([$id, $mod]);

    // Save dropdown options
    if ($dataType === 'dropdown') {
        $oStmt = $pdo->prepare("INSERT INTO custom_field_options (custom_field_id, option_value, sort_order) VALUES (?,?,?)");
        foreach (array_values($options) as $i => $opt) $oStmt->execute([$id, $opt, $i]);
    }

    flash('success', $cfid ? 'Custom field updated.' : 'Custom field created.');
    redirect('custom_fields.php');
}

// ══════════════════════════════════════════════════════════════════
// DELETE
// ══════════════════════════════════════════════════════════════════
if ($action === 'delete' && $cfid) {
    $pdo->prepare("DELETE FROM custom_fields WHERE id=?")->execute([$cfid]);
    flash('success', 'Custom field deleted.');
    redirect('custom_fields.php');
}

// ══════════════════════════════════════════════════════════════════
// LIST
// ══════════════════════════════════════════════════════════════════
if ($action === 'list'):
    $fields = $pdo->query("SELECT * FROM custom_fields ORDER BY sort_order, name")->fetchAll();
    $fieldsJson = json_encode(array_values(array_map(function($f) use ($dataTypes) {
        return [
            'id'         => (int)$f['id'],
            'name'       => $f['name'],
            'field_type' => ucfirst($f['field_type']),
            'data_type'  => $dataTypes[$f['data_type']] ?? $f['data_type'],
        ];
    }, $fields)), JSON_HEX_TAG|JSON_HEX_QUOT);

    layoutOpen('Custom Fields', 'Add user defined fields to contacts / transactions.');
?>
<script>
document.getElementById('pageActions').innerHTML =
    '<a href="custom_fields.php?action=new" class="<?= t('btn_base').' '.t('btn_primary') ?> h-9">' +
    '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>' +
    'New</a>';
document.querySelector('main').style.overflow = 'hidden';
document.querySelector('main').style.display  = 'flex';
document.querySelector('main').style.flexDirection = 'column';
</script>

<div id="cfWrap" class="flex flex-col flex-1 min-h-0">
    <div class="bg-white rounded-xl border border-slate-200 flex flex-col flex-1 min-h-0 overflow-hidden">
        <table class="w-full text-sm" style="table-layout:fixed">
            <colgroup>
                <col style="width:36px">
                <col>
                <col style="width:180px">
                <col style="width:160px">
                <col style="width:120px">
            </colgroup>
            <thead class="bg-slate-50 border-b border-slate-100 shrink-0">
                <tr>
                    <th class="px-4 py-3 text-center text-[10px] font-semibold text-slate-500 uppercase tracking-wide">#</th>
                    <th class="px-4 py-3 text-left   text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Name</th>
                    <th class="px-4 py-3 text-left   text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Type</th>
                    <th class="px-4 py-3 text-left   text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Data Type</th>
                    <th class="px-4 py-3 text-center text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Action</th>
                </tr>
            </thead>
        </table>
        <div class="flex-1 overflow-y-auto">
            <table class="w-full text-sm" style="table-layout:fixed" id="cfTable">
                <colgroup>
                    <col style="width:36px">
                    <col>
                    <col style="width:180px">
                    <col style="width:160px">
                    <col style="width:120px">
                </colgroup>
                <tbody id="cfBody"></tbody>
            </table>
            <div id="cfEmpty" class="hidden flex flex-col items-center justify-center py-20 text-slate-400">
                <svg class="w-12 h-12 mb-3 opacity-30" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                <p class="text-sm">No custom fields yet. <a href="custom_fields.php?action=new" class="text-indigo-600 hover:underline">Create one →</a></p>
            </div>
        </div>
        <!-- Pagination -->
        <div class="flex items-center justify-end gap-3 px-4 py-3 border-t border-slate-100 shrink-0 text-xs text-slate-500">
            <span id="cfPagInfo"></span>
            <div class="flex items-center gap-1">
                <button id="cfPrev" onclick="cfPage(-1)" class="w-7 h-7 flex items-center justify-center rounded border border-slate-200 hover:bg-slate-50 disabled:opacity-40">&lt;</button>
                <span id="cfPageNum" class="w-7 h-7 flex items-center justify-center rounded border border-indigo-500 bg-indigo-500 text-white font-medium">1</span>
                <button id="cfNext" onclick="cfPage(1)"  class="w-7 h-7 flex items-center justify-center rounded border border-slate-200 hover:bg-slate-50 disabled:opacity-40">&gt;</button>
            </div>
            <select id="cfPerPage" onchange="cfRender()" class="h-7 border border-slate-200 rounded px-2 text-xs">
                <option value="30" selected>30 / page</option>
                <option value="50">50 / page</option>
                <option value="100">100 / page</option>
            </select>
        </div>
    </div>
</div>

<script>
const CF_DATA = <?= $fieldsJson ?>;
let cfCurrentPage = 1;

function cfPage(dir) { cfCurrentPage += dir; cfRender(); }

function cfRender() {
    const per  = parseInt(document.getElementById('cfPerPage').value);
    const total = CF_DATA.length;
    const pages = Math.max(1, Math.ceil(total / per));
    cfCurrentPage = Math.max(1, Math.min(cfCurrentPage, pages));
    const start = (cfCurrentPage - 1) * per;
    const rows  = CF_DATA.slice(start, start + per);

    document.getElementById('cfPagInfo').textContent =
        total === 0 ? '0 items' : `${start+1}-${Math.min(start+per,total)} of ${total} items`;
    document.getElementById('cfPageNum').textContent = cfCurrentPage;
    document.getElementById('cfPrev').disabled = cfCurrentPage <= 1;
    document.getElementById('cfNext').disabled = cfCurrentPage >= pages;

    const tbody = document.getElementById('cfBody');
    const empty = document.getElementById('cfEmpty');

    if (rows.length === 0) { tbody.innerHTML = ''; empty.classList.remove('hidden'); return; }
    empty.classList.add('hidden');

    tbody.innerHTML = rows.map(function(f, i) {
        return `<tr class="border-b border-slate-50 hover:bg-slate-50/50 transition-colors">
            <td class="px-4 py-3 text-center text-xs text-slate-400">${start + i + 1}</td>
            <td class="px-4 py-3 font-medium text-slate-800">${esc(f.name)}</td>
            <td class="px-4 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded border border-slate-200 text-xs text-slate-600">${esc(f.field_type)}</span></td>
            <td class="px-4 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded bg-blue-50 border border-blue-200 text-xs text-blue-700">${esc(f.data_type)}</span></td>
            <td class="px-4 py-3 text-center">
                <a href="custom_fields.php?action=edit&id=${f.id}" class="inline-flex items-center px-3 py-1 rounded border border-slate-200 text-xs font-medium text-slate-600 hover:bg-slate-50 hover:border-slate-300 transition-colors">Edit</a>
            </td>
        </tr>`;
    }).join('');
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
cfRender();
</script>

<?php layoutClose(); ?>
<?php

// ══════════════════════════════════════════════════════════════════
// NEW / EDIT FORM
// ══════════════════════════════════════════════════════════════════
else:
    $isEdit = $action === 'edit' && $cfid;
    layoutOpen(
        $isEdit ? 'Edit Custom Field' : 'New Custom Field',
        'Control Panel / Custom Fields'
    );
?>
<script>
document.getElementById('pageActions').innerHTML =
    '<a href="custom_fields.php" class="<?= t('btn_base') ?> h-9 border border-slate-200 text-slate-600 hover:bg-slate-50">Back to List</a>';
</script>

<form method="POST" action="custom_fields.php<?= $isEdit ? "?action=edit&id=$cfid" : '?action=new' ?>">

<!-- ── General ───────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl border border-slate-200 mb-5">
    <button type="button" onclick="toggleSection('secGeneral','chevGeneral')"
            class="w-full flex items-center gap-2 px-5 py-3.5 border-b border-slate-100 text-left">
        <svg id="chevGeneral" class="w-4 h-4 text-slate-400 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
        <span class="text-sm font-semibold text-slate-700">General</span>
    </button>
    <div id="secGeneral" class="p-6">
        <!-- Name -->
        <div class="grid grid-cols-[200px_1fr_1fr] gap-6 items-start mb-5">
            <label class="text-sm font-medium text-slate-600 text-right pt-2">
                <span class="text-red-500">*</span> Name:
            </label>
            <input type="text" name="name" value="<?= e($cf['name']) ?>" placeholder="Eg. Member ID"
                   class="h-9 border border-slate-200 rounded-lg px-3 text-sm focus:outline-none focus:border-indigo-500"
                   required>
            <p class="text-xs text-slate-400 pt-2">The name of the field that will appear on your transactions.</p>
        </div>

        <!-- Type -->
        <div class="grid grid-cols-[200px_1fr_1fr] gap-6 items-start mb-5" x-data="{type: '<?= e($cf['field_type']) ?>'}">
            <label class="text-sm font-medium text-slate-600 text-right pt-2">
                <span class="text-red-500">*</span> Type:
            </label>
<div class="relative" x-data="{open:false,value:'<?= e($cf['field_type']) ?>',options:[{value:'contact',text:'Contact'},{value:'transaction',text:'Transaction'}]}">
                <button type="button" @click="open=!open" @keydown.escape="open=false"
                        class="h-9 w-full border border-slate-200 rounded-lg px-3 text-sm text-left flex items-center justify-between focus:outline-none focus:border-indigo-500 bg-white"
                        @click="type=value">
                    <span x-text="options.find(o=>o.value===value)?.text||'—'" class="text-slate-800"></span>
                    <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform" :class="open?'rotate-180':''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div x-show="open" @click.outside="open=false" style="display:none"
                     class="absolute z-[9996] left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">
                    <ul class="py-1">
                        <template x-for="o in options" :key="o.value">
                            <li>
                                <button type="button" @click="value=o.value;type=o.value;open=false;$el.closest('.relative').querySelector('input[name=field_type]').value=o.value"
                                        class="w-full text-left px-3 py-2 text-sm transition-colors"
                                        :class="value===o.value?'bg-indigo-50 text-indigo-700 font-medium':'text-slate-700 hover:bg-slate-50'">
                                    <span x-text="o.text"></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>
                <input type="hidden" name="field_type" :value="value">
            </div>
            <div class="text-xs text-slate-400 pt-2">
                <p x-show="type==='contact'">Contact: Attach the field to your contacts. The data appearing in transactions will always controlled from the contact's profile.</p>
                <p x-show="type==='transaction'">Transaction: Attach the field to your transactions and you update the data at each transactions.</p>
            </div>
        </div>

        <!-- Data Type -->
        <div class="grid grid-cols-[200px_1fr_1fr] gap-6 items-start mb-5" x-data="{dt: '<?= e($cf['data_type']) ?>'}">
            <label class="text-sm font-medium text-slate-600 text-right pt-2">
                <span class="text-red-500">*</span> Data Type:
            </label>
            <div>
                <div class="relative">
                    <input type="text" id="dtInput" placeholder="Text" readonly
                           onclick="dtOpen()"
                           value="<?= $dataTypes[$cf['data_type']] ?? 'Text' ?>"
                           class="h-9 border border-slate-200 rounded-lg px-3 pr-8 text-sm w-full focus:outline-none focus:border-indigo-500 cursor-pointer">
                    <svg class="absolute right-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                    <div id="dtPanel" class="hidden absolute z-[9996] mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-lg overflow-hidden">
                        <?php foreach ($dataTypes as $val => $label): ?>
                        <button type="button" onclick="dtPick('<?= $val ?>','<?= $label ?>')"
                                class="w-full text-left px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 transition-colors <?= $cf['data_type']===$val ? 'bg-indigo-50 text-indigo-700 font-medium' : '' ?>">
                            <?= e($label) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <input type="hidden" name="data_type" id="dataTypeVal" value="<?= e($cf['data_type']) ?>">

                <!-- Dropdown Options (shown only when data_type = dropdown) -->
                <div id="dropdownOptsWrap" class="mt-3 <?= $cf['data_type'] === 'dropdown' ? '' : 'hidden' ?>">
                    <div id="dropdownOptsList" class="space-y-2">
                        <?php foreach ($cfOptions as $oi => $opt): ?>
                        <div class="flex items-center gap-2 option-row">
                            <span class="text-xs text-slate-400 w-5 text-right opt-num"><?= $oi+1 ?></span>
                            <input type="text" name="dropdown_options[]" value="<?= e($opt['option_value']) ?>"
                                   class="flex-1 h-8 border border-slate-200 rounded-lg px-2.5 text-sm focus:outline-none focus:border-indigo-500">
                            <button type="button" onclick="removeOpt(this)" class="w-7 h-7 flex items-center justify-center rounded text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addOpt()"
                            class="mt-2 w-full h-9 flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                        Item
                    </button>
                </div>
            </div>
            <p class="text-xs text-slate-400 pt-2" id="dtHelp">The data type for the field.</p>
        </div>

        <!-- Set as Required -->
        <div class="grid grid-cols-[200px_1fr_1fr] gap-6 items-center">
            <label class="text-sm font-medium text-slate-600 text-right">Set as Required:</label>
            <div>
                <input type="checkbox" name="is_required" value="1" id="isRequired"
                       class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500"
                       <?= $cf['is_required'] ? 'checked' : '' ?>>
            </div>
            <div></div>
        </div>
    </div>
</div>

<!-- ── Show In ────────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl border border-slate-200 mb-5">
    <button type="button" onclick="toggleSection('secShowIn','chevShowIn')"
            class="w-full flex items-center gap-2 px-5 py-3.5 border-b border-slate-100 text-left">
        <svg id="chevShowIn" class="w-4 h-4 text-slate-400 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
        <span class="text-sm font-semibold text-slate-700">Show In...</span>
    </button>
    <div id="secShowIn" class="p-6 space-y-6">
        <?php foreach ($allModules as $groupName => $modules): ?>
        <div>
            <div class="flex items-center gap-2 px-3 py-2 bg-blue-50/60 rounded-lg border border-blue-100 mb-3">
                <span class="text-xs font-semibold text-slate-600"><?= e($groupName) ?></span>
            </div>
            <div class="space-y-2.5 pl-3">
                <?php foreach ($modules as $modKey => $mod): ?>
                <div class="grid grid-cols-[200px_auto] gap-4 items-center">
                    <label class="text-sm text-slate-<?= $mod['enabled'] ? '600' : '400' ?> text-right">
                        <?= e($mod['label']) ?>:
                    </label>
<?php
                    $isChecked  = in_array($modKey, $cfModules);
                    $isDisabled = !$mod['enabled'];
                    $toggleBg   = $isChecked ? 'bg-indigo-500' : 'bg-slate-200';
                    $translateX = $isChecked ? 'translate-x-4' : '';
                    ?>
                    <?php if ($isDisabled): ?>
                    <button type="button" disabled
                            class="relative w-9 h-5 rounded-full bg-slate-200 opacity-40 cursor-not-allowed shrink-0">
                        <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow"></div>
                    </button>
                    <?php else: ?>
                    <div x-data="{on:<?= $isChecked ? 'true' : 'false' ?>}"
                         class="flex items-center">
                        <button type="button" @click="on=!on"
                                class="relative w-9 h-5 rounded-full transition-colors focus:outline-none shrink-0"
                                :class="on?'bg-indigo-500':'bg-slate-200'">
                            <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform"
                                 :class="on?'translate-x-4':''"></div>
                        </button>
                        <input type="checkbox" name="modules[]" value="<?= e($modKey) ?>"
                               class="sr-only" :checked="on"
                               x-effect="$el.checked=on">
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── Sticky Footer ─────────────────────────────────────────────── -->
<div class="fixed bottom-0 right-0 z-10 bg-white border-t border-slate-200 px-6 py-3 flex items-center justify-between"
     style="left:256px">
    <div>
        <?php if ($isEdit): ?>
        <a href="custom_fields.php?action=delete&id=<?= $cfid ?>"
           onclick="return confirm('Delete this custom field? This will also remove all saved values.')"
           class="<?= t('btn_base') ?> h-9 border border-red-200 text-red-600 hover:bg-red-50">Delete</a>
        <?php endif; ?>
    </div>
    <div class="flex items-center gap-3">
        <a href="custom_fields.php" class="<?= t('btn_base') ?> h-9 border border-slate-200 text-slate-600 hover:bg-slate-50">Cancel</a>
        <button type="submit" class="<?= t('btn_base').' '.t('btn_primary') ?> h-9">
            <?= $isEdit ? 'Save Changes' : 'Create' ?>
        </button>
    </div>
</div>
<div class="h-16"></div><!-- spacer for sticky footer -->

</form>

<script>
// ── Section toggle ─────────────────────────────────────────────────
function toggleSection(id, chevId) {
    var s = document.getElementById(id);
    var c = document.getElementById(chevId);
    var hidden = s.style.display === 'none';
    s.style.display = hidden ? '' : 'none';
    c.style.transform = hidden ? '' : 'rotate(-90deg)';
}

// ── Data type dropdown ────────────────────────────────────────────
function dtOpen() {
    var p = document.getElementById('dtPanel');
    p.classList.toggle('hidden');
}
function dtPick(val, label) {
    document.getElementById('dtInput').value    = label;
    document.getElementById('dataTypeVal').value = val;
    document.getElementById('dtPanel').classList.add('hidden');
    // Show/hide dropdown options section
    document.getElementById('dropdownOptsWrap').classList.toggle('hidden', val !== 'dropdown');
}
document.addEventListener('click', function(e) {
    var wrap = document.getElementById('dtPanel');
    if (!wrap.classList.contains('hidden') && !e.target.closest('#dtPanel') && e.target.id !== 'dtInput') {
        wrap.classList.add('hidden');
    }
});

// ── Dropdown options ──────────────────────────────────────────────
function addOpt() {
    var list = document.getElementById('dropdownOptsList');
    var n    = list.querySelectorAll('.option-row').length + 1;
    var div  = document.createElement('div');
    div.className = 'flex items-center gap-2 option-row';
    div.innerHTML =
        '<span class="text-xs text-slate-400 w-5 text-right opt-num">' + n + '</span>' +
        '<input type="text" name="dropdown_options[]" ' +
        'class="flex-1 h-8 border border-slate-200 rounded-lg px-2.5 text-sm focus:outline-none focus:border-indigo-500">' +
        '<button type="button" onclick="removeOpt(this)" ' +
        'class="w-7 h-7 flex items-center justify-center rounded text-slate-300 hover:text-red-500 hover:bg-red-50 transition-colors">' +
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>' +
        '</button>';
    list.appendChild(div);
    div.querySelector('input').focus();
}
function removeOpt(btn) {
    btn.closest('.option-row').remove();
    // Renumber
    document.querySelectorAll('#dropdownOptsList .opt-num').forEach(function(el, i) {
        el.textContent = i + 1;
    });
}
</script>

<?php layoutClose();
endif; ?>
