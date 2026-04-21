<?php
/**
 * includes/sidebar.php
 */
$currentPage = basename($_SERVER['PHP_SELF']);
$currentAction = $_GET['action'] ?? 'list';

// Fetch logo from company profile
$_logoPath = '';
try {
    $_logoRow = db()->query("SELECT logo_path FROM company_profiles WHERE id=1 LIMIT 1")->fetch();
    $_logoPath = $_logoRow['logo_path'] ?? '';
} catch (Exception $_e) {}

$user      = authUser();
$nameParts = explode(' ', $user['name']);
$initials  = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));

// ── Determine open group ───────────────────────────────────────────
$openGroup = '';
if (in_array($currentPage, ['invoice.php', 'view_invoice.php']))          $openGroup = 'sales';
elseif (in_array($currentPage, ['quotation.php', 'view_quotation.php']))   $openGroup = 'sales';
elseif (in_array($currentPage, ['product.php', 'inventory.php', 'inventory_adjustment.php'])) $openGroup = 'products';
elseif (in_array($currentPage, ['customer.php', 'supplier.php']))          $openGroup = 'contacts';
elseif (in_array($currentPage, ['company_details.php', 'invoice_settings.php', 'lhdn_settings.php', 'number_formats.php', 'tax_settings.php', 'payment_terms.php', 'inventory_settings.php', 'custom_fields.php'])) $openGroup = 'panel';

// ── Active detection helpers ───────────────────────────────────────
function isActive(string $page, string $current, array $actions = [], string $currentAction = 'list'): bool {
    if ($page !== $current) return false;
    if (empty($actions)) return true;
    return in_array($currentAction, $actions);
}

// ── Icons ──────────────────────────────────────────────────────────
$I = [
    'dashboard' => '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
    'invoice'   => '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
    'quote'     => '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
    'customer'  => '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>',
    'supplier'  => '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
    'product'   => '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
    'panel'     => '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14"/><path d="M15.54 8.46a5 5 0 010 7.07M8.46 8.46a5 5 0 000 7.07"/></svg>',
    'sales'     => '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>',
    'contacts'  => '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>',
];

// ── Style helpers ──────────────────────────────────────────────────
$navItem   = 'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors ';
$activeItem = 'bg-indigo-600 text-white';
$normalItem = t('sidebar_text') . ' ' . t('sidebar_hover');

$subItem   = 'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors ';
$activeSub = 'text-white bg-white/10 font-medium';
$normalSub = 'text-slate-400 hover:text-slate-200 hover:bg-white/5';

$groupBtn  = 'w-full flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-medium transition-colors ' . t('sidebar_text') . ' ' . t('sidebar_hover');
$sectionLbl = t('sidebar_label') . ' text-[11px] font-semibold uppercase tracking-widest px-3 pt-4 pb-1.5';
?>

