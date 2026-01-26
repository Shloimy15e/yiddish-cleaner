import '../css/app.css';
import 'tippy.js/dist/tippy.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import VueTippy from 'vue-tippy';
import { ZiggyVue } from 'ziggy-js';

import { initializeTheme } from './composables/useAppearance';

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
