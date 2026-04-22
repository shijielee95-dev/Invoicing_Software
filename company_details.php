<?php
require_once 'config/bootstrap.php';
requireAuth();
include 'includes/layout.php';
include 'includes/dropdown.php';

$pdo     = db();
$company = $pdo->query("SELECT * FROM company_profiles WHERE id = 1 LIMIT 1")->fetch();
if (!$company) $company = [];

// Mask NA display values — show blank in form
$c = $company ?: [];
foreach (['sst_no', 'tourism_tax_no'] as $f) {
    if (($c[$f] ?? '') === 'NA') $c[$f] = '';
}

// ── Save ──────────────────────────────────────────────────────────
$saved     = false;
$saveError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $idType = trim($_POST['id_type'] ?? '');
        $currency = trim($_POST['currency'] ?? 'MYR');

        // SST/Tourism: store NA if empty
        $sstNo         = trim($_POST['sst_no']         ?? '');
        $tourismTaxNo  = trim($_POST['tourism_tax_no'] ?? '');
        if ($sstNo        === '') $sstNo        = 'NA';
        if ($tourismTaxNo === '') $tourismTaxNo = 'NA';

        $fields = [
            'company_name'      => strtoupper(trim($_POST['company_name']    ?? '')),
            'company_tin'       => trim($_POST['company_tin']                ?? ''),
            'id_type'           => $idType,
            'id_no'             => strtoupper(trim($_POST['id_no']           ?? '')),
            'currency'          => $currency,
            'tin_no'            => strtoupper(trim($_POST['tin_no']           ?? '')),
            'sst_no'            => strtoupper($sstNo),
            'tourism_tax_no'    => strtoupper($tourismTaxNo),
            'company_email'     => trim($_POST['company_email']              ?? ''),
            'msic_code'         => strtoupper(trim($_POST['msic_code']       ?? '')),
            'business_activity' => strtoupper(trim($_POST['business_activity'] ?? '')),
            'address_line_0'    => strtoupper(trim($_POST['address_line_0']  ?? '')),
            'address_line_1'    => strtoupper(trim($_POST['address_line_1']  ?? '')),
            'address_line_2'    => strtoupper(trim($_POST['address_line_2']  ?? '')),
            'postal_code'       => trim($_POST['postal_code']                ?? ''),
            'city'              => strtoupper(trim($_POST['city']            ?? '')),
            'state_code'        => strtoupper(trim($_POST['state_code']      ?? '')),
            'country_code'      => trim($_POST['country_code']               ?? 'MYS'),
            'phone'             => trim($_POST['phone']                      ?? ''),
            'contact_email'     => trim($_POST['contact_email']              ?? ''),
            'client_id'         => trim($_POST['client_id']                  ?? ''),
            'client_secret_1'   => trim($_POST['client_secret_1']            ?? ''),
            'client_secret_2'   => trim($_POST['client_secret_2']            ?? ''),
        ];

        // Handle logo upload — always named company_logo.{ext}, replaces any existing
        if (!empty($_FILES['logo']['tmp_name'])) {
            $ext   = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allow = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            if (in_array($ext, $allow)) {
                // Delete any existing company_logo.* first
                foreach (glob(UPLOAD_DIR . '/company_logo.*') as $old) { @unlink($old); }
                $fname = 'company_logo.' . $ext;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], UPLOAD_DIR . '/' . $fname)) {
                    // Store clean path only — cache busting applied at display time
                    $fields['logo_path'] = 'uploads/' . $fname;
                }
            }
        }

        $set  = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
        $vals = array_values($fields);

        $exists = $pdo->query("SELECT COUNT(*) FROM company_profiles WHERE id = 1")->fetchColumn();
        if ($exists) {
            $pdo->prepare("UPDATE company_profiles SET $set WHERE id = 1")->execute($vals);
        } else {
            $cols = 'id, ' . implode(', ', array_keys($fields));
            $phs  = '?, ' . implode(', ', array_fill(0, count($fields), '?'));
            $pdo->prepare("INSERT INTO company_profiles ($cols) VALUES ($phs)")->execute(array_merge([1], $vals));
        }

        // Reload
        $company = $pdo->query("SELECT * FROM company_profiles WHERE id = 1 LIMIT 1")->fetch();
        foreach (['sst_no', 'tourism_tax_no'] as $f) {
            if (($company[$f] ?? '') === 'NA') $company[$f] = '';
        }
        $c     = $company ?: [];
        $saved = true;

    } catch (Exception $ex) {
        $saveError = $ex->getMessage();
    }
}

