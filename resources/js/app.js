import { createApp } from 'vue';
import App from './App.vue';
import { createLocalization } from './localization/index.js';

export function mountApplication(element) {
    const props = JSON.parse(element.dataset.props);
    const application = createApp(App, props);

    application.use(createLocalization(props.initialLanguage));
    application.mount(element);

    return application;
}

const element = document.getElementById('dcms-app');

export const application = element ? mountApplication(element) : null;
