import { readonly, ref } from 'vue';

const count = ref(0);

/** Number of data-changing requests in flight. BusyOverlay shows a spinner while it is above zero. */
export const busyCount = readonly(count);

export async function trackBusy(task) {
    count.value++;
    try {
        return await task();
    } finally {
        count.value--;
    }
}