layoutOpen('Company Profile', 'Legal entity details and API credentials.');
?>

<?php if ($saved): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ showToast('Company profile saved.','success'); });</script>
<?php endif; ?>
<?php if ($saveError): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ showToast('Save failed: <?= e(addslashes($saveError)) ?>','error'); });</script>
<?php endif; ?>

<style>
/* ── Panel overlay fix ── */
#companyCountryPanel, #companyCurrencyPanel { 
    position: fixed !important; 
    z-index: 9999 !important;
}
</style>

<form method="POST" id="companyForm" enctype="multipart/form-data" novalidate
      x-data="companyFormComp()"
      @submit.prevent="submitForm()">

<div class="bg-white rounded-xl border border-slate-200 divide-y divide-slate-100">

    <!-- ═══ SECTION 1: Company Logo ═══ -->
    <div class="grid grid-cols-[220px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Company Logo</h3>
            <p class="text-xs text-slate-400 leading-relaxed">Displayed on invoices and reports.</p>
        </div>
        <div class="p-6">
            <label class="<?= t('label') ?>">Logo</label>
            <div class="flex items-start gap-4 mt-1">
                <!-- Current logo -->
                <?php if (!empty($c['logo_path'])): ?>
                <?php
                    $logoFilePath  = UPLOAD_DIR . '/' . basename($c['logo_path']);
                    $logoCacheBust = file_exists($logoFilePath) ? '?v=' . filemtime($logoFilePath) : '';
                    // Derive web-root-relative base from DOCUMENT_ROOT so it works on any server
                    $logoRelUrl = str_replace(
                        rtrim($_SERVER['DOCUMENT_ROOT'], '/'),
                        '',
                        APP_ROOT
                    ) . '/' . ltrim($c['logo_path'], '/') . $logoCacheBust;
                ?>
                <div id="logoCurrentWrap" class="w-24 h-24 rounded-xl border border-slate-200 overflow-hidden shrink-0 bg-slate-50 flex items-center justify-center relative">
                    <img src="<?= htmlspecialchars($logoRelUrl, ENT_QUOTES, 'UTF-8') ?>" class="max-w-full max-h-full object-contain p-1" alt="Company Logo">
                    <!-- DEBUG: <?= htmlspecialchars($logoRelUrl) ?> | exists: <?= file_exists($logoFilePath) ? 'YES' : 'NO' ?> -->
                </div>
                <?php else: ?>
                <div id="logoCurrentWrap" class="w-24 h-24 rounded-xl border border-slate-200 shrink-0 bg-slate-50 flex items-center justify-center text-slate-300">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="1" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9l4-4 4 4 4-5 4 5"/><circle cx="8.5" cy="13.5" r="1.5"/></svg>
                </div>
                <?php endif; ?>
                <!-- New preview (shown after file selected) -->
                <div id="logoNewPreview" style="display:none"
                     class="w-24 h-24 rounded-xl border-2 border-indigo-300 overflow-hidden shrink-0 bg-slate-50 flex items-center justify-center relative">
                    <img id="logoNewPreviewImg" class="max-w-full max-h-full object-contain p-1" src="" alt="New Logo Preview">
                    <span class="absolute bottom-1 right-1 bg-indigo-600 text-white text-[9px] font-semibold px-1 rounded">NEW</span>
                </div>
                <!-- Upload button -->
                <div class="flex flex-col gap-1.5">
                    <label class="w-24 h-24 flex flex-col items-center justify-center gap-1.5 border-2 border-dashed border-slate-300 rounded-xl cursor-pointer hover:border-indigo-400 hover:bg-indigo-50/30 transition-colors text-slate-400 hover:text-indigo-500">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                        <span class="text-xs font-medium">Upload</span>
                        <input type="file" name="logo" accept="image/*" class="hidden" onchange="showLogoPreview(this)">
                    </label>
                    <span id="logoFileName" class="text-xs text-slate-400 truncate max-w-[96px]"></span>
                    <span class="text-[10px] text-slate-300">JPG, PNG, SVG</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══ SECTION 2: Company Information ═══ -->
    <div class="grid grid-cols-[220px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Company Information</h3>
            <p class="text-xs text-slate-400 leading-relaxed">Legal entity details registered with LHDN.</p>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-4">

                <div>
                    <label class="<?= t('label') ?>">Company Name <span class="text-red-400">*</span></label>
                    <input type="text" name="company_name" required
                           value="<?= e($c['company_name'] ?? '') ?>"
                           class="<?= t('input') ?> uppercase" style="text-transform:uppercase">
                </div>

                <div>
                    <label class="<?= t('label') ?>">Friendly Name</label>
                    <input type="text" name="company_tin"
                           value="<?= e($c['company_tin'] ?? '') ?>"
                           placeholder="Display name (optional)"
                           class="<?= t('input') ?>">
                </div>

                <div>
                    <label class="<?= t('label') ?>">Base Currency <span class="text-red-400">*</span></label>
                    <div id="companyCurrencyDd" x-data="companyCurrencyComp('<?= e($c['currency'] ?? 'MYR') ?>')" class="relative">
                        <div class="relative">
                            <input type="text" id="companyCurrencyInput"
                                   :value="open ? q : selected.label"
                                   @focus="onFocus()"
                                   @input="q=$event.target.value; activeIdx=-1"
                                   @blur="onBlur()"
                                   @keydown.escape="$el.blur()"
                                   @keydown.arrow-down.prevent="moveDown()"
                                   @keydown.arrow-up.prevent="moveUp()"
                                   @keydown.enter.prevent="pickActive()"
                                   placeholder="Search currency..."
                                   autocomplete="off"
                                   class="<?= t('input') ?> pr-8">
                            <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none transition-transform"
                                 :class="open ? 'rotate-180' : ''"
                                 fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                        <div id="companyCurrencyPanel"
                             x-show="open && filtered.length"
                             @mousedown.prevent
                             style="display:none;z-index:9998"
                             x-init="$watch('open', function(v) {
                                 if (v) {
                                     var r = $el.previousElementSibling.getBoundingClientRect();
                                     $el.style.top   = (r.bottom + 4) + 'px';
                                     $el.style.left  = r.left + 'px';
                                     $el.style.width = r.width + 'px';
                                 }
                             })"
                             class="bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">
                            <ul class="max-h-52 overflow-y-auto py-1" x-ref="list">
                                <template x-for="(curr, i) in filtered" :key="curr.code">
                                    <li>
                                        <button type="button"
                                                @mousedown.prevent="pick(curr)"
                                                class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                                :class="i===activeIdx ? 'bg-indigo-50 text-indigo-700 font-medium' : (curr.code===selected.code ? 'bg-slate-50 text-slate-700 font-medium' : 'text-slate-800 hover:bg-slate-50')">
                                            <span class="font-mono text-xs font-semibold" x-text="curr.code"></span>
                                            <span class="ml-2 text-slate-500" x-text="curr.name"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <input type="hidden" name="currency" :value="selected.code">
                    </div>
                </div>

                <div>
                    <label class="<?= t('label') ?>">ID Type</label>
                    <div class="relative" x-data="{open:false}" @keydown.escape="open=false">
                        <button type="button" @click="open=!open"
                                class="<?= t('input') ?> flex items-center justify-between cursor-pointer text-left">
                            <span :class="idType ? 'text-slate-800' : 'text-slate-400'"
                                  x-text="idTypeOptions.find(o=>o.v===idType)?.l || 'Select ID Type'"></span>
                            <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform" :class="open?'rotate-180':''"
                                 fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div x-show="open" @click.outside="open=false" @mousedown.prevent style="display:none"
                             class="absolute z-[9996] left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">
                            <ul class="py-1">
                                <template x-for="o in idTypeOptions" :key="o.v">
                                    <li>
                                        <button type="button" @click="idType=o.v; open=false"
                                                class="w-full text-left px-3 py-2 text-sm transition-colors"
                                                :class="idType===o.v ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-slate-700 hover:bg-slate-50'">
                                            <span x-text="o.l"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <input type="hidden" name="id_type" :value="idType">
                    </div>
                </div>

                <div>
                    <label class="<?= t('label') ?>">
                        ID No <span class="text-red-400" id="idNoRequired" x-show="idType !== 'NA'">*</span>
                    </label>
                    <input type="text" name="id_no" id="idNoInput"
                           value="<?= e($c['id_no'] ?? '') ?>"
                           :required="idType !== 'NA'"
                           class="<?= t('input') ?> uppercase" style="text-transform:uppercase">
                </div>

                <div>
                    <label class="<?= t('label') ?>">
                        TIN Number <span class="text-red-400" x-show="idType !== 'NA'">*</span>
                    </label>
                    <input type="text" name="tin_no" id="tinNoInput"
                           value="<?= e($c['tin_no'] ?? '') ?>"
                           :required="idType !== 'NA'"
                           class="<?= t('input') ?> uppercase" style="text-transform:uppercase">
                </div>

                <div>
                    <label class="<?= t('label') ?>">SST No</label>
                    <input type="text" name="sst_no"
                           value="<?= e($c['sst_no'] ?? '') ?>"
                           placeholder="Leave blank if N/A"
                           class="<?= t('input') ?> uppercase" style="text-transform:uppercase">
                </div>

                <div>
                    <label class="<?= t('label') ?>">Tourism Tax No</label>
                    <input type="text" name="tourism_tax_no"
                           value="<?= e($c['tourism_tax_no'] ?? '') ?>"
                           placeholder="Leave blank if N/A"
                           class="<?= t('input') ?> uppercase" style="text-transform:uppercase">
                </div>

                <div class="col-span-2">
                    <label class="<?= t('label') ?>">Company Email</label>
                    <input type="email" name="company_email"
                           value="<?= e($c['company_email'] ?? '') ?>"
                           class="<?= t('input') ?>">
                </div>

            </div>
        </div>
    </div>

    <!-- ═══ SECTION 3: Business Details ═══ -->
    <div class="grid grid-cols-[220px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Business Details</h3>
            <p class="text-xs text-slate-400 leading-relaxed">MSIC code and business activity.</p>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-4">

                <div>
                    <label class="<?= t('label') ?>">
                        MSIC Code
                        <span class="text-red-400" x-show="idType !== 'NA'">*</span>
                    </label>
                    <input type="text" name="msic_code" id="msicInput"
                           value="<?= e($c['msic_code'] ?? '') ?>"
                           :required="idType !== 'NA'"
                           class="<?= t('input') ?> uppercase" style="text-transform:uppercase">
                </div>

                <div>
                    <label class="<?= t('label') ?>">
                        Business Activity
                        <span class="text-red-400" x-show="idType !== 'NA'">*</span>
                    </label>
                    <input type="text" name="business_activity" id="bizActInput"
                           value="<?= e($c['business_activity'] ?? '') ?>"
                           :required="idType !== 'NA'"
                           class="<?= t('input') ?> uppercase" style="text-transform:uppercase">
                </div>

            </div>
        </div>
    </div>

    <!-- ═══ SECTION 4: Address ═══ -->
    <div class="grid grid-cols-[220px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Address</h3>
            <p class="text-xs text-slate-400 leading-relaxed">Registered business address.</p>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-4">

                <div>
                    <label class="<?= t('label') ?>">Address Line 1</label>
                    <input type="text" name="address_line_0"
                           value="<?= e($c['address_line_0'] ?? '') ?>"
                           class="<?= t('input') ?> uppercase" style="text-transform:uppercase">
                </div>

                <div>
                    <label class="<?= t('label') ?>">Address Line 2</label>
                    <input type="text" name="address_line_1"
                           value="<?= e($c['address_line_1'] ?? '') ?>"
                           class="<?= t('input') ?> uppercase" style="text-transform:uppercase">
                </div>

                <div>
                    <label class="<?= t('label') ?>">Address Line 3</label>
                    <input type="text" name="address_line_2"
                           value="<?= e($c['address_line_2'] ?? '') ?>"
                           class="<?= t('input') ?> uppercase" style="text-transform:uppercase">
                </div>

                <div>
                    <label class="<?= t('label') ?>">Postal Code</label>
                    <input type="text" name="postal_code"
                           value="<?= e($c['postal_code'] ?? '') ?>"
                           class="<?= t('input') ?>">
                </div>

                <div>
                    <label class="<?= t('label') ?>">City</label>
                    <input type="text" name="city"
                           value="<?= e($c['city'] ?? '') ?>"
                           class="<?= t('input') ?> uppercase" style="text-transform:uppercase">
                </div>

                <div>
                    <label class="<?= t('label') ?>">State</label>
                    <input type="text" name="state_code"
                           value="<?= e($c['state_code'] ?? '') ?>"
                           class="<?= t('input') ?> uppercase" style="text-transform:uppercase">
                </div>

                <div>
                    <label class="<?= t('label') ?>">Country</label>
                    <div id="companyCountryDd" x-data="companyCountryComp('<?= e($c['country_code'] ?? 'MYS') ?>')" class="relative">
                        <div class="relative">
                            <input type="text" id="companyCountryInput"
                                   :value="open ? q : selected.label"
                                   @focus="onFocus()"
                                   @input="q=$event.target.value; activeIdx=-1"
                                   @blur="onBlur()"
                                   @keydown.escape="$el.blur()"
                                   @keydown.arrow-down.prevent="moveDown()"
                                   @keydown.arrow-up.prevent="moveUp()"
                                   @keydown.enter.prevent="pickActive()"
                                   placeholder="Search country..."
                                   autocomplete="off"
                                   class="<?= t('input') ?> pr-8">
                            <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none transition-transform"
                                 :class="open ? 'rotate-180' : ''"
                                 fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                        <div id="companyCountryPanel"
                             x-show="open && filtered.length"
                             @mousedown.prevent
                             style="display:none;z-index:9997"
                             x-init="$watch('open', function(v) {
                                 if (v) {
                                     var r = $el.previousElementSibling.getBoundingClientRect();
                                     $el.style.top   = (r.bottom + 4) + 'px';
                                     $el.style.left  = r.left + 'px';
                                     $el.style.width = r.width + 'px';
                                 }
                             })"
                             class="bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">
                            <ul class="max-h-52 overflow-y-auto py-1" x-ref="list">
                                <template x-for="(c, i) in filtered" :key="c.code">
                                    <li>
                                        <button type="button"
                                                @mousedown.prevent="pick(c)"
                                                class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                                                :class="i===activeIdx ? 'bg-indigo-50 text-indigo-700 font-medium' : (c.code===selected.code ? 'bg-slate-50 text-slate-700 font-medium' : 'text-slate-800 hover:bg-slate-50')">
                                            <span class="font-mono text-xs font-semibold" x-text="c.code"></span>
                                            <span class="ml-2 text-slate-500" x-text="c.name"></span>
                                        </button>
                                    </li>
                                </template>
                            </ul>
                        </div>
                        <input type="hidden" name="country_code" :value="selected.code">
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- ═══ SECTION 5: Contact ═══ -->
    <div class="grid grid-cols-[220px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">Contact</h3>
            <p class="text-xs text-slate-400 leading-relaxed">Primary contact details.</p>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-4">

                <div>
                    <label class="<?= t('label') ?>">Phone</label>
                    <input type="text" name="phone"
                           value="<?= e($c['phone'] ?? '') ?>"
                           class="<?= t('input') ?>">
                </div>

                <div>
                    <label class="<?= t('label') ?>">Contact Email</label>
                    <input type="email" name="contact_email"
                           value="<?= e($c['contact_email'] ?? '') ?>"
                           class="<?= t('input') ?>">
                </div>

            </div>
        </div>
    </div>

    <!-- ═══ SECTION 6: e-Invoice API ═══ -->
    <div class="grid grid-cols-[220px_1fr]">
        <div class="p-6 border-r border-slate-100">
            <h3 class="text-sm font-semibold text-slate-800 mb-1">e-Invoice API</h3>
            <p class="text-xs text-slate-400 leading-relaxed">LHDN MyInvois API credentials.</p>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 gap-4 max-w-lg">

                <div>
                    <label class="<?= t('label') ?>">Client ID</label>
                    <input type="text" name="client_id"
                           value="<?= e($c['client_id'] ?? '') ?>"
                           class="<?= t('input') ?>">
                </div>

                <div>
                    <label class="<?= t('label') ?>">Client Secret 1</label>
                    <input type="text" name="client_secret_1"
                           value="<?= e($c['client_secret_1'] ?? '') ?>"
                           class="<?= t('input') ?>">
                </div>

                <div>
                    <label class="<?= t('label') ?>">Client Secret 2</label>
                    <input type="text" name="client_secret_2"
                           value="<?= e($c['client_secret_2'] ?? '') ?>"
                           class="<?= t('input') ?>">
                </div>

            </div>
        </div>
    </div>

