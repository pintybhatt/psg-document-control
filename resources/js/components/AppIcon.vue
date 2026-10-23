<script setup>
defineProps({ name: { type: String, required: true } });

const paths = {
    dashboard: 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
    incoming: 'M4 4h16v16H4z M12 7v9 M8 12l4 4 4-4',
    outgoing: 'M4 4h16v16H4z M12 17V8 M8 12l4-4 4 4',
    'controlled-documents': 'M6 3h8l4 4v14H6z M14 3v5h4 M9 12h6 M9 16h6',
    'document-search': 'M10 17a7 7 0 1 0 0-14 7 7 0 0 0 0 14z M15 15l6 6',
    reports: 'M4 3v18h17 M8 16v-5 M13 16V6 M18 16v-8',
    administration: 'M3 6h18 M3 12h18 M3 18h18 M8 3v6 M16 9v6 M10 15v6',
    organization: 'M4 21V3h12v18 M16 10h4v11 M8 7h4 M8 11h4 M8 15h4 M2 21h20',
    arrow: 'M5 12h14 M14 7l5 5-5 5',
    menu: 'M4 6h16 M4 12h16 M4 18h16',
    close: 'M6 6l12 12 M6 18 18 6',
};
</script>

<template>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path :d="paths[name] ?? paths['controlled-documents']" />
    </svg>
</template>
