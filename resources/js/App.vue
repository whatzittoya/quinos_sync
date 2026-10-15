<script setup>
import { ref, watch } from 'vue';
import { useRoute, RouterLink, RouterView } from 'vue-router';
import Icon from './components/Icon.vue';

const route = useRoute();

const nav = [
    { label: 'Dashboard', icon: 'home', to: '/dashboard' },
    { label: 'Sync', icon: 'calendar', to: '/sync' },
    { label: 'Scheduler', icon: 'clock', to: '/scheduler' },
    { label: 'Connection', icon: 'key', to: '/connection' },
];

const KEY = 'quinos.sidebarCollapsed';
const read = () => { try { return localStorage.getItem(KEY) === '1'; } catch { return false; } };
const collapsed = ref(read()); // desktop: narrow icon rail
const drawer = ref(false); // mobile: sidebar slides over the page

const toggleCollapsed = () => {
    collapsed.value = !collapsed.value;
    try { localStorage.setItem(KEY, collapsed.value ? '1' : '0'); } catch { /* ignore */ }
};

const isActive = (to) => route.path === to || route.path.startsWith(`${to}/`);
watch(() => route.path, () => { drawer.value = false; });
</script>

<template>
    <RouterView v-if="route.name === 'setup'" />
    <div v-else class="flex min-h-screen">
        <div v-if="drawer" class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" @click="drawer = false" />

        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-slate-200 bg-white transition-all lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
            :class="[drawer ? 'translate-x-0' : '-translate-x-full', collapsed ? 'lg:w-16' : 'lg:w-64']"
        >
            <div class="flex h-14 items-center gap-2 border-b border-slate-200 px-4">
                <span class="truncate font-semibold tracking-tight" :class="collapsed && 'lg:hidden'">Quinos Local Sync</span>
                <button
                    class="ml-auto hidden rounded-md p-1.5 text-slate-500 hover:bg-slate-100 lg:block"
                    :class="collapsed && '!mx-auto'"
                    :aria-label="collapsed ? 'Expand menu' : 'Collapse menu'"
                    @click="toggleCollapsed"
                >
                    <Icon :name="collapsed ? 'expand' : 'collapse'" />
                </button>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto p-2">
                <RouterLink
                    v-for="item in nav" :key="item.to" :to="item.to" :title="collapsed ? item.label : undefined"
                    class="flex items-center gap-3 rounded-md px-3 py-2 text-sm transition"
                    :class="[isActive(item.to) ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100', collapsed && 'lg:justify-center']"
                >
                    <Icon :name="item.icon" />
                    <span :class="collapsed && 'lg:hidden'">{{ item.label }}</span>
                </RouterLink>
            </nav>

            <div class="border-t border-slate-200 p-2">
                <RouterLink to="/setup" class="flex items-center gap-3 rounded-md px-3 py-2 text-sm text-slate-500 hover:bg-slate-100" :class="collapsed && 'lg:justify-center'" :title="collapsed ? 'Setup' : undefined">
                    <Icon name="key" />
                    <span :class="collapsed && 'lg:hidden'">Setup</span>
                </RouterLink>
            </div>
        </aside>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-slate-200 bg-white px-4 lg:hidden">
                <button class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100" aria-label="Open menu" @click="drawer = true">
                    <Icon name="menu" />
                </button>
                <span class="font-semibold tracking-tight">Quinos Local Sync</span>
            </header>

            <main class="w-full px-4 py-6" :class="!route.meta.wide && 'max-w-6xl'">
                <RouterView />
            </main>
        </div>
    </div>
</template>
