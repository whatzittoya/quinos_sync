<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api, ApiError, selectedClientId } from '../api';

const clients = ref([]);
const clientId = ref(selectedClientId.get() ?? '');

const month = ref(null); // 'YYYY-MM'
const calendar = ref(null);
const loading = ref(true);
const error = ref('');
const notice = ref('');
const selected = ref([]); // picked reports of days: 'YYYY-MM-DD|package'
const anchor = ref(null); // last clicked day, for shift-click ranges
const busy = ref(false);
let timer = null;

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const needsClient = computed(() => !clientId.value);
const today = computed(() => calendar.value?.today ?? null);
const packages = computed(() => calendar.value?.packages ?? []);

// ---- month grid ---------------------------------------------------------

const pad = (n) => String(n).padStart(2, '0');

function monthOf(dateStr) { return dateStr.slice(0, 7); }

function shiftMonth(ym, delta) {
    const [y, m] = ym.split('-').map(Number);
    const d = new Date(Date.UTC(y, m - 1 + delta, 1));
    return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}`;
}

const monthLabel = computed(() => {
    if (!month.value) return '';
    const [y, m] = month.value.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, 1)).toLocaleString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });
});

const cells = computed(() => {
    if (!month.value) return [];
    const [y, m] = month.value.split('-').map(Number);
    const lead = (new Date(Date.UTC(y, m - 1, 1)).getUTCDay() + 6) % 7; // Monday first
    const count = new Date(Date.UTC(y, m, 0)).getUTCDate();
    return [
        ...Array.from({ length: lead }, () => null),
        ...Array.from({ length: count }, (_, i) => `${month.value}-${pad(i + 1)}`),
    ];
});

const isFuture = (date) => today.value !== null && date > today.value;

// ---- day state ------------------------------------------------------------

function entriesOf(date) {
    return packages.value.map((p) => calendar.value?.days?.[date]?.[p]);
}

function stateOf(date) {
    const e = entriesOf(date);
    if (e.some((x) => x?.status === 'failed')) return 'failed';
    if (e.some((x) => x && ['pending', 'running'].includes(x.status))) return 'busy';
    if (e.length && e.every((x) => x?.status === 'done')) return e.some((x) => x.partial) ? 'partial' : 'done';
    if (e.some((x) => x?.status === 'done')) return 'partial';
    return 'none';
}

const STATE_LABEL = { done: 'Synced', partial: 'Synced, day not finished', failed: 'Failed', busy: 'Waiting / running', none: 'Not synced' };

// One pill per report (data package) inside each day: green = synced, gray = not synced.
const PILL_CLASS = {
    done: 'bg-green-600 text-white',
    failed: 'bg-red-600 text-white',
    pending: 'bg-sky-500 text-white',
    running: 'bg-sky-500 text-white',
    none: 'bg-slate-300 text-slate-600',
};
const PILL_LABEL = { done: 'synced', failed: 'failed', pending: 'waiting', running: 'syncing', none: 'not synced' };
const pillState = (entry) => entry?.status ?? 'none';
const pillTitle = (date, i) => `${packages.value[i]}: ${PILL_LABEL[pillState(entriesOf(date)[i])]}`;
const cap = (word) => word.charAt(0).toUpperCase() + word.slice(1);

const anyBusy = computed(() => cells.value.some((d) => d && stateOf(d) === 'busy'));

// Selection works on pills: one (day, report) pair each. Clicking a pill picks just that report; clicking the day picks all of them.
const keyOf = (date, p) => `${date}|${p}`;
const isPicked = (date, p) => selected.value.includes(keyOf(date, p));
const dayPicked = (date) => packages.value.length > 0 && packages.value.every((p) => isPicked(date, p));

const syncableSelection = computed(() => selected.value.filter((k) => !isFuture(k.split('|')[0])));

// The newest sync time among the selected days (whichever report it was).
const lastSyncedSelected = computed(() => {
    const times = [...new Set(selected.value.map((k) => k.split('|')[0]))]
        .flatMap((date) => entriesOf(date).map((e) => e?.synced_at))
        .filter(Boolean)
        .sort();
    return times.length ? times[times.length - 1] : null;
});

// package => its picked dates, for one API call per report
const selectionByReport = computed(() => {
    const groups = {};
    syncableSelection.value.forEach((k) => {
        const [date, p] = k.split('|');
        (groups[p] ??= []).push(date);
    });
    return Object.entries(groups).map(([p, dates]) => [dates.sort(), [p]]);
});

// ---- loading --------------------------------------------------------------

async function load({ quiet = false } = {}) {
    if (needsClient.value) { loading.value = false; return; }
    if (!quiet) loading.value = true;
    error.value = '';
    try {
        const data = await api('/sync/calendar', { query: { month: month.value ?? currentMonthGuess() } });
        calendar.value = data;
        month.value ??= monthOf(data.today);
        if (!rangeTo.value) {
            rangeTo.value = data.today;
            rangeFrom.value = lastDays(7)[0];
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
        schedulePoll();
    }
}

// Before the first answer we only know the browser's date; the server's "today" (client timezone) replaces it.
function currentMonthGuess() {
    const now = new Date();
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}`;
}

