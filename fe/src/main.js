import { createApp, h, computed } from 'vue'
import { useRoute } from 'vue-router'
import './assets/main.css'
import router from './router'
import HomeView from './views/HomeView.vue'
import GameView from './views/GameView.vue'
import NotFoundView from './views/NotFoundView.vue'
import LeaderboardView from './views/LeaderboardView.vue'
import RulesView from './views/RulesView.vue'

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
      } else if (path === '/rules') {
        return RulesView
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
        background-color: #0a0a0a;
        min-height: 100vh;
      `
    }, [
      
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
          margin: 0;
          padding: 0;
        `
      }, [
        h(CustomRouterView)
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