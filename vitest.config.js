import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } })],
    test: {
        environment: 'jsdom',
        include: ['tests/Frontend/**/*.test.js'],
        setupFiles: ['tests/Frontend/setup.js'],
        restoreMocks: true,
        // vanillajs-datepicker ships untranspiled ESM that touches `window`; inline it so it runs against jsdom.
        server: { deps: { inline: ['vanillajs-datepicker'] } },
    },
});
