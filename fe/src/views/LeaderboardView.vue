<template>
  <div class="leaderboard-container">
    <!-- language and music, as on every other page -->
    <div class="page-tools">
      <LanguageSwitch />
      <MusicToggle />
    </div>
    <div class="header-section">
      <button
        type="button"
        class="leaderboard-back btn btn--ghost"
        @click="goBack"
      >
        <GameIcon name="home" :size="18" /> {{ $t('leaderboard.back') }}
      </button>
      <h1 class="leaderboard-title">
        <GameIcon name="trophy" :size="40" /> {{ $t('leaderboard.title') }}
      </h1>
    </div>

    <!-- Error state -->
    <div v-if="error" class="error">{{ error }}</div>

    <!-- Initial loading state -->
    <div v-else-if="loading && !entries.length" class="loading">{{ $t('leaderboard.loading') }}</div>

    <!-- Leaderboard table -->
    <div v-else class="leaderboard-table-container">
      <!-- Main leaderboard table -->
      <table class="leaderboard-table">
        <thead>
          <tr>
            <th class="position">#</th>
            <th class="username">{{ $t('leaderboard.player') }}</th>
            <th
              class="victories sortable"
              :aria-sort="sortBy === 'victories' ? (sortOrder === 'DESC' ? 'descending' : 'ascending') : 'none'"
              tabindex="0"
              @click="toggleSort('victories')"
              @keydown.enter="toggleSort('victories')"
            >
              {{ $t('leaderboard.wins') }}
              <span class="sort-indicator" v-if="sortBy === 'victories'">
                <span :class="sortOrder === 'DESC' ? 'sort-down' : 'sort-up'" aria-hidden="true" />
              </span>
            </th>
            <th
              class="games sortable"
              :aria-sort="sortBy === 'totalGames' ? (sortOrder === 'DESC' ? 'descending' : 'ascending') : 'none'"
              tabindex="0"
              @click="toggleSort('totalGames')"
              @keydown.enter="toggleSort('totalGames')"
            >
              {{ $t('leaderboard.games') }}
              <span class="sort-indicator" v-if="sortBy === 'totalGames'">
                <span :class="sortOrder === 'DESC' ? 'sort-down' : 'sort-up'" aria-hidden="true" />
              </span>
            </th>
            <th class="winrate">{{ $t('leaderboard.winRate') }}</th>
          </tr>
        </thead>
        <tbody class="table-body-container">
          <!-- Loading spinner in the middle of table body -->
          <tr v-if="loading" class="loading-row">
            <td colspan="5">
              <div class="table-spinner">
                <div class="spinner-circle"></div>
              </div>
            </td>
          </tr>
          
          <!-- Nobody has won yet -->
          <tr
            v-if="!loading && !entries.length"
            class="empty-row"
          >
            <td colspan="5">
              {{ $t('leaderboard.empty') }}
            </td>
          </tr>

          <!-- Table data rows -->
          <tr 
            v-for="entry in entries" 
            :key="entry.position"
            :class="{ 'faded': loading, [`rank-${entry.position}`]: entry.position <= 3 }"
          >
            <td class="position">{{ entry.position }}</td>
            <td class="username">
              {{ entry.username }}
            </td>
            <td class="victories">{{ entry.victories }}</td>
            <td class="games">{{ entry.totalGames }}</td>
            <td class="winrate">{{ entry.winRate }}%</td>
          </tr>
        </tbody>
      </table>

      <!-- Pagination controls at bottom -->
      <div class="table-footer" v-if="totalPages > 1">
        <div class="page-info">
          {{ $t('leaderboard.showing', { from: (currentPage - 1) * limit + 1, to: Math.min(currentPage * limit, totalCount), total: totalCount }) }}
        </div>
        <div class="pagination">
          <button 
            @click="goToPage(currentPage - 1)" 
            :disabled="currentPage === 1"
            class="pagination-btn"
          >
            ‹
          </button>
          
          <button 
            v-for="page in visiblePages" 
            :key="page"
            @click="goToPage(page)"
            :class="['page-btn', { active: page === currentPage }]"
            :disabled="page === currentPage"
          >
            {{ page }}
          </button>
          
          <button 
            @click="goToPage(currentPage + 1)" 
            :disabled="currentPage === totalPages"
            class="pagination-btn"
          >
            ›
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { gameApi } from '../services/api';
import { t } from '@/i18n';
import GameIcon from '@/components/ui/GameIcon.vue';
import LanguageSwitch from '@/components/ui/LanguageSwitch.vue';
import MusicToggle from '@/components/game/MusicToggle.vue';

