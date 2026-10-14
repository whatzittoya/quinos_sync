// Thin fetch wrapper for the same-origin Laravel API (Sanctum cookie + CSRF).

export class ApiError extends Error {
    constructor(status, message, errors = {}) {
        super(message);
        this.status = status;
        this.errors = errors;
    }
}

// Folder the app is served from: '' on its own server, '/local_sync' when XAMPP serves it from htdocs.
export const BASE = document.querySelector('meta[name="app-base"]')?.content ?? '';

const CLIENT_KEY = 'quinos.clientId';

export const selectedClientId = {
    get: () => {
        try { return localStorage.getItem(CLIENT_KEY); } catch { return null; }
    },
    set: (id) => {
        try { id ? localStorage.setItem(CLIENT_KEY, String(id)) : localStorage.removeItem(CLIENT_KEY); } catch { /* ignore */ }
    },
};

function xsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : null;
}

let onUnauthorized = () => {};
export const setUnauthorizedHandler = (fn) => { onUnauthorized = fn; };

export async function api(path, { method = 'GET', body, query } = {}) {
    const url = new URL(`${BASE}/api${path}`, window.location.origin);
    Object.entries(query ?? {}).forEach(([k, v]) => v != null && url.searchParams.set(k, v));

    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (xsrfToken()) headers['X-XSRF-TOKEN'] = xsrfToken();
    const clientId = selectedClientId.get();
    if (clientId) headers['X-Client-Id'] = clientId;

    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    const data = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) {
        if (response.status === 401 && path !== '/me' && path !== '/login') onUnauthorized();
        throw new ApiError(response.status, data?.message ?? `Request failed (${response.status})`, data?.errors ?? {});
    }

    return data;
}

// Download a file from the API (needs the same headers as api(), so a plain link does not work).
export async function download(path, { query } = {}) {
    const url = new URL(`${BASE}/api${path}`, window.location.origin);
    Object.entries(query ?? {}).forEach(([k, v]) => v != null && url.searchParams.set(k, v));

    const headers = { 'X-Requested-With': 'XMLHttpRequest' };
    const clientId = selectedClientId.get();
    if (clientId) headers['X-Client-Id'] = clientId;

    const response = await fetch(url, { headers, credentials: 'same-origin' });

    if (!response.ok) {
        const data = await response.json().catch(() => null);
        throw new ApiError(response.status, data?.message ?? `Download failed (${response.status})`, data?.errors ?? {});
    }

    const name = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? 'report.xlsx';
    const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(await response.blob()), download: name });
    link.click();
    URL.revokeObjectURL(link.href);
}
