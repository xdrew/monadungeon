import { createRouter, createWebHistory } from 'vue-router';
import HomeView from '@/views/HomeView.vue';
import GameView from '@/views/GameView.vue';
import NotFoundView from '@/views/NotFoundView.vue';
import LeaderboardView from '@/views/LeaderboardView.vue';
import RulesView from '@/views/RulesView.vue';
import TableJoinView from '@/views/TableJoinView.vue';
import { t, setTitleResolver } from '@/i18n';

const routes = [
  {
    path: '/',
    name: 'home',
    component: HomeView,
    meta: { titleKey: 'routes.home' }
  },
  {
    path: '/game/:id',
    name: 'game',
    component: GameView,
    props: true,
    meta: { titleKey: 'routes.game' }
  },
  {
    // an invite link to an online table
    path: '/table/:id',
    name: 'table',
    component: TableJoinView,
    props: true,
    meta: { titleKey: 'routes.table' }
  },
  {
    path: '/leaderboard',
    name: 'leaderboard',
    component: LeaderboardView,
    meta: { titleKey: 'routes.leaderboard' }
  },
  {
    path: '/rules',
    name: 'rules',
    component: RulesView,
    meta: { titleKey: 'routes.rules' }
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: NotFoundView,
    meta: { titleKey: 'routes.notFound' }
  }
];

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes
});

// Add navigation debugging
router.beforeEach((to, from, next) => {
  console.log(`Navigating from ${from.path} to ${to.path}`);
  // The title follows the locale too: setLocale() re-runs the resolver
  setTitleResolver(() => (to.meta.titleKey ? `${t('common.appName')} - ${t(to.meta.titleKey)}` : t('common.appName')));
  next();
});

// Add global navigation guard to log component rendering
router.afterEach((to) => {
  console.log(`Route changed to ${to.path}, component found:`, !!to.matched[0]);
});

export default router;
