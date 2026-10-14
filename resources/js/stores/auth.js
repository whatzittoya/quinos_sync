import { defineStore } from 'pinia';
import { api, ApiError, selectedClientId } from '../api';

export const useAuth = defineStore('auth', {
    state: () => ({ user: null, loaded: false, clients: [], clientId: selectedClientId.get() }),

    getters: {
        isAdmin: (s) => s.user?.role === 'admin',
        // The client whose data is on screen: own client, or the admin's pick.
        activeClient(s) {
            if (!s.user) return null;
            if (s.user.role === 'client') return s.user.client;
            return s.clients.find((c) => String(c.id) === String(s.clientId)) ?? null;
        },
    },

    actions: {
        async fetchUser() {
            try {
                const { data } = await api('/me');
                this.user = data;
                if (this.isAdmin) await this.fetchClients();
            } catch (e) {
                if (!(e instanceof ApiError) || e.status !== 401) throw e;
                this.user = null;
            } finally {
                this.loaded = true;
            }
        },

        async fetchClients() {
            const { data } = await api('/admin/clients');
            this.clients = data;
            if (!data.some((c) => String(c.id) === String(this.clientId))) {
                this.setClient(data[0]?.id ?? null);
            }
        },

        setClient(id) {
            this.clientId = id;
            selectedClientId.set(id);
        },

        async login(credentials) {
            const { data } = await api('/login', { method: 'POST', body: credentials });
            this.user = data;
            if (this.isAdmin) await this.fetchClients();
        },

        async logout() {
            await api('/logout', { method: 'POST' }).catch(() => {});
            this.user = null;
            this.clients = [];
        },
    },
});
