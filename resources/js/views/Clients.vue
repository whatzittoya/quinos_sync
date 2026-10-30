<script setup>
import { onMounted, reactive, ref } from 'vue';
import { api, ApiError } from '../api';

// Clients are stored on the hosted app; this page creates and edits them there and refreshes the local copy.
const clients = ref([]);
const loading = ref(false);
const error = ref('');
const editing = ref(null); // null = closed, 0 = new, otherwise the client id
const saving = ref(false);
const formError = ref('');
const apiKey = ref(null); // { client, token, url }, shown once

const TIMEZONES = [
    { value: 'Asia/Jakarta', label: 'WIB (Jakarta, UTC+7)' },
    { value: 'Asia/Makassar', label: 'WITA (Bali, Makassar, UTC+8)' },
    { value: 'Asia/Jayapura', label: 'WIT (Jayapura, UTC+9)' },
];
const blank = () => ({ name: '', quinos_email: '', quinos_password: '', timezone: 'Asia/Makassar', has_quinos_password: false });
const form = reactive(blank());

const message = (e) => (e instanceof ApiError && Object.keys(e.errors).length ? Object.values(e.errors).flat().join(' ') : e.message);

async function load() {
    loading.value = true;
    error.value = '';
    try {
        clients.value = (await api('/hosted-clients')).data;
    } catch (e) {
        error.value = message(e);
    } finally {
        loading.value = false;
    }
}

function open(client = null) {
    formError.value = '';
    Object.assign(form, blank(), client ?? {}, { quinos_password: '' });
    editing.value = client ? client.id : 0;
}

async function save() {
    saving.value = true;
    formError.value = '';
    try {
        const body = { name: form.name, quinos_email: form.quinos_email || null, quinos_password: form.quinos_password || null, timezone: form.timezone };
        const { data } = editing.value === 0
            ? await api('/hosted-clients', { method: 'POST', body })
            : await api(`/hosted-clients/${editing.value}`, { method: 'PUT', body });
        const isNew = editing.value === 0;
        editing.value = null;
        await load();
        if (isNew && confirm(`${data.name} was created. Generate its mobile API key now?`)) await generateKey(data);
    } catch (e) {
        formError.value = message(e);
    } finally {
        saving.value = false;
    }
}

async function generateKey(client) {
    if (client.has_mobile_api_token && !confirm(`Replace the API key of ${client.name}? The app stops working with the old key.`)) return;
    error.value = '';
    try {
        const result = await api(`/hosted-clients/${client.id}/mobile-api-key`, { method: 'POST' });
        apiKey.value = { client: client.name, token: result.token, url: result.url };
        await load();
    } catch (e) {
        error.value = message(e);
    }
}

async function removeKey(client) {
    if (!confirm(`Remove the API key of ${client.name}? The app stops working until a new key is set.`)) return;
    error.value = '';
    try {
        await api(`/hosted-clients/${client.id}/mobile-api-key`, { method: 'DELETE' });
        await load();
    } catch (e) {
        error.value = message(e);
    }
}

const copy = (text) => navigator.clipboard?.writeText(text);

onMounted(load);
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Clients</h1>
                <p class="text-sm text-slate-500">Saved on the hosted app. New clients are synced by this machine from the next run.</p>
            </div>
            <div class="flex shrink-0 gap-2">
                <button class="btn-ghost" :disabled="loading" @click="load">{{ loading ? 'Loading…' : 'Refresh' }}</button>
                <button class="btn-primary" @click="open()">Add client</button>
            </div>
        </div>

        <p v-if="error" role="alert" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

        <div v-if="apiKey" class="card space-y-3 p-4">
            <p class="text-sm">New mobile API key for <b>{{ apiKey.client }}</b>. Copy it now: it is shown only once.</p>
            <div class="flex gap-2">
                <input :value="apiKey.token" readonly class="field font-mono" aria-label="API key">
                <button class="btn-ghost shrink-0" @click="copy(apiKey.token)">Copy</button>
            </div>
            <div>
                <label class="label">App URL (replace {date} with YYYY-MM-DD)</label>
                <div class="flex gap-2">
                    <input :value="apiKey.url" readonly class="field font-mono text-xs" aria-label="App URL">
                    <button class="btn-ghost shrink-0" @click="copy(apiKey.url)">Copy</button>
                </div>
            </div>
            <button class="btn-ghost" @click="apiKey = null">Done</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Quinos login</th>
                        <th class="px-4 py-3">Mobile API key</th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-if="!loading && clients.length === 0">
                        <td colspan="4" class="px-4 py-6 text-center text-slate-500">No clients yet.</td>
                    </tr>
                    <tr v-for="c in clients" :key="c.id">
                        <td class="px-4 py-3 font-medium">{{ c.name }}</td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ c.quinos_email || '—' }}
                            <span v-if="c.quinos_email && !c.has_quinos_password" class="ml-1 text-amber-600">(no password)</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span :class="c.has_mobile_api_token ? 'text-green-700' : 'text-slate-400'">{{ c.has_mobile_api_token ? 'Set' : 'Not set' }}</span>
                            <button class="btn-ghost ml-2" @click="generateKey(c)">{{ c.has_mobile_api_token ? 'Regenerate' : 'Generate API key' }}</button>
                            <button v-if="c.has_mobile_api_token" class="btn-ghost" @click="removeKey(c)">Remove</button>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button class="btn-ghost" @click="open(c)">Edit</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="editing !== null" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
            <form class="card w-full max-w-lg space-y-4 p-6" @submit.prevent="save">
                <h2 class="text-lg font-semibold">{{ editing === 0 ? 'Add client' : 'Edit client' }}</h2>
                <div>
                    <label class="label" for="c-name">Name</label>
                    <input id="c-name" v-model="form.name" class="field" required>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="c-email">Quinos email</label>
                        <input id="c-email" v-model="form.quinos_email" type="email" class="field" autocomplete="off">
                    </div>
                    <div>
                        <label class="label" for="c-password">Quinos password</label>
                        <input
                            id="c-password" v-model="form.quinos_password" type="password" class="field" autocomplete="new-password"
                            :placeholder="form.has_quinos_password ? 'Unchanged' : ''"
                        >
                    </div>
                </div>
                <div>
                    <label class="label" for="c-tz">Timezone</label>
                    <select id="c-tz" v-model="form.timezone" class="field" required>
                        <option v-for="t in TIMEZONES" :key="t.value" :value="t.value">{{ t.label }}</option>
                        <option v-if="!TIMEZONES.some((t) => t.value === form.timezone)" :value="form.timezone">{{ form.timezone }}</option>
                    </select>
                </div>
                <p v-if="formError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ formError }}</p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn-ghost" @click="editing = null">Cancel</button>
                    <button class="btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
                </div>
            </form>
        </div>
    </div>
</template>
