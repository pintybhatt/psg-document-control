import { createApp } from 'vue';
import App from './App.vue';
import { createLocalization } from './localization/index.js';

export function mountApplication(element) {
    const application = createApp(App, JSON.parse(element.dataset.props));

    application.use(createLocalization());
    application.mount(element);

    return application;
}

const element = document.getElementById('dcms-app');

export const application = element ? mountApplication(element) : null;
