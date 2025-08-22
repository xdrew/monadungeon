import path from 'path'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Production configuration with environment variables defined
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
    // Ensure unique filenames with content hash for automatic cache busting
    rollupOptions: {
      output: {
        // Content hash in filenames - changes when file content changes
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash].[ext]'
      }
    },
    // Clear output directory before build
    emptyOutDir: true,
    // Generate manifest.json for asset mapping
    manifest: true,
    // Production minification
    minify: 'terser',
    terserOptions: {
      compress: {
        drop_console: true,
        drop_debugger: true
      }
    },
    // Disable source maps in production for smaller size
    sourcemap: false
  },
  // Define environment variables explicitly for production build
  define: {
    'import.meta.env.VITE_PRIVY_APP_ID': JSON.stringify(process.env.VITE_PRIVY_APP_ID || 'cmeb207py01ikky0csyj8akos'),
    'import.meta.env.VITE_MONAD_GAMES_APP_ID': JSON.stringify(process.env.VITE_MONAD_GAMES_APP_ID || 'cmd8euall0037le0my79qpz42'),
    'import.meta.env.VITE_API_BASE_URL': JSON.stringify(process.env.VITE_API_BASE_URL || 'https://monadungeon.xyz'),
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    allowedHosts: ['monadungeon.xyz', 'sf.sf', 'localhost', '127.0.0.1'],
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