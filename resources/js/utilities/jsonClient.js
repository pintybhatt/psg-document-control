export function createJsonClient(csrfToken) {
    return async (url, { method = 'GET', body, signal } = {}) => {
        const response = await fetch(url, {
            method, signal, credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        if (response.status === 204) {
            return null;
        }
        const data = await response.json();
        if (!response.ok) {
            const message = typeof data.message === 'string' && /^(rbac|membership|master|numbering|incoming)/.test(data.message) ? data.message
                : (response.status === 403 ? 'rbacForbidden' : 'rbacRequestFailed');
            const error = new Error(response.status === 401 || response.status === 419 ? 'rbacSessionExpired' : message);
            error.status = response.status;
            error.fields = data.errors ?? {};
            throw error;
        }
        return data;
    };
}