function schedulePoll() {
    clearTimeout(timer);
    if (anyBusy.value) timer = setTimeout(() => load({ quiet: true }), 3000);
}

function goToMonth(delta) {
    month.value = shiftMonth(month.value, delta);
    selected.value = [];
    anchor.value = null;
    load();
}

function goToToday() {
    month.value = monthOf(today.value);
    selected.value = packages.value.map((p) => keyOf(today.value, p));
    anchor.value = today.value;
    load();
}

// ---- selecting ------------------------------------------------------------

// A plain click replaces the selection (click the only selected one again to clear it).
// Cmd/Ctrl-click adds to or removes from it; Shift-click selects a range of days.
const additive = (event) => event.metaKey || event.ctrlKey;

function pick(date, event) {
    if (isFuture(date)) return;

    if (event.shiftKey && anchor.value && monthOf(anchor.value) === monthOf(date)) {
        const [a, b] = [anchor.value, date].sort();
        const range = cells.value.filter((d) => d && d >= a && d <= b && !isFuture(d));
        selected.value = range.flatMap((d) => packages.value.map((p) => keyOf(d, p)));
        return;
    }

    const keys = packages.value.map((p) => keyOf(date, p));
    const others = selected.value.filter((k) => !keys.includes(k));

    if (dayPicked(date) && (additive(event) || others.length === 0)) {
        selected.value = others; // unselect this day
    } else {
        selected.value = additive(event) ? [...others, ...keys] : keys;
    }

    anchor.value = date;
}

// Clicking a pill selects just that report of that day; nothing is synced until a Sync button is pressed.
function pickPill(date, p, event) {
    if (isFuture(date)) return;

    const key = keyOf(date, p);
    const others = selected.value.filter((k) => k !== key);

    if (isPicked(date, p) && (additive(event) || others.length === 0)) {
        selected.value = others;
    } else {
        selected.value = additive(event) ? [...others, key] : [key];
    }

    anchor.value = date;
}

function lastDays(count) {
    const end = new Date(`${today.value}T00:00:00Z`);
    return Array.from({ length: count }, (_, i) => {
        const d = new Date(end.getTime() - i * 86400000);
        return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
    }).sort();
}

const missingThisMonth = computed(() => cells.value.filter((d) => d && !isFuture(d) && ['none', 'failed'].includes(stateOf(d))));

// ---- actions ----------------------------------------------------------------

const waitingCount = computed(() => cells.value.filter((d) => d && stateOf(d) === 'busy').length);

