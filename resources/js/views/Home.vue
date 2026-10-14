<script setup>
import { computed, onMounted, ref } from 'vue';
import { api } from '../api';
import { useAuth } from '../stores/auth';

const auth = useAuth();
const data = ref(null);
const loading = ref(true);
const error = ref('');
const blocked = computed(() => auth.isAdmin && !auth.activeClient);

async function load() {
    if (blocked.value) { loading.value = false; return; }
    try {
        data.value = await api('/dashboard');
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

const fmt = (iso) => (iso ? new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : 'never');

// How current a report is: synced up to today, or N days behind.
function status(r) {
    if (r.days_behind === null) return { text: 'Never synced', tone: 'border-slate-200 bg-slate-50 text-slate-600', dot: 'bg-slate-400' };
    if (r.days_behind === 0) return { text: 'Up to date · today', tone: 'border-green-200 bg-green-50 text-green-800', dot: 'bg-green-500' };
    const text = `${r.days_behind} day${r.days_behind > 1 ? 's' : ''} behind`;
    return r.days_behind <= 2
        ? { text, tone: 'border-amber-200 bg-amber-50 text-amber-800', dot: 'bg-amber-500' }
        : { text, tone: 'border-red-200 bg-red-50 text-red-800', dot: 'bg-red-500' };
}

onMounted(load);
</script>

<template>
    <div class="space-y-6">
        <h1 class="text-2xl font-semibold tracking-tight">Dashboard</h1>

        <div v-if="blocked" class="card p-6 text-sm text-slate-600">
            Pick a client in the top bar, or add one under <RouterLink class="underline" to="/admin/clients">Clients</RouterLink>.
        </div>

        <p v-else-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
        <p v-else-if="loading" class="text-sm text-slate-500">Loading…</p>

        <template v-else-if="data">
            <section class="space-y-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Reports</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <RouterLink
                        v-for="r in data.reports" :key="r.key" to="/sync"
                        class="rounded-lg border p-5 transition hover:shadow-md" :class="status(r).tone"
                    >
                        <div class="text-sm font-medium opacity-80">{{ r.label }}</div>
                        <div class="mt-2 flex items-center gap-2 text-2xl font-semibold">
                            <span class="h-2.5 w-2.5 rounded-full" :class="status(r).dot" />
                            {{ status(r).text }}
                        </div>
                        <div class="mt-3 text-xs opacity-70">Last synced: {{ fmt(r.last_synced_at) }}</div>
                    </RouterLink>
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Master data</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <RouterLink
                        v-for="m in data.master" :key="m.key" :to="`/master/${m.key}`"
                        class="card p-5 transition hover:shadow-md"
                    >
                        <div class="text-sm font-medium text-slate-500">{{ m.label }}</div>
                        <div class="mt-2 text-3xl font-semibold tabular-nums">{{ m.count.toLocaleString('en-US') }}</div>
                        <div class="mt-3 text-xs text-slate-400">Last synced: {{ fmt(m.last_synced_at) }}</div>
                    </RouterLink>
                </div>
            </section>
        </template>
    </div>
</template>
