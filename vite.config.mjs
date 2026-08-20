import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    // Keep identifier names (so `__`, `_n`, `sprintf` survive minification and
    // `wp i18n make-pot` can extract translatable strings from the built bundle).
    esbuild: {
        minifyIdentifiers: false
    },
    build: {
        outDir: 'assets/build',
        cssCodeSplit: false,
        rollupOptions: {
            input: 'assets/js/migrator-app.js',
            output: {
                format: 'iife',
                entryFileNames: 'migrator-app.js',
                assetFileNames: 'migrator-app.[ext]'
            }
        }
    }
});
