import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createWorkspace } from '../../resources/js/utilities/workspace.js';

const site = (id, department = null) => ({ key: `${id}:${department ?? 'site'}`, company_id: 1, site_id: id, department_id: department, permissions: ['documents.metadata.view'], company: { name_en: 'Company', name_lo: 'ບໍລິສັດ' }, site: { name_en: `Site ${id}`, name_lo: 'ສະຖານທີ່' } });
function server(data) { vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ data }) }))); }
beforeEach(() => { localStorage.clear(); window.history.replaceState({}, '', '/'); });
afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks(); });

describe('authorized shared workspace', () => {
    it('automatically selects the only authorized context', async () => {
        server([site(2)]);
        const workspace = createWorkspace(1, '/contexts', 'token');
        await workspace.refresh();
        expect(workspace.siteKey.value).toBe('1:2');
        expect(workspace.selected.value.key).toBe('2:site');
    });
    it('restores a valid workspace and department across module navigation', async () => {
        server([site(2), site(3), site(3, 9)]);
        const workspace = createWorkspace(1, '/contexts', 'token');
        await workspace.refresh();
        workspace.siteKey.value = '1:3'; workspace.unitKey.value = '3:9'; await nextTick();
        const nextModule = createWorkspace(1, '/contexts', 'token'); await nextModule.refresh();
        expect(nextModule.selected.value).toEqual(site(3, 9));
    });
    it('discards removed memberships and untrusted permissions from remembered selections', async () => {
        localStorage.setItem('dcms.workspace.1', JSON.stringify({ site: '1:3', unit: '3:9', permissions: ['incoming.drafts.manage'] }));
        server([site(2)]);
        const workspace = createWorkspace(1, '/contexts', 'token'); await workspace.refresh();
        expect(workspace.selected.value).toEqual(site(2));
        expect(workspace.contexts.value).toEqual([site(2)]);
    });
    it('keeps preferences isolated between accounts', async () => {
        localStorage.setItem('dcms.workspace.1', JSON.stringify({ site: '1:3', unit: '3:site' }));
        server([site(2), site(3)]);
        const workspace = createWorkspace(2, '/contexts', 'token'); await workspace.refresh();
        expect(workspace.selected.value.key).toBe('2:site');
    });
    it('honors a document deep link only for an eligible unit', async () => {
        window.history.replaceState({}, '', '/incoming?unit=2:9'); server([site(3), site(2), site(2, 9)]);
        localStorage.setItem('dcms.workspace.1', JSON.stringify({ site: '1:3', unit: '3:site' }));
        const workspace = createWorkspace(1, '/contexts', 'token'); await workspace.refresh();
        expect(workspace.selected.value.key).toBe('2:9');
        window.history.replaceState({}, '', '/incoming?unit=2:99'); await workspace.refresh();
        expect(workspace.selected.value.key).toBe('2:9');
    });
    it('clears sensitive context when authorization is revoked', async () => {
        server([site(2)]); const workspace = createWorkspace(1, '/contexts', 'token'); await workspace.refresh();
        workspace.revoke();
        expect(workspace.selected.value).toBeUndefined(); expect(workspace.contexts.value).toEqual([]);
        expect(workspace.error.value).toBe('rbacForbidden');
    });
    it('distinguishes request failure from no authorized workspaces', async () => {
        server([]); const workspace = createWorkspace(1, '/contexts', 'token'); await workspace.refresh();
        expect(workspace.error.value).toBe(''); expect(workspace.selected.value).toBeUndefined();
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500, json: async () => ({ message: 'Internal error' }) })));
        await workspace.refresh(); expect(workspace.error.value).toBe('rbacRequestFailed');
    });
    it('works when storage is unavailable or malformed', async () => {
        localStorage.setItem('dcms.workspace.1', 'broken json'); server([site(2)]);
        const workspace = createWorkspace(1, '/contexts', 'token'); await workspace.refresh();
        expect(workspace.selected.value.key).toBe('2:site');
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('Blocked'); });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('Blocked'); });
        await workspace.refresh(); expect(workspace.selected.value.key).toBe('2:site');
    });
});
