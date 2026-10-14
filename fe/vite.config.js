import path from 'path'
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import themeHtml from './vite-plugins/themeHtml.js'

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
      // Ensure unique filenames for each build to bust cache
      rollupOptions: {
        output: {
          // Use content hash in filenames for cache busting
          entryFileNames: 'assets/[name]-[hash].js',
          chunkFileNames: 'assets/[name]-[hash].js',
          assetFileNames: 'assets/[name]-[hash].[ext]'
        }
      },
      // Minification settings
      minify: 'esbuild', // Use esbuild for faster builds, or 'terser' for better compression
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
  }
})
