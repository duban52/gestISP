import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // Toda entrada referenciada con @vite en una vista DEBE
            // estar aquí: en dev no se nota (no hay manifiesto),
            // pero en producción @vite busca la entrada en
            // public/build/manifest.json y lanza excepción si falta.
            input: [
                'resources/sass/app.scss',
                'resources/js/app.js',
                'resources/js/movements/movements.js',
                'resources/js/technical_orders/order_process.js',
            ],
            refresh: true,
        }),
    ],
});