</div><!-- end card -->

<!-- Sticky footer -->
<div class="fixed bottom-0 right-0 bg-white border-t border-slate-200 z-20 flex items-center justify-end px-8 py-3" style="left:256px">
    <button type="submit" class="<?= t('btn_base') ?> <?= t('btn_primary') ?> h-9">
        Save Changes
    </button>
</div>
<div class="h-16"></div>

</form>

<script>
// ── Currencies list (same as invoice.php) ───────────────────────
var COMPANY_CURRENCIES = [
    {code:'MYR',name:'Malaysian Ringgit'},{code:'USD',name:'US Dollar'},
    {code:'EUR',name:'Euro'},{code:'GBP',name:'British Pound'},
    {code:'SGD',name:'Singapore Dollar'},{code:'AUD',name:'Australian Dollar'},
    {code:'CAD',name:'Canadian Dollar'},{code:'JPY',name:'Japanese Yen'},
    {code:'CNY',name:'Chinese Yuan'},{code:'HKD',name:'Hong Kong Dollar'},
    {code:'CHF',name:'Swiss Franc'},{code:'NZD',name:'New Zealand Dollar'},
    {code:'SEK',name:'Swedish Krona'},{code:'NOK',name:'Norwegian Krone'},
    {code:'DKK',name:'Danish Krone'},{code:'KRW',name:'South Korean Won'},
    {code:'INR',name:'Indian Rupee'},{code:'IDR',name:'Indonesian Rupiah'},
];

