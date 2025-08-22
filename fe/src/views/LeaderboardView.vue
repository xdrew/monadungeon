<template>
  <div class="leaderboard-container">
    <div class="header-section">
      <button @click="goBack" class="back-button">← Back to Home</button>
      <h2 class="leaderboard-title">Leaderboard</h2>
    </div>

    <!-- Error state -->
    <div v-if="error" class="error">{{ error }}</div>

    <!-- Initial loading state -->
    <div v-else-if="loading && !entries.length" class="loading">Loading leaderboard...</div>

    <!-- Leaderboard table -->
    <div v-else class="leaderboard-table-container">
      <!-- Show current player at top if not on current page and above -->
      <table v-if="currentPlayerTop" class="leaderboard-table current-player-preview">
        <tbody>
          <tr class="current-player-row highlighted">
            <td class="position">{{ currentPlayerTop.position }}</td>
            <td class="username">{{ currentPlayerTop.username }} (You)</td>
            <td class="victories">{{ currentPlayerTop.victories }}</td>
            <td class="games">{{ currentPlayerTop.totalGames }}</td>
            <td class="winrate">{{ currentPlayerTop.winRate }}%</td>
          </tr>
        </tbody>
      </table>

      <!-- Main leaderboard table -->
      <table class="leaderboard-table">
        <thead>
          <tr>
            <th class="position">#</th>
            <th class="username">Player</th>
            <th class="victories sortable" @click="toggleSort('victories')">
              Wins
              <span class="sort-indicator" v-if="sortBy === 'victories'">
                {{ sortOrder === 'DESC' ? '▼' : '▲' }}
              </span>
            </th>
            <th class="games sortable" @click="toggleSort('totalGames')">
              Games
              <span class="sort-indicator" v-if="sortBy === 'totalGames'">
                {{ sortOrder === 'DESC' ? '▼' : '▲' }}
              </span>
            </th>
            <th class="winrate">Win Rate</th>
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
          
          <!-- Table data rows -->
          <tr 
            v-for="entry in entries" 
            :key="entry.position"
            :class="{ 
              'current-player-row': entry.isCurrentPlayer,
              'highlighted': entry.isCurrentPlayer,
              'faded': loading
            }"
          >
            <td class="position">{{ entry.position }}</td>
            <td class="username">
              {{ entry.username }}
              <span v-if="entry.isCurrentPlayer" class="you-badge">(You)</span>
            </td>
            <td class="victories">{{ entry.victories }}</td>
            <td class="games">{{ entry.totalGames }}</td>
            <td class="winrate">{{ entry.winRate }}%</td>
          </tr>
        </tbody>
      </table>

      <!-- Show current player at bottom if not on current page and below -->
      <table v-if="currentPlayerBottom" class="leaderboard-table current-player-preview">
        <tbody>
          <tr class="current-player-row highlighted">
            <td class="position">{{ currentPlayerBottom.position }}</td>
            <td class="username">{{ currentPlayerBottom.username }} (You)</td>
            <td class="victories">{{ currentPlayerBottom.victories }}</td>
            <td class="games">{{ currentPlayerBottom.totalGames }}</td>
            <td class="winrate">{{ currentPlayerBottom.winRate }}%</td>
          </tr>
        </tbody>
      </table>

      <!-- Pagination controls at bottom -->
      <div class="table-footer" v-if="totalPages > 1">
        <div class="page-info">
          Showing {{ (currentPage - 1) * limit + 1 }}-{{ Math.min(currentPage * limit, totalCount) }} of {{ totalCount }}
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

