import path from 'path'
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import themeHtml from './vite-plugins/themeHtml.js'

// Production configuration with environment variables defined
export default defineConfig(({ mode }) => {
  // Theme (base | monad) is fixed at build time: VITE_THEME from the environment or .env files
  const env = loadEnv(mode, process.cwd(), 'VITE_')
  const themeName = process.env.VITE_THEME || env.VITE_THEME || 'base'
  console.info('[theme] building with theme:', themeName)

  return {
    plugins: [
      themeHtml(themeName),
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
      // Production minification - use esbuild by default (faster)
      // Change to 'terser' if you need better compression and have terser installed
      minify: 'esbuild',
      // Disable source maps in production for smaller size
      sourcemap: false
    },
    // Define environment variables explicitly for production build
    define: {
      'import.meta.env.VITE_PRIVY_APP_ID': JSON.stringify(process.env.VITE_PRIVY_APP_ID || 'cmeb207py01ikky0csyj8akos'),
      'import.meta.env.VITE_MONAD_GAMES_APP_ID': JSON.stringify(process.env.VITE_MONAD_GAMES_APP_ID || 'cmd8euall0037le0my79qpz42'),
      'import.meta.env.VITE_API_BASE_URL': JSON.stringify(process.env.VITE_API_BASE_URL || 'https://monadungeon.avelent.work'),
      'import.meta.env.VITE_THEME': JSON.stringify(themeName),
    },
    server: {
      host: '0.0.0.0',
      port: 5173,
      strictPort: true,
      allowedHosts: ['monadungeon.avelent.work', 'sf.sf', 'localhost', '127.0.0.1'],
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
  }
})
