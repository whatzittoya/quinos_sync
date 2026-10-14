<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { api, ApiError } from '../../api';
import { useAuth } from '../../stores/auth';

const auth = useAuth();
const clients = ref([]);
const editing = ref(null); // null = closed, 0 = new, id = existing
const error = ref('');
const saving = ref(false);

const blank = () => ({
    name: '', quinos_email: '', quinos_password: '', quinos_company_id: '',
    timezone: 'Asia/Makassar', active: true, has_quinos_password: false,
});
const form = reactive(blank());

const TIMEZONES = [
    { value: 'Asia/Jakarta', label: 'WIB (Jakarta, UTC+7)' },
    { value: 'Asia/Makassar', label: 'WITA (Bali, Makassar, UTC+8)' },
    { value: 'Asia/Jayapura', label: 'WIT (Jayapura, UTC+9)' },
];
// A client saved earlier with some other timezone keeps it as an extra choice.
const timezoneOptions = computed(() => (TIMEZONES.some((t) => t.value === form.timezone)
    ? TIMEZONES
    : [...TIMEZONES, { value: form.timezone, label: form.timezone }]));

async function load() {
    const { data } = await api('/admin/clients');
    clients.value = data;
}

function open(client = null) {
    error.value = '';
    Object.assign(form, blank(), client ?? {}, { quinos_password: '' });
    editing.value = client ? client.id : 0;
}

async function save() {
    saving.value = true;
    error.value = '';
    try {
        const body = { ...form };
        delete body.has_quinos_password;
        if (editing.value === 0) {
            await api('/admin/clients', { method: 'POST', body });
        } else {
            await api(`/admin/clients/${editing.value}`, { method: 'PUT', body });
        }
        editing.value = null;
        await load();
        await auth.fetchClients();
    } catch (e) {
        error.value = e instanceof ApiError && Object.keys(e.errors).length
            ? Object.values(e.errors).flat().join(' ')
            : e.message;
    } finally {
        saving.value = false;
    }
}

async function remove(client) {
    if (!confirm(`Delete ${client.name}? Its synced data will be deleted too.`)) return;
    await api(`/admin/clients/${client.id}`, { method: 'DELETE' });
    await load();
    await auth.fetchClients();
}

// ---- users of one client (uses the /admin/users API)
const usersFor = ref(null); // the client whose users are open
const users = ref([]);
const userEditing = ref(null); // null = list only, 0 = new, id = existing
const userError = ref('');
const userSaving = ref(false);
const userForm = reactive({ name: '', email: '', password: '' });
const clientUsers = computed(() => users.value.filter((u) => u.client_id != null && String(u.client_id) === String(usersFor.value?.id)));

async function loadUsers() {
    const { data } = await api('/admin/users');
    users.value = data;
}

async function openUsers(client) {
    usersFor.value = client;
    userEditing.value = null;
    userError.value = '';
    await loadUsers();
}

function openUser(user = null) {
    userError.value = '';
    Object.assign(userForm, { name: user?.name ?? '', email: user?.email ?? '', password: '' });
    userEditing.value = user ? user.id : 0;
}

async function saveUser() {
    userSaving.value = true;
    userError.value = '';
    try {
        const body = { ...userForm, role: 'client', client_id: usersFor.value.id };
        if (userEditing.value === 0) {
            await api('/admin/users', { method: 'POST', body });
        } else {
            await api(`/admin/users/${userEditing.value}`, { method: 'PUT', body });
        }
        userEditing.value = null;
        await loadUsers();
    } catch (e) {
        userError.value = e instanceof ApiError && Object.keys(e.errors).length
            ? Object.values(e.errors).flat().join(' ')
            : e.message;
    } finally {
        userSaving.value = false;
    }
}

