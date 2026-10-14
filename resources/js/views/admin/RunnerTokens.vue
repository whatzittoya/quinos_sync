<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../../api';
const tokens = ref([]), imports = ref([]), name = ref('Windows runner'), secret = ref(''), error = ref(''), busy = ref(false);
async function load() { const data = await api('/admin/runner-tokens'); tokens.value = data.data; imports.value = data.imports; }
async function action(path, method = 'POST', body) {
    busy.value = true; error.value = '';
    try { const result = await api(path, { method, body }); if (result.secret) secret.value = result.secret; await load(); }
    catch (e) { error.value = e.message; } finally { busy.value = false; }
}
onMounted(() => load().catch(e => error.value = e.message));
</script>
<template>
    <div class="space-y-5">
        <h1 class="text-2xl font-semibold">Runner tokens</h1>
        <p class="text-slate-600">These tokens allow a local runner to retrieve tenant Quinos credentials and upload synced data.</p>
        <p v-if="error" role="alert" class="text-red-700">{{ error }}</p>
        <form class="flex gap-3" @submit.prevent="action('/admin/runner-tokens', 'POST', { name })">
            <input v-model="name" class="field" aria-label="Token name" required maxlength="255">
            <button class="btn-primary" :disabled="busy">Create token</button>
        </form>
        <div v-if="secret" class="card p-4 space-y-2">
            <p>Copy this token into the local runner. It is shown only once.</p>
            <input :value="secret" readonly class="field font-mono" aria-label="New runner token">
            <button class="btn-ghost" @click="secret = ''">Dismiss token</button>
        </div>
        <div class="card overflow-x-auto"><table class="w-full text-left text-sm">
            <thead><tr><th class="p-3">Name</th><th>Created by</th><th>Last used</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody><tr v-for="token in tokens" :key="token.id" class="border-t">
                <td class="p-3">{{ token.name }}</td><td>{{ token.created_by }}</td><td>{{ token.last_used_at || 'Never' }}</td>
                <td>{{ token.revoked_at ? 'Revoked' : 'Active' }}</td><td>
                    <button v-if="!token.revoked_at" class="btn-ghost" :disabled="busy" @click="action(`/admin/runner-tokens/${token.id}/revoke`)">Revoke</button>
                    <button class="btn-ghost" :disabled="busy" @click="action(`/admin/runner-tokens/${token.id}`, 'DELETE')">Delete</button>
                </td>
            </tr></tbody>
        </table></div>
        <h2 class="text-lg font-semibold">Recent imports</h2>
        <div class="card overflow-x-auto"><table class="w-full text-left text-sm">
            <thead><tr><th class="p-3">Tenant</th><th>Package</th><th>Dates</th><th>Status</th><th>Completed</th><th>Error</th></tr></thead>
            <tbody><tr v-for="item in imports" :key="item.id" class="border-t"><td class="p-3">{{ item.client_id }}</td><td>{{ item.package }}</td><td>{{ item.dates.join(', ') || 'Master' }}</td><td>{{ item.status }}</td><td>{{ item.completed_at }}</td><td>{{ item.error }}</td></tr></tbody>
        </table></div>
    </div>
</template>
