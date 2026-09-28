import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // The live stock script runs on every page; the order form's script only on /order. The theme is the admin panel's.
            input: ['resources/css/app.css', 'resources/js/drop-status.js', 'resources/js/order-form.js', 'resources/css/filament/admin/theme.css'],
            // Responsive variants built by scripts/build-images.mjs, referenced with Vite::asset().
            assets: ['resources/images/generated/**'],
            refresh: true,
            // Aliases are mapped onto Tailwind's font tokens in resources/css/app.css.
            fonts: [
                local('Source Sans 3', {
                    alias: 'source-sans',
                    variants: [{ src: 'resources/fonts/source-sans-3-latin-wght-normal.woff2', weight: '200 900' }],
                    fallbacks: ['Arial', 'sans-serif'],
                }),
                local('Cormorant Garamond', {
                    alias: 'cormorant',
                    variants: [{ src: 'resources/fonts/cormorant-garamond-latin-600-normal.woff2', weight: 600 }],
                    fallbacks: ['Georgia', 'serif'],
                }),
                // Only used for the signature on the two confirmation pages, so never preloaded.
                local('Caveat', {
                    alias: 'caveat',
                    variants: [{ src: 'resources/fonts/caveat-latin-400-normal.woff2', weight: 400 }],
                    preload: false,
                    fallbacks: ['cursive'],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