function companyCurrencyComp(initialCode) {
    var sorted = COMPANY_CURRENCIES.slice().sort(function(a, b) {
        return a.name.localeCompare(b.name);
    });
    // Keep MYR first
    sorted = sorted.filter(function(c){ return c.code !== 'MYR'; });
    sorted.unshift(COMPANY_CURRENCIES.find(function(c){ return c.code === 'MYR'; }));

    var def = sorted.find(function(c){ return c.code === initialCode; }) || sorted[0];
    return {
        inputId: 'companyCurrencyInput',
        q: '', open: false, activeIdx: -1,
        selected: { code: def.code, label: def.code + ' \u2014 ' + def.name },
        currencies: sorted,
        get filtered() {
            var q = this.q.trim().toLowerCase();
            if (!q) return this.currencies;
            return this.currencies.filter(function(c) {
                return c.code.toLowerCase().includes(q) || c.name.toLowerCase().includes(q);
            });
        },
        onFocus: function() {
            this.q = ''; this.open = true; this.activeIdx = -1;
            var self = this;
            this.$nextTick(function() {
                var el = document.getElementById('companyCurrencyInput');
                if (el) el.select();
            });
        },
        pick: function(c) {
            this.selected  = { code: c.code, label: c.code + ' \u2014 ' + c.name };
            this.q = ''; this.open = false; this.activeIdx = -1;
            var inp = document.getElementById('companyCurrencyInput');
            if (inp) inp.blur();
        },
        pickActive: function() {
            var list = this.filtered;
            var idx = this.activeIdx >= 0 ? this.activeIdx : 0;
            if (list[idx] && (this.q.trim() !== '' || this.activeIdx >= 0)) {
                this.pick(list[idx]);
            } else {
                this.revert();
            }
        },
        revert: function() {
            this.q = ''; this.open = false; this.activeIdx = -1;
            var inp = document.getElementById(this.inputId);
            if (inp) {
                inp.value = this.selected.label;
                inp.blur();
            }
        },
        cancel: function() {
            this.q = ''; this.open = false; this.activeIdx = -1;
        },
        moveDown: function() {
            this.activeIdx = Math.min(this.activeIdx + 1, this.filtered.length - 1);
            this.scrollActive();
        },
        moveUp: function() {
            this.activeIdx = Math.max(this.activeIdx - 1, 0);
            this.scrollActive();
        },
        scrollActive: function() {
            var self = this;
            this.$nextTick(function() {
                var list = self.$refs.list;
                if (!list) return;
                var active = list.querySelectorAll('li')[self.activeIdx];
                if (active) active.scrollIntoView({ block: 'nearest' });
            });
        },
        onBlur: function() {
            var self = this;
            setTimeout(function() {
                if (self.open) { self.cancel(); }
            }, 200);
        }
    };
}

