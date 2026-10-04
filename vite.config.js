import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/join-us.js', 'resources/css/filament/admin/theme.css'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                // Arabic glyphs for the Filament dashboard (Inter has none). The face is limited to the Arabic
                // unicode range, so Latin text keeps using Inter and the file is only fetched when needed.
                bunny('Noto Sans Arabic', {
                    weights: [400, 500, 600, 700],
                    subsets: ['arabic'],
                    preload: false,
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