export default {
  name: 'LeaderboardView',
  components: { GameIcon },
  props: {
    autoRefresh: {
      type: Boolean,
      default: false
    }
  },
  setup(props) {
    const router = useRouter();
    const loading = ref(false);
    const error = ref(null);
    const entries = ref([]);
    const currentPage = ref(1);
    const totalPages = ref(1);
    const totalCount = ref(0);
    const sortBy = ref('victories');
    const sortOrder = ref('DESC');
    const limit = ref(20);

    // Computed property for visible page numbers
    const visiblePages = computed(() => {
      const pages = [];
      const maxVisible = 5;
      const halfVisible = Math.floor(maxVisible / 2);
      
      let start = Math.max(1, currentPage.value - halfVisible);
      let end = Math.min(totalPages.value, start + maxVisible - 1);
      
      if (end - start < maxVisible - 1) {
        start = Math.max(1, end - maxVisible + 1);
      }
      
      for (let i = start; i <= end; i++) {
        pages.push(i);
      }
      
      return pages;
    });

    // Fetch leaderboard data
    const fetchLeaderboard = async () => {
      loading.value = true;
      error.value = null;
      
      try {
        const params = {
          page: currentPage.value,
          limit: limit.value,
          sortBy: sortBy.value,
          sortOrder: sortOrder.value
        };
        
        const response = await gameApi.getLeaderboard(params);
        
        entries.value = response.entries || [];
        totalPages.value = response.pagination?.totalPages || 1;
        totalCount.value = response.pagination?.totalCount || 0;
        
      } catch (err) {
        console.error('Failed to fetch leaderboard:', err);
        error.value = t('leaderboard.loadFailed');
      } finally {
        loading.value = false;
      }
    };

    // Navigate back
    const goBack = () => {
      router.push('/');
    };

    // Toggle sort
    const toggleSort = (column) => {
      if (sortBy.value === column) {
        sortOrder.value = sortOrder.value === 'DESC' ? 'ASC' : 'DESC';
      } else {
        sortBy.value = column;
        sortOrder.value = 'DESC';
      }
      currentPage.value = 1; // Reset to first page when sorting changes
      fetchLeaderboard();
    };

    // Go to specific page
    const goToPage = (page) => {
      if (page >= 1 && page <= totalPages.value && page !== currentPage.value) {
        currentPage.value = page;
        fetchLeaderboard();
      }
    };

    // Auto-refresh functionality
    let refreshInterval = null;
    
    watch(() => props.autoRefresh, (newVal) => {
      if (newVal) {
        refreshInterval = setInterval(fetchLeaderboard, 30000); // Refresh every 30 seconds
      } else if (refreshInterval) {
        clearInterval(refreshInterval);
        refreshInterval = null;
      }
    });

    // Initial load
    onMounted(() => {
      fetchLeaderboard();
      
      if (props.autoRefresh) {
        refreshInterval = setInterval(fetchLeaderboard, 30000);
      }
    });

    // Cleanup
    onUnmounted(() => {
      if (refreshInterval) {
        clearInterval(refreshInterval);
      }
    });

    return {
      loading,
      error,
      entries,
      currentPage,
      totalPages,
      totalCount,
      sortBy,
      sortOrder,
      limit,
      visiblePages,
      fetchLeaderboard,
      toggleSort,
      goToPage,
      goBack
    };
  }
};
</script>

<style scoped>
.page-tools {
  position: fixed;
  top: 20px;
  right: 20px;
  z-index: 3;
  display: flex;
  gap: 10px;
}

/* The hall of fame: the dark, a title in the display face, a table of hairline rows */
.leaderboard-container {
  min-height: 100vh;
  box-sizing: border-box;
  padding: 28px 24px 56px;
  font-family: var(--font-body);
  color: var(--text-primary);
  background-color: var(--bg-field);
  background-image: radial-gradient(ellipse at 50% -10%, rgba(var(--ember-rgb), 0.16) 0%, rgba(0, 0, 0, 0) 45%);
}

.header-section {
  max-width: 860px;
  margin: 0 auto 28px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
}

.leaderboard-back {
  align-self: flex-start;
  gap: 8px;
  min-height: 44px;
}