// ── Countries list (same as invoice.php currencies pattern) ──────
var COMPANY_COUNTRIES = [
    {code:'MYS',name:'Malaysia'},{code:'SGP',name:'Singapore'},{code:'IDN',name:'Indonesia'},
    {code:'THA',name:'Thailand'},{code:'PHL',name:'Philippines'},{code:'VNM',name:'Vietnam'},
    {code:'MMR',name:'Myanmar'},{code:'KHM',name:'Cambodia'},{code:'LAO',name:'Laos'},
    {code:'BRN',name:'Brunei'},{code:'AUS',name:'Australia'},{code:'NZL',name:'New Zealand'},
    {code:'USA',name:'United States'},{code:'GBR',name:'United Kingdom'},{code:'IND',name:'India'},
    {code:'CHN',name:'China'},{code:'JPN',name:'Japan'},{code:'KOR',name:'South Korea'},
    {code:'HKG',name:'Hong Kong'},{code:'TWN',name:'Taiwan'},{code:'ARE',name:'UAE'},
    {code:'SAU',name:'Saudi Arabia'},{code:'QAT',name:'Qatar'},{code:'KWT',name:'Kuwait'},
    {code:'BHR',name:'Bahrain'},{code:'OMN',name:'Oman'},{code:'JOR',name:'Jordan'},
    {code:'EGY',name:'Egypt'},{code:'ZAF',name:'South Africa'},{code:'NGA',name:'Nigeria'},
    {code:'KEN',name:'Kenya'},{code:'BRA',name:'Brazil'},{code:'MEX',name:'Mexico'},
    {code:'ARG',name:'Argentina'},{code:'CHL',name:'Chile'},{code:'COL',name:'Colombia'},
    {code:'RUS',name:'Russia'},{code:'TUR',name:'Turkey'},{code:'POL',name:'Poland'},
    {code:'NLD',name:'Netherlands'},{code:'DEU',name:'Germany'},{code:'FRA',name:'France'},
    {code:'ITA',name:'Italy'},{code:'ESP',name:'Spain'},{code:'PRT',name:'Portugal'},
    {code:'CHE',name:'Switzerland'},{code:'SWE',name:'Sweden'},{code:'NOR',name:'Norway'},
    {code:'DNK',name:'Denmark'},{code:'FIN',name:'Finland'},
];

