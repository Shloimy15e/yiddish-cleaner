import 'tippy.js/dist/tippy.css';
import '../css/app.css';

import { createApp, h } from 'vue';

import type { DefineComponent } from 'vue';
import VueTippy from 'vue-tippy';
import { ZiggyVue } from 'ziggy-js';
import axios from 'axios';
import { configureEcho } from '@laravel/echo-vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from './composables/useAppearance';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

// Configure axios with CSRF token
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
if (csrfToken) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
}

configureEcho({
    broadcaster: 'reverb',
});

const appName = import.meta.env.VITE_APP_NAME || 'Yiddish Cleaner';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(VueTippy, {
                defaultProps: {
                    placement: 'top',
                    theme: 'dark',
                },
            })
            .mount(el);
    },
    progress: {
        color: '#00d4ff',
    },
});

// This will set light / dark mode on page load...
initializeTheme();
