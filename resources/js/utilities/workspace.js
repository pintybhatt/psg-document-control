import { computed, inject, provide, ref, watch } from 'vue';
import { createJsonClient } from './jsonClient.js';

const workspaceKey = Symbol('workspace');

export function createWorkspace(userId, endpoint, csrfToken) {
    const contexts = ref([]);
    const siteKey = ref('');
    const unitKey = ref('');
    const loading = ref(false);
    const error = ref('');
    const storageKey = `dcms.workspace.${userId}`;
    const sites = computed(() => [...new Map(contexts.value.map(item => [`${item.company_id}:${item.site_id}`, item])).values()]);
    const units = computed(() => contexts.value.filter(item => `${item.company_id}:${item.site_id}` === siteKey.value));
    const selected = computed(() => units.value.find(item => item.key === unitKey.value));
    function remember() {
        try { localStorage.setItem(storageKey, JSON.stringify({ site: siteKey.value, unit: unitKey.value })); } catch { /* Persistence is optional. */ }
    }
    watch(siteKey, () => {
        if (!units.value.some(item => item.key === unitKey.value)) {
            unitKey.value = units.value.find(item => item.department_id === null)?.key ?? units.value[0]?.key ?? '';
        }
        remember();
    }, { flush: 'sync' });
    watch(unitKey, remember);
    async function refresh() {
        if (!endpoint) { return; }
        loading.value = true; error.value = ''; contexts.value = [];
        try {
            const result = await createJsonClient(csrfToken)(endpoint);
            let saved;
            try { saved = JSON.parse(localStorage.getItem(storageKey)); } catch { /* Ignore invalid or unavailable storage. */ }
            contexts.value = result.data;
            // Remembered values select only from the newly authorized server response.
            const requestedUnit = new URLSearchParams(window.location.search).get('unit');
            const linkedContext = contexts.value.find(item => item.key === requestedUnit);
            const validSite = linkedContext ?? sites.value.find(item => `${item.company_id}:${item.site_id}` === saved?.site) ?? sites.value[0];
            siteKey.value = validSite ? `${validSite.company_id}:${validSite.site_id}` : '';
            unitKey.value = units.value.find(item => item.key === requestedUnit)?.key ?? units.value.find(item => item.key === saved?.unit)?.key ?? units.value.find(item => item.department_id === null)?.key ?? units.value[0]?.key ?? '';
            remember();
        } catch (failure) { siteKey.value = ''; unitKey.value = ''; error.value = failure.message; }
        finally { loading.value = false; }
    }
    function revoke() { contexts.value = []; siteKey.value = ''; unitKey.value = ''; error.value = 'rbacForbidden'; }
    return { contexts, sites, siteKey, units, unitKey, selected, loading, error, refresh, revoke };
}

export function provideWorkspace(workspace) { provide(workspaceKey, workspace); }
export function useWorkspace() { return inject(workspaceKey, null); }