function companyCountryComp(initialCode) {
    var sorted = COMPANY_COUNTRIES.slice().sort(function(a,b){ return a.name.localeCompare(b.name); });
    // Keep MYS first
    sorted = sorted.filter(function(c){ return c.code !== 'MYS'; });
    sorted.unshift({code:'MYS', name:'Malaysia'});

    var def = sorted.find(function(c){ return c.code === initialCode; }) || sorted[0];
    return {
        inputId: 'companyCountryInput',
        q: '', open: false, activeIdx: -1,
        selected: { code: def.code, label: def.code + ' — ' + def.name },
        countries: sorted,
        get filtered() {
            var q = this.q.trim().toLowerCase();
            if (!q) return this.countries;
            return this.countries.filter(function(c) {
                return c.code.toLowerCase().includes(q) || c.name.toLowerCase().includes(q);
            });
        },
        onFocus: function() {
            this.q = ''; this.open = true; this.activeIdx = -1;
            var self = this;
            this.$nextTick(function() {
                var el = document.getElementById('companyCountryInput');
                if (el) el.select();
            });
        },
        pick: function(c) {
            this.selected  = { code: c.code, label: c.code + ' \u2014 ' + c.name };
            this.q = ''; this.open = false; this.activeIdx = -1;
            var inp = document.getElementById('companyCountryInput');
            if (inp) inp.blur();
        },
        pickActive: function() {
            var list = this.filtered;
            var idx = this.activeIdx >= 0 ? this.activeIdx : 0;
            if (list[idx] && (this.q.trim() !== '' || this.activeIdx >= 0)) {
                this.pick(list[idx]);
            } else {
                this.revert();
            }
        },
        revert: function() {
            this.q = ''; this.open = false; this.activeIdx = -1;
            var inp = document.getElementById(this.inputId);
            if (inp) {
                inp.value = this.selected.label;
                inp.blur();
            }
        },
        cancel: function() {
            this.q = ''; this.open = false; this.activeIdx = -1;
        },
        moveDown: function() {
            this.activeIdx = Math.min(this.activeIdx + 1, this.filtered.length - 1);
            this.scrollActive();
        },
        moveUp: function() {
            this.activeIdx = Math.max(this.activeIdx - 1, 0);
            this.scrollActive();
        },
        scrollActive: function() {
            var self = this;
            this.$nextTick(function() {
                var list = self.$refs.list;
                if (!list) return;
                var active = list.querySelectorAll('li')[self.activeIdx];
                if (active) active.scrollIntoView({ block: 'nearest' });
            });
        },
        onBlur: function() {
            var self = this;
            setTimeout(function() {
                if (self.open) { self.cancel(); }
            }, 200);
        }
    };
}