// Start a background worker for what is waiting.
async function runNow() {
    busy.value = true;
    error.value = notice.value = '';
    try {
        const res = await api('/sync/run-now', { method: 'POST' });
        if (res.running) notice.value = 'Started. The waiting days will sync now.';
        else error.value = res.message;
        await load({ quiet: true });
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

// Give up on waiting days (they go back to "not synced" and can be queued again).
async function cancelWaiting() {
    busy.value = true;
    error.value = notice.value = '';
    try {
        const res = await api('/sync/cancel', { method: 'POST' });
        notice.value = `Cancelled ${res.cancelled_days} waiting entr${res.cancelled_days === 1 ? 'y' : 'ies'}.`
            + (res.still_running ? ` ${res.still_running} still syncing right now.` : '');
        await load({ quiet: true });
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

// Reports (data packages) used by "last 7 days", "missing days" and the date range. null = all.
const reportsPicked = ref(null);
const pickedReports = computed(() => reportsPicked.value ?? packages.value);
const noReports = computed(() => pickedReports.value.length === 0);

function toggleReport(p) {
    const now = pickedReports.value;
    reportsPicked.value = now.includes(p) ? now.filter((x) => x !== p) : packages.value.filter((x) => x === p || now.includes(x));
}

async function queue(dates, { force = false, reports = pickedReports.value } = {}) {
    if (!dates.length || !reports.length) return;
    await queueGroups([[dates, reports]], force);
}

/** @param {Array<[string[], string[]]>} groups  [dates, reports] pairs, one API call each */
async function queueGroups(groups, force = false) {
    if (!groups.length) return;
    busy.value = true;
    error.value = notice.value = '';
    let queued = 0;
    let skipped = 0;
    try {
        for (const [dates, reports] of groups) {
            const result = await api('/sync/days', { method: 'POST', body: { dates, force, packages: reports } });
            queued += result.queued.length;
            skipped += result.skipped.length;
        }
        notice.value = `Queued ${queued} day(s)`
            + (skipped ? `, ${skipped} already waiting` : '')
            + '. It starts shortly.';
        await load({ quiet: true });
    } catch (e) {
        error.value = e instanceof ApiError && Object.keys(e.errors).length
            ? Object.values(e.errors).flat().join(' ')
            : e.message;
    } finally {
        busy.value = false;
    }
}

// ---- custom range ---------------------------------------------------------

const rangeFrom = ref('');
const rangeTo = ref('');
const rangeForce = ref(false);

const rangeDates = computed(() => {
    if (!rangeFrom.value || !rangeTo.value || !today.value) return [];
    const end = new Date(`${rangeTo.value < today.value ? rangeTo.value : today.value}T00:00:00Z`);
    const out = [];
    for (let d = new Date(`${rangeFrom.value}T00:00:00Z`); d <= end; d = new Date(d.getTime() + 86400000)) {
        out.push(`${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`);
    }
    return out;
});
const rangeTooLong = computed(() => rangeDates.value.length > 62);

// ---- reference data -------------------------------------------------------

const master = computed(() => calendar.value?.master ?? []);
const masterSelected = ref([]); // kind keys
const masterBusy = ref(false);
let masterTimer = null;

const toggleMaster = (key) => {
    masterSelected.value = masterSelected.value.includes(key)
        ? masterSelected.value.filter((k) => k !== key)
        : [...masterSelected.value, key];
};

// Queue a refresh of the chosen kinds (null = all), then reload until their "last refreshed" moves.
async function refreshMaster(kinds = null) {
    masterBusy.value = true;
    error.value = notice.value = '';
    const keys = kinds ?? master.value.map((k) => k.key);
    const before = Object.fromEntries(master.value.map((k) => [k.key, k.synced_at]));
    const changed = () => keys.every((key) => master.value.find((k) => k.key === key)?.synced_at !== before[key]);

    try {
        await api('/sync/master', { method: 'POST', body: kinds ? { kinds } : {} });
        notice.value = 'Refreshing reference data…';
        for (let i = 0; i < 60 && masterBusy.value; i++) {
            await new Promise((resolve) => { masterTimer = setTimeout(resolve, 3000); });
            await load({ quiet: true });
            if (changed()) {
                notice.value = 'Reference data refreshed.';
                masterSelected.value = [];
                return;
            }
        }
        notice.value = 'Still waiting. It runs once the queue worker picks it up.';
    } catch (e) {
        error.value = e instanceof ApiError && Object.keys(e.errors).length
            ? Object.values(e.errors).flat().join(' ')
            : e.message;
    } finally {
        masterBusy.value = false;
    }
}

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : '—');

let ready = false;

watch(clientId, (id) => {
    if (!ready) return;
    selectedClientId.set(id || null);
    calendar.value = null;
    selected.value = [];
    month.value = null;
    error.value = '';
    notice.value = '';
    pullStatus().then(() => load());
});

// Days the hosted app already synced show as synced here too. Quiet: if hosted cannot be reached the calendar still works.
async function pullStatus() {
    try { await api('/sync/pull-status', { method: 'POST' }); } catch { /* ignore */ }
}

async function loadClients() {
    try {
        const res = await api('/clients');
        clients.value = res.data;
        if (res.warning) notice.value = res.warning;
        // A remembered client that no longer exists, or none picked yet: start with the first one.
        if (!clients.value.some((c) => String(c.id) === String(clientId.value))) clientId.value = clients.value[0]?.id ?? '';
    } catch (e) {
        error.value = e.message;
    }
}
onMounted(async () => {
    await loadClients();
    selectedClientId.set(clientId.value || null);
    ready = true;
    if (clientId.value) await pullStatus();
    await load();
});
onBeforeUnmount(() => { clearTimeout(timer); masterBusy.value = false; clearTimeout(masterTimer); });
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
            <h1 class="text-2xl font-semibold tracking-tight">Sync</h1>
            <p class="text-sm text-slate-500">
                Data is copied from Quinos one day at a time. Green days are done. Pick days and sync them, or re-sync a day to read it again from scratch.
            </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <select v-model="clientId" class="field !w-auto" aria-label="Client">
                    <option value="" disabled>Select client…</option>
                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>
        </div>

        <p v-if="needsClient" class="card p-6 text-sm text-slate-600">{{ clients.length ? 'Select a client first.' : 'No clients yet. Check the Connection page, then reload.' }}</p>

        <template v-else>
            <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
            <p v-if="notice" class="rounded-md bg-sky-50 px-3 py-2 text-sm text-sky-800">{{ notice }}</p>

            <div v-if="waitingCount" class="flex flex-wrap items-center gap-3 rounded-md border border-sky-200 bg-white px-3 py-2 text-sm">
                <span class="text-slate-700">{{ waitingCount }} day{{ waitingCount > 1 ? 's' : '' }} waiting or syncing in this month.<template v-if="calendar?.progress"> Now: {{ calendar.progress }}.</template></span>
                <button class="btn-ghost" :disabled="busy" @click="runNow">Run now</button>
                <button class="btn-ghost" :disabled="busy" @click="cancelWaiting">Cancel waiting</button>
            </div>

            <div class="grid gap-4 lg:grid-cols-[1fr_20rem]">
                <section class="card p-4">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <button class="btn-ghost" :disabled="!month" aria-label="Previous month" @click="goToMonth(-1)">‹</button>
                        <h2 class="min-w-40 text-center font-medium">{{ monthLabel }}</h2>
                        <button class="btn-ghost" :disabled="!month" aria-label="Next month" @click="goToMonth(1)">›</button>
                        <button class="btn-ghost ml-auto" :disabled="!today" @click="goToToday">Today</button>
                    </div>

                    <div class="grid grid-cols-7 gap-1 text-center text-xs text-slate-500">
                        <div v-for="w in WEEKDAYS" :key="w" class="py-1">{{ w }}</div>
                    </div>

                    <div v-if="loading && !calendar" class="py-16 text-center text-sm text-slate-500">Loading…</div>

                    <div v-else class="grid grid-cols-7 gap-1">
                        <template v-for="(date, i) in cells" :key="i">
                            <div v-if="date === null" />
                            <button
                                v-else
                                type="button"
                                :disabled="isFuture(date)"
                                :title="`${date}: ${STATE_LABEL[stateOf(date)]}`"
                                class="relative flex aspect-square flex-col justify-center rounded-md border p-1 text-sm transition disabled:cursor-not-allowed disabled:opacity-40"
                                :class="dayPicked(date) ? 'border-indigo-300 bg-indigo-100 text-indigo-900' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
                                @click="pick(date, $event)"
                            >
                                <span class="absolute left-1.5 top-1 flex items-baseline gap-1 leading-none">
                                    <span class="text-base font-semibold">{{ Number(date.slice(8)) }}</span>
                                    <span v-if="date === today" class="text-[11px] uppercase tracking-wide">today</span>
                                </span>
                                <span v-if="!isFuture(date)" class="flex w-full flex-col gap-0.5">
                                    <span
                                        v-for="(p, pi) in packages" :key="p"
                                        role="button"
                                        :title="`${pillTitle(date, pi)}. Click to select`"
                                        class="block w-full rounded-sm py-0.5 text-center text-xs font-medium leading-4 hover:brightness-110 sm:text-sm"
                                        :class="[PILL_CLASS[pillState(entriesOf(date)[pi])], isPicked(date, p) && !dayPicked(date) && '!bg-indigo-600 !text-white']"
                                        @click.stop="pickPill(date, p, $event)"
                                    ><span class="sm:hidden">{{ cap(p).charAt(0) }}</span><span class="hidden sm:inline">{{ cap(p) }}</span></span>
                                </span>
                            </button>
                        </template>
                    </div>

                    <ul class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
                        <li class="text-slate-500">Each day shows its reports:</li>
                        <li v-for="key in ['done', 'none', 'busy', 'failed']" :key="key" class="flex items-center gap-1.5">
                            <span class="inline-block h-3 w-5 rounded-sm" :class="PILL_CLASS[key === 'busy' ? 'pending' : key]" />
                            {{ key === 'busy' ? 'waiting / syncing' : PILL_LABEL[key] }}
                        </li>
                    </ul>
                    <p class="mt-2 text-sm text-slate-500">
                        <template v-if="!selected.length">Select a day to see when it was last synced.</template>
                        <template v-else>Last synced: <strong class="font-medium text-slate-700">{{ lastSyncedSelected ? fmt(lastSyncedSelected) : 'not synced yet' }}</strong></template>
                    </p>
                </section>

                <aside class="space-y-4">
                    <section class="card space-y-2 p-4">
                        <h2 class="font-medium">Sync</h2>
                        <button class="btn-primary w-full" :disabled="busy || !syncableSelection.length" @click="queueGroups(selectionByReport)">
                            Sync {{ syncableSelection.length || '' }} selected
                        </button>
                        <button class="btn-ghost w-full" :disabled="busy || !syncableSelection.length" @click="queueGroups(selectionByReport, true)">
                            Re-sync selected (full)
                        </button>
                        <hr class="border-slate-200">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            <span class="text-slate-500">Reports for the buttons below and the date range:</span>
                            <label v-for="p in packages" :key="p" class="flex cursor-pointer items-center gap-1.5">
                                <input type="checkbox" class="rounded border-slate-300" :checked="pickedReports.includes(p)" @change="toggleReport(p)">
                                {{ cap(p) }}
                            </label>
                        </div>
                        <button class="btn-ghost w-full" :disabled="busy || noReports || !today" @click="queue(lastDays(7))">Sync last 7 days</button>
                        <button class="btn-ghost w-full" :disabled="busy || noReports || !missingThisMonth.length" @click="queue(missingThisMonth)">
                            Sync {{ missingThisMonth.length }} missing day(s) this month
                        </button>
                        <p class="text-xs text-slate-500">
                            A full re-sync reads the Quinos lists from the beginning and re-downloads every document. Use it if something looks wrong.
                        </p>
                    </section>

                    <section class="card space-y-2 p-4 text-sm">
                        <h2 class="font-medium">Custom date range</h2>
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="label">From</label><input v-model="rangeFrom" type="date" class="field" :max="today"></div>
                            <div><label class="label">To</label><input v-model="rangeTo" type="date" class="field" :max="today"></div>
                        </div>
                        <label class="flex cursor-pointer items-center gap-2">
                            <input v-model="rangeForce" type="checkbox" class="rounded border-slate-300"> Re-sync even if already synced
                        </label>
                        <button
                            class="btn-primary w-full"
                            :disabled="busy || noReports || !rangeDates.length || rangeTooLong"
                            @click="queue(rangeDates, { force: rangeForce })"
                        >Sync {{ rangeDates.length || '' }} {{ rangeDates.length === 1 ? 'day' : 'days' }}</button>
                        <p v-if="rangeTooLong" class="text-red-600">Pick at most 62 days at a time.</p>
                        <p v-else class="text-xs text-slate-500">Uses the reports ticked in the Sync card. Days after today are skipped.</p>
                    </section>

                </aside>
            </div>

            <section class="card space-y-3 p-4 text-sm">
                <div>
                    <h2 class="font-medium">Reference data</h2>
                    <p class="text-slate-500">Items, departments and the like. Reports use them, for example to split sales into Food and Beverage.</p>
                </div>
                <table class="w-full text-left">
                    <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="w-10 px-3 py-2">
                                <input
                                    type="checkbox" class="rounded border-slate-300" aria-label="Select all"
                                    :checked="master.length > 0 && masterSelected.length === master.length"
                                    :disabled="masterBusy"
                                    @change="masterSelected = $event.target.checked ? master.map((k) => k.key) : []"
                                >
                            </th>
                            <th class="px-3 py-2">Data</th>
                            <th class="px-3 py-2">Last refreshed</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="k in master" :key="k.key" class="cursor-pointer hover:bg-slate-50" @click="!masterBusy && toggleMaster(k.key)">
                            <td class="px-3 py-2">
                                <input
                                    type="checkbox" class="rounded border-slate-300"
                                    :checked="masterSelected.includes(k.key)" :disabled="masterBusy" @click.stop @change="toggleMaster(k.key)"
                                >
                            </td>
                            <td class="px-3 py-2 font-medium">{{ k.label }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ fmt(k.synced_at) }}</td>
                        </tr>
                    </tbody>
                </table>
                <div class="flex flex-wrap items-center gap-2">
                    <button class="btn-ghost" :disabled="masterBusy || !masterSelected.length" @click="refreshMaster(masterSelected)">
                        Sync selected{{ masterSelected.length ? ` (${masterSelected.length})` : '' }}
                    </button>
                    <button class="btn-primary" :disabled="masterBusy" @click="refreshMaster()">Sync all</button>
                    <span v-if="masterBusy" class="text-slate-500">Syncing…</span>
                </div>
            </section>
        </template>
    </div>
</template>
