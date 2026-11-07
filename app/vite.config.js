import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [laravel({ input: ['resources/js/dashboard.jsx'], refresh: true }), react()],
    build: { sourcemap: false },
    server: { watch: { ignored: ['**/storage/framework/views/**'] } },
});
