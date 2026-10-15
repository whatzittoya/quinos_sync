<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { api } from '../api';

const clients = ref([]);
const schedules = ref({ master: { run_time: '02:00', enabled: false }, reports: { run_time: '02:30', enabled: false } });
const busy = ref(false);
const editing = ref(null); // 'master' | 'reports' while its popup is open
const message = ref('');
const error = ref('');

const KINDS = {
    master: { title: 'Master data', collects: 'Departments, categories, items, sales types and contacts.' },
    reports: { title: 'Reports', collects: 'Purchase orders, goods receipts, purchase invoices and sales transactions for yesterday and today (in each client’s timezone), plus any recent day still missing.' },
};

async function load() {
    const [s, c] = await Promise.all([api('/local-runner/settings'), api('/clients')]);
    schedules.value = s.schedules;
    clients.value = c.data;
    if (c.warning) error.value = c.warning;
}

async function act(fn, done) {
    busy.value = true;
    error.value = '';
    message.value = '';
    try {
        const r = await fn();
        message.value = done ?? r?.message ?? 'Done';
        await load();
        await loadLogs();
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

const schedule = (kind, action) => act(() => api('/local-runner/scheduler', { method: 'POST', body: { kind, action, time: schedules.value[kind].run_time?.slice(0, 5) } }), `Scheduler ${action} done`);
const runNow = (mode) => act(() => api('/local-runner/runs', { method: 'POST', body: { master_only: mode === 'master', reports_only: mode === 'reports' } }), 'Run finished');
const refreshClients = () => act(() => api('/clients', { query: { refresh: 1 } }), 'Clients refreshed');

// ---- clients to automate ------------------------------------------------------

const scheduledCount = computed(() => clients.value.filter((c) => c.scheduled).length);

function saveClients() {
    return act(() => api('/local-runner/scheduler/clients', { method: 'PUT', body: { client_ids: clients.value.filter((c) => c.scheduled).map((c) => c.id) } }), 'Clients saved');
}

const allScheduled = computed({
    get: () => clients.value.length > 0 && scheduledCount.value === clients.value.length,
    set: (v) => clients.value.forEach((c) => { c.scheduled = v; }),
});

// ---- log -------------------------------------------------------------------------

const logs = ref([]);
const meta = ref({ page: 1, last_page: 1, total: 0 });
const filter = ref({ client_id: '', from: '', to: '', status: '', trigger: '' });
const logError = ref('');

async function loadLogs(page = 1) {
    logError.value = '';
    try {
        const query = { page };
        Object.entries(filter.value).forEach(([k, v]) => { if (v) query[k] = v; });
        const res = await api('/local-runner/logs', { query });
        logs.value = res.data;
        meta.value = res.meta;
    } catch (e) {
        logError.value = e.message;
    }
}

watch(filter, () => loadLogs(1), { deep: true });

// Reload the page you are on, keeping the filters.
const refreshing = ref(false);
async function refreshLogs() {
    refreshing.value = true;
    await loadLogs(meta.value.page);
    refreshing.value = false;
}

const fmt = (iso) => (iso ? new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'medium' }) : '');
const TONE = { success: 'bg-green-100 text-green-800', failed: 'bg-red-100 text-red-800', running: 'bg-sky-100 text-sky-800' };
// How long a finished step took: 45s, 3m 05s, 1h 02m.
function duration(l) {
    if (!l.started_at || !l.finished_at) return '';
    const sec = Math.max(0, Math.round((new Date(l.finished_at) - new Date(l.started_at)) / 1000));
    if (sec < 60) return `${sec}s`;
    const m = Math.floor(sec / 60);
    return m < 60 ? `${m}m ${String(sec % 60).padStart(2, '0')}s` : `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, '0')}m`;
}
const range = (dates) => (!dates?.length ? 'all' : dates.length === 1 ? dates[0] : `${dates[0]} → ${dates[dates.length - 1]}`);

