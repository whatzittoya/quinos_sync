<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api';

const url = ref('');
const token = ref('');
const busy = ref(false);
const message = ref('');
const error = ref('');

async function act(fn, done) {
    busy.value = true;
    message.value = '';
    error.value = '';
    try {
        await fn();
        message.value = done;
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

const save = () => act(async () => {
    await api('/local-runner/settings', { method: 'PUT', body: { hosted_api_url: url.value, runner_token: token.value || undefined } });
    token.value = '';
}, 'Connection saved');
const test = () => act(() => api('/local-runner/test', { method: 'POST' }), 'Connection works');

onMounted(() => act(async () => { url.value = (await api('/local-runner/settings')).settings.hosted_api_url ?? ''; }, ''));
</script>

<template>
    <div class="max-w-xl space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Connection</h1>
            <p class="text-sm text-slate-500">The hosted app this machine uploads to. Create the token under Runner tokens in the hosted app.</p>
        </div>

        <p v-if="message" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-800">{{ message }}</p>
        <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

        <section class="card space-y-4 p-4">
            <div>
                <label class="label" for="url">Hosted URL</label>
                <input id="url" v-model="url" class="field" placeholder="https://quinos.qaccounting.id">
            </div>
            <div>
                <label class="label" for="token">API token</label>
                <input id="token" v-model="token" class="field" type="password" autocomplete="off" placeholder="Leave empty to keep the saved token">
            </div>
            <div class="flex gap-2">
                <button class="btn-primary" :disabled="busy" @click="save">Save</button>
                <button class="btn-ghost" :disabled="busy" @click="test">Test connection</button>
            </div>
        </section>
    </div>
</template>
