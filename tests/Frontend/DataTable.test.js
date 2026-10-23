import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';
import { ref } from 'vue';
import DataTable from '../../resources/js/components/ui/DataTable.vue';
import { createLocalization } from '../../resources/js/localization/index.js';

enableAutoUnmount(afterEach);
afterEach(() => document.body.replaceChildren());
beforeEach(() => localStorage.clear());

const columns = [
    { key: 'reference', label: 'Reference', type: 'reference', sortable: true },
    { key: 'date', label: 'Date', type: 'date', sortable: true },
    { key: 'title', label: 'Title' },
];
const rows = [
    { id: 1, reference: 'DEMO-10', date: '2026-01-02', title: 'Tenth' },
    { id: 2, reference: 'DEMO-2', date: '2025-12-31', title: 'Second' },
    { id: 3, reference: 'DEMO-1', date: null, title: 'First' },
];

function render(props = {}, slots = {}) {
    return mount(DataTable, {
        attachTo: document.body,
        props: { columns, rows, caption: 'Fictional samples', perPage: 2, ...props },
        slots,
        global: { plugins: [createLocalization()] },
    });
}

function references(wrapper) {
    return wrapper.findAll('tbody tr').map(row => row.findAll('td')[0].text());
}

describe('enterprise data table', () => {
    it('uses accessible headers and keyboard-focusable sorting and scrolling controls', () => {
        const wrapper = render();

        expect(wrapper.get('caption').text()).toContain('Fictional samples');
        expect(wrapper.get('[role="region"]').attributes('tabindex')).toBe('0');
        expect(wrapper.get('[role="region"]').attributes('aria-label')).toBe('Fictional samples');
        expect(wrapper.findAll('th').every(header => header.attributes('scope') === 'col')).toBe(true);
        expect(wrapper.findAll('th button')).toHaveLength(2);
        const sortButton = wrapper.get('th button');
        sortButton.element.focus();
        expect(document.activeElement).toBe(sortButton.element);
        expect(sortButton.attributes('aria-label')).toBe('Sort Reference ascending');
        expect(wrapper.get('tbody tr').findAll('td')[1].text()).toBe('02/01/2026');
    });

    it('sorts naturally in both directions before pagination without mutating the input rows', async () => {
        const wrapper = render();

        await wrapper.get('th button').trigger('click');
        expect(references(wrapper)).toEqual(['DEMO-1', 'DEMO-2']);
        expect(wrapper.get('th').attributes('aria-sort')).toBe('ascending');
        expect(wrapper.emitted('change').at(-1)).toEqual([{ page: 1, perPage: 2, sort: { key: 'reference', direction: 'asc' } }]);
        await wrapper.get('th button').trigger('click');
        expect(references(wrapper)).toEqual(['DEMO-10', 'DEMO-2']);
        expect(wrapper.get('th').attributes('aria-sort')).toBe('descending');
        expect(rows.map(row => row.id)).toEqual([1, 2, 3]);
    });

    it('sorts ISO dates chronologically and keeps missing values last in both directions', async () => {
        const wrapper = render({ perPage: 5 });
        const dateButton = wrapper.findAll('th button')[1];

        await dateButton.trigger('click');
        expect(references(wrapper)).toEqual(['DEMO-2', 'DEMO-10', 'DEMO-1']);
        await dateButton.trigger('click');
        expect(references(wrapper)).toEqual(['DEMO-10', 'DEMO-2', 'DEMO-1']);
    });

    it('paginates with boundary controls and returns to page one on sorting', async () => {
        const wrapper = render();
        const buttons = wrapper.findAll('nav button');

        expect(buttons[0].element.disabled).toBe(true);
        expect(wrapper.get('[role="status"]').text()).toBe('Page 1 of 2 · 3 records');
        await buttons[1].trigger('click');
        expect(references(wrapper)).toEqual(['DEMO-1']);
        expect(buttons[1].element.disabled).toBe(true);
        expect(wrapper.emitted('update:page').at(-1)).toEqual([2]);
        await buttons[0].trigger('click');
        expect(references(wrapper)).toEqual(['DEMO-10', 'DEMO-2']);
        await buttons[1].trigger('click');
        await wrapper.get('th button').trigger('click');
        expect(wrapper.get('[role="status"]').text()).toBe('Page 1 of 2 · 3 records');
    });

    it('clamps the current page when filtering reduces the available rows', async () => {
        const wrapper = render({ page: 2 });

        await wrapper.setProps({ rows: [rows[0]] });

        expect(references(wrapper)).toEqual(['DEMO-10']);
        expect(wrapper.emitted('update:page').at(-1)).toEqual([1]);
    });

    it('announces loading and empty states and prevents actions while loading', async () => {
        const wrapper = render({ loading: true });

        expect(wrapper.get('table').attributes('aria-busy')).toBe('true');
        expect(wrapper.get('tbody').text()).toBe('Loading…');
        expect(wrapper.get('tbody').text()).not.toContain('DEMO-10');
        expect(wrapper.findAll('button').every(button => button.element.disabled)).toBe(true);
        await wrapper.setProps({ loading: false, rows: [] });
        expect(wrapper.get('tbody').text()).toBe('No records to display.');
        expect(wrapper.findAll('nav button').every(button => button.element.disabled)).toBe(true);
        expect(wrapper.get('[role="status"]').text()).toBe('Page 1 of 1 · 0 records');
    });

    it('passes server-side sort and page requests to the parent without reordering or slicing server rows', async () => {
        const wrapper = render({ serverSide: true, total: 100, page: 2, perPage: 5, rows: rows.slice(0, 2) });

        expect(references(wrapper)).toEqual(['DEMO-10', 'DEMO-2']);
        expect(wrapper.get('[role="status"]').text()).toBe('Page 2 of 20 · 100 records');
        await wrapper.findAll('nav button')[1].trigger('click');
        expect(wrapper.emitted('change').at(-1)[0].page).toBe(3);
        await wrapper.get('th button').trigger('click');
        expect(wrapper.emitted('change').at(-1)).toEqual([{ page: 1, perPage: 5, sort: { key: 'reference', direction: 'asc' } }]);
        expect(references(wrapper)).toEqual(['DEMO-10', 'DEMO-2']);
    });

    it('supports cell slots and escapes unslotted data', () => {
        const wrapper = render({ rows: [{ id: 'sample', reference: '<img src=x onerror=alert(1)>', date: 'invalid', title: 'Example' }] }, { 'cell-title': '<strong>Custom presentation</strong>' });

        expect(wrapper.get('tbody').text()).toContain('<img src=x onerror=alert(1)>');
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.get('strong').text()).toBe('Custom presentation');
        expect(wrapper.get('tbody tr').findAll('td')[1].text()).toBe('—');
    });

    it('emits the requested state immediately when page and sort are bound to a parent', async () => {
        const wrapper = mount({
            components: { DataTable },
            setup: () => ({ page: ref(2), sort: ref({ key: null, direction: 'asc' }), requests: ref([]), columns, rows }),
            template: '<DataTable v-model:page="page" v-model:sort="sort" :columns="columns" :rows="rows" server-side :total="30" :per-page="5" caption="Samples" @change="requests.push($event)" />',
        }, { global: { plugins: [createLocalization()] } });

        await wrapper.findAll('nav button')[1].trigger('click');
        expect(wrapper.vm.requests.at(-1)).toEqual({ page: 3, perPage: 5, sort: { key: null, direction: 'asc' } });
        await wrapper.get('th button').trigger('click');
        expect(wrapper.vm.requests.at(-1)).toEqual({ page: 1, perPage: 5, sort: { key: 'reference', direction: 'asc' } });
        await wrapper.get('th button').trigger('click');
        expect(wrapper.vm.requests.at(-1).sort.direction).toBe('desc');
    });

    it('localizes pagination and sorting instructions in Lao', () => {
        localStorage.setItem('dcms.language', 'lo');
        const wrapper = render();

        expect(wrapper.get('[role="status"]').text()).toBe('ໜ້າ 1 ຈາກ 2 · 3 ລາຍການ');
        expect(wrapper.get('th button').attributes('aria-label')).toBe('ຈັດລຽງ Reference ຈາກນ້ອຍຫາຫຼາຍ');
    });
});
