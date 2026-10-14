<script setup>
import { reactive, ref } from 'vue';
import { api, ApiError } from '../api';

const form = reactive({ current_password: '', password: '', password_confirmation: '' });
const saving = ref(false);
const done = ref(false);
const errors = reactive({ current_password: '', password: '', other: '' });

async function save() {
    saving.value = true;
    done.value = false;
    Object.assign(errors, { current_password: '', password: '', other: '' });
    try {
        await api('/me/password', { method: 'PUT', body: { ...form } });
        Object.assign(form, { current_password: '', password: '', password_confirmation: '' });
        done.value = true;
    } catch (e) {
        if (e instanceof ApiError && e.errors) {
            errors.current_password = e.errors.current_password?.[0] ?? '';
            errors.password = e.errors.password?.[0] ?? '';
            if (!errors.current_password && !errors.password) errors.other = e.message;
        } else {
            errors.other = e.message;
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="max-w-md space-y-4">
        <h1 class="text-2xl font-semibold tracking-tight">Change password</h1>

        <form class="card space-y-4 p-6" @submit.prevent="save">
            <div>
                <label class="label">Current password</label>
                <input v-model="form.current_password" type="password" class="field" autocomplete="current-password" required>
                <p v-if="errors.current_password" class="mt-1 text-sm text-red-600">{{ errors.current_password }}</p>
            </div>
            <div>
                <label class="label">New password</label>
                <input v-model="form.password" type="password" class="field" autocomplete="new-password" minlength="8" required>
                <p v-if="errors.password" class="mt-1 text-sm text-red-600">{{ errors.password }}</p>
                <p v-else class="mt-1 text-xs text-slate-500">At least 8 characters, different from the current one.</p>
            </div>
            <div>
                <label class="label">Repeat new password</label>
                <input v-model="form.password_confirmation" type="password" class="field" autocomplete="new-password" required>
            </div>

            <p v-if="errors.other" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errors.other }}</p>
            <p v-if="done" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">Password changed.</p>

            <button class="btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Change password' }}</button>
        </form>
    </div>
</template>
