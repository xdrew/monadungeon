import { createRouter, createWebHistory } from 'vue-router';
import HomeView from '@/views/HomeView.vue';
import GameView from '@/views/GameView.vue';
import NotFoundView from '@/views/NotFoundView.vue';
import LeaderboardView from '@/views/LeaderboardView.vue';
import RulesView from '@/views/RulesView.vue';

const routes = [
  {
    path: '/',
    name: 'home',
    component: HomeView,
    meta: { title: 'Home' }
  },
  {
    path: '/game/:id',
    name: 'game',
    component: GameView,
    props: true,
    meta: { title: 'Game' }
  },
  {
    path: '/leaderboard',
    name: 'leaderboard',
    component: LeaderboardView,
    meta: { title: 'Leaderboard' }
  },
  {
    path: '/rules',
    name: 'rules',
    component: RulesView,
    meta: { title: 'Rules' }
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: NotFoundView,
    meta: { title: '404 Not Found' }
  }
];

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes
});

// Add navigation debugging
router.beforeEach((to, from, next) => {
  console.log(`Navigating from ${from.path} to ${to.path}`);
  document.title = to.meta.title ? `Monadungeon - ${to.meta.title}` : 'Monadungeon';
  next();
});

// Add global navigation guard to log component rendering
router.afterEach((to) => {
  console.log(`Route changed to ${to.path}, component found:`, !!to.matched[0]);
});

export default router;
