import { defineConfig } from 'vite';
import legacy from '@vitejs/plugin-legacy';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    build: {
        // Preserve conventional CSS fallbacks; JS targets alone do not set CSS compatibility.
        cssTarget: ['chrome70', 'safari12', 'firefox78', 'edge79'],
    },
    plugins: [
        legacy({
            targets: [
                'defaults',
                'iOS >= 12',
                'Safari >= 12',
                'Chrome >= 70',
                'Edge >= 79',
                'Firefox >= 78',
                'Android >= 8',
            ],
            modernPolyfills: true,
            renderLegacyChunks: true,
        }),
        laravel({
            input: ['resources/css/app.css', 'resources/css/operations.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
