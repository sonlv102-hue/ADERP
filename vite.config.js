import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const hmrHost = env.VITE_HMR_HOST || 'localhost';
    const hmrPort = parseInt(env.VITE_PORT || '5173');

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                // Live Reload: tự reload trang khi các file PHP/Blade thay đổi
                refresh: [
                    'resources/views/**',
                    'app/Http/**/*.php',
                    'routes/**/*.php',
                ],
            }),
            // HMR: cập nhật Vue component tức thì, không reload toàn trang
            vue({
                template: {
                    transformAssetUrls: { base: null, includeAbsolute: false },
                },
            }),
        ],
        resolve: {
            alias: { '@': '/resources/js' },
        },
        server: {
            host: '0.0.0.0',
            port: hmrPort,
            cors: true,
            // Hot Module Replacement — phải khớp VITE_HMR_HOST trong .env
            hmr: {
                host: hmrHost,
                port: hmrPort,
            },
            watch: {
                usePolling: false,
                ignored: [
                    '**/storage/framework/views/**',
                    '**/vendor/**',
                    '**/.git/**',
                ],
            },
        },
    };
});
