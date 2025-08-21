// Game State Management Utilities
import { ref, reactive, computed } from 'vue';

export function createGameState() {
  // UI State
  const uiState = reactive({
    loading: true,
    loadingStatus: '',
    error: null,
    playerSwitched: false,
    notificationTimeout: null
  });

  // Game Core State
  const gameState = reactive({
    data: null,
    started: false,
    playerIsReady: false
  });

  // Player State
  const playerState = reactive({
    currentPlayerId: localStorage.getItem('currentPlayerId') || null,
    secondPlayerId: localStorage.getItem('secondPlayerId') || null
  });

  // Tile Interaction State
  const tileState = reactive({
    highlightedTile: null,
    selectedTile: null,
    pickedTileId: null,
    pickedTile: null,
    ghostTilePosition: null,
    ghostTileOrientation: null,
    isPlacingTile: false,
    isMoveMode: false,
    isPlaceMode: false
  });

  // View State
  const viewState = reactive({
    zoomLevel: 1,
    tileSize: 100,
    isDragging: false,
    startDragX: 0,
    startDragY: 0,
    startScrollLeft: 0,
    startScrollTop: 0,
    isCtrlPressed: false
  });

  // Inventory State
  const inventoryState = reactive({
    showInventoryFullDialog: false,
    droppedItem: null,
    itemCategory: '',
    maxItemsInCategory: 0,
    selectedItemToReplace: null,
    inventoryForCategory: []
  });

  // Replay State
  const replayState = reactive({
    isReplaying: false,
    gameTurns: [],
    currentReplayTurn: 0,
    originalGameData: null,
    replaySpeed: 1,
    gamePollingInterval: null
  });

  // Battle State
  const battleState = reactive({
    showBattleReportModal: false,
    battleInfo: null
  });

  return {
    uiState,
    gameState,
    playerState,
    tileState,
    viewState,
    inventoryState,
    replayState,
    battleState
  };
}

// Computed properties factory
export function createGameComputedProperties(state) {
  const canStartGame = computed(() => {
    if (!state.gameState.data || !state.gameState.data.players) return false;
    return state.gameState.data.players.length >= 2 && !state.gameState.started;
  });

  const isPlayerInGame = computed(() => {
    return state.gameState.data?.players?.some(player => 
      player.id === state.playerState.currentPlayerId
    );
  });

  const isSecondPlayerInGame = computed(() => {
    return state.gameState.data?.players?.some(player => 
      player.id === state.playerState.secondPlayerId
    );
  });

  const isPlayerTurn = computed(() => {
    return state.gameState.data?.state?.currentPlayerId === state.playerState.currentPlayerId;
  });

  const getCurrentPlayerData = computed(() => {
    if (!state.gameState.data?.players || !state.playerState.currentPlayerId) return null;
    return state.gameState.data.players.find(player => 
      player.id === state.playerState.currentPlayerId
    );
  });

  const getSecondPlayerData = computed(() => {
    if (!state.gameState.data?.players || !state.playerState.secondPlayerId) return null;
    return state.gameState.data.players.find(player => 
      player.id === state.playerState.secondPlayerId
    );
  });

  return {
    canStartGame,
    isPlayerInGame,
    isSecondPlayerInGame,
    isPlayerTurn,
    getCurrentPlayerData,
    getSecondPlayerData
  };
} 