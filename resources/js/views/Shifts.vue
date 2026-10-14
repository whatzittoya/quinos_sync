<script setup>
import { onMounted, ref } from 'vue';
import { api, ApiError } from '../api';
import { useAuth } from '../stores/auth';

const auth = useAuth();
const form = ref({ shift_assign_by: 'open_time', shifts: [] });
const loading = ref(true);
const saving = ref(false);
const message = ref('');
const error = ref('');

async function load() {
    if (auth.isAdmin && !auth.activeClient) { loading.value = false; return; }
    try {
        form.value = await api('/settings/shifts');
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function addShift() {
    form.value.shifts.push({ label: '', starts_at: '00:00', ends_at: '24:00' });
}

async function save() {
    saving.value = true;
    message.value = error.value = '';
    try {
        form.value = await api('/settings/shifts', { method: 'PUT', body: form.value });
        message.value = 'Saved.';
    } catch (e) {
        error.value = e instanceof ApiError && Object.keys(e.errors).length
            ? Object.values(e.errors).flat().join(' ')
            : e.message;
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Shifts</h1>
            <p class="text-sm text-slate-500">
                Dayparts used by the weekly sales report. A transaction belongs to the shift its time falls in.
                Start is included, end is excluded (use 24:00 for end of day).
            </p>
        </div>

        <p v-if="auth.isAdmin && !auth.activeClient" class="card p-6 text-sm text-slate-600">Select a client first.</p>

        <form v-else-if="!loading" class="card space-y-4 p-6" @submit.prevent="save">
            <div class="max-w-xs">
                <label class="label">Assign shift by</label>
                <select v-model="form.shift_assign_by" class="field">
                    <option value="open_time">Time the bill was opened</option>
                    <option value="close_time">Time the bill was closed</option>
                </select>
            </div>

            <div class="space-y-2">
                <div v-for="(shift, i) in form.shifts" :key="i" class="grid grid-cols-12 items-end gap-2">
                    <div class="col-span-12 sm:col-span-6">
                        <label v-if="i === 0" class="label">Label (printed on the report)</label>
                        <input v-model="shift.label" class="field" required>
                    </div>
                    <div class="col-span-5 sm:col-span-2">
                        <label v-if="i === 0" class="label">Start</label>
                        <input v-model="shift.starts_at" class="field" placeholder="00:00" required>
                    </div>
                    <div class="col-span-5 sm:col-span-2">
                        <label v-if="i === 0" class="label">End</label>
                        <input v-model="shift.ends_at" class="field" placeholder="24:00" required>
                    </div>
                    <div class="col-span-2">
                        <button type="button" class="btn-danger w-full" @click="form.shifts.splice(i, 1)">Remove</button>
                    </div>
                </div>
            </div>

            <p v-if="error" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
            <p v-if="message" class="rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{{ message }}</p>

            <div class="flex gap-2">
                <button type="button" class="btn-ghost" @click="addShift">Add shift</button>
                <button class="btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
            </div>
        </form>
    </div>
</template>
