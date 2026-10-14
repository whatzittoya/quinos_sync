<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '../api';
import { useAuth } from '../stores/auth';

const KINDS = {
    items: { title: 'Items', columns: [['code', 'Code'], ['name', 'Name'], ['category_quinos_id', 'Category'], ['department_quinos_id', 'Department'], ['active', 'Active']] },
    departments: { title: 'Departments', columns: [['quinos_id', 'ID'], ['name', 'Name']] },
    categories: { title: 'Categories', columns: [['quinos_id', 'ID'], ['name', 'Name'], ['department_quinos_id', 'Department']] },
    'sales-types': { title: 'Sales types', columns: [['quinos_id', 'ID'], ['name', 'Name'], ['active', 'Active']] },
    contacts: { title: 'Contacts', columns: [['name', 'Name'], ['supplier', 'Supplier'], ['customer', 'Customer'], ['active', 'Active']] },
};

const auth = useAuth();
const route = useRoute();
const kind = computed(() => route.params.kind);
const meta = computed(() => KINDS[kind.value] ?? KINDS.items);

const rows = ref([]);
const page = ref(1);
const lastPage = ref(1);
const total = ref(0);
const search = ref('');
const lastSynced = ref(null);
const syncing = ref(false);
const notice = ref('');
const loading = ref(false);
const error = ref('');
const blocked = computed(() => auth.isAdmin && !auth.activeClient);

let timer;
let pollTimer;

async function load() {
    if (blocked.value) return;
    loading.value = true;
    error.value = '';
    try {
        const res = await api(`/master/${kind.value}`, { query: { page: page.value, search: search.value || null } });
        rows.value = res.data;
        lastPage.value = res.last_page;
        total.value = res.total;
        lastSynced.value = res.last_synced_at;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

const lastSyncedText = computed(() => (lastSynced.value
    ? new Date(lastSynced.value).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' })
    : 'never'));

// Queue a refresh of all master data, then poll until this page's "last pulled" time moves.
async function sync() {
    syncing.value = true;
    error.value = notice.value = '';
    const before = lastSynced.value;
    try {
        await api('/sync/master', { method: 'POST' });
        notice.value = 'Sync queued. This page updates when it finishes.';
        for (let i = 0; i < 60 && syncing.value; i++) {
            await new Promise((resolve) => { pollTimer = setTimeout(resolve, 3000); });
            await load();
            if (lastSynced.value !== before) {
                notice.value = 'Sync finished.';
                return;
            }
        }
        notice.value = 'Still waiting. The sync runs once the queue worker picks it up.';
    } catch (e) {
        error.value = e.message;
    } finally {
        syncing.value = false;
    }
}

const show = (v) => (typeof v === 'boolean' ? (v ? 'Yes' : 'No') : (v ?? '—'));

onBeforeUnmount(() => { syncing.value = false; clearTimeout(timer); clearTimeout(pollTimer); });

watch(kind, () => { page.value = 1; search.value = ''; load(); }, { immediate: true });
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => { page.value = 1; load(); }, 300); });
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-2xl font-semibold tracking-tight">Master · {{ meta.title }}</h1>
            <input v-model="search" class="field !w-64" placeholder="Search…">
        </div>

        <div v-if="!blocked" class="flex flex-wrap items-center gap-3 text-sm">
            <span class="text-slate-500">Last pulled: <strong class="text-slate-700">{{ lastSyncedText }}</strong></span>
            <button class="btn-primary" :disabled="syncing" @click="sync">{{ syncing ? 'Syncing…' : 'Sync now' }}</button>
            <span v-if="notice" class="text-slate-500">{{ notice }}</span>
        </div>

        <div v-if="blocked" class="card p-6 text-sm text-slate-600">Pick a client in the top bar first.</div>

        <template v-else>
            <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

            <div class="card overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr><th v-for="[, label] in meta.columns" :key="label" class="px-4 py-3">{{ label }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(r, i) in rows" :key="i">
                            <td v-for="[key] in meta.columns" :key="key" class="px-4 py-2">{{ show(r[key]) }}</td>
                        </tr>
                        <tr v-if="!rows.length && !loading">
                            <td :colspan="meta.columns.length" class="px-4 py-6 text-center text-slate-500">
                                Nothing here. Run a sync from the Sync page to load master data.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between text-sm text-slate-500">
                <span>{{ total }} rows</span>
                <div class="flex items-center gap-2">
                    <button class="btn-ghost" :disabled="page <= 1" @click="page--; load()">Previous</button>
                    <span>Page {{ page }} / {{ lastPage }}</span>
                    <button class="btn-ghost" :disabled="page >= lastPage" @click="page++; load()">Next</button>
                </div>
            </div>
        </template>
    </div>
</template>