export default {
  name: 'LeaderboardView',
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
    const currentPlayerInfo = ref(null);

    // Computed property for current player display at top
    const currentPlayerTop = computed(() => {
      if (!currentPlayerInfo.value) return null;
      const { position, isOnCurrentPage, entry } = currentPlayerInfo.value;
      
      // Show at top if player is above current page
      const firstPositionOnPage = (currentPage.value - 1) * limit.value + 1;
      if (!isOnCurrentPage && position < firstPositionOnPage) {
        return entry;
      }
      return null;
    });

    // Computed property for current player display at bottom
    const currentPlayerBottom = computed(() => {
      if (!currentPlayerInfo.value) return null;
      const { position, isOnCurrentPage, entry } = currentPlayerInfo.value;
      
      // Show at bottom if player is below current page
      const lastPositionOnPage = currentPage.value * limit.value;
      if (!isOnCurrentPage && position > lastPositionOnPage) {
        return entry;
      }
      return null;
    });

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
        
        // Only include wallet if user is logged in with a real account
        const walletAddress = localStorage.getItem('walletAddress');
        const username = localStorage.getItem('monadUsername');
        const privyUser = localStorage.getItem('privyUser');
        
        // Only set currentPlayerWallet if user is actually logged in with authentication
        // Don't set it for anonymous/AI players
        if (walletAddress && username && privyUser) {
          params.currentPlayerWallet = walletAddress;
          params.currentPlayerUsername = username;
        }
        
        const response = await gameApi.getLeaderboard(params);
        
        entries.value = response.entries || [];
        totalPages.value = response.pagination?.totalPages || 1;
        totalCount.value = response.pagination?.totalCount || 0;
        currentPlayerInfo.value = response.currentPlayer || null;
        
      } catch (err) {
        console.error('Failed to fetch leaderboard:', err);
        error.value = 'Failed to load leaderboard. Please try again.';
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
      currentPlayerTop,
      currentPlayerBottom,
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
.leaderboard-container {
  max-width: 900px;
  margin: 0 auto;
  padding: 20px;
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.header-section {
  text-align: center;
  margin-bottom: 30px;
  position: relative;
  padding-top: 40px;
}

.back-button {
  position: absolute;
  left: 0;
  top: 0;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  border: none;
  padding: 8px 16px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  transition: all 0.3s;
  box-shadow: 0 2px 8px rgba(118, 75, 162, 0.3);
}

.back-button:hover {
  background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
  transform: none; /* Prevent position change on hover */
  box-shadow: 0 4px 12px rgba(118, 75, 162, 0.4);
}

.leaderboard-title {
  color: #ffd700;
  margin: 0;
  font-size: 2.5em;
  text-shadow: 2px 2px 8px rgba(0,0,0,0.5);
  font-weight: bold;
  letter-spacing: 1px;
}

.loading, .error {
  text-align: center;
  padding: 40px;
  font-size: 1.1em;
  color: #ffd700;
}

.error {
  color: #ff6b6b;
}

.leaderboard-table-container {
  background: #2a2a3e;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 4px 20px rgba(0,0,0,0.5);
  position: relative;
  border: 1px solid rgba(118, 75, 162, 0.3);
}

.table-body-container {
  position: relative;
  min-height: 200px;
}

.loading-row {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 10;
  pointer-events: none;
}

.loading-row td {
  padding: 0;
  border: none;
  background: transparent;
}

.table-spinner {
  display: flex;
  justify-content: center;
  align-items: center;
}

.spinner-circle {
  width: 50px;
  height: 50px;
  border: 4px solid #e9ecef;
  border-top: 4px solid #007bff;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.faded {
  opacity: 0.3;
  transition: opacity 0.3s;
}

.leaderboard-table {
  width: 100%;
  border-collapse: collapse;
}

.leaderboard-table thead {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
}

.leaderboard-table th {
  padding: 15px;
  text-align: left;
  font-weight: 600;
  text-transform: uppercase;
  font-size: 0.9em;
  letter-spacing: 0.5px;
  position: relative;
}

.leaderboard-table th.sortable {
  cursor: pointer;
  user-select: none;
  transition: background 0.2s;
}

.leaderboard-table th.sortable:hover {
  background: rgba(255,255,255,0.1);
}

.sort-indicator {
  display: inline-block;
  margin-left: 5px;
  font-size: 0.8em;
}

.leaderboard-table tbody tr {
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  transition: background 0.2s;
  background: rgba(0, 0, 0, 0.2);
}

.leaderboard-table tbody tr:hover {
  background: rgba(118, 75, 162, 0.2);
}

.leaderboard-table tbody tr:last-child {
  border-bottom: none;
}

.leaderboard-table td {
  padding: 12px 15px;
  color: #e0e0e0;
}

.position {
  width: 60px;
  font-weight: 600;
  color: #a0a0a0;
}

.username {
  font-weight: 500;
  color: #ffd700;
}

.victories, .games {
  width: 100px;
  text-align: center;
  color: #e0e0e0;
}

.winrate {
  width: 100px;
  text-align: center;
  font-weight: 500;
  color: #e0e0e0;
}

.current-player-row {
  background: rgba(255, 215, 0, 0.15) !important;
}

.current-player-row.highlighted {
  background: linear-gradient(90deg, rgba(255, 215, 0, 0.2) 0%, rgba(255, 215, 0, 0.1) 100%) !important;
  font-weight: 600;
}

.you-badge {
  background: #ffc107;
  color: #000;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 0.8em;
  margin-left: 8px;
  font-weight: 600;
}

.current-player-preview {
  margin: 10px 0;
  border: 2px solid #ffc107;
}

.table-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 15px 20px;
  background: rgba(0, 0, 0, 0.3);
  border-top: 1px solid rgba(118, 75, 162, 0.3);
}

.page-info {
  color: #a0a0a0;
  font-size: 0.9em;
}

.pagination {
  display: flex;
  gap: 5px;
}

.pagination-btn, .page-btn {
  padding: 6px 12px;
  background: rgba(118, 75, 162, 0.2);
  color: #ffd700;
  border: 1px solid rgba(118, 75, 162, 0.4);
  border-radius: 4px;
  cursor: pointer;
  transition: all 0.2s;
  font-weight: 500;
  font-size: 0.9em;
  min-width: 32px;
  text-align: center;
}

.pagination-btn:hover:not(:disabled), .page-btn:hover:not(:disabled) {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  border-color: #764ba2;
  box-shadow: 0 2px 8px rgba(118, 75, 162, 0.3);
}

.pagination-btn:disabled, .page-btn:disabled {
  opacity: 0.3;
  cursor: not-allowed;
  color: #666;
}

.page-btn.active {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  border-color: #764ba2;
}

/* Responsive design */
@media (max-width: 768px) {
  .leaderboard-container {
    padding: 10px;
  }
  
  .leaderboard-table th, .leaderboard-table td {
    padding: 8px;
    font-size: 0.9em;
  }
  
  .victories, .games, .winrate {
    width: 70px;
  }
  
  .table-footer {
    flex-direction: column;
    gap: 10px;
    text-align: center;
  }
  
  .back-button {
    position: static;
    margin-bottom: 15px;
  }
  
  .header-section {
    padding-top: 0;
  }
}
</style>