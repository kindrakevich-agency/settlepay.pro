import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'node:path';

export default defineConfig({
  plugins: [react(), tailwindcss()],

  // Symfony serves built assets from /public/build/
  build: {
    outDir: 'public/build',
    manifest: true,
    rollupOptions: {
      input: {
        app:       resolve(__dirname, 'assets/styles/app.css'),
        checkout:  resolve(__dirname, 'assets/checkout/checkout.ts'),
        dashboard: resolve(__dirname, 'assets/dashboard/main.tsx'),
      },
      output: {
        entryFileNames:  'assets/[name]-[hash].js',
        chunkFileNames:  'assets/[name]-[hash].js',
        assetFileNames:  'assets/[name]-[hash][extname]',
      },
    },
  },

  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    origin: 'http://localhost:5173',
    cors: true,
  },
});
