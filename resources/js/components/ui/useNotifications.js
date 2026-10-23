import { readonly, ref } from 'vue';

export function useNotifications() {
    const items = ref([]);
    let nextId = 0;

    function notify(notification) {
        const id = ++nextId;
        items.value.push({ ...notification, id });

        return id;
    }

    function dismiss(id) {
        items.value = items.value.filter(item => item.id !== id);
    }

    return { items: readonly(items), notify, dismiss };
}
