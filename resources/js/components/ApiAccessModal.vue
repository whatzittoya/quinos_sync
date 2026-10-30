<script setup>
import { computed, ref } from 'vue';
import { api } from '../api';

// Mobile API access for one client: its key and the app link, with generate / regenerate / remove.
// `path` is the key endpoint (POST makes a new key, DELETE removes it); both answer with { token?, url?, data? }.
const props = defineProps({ client: { type: Object, required: true }, path: { type: String, required: true } });
const emit = defineEmits(['close', 'changed']);

const token = ref(props.client.mobile_api_token ?? null);
const url = ref(props.client.mobile_api_url ?? null);
const mcpUrl = ref(props.client.mcp_url ?? null);
const hidden = ref(!token.value && props.client.has_mobile_api_token); // set before keys could be shown again
const busy = ref(false);
const error = ref('');
const copied = ref('');

// One line to paste into an AI agent (Claude Code, Claude Desktop, Cursor, ...): it installs the MCP server with this
// client's key and tells the agent what the server holds, so it can use it right away.
const agentPrompt = computed(() => {
    if (!mcpUrl.value || !token.value) return null;
    const name = 'quinos-' + (props.client.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'client');
    const base = mcpUrl.value.slice(0, mcpUrl.value.lastIndexOf('/'));
    return `Install the Quinos MCP server for ${props.client.name} and remember how to use it. `
        + `API key: ${token.value}. In Claude Code run: claude mcp add --transport http ${name} ${mcpUrl.value} `
        + `- in other agents add a remote MCP server (Streamable HTTP) at ${mcpUrl.value}, or at ${base} with the header "Authorization: Bearer ${token.value}". `
        + `It holds the POS sales and purchasing of ${props.client.name} (amounts in IDR, dates in the client's timezone). `
        + `Sales tools: list_stores (store codes and which days have data - start here), sales_summary (by day, weekday, hour, shift, sales type, payment, store), item_sales, list_transactions, get_transaction, void_report; voided bills, FOC bills (compliments, wastage, staff meals) and voided lines are not sales. `
        + `Purchasing tools: purchase_summary (spend by supplier, month, week, category or item; basis received or ordered; reports which days are synced), list_purchase_orders, get_purchase_order (order to receipts to invoices), item_purchase_prices (price history per supplier), payables (unpaid supplier invoices). `
        + `Sync tools: when the days you need are missing, call sync_days (package pos for sales, purchase for purchasing, at most 31 days per call), wait about a minute, check sync_status, then query again. `
        + `Treat the API key like a password. After installing, call list_stores to confirm it works, and save these notes as a skill or memory so you know this server next time.`;
});

// The link with yesterday's date filled in, to try it in a browser.
const example = computed(() => {
    if (!url.value) return null;
    const d = new Date(Date.now() - 86400000);
    const day = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    return url.value.replace('{date}', day);
});

async function generate() {
    if ((token.value || hidden.value) && !confirm(`Replace the API key of ${props.client.name}? The app stops working with the old key.`)) return;
    await run(async () => {
        const result = await api(props.path, { method: 'POST' });
        token.value = result.token;
        url.value = result.url;
        mcpUrl.value = result.mcp_url ?? null;
        hidden.value = false;
    });
}

async function remove() {
    if (!confirm(`Remove the API key of ${props.client.name}? The app stops working until a new key is set.`)) return;
    await run(async () => {
        await api(props.path, { method: 'DELETE' });
        token.value = null;
        url.value = null;
        mcpUrl.value = null;
        hidden.value = false;
    });
}

async function run(fn) {
    busy.value = true;
    error.value = '';
    try {
        await fn();
        emit('changed');
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

function copy(what, text) {
    navigator.clipboard?.writeText(text);
    copied.value = what;
    setTimeout(() => { if (copied.value === what) copied.value = ''; }, 1500);
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" @click.self="emit('close')">
        <div class="card w-full max-w-xl space-y-4 p-6" role="dialog" aria-modal="true" :aria-label="`API access for ${client.name}`">
            <div>
                <h2 class="text-lg font-semibold">API access</h2>
                <p class="text-sm text-slate-500">Mobile app transactions for <b>{{ client.name }}</b>.</p>
            </div>

            <p v-if="error" role="alert" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

            <template v-if="token">
                <div>
                    <label class="label" for="api-key">API key</label>
                    <div class="flex gap-2">
                        <input id="api-key" :value="token" readonly class="field font-mono" @focus="$event.target.select()">
                        <button type="button" class="btn-ghost w-20 shrink-0" @click="copy('key', token)">{{ copied === 'key' ? 'Copied' : 'Copy' }}</button>
                    </div>
                </div>
                <div>
                    <label class="label" for="api-url">Link (replace {date} with YYYY-MM-DD)</label>
                    <div class="flex gap-2">
                        <input id="api-url" :value="url" readonly class="field font-mono text-xs" @focus="$event.target.select()">
                        <button type="button" class="btn-ghost w-20 shrink-0" @click="copy('url', url)">{{ copied === 'url' ? 'Copied' : 'Copy' }}</button>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Try it: <a :href="example" target="_blank" rel="noopener" class="break-all text-blue-700 underline">yesterday's transactions</a>.
                        {STORE} in the link means no bills were uploaded yet; it becomes the outlet code (for example ZALI.ULUWATU) after the first sync.
                    </p>
                </div>
                <div v-if="mcpUrl">
                    <label class="label" for="mcp-url">MCP server for AI assistants</label>
                    <div class="flex gap-2">
                        <input id="mcp-url" :value="mcpUrl" readonly class="field font-mono text-xs" @focus="$event.target.select()">
                        <button type="button" class="btn-ghost w-20 shrink-0" @click="copy('mcp', mcpUrl)">{{ copied === 'mcp' ? 'Copied' : 'Copy' }}</button>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Add it as a custom connector (remote MCP server, Streamable HTTP) in Claude or another AI assistant. It reads sales, items, bills, voids and purchasing (orders, receipts, supplier prices, unpaid invoices) of this client, and can pull missing days from Quinos.
                    </p>
                </div>
                <div v-if="agentPrompt">
                    <label class="label" for="agent-prompt">Prompt for an AI agent</label>
                    <div class="flex gap-2">
                        <textarea id="agent-prompt" :value="agentPrompt" readonly rows="3" class="field font-mono text-xs" @focus="$event.target.select()" />
                        <button type="button" class="btn-ghost w-20 shrink-0 self-start" @click="copy('prompt', agentPrompt)">{{ copied === 'prompt' ? 'Copied' : 'Copy' }}</button>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Paste it into Claude Code, Claude Desktop or another agent: it installs the MCP server with this key and explains what it can do. It contains the key, so share it only with people who may see this client's data.
                    </p>
                </div>
            </template>
            <p v-else-if="hidden" class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
                A key is set, but it was made before keys could be shown again. Regenerate to see a key (the app then needs the new one).
            </p>
            <p v-else class="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">No API key yet. Generate one to give the mobile app access.</p>

            <div class="flex flex-wrap justify-between gap-2">
                <div class="flex gap-2">
                    <button type="button" class="btn-primary" :disabled="busy" @click="generate">
                        {{ busy ? 'Working…' : (token || hidden ? 'Regenerate' : 'Generate API key') }}
                    </button>
                    <button v-if="token || hidden" type="button" class="btn-danger" :disabled="busy" @click="remove">Remove</button>
                </div>
                <button type="button" class="btn-ghost" @click="emit('close')">Close</button>
            </div>
        </div>
    </div>
</template>
