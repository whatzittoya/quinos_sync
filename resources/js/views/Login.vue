<script setup>
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ApiError } from '../api';
import { useAuth } from '../stores/auth';

const auth = useAuth();
const router = useRouter();

const form = reactive({ email: '', password: '', remember: false });
const error = ref('');
const busy = ref(false);

async function submit() {
    busy.value = true;
    error.value = '';
    try {
        await auth.login(form);
        router.push({ name: 'home' });
    } catch (e) {
        error.value = e instanceof ApiError ? (e.errors.email?.[0] ?? e.message) : 'Something went wrong.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center px-4">
        <form class="card w-full max-w-sm space-y-4 p-6" @submit.prevent="submit">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Quinos Reports</h1>
                <p class="text-sm text-slate-500">Sign in to see your reports.</p>
            </div>

            <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

            <div>
                <label class="label" for="email">Email</label>
                <input id="email" v-model="form.email" type="email" class="field" autocomplete="username" required autofocus>
            </div>
            <div>
                <label class="label" for="password">Password</label>
                <input id="password" v-model="form.password" type="password" class="field" autocomplete="current-password" required>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input v-model="form.remember" type="checkbox" class="rounded border-slate-300"> Remember me
            </label>

            <button class="btn-primary w-full" :disabled="busy">{{ busy ? 'Signing in…' : 'Sign in' }}</button>
        </form>
    </div>
</template>