<aside class="<?= t('sidebar_width') ?> <?= t('sidebar_bg') ?> flex flex-col h-screen sticky top-0 border-r border-white/5 shrink-0"
       x-data="{ openGroup: '<?= $openGroup ?>' }">

    <!-- Logo -->
    <div class="flex items-center justify-center h-16 border-b border-white/5 shrink-0 px-4">
        <?php if ($_logoPath && file_exists(APP_ROOT . '/' . strtok($_logoPath, '?'))): ?>
            <img src="<?= e($_logoPath) ?>" alt="Logo" class="h-10 max-w-full object-contain">
        <?php else: ?>
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-xl <?= t('sidebar_logo_bg') ?> flex items-center justify-center shrink-0">
                    <span class="text-white text-xs font-bold">eI</span>
                </div>
                <div>
                    <div class="text-white text-sm font-semibold leading-none"><?= e($theme['app_name']) ?></div>
                    <div class="text-slate-500 text-[10px] mt-0.5"><?= e($theme['app_version']) ?></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Nav -->
    <nav class="flex-1 overflow-y-auto py-3 px-2.5 space-y-0.5 sidebar-scroll">

        <!-- Dashboard -->
        <a href="dashboard.php"
           class="<?= $navItem . ($currentPage === 'dashboard.php' ? $activeItem : $normalItem) ?>">
            <?= $I['dashboard'] ?>
            <span>Dashboard</span>
        </a>

        <!-- ── SALES ────────────────────────────────────── -->
        <div class="<?= $sectionLbl ?>">Sales</div>

        <!-- Sales group (Invoices + Quotations flat, always open) -->
        <a href="invoice.php"
           class="<?= $subItem . (in_array($currentPage, ['invoice.php', 'view_invoice.php']) ? $activeSub : $normalSub) ?>">
            <?= $I['invoice'] ?>
            <span>Invoices</span>
        </a>

        <a href="quotation.php"
           class="<?= $subItem . (in_array($currentPage, ['quotation.php', 'view_quotation.php']) ? $activeSub : $normalSub) ?>">
            <?= $I['quote'] ?>
            <span>Quotations</span>
        </a>

        <!-- ── PRODUCTS ──────────────────────────────────── -->
        <div class="<?= $sectionLbl ?>">Products</div>

        <!-- Products accordion -->
        <div>
            <button @click="openGroup = openGroup === 'products' ? '' : 'products'"
                    class="<?= $groupBtn ?>">
                <div class="flex items-center gap-3"><?= $I['product'] ?><span>Products</span></div>
                <svg class="w-4 h-4 transition-transform" :class="openGroup==='products'?'rotate-180':''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="openGroup === 'products'"
                 x-transition:enter="transition duration-150 ease-out"
                 x-transition:enter-start="opacity-0 -translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="mt-0.5 ml-2 space-y-0.5">
                <a href="product.php"
                   class="<?= $subItem . ($currentPage === 'product.php' ? $activeSub : $normalSub) ?>">
                    All Products
                </a>
                <a href="inventory.php"
                   class="<?= $subItem . ($currentPage === 'inventory.php' ? $activeSub : $normalSub) ?>">
                    Inventory
                </a>
                <a href="inventory_adjustment.php"
                   class="<?= $subItem . ($currentPage === 'inventory_adjustment.php' ? $activeSub : $normalSub) ?>">
                    Stock Adjustment
                </a>
            </div>
        </div>

        <!-- ── CONTACTS ──────────────────────────────────── -->
        <div class="<?= $sectionLbl ?>">Contacts</div>

        <!-- Customers -->
        <a href="customer.php"
           class="<?= $subItem . ($currentPage === 'customer.php' ? $activeSub : $normalSub) ?>">
            <?= $I['customer'] ?>
            <span>Customers</span>
        </a>

        <!-- Suppliers -->
        <a href="supplier.php"
           class="<?= $subItem . ($currentPage === 'supplier.php' ? $activeSub : $normalSub) ?>">
            <?= $I['supplier'] ?>
            <span>Suppliers</span>
        </a>

        <!-- ── CONTROL PANEL ──────────────────────────────── -->
        <div class="<?= $sectionLbl ?>">Control Panel</div>

        <div>
            <button @click="openGroup = openGroup === 'panel' ? '' : 'panel'"
                    class="<?= $groupBtn ?>">
                <div class="flex items-center gap-3"><?= $I['panel'] ?><span>Settings</span></div>
                <svg class="w-4 h-4 transition-transform" :class="openGroup==='panel'?'rotate-180':''" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="openGroup === 'panel'"
                 x-transition:enter="transition duration-150 ease-out"
                 x-transition:enter-start="opacity-0 -translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 class="mt-0.5 ml-2 space-y-0.5">
                <a href="company_details.php"     class="<?= $subItem . ($currentPage === 'company_details.php'     ? $activeSub : $normalSub) ?>">Company Details</a>
                <a href="invoice_settings.php"    class="<?= $subItem . ($currentPage === 'invoice_settings.php'    ? $activeSub : $normalSub) ?>">Invoice Settings</a>
                <a href="lhdn_settings.php"       class="<?= $subItem . ($currentPage === 'lhdn_settings.php'       ? $activeSub : $normalSub) ?>">LHDN e-Invoice</a>
                <a href="number_formats.php"      class="<?= $subItem . ($currentPage === 'number_formats.php'      ? $activeSub : $normalSub) ?>">Number Formats</a>
                <a href="tax_settings.php"        class="<?= $subItem . ($currentPage === 'tax_settings.php'        ? $activeSub : $normalSub) ?>">Tax Settings</a>
                <a href="payment_terms.php"       class="<?= $subItem . ($currentPage === 'payment_terms.php'       ? $activeSub : $normalSub) ?>">Payment Terms</a>
                <a href="custom_fields.php"      class="<?= $subItem . ($currentPage === 'custom_fields.php'      ? $activeSub : $normalSub) ?>">Custom Fields</a>
                <a href="inventory_settings.php"  class="<?= $subItem . ($currentPage === 'inventory_settings.php'  ? $activeSub : $normalSub) ?>">Inventory Settings</a>
            </div>
        </div>

    </nav>

    <!-- User footer -->
    <div class="border-t border-white/5 p-2.5 shrink-0">
        <div class="flex items-center gap-3 px-2.5 py-2.5 rounded-lg <?= t('sidebar_hover') ?> cursor-pointer transition-colors"
             onclick="document.getElementById('userMenuBtn').click()">
            <div id="sidebarUserInitial"
                 class="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center text-white text-sm font-semibold shrink-0">
                <?= e($initials) ?>
            </div>
            <div class="flex-1 min-w-0">
                <div id="sidebarUserName"  class="text-slate-200 text-sm font-medium truncate"><?= e($user['name']) ?></div>
                <div id="sidebarUserEmail" class="text-slate-500 text-xs truncate"><?= e($user['email']) ?></div>
            </div>
        </div>
    </div>

</aside>

<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
