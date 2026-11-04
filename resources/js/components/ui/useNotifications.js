import { onScopeDispose, readonly, ref } from 'vue';

export function useNotifications() {
    const items = ref([]);
    let nextId = 0;
    const timers = new Map();

    function notify(notification) {
        const id = ++nextId;
        items.value.push({ ...notification, id });
        const duration = { success: 4000, info: 5000 }[notification.type];
        if (duration) {
            timers.set(id, setTimeout(() => dismiss(id), duration));
        }

        return id;
    }

    function dismiss(id) {
        clearTimeout(timers.get(id));
        timers.delete(id);
        items.value = items.value.filter(item => item.id !== id);
    }

    onScopeDispose(() => {
        for (const timer of timers.values()) { clearTimeout(timer); }
        timers.clear();
    });

    return { items: readonly(items), notify, dismiss };
}
