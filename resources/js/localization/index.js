import { inject, readonly, ref } from 'vue';
import en from './en.js';
import lo from './lo.js';

const localizationKey = Symbol('localization');
const dictionaries = { en, lo };
const storageKey = 'dcms.language';

export function createLocalization() {
    let savedLanguage;

    try {
        savedLanguage = window.localStorage.getItem(storageKey);
    } catch {
        // Storage can be unavailable in restricted browser sessions.
    }

    const language = ref(Object.hasOwn(dictionaries, savedLanguage) ? savedLanguage : 'en');

    function translate(key) {
        return dictionaries[language.value][key] ?? en[key] ?? key;
    }

    function setLanguage(value) {
        if (!Object.hasOwn(dictionaries, value)) {
            return;
        }

        language.value = value;
        document.documentElement.lang = value;

        try {
            window.localStorage.setItem(storageKey, value);
        } catch {
            // Keep switching functional even when persistence is unavailable.
        }
    }

    return {
        install(application) {
            document.documentElement.lang = language.value;
            application.provide(localizationKey, { language: readonly(language), setLanguage, t: translate });
        },
    };
}

export function useLocalization() {
    return inject(localizationKey);
}
