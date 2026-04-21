<?php

function renderDropdown(
    string $name,
    array  $options,
    string $selected    = '',
    string $placeholder = 'Select...',
    bool   $required    = false,
    string $extraClass  = ''
): void {

    // Build options for Alpine
    $alpineOptions = [];
    foreach ($options as $val => $label) {
        $alpineOptions[] = [
            'value' => (string)$val,
            'text'  => (string)$label
        ];
    }

    // Alpine state (pure JSON)
    $state = json_encode([
        'open'    => false,
        'value'   => (string)$selected,
        'options' => $alpineOptions
    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

    $req = $required ? ' required' : '';
    $ec  = $extraClass ? ' ' . $extraClass : '';
    $ph  = htmlspecialchars($placeholder, ENT_QUOTES);

    // Use full width when w-full class is passed, else auto-size to content
    if (strpos($extraClass, 'w-full') !== false) {
        $widthStyle = 'style="width:100%"';
    } else {
        $longest = $placeholder;
        foreach ($options as $label) {
            if (mb_strlen($label) > mb_strlen($longest)) $longest = $label;
        }
        $widthStyle = 'style="width:' . (mb_strlen($longest) * 0.6 + 2) . 'rem"';
    }

    echo '
    <div x-data=\'' . $state . '\' class="relative' . $ec . '" ' . $widthStyle . '>

        <!-- Trigger — border-only focus, no ring -->
        <button type="button"
            @click="open=!open"
            @keydown.escape="open=false"
            class="w-full h-9 px-3 rounded-lg bg-white border border-slate-300 text-left flex items-center justify-between text-sm focus:outline-none focus:border-indigo-500 transition hover:border-slate-400">

            <span
                x-text="options.find(o => o.value === value)?.text || \'' . $ph . '\'"
                :class="value ? \'text-black\' : \'text-slate-400\'">
            </span>

            <svg class="w-4 h-4 text-slate-400 shrink-0 transition-transform"
                :class="open ? \'rotate-180\' : \'\'"
                fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <!-- Dropdown panel — fixed positioning to avoid clipping -->
        <div x-show="open"
            @click.outside="open=false"
            style="display:none"
            x-transition:enter="transition ease-out duration-100"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            class="fixed z-50 bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden"
            x-init="$watch(\'open\', function(v) {
                if (v) {
                    var r = $el.previousElementSibling.getBoundingClientRect();
                    $el.style.top   = (r.bottom + 4) + \'px\';
                    $el.style.left  = r.left + \'px\';
                    $el.style.width = r.width + \'px\';
                }
            })">

            <ul class="max-h-56 overflow-y-auto py-1">

                <template x-for="item in options" :key="item.value">
                    <li>
                        <button type="button"
                            @click="value=item.value; open=false"
                            class="w-full text-left px-3 py-1.5 text-sm transition-colors"
                            :class="value===item.value
                                ? \'bg-indigo-50 text-indigo-700 font-medium\'
                                : \'text-black hover:bg-slate-50\'">

                            <span x-text="item.text"></span>
                        </button>
                    </li>
                </template>

            </ul>
        </div>

        <!-- Hidden select -->
        <select name="' . htmlspecialchars($name, ENT_QUOTES) . '"
            x-model="value"
            ' . $req . '
            class="absolute opacity-0 pointer-events-none w-0 h-0 top-0 left-0"
            tabindex="-1"
            aria-hidden="true">

            <option value="" disabled>' . $ph . '</option>';

    foreach ($options as $val => $label) {
        $sel = ($selected === (string)$val) ? ' selected' : '';
        echo '<option value="' . htmlspecialchars((string)$val, ENT_QUOTES) . '"' . $sel . '>'
            . htmlspecialchars((string)$label, ENT_QUOTES) .
        '</option>';
    }

    echo '
        </select>
    </div>';
}


/**
 * renderSearchableDropdown()
 * A type-to-search combobox using the same Alpine pattern as the currency field.
 * Works anywhere Alpine.js is loaded (invoice/quotation form pages).
 *
 * @param string $name       Form field name (submitted value)
 * @param array  $options    ['value' => 'Label', ...] or ['Label', ...]
 * @param string $selected   Currently selected value
 * @param string $placeholder Placeholder text
 * @param bool   $required
 * @param string $extraClass  Additional classes on wrapper div
 */
function renderSearchableDropdown(
    string $name,
    array  $options,
    string $selected    = '',
    string $placeholder = 'Select or type...',
    bool   $required    = false,
    string $extraClass  = ''
): void {

    // Normalise options to [{v, l}] array for Alpine
    $opts = [];
    foreach ($options as $val => $label) {
        $opts[] = ['v' => (string)$val, 'l' => (string)$label];
    }

    $optsJson = json_encode($opts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    $selJson  = json_encode((string)$selected, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
    $req      = $required ? ' required' : '';
    $ec       = $extraClass ? ' ' . $extraClass : '';
    $ph       = htmlspecialchars($placeholder, ENT_QUOTES);

    echo <<<HTML
    <div class="relative{$ec}"
         x-data="sdComp({$selJson}, {$optsJson})">
        <div class="relative">
            <input type="text" x-ref="inp"
                   :value="open ? q : (selected ? opts.find(function(o){return o.v===selected;})?.l||selected : '')"
                   @focus="onFocus()"
                   @input="q=\$event.target.value; activeIdx=-1"
                   @blur="onBlur()"
                   @keydown.escape="open=false; q=''"
                   @keydown.arrow-down.prevent="moveDown()"
                   @keydown.arrow-up.prevent="moveUp()"
                   @keydown.enter.prevent="pickActive()"
                   placeholder="{$ph}"
                   autocomplete="off"
                   class="w-full h-9 border border-slate-200 rounded-lg px-3 pr-8 text-sm focus:outline-none focus:border-indigo-500 transition">
            <svg class="absolute right-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none transition-transform"
                 :class="open ? 'rotate-180' : ''"
                 fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M19 9l-7 7-7-7"/>
            </svg>
        </div>
        <div x-show="open && filtered.length" @mousedown.prevent style="display:none"
             class="absolute z-[9996] left-0 top-full mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden">
            <ul class="max-h-48 overflow-y-auto py-1" x-ref="list">
                <template x-for="(o, i) in filtered" :key="o.v">
                    <li>
                        <button type="button" @mousedown.prevent="pick(o)"
                                class="w-full text-left px-3 py-2 text-sm transition-colors"
                                :class="i===activeIdx
                                    ? 'bg-indigo-50 text-indigo-700 font-medium'
                                    : (o.v===selected ? 'bg-slate-50 text-slate-700 font-medium' : 'text-slate-800 hover:bg-slate-50')">
                            <span x-text="o.l"></span>
                        </button>
                    </li>
                </template>
            </ul>
        </div>
        <input type="hidden" name="{$name}" :value="selected"{$req}>
    </div>
HTML;
}
