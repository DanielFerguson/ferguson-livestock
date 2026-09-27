import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // No JavaScript entry yet: pages ship zero JS until the live stock script arrives.
            input: ['resources/css/app.css'],
            assets: ['resources/images/**'],
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
