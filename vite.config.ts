import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import AutoImport from 'unplugin-auto-import/vite';
import Components from 'unplugin-vue-components/vite';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            ssr: 'resources/js/ssr.ts',
            refresh: true,
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
        AutoImport({
            imports: [
                'vue',
                {
                    '@inertiajs/vue3': ['router', 'useForm', 'usePage'],
                    'ziggy-js': ['route'],
                },
            ],
            dirs: [
                './resources/js/composables/**',
                './resources/js/lib/**',
            ],
            dts: './resources/js/auto-imports.d.ts',
            vueTemplate: true,
        }),
        Components({
            dirs: [
                './resources/js/components',
                './resources/js/layouts',
            ],
            dts: './resources/js/components.d.ts',
            resolvers: [
                // Auto-import Inertia's Link component
                (componentName) => {
                    if (componentName === 'Link') {
                        return { name: 'Link', from: '@inertiajs/vue3' };
                    }
                    if (componentName === 'Head') {
                        return { name: 'Head', from: '@inertiajs/vue3' };
                    }
                },
            ],
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});
