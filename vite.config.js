import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// docker-dev runs `npm run dev` inside the container (VITE_DOCKER=true):
// listen on all interfaces and point the browser at the published port.
const docker = process.env.VITE_DOCKER === 'true';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        ...(docker && {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            hmr: { host: process.env.VITE_HMR_HOST || 'localhost' },
        }),
        watch: {
            usePolling: process.env.VITE_POLLING === 'true',
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
