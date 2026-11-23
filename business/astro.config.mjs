import { defineConfig } from 'astro/config';
import vue from '@astrojs/vue';
import tailwindcss from '@tailwindcss/vite';
export default defineConfig({
  integrations: [vue()], output: 'static', outDir: './dist/client', devToolbar:{enabled:false},
  vite: { plugins: [tailwindcss()], server: { proxy: { '/api': 'http://127.0.0.1:8080' } } }
});
