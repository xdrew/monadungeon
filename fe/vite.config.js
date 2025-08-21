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
