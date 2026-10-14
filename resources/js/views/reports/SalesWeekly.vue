<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { api, download } from '../../api';
import SyncDaysPanel from '../../components/SyncDaysPanel.vue';
import { useAuth } from '../../stores/auth';

const auth = useAuth();
const iso = (d) => d.toISOString().slice(0, 10);
const today = new Date();
const from = ref(iso(new Date(today.getTime() - 6 * 86400000)));
const to = ref(iso(today));

const report = ref(null);
const missing = ref([]);
const daysSync = ref([]);
const loading = ref(false);
const syncing = ref(false);
const notice = ref('');
const error = ref('');
const blocked = computed(() => auth.isAdmin && !auth.activeClient);

const TYPE_LABEL = { 'DINE IN': 'Dine In', GOJEK: 'Gojek', GRAB: 'Grab', 'TAKE AWAY': 'Takeaway', 'EVENT/CATERING': 'Event/Catering' };
const types = computed(() => report.value?.types ?? []);
const busy = (d) => d.status === 'pending' || d.status === 'running';

const n = (v) => Number(v ?? 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
const when = (date) => new Date(`${date}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });

let pollTimer;
onBeforeUnmount(() => clearTimeout(pollTimer));

async function run({ quiet = false } = {}) {
    clearTimeout(pollTimer);
    if (!quiet) { loading.value = true; notice.value = ''; }
    error.value = '';
    try {
        const res = await api('/reports/sales-weekly', { query: { from: from.value, to: to.value } });
        missing.value = res.missing;
        daysSync.value = res.days_sync;
        report.value = res.missing.length ? null : res;
        if (res.missing.some(busy)) {
            pollTimer = setTimeout(() => run({ quiet: true }), 3000); // wait for the running sync, then show the report
        } else {
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

const exporting = ref(false);

async function exportExcel() {
    exporting.value = true;
    error.value = '';
    try {
        await download('/reports/sales-weekly/export', { query: { from: from.value, to: to.value } });
    } catch (e) {
        error.value = e.message;
    } finally {
        exporting.value = false;
    }
}
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-semibold tracking-tight">Weekly summary sales</h1>

        <div v-if="blocked" class="card p-6 text-sm text-slate-600">Pick a client in the top bar first.</div>

        <template v-else>
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="run()">
                <div><label class="label">From</label><input v-model="from" type="date" class="field" required></div>
                <div><label class="label">To</label><input v-model="to" type="date" class="field" required></div>
                <button class="btn-primary" :disabled="loading">{{ loading ? 'Loading…' : 'Run report' }}</button>
                <button v-if="report" type="button" class="btn-ghost" :disabled="exporting" @click="exportExcel">{{ exporting ? 'Preparing…' : 'Download Excel' }}</button>
            </form>

            <SyncDaysPanel v-if="daysSync.length" package="sales" :days="daysSync" :syncing="syncing" :notice="notice" @queued="onResync" />

            <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

            <template v-if="report">
                <div v-if="report.warnings.unassigned || Object.keys(report.warnings.excluded_types).length || report.warnings.unknown_codes.length"
                     class="space-y-1 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <p v-if="report.warnings.unassigned">{{ report.warnings.unassigned }} transaction(s) fall outside every shift (see Shifts) and are not counted.</p>
                    <p v-for="(count, type) in report.warnings.excluded_types" :key="type">{{ count }} {{ type }} transaction(s) are left out of this report.</p>
                    <p v-if="report.warnings.unknown_codes.length">Items not found in the master data (not counted in Food/Beverage): {{ report.warnings.unknown_codes.join(', ') }}. Sync master data from the Master pages.</p>
                </div>

                <template v-for="block in [...report.days.map((d) => ({ title: when(d.date), rows: d.rows, key: d.date })), { title: `Whole period · ${from} to ${to}`, rows: report.total.rows, key: 'total' }]" :key="block.key">
                    <div v-if="block.key === 'total'" class="flex items-center gap-3 pt-4 text-xs font-medium uppercase tracking-wide text-slate-500">
                        <span class="h-px flex-1 bg-slate-300" />
                        <span>Whole period</span>
                        <span class="h-px flex-1 bg-slate-300" />
                    </div>
                    <div class="card overflow-x-auto" :class="block.key === 'total' && '!border-slate-400'">
                        <table class="w-full whitespace-nowrap text-right text-sm tabular-nums">
                            <thead class="text-xs uppercase tracking-wide text-slate-500">
                                <tr class="divide-x divide-slate-200 border-b border-slate-200">
                                    <th rowspan="2" class="px-3 py-2 text-left font-semibold normal-case text-slate-800">{{ block.title }}</th>
                                    <th :colspan="types.length + 1" class="border-l border-slate-200 px-3 py-2 text-center">Cover / pax</th>
                                    <th :colspan="types.length + 1" class="border-l border-slate-200 px-3 py-2 text-center">Revenue</th>
                                    <th colspan="5" class="border-l border-slate-200 px-3 py-2 text-center">Sales mix</th>
                                </tr>
                                <tr class="divide-x divide-slate-200 border-b border-slate-200">
                                    <th v-for="(t, i) in types" :key="`c${t}`" class="px-3 py-2" :class="i === 0 && 'border-l border-slate-200'">{{ TYPE_LABEL[t] }}</th>
                                    <th class="bg-slate-100 px-3 py-2">Total</th>
                                    <th v-for="(t, i) in types" :key="`r${t}`" class="px-3 py-2" :class="i === 0 && 'border-l border-slate-200'">{{ TYPE_LABEL[t] }}</th>
                                    <th class="bg-slate-100 px-3 py-2">Total</th>
                                    <th class="border-l border-slate-200 px-3 py-2">Food</th>
                                    <th class="px-3 py-2">Beverage</th>
                                    <th class="px-3 py-2">F&amp;B</th>
                                    <th class="px-3 py-2">Others</th>
                                    <th class="px-3 py-2">Avg check</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="r in block.rows" :key="r.label" class="divide-x divide-slate-200" :class="r.label === 'TOTAL' && 'bg-slate-50 font-semibold'">
                                    <td class="px-3 py-2 text-left">{{ r.label }}</td>
                                    <td v-for="(t, i) in types" :key="`c${t}`" class="px-3 py-2" :class="i === 0 && 'border-l border-slate-200'">{{ n(r.cover[t]) }}</td>
                                    <td class="bg-slate-50 px-3 py-2 font-medium">{{ n(r.cover.total) }}</td>
                                    <td v-for="(t, i) in types" :key="`r${t}`" class="px-3 py-2" :class="i === 0 && 'border-l border-slate-200'">{{ n(r.revenue[t]) }}</td>
                                    <td class="bg-slate-50 px-3 py-2 font-medium">{{ n(r.revenue.total) }}</td>
                                    <td class="border-l border-slate-200 px-3 py-2">{{ n(r.food) }}</td>
                                    <td class="px-3 py-2">{{ n(r.beverage) }}</td>
                                    <td class="px-3 py-2">{{ n(r.fnb) }}</td>
                                    <td class="px-3 py-2">{{ n(r.others) }}</td>
                                    <td class="px-3 py-2">{{ n(r.average_check) }}</td>
                                </tr>
                                <tr v-if="!block.rows.length">
                                    <td :colspan="types.length * 2 + 8" class="px-3 py-4 text-center text-slate-500">No sales.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </template>
        </template>
    </div>
</template>