// ── Main form Alpine component ───────────────────────────────────
function companyFormComp() {
    return {
        idType: '<?= e($c['id_type'] ?? 'NA') ?>',
        idTypeOptions: [
            {v:'NA',    l:'None'},
            {v:'NRIC',    l:'NRIC'},
            {v:'BRN',     l:'BRN'},
            {v:'ARMY',    l:'Army No'},
            {v:'PASSPORT',l:'Passport No'},
        ],

        submitForm: function() {
            var form = document.getElementById('companyForm');
            form.querySelectorAll('.field-error').forEach(function(el) { el.remove(); });
            form.querySelectorAll('.border-red-500').forEach(function(el) { el.classList.remove('border-red-500'); });

            var idType = this.idType;
            var errors = [];

            var check = function(id, label) {
                var el = document.getElementById(id) || form.querySelector('[name="' + id + '"]');
                if (!el) return;
                if (!el.value.trim()) {
                    el.classList.add('border-red-500');
                    var p = document.createElement('p');
                    p.className = 'field-error text-red-500 text-xs mt-1';
                    p.textContent = label + ' is required.';
                    el.after(p);
                    el.addEventListener('input', function fix() {
                        if (el.value.trim()) { el.classList.remove('border-red-500'); p.remove(); el.removeEventListener('input', fix); }
                    });
                    errors.push(el);
                }
            };

            // Always required
            check('company_name', 'Company Name');

            // Required only when ID Type != None
            if (idType !== 'NA' && idType !== '') {
                check('idNoInput',   'ID No');
                check('tinNoInput',  'TIN Number');
                check('msicInput',   'MSIC Code');
                check('bizActInput', 'Business Activity');
            }

            if (errors.length > 0) { errors[0].focus(); return; }
            form.submit();
        }
    };
}