onMounted(async () => {
    try { await load(); } catch (e) { error.value = e.message; }
    await loadLogs();
});
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-semibold tracking-tight">Scheduler</h1>

        <p v-if="message" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-800">{{ message }}</p>
        <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

        <section class="card flex flex-wrap items-center gap-x-3 gap-y-2 p-3 text-sm">
            <strong>Run now</strong>
            <span class="text-xs text-slate-500">Every client, right away. For one client or specific days use Sync.</span>
            <div class="ml-auto flex gap-1.5">
                <button class="btn-primary !px-2.5 !py-1" :disabled="busy" @click="runNow('all')">Sync all</button>
                <button class="btn-ghost !px-2.5 !py-1" :disabled="busy" @click="runNow('master')">Master data</button>
                <button class="btn-ghost !px-2.5 !py-1" :disabled="busy" @click="runNow('reports')">Reports</button>
            </div>
        </section>

        <div class="grid gap-4 text-sm lg:grid-cols-3">
            <section class="card space-y-3 p-3 lg:col-span-2">
                <div>
                    <h2 class="font-semibold">Automatic sync</h2>
                </div>
                <div v-for="(sch, kind) in schedules" :key="kind" class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-slate-200 p-2.5">
                    <div class="min-w-60 flex-1">
                        <strong>{{ KINDS[kind].title }}</strong>
                        <span class="ml-2 rounded-full px-1.5 py-0.5 text-xs" :class="sch.enabled ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-600'">{{ sch.enabled ? 'Enabled' : 'Disabled' }}</span>
                        <div class="text-xs text-slate-500">{{ KINDS[kind].collects }}</div>
                        <div v-if="sch.last_error" class="text-xs text-red-600">{{ sch.last_error }}</div>
                    </div>
                    <div class="text-xs text-slate-600">Daily at <strong class="text-sm tabular-nums">{{ sch.run_time }}</strong></div>
                    <button class="btn-ghost !px-2.5 !py-1" @click="editing = kind">Edit</button>
                </div>
            </section>
            <section class="card space-y-2 p-3">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-semibold">Clients</h2>
                    <button class="btn-ghost !px-2.5 !py-1" :disabled="busy" @click="refreshClients">Refresh</button>
                </div>
                <p class="text-xs text-slate-500">Only checked clients are synced by the daily tasks.</p>
                <p v-if="!clients.length" class="text-xs text-slate-500">None yet. Check the Connection page, then refresh.</p>
                <template v-else>
                    <label class="flex items-center gap-2 border-b border-slate-100 pb-2 text-xs font-medium"><input v-model="allScheduled" type="checkbox"> All ({{ scheduledCount }}/{{ clients.length }})</label>
                    <div class="max-h-64 space-y-1 overflow-y-auto">
                        <label v-for="c in clients" :key="c.id" class="flex items-center gap-2 rounded px-1 py-0.5 hover:bg-slate-50"><input v-model="c.scheduled" type="checkbox"> {{ c.name }}</label>
                    </div>
                    <button class="btn-primary !px-2.5 !py-1" :disabled="busy" @click="saveClients">Save clients</button>
                </template>
            </section>
        </div>

        <div v-if="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" @click.self="editing = null" @keydown.esc="editing = null">
            <div class="card w-full max-w-sm space-y-4 p-4 text-sm" role="dialog" aria-modal="true" :aria-label="`Edit ${KINDS[editing].title} task`">
                <div>
                    <h2 class="font-semibold">{{ KINDS[editing].title }} task</h2>
                    <p class="text-xs text-slate-500">{{ KINDS[editing].collects }}</p>
                </div>
                <label class="block text-xs text-slate-600">Run every day at
                    <input v-model="schedules[editing].run_time" class="field mt-1" type="time" step="60">
                </label>
                <div class="text-xs text-slate-500">Status: <strong>{{ schedules[editing].enabled ? 'Enabled' : 'Disabled' }}</strong></div>
                <div class="flex flex-wrap gap-1.5">
                    <button class="btn-primary !px-2.5 !py-1" :disabled="busy" @click="schedule(editing, 'create')">Create / update</button>
                    <button class="btn-ghost !px-2.5 !py-1" :disabled="busy" @click="schedule(editing, 'enable')">Enable</button>
                    <button class="btn-ghost !px-2.5 !py-1" :disabled="busy" @click="schedule(editing, 'disable')">Disable</button>
                    <button class="btn-danger !px-2.5 !py-1" :disabled="busy" @click="schedule(editing, 'delete')">Remove</button>
                </div>
                <p v-if="error" class="rounded-md bg-red-50 px-2 py-1 text-xs text-red-700">{{ error }}</p>
                <p v-else-if="message" class="rounded-md bg-green-50 px-2 py-1 text-xs text-green-800">{{ message }}</p>
                <div class="text-right"><button class="btn-ghost !px-2.5 !py-1" @click="editing = null">Close</button></div>
            </div>
        </div>

        <section class="space-y-3">
            <h2 class="text-lg font-semibold">Run log</h2>
            <div class="flex flex-wrap items-end gap-3">
                <label class="text-xs text-slate-500">Client
                    <select v-model="filter.client_id" class="field !w-auto"><option value="">All clients</option><option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                </label>
                <label class="text-xs text-slate-500">From <input v-model="filter.from" type="date" class="field !w-auto"></label>
                <label class="text-xs text-slate-500">To <input v-model="filter.to" type="date" class="field !w-auto"></label>
                <label class="text-xs text-slate-500">Status
                    <select v-model="filter.status" class="field !w-auto"><option value="">Any</option><option value="success">Success</option><option value="failed">Failed</option><option value="running">Running</option></select>
                </label>
                <label class="text-xs text-slate-500">Started by
                    <select v-model="filter.trigger" class="field !w-auto"><option value="">Any</option><option value="scheduled">Scheduler</option><option value="manual">Manual</option></select>
                </label>
                <button class="btn-ghost" @click="filter = { client_id: '', from: '', to: '', status: '', trigger: '' }">Clear</button>
                <button class="btn-ghost ml-auto" :disabled="refreshing" @click="refreshLogs">{{ refreshing ? 'Refreshing…' : 'Refresh' }}</button>
            </div>

            <p v-if="logError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ logError }}</p>

            <div class="card overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr><th class="p-3">Started</th><th class="p-3">Client</th><th class="p-3">Data</th><th class="p-3">Days</th><th class="p-3">By</th><th class="p-3">Status</th><th class="p-3 text-right">Rows</th><th class="p-3">Finished</th><th class="p-3 text-right">Duration</th><th class="p-3">Error</th></tr>
                    </thead>
                    <tbody>
                        <tr v-if="!logs.length"><td colspan="10" class="p-6 text-center text-slate-500">Nothing has run yet.</td></tr>
                        <tr v-for="l in logs" :key="l.id" class="border-t border-slate-100 align-top">
                            <td class="whitespace-nowrap p-3">{{ fmt(l.started_at) }}</td>
                            <td class="p-3">{{ l.client }}</td>
                            <td class="p-3 capitalize">{{ l.package }}</td>
                            <td class="whitespace-nowrap p-3">{{ range(l.dates) }}</td>
                            <td class="p-3 capitalize">{{ l.trigger === 'scheduled' ? 'scheduler' : l.trigger }}</td>
                            <td class="p-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="TONE[l.status] ?? 'bg-slate-100 text-slate-600'">{{ l.status }}</span></td>
                            <td class="p-3 text-right tabular-nums">{{ l.rows }}</td>
                            <td class="whitespace-nowrap p-3">{{ fmt(l.finished_at) }}</td>
                            <td class="whitespace-nowrap p-3 text-right tabular-nums">{{ duration(l) }}</td>
                            <td class="max-w-xs p-3 text-red-700">{{ l.error }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between text-sm text-slate-500">
                <span>{{ meta.total }} entr{{ meta.total === 1 ? 'y' : 'ies' }}</span>
                <div class="flex items-center gap-2">
                    <button class="btn-ghost" :disabled="meta.page <= 1" @click="loadLogs(meta.page - 1)">Previous</button>
                    <span>Page {{ meta.page }} of {{ meta.last_page }}</span>
                    <button class="btn-ghost" :disabled="meta.page >= meta.last_page" @click="loadLogs(meta.page + 1)">Next</button>
                </div>
            </div>
        </section>
    </div>
</template>
