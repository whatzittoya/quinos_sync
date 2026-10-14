<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { api } from '../../api';
import SyncDaysPanel from '../../components/SyncDaysPanel.vue';
import { useAuth } from '../../stores/auth';

const auth = useAuth();
const iso = (d) => d.toISOString().slice(0, 10);
const today = new Date();
const from = ref(iso(new Date(today.getFullYear(), today.getMonth(), 1)));
const to = ref(iso(today));
const rows = ref([]);
const loaded = ref(false);
const loading = ref(false);
const missing = ref([]);
const daysSync = ref([]);
const syncing = ref(false);
const notice = ref('');
const error = ref('');
const blocked = computed(() => auth.isAdmin && !auth.activeClient);

const COLUMNS = [
    ['contact_name', 'Contact'], ['po_no', 'PO'], ['invoice_no', 'Invoice'], ['invoice_date', 'Invoice date'],
    ['due_date', 'Due date'], ['total', 'Total'], ['amount_due', 'Amount due'], ['item', 'Item'],
    ['item_code', 'Code'], ['quantity', 'Qty'], ['unit_amount', 'Unit amount'], ['account_code', 'Account'],
    ['tax_type', 'Tax type'], ['tax_amount', 'Tax'], ['currency', 'Currency'], ['type', 'Type'], ['status', 'Status'],
];
const NUMERIC = new Set(['total', 'amount_due', 'quantity', 'unit_amount', 'tax_amount']);
const fmt = (key, v) => (v == null ? '' : NUMERIC.has(key) ? Number(v).toLocaleString('en-US', { maximumFractionDigits: 4 }) : v);

let pollTimer;
onBeforeUnmount(() => clearTimeout(pollTimer));

async function run({ quiet = false } = {}) {
    clearTimeout(pollTimer);
    if (!quiet) { loading.value = true; notice.value = ''; }
    error.value = '';
    try {
        const res = await api('/reports/purchase', { query: { from: from.value, to: to.value } });
        missing.value = res.missing;
        daysSync.value = res.days_sync;
        rows.value = res.rows;
        loaded.value = res.missing.length === 0;
        if (res.missing.length && res.missing.some((d) => d.status === 'pending' || d.status === 'running')) {
            pollTimer = setTimeout(() => run({ quiet: true }), 3000); // wait for the running sync, then show the report
        } else if (syncing.value) {
            syncing.value = false;
            notice.value = '';
        }
    } catch (e) {
        error.value = e.message;
        syncing.value = false;
    } finally {
        loading.value = false;
    }
}

// Days were queued: keep checking until they are done, then the report shows.
async function onResync() {
    syncing.value = true;
    notice.value = 'Syncing… the report shows when it finishes.';
    await run({ quiet: true });
}


function exportCsv() {
    const esc = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const lines = [COLUMNS.map(([, l]) => esc(l)).join(',')]
        .concat(rows.value.map((r) => COLUMNS.map(([k]) => esc(r[k])).join(',')));
    const url = URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv' }));
    const a = Object.assign(document.createElement('a'), { href: url, download: `purchase-report_${from.value}_${to.value}.csv` });
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-semibold tracking-tight">Purchase report</h1>

        <div v-if="blocked" class="card p-6 text-sm text-slate-600">Pick a client in the top bar first.</div>

        <template v-else>
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="run()">
                <div><label class="label">From</label><input v-model="from" type="date" class="field" required></div>
                <div><label class="label">To</label><input v-model="to" type="date" class="field" required></div>
                <button class="btn-primary" :disabled="loading">{{ loading ? 'Loading…' : 'Run report' }}</button>
                <button v-if="rows.length" type="button" class="btn-ghost" @click="exportCsv">Download CSV</button>
            </form>

            <SyncDaysPanel v-if="daysSync.length" package="purchase" :days="daysSync" :syncing="syncing" :notice="notice" @queued="onResync" />

            <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

            <div v-if="loaded" class="card overflow-x-auto">
                <table class="w-full whitespace-nowrap text-left text-sm">
                    <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr><th v-for="[, label] in COLUMNS" :key="label" class="px-3 py-3">{{ label }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(r, i) in rows" :key="i">
                            <td
                                v-for="[key] in COLUMNS" :key="key" class="px-3 py-2"
                                :class="NUMERIC.has(key) && 'text-right tabular-nums'"
                            >{{ fmt(key, r[key]) }}</td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td :colspan="COLUMNS.length" class="px-4 py-6 text-center text-slate-500">No purchase orders in this period.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