// ── Logo preview ─────────────────────────────────────────────────
function showLogoPreview(input) {
    if (!input.files || !input.files[0]) return;
    var file = input.files[0];
    document.getElementById('logoFileName').textContent = file.name;
    var reader = new FileReader();
    reader.onload = function(e) {
        // Hide existing logo, show new preview
        var current = document.getElementById('logoCurrentWrap');
        if (current) current.style.display = 'none';
        var preview = document.getElementById('logoNewPreview');
        var img     = document.getElementById('logoNewPreviewImg');
        img.src     = e.target.result;
        preview.style.display = 'flex';
    };
    reader.readAsDataURL(file);
}

// ── Enter key advances fields ────────────────────────────────────
(function() {
    var form = document.getElementById('companyForm');
    if (!form) return;
    form.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        if (e.target.tagName === 'TEXTAREA') return;
        e.preventDefault();
        var focusable = Array.from(form.querySelectorAll(
            'input:not([type=hidden]):not([disabled]), button:not([disabled])'
        )).filter(function(el) { 
            return el.offsetParent !== null && !el.closest('ul'); 
        });
        var idx = focusable.indexOf(e.target);
        if (idx > -1 && idx < focusable.length - 1) {
            var next = focusable[idx + 1];
            next.focus();
            if (next.tagName === 'INPUT') next.select();
        } else if (idx === focusable.length - 1 || e.target.type === 'submit') {
            form.querySelector('button[type=submit]').click();
        }
    });
})();

// ── Panel scroll tracking ───────────────────────────────────────
(function() {
    var mainEl = document.querySelector('main');
    if (!mainEl) return;
    mainEl.addEventListener('scroll', function() {
        // Country
        var inpC = document.getElementById('companyCountryInput');
        var panC = document.getElementById('companyCountryPanel');
        if (inpC && panC && panC.style.display !== 'none') {
            var r = inpC.getBoundingClientRect();
            panC.style.top = (r.bottom + 4) + 'px';
            panC.style.left = r.left + 'px';
        }
        // Currency
        var inpR = document.getElementById('companyCurrencyInput');
        var panR = document.getElementById('companyCurrencyPanel');
        if (inpR && panR && panR.style.display !== 'none') {
            var r = inpR.getBoundingClientRect();
            panR.style.top = (r.bottom + 4) + 'px';
            panR.style.left = r.left + 'px';
        }
    }, { passive: true });
})();
</script>

<?php layoutClose(); ?>
