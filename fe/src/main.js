import { createApp, h, computed } from 'vue'
import { useRoute } from 'vue-router'
import './assets/main.css'
import router from './router'
import HomeView from './views/HomeView.vue'
import GameView from './views/GameView.vue'
import NotFoundView from './views/NotFoundView.vue'
import LeaderboardView from './views/LeaderboardView.vue'

// ----------------
// Define Game Components
// ----------------

// Home component is now imported from HomeView.vue
// Game component is now imported from GameView.vue
// NotFound component is now imported from NotFoundView.vue

// ----------------
// Custom Router View Component
// ----------------
const CustomRouterView = {
  name: 'CustomRouterView',
  setup() {
    // Get current route
    const route = useRoute()
    
    // Compute which component to show based on route
    const currentComponent = computed(() => {
      const path = route.path
      
      if (path === '/') {
        return HomeView
      } else if (path.startsWith('/game')) {
        return GameView
      } else if (path === '/leaderboard') {
        return LeaderboardView
      } else {
        return NotFoundView
      }
    })
    
    return () => {
      // Render the current component
      return h('div', { class: 'custom-router-view' }, [
        h(currentComponent.value)
      ])
    }
  }
}

// Router is now imported from ./router/index.js

// ----------------
// Create App Component
// ----------------
const App = {
  name: 'App',
  render() {
    return h('div', { 
      id: 'app-container', 
      style: `
        color: white;
        background-color: #111;
        background-image: linear-gradient(to bottom, #1a1a1a, #111);
        min-height: 100vh;
        padding-bottom: 40px;
      `
    }, [
      // Header
      h('header', { 
        style: `
          background-color: #222;
          padding: 15px 0;
          box-shadow: 0 2px 10px rgba(0,0,0,0.5);
          margin-bottom: 30px;
        `
      }, [
        h('h1', { 
          style: 'color: #ffd700; text-align: center; margin: 0; text-shadow: 1px 1px 3px #000;'
        }, 'Monadungeon: Adventure')
      ]),
      
      // Navigation
      // h('nav', {
      //   style: `
      //     display: flex;
      //     justify-content: center;
      //     gap: 20px;
      //     margin: 20px 0 30px;
      //   `
      // }, [
      //   h('button', {
      //     style: `
      //       background-color: #333;
      //       color: white;
      //       padding: 10px 20px;
      //       border: 2px solid #555;
      //       border-radius: 5px;
      //       cursor: pointer;
      //       font-weight: bold;
      //       transition: all 0.2s ease;
      //     `,
      //     onMouseover: (event) => {
      //       event.target.style.backgroundColor = '#444';
      //       event.target.style.borderColor = '#777';
      //     },
      //     onMouseout: (event) => {
      //       event.target.style.backgroundColor = '#333';
      //       event.target.style.borderColor = '#555';
      //     },
      //     onClick: () => router.push('/')
      //   }, 'Home'),
      //   h('button', {
      //     style: `
      //       background-color: #333;
      //       color: white;
      //       padding: 10px 20px;
      //       border: 2px solid #555;
      //       border-radius: 5px;
      //       cursor: pointer;
      //       font-weight: bold;
      //       transition: all 0.2s ease;
      //     `,
      //     onMouseover: (event) => {
      //       event.target.style.backgroundColor = '#444';
      //       event.target.style.borderColor = '#777';
      //     },
      //     onMouseout: (event) => {
      //       event.target.style.backgroundColor = '#333';
      //       event.target.style.borderColor = '#555';
      //     },
      //     onClick: () => router.push('/game')
      //   }, 'Play Game')
      // ]),

      // Main content container with router view
      h('main', { 
        style: `
          margin: 0 auto;
          padding: 0 20px;
        `
      }, [
        h(CustomRouterView)
      ]),
      
      // Footer
      h('footer', {
        style: `
          text-align: center;
          margin-top: 60px;
          padding-top: 20px;
          border-top: 1px solid #333;
          color: #777;
          font-size: 14px;
        `
      }, [
        h('p', null, 'Monadungeon Adventure © 2025'),
        h('p', { style: 'margin-top: 5px; font-size: 12px;' }, 'A Vue.js Game Application')
      ])
    ])
  }
}

// ----------------
// Create & Mount App
// ----------------
const app = createApp(App)

// Add global error handler
app.config.errorHandler = (err, vm, info) => {
  console.error('Global error:', err)
  console.error('Error info:', info)
}

// Use router
app.use(router)

// Mount the app
app.mount('#app')