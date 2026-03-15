import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            'vue': 'vue/dist/vue.esm-bundler.js'
        }
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
