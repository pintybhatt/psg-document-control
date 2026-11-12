import { trackBusy } from './busy.js';

export function createJsonClient(csrfToken) {
    const send = createRequest(csrfToken);

    // Data-changing requests are tracked so a mounted BusyOverlay can show a loading spinner.
    // Read-only POSTs (previews) pass `busy: false`.
    return (url, options = {}) => (options.method ?? 'GET') === 'GET' || options.busy === false ? send(url, options) : trackBusy(() => send(url, options));
}

function createRequest(csrfToken) {
    return async (url, { method = 'GET', body, signal, responseType = 'json' } = {}) => {
        const multipart = body instanceof FormData;
        const response = await fetch(url, {
            method, signal, credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', ...(multipart ? {} : { 'Content-Type': 'application/json' }), 'X-CSRF-TOKEN': csrfToken },
            body: body === undefined ? undefined : multipart ? body : JSON.stringify(body),
        });
        if (response.status === 204) {
            return null;
        }
        if (response.ok && responseType === 'blob') {
            return { blob: await response.blob(), pages: Number(response.headers.get('X-PDF-Pages')) };
        }
        const data = await response.json();
        if (!response.ok) {
            const message = typeof data.message === 'string' && /^(rbac|membership|master|numbering|incoming|outgoing|controlled|storage|documentSecurity|confidentiality)/.test(data.message) ? data.message
                : (response.status === 403 ? 'rbacForbidden' : 'rbacRequestFailed');
            const error = new Error(response.status === 401 || response.status === 419 ? 'rbacSessionExpired' : message);
            error.status = response.status;
            error.fields = data.errors ?? {};
            throw error;
        }
        return data;
    };
}
