<script setup>
import { onMounted, reactive, ref } from 'vue';
import { api, ApiError } from '../../api';
import { useAuth } from '../../stores/auth';

const auth = useAuth();
const users = ref([]);
const editing = ref(null);
const error = ref('');
const saving = ref(false);

const blank = () => ({ name: '', email: '', password: '', role: 'client', client_id: '' });
const form = reactive(blank());

async function load() {
    const { data } = await api('/admin/users');
    users.value = data;
}

function open(user = null) {
    error.value = '';
    Object.assign(form, blank(), user ? { ...user, client_id: user.client_id ?? '' } : {}, { password: '' });
    editing.value = user ? user.id : 0;
}

async function save() {
    saving.value = true;
    error.value = '';
    try {
        const body = {
            name: form.name, email: form.email, password: form.password,
            role: form.role, client_id: form.role === 'client' ? form.client_id || null : null,
        };
        if (editing.value === 0) {
            await api('/admin/users', { method: 'POST', body });
        } else {
            await api(`/admin/users/${editing.value}`, { method: 'PUT', body });
        }
        editing.value = null;
        await load();
    } catch (e) {
        error.value = e instanceof ApiError && Object.keys(e.errors).length
            ? Object.values(e.errors).flat().join(' ')
            : e.message;
    } finally {
        saving.value = false;
    }
}

async function remove(user) {
    if (!confirm(`Delete ${user.email}?`)) return;
    try {
        await api(`/admin/users/${user.id}`, { method: 'DELETE' });
        await load();
    } catch (e) {
        alert(e.message);
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold tracking-tight">Users</h1>
            <button class="btn-primary" @click="open()">Add user</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Client</th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="u in users" :key="u.id">
                        <td class="px-4 py-3 font-medium">{{ u.name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ u.email }}</td>
                        <td class="px-4 py-3 capitalize">{{ u.role }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ u.client?.name ?? '—' }}</td>
                        <td class="space-x-2 px-4 py-3 text-right">
                            <button class="btn-ghost" @click="open(u)">Edit</button>
                            <button class="btn-danger" @click="remove(u)">Delete</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="editing !== null" class="fixed inset-0 z-10 flex items-center justify-center bg-slate-900/40 p-4">
            <form class="card w-full max-w-lg space-y-4 p-6" @submit.prevent="save">
                <h2 class="text-lg font-semibold">{{ editing === 0 ? 'Add user' : 'Edit user' }}</h2>

                <div>
                    <label class="label">Name</label>
                    <input v-model="form.name" class="field" required>
                </div>
                <div>
                    <label class="label">Email</label>
                    <input v-model="form.email" type="email" class="field" required>
                </div>
                <div>
                    <label class="label">Password</label>
                    <input
                        v-model="form.password" type="password" class="field" autocomplete="new-password"
                        :required="editing === 0" :placeholder="editing === 0 ? '' : 'Unchanged'"
                    >
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Role</label>
                        <select v-model="form.role" class="field">
                            <option value="client">Client</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div v-if="form.role === 'client'">
                        <label class="label">Client</label>
                        <select v-model="form.client_id" class="field" required>
                            <option value="" disabled>Select…</option>
                            <option v-for="c in auth.clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                </div>

                <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

                <div class="flex justify-end gap-2">
                    <button type="button" class="btn-ghost" @click="editing = null">Cancel</button>
                    <button class="btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
                </div>
            </form>
        </div>
    </div>
</template>
