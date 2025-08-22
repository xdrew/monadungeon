import path from 'path'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [
    vue({
      template: {
        compilerOptions: {
          isCustomElement: tag => tag.includes('-')
        }
      },
      script: {
        // Enable JSX/TSX support in Vue components
        babelParserPlugins: ['jsx', 'typescript']
      }
    })
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
  build: {
    // Ensure unique filenames for each build to bust cache
    rollupOptions: {
      output: {
        // Use content hash in filenames for cache busting
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash].[ext]'
      }
    },
    // Add build timestamp as a comment in the output
    minify: 'terser',
    terserOptions: {
      format: {
        comments: false,
      },
    },
    // Generate manifest for asset mapping
    manifest: true,
    // Clear output directory before build
    emptyOutDir: true,
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    allowedHosts: ['sf.sf'],
    proxy: {
      // Proxy API requests to backend during development
      '/api': {
        target: 'http://php_monadungeon:8080',
        changeOrigin: true,
        secure: false,
      }
    }
  },
  esbuild: {
    loader: {
      '.vue': 'jsx'
    },
    jsxFactory: 'h',
    jsxFragment: 'Fragment'
  }
})
