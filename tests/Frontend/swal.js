import { flushPromises } from '@vue/test-utils';

// Helpers for driving the real SweetAlert2 popups (rendered into document.body) in jsdom.
const tick = () => new Promise(resolve => setTimeout(resolve, 0));

export async function settle() {
    await flushPromises();
    await tick();
    await flushPromises();
}

export function swalPopup() {
    return document.querySelector('.swal2-container .swal2-popup');
}

export function swalText() {
    return swalPopup()?.textContent ?? '';
}

export function swalTitle() {
    return document.querySelector('.swal2-container .swal2-title')?.textContent ?? '';
}

async function click(selector) {
    await settle();
    const button = document.querySelector(`.swal2-container ${selector}`);
    if (!button) {
        throw new Error(`SweetAlert button ${selector} is not shown`);
    }
    button.click();
    await settle();
}

export const swalConfirm = () => click('.swal2-confirm');
export const swalCancel = () => click('.swal2-cancel');
