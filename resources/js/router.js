import { createRouter, createWebHistory } from 'vue-router';
import { BASE } from './api';

const router = createRouter({
    history: createWebHistory(BASE || '/'),
    routes: [
        { path: '/setup', name: 'setup', component: () => import('./views/Setup.vue') },
        { path: '/', redirect: '/dashboard' },
        { path: '/dashboard', name: 'dashboard', component: () => import('./views/Dashboard.vue') },
        { path: '/sync', name: 'sync', component: () => import('./views/Sync.vue') },
        { path: '/scheduler', name: 'scheduler', component: () => import('./views/Scheduler.vue') },
        { path: '/connection', name: 'connection', component: () => import('./views/Connection.vue') },
        { path: '/local-runner', redirect: '/scheduler' },
        { path: '/:pathMatch(.*)*', redirect: '/' },
    ],
});
router.beforeEach(async (to) => {
    try {
        const response = await fetch(`${BASE}/api/setup/status`, { headers: { Accept: 'application/json' } });
        const status = await response.json();
        if (!status.ready && to.name !== 'setup') return { name: 'setup' };
    } catch {
        if (to.name !== 'setup') return { name: 'setup' };
    }
    return true;
});
export default router;