.leaderboard-title {
  display: flex;
  align-items: center;
  gap: 14px;
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: clamp(40px, 6vw, 64px);
  line-height: 1;
  color: var(--text-heading);
  text-shadow: 0 0 24px rgba(var(--ember-rgb), 0.4), 0 4px 12px #000;
}

.leaderboard-title :deep(svg) {
  color: var(--color-primary);
}

.error,
.loading {
  max-width: 860px;
  margin: 40px auto;
  text-align: center;
  font-style: italic;
  color: var(--text-secondary);
}

.error {
  color: #f0b5a8;
}

.leaderboard-table-container {
  max-width: 860px;
  margin: 0 auto;
  overflow-x: auto;
}

.leaderboard-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 16px;
}

.leaderboard-table th {
  padding: 10px 12px;
  border-bottom: 1px solid rgba(var(--ember-rgb), 0.45);
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  text-align: left;
  color: var(--text-accent);
  white-space: nowrap;
}

.leaderboard-table td {
  padding: 12px;
  border-bottom: 1px solid rgba(234, 220, 192, 0.1);
}

.leaderboard-table th.victories,
.leaderboard-table th.games,
.leaderboard-table th.winrate,
.leaderboard-table td.victories,
.leaderboard-table td.games,
.leaderboard-table td.winrate {
  text-align: right;
}

.sortable {
  cursor: pointer;
  user-select: none;
}

.sortable:hover,
.sortable:focus-visible {
  outline: none;
  color: var(--text-heading);
}

.sort-indicator {
  display: inline-block;
  margin-left: 6px;
  vertical-align: middle;
}

.sort-down,
.sort-up {
  display: inline-block;
  width: 0;
  height: 0;
  border-left: 5px solid transparent;
  border-right: 5px solid transparent;
}

.sort-down {
  border-top: 6px solid currentColor;
}

.sort-up {
  border-bottom: 6px solid currentColor;
}

td.position {
  width: 56px;
  font-family: var(--font-display);
  font-size: 24px;
  line-height: 1;
  color: var(--text-muted);
}

td.username {
  font-weight: 600;
  color: var(--text-heading);
}

.rank-1 td.position { color: var(--color-primary); font-size: 30px; text-shadow: 0 0 12px rgba(var(--ember-rgb), 0.6); }
.rank-2 td.position { color: var(--text-heading); font-size: 28px; }
.rank-3 td.position { color: #d9c08a; font-size: 26px; }

.leaderboard-table tbody tr:hover td {
  background: rgba(var(--ember-rgb), 0.06);
}

.faded {
  opacity: 0.4;
}

.empty-row td {
  padding: 32px 12px;
  border-bottom: 0;
  text-align: center;
  font-style: italic;
  color: var(--text-secondary);
}

.loading-row td {
  border-bottom: 0;
}

.table-spinner {
  display: flex;
  justify-content: center;
  padding: 16px 0;
}

.spinner-circle {
  width: 26px;
  height: 26px;
  border: 3px solid rgba(var(--ember-rgb), 0.2);
  border-top-color: var(--color-primary);
  border-radius: 50%;
  animation: spin 0.9s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.table-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 18px;
}

.page-info {
  font-size: 14px;
  font-style: italic;
  color: var(--text-secondary);
}

.pagination {
  display: flex;
  gap: 8px;
}

.pagination-btn,
.page-btn {
  width: 40px;
  height: 40px;
  padding: 0;
  border: 0;
  border-radius: 50%;
  font-family: var(--font-display);
  font-size: 18px;
  color: var(--text-primary);
  background: radial-gradient(circle at 50% 35%, #2a201a 0%, #0d0907 80%);
  box-shadow: 0 0 0 2px var(--iron-dark), 0 0 0 4px var(--iron-edge);
  cursor: pointer;
}

.pagination-btn:hover:not(:disabled),
.page-btn:hover:not(:disabled) {
  box-shadow: 0 0 0 2px var(--color-secondary), 0 0 0 4px var(--iron-edge);
}

.page-btn.active {
  color: var(--text-heading);
  box-shadow: 0 0 0 2px var(--color-primary), 0 0 0 4px var(--iron-edge), 0 0 12px rgba(var(--ember-rgb), 0.5);
  cursor: default;
}

.pagination-btn:disabled {
  opacity: 0.4;
  cursor: default;
}

@media (max-width: 600px) {
  .leaderboard-table th,
  .leaderboard-table td {
    padding: 10px 6px;
  }

  .leaderboard-table th.games,
  .leaderboard-table td.games {
    display: none;
  }
}
</style>
