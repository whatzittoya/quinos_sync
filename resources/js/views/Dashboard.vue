<script setup>
import { onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { api, selectedClientId } from '../api';

const clients = ref([]);
const loading = ref(true);
const refreshing = ref(false);
const error = ref('');

async function load() {
    try {
        // Days hosted already synced count here too. Quiet: an unreachable hosted app is not an error.
        await api('/sync/pull-status', { method: 'POST' }).catch(() => {});
        const res = await api('/dashboard');
        clients.value = res.data;
        if (!clients.value.length) {
            // Nothing local yet: ask the hosted app for the client list, then look again.
            const list = await api('/clients');
            if (list.warning) error.value = list.warning;
            clients.value = (await api('/dashboard')).data;
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function refresh() {
    refreshing.value = true;
    error.value = '';
    try {
        const list = await api('/clients', { query: { refresh: 1 } });
        if (list.warning) error.value = list.warning;
        clients.value = (await api('/dashboard')).data;
    } catch (e) {
        error.value = e.message;
    } finally {
        refreshing.value = false;
    }
}

const fmt = (iso) => (iso ? new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : 'never');

// How current a report is: synced up to today, or N days behind.
function status(r) {
    if (r.days_behind === null) return { text: 'Never synced', tone: 'border-slate-200 bg-slate-50 text-slate-600', dot: 'bg-slate-400' };
    if (r.days_behind === 0) return { text: 'Up to date', tone: 'border-green-200 bg-green-50 text-green-800', dot: 'bg-green-500' };
    const text = `${r.days_behind} day${r.days_behind > 1 ? 's' : ''} behind`;
    return r.days_behind <= 2
        ? { text, tone: 'border-amber-200 bg-amber-50 text-amber-800', dot: 'bg-amber-500' }
        : { text, tone: 'border-red-200 bg-red-50 text-red-800', dot: 'bg-red-500' };
}

const open = (id) => selectedClientId.set(id);

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>
            <button class="btn-ghost" :disabled="refreshing" @click="refresh">{{ refreshing ? 'Refreshing…' : 'Refresh clients' }}</button>
        </div>

        <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
        <p v-if="loading" class="text-sm text-slate-500">Loading…</p>
        <p v-else-if="!clients.length" class="card p-6 text-sm text-slate-600">
            No clients yet. Check the hosted connection under <RouterLink class="underline" to="/connection">Connection</RouterLink>, then refresh.
        </p>

        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <section v-for="c in clients" :key="c.id" class="card space-y-3 p-4">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h2 class="font-semibold">{{ c.name }}</h2>
                        <div class="text-xs text-slate-400">{{ c.timezone }}</div>
                    </div>
                    <span v-if="c.scheduled" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Scheduled</span>
                </div>

                <RouterLink
                    v-for="r in c.reports" :key="r.key" to="/sync" @click="open(c.id)"
                    class="block rounded-lg border p-3 transition hover:shadow-md" :class="status(r).tone"
                >
                    <div class="text-xs font-medium opacity-80">{{ r.label }}</div>
                    <div class="mt-1 flex items-center gap-2 text-lg font-semibold">
                        <span class="h-2.5 w-2.5 rounded-full" :class="status(r).dot" />
                        {{ status(r).text }}
                    </div>
                    <div class="mt-1 text-xs opacity-70">Last sync: {{ fmt(r.last_synced_at) }}</div>
                </RouterLink>

                <div class="text-xs text-slate-500">Master data last sync: {{ fmt(c.master_synced_at) }}</div>
            </section>
        </div>
    </div>
</template>
