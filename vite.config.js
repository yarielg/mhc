import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
    plugins: [vue()],
    build: {
        outDir: 'assets/dist',
        emptyOutDir: true,
        cssCodeSplit: false,          // bundle all CSS into one file
        assetsDir: '',                // put CSS in outDir root
        rollupOptions: {
            input: 'src/vue/index.js',
            output: {
                format: 'iife',
                entryFileNames: 'app.js',
                assetFileNames: (chunkInfo) => {
                    // ensure CSS becomes app.css
                    if (chunkInfo.name && chunkInfo.name.endsWith('.css')) return 'app.css';
                    return '[name][extname]';
                }
            }
        },
        target: 'es2015'
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'vue-src'),
            'vue': 'vue/dist/vue.esm-bundler.js'
        }
    }
})