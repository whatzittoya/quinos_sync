<script setup>
// One list of the report's days: a checkbox and the sync status of each. Sync the missing ones, or tick days to sync again.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api, ApiError } from '../api';

const props = defineProps({
    days: { type: Array, required: true }, // [{ date, status }]
    package: String, // the one report these days belong to
    syncing: Boolean,
    notice: String,
});
const emit = defineEmits(['queued']);

const picked = ref([]);
const popup = ref(false); // the day list as a popup, used when nothing is missing
const busy = ref(false);
const error = ref('');

const STATUS = {
    done: ['Synced', 'text-green-700'],
    pending: ['Waiting to sync', 'text-slate-500'],
    running: ['Syncing…', 'text-slate-500'],
    failed: ['Last sync failed', 'text-red-600'],
};
const statusOf = (d) => STATUS[d.status] ?? ['Not synced', 'text-amber-700'];
const isBusy = (d) => d.status === 'pending' || d.status === 'running';

const missing = computed(() => props.days.filter((d) => d.status !== 'done'));
const toQueue = computed(() => missing.value.filter((d) => !isBusy(d)).map((d) => d.date));
const showList = computed(() => missing.value.length > 0 || popup.value);
const asPopup = computed(() => missing.value.length === 0 && popup.value);
const disabled = computed(() => busy.value || props.syncing);

watch(() => props.days, (list) => {
    const dates = list.map((d) => d.date);
    picked.value = picked.value.filter((d) => dates.includes(d));
});

const toggle = (date) => {
    picked.value = picked.value.includes(date) ? picked.value.filter((d) => d !== date) : [...picked.value, date].sort();
};

const anyBusy = computed(() => props.days.some(isBusy));

// Waiting too long? Start a worker for the queue, or give up on the waiting days.
async function recover(action) {
    busy.value = true;
    error.value = '';
    try {
        const res = await api(action === 'run' ? '/sync/run-now' : '/sync/cancel', { method: 'POST' });
        if (action === 'run' && !res.running) error.value = res.message;
        emit('queued'); // the page checks the days again
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

async function queue(dates, force) {
    busy.value = true;
    error.value = '';
    try {
        for (let i = 0; i < dates.length; i += 62) {
            await api('/sync/days', { method: 'POST', body: { dates: dates.slice(i, i + 62), force, packages: [props.package] } });
        }
        picked.value = [];
        popup.value = false;
        emit('queued');
    } catch (e) {
        error.value = e instanceof ApiError && Object.keys(e.errors).length ? Object.values(e.errors).flat().join(' ') : e.message;
    } finally {
        busy.value = false;
    }
}

const onKey = (e) => { if (e.key === 'Escape') popup.value = false; };
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <div v-if="missing.length === 0 && !popup" class="card flex flex-wrap items-center gap-x-2 p-4 text-sm">
        <span class="text-slate-600">All days are synced.</span>
        <button type="button" class="underline" @click="popup = true">Click here to re-sync selected dates</button>
        <span v-if="notice" class="text-slate-500">{{ notice }}</span>
        <template v-if="anyBusy">
            <button type="button" class="underline" :disabled="busy" @click="recover('run')">Run now</button>
            <button type="button" class="underline" :disabled="busy" @click="recover('cancel')">Cancel waiting</button>
        </template>
    </div>

    <!-- The same list is inline when days are missing, and a popup when everything is synced. -->
    <div
        v-if="showList"
        :class="asPopup && 'fixed inset-0 z-30 flex items-center justify-center bg-slate-900/40 p-4'"
        @click.self="asPopup && (popup = false)"
    >
        <div class="card space-y-3 p-4 text-sm" :class="asPopup && 'w-full max-w-2xl p-6'">
            <p v-if="missing.length" class="font-medium text-amber-700">
                {{ missing.length }} day{{ missing.length > 1 ? 's are' : ' is' }} not synced yet, so the report can't run.
            </p>
            <h2 v-else class="text-lg font-semibold">Re-sync days</h2>
            <p v-if="asPopup" class="text-slate-600">Tick the days to pull from Quinos again. The report runs again when they are done.</p>

            <div class="flex flex-wrap items-center gap-3">
                <button v-if="missing.length" type="button" class="btn-primary" :disabled="disabled || !toQueue.length" @click="queue(toQueue, false)">
                    {{ syncing ? 'Syncing…' : `Sync ${toQueue.length} missing day${toQueue.length === 1 ? '' : 's'}` }}
                </button>
                <button type="button" :class="asPopup ? 'btn-primary' : 'btn-ghost'" :disabled="disabled || !picked.length" @click="queue(picked, true)">
                    Re-sync selected{{ picked.length ? ` (${picked.length})` : '' }}
                </button>
                <button type="button" class="underline" @click="picked = days.map((d) => d.date)">Select all</button>
                <button type="button" class="underline" @click="picked = []">Clear</button>
                <RouterLink v-if="!asPopup" class="underline" to="/sync">Open Sync page</RouterLink>
                <button v-if="asPopup" type="button" class="btn-ghost ml-auto" @click="popup = false">Close</button>
                <span v-if="notice" class="text-slate-500">{{ notice }}</span>
                <template v-if="anyBusy">
                    <button type="button" class="underline" :disabled="busy" @click="recover('run')">Run now</button>
                    <button type="button" class="underline" :disabled="busy" @click="recover('cancel')">Cancel waiting</button>
                </template>
            </div>

            <ul class="max-h-72 columns-1 gap-6 overflow-y-auto sm:columns-2 lg:columns-3">
                <li v-for="d in days" :key="d.date">
                    <label class="flex cursor-pointer items-center gap-2 py-0.5">
                        <input type="checkbox" class="rounded border-slate-300" :checked="picked.includes(d.date)" :disabled="disabled" @change="toggle(d.date)">
                        <span>{{ d.date }}</span>
                        <span :class="statusOf(d)[1]">{{ statusOf(d)[0] }}</span>
                    </label>
                </li>
            </ul>

            <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-red-700">{{ error }}</p>
        </div>
    </div>
</template>