async function removeUser(user) {
    if (!confirm(`Delete ${user.email}?`)) return;
    try {
        await api(`/admin/users/${user.id}`, { method: 'DELETE' });
        await loadUsers();
    } catch (e) {
        userError.value = e.message;
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-semibold tracking-tight">Clients</h1>
            <button class="btn-primary" @click="open()">Add client</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Quinos login</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="c in clients" :key="c.id">
                        <td class="px-4 py-3 font-medium">{{ c.name }}</td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ c.quinos_email || '—' }}
                            <span v-if="c.quinos_email && !c.has_quinos_password" class="ml-1 text-amber-600">(no password)</span>
                        </td>
                        <td class="px-4 py-3">
                            <span :class="c.active ? 'text-green-700' : 'text-slate-400'">{{ c.active ? 'Active' : 'Disabled' }}</span>
                        </td>
                        <td class="space-x-2 px-4 py-3 text-right">
                            <button class="btn-ghost" @click="openUsers(c)">Users</button>
                            <button class="btn-ghost" @click="open(c)">Edit</button>
                            <button class="btn-danger" @click="remove(c)">Delete</button>
                        </td>
                    </tr>
                    <tr v-if="!clients.length">
                        <td colspan="4" class="px-4 py-6 text-center text-slate-500">No clients yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="editing !== null" class="fixed inset-0 z-10 flex items-center justify-center bg-slate-900/40 p-4">
            <form class="card w-full max-w-lg space-y-4 p-6" @submit.prevent="save">
                <h2 class="text-lg font-semibold">{{ editing === 0 ? 'Add client' : 'Edit client' }}</h2>

                <div>
                    <label class="label">Name</label>
                    <input v-model="form.name" class="field" required>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">Quinos email</label>
                        <input v-model="form.quinos_email" type="email" class="field" autocomplete="off">
                    </div>
                    <div>
                        <label class="label">Quinos password</label>
                        <input
                            v-model="form.quinos_password" type="password" class="field" autocomplete="new-password"
                            :placeholder="form.has_quinos_password ? 'Unchanged' : ''"
                        >
                    </div>
                </div>
                <div>
                    <label class="label">Timezone</label>
                    <select v-model="form.timezone" class="field" required>
                        <option v-for="t in timezoneOptions" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.active" type="checkbox" class="rounded border-slate-300"> Active
                </label>

                <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

                <div class="flex justify-end gap-2">
                    <button type="button" class="btn-ghost" @click="editing = null">Cancel</button>
                    <button class="btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
                </div>
            </form>
        </div>

        <div v-if="usersFor" class="fixed inset-0 z-10 flex items-center justify-center bg-slate-900/40 p-4">
            <div class="card w-full max-w-lg space-y-4 p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">Users of {{ usersFor.name }}</h2>
                    <button v-if="userEditing === null" class="btn-primary" @click="openUser()">Add user</button>
                </div>

                <form v-if="userEditing !== null" class="space-y-4" @submit.prevent="saveUser">
                    <div>
                        <label class="label">Name</label>
                        <input v-model="userForm.name" class="field" required>
                    </div>
                    <div>
                        <label class="label">Email</label>
                        <input v-model="userForm.email" type="email" class="field" required>
                    </div>
                    <div>
                        <label class="label">Password</label>
                        <input
                            v-model="userForm.password" type="password" class="field" autocomplete="new-password"
                            :required="userEditing === 0" :placeholder="userEditing === 0 ? '' : 'Unchanged'"
                        >
                    </div>
                    <p v-if="userError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ userError }}</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn-ghost" @click="userEditing = null">Cancel</button>
                        <button class="btn-primary" :disabled="userSaving">{{ userSaving ? 'Saving…' : 'Save' }}</button>
                    </div>
                </form>

                <template v-else>
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="u in clientUsers" :key="u.id" class="flex items-center justify-between py-2">
                            <div>
                                <div class="font-medium">{{ u.name }}</div>
                                <div class="text-slate-500">{{ u.email }}</div>
                            </div>
                            <div class="space-x-2">
                                <button class="btn-ghost" @click="openUser(u)">Edit</button>
                                <button class="btn-danger" @click="removeUser(u)">Delete</button>
                            </div>
                        </li>
                        <li v-if="!clientUsers.length" class="py-4 text-center text-slate-500">No users yet.</li>
                    </ul>
                    <p v-if="userError" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ userError }}</p>
                    <div class="flex justify-end">
                        <button class="btn-ghost" @click="usersFor = null">Close</button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
