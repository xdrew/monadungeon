<template>
  <div class="game-container">
    <!-- Use the extracted Loading components -->
    <LoadingScreen v-if="!gameData && loading" />
    <LoadingOverlay
      v-if="gameData && loading"
      :status="loadingStatus"
    />
    <ErrorDisplay
      v-if="error"
      :error-message="error"
    />

    <div
      v-else-if="gameData"
      class="game-interface"
      :class="{ 'game-finished': gameData?.state?.status === 'finished' }"
    >
      <!-- Game finished banner -->
      <div
        v-if="gameData?.state?.status === 'finished'"
        class="game-finished-banner"
      >
        <span class="trophy-icon">🏆</span>
        <span class="finished-text">Game Over - {{ isCurrentUserWinner() ? 'You Win!' : winnerId ? `${getPlayerEmoji(winnerId)} Wins!` : 'View Results' }}</span>
        <button
          class="view-results-button"
          @click="showLeaderboardModal = true"
        >
          View Leaderboard
        </button>
      </div>

      <!-- Player switch notification component -->
      <!--
      <PlayerSwitchNotification 
        v-if="playerSwitched" 
        :playerId="currentPlayerId"
        :formattedPlayerId="formatPlayerId(currentPlayerId)"
        @dismiss="dismissNotification" 
      />
      -->

      <!-- Inventory full dialog component -->
      <InventoryFullDialog
        v-if="showInventoryFullDialog"
        :dropped-item="droppedItem"
        :item-category="itemCategory"
        :inventory-for-category="inventoryForCategory"
        @select-item="selectItemToReplace"
        @replace-item="replaceItem"
        @skip-item="skipItem"
      />

      <!-- Missing key dialog component -->
      <MissingKeyDialog
        v-if="showMissingKeyDialog"
        :chest-type="missingKeyChestType"
        @close="showMissingKeyDialog = false"
      />

      <!-- Item pickup dialog component -->
      <ItemPickupDialog
        v-if="showItemPickupDialog"
        :show="showItemPickupDialog"
        :item="tileItem"
        @pickup="handleAutoItemPickup"
        @skip="skipItemAndEndTurn"
      />


      <!-- Game layout -->
      <div class="game-layout">
        <!-- Game field on the left -->
        <div class="game-main-area">
          <div class="game-board">
            <div class="game-visualization">
              <ZoomControls 
                @center-map="centerViewOnMiddle"
                @center-player="centerViewOnCurrentPlayer"
                @center-available="centerViewOnAvailablePlaces"
              />

              <!-- Render field and tiles if available -->
              <div 
                v-if="gameData && gameData.field" 
                ref="fieldElement" 
                class="game-field"
                :class="{ 
                  'ctrl-pressed': isCtrlPressed, 
                  'dragging': isDragging,
                  'is-replaying': isReplaying,
                  'teleport-mode': isTeleportMode 
                }"
                @mousedown="onMouseDown"
                @mouseleave="onMouseLeave"
              >
                <div
                  class="tiles-container"
                  :style="fieldStyles"
                >
                  <!-- Render available move/place markers before tiles -->
                  <AvailableMoveMarker
                    v-for="position in gameData.state.availablePlaces.moveTo"
                    v-if="gameData.state && gameData.state.availablePlaces && !isPlacingTile && !isTeleportMode && !isProcessingAI && gameData.state.status !== 'finished'"
                    :key="`move-${position}`"
                    :position="position"
                    :x="parseInt(position.split(',')[0])"
                    :y="parseInt(position.split(',')[1])"
                    :can-place-tile="(gameData.state.availablePlaces.placeTile.includes(position) || 
                      (gameData.state.availablePlaces.placeTile.length === 0 && 
                        gameData.state.deck?.remainingTiles > 0 && 
                        !gameData.state.deck?.isEmpty && 
                        !isFieldPlaceAlreadyTaken(position, gameData))) && 
                      !gameData.state.deck?.isEmpty"
                    :is-player-turn="isPlayerTurn"
                    :can-click="isPlayerTurn && !isRequestInProgress"
                    :is-request-in-progress="isRequestInProgress"
                    :min-x="gameData.field.size.minX || 0"
                    :min-y="gameData.field.size.minY || 0"
                    :tile-size="tileSize"
                    @click="(position) => handlePlaceClick(position)"
                  />

                  <!-- Render healing fountain markers in teleport mode -->
                  <div
                    v-for="position in gameData.field.healingFountainPositions"
                    v-if="isTeleportMode && gameData.field && gameData.field.healingFountainPositions"
                    :key="`teleport-${position}`"
                    class="healing-fountain-marker"
                    :style="{
                      position: 'absolute',
                      left: `${(parseInt(position.split(',')[0]) - (gameData.field.size.minX || 0)) * tileSize}px`,
                      top: `${(parseInt(position.split(',')[1]) - (gameData.field.size.minY || 0)) * tileSize}px`,
                      width: `${tileSize}px`,
                      height: `${tileSize}px`,
                      pointerEvents: !isCurrentPlayerPosition(position) ? 'auto' : 'none',
                      opacity: isCurrentPlayerPosition(position) ? 0.3 : 1,
                      cursor: !isCurrentPlayerPosition(position) ? 'pointer' : 'default'
                    }"
                    @click="!isCurrentPlayerPosition(position) && handleTeleportClick(position)"
                  >
                    <div class="healing-fountain-indicator">
                      <span class="fountain-emoji">🌿</span>
                      <span
                        v-if="isCurrentPlayerPosition(position)"
                        class="current-position-label"
                      >Current</span>
                    </div>
                  </div>

                  <!-- Render ghost tile if we have one and deck is not empty -->
                  <GhostTile
                    v-if="ghostTilePosition && pickedTile && !(gameData?.state?.deck?.isEmpty) && !isProcessingAI && gameData?.state?.status !== 'finished'"
                    :key="`ghost-${ghostTilePosition}-${ghostTileOrientation}-${pickedTile?.orientation}`"
                    :position="ghostTilePosition"
                    :orientation="ghostTileOrientation"
                    :orientation-symbol="ghostTileOrientationSymbol"
                    :orientation-class="ghostTileOrientationClass"
                    :is-room="pickedTile.room"
                    :is-placing-tile="isPlacingTile"
                    :min-x="gameData.field.size.minX || 0"
                    :min-y="gameData.field.size.minY || 0"
                    :tile-size="tileSize"
                    :openings="{
                      top: hasOpening(ghostTileOrientation, 'T'),
                      right: hasOpening(ghostTileOrientation, 'R'),
                      bottom: hasOpening(ghostTileOrientation, 'B'),
                      left: hasOpening(ghostTileOrientation, 'L')
                    }"
                    @rotate="rotateGhostTileLocal"
                    @place="handlePlaceClick"
                  />

                  <!-- Replace the processed tiles with GameTile components -->
                  <GameTile
                    v-for="tile in processedTiles"
                    v-if="processedTiles && processedTiles.length"
                    :key="`${tile.x}-${tile.y}`"
                    :position="tile.position"
                    :x="tile.x"
                    :y="tile.y"
                    :orientation-char="tile.orientationChar"
                    :orientation-class="tile.orientation"
                    :orientation-symbol="getTileOrientationSymbol(tile.orientationChar, tile.isRoom)"
                    :is-room="tile.isRoom"
                    :is-starting-tile="tile.x === 0 && tile.y === 0"
                    :has-player="tile.hasPlayer"
                    :player-id="tile.playerId"
                    :all-player-ids="tile.allPlayerIds"
                    :player-emoji="getPlayerEmoji(tile.playerId)"
                    :has-item="tile.hasItem"
                    :item="tile.item"
                    :item-emoji="getItemEmoji(tile.item)"
                    :item-tooltip="getItemTooltip(tile.item)"
                    :is-replaying="isReplaying"
                    :current-replay-turn="currentReplayTurn"
                    :turn-number="tile.turnNumber"
                    :is-current-action="tile.isCurrentAction"
                    :is-placing-animation="tile.isPlacingAnimation"
                    :is-current-player-position="isPlayerPosition(tile.position)"
                    :min-x="gameData.field.size.minX || 0"
                    :min-y="gameData.field.size.minY || 0"
                    :tile-size="tileSize"
                    :animation-start-x="tile.animationStartX"
                    :animation-start-y="tile.animationStartY"
                    :is-highlighted="highlightedTile === tile"
                    :openings="{
                      top: hasOpening(tile.orientationChar, 'T'),
                      right: hasOpening(tile.orientationChar, 'R'),
                      bottom: hasOpening(tile.orientationChar, 'B'),
                      left: hasOpening(tile.orientationChar, 'L')
                    }"
                    :has-healing-fountain="tile.hasHealingFountain"
                    :has-teleportation-gate="tile.hasTeleportationGate"
                    @highlight="highlightTile"
                    @unhighlight="unhighlightTile"
                    @center-view="centerViewOnTile"
                    @item-click="handleItemClick"
                  />
                </div>
              </div>
              <p v-else>
                No field data available for game {{ id }}
              </p>
            </div>
          </div>
        </div>

        <!-- Game sidebar on the right -->
        <div class="game-sidebar">
          <div class="sidebar-header">
            <div class="monad-logo-small">
              <img src="/assets/monad-logo-black.webp" alt="Monad" />
            </div>
            <h3 v-if="gameData?.state?.turn">
              Turn: {{ gameData.state.turn }}
            </h3>
            <h3 v-else>
              Game Setup
            </h3>
          </div>

          <div class="sidebar-content">
            <!-- Player Setup and Game Controls sections removed for cleaner UI -->

            <!-- Both Players' Inventories -->
            <div
              v-if="(gameStarted || gameData?.state?.status === 'started' || gameData?.state?.status === 'turn_in_progress' || gameData?.state?.status === 'finished') && gameData?.players" 
              class="sidebar-section inventory-section both-players"
              :class="{ 'active-game': gameStarted || gameData?.state?.status === 'started' }"
            >
              <div class="inventory-header">
                <h3>Players' Inventories</h3>
              </div>

              <!-- Show inventory for each player -->
              <div
                v-for="player in gameData.players"
                :key="player.id"
                class="player-inventory-section"
                :class="{ 
                  'current-turn': gameData?.state?.currentPlayerId === player.id,
                  'is-current-user': isCurrentUserEntry({ playerId: player.id, externalId: player.externalId })
                }"
              >
                <div class="player-inventory-header">
                  <span class="player-emoji">{{ getPlayerEmoji(player.id) }}</span>
                  <span class="player-name">
                    {{ isVirtualPlayer(player.id) ? 'AI' : isCurrentUserEntry({ playerId: player.id, externalId: player.externalId }) ? 'You' : 'Player' }}
                  </span>
                  <span class="hp-indicator">
                    ❤️ {{ player.hp !== undefined ? player.hp : 5 }}/5
                  </span>
                  <span 
                    v-if="gameData?.state?.currentPlayerId === player.id"
                    class="turn-badge"
                  >📍</span>
                </div>

              <div class="inventory-container">
                <div class="unified-inventory-grid compact">
                  <!-- Keys -->
                  <div
                    v-for="item in (player.inventory?.keys || [])"
                    :key="item.itemId" 
                    class="inventory-item key-item compact" 
                    :title="getItemTooltip(item)"
                  >
                    <div class="item-icon">
                      {{ getInventoryItemEmoji(item) }}
                    </div>
                  </div>

                  <!-- Weapons -->
                  <div
                    v-for="item in (player.inventory?.weapons || [])"
                    :key="item.itemId" 
                    class="inventory-item weapon-item compact"
                    :title="getItemTooltip(item)"
                  >
                    <div class="item-icon">
                      {{ getInventoryItemEmoji(item) }}
                    </div>
                    <div class="item-damage-small">
                      +{{ getItemDamage(item) }}
                    </div>
                  </div>

                  <!-- Spells -->
                  <div
                    v-for="item in (player.inventory?.spells || [])"
                    :key="item.itemId" 
                    class="inventory-item spell-item compact"
                    :class="{ 'clickable': item.type === 'teleport' && isPlayerTurn && player.id === currentPlayerId }"
                    :title="getItemTooltip(item)"
                    @click="item.type === 'teleport' && isPlayerTurn && player.id === currentPlayerId && handleTeleportSpellSelection(item)"
                  >
                    <div class="item-icon">
                      {{ getInventoryItemEmoji(item) }}
                    </div>
                    <div class="item-damage-small">
                      +{{ getItemDamage(item) }}
                    </div>
                  </div>

                  <!-- Treasures -->
                  <div
                    v-for="item in (player.inventory?.treasures || [])"
                    :key="item.itemId" 
                    class="inventory-item treasure-item compact"
                    :title="getItemTooltip(item)"
                  >
                    <div class="item-icon">
                      {{ getInventoryItemEmoji(item) }}
                    </div>
                    <div
                      v-if="item.treasureValue > 0"
                      class="item-value-small"
                    >
                      {{ item.treasureValue }}
                    </div>
                  </div>

                  <!-- Total treasure value -->
                  <div class="player-treasure-total">
                    💎 {{ calculatePlayerTreasure(player) }}
                  </div>
                </div>
              </div>
              </div>
            </div>

            <!-- Teleport Mode Controls -->
            <div
              v-if="isTeleportMode"
              class="sidebar-section teleport-controls"
            >
              <h3>Teleport Mode</h3>
              <p>Click a healing fountain (🌿) to teleport there</p>
              <button
                class="cancel-teleport-btn btn--block"
                @click="cancelTeleportMode"
              >
                Cancel Teleport
              </button>
            </div>

            <!-- End Turn Button -->
            <div
              v-if="isPlayerTurn && (gameStarted || gameData?.state?.status === 'started' || gameData?.state?.status === 'turn_in_progress') && !isTeleportMode"
              class="sidebar-section end-turn-section"
            >
              <button
                class="end-turn-btn btn--block"
                @click="handleManualEndTurn"
                :disabled="loading || isRequestInProgress"
              >
                End Turn
              </button>
            </div>

            <!-- Action Log -->
            <div
              v-if="(gameStarted || gameData?.state?.status === 'started' || gameData?.state?.status === 'turn_in_progress') && gameTurns.length > 0"
              class="sidebar-section"
            >
              <ActionLog
                :turns="gameTurns"
                :current-player-id="currentPlayerId"
              />
            </div>

            <!-- Replay controls -->
            <ReplayControls
              v-if="isReplaying"
              :current-replay-turn="currentReplayTurn"
              :game-turns="gameTurns"
              :replay-speed="replaySpeed"
              @stop-replay="finishReplay"
              @speed-change="changeReplaySpeed"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- Healing notification component -->
    <HealingNotification
      v-if="showHealingNotification"
      :player-id="healingNotification?.playerId"
      :player-name="healingNotification?.playerName"
      :heal-amount="healingNotification?.healAmount"
      @dismiss="dismissHealingNotification"
    />

    <!-- Battle report modal component -->
    <BattleReportModal
      v-if="showBattleReportModal"
      ref="battleReportModalRef"
      :battle-info="battleInfo"
      :has-inventory-space="hasInventorySpaceForReward"
      @end-turn="closeBattleReportAndEndTurn"
      @pick-item-and-end-turn="handlePickItemAndEndTurn"
      @pick-item-with-replacement="handlePickItemWithReplacement"
      @finalize-battle="handleFinalizeBattle"
      @finalize-battle-and-pick-up="handleFinalizeBattleAndPickUp"
    />

    <!-- Leaderboard Modal -->
    <div
      v-if="showLeaderboardModal"
      class="leaderboard-modal-overlay"
    >
      <div class="leaderboard-modal">
        <h2>🏆 Game Over: Leaderboard</h2>
        <div 
          v-if="getCurrentUserPrivyId() || (humanPlayerId && !isVirtualPlayer(humanPlayerId))"
          class="player-result-message"
          :class="{ 
            'winner-message': isCurrentUserWinner(),
            'loser-message': !isCurrentUserWinner()
          }"
        >
          <span v-if="isCurrentUserWinner()">
            🎉 Congratulations! You Won! 🎉
          </span>
          <span v-else>
            Better luck next time! The winner is {{ getPlayerEmoji(winnerId) }}
          </span>
        </div>
        <table class="leaderboard-table">
          <thead>
            <tr>
              <th>Rank</th>
              <th>Icon</th>
              <th>Treasure</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(entry, idx) in leaderboard"
              :key="entry.playerId"
              :class="{ 
                winner: entry.playerId === winnerId,
                'current-player': isCurrentUserEntry(entry)
              }"
            >
              <td>
                {{ idx + 1 }}
                <span 
                  v-if="idx === 0"
                  class="rank-medal"
                >🥇</span>
                <span 
                  v-else-if="idx === 1"
                  class="rank-medal"
                >🥈</span>
                <span 
                  v-else-if="idx === 2"
                  class="rank-medal"
                >🥉</span>
              </td>
              <td>
                <div class="player-info-row">
                  <span class="player-icon">{{ getPlayerEmoji(entry.playerId) }}</span>
                  <span
                    v-if="entry.playerId === winnerId"
                    class="winner-crown"
                  >👑</span>
                  <span
                    v-if="isCurrentUserEntry(entry)"
                    class="you-badge"
                  >YOU</span>
                </div>
              </td>
              <td>
                💎 {{ entry.treasure }}
              </td>
            </tr>
          </tbody>
        </table>
        <div class="leaderboard-actions">
          <button 
            class="new-game-button"
            @click="navigateToLobby"
          >
            🏠 Return to Lobby
          </button>
          <button 
            class="replay-button"
            @click="reloadPage"
          >
            🔄 View Game Board
          </button>
        </div>
      </div>
    </div>

    <!-- Add a stunned player overlay when player is stunned -->
    <div
      v-if="isCurrentPlayerStunned"
      class="stunned-player-overlay"
    >
      <div class="stunned-message">
        <div class="stunned-player-icon">
          {{ getPlayerEmoji(currentPlayerId) }}
        </div>
        <h3>🚫 You are stunned! 🚫</h3>
        <p>You were defeated in battle and cannot move this turn.</p>
        <p>Your HP will be restored on your next turn.</p>
        <button
          class="skip-turn-button"
          @click="skipStunnedPlayerTurn"
        >
          Skip Turn
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { gameApi } from '@/services/api';

// Import the components
import LoadingScreen from '@/components/game/LoadingScreen.vue';
import LoadingOverlay from '@/components/game/LoadingOverlay.vue';
import ErrorDisplay from '@/components/game/ErrorDisplay.vue';
import PlayerSwitchNotification from '@/components/game/PlayerSwitchNotification.vue';
import HealingNotification from '@/components/game/HealingNotification.vue';
import InventoryFullDialog from '@/components/game/InventoryFullDialog.vue';
import MissingKeyDialog from '@/components/game/MissingKeyDialog.vue';
import BattleReportModal from '@/components/game/BattleReportModal.vue';
import ZoomControls from '@/components/game/ZoomControls.vue';
import GhostTile from '@/components/game/GhostTile.vue';
import ReplayControls from '@/components/game/ReplayControls.vue';
import GameTile from '@/components/game/GameTile.vue';
import AvailableMoveMarker from '@/components/game/AvailableMoveMarker.vue';
import ItemPickupDialog from '@/components/game/ItemPickupDialog.vue';
import ActionLog from '@/components/ActionLog.vue';
import { getTileOrientationChar, getTileOrientationClass, processTiles, getTileOrientationSymbol, parseOrientationString, hasOpening, hasMatchingDoors, getOppositeSide, isOpenedSide, getAllAdjacentPositions, getTileOrientationAt, isValidOrientation, getRequiredOpenSide, rotateGhostTile, handleInitialTileOrientation, highlightTile as highlightTileUtil, unhighlightTile as unhighlightTileUtil } from '@/utils/tileUtils';
import { isPlayerInGame as isPlayerInGameUtil, isSecondPlayerInGame as isSecondPlayerInGameUtil, isPlayerTurn as isPlayerTurnUtil, getPlayerEmoji, joinGame as joinGameUtil, generateUUID, getPlayerReady as getPlayerReadyUtil, formatPlayerId, switchPlayer as switchPlayerUtil, autoSwitchPlayer as autoSwitchPlayerUtil } from '@/utils/playerUtils';
import { getItemEmoji, getInventoryItemEmoji, getItemTooltip, formatItemName, handleItemClick as handleItemClickUtil } from '@/utils/itemUtils';

// Import field utility functions
import { playerIsAtPosition as isPlayerAt, getPlayerIdAtPosition as getPlayerAt, getAllPlayerIdsAtPosition as getAllPlayersAt, isPlayerPosition as isCurrentPlayerAt, getProcessedAvailablePlaces, centerViewOnCurrentPlayer as centerViewOnCurrentPlayerUtil, centerViewOnAvailablePlaces as centerViewOnAvailablePlacesUtil, scrollToPosition as scrollToPositionUtil, handlePlaceClick as handlePlaceClickUtil, isRequestActive, resetRequestLock, isItemWorthPickingUp, isFieldPlaceAlreadyTaken } from '@/utils/fieldUtils';
import { updateGameDataSelectively as doUpdateGameDataSelectively, initGame as doInitGame, joinGame as doJoinGame, startGame as doStartGame } from '@/utils/gameUtils';

// Import notification utility functions
import { dismissNotification as dismissNotificationUtil, showPlayerSwitchNotification as showPlayerSwitchNotificationUtil } from '@/utils/notificationUtils';

// Import inventory utility functions
import { selectInventoryItem as selectInventoryItemUtil, selectItemToReplace as selectItemToReplaceUtil, replaceItem as replaceItemUtil, skipItem as skipItemUtil, handleInventoryFullResponse as handleInventoryFullResponseUtil } from '@/utils/inventoryUtils';

// Import monster utility functions
import { getMonsterEmoji as getMonsterEmojiUtil } from '@/utils/monsterUtils';

// Import battle utility functions
import { closeBattleReportAndEndTurn as closeBattleReportAndEndTurnUtil } from '@/utils/battleUtils';

// Import keyboard utility functions
import { handleKeyboardEvents as handleKeyboardEventsUtil } from '@/utils/keyboardUtils';

// Helper function for calculating item damage values
const getItemDamage = (item) => {
  if (!item) return 0;

  // Use item type to determine damage
  const itemType = item.type || '';

  switch (itemType) {
    case 'dagger':
      return 1;
    case 'sword':
      return 2;
    case 'axe':
      return 3;
    case 'fireball':
      return 1;
    case 'teleport':
      return 0;
    default:
      // Check if item has a direct damage property
      return item.damage || 0;
  }
};

const router = useRouter();
const route = useRoute();
const id = computed(() => route.params.id);
const loading = ref(true);
const loadingStatus = ref('');  // Add this line to track specific loading operations
const error = ref(null);
const gameData = ref(null);
const currentPlayerId = ref(localStorage.getItem('currentPlayerId') || null);
const secondPlayerId = ref(localStorage.getItem('secondPlayerId') || null);
const humanPlayerId = ref(localStorage.getItem('humanPlayerId') || localStorage.getItem('currentPlayerId') || null);
const playerIsReady = ref(false);
const gameStarted = ref(false);
const isProcessingAI = ref(false); // Flag to block interactions during AI turn
const zoomLevel = ref(1);
const highlightedTile = ref(null);
const selectedTile = ref(null);
const pickedTileId = ref(null);
const pickedTile = ref(null);
const ghostTilePosition = ref(null);
const ghostTileOrientation = ref(null);
const isPlacingTile = ref(false);
const isMoveMode = ref(false);
const isPlaceMode = ref(false);
const tileSize = ref(100);
const playerSwitched = ref(false);
const notificationTimeout = ref(null);
const healingNotification = ref(null);
const showHealingNotification = ref(false);

// Inventory management state
const showInventoryFullDialog = ref(false);
const droppedItem = ref(null);
const itemCategory = ref('');
const maxItemsInCategory = ref(0);
const selectedItemToReplace = ref(null);
const inventoryForCategory = ref([]);
const isInventoryFullAfterBattle = ref(false); // Track if inventory full dialog is after battle

// Missing key dialog state
const showMissingKeyDialog = ref(false);
const missingKeyChestType = ref('chest');

// Item pickup dialog state
const showItemPickupDialog = ref(false);
const tileItem = ref(null);
const itemPickupTurnId = ref(null); // Store the turn ID when the dialog is shown

// Teleport spell state
const isTeleportMode = ref(false);
const selectedTeleportSpell = ref(null);

// Game turns for action log
const gameTurns = ref([]);

// WebSocket for real-time updates
// const socket = ref(null);

// Drag navigation state
const isDragging = ref(false);
const startDragX = ref(0);
const startDragY = ref(0);
const startScrollLeft = ref(0);
const startScrollTop = ref(0);
const fieldElement = ref(null); // Reference to the game field element

// Handle CTRL key press to show grab cursor
const isCtrlPressed = ref(false);

// Need to add isReplaying ref and replay state variables
const isReplaying = ref(false);
const currentReplayTurn = ref(0);
const originalGameData = ref(null);
const replaySpeed = ref(1); // 1 = normal speed, 0.5 = slow, 2 = fast
const gamePollingInterval = ref(null); // Interval for polling game updates

// Battle modal state
const showBattleReportModal = ref(false);
const battleInfo = ref(null);
const battleReportModalRef = ref(null);

// Computed property to determine if the game can be started
// Computed properties for ghost tile to ensure reactivity
const ghostTileOrientationSymbol = computed(() => {
  if (!ghostTileOrientation.value || !pickedTile.value) return '';
  const symbol = getTileOrientationSymbol(ghostTileOrientation.value, pickedTile.value.room);
  console.log('Computing ghost tile symbol:', {
    orientation: ghostTileOrientation.value,
    isRoom: pickedTile.value.room,
    symbol
  });
  return symbol;
});

const ghostTileOrientationClass = computed(() => {
  if (!ghostTileOrientation.value || !pickedTile.value) return '';
  return getTileOrientationClass(ghostTileOrientation.value, pickedTile.value.room);
});

const canStartGame = computed(() => {
  // Logic to determine if game can be started
  if (!gameData.value || !gameData.value.players) return false;

  // Check if we have at least two players and game is not already started
  return gameData.value.players.length >= 2 && !gameStarted.value;
});

// Computed styles for the field container
const fieldStyles = computed(() => {
  if (!gameData.value || !gameData.value.field) return {};

  // Fixed tile size at 100px
  tileSize.value = 100;

  // Get field dimensions based on tile positions
  let fieldWidth, fieldHeight;

  // Check if size has min/max properties (preferred format)
  if (gameData.value.field.size && 
      'minX' in gameData.value.field.size && 
      'maxX' in gameData.value.field.size && 
      'minY' in gameData.value.field.size && 
      'maxY' in gameData.value.field.size) {

    const widthTiles = gameData.value.field.size.maxX - gameData.value.field.size.minX + 1;
    const heightTiles = gameData.value.field.size.maxY - gameData.value.field.size.minY + 1;

    // Calculate field dimensions with fixed tile size
    fieldWidth = widthTiles * tileSize.value;
    fieldHeight = heightTiles * tileSize.value;

  } else if (gameData.value.field.size && 'width' in gameData.value.field.size && 'height' in gameData.value.field.size) {
    // Alternative format with direct width/height
    const widthTiles = gameData.value.field.size.width;
    const heightTiles = gameData.value.field.size.height;

    fieldWidth = widthTiles * tileSize.value;
    fieldHeight = heightTiles * tileSize.value;
  } else {
    // Default fallback
    fieldWidth = 600;
    fieldHeight = 600;
  }

  return {
    width: `${fieldWidth}px`,
    height: `${fieldHeight}px`,
    position: 'relative',
    transform: `scale(${zoomLevel.value})`,
    margin: '0 auto',
    transformOrigin: 'top left' // Change transform origin to avoid centering issues when zooming
  };
});

// Computed property to check if current player is in the game
const isPlayerInGame = computed(() => {
  return isPlayerInGameUtil(gameData.value, currentPlayerId.value);
});

// Check if the second player is in the game
const isSecondPlayerInGame = computed(() => {
  return isSecondPlayerInGameUtil(gameData.value, secondPlayerId.value);
});

// Check if it's the current player's turn
const isPlayerTurn = computed(() => {
  // Block interactions if game is finished
  if (gameData.value?.state?.status === 'finished') {
    return false;
  }
  // Block interactions if AI is processing
  if (isProcessingAI.value) {
    return false;
  }
  return isPlayerTurnUtil(gameData.value, currentPlayerId.value);
});

// Check if the player can place a tile
const canPlaceTile = computed(() => {
  return isPlayerTurn.value && pickedTileId.value !== null;
});

// Check if the player can rotate a tile
const canRotateTile = computed(() => {
  return isPlayerTurn.value && pickedTileId.value !== null;
});

// Process tiles to add additional information for rendering
const processedTiles = computed(() => {
  return processTiles(
    gameData.value,
    playerIsAtPosition,
    getPlayerIdAtPosition,
    getAllPlayersAtPosition,
    true // Enable limited logging
  );
});

// Get available places for rendering
const availablePlaces = computed(() => {
  // Use the enhanced utility function to get all available places
  return getProcessedAvailablePlaces(gameData.value);
});

// Wrapper for playerIsAtPosition function from utils
const playerIsAtPosition = (position) => {
  return isPlayerAt(gameData.value?.field, position);
};

// Wrapper for getPlayerIdAtPosition function from utils
const getPlayerIdAtPosition = (position) => {
  return getPlayerAt(gameData.value?.field, position);
};

// Wrapper for getAllPlayerIdsAtPosition function from utils
const getAllPlayersAtPosition = (position) => {
  return getAllPlayersAt(gameData.value?.field, position);
};

// Wrapper for isPlayerPosition function from utils
const isPlayerPosition = (position) => {
  return isCurrentPlayerAt(gameData.value?.field, position, currentPlayerId.value);
};

// Computed property to track if a request is currently in progress
const isRequestInProgress = computed(() => {
  return isRequestActive();
});

// Update game turns from game data (no separate API call needed)
const updateGameTurns = (gameDataResponse) => {
  if (gameDataResponse && gameDataResponse.turns) {
    gameTurns.value = gameDataResponse.turns.map(turn => ({
      turnId: turn.turnId || turn.turn_id,
      turnNumber: turn.turnNumber || turn.turn_number,
      playerId: turn.playerId || turn.player_id,
      actions: turn.actions || [],
      startTime: turn.startTime || turn.start_time,
      endTime: turn.endTime || turn.end_time,
    }));
  }
};

// Track if we're already processing an AI turn to prevent duplicates
let aiTurnInProgress = false;

// Check if current player is virtual and handle their turn
const checkAndHandleVirtualPlayerTurn = async () => {
  try {
    if (!gameData.value?.state) return;
    
    // Don't execute AI turn if game is finished
    if (gameData.value.state.status === 'finished') {
      console.log('Game is finished, skipping AI turn check');
      isProcessingAI.value = false;
      aiTurnInProgress = false;
      return;
    }
    
    const currentPlayer = gameData.value.state.currentPlayer || gameData.value.state.currentPlayerId;
    const virtualPlayerId = localStorage.getItem('virtualPlayerId');
    
    // console.log('DEBUG: Checking virtual player turn');
    // console.log('DEBUG: Current player:', currentPlayer);
    // console.log('DEBUG: Virtual player ID:', virtualPlayerId);
    
    // Check if current player is a virtual player (matches stored virtual player ID)
    if (virtualPlayerId && currentPlayer === virtualPlayerId) {
      // Prevent duplicate AI turn execution
      if (aiTurnInProgress) {
        console.log('⚠️ AI turn already in progress, skipping duplicate call');
        return;
      }
      
      console.log('✅ Virtual player turn detected:', currentPlayer);
      
      // Set both flags to block interactions and prevent duplicates
      isProcessingAI.value = true;
      aiTurnInProgress = true;
      
      // Add a small delay to make it feel more natural
      setTimeout(async () => {
        try {
          await executeVirtualPlayerTurn(currentPlayer);
        } finally {
          // Clear the in-progress flag after execution
          aiTurnInProgress = false;
        }
      }, 1000); // 1 second delay
    } else {
      console.log('❌ Not a virtual player turn');
      // Make sure to clear the flags if it's not AI turn
      isProcessingAI.value = false;
      aiTurnInProgress = false;
    }
  } catch (error) {
    console.error('Error checking virtual player turn:', error);
    aiTurnInProgress = false;
  }
};

// Execute a virtual player's turn
const executeVirtualPlayerTurn = async (playerId) => {
  try {
    loading.value = true;
    loadingStatus.value = 'Virtual player thinking...';
    
    console.log('Executing virtual player turn for:', playerId);
    
    // Call the backend to execute the virtual player's turn
    const response = await gameApi.executeVirtualPlayerTurn(id.value, playerId);
    
    if (response.success) {
      console.log('Virtual player actions:', response.actions);
      
      // Refresh game state after virtual player's turn
      await loadGameData();
    } else {
      console.error('Virtual player turn failed:', response);
    }
  } catch (error) {
    console.error('Failed to execute virtual player turn:', error);
    error.value = `Virtual player error: ${error.message}`;
  } finally {
    loading.value = false;
    loadingStatus.value = '';
    // Clear the AI processing flag to re-enable interactions
    isProcessingAI.value = false;
  }
};

const loadGameData = async () => {
  if (!id.value) {
    console.error('No game ID found in route params');
    error.value = 'Invalid game ID';
    loading.value = false;
    // Reset request lock on error
    resetRequestLock();
    return;
  }

  console.log('Loading game with ID:', id.value);

  try {
    // Attempt to load the game data
    loading.value = true;
    const fetchedGameData = await gameApi.getGame(id.value);
    console.log('Game data loaded:', fetchedGameData);

    // Initialize gameData if it doesn't exist yet
    if (!gameData.value) {
      gameData.value = fetchedGameData;
    } else {
      // Use selective update for existing gameData
      updateGameDataSelectively(fetchedGameData);
    }

    // Update game turns from the fetched game data
    updateGameTurns(fetchedGameData);

    // Check if it's a virtual player's turn and handle it
    // Skip this check if we're already processing an AI turn (called from executeVirtualPlayerTurn)
    if (!aiTurnInProgress) {
      await checkAndHandleVirtualPlayerTurn();
    }

    // Check for pending battle info and show battle modal if needed
    if (fetchedGameData.field && fetchedGameData.field.lastBattleInfo) {
      const battleData = fetchedGameData.field.lastBattleInfo;

      // Only show battle modal if it's for the current player and modal isn't already shown
      if (battleData.player === currentPlayerId.value && !showBattleReportModal.value) {
        console.log('Found pending battle info for current player:', battleData);
        battleInfo.value = battleData;
        showBattleReportModal.value = true;
      }
    } else {
      console.log('DEBUG: No lastBattleInfo found in field data');
    }

    // Check if we need to update our current player
    if (fetchedGameData.state && fetchedGameData.state.currentPlayerId) {
      checkStunnedPlayersAndSwitch();
    }
    
    // Set game as started if appropriate
    if (gameData.value.state && (gameData.value.state.status === 'started' || gameData.value.state.status === 'turn_in_progress')) {
      // Set gameStarted to true when loading an already started game
      gameStarted.value = true;

      // Also update playerIsReady status
      if (isPlayerInGame.value) {
        playerIsReady.value = true;
      }
    }

    // Initialize game here if using Phaser
    initGame();
    error.value = null;

    // Reset request lock after successful load
    resetRequestLock();
  } catch (err) {
    console.error('Error loading game:', err);
    error.value = 'Failed to load game. The game ID may be invalid.';
    // Reset request lock on error
    resetRequestLock();
  } finally {
    loading.value = false;
  }
};

// Watch for route changes to reload data if necessary
watch(() => route.params.id, (newId, oldId) => {
  if (newId !== oldId) {
    loadGameData();
  }
});

onMounted(async () => {
  console.log('Game view mounted with game ID:', id.value);

  try {
    // Initial game data load
    loading.value = true;
    loadingStatus.value = 'Loading game data...';
    await loadGameData();

    // Initialize WebSocket for real-time updates
    // initWebSocket();
  } catch (err) {
    console.error('Error loading game data:', err);
    error.value = `Failed to load game data: ${err.message}`;
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
});

onBeforeUnmount(() => {
  // Clean up WebSocket connection when component is destroyed
  // if (socket.value) {
  //   socket.value.close();
  //   socket.value = null;
  // }
});

// Helper function to selectively update game data without replacing the entire object
// This function has been moved to @/utils/gameUtils.js
// Import is already at the top of this file
const updateGameDataSelectively = (updatedData) => {
  // Check for HP changes before updating
  if (updatedData.players && gameData.value?.players) {
    updatedData.players.forEach(updatedPlayer => {
      const currentPlayer = gameData.value.players.find(p => p.id === updatedPlayer.id);
      if (currentPlayer && currentPlayer.hp < updatedPlayer.hp) {
        // Player was healed!
        const healAmount = updatedPlayer.hp - currentPlayer.hp;
        
        // Check if player is at a healing fountain tile
        const playerPosition = updatedData.field?.playerPositions?.[updatedPlayer.id];
        if (playerPosition) {
          const tile = processedTiles.value?.find(t => t.position === playerPosition);
          if (tile?.hasHealingFountain) {
            // Show healing notification
            showHealingNotificationForPlayer(updatedPlayer.id, healAmount);
          }
        }
      }
    });
  }
  
  // Call the imported function with gameData.value as the first parameter
  return doUpdateGameDataSelectively(gameData.value, updatedData);
};

const initGame = () => {
  // Call the imported initGame function with the necessary parameters
  doInitGame(id.value, gameData.value, centerViewOnCurrentPlayer);
};

// Function to join the game
const joinGame = async () => {
  try {
    // Call the extracted joinGame utility function from gameUtils
    const result = await doJoinGame(
      id.value, 
      currentPlayerId.value, 
      gameApi, 
      updateGameDataSelectively,
      (value) => loading.value = value
    );

    // Update the player ID if a new one was generated
    if (result && result.playerId) {
      currentPlayerId.value = result.playerId;
      localStorage.setItem('currentPlayerId', currentPlayerId.value);
    }
  } catch (err) {
    console.error('Failed to join game:', err);
    error.value = err.message || 'Failed to join game';
  }
};

// Function to mark player as ready
const getPlayerReady = async () => {
  try {
    // Call the extracted getPlayerReady utility function
    const result = await getPlayerReadyUtil(
      id.value,
      currentPlayerId.value,
      gameApi,
      updateGameDataSelectively,
      (value) => loading.value = value,
      (value) => loadingStatus.value = value,
      (value) => error.value = value
    );

    // Set player as ready if successful
    if (result && result.success) {
      playerIsReady.value = true;
    }
  } catch (err) {
    console.error('Failed to mark player as ready:', err);
    // Error is already set in the utility function
  }
};

// Function to add a second player and set them ready
const addSecondPlayer = async () => {
  try {
    // Start loading without destroying UI
    loading.value = true;
    loadingStatus.value = 'Adding second player...';

    // Generate a new player ID for the second player
    secondPlayerId.value = generateUUID();
    localStorage.setItem('secondPlayerId', secondPlayerId.value);

    console.log('Adding second player to game with ID:', id.value, 'as player:', secondPlayerId.value);

    // Join the game with the second player (as Player 2)
    await gameApi.joinGame(id.value, secondPlayerId.value, null, 'Player 2');

    // Mark the second player as ready
    loadingStatus.value = 'Getting second player ready...';
    await gameApi.playerReady(id.value, secondPlayerId.value);

    // Refresh game data
    loadingStatus.value = 'Updating game status...';
    const updatedGameData = await gameApi.getGame(id.value);
    updateGameDataSelectively(updatedGameData);

    console.log('Successfully added and set ready second player:', secondPlayerId.value);
  } catch (err) {
    console.error('Failed to add second player:', err);
    error.value = `Failed to add second player: ${err.message}`;
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Zoom control functions
const zoomIn = () => {
  zoomLevel.value = Math.min(zoomLevel.value + 0.2, 2);
};

const zoomOut = () => {
  zoomLevel.value = Math.max(zoomLevel.value - 0.2, 0.5);
};

const resetZoom = () => {
  zoomLevel.value = 1;
  // Scroll to middle of the field
  if (gameData.value && gameData.value.field && gameData.value.field.size) {
    centerViewOnMiddle();
  }
};

// Function to center view on a specific tile
const centerViewOnTile = (tile) => {
  if (!tile) return;

  // If tile is already a proper object with x and y properties
  if (tile.x !== undefined && tile.y !== undefined) {
    scrollToPosition(tile.x, tile.y);
  } 
  // If we got a position string (common in some API responses)
  else if (typeof tile === 'string') {
    const [x, y] = tile.split(',').map(Number);
    scrollToPosition(x, y);
  }
  // If we got a position object in a different format
  else if (tile.position) {
    const [x, y] = tile.position.split(',').map(Number);
    scrollToPosition(x, y);
  }
};

// Center view on the middle of the field
const centerViewOnMiddle = () => {
  const fieldElement = document.querySelector('.game-field');
  if (!fieldElement || !gameData.value || !gameData.value.field || !gameData.value.field.size) return;

  const minX = gameData.value.field.size.minX || 0;
  const maxX = gameData.value.field.size.maxX || 0;
  const minY = gameData.value.field.size.minY || 0;
  const maxY = gameData.value.field.size.maxY || 0;

  // Calculate field dimensions
  const fieldWidth = (maxX - minX + 1) * tileSize.value;
  const fieldHeight = (maxY - minY + 1) * tileSize.value;

  // Calculate view dimensions
  const viewWidth = fieldElement.clientWidth;
  const viewHeight = fieldElement.clientHeight;

  // Get center coordinates of the field in pixels
  const centerX = ((maxX + minX) / 2 - minX) * tileSize.value;
  const centerY = ((maxY + minY) / 2 - minY) * tileSize.value;

  // Calculate ideal scroll positions
  let idealScrollLeft = centerX - (viewWidth / 2);
  let idealScrollTop = centerY - (viewHeight / 2);

  // For debugging
  console.log('Centering on middle:', {
    fieldDimensions: { width: fieldWidth, height: fieldHeight, minX, maxX, minY, maxY },
    viewDimensions: { width: viewWidth, height: viewHeight },
    centerPoint: { x: centerX, y: centerY },
    idealScroll: { left: idealScrollLeft, top: idealScrollTop }
  });

  // Take into account the field's padding (100px on all sides)
  const padding = 100;

  // Calculate maximum scroll boundaries with padding
  const maxScrollLeft = Math.max(0, fieldWidth + (padding * 2) - viewWidth);
  const maxScrollTop = Math.max(0, fieldHeight + (padding * 2) - viewHeight);

  // Ensure we don't scroll beyond field boundaries
  // Add padding to account for the UI elements and padding in .game-field
  const boundedScrollLeft = Math.min(Math.max(0, idealScrollLeft + padding), maxScrollLeft);
  const boundedScrollTop = Math.min(Math.max(0, idealScrollTop + padding), maxScrollTop);

  // Apply scroll with smooth behavior
  fieldElement.scrollTo({
    left: boundedScrollLeft,
    top: boundedScrollTop,
    behavior: 'smooth'
  });

  // Debug info
  console.log('Applied middle scroll:', { left: boundedScrollLeft, top: boundedScrollTop });
};

// Tile highlighting functions
const highlightTile = (tileData, centerView = false) => {
  const tile = highlightTileUtil(tileData, processedTiles.value, centerViewOnTile, centerView);
  if (tile) {
    highlightedTile.value = tile;
  }
};

const unhighlightTile = () => {
  highlightedTile.value = unhighlightTileUtil();
};

// Function to start the game
const startGame = async () => {
  try {
    // Call the extracted startGame utility function
    const result = await doStartGame(
      id.value,
      gameApi,
      updateGameDataSelectively,
      (value) => loading.value = value,
      (value) => loadingStatus.value = value,
      (value) => error.value = value,
      (value) => gameStarted.value = value
    );

    // The utility function handles all the logic and error handling
    if (result && result.success) {
      console.log('Game started successfully via utility function');
    }
  } catch (err) {
    console.error('Failed to start game:', err);
    // Error is already set in the utility function
  }
};

// Watch for changes in tile size and update the CSS variable
watch(tileSize, (newSize) => {
  document.documentElement.style.setProperty('--tile-size', `${newSize}px`);

  // Also update the tile size CSS variable
  const tiles = document.querySelectorAll('.tile');
  if (tiles.length) {
    tiles.forEach(tile => {
      tile.style.setProperty('--tile-size', `${newSize}px`);
    });
  }
});

// Watch for orientation changes during tile placement
watch(ghostTileOrientation, (newVal, oldVal) => {
  if (newVal !== oldVal && newVal) {
    const newSymbol = getTileOrientationSymbol(newVal, pickedTile.value?.room);
    const newClass = getTileOrientationClass(newVal, pickedTile.value?.room);
    console.log('Ghost tile orientation changed:', {
      from: oldVal,
      to: newVal,
      symbol: newSymbol,
      class: newClass,
      isRoom: pickedTile.value?.room
    });
    
    // Force update of the ghost tile by triggering a re-render
    nextTick(() => {
      console.log('After nextTick - checking computed values:', {
        orientation: ghostTileOrientation.value,
        computedSymbol: getTileOrientationSymbol(ghostTileOrientation.value, pickedTile.value?.room)
      });
    });
  }
});

// Watch for changes in field dimensions
watch(() => gameData.value?.field?.size, (newSize) => {
  if (newSize) {
    document.documentElement.style.setProperty('--field-min-x', newSize.minX || 0);
    document.documentElement.style.setProperty('--field-min-y', newSize.minY || 0);

    // Update all tiles with the new field dimensions
    const tiles = document.querySelectorAll('.tile');
    if (tiles.length) {
      tiles.forEach(tile => {
        tile.style.setProperty('--field-min-x', newSize.minX || 0);
        tile.style.setProperty('--field-min-y', newSize.minY || 0);
      });
    }
  }
}, { deep: true });

// Handle window resize by resetting zoom and letting the computed properties recalculate
const handleResize = () => {
  zoomLevel.value = 1;
  // Simply accessing the fieldStyles computed property will trigger recalculation
  const _ = fieldStyles.value;
};

// Clean up event listener on component unmount
onUnmounted(() => {
  window.removeEventListener('resize', handleResize);

  // Remove global event listeners for drag navigation
  window.removeEventListener('mouseup', onMouseUp);
  window.removeEventListener('mousemove', onMouseMove);

  // Remove keyboard event listeners for CTRL key detection
  window.removeEventListener('keydown', onKeyDown);
  window.removeEventListener('keyup', onKeyUp);

  // Remove keyboard listener for combined keyboard events
  window.removeEventListener('keydown', handleKeyboardEvents);

  // Clear the polling interval
  if (gamePollingInterval.value) {
    clearInterval(gamePollingInterval.value);
    gamePollingInterval.value = null;
  }
});

// Methods for drag navigation
const onMouseDown = (e) => {
  if (!fieldElement.value) return;

  // Also start dragging on middle-click (button 1) or when holding space with any mouse button
  if (e.button === 1 || (e.button === 0 && (e.ctrlKey || isCtrlPressed.value))) {
    isDragging.value = true;
    startDragX.value = e.pageX;
    startDragY.value = e.pageY;
    startScrollLeft.value = fieldElement.value.scrollLeft;
    startScrollTop.value = fieldElement.value.scrollTop;

    // Change cursor style
    fieldElement.value.style.cursor = 'grabbing';

    // Prevent default behavior (like text selection)
    e.preventDefault();
  }
};

const onMouseMove = (e) => {
  if (!isDragging.value || !fieldElement.value) return;

  // Calculate distance moved
  const dx = e.pageX - startDragX.value;
  const dy = e.pageY - startDragY.value;

  // Scroll in the opposite direction of drag
  fieldElement.value.scrollLeft = startScrollLeft.value - dx;
  fieldElement.value.scrollTop = startScrollTop.value - dy;

  // Prevent default behavior
  e.preventDefault();
};

const onMouseUp = () => {
  if (!isDragging.value) return;

  isDragging.value = false;

  // Reset cursor style
  if (fieldElement.value) {
    fieldElement.value.style.cursor = 'default';
  }
};

// Handle mouse leaving the field
const onMouseLeave = () => {
  if (isDragging.value) {
    isDragging.value = false;

    // Reset cursor style
    if (fieldElement.value) {
      fieldElement.value.style.cursor = 'default';
    }
  }
};

// Handle CTRL key press to show grab cursor
const onKeyDown = (e) => {
  if (e.key === 'Control') {
    isCtrlPressed.value = true;
    if (fieldElement.value) {
      fieldElement.value.style.cursor = 'grab';
    }
  }
};

const onKeyUp = (e) => {
  if (e.key === 'Control') {
    isCtrlPressed.value = false;
    if (fieldElement.value && !isDragging.value) {
      fieldElement.value.style.cursor = 'default';
    }
  }
};

// Add startReplay method and related replay functionality
const startReplay = async () => {
  // Replay functionality disabled (uses /turns API)
  alert('Replay functionality is currently disabled');
  return;
  /*
  if (isReplaying.value) return;

  try {
    // Fetch all turns for the game
    const response = await gameApi.getGameTurns(id.value);

    if (!response || !response.turns || !response.turns.length) {
      alert('No turns available for replay');
      return;
    }

    // Process the turns directly from the API response
    gameTurns.value = response.turns.map(turn => {
      // The API already provides the actions in parsed format
      return {
        turnId: turn.turn_id,
        turnNumber: turn.turn_number,
        playerId: turn.player_id,
        actions: turn.actions,
        startTime: turn.start_time,
        // Don't rely on end_time as it's always null currently
        endTime: null 
      };
    });

    if (!gameTurns.value.length) {
      alert('No turns available for replay');
      return;
    }
    // Save original game data to restore after replay
    originalGameData.value = JSON.parse(JSON.stringify(gameData.value));

    // Setup replay UI and state
    isReplaying.value = true;
    currentReplayTurn.value = 0;
    replaySpeed.value = 1;

    // Add class to game field to enable replay-specific styling
    const gameField = document.querySelector('.game-field');
    if (gameField) {
      gameField.classList.add('is-replaying');
    }

    // Show replay overlay
    nextTick(() => { // Use imported nextTick instead of this.$nextTick
      const overlay = document.createElement('div');
      overlay.className = 'replay-overlay';
      overlay.id = 'replay-overlay';

      const status = document.createElement('div');
      status.className = 'replay-status';
      status.textContent = 'REPLAY MODE';
      overlay.appendChild(status);

      // Create speed indicator
      const speedIndicator = document.createElement('div');
      speedIndicator.className = 'replay-speed-indicator';
      speedIndicator.id = 'replay-speed-indicator';
      speedIndicator.textContent = `Speed: ${replaySpeed.value}x`;

      document.querySelector('.game-container').appendChild(overlay);
      document.querySelector('.game-container').appendChild(speedIndicator);
    });

    // Clean the field before starting replay
    cleanFieldForReplay();

    // Center view on the middle of the field
    centerViewOnMiddle();

    // Start the replay sequence after a short delay to allow for UI updates
    setTimeout(() => {
      replayNextTurn();
    }, 500);

  } catch (error) {
    console.error('Failed to start replay:', error);
    alert('Failed to start replay: ' + (error.message || 'Unknown error'));
    isReplaying.value = false;
  }
  */
};

const finishReplay = () => {
  // Ask for confirmation before ending replay
  if (isReplaying.value && !confirm('Are you sure you want to stop the replay?')) {
    return;
  }

  isReplaying.value = false;
  clearTimeout(replayTimeout.value);

  // Clean up animations: remove classes from all DOM elements
  const tiles = document.querySelectorAll('.tile');
  tiles.forEach(tile => {
    tile.classList.remove('is-current-action');
    tile.classList.remove('tile-placing-animation');
  });

  // Also clear any animation flags from the data model
  if (gameData.value && gameData.value.field && gameData.value.field.tiles) {
    gameData.value.field.tiles.forEach(tile => {
      tile.isCurrentAction = false;
      tile.isPlacingAnimation = false;
    });
  }

  // Remove class from game field
  const gameField = document.querySelector('.game-field');
  if (gameField) {
    gameField.classList.remove('is-replaying');
  }

  // Remove overlay and speed indicator
  const overlay = document.getElementById('replay-overlay');
  const speedIndicator = document.getElementById('replay-speed-indicator');

  if (overlay) {
    overlay.remove();
  }

  if (speedIndicator) {
    speedIndicator.remove();
  }

  // Restore original game data
  if (originalGameData.value) {
    gameData.value = originalGameData.value;
  }

  // Reset replay state
  currentReplayTurn.value = 0;

  // Center view on the middle of the field
  setTimeout(() => {
    centerViewOnMiddle();
  }, 100);
};

const changeReplaySpeed = (newSpeed) => {
  // Update the replay speed with the value received from the ReplayControls component
  replaySpeed.value = newSpeed;

  // Update speed indicator
  const speedIndicator = document.getElementById('replay-speed-indicator');
  if (speedIndicator) {
    speedIndicator.textContent = `Speed: ${replaySpeed.value}x`;
  }
};

// Add a function to clean the field before replay
const cleanFieldForReplay = () => {
  console.log('Cleaning field for replay');

  if (gameData.value && gameData.value.field) {
    // Keep only the starting tile (at 0,0) and remove all other tiles
    if (gameData.value.field.tiles && gameData.value.field.tiles.length > 0) {
      // Find the starting tile (usually at 0,0)
      const startingTileIndex = gameData.value.field.tiles.findIndex(
        tile => {
          const [x, y] = tile.position.split(',').map(Number);
          return x === 0 && y === 0;
        }
      );

      // If we found a starting tile, keep only that one
      if (startingTileIndex >= 0) {
        const startingTile = gameData.value.field.tiles[startingTileIndex];
        gameData.value.field.tiles = [startingTile];
      } else {
        // If no starting tile is found, just clear all tiles
        gameData.value.field.tiles = [];
      }
    }

    // Clear player positions
    if (gameData.value.field.playerPositions) {
      // Reset all players to starting position (0,0)
      for (const playerId in gameData.value.field.playerPositions) {
        gameData.value.field.playerPositions[playerId] = '0,0';
      }
    }

    // Reset field size to minimal (just around the starting tile)
    if (gameData.value.field.size) {
      gameData.value.field.size.minX = -1;
      gameData.value.field.size.maxX = 1;
      gameData.value.field.size.minY = -1;
      gameData.value.field.size.maxY = 1;
    }

    console.log('Field cleaned for replay', gameData.value.field);
  }
};

// Track replay timeout so we can clear it
const replayTimeout = ref(null);

const replayNextTurn = () => {
  if (!isReplaying.value || currentReplayTurn.value >= gameTurns.value.length) {
    // End of replay
    finishReplay();
    return;
  }

  const turn = gameTurns.value[currentReplayTurn.value];

  // Clean up previous animations properly
  // 1. Remove the is-current-action class from all DOM elements
  const tiles = document.querySelectorAll('.tile');
  tiles.forEach(tile => {
    tile.classList.remove('is-current-action');
  });

  // 2. Clear the isCurrentAction flag from all tiles in the data model
  if (gameData.value && gameData.value.field && gameData.value.field.tiles) {
    gameData.value.field.tiles.forEach(tile => {
      if (tile.isCurrentAction) {
        tile.isCurrentAction = false;
      }
    });
  }

  // Variables to track the primary action for this turn
  let activeTilePos = null;
  let activeTileType = 'default'; // Default type if no specific action was found

  // Use the original game state and simulate actions to display this turn
  if (gameData.value && gameData.value.field) {
    // Group actions by type for sequential processing
    const placeTileActions = turn.actions.filter(action => action.action === 'place_tile');
    const rotateTileActions = turn.actions.filter(action => action.action === 'rotate_tile');
    const moveActions = turn.actions.filter(action => action.action === 'move');

    // First process tile placements
    if (placeTileActions.length > 0) {
      // For each placement, update the game state
      placeTileActions.forEach((action) => {
        const fieldPlace = action.additionalData?.fieldPlace;
        if (fieldPlace && action.tileId) {
          // Update the tiles collection with this placement
          const [x, y] = fieldPlace.split(',').map(Number);

          // Find if the tile already exists or add a new one
          const existingTileIndex = gameData.value.field.tiles.findIndex(
            t => t.position === fieldPlace
          );

          // Calculate animation start position from player's position
          let animationStartX, animationStartY;
          const playerPosition = gameData.value.field.playerPositions?.[turn.playerId];

          if (playerPosition) {
            // Get player coordinates
            const [playerX, playerY] = playerPosition.split(',').map(Number);
            const minX = gameData.value.field.size.minX || 0;
            const minY = gameData.value.field.size.minY || 0;

            // Calculate start pixel positions (relative to the tiles container)
            animationStartX = (playerX - minX) * tileSize.value;
            animationStartY = (playerY - minY) * tileSize.value;
          }

          if (existingTileIndex >= 0) {
            // Update existing tile
            gameData.value.field.tiles[existingTileIndex].tileId = action.tileId;
            // Flag for current action
            gameData.value.field.tiles[existingTileIndex].isCurrentAction = true;
          } else {
            // Add new tile with animation data
            const newTile = {
              position: fieldPlace,
              tileId: action.tileId,
              isCurrentAction: true,
              isPlacingAnimation: true,
              animationStartX,
              animationStartY,
              turnNumber: currentReplayTurn.value + 1
            };
            gameData.value.field.tiles.push(newTile);
          }

          // Update field size if needed
          if (gameData.value.field.size) {
            gameData.value.field.size.minX = Math.min(gameData.value.field.size.minX || 0, x);
            gameData.value.field.size.maxX = Math.max(gameData.value.field.size.maxX || 0, x);
            gameData.value.field.size.minY = Math.min(gameData.value.field.size.minY || 0, y);
            gameData.value.field.size.maxY = Math.max(gameData.value.field.size.maxY || 0, y);
          }

          // Track this as the active position for centering view
          activeTilePos = { x, y };
          activeTileType = 'placement';
        }
      });

      // Apply current action class after a short delay to ensure DOM is updated
      setTimeout(() => {
        placeTileActions.forEach((action) => {
          const fieldPlace = action.additionalData?.fieldPlace;
          if (fieldPlace) {
            const [x, y] = fieldPlace.split(',').map(Number);
            const tileElement = document.querySelector(`.tile[data-x="${x}"][data-y="${y}"]`);
            if (tileElement) {
              tileElement.classList.add('is-current-action');

              // Remove animation class after animation completes
              setTimeout(() => {
                // Find the tile in our data model and remove the animation flag
                const tileIndex = gameData.value.field.tiles.findIndex(
                  t => t.position === fieldPlace
                );
                if (tileIndex >= 0) {
                  gameData.value.field.tiles[tileIndex].isPlacingAnimation = false;
                }
              }, 1000); // slightly longer than animation duration
            }
          }
        });
      }, 10);
    }

    // Process rotate tile actions (lower priority than placements)
    if (rotateTileActions.length > 0 && !activeTilePos) {
      rotateTileActions.forEach(action => {
        const fieldPlace = action.additionalData?.fieldPlace;
        if (fieldPlace) {
          const [x, y] = fieldPlace.split(',').map(Number);

          // Find the tile to rotate
          const tileIndex = gameData.value.field.tiles.findIndex(
            t => t.position === fieldPlace
          );

          if (tileIndex >= 0) {
            // Only mark as current action if no tile placement
            if (placeTileActions.length === 0) {
              gameData.value.field.tiles[tileIndex].isCurrentAction = true;
            }

            // Apply current action class after a short delay to ensure DOM is updated
            setTimeout(() => {
              const tileElement = document.querySelector(`.tile[data-x="${x}"][data-y="${y}"]`);
              if (tileElement && placeTileActions.length === 0) {
                tileElement.classList.add('is-current-action');
              }
            }, 10);

            // Track this as the active position for centering view if no placement
            if (!activeTilePos) {
              activeTilePos = { x, y };
              activeTileType = 'rotation';
            }
          }
        }
      });
    }

    // Process move actions
    if (moveActions.length > 0) {
      moveActions.forEach(action => {
        const toPosition = action.additionalData?.toPosition;
        if (toPosition && gameData.value.field.playerPositions) {
          // Update the player position
          gameData.value.field.playerPositions[turn.playerId] = toPosition;

          // Parse coordinates from position
          const [x, y] = toPosition.split(',').map(Number);

          // Flag the destination tile for highlighting
          const tileIndex = gameData.value.field.tiles.findIndex(
            t => t.position === toPosition
          );

          if (tileIndex >= 0) {
            // Mark as current action if no tile placement or rotation
            if (placeTileActions.length === 0 && rotateTileActions.length === 0) {
              gameData.value.field.tiles[tileIndex].isCurrentAction = true;
            }

            // Apply current action class after a short delay to ensure DOM is updated
            setTimeout(() => {
              const tileElement = document.querySelector(`.tile[data-x="${x}"][data-y="${y}"]`);
              if (tileElement && placeTileActions.length === 0 && rotateTileActions.length === 0) {
                tileElement.classList.add('is-current-action');
              }
            }, 10);

            // Track this as the active position for centering view if no placement or rotation
            if (!activeTilePos) {
              activeTilePos = { x, y };
              activeTileType = 'movement';
            }
          }
        }
      });
    }

    // Center the view on the most important action
    if (activeTilePos) {
      centerViewOnTile(activeTilePos);
    } else {
      centerViewOnMiddle();
    }
  }

  // Add a visual feedback that the turn is being processed
  const gameBoard = document.querySelector('.game-board');
  if (gameBoard) {
    gameBoard.classList.add('turn-transition');
    setTimeout(() => {
      gameBoard.classList.remove('turn-transition');
    }, 300);
  }

  // Increment the turn counter
  currentReplayTurn.value++;

  // Adjust timing based on action type - placement and rotation need more time than movement
  let baseDuration = 2000; // 2 seconds base duration
  if (activeTileType === 'placement') {
    baseDuration = 2500;
  } else if (activeTileType === 'rotation') {
    baseDuration = 2000;
  } else if (activeTileType === 'movement') {
    baseDuration = 1500;
  }

  // Apply speed factor
  const duration = baseDuration / replaySpeed.value;

  // Continue to the next turn after a delay (adjusted by replay speed)
  replayTimeout.value = setTimeout(() => {
    replayNextTurn();
  }, duration);
};

// Helper functions for the replay UI
const formatActionType = (actionType) => {
  // Convert snake_case to Title Case
  if (!actionType) return 'Unknown Action';
  return actionType.split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};

const formatTileId = (tileId) => {
  // Shorten tile ID for display
  if (!tileId) return 'Unknown';
  return tileId.substring(0, 8) + '...';
};

const formatTime = (timestamp) => {
  if (!timestamp) return 'N/A';

  try {
    // Convert timestamp to readable format
    const date = new Date(timestamp);
    return date.toLocaleTimeString();
  } catch (e) {
    console.error('Error formatting timestamp:', e);
    return 'Invalid timestamp';
  }
};

// Function to center view on the current player's position
const centerViewOnCurrentPlayer = () => {
  centerViewOnCurrentPlayerUtil(gameData.value, centerViewOnMiddle, processedTiles.value, centerViewOnTile);
};

// Watch for changes in the game state to center on player when needed
watch(() => gameData.value?.state?.currentPlayerId, async (newPlayerId, oldPlayerId) => {
  if (newPlayerId && newPlayerId !== oldPlayerId) {
    // When current player changes, center on them
    nextTick(() => {
      centerViewOnCurrentPlayer();
    });
    
    // Check if the new current player is an AI player
    // This handles cases where turn auto-ends (e.g., after 4 moves)
    await checkAndHandleVirtualPlayerTurn();
  }
}, { immediate: false });

// Watch for changes in game status - center on player when game starts
watch(() => gameData.value?.state?.status, (newStatus, oldStatus) => {
  if (newStatus === 'started') {
    // When game starts, center on current player
    gameStarted.value = true;
    nextTick(() => {
      centerViewOnCurrentPlayer();
    });
  } else if (newStatus === 'finished') {
    // Game is finished, show the leaderboard
    console.log('Game finished, showing leaderboard');

    // Extract scores from players' treasures
    const entries = gameData.value.players.map(player => {
      const treasures = player.inventory?.treasures || [];
      const treasureValue = treasures.reduce((sum, treasure) => sum + (treasure.treasureValue || 0), 0);
      return {
        playerId: player.id,
        externalId: player.externalId || null,
        treasure: treasureValue
      };
    });

    // Sort entries by treasure value (highest first)
    entries.sort((a, b) => b.treasure - a.treasure);

    // Set the leaderboard data
    leaderboard.value = entries;

    // Get the winner (player with most treasure)
    winnerId.value = entries.length > 0 ? entries[0].playerId : null;
    
    // Also store the winner's externalId for authenticated user comparison
    const winnerExternalId = entries.length > 0 ? entries[0].externalId : null;
    console.log('Winner determined:', { winnerId: winnerId.value, winnerExternalId });

    // Close battle modal if it's still open before showing leaderboard
    if (showBattleReportModal.value) {
      console.log('Closing battle modal before showing leaderboard');
      showBattleReportModal.value = false;
      
      // Add a small delay to ensure DOM updates
      setTimeout(() => {
        showLeaderboardModal.value = true;
      }, 100);
    } else {
      // Show the leaderboard modal immediately if battle modal is not open
      showLeaderboardModal.value = true;
    }
  }
}, { immediate: true });

// Function to center view on all available places for player moves or tile placements
const centerViewOnAvailablePlaces = () => {
  centerViewOnAvailablePlacesUtil(gameData.value, centerViewOnMiddle, tileSize.value);
};

// Function to handle clicking on an available place
const handlePlaceClick = async (position) => {
  // Double-check it's actually the player's turn (in case of stale UI state)
  if (!isPlayerTurn.value) {
    console.log('Not player turn - ignoring click. Current player:', gameData.value?.state?.currentPlayerId, 'UI player:', currentPlayerId.value);
    // Force refresh game state if there's a mismatch
    await loadGameData();
    return;
  }
  
  // Prevent actions if player is stunned
  if (isCurrentPlayerStunned.value) {
    console.log('Player is stunned and cannot move');
    return;
  }
  
  const response = await handlePlaceClickUtil({
    position,
    isPlayerTurn: isPlayerTurn.value,
    gameData: gameData.value,
    currentPlayerId,
    autoSwitchPlayer,
    gameApi,
    gameId: id.value,
    loadingState: { loading, loadingStatus, error },
    battleState: { battleInfo, showBattleReportModal },
    updateGameDataSelectively,
    centerViewOnTile,
    tileState: { 
      pickedTileId, 
      pickedTile, 
      ghostTilePosition, 
      isPlacingTile, 
      ghostTileOrientation 
    },
    tileUtils: {
      isValidOrientation: isValidOrientationLocal,
      getTileOrientationChar,
      getTileOrientationSymbol,
      generateUUID,
      getRequiredOpenSide: getRequiredOpenSideLocal,
      handleInitialTileOrientation: handleInitialTileOrientationLocal,
      cancelTilePlacement
    }
  });
  
  // Check if the move response contains a battle with a reward (win)
  if (response?.battleInfo && response.battleInfo.result === 'win' && response.battleInfo.reward) {
    console.log('Battle won! Reward will be handled through battle modal');
    // Battle rewards are handled through the battle modal, not the item pickup dialog
    // The battle modal will show automatically when loadGameData() is called
  }
  // Check if the move response contains itemInfo (non-battle item)
  else if (response?.itemInfo) {
    console.log('Item found at destination:', response.itemInfo);
    
    // Store the current turn ID and item info
    itemPickupTurnId.value = gameData.value?.state?.currentTurnId;
    tileItem.value = response.itemInfo.item;
    
    // Check if the item requires a key and player doesn't have one
    if (response.itemInfo.requiresKey && !response.itemInfo.hasKey) {
      console.log('Player needs a key to pick up this chest');
      missingKeyChestType.value = response.itemInfo.item.type;
      showMissingKeyDialog.value = true;
      
      // End turn since player can't pick up the item
      try {
        const currentTurnId = gameData.value?.state?.currentTurnId;
        if (currentTurnId) {
          await gameApi.endTurn({
            gameId: id.value,
            playerId: currentPlayerId.value,
            turnId: currentTurnId
          });
          await loadGameData();
        }
      } catch (err) {
        console.error('Failed to end turn after missing key:', err);
      }
    } else {
      // Only show the dialog if it's still the player's turn
      if (isPlayerTurn.value) {
        showItemPickupDialog.value = true;
      } else {
        console.log('Not showing item pickup dialog - turn has already changed');
      }
    }
  } else if (!response?.battleInfo && !response?.tilePicked && !response?.tilePlaced) {
    // No battle, no item, no tile picked/placed - just a regular move
    // Players can move up to 4 times per turn, so don't end turn automatically
    console.log('Regular move completed, turn continues');
    // Just reload game data to update the UI with new positions
    await loadGameData();
  }
};

// Add a function to cancel tile placement
const cancelTilePlacement = () => {
  ghostTilePosition.value = null;
  pickedTileId.value = null;
  pickedTile.value = null;
  isPlacingTile.value = false;
  ghostTileOrientation.value = null;
};


// Use the imported isValidOrientation function with gameData.value and currentPlayerId.value
const isValidOrientationLocal = (position, orientation) => {
  return isValidOrientation(position, orientation, gameData.value, currentPlayerId.value);
};

// Use the imported getRequiredOpenSide function with gameData.value and currentPlayerId.value
const getRequiredOpenSideLocal = (position) => {
  return getRequiredOpenSide(position, gameData.value, currentPlayerId.value);
};

// Use the imported rotateGhostTile function with necessary parameters
const rotateGhostTileLocal = async () => {
  if (!pickedTileId.value || !pickedTile.value || !isPlacingTile.value || !ghostTilePosition.value) return;

  // Ensure we're using the correct player ID from the game state
  if (gameData.value?.state?.currentPlayerId !== currentPlayerId.value) {
    console.error('Player ID mismatch in rotateGhostTile: UI player does not match game state player');
    console.log('UI player:', currentPlayerId.value);
    console.log('Game state player:', gameData.value?.state?.currentPlayerId);

    // Force a player switch to ensure we're using the correct player
    autoSwitchPlayer();

    // If after switching the player IDs still don't match, don't proceed
    if (gameData.value?.state?.currentPlayerId !== currentPlayerId.value) {
      console.error('Failed to align player IDs, aborting rotation');
      return;
    }
  }

  // Get current turn ID from game state
  const currentTurnId = gameData.value?.state?.currentTurnId;
  if (!currentTurnId) {
    console.error('No current turn ID found in game state');
    return;
  }

  await rotateGhostTile({
    ghostTilePosition: ghostTilePosition.value,
    pickedTileId: pickedTileId.value,
    pickedTile: pickedTile.value,
    gameData: gameData.value,
    currentPlayerId: currentPlayerId.value,
    gameId: id.value,
    currentTurnId,
    rotateTileApi: gameApi.rotateTile,
    onSuccess: (tile) => {
      // Update the tile orientation
      pickedTile.value = tile;
      const newOrientation = parseOrientationString(tile.orientation);
      const newSymbol = getTileOrientationSymbol(newOrientation, tile.room);
      console.log('Rotation successful - updating orientation:', {
        oldOrientation: ghostTileOrientation.value,
        newOrientation,
        tileOrientation: tile.orientation,
        isRoom: tile.room,
        newSymbol
      });
      ghostTileOrientation.value = newOrientation;
    },
    onError: (err) => {
      console.error('Failed to rotate tile:', err);
      error.value = `Failed to rotate tile: ${err.message}`;
    }
  });
};

const handleInitialTileOrientationLocal = async (position) => {
  if (!pickedTile.value || !pickedTileId.value) return false;

  // Ensure we're using the correct player ID from the game state
  if (gameData.value?.state?.currentPlayerId !== currentPlayerId.value) {
    console.error('Player ID mismatch in handleInitialTileOrientation: UI player does not match game state player');
    console.log('UI player:', currentPlayerId.value);
    console.log('Game state player:', gameData.value?.state?.currentPlayerId);

    // Force a player switch to ensure we're using the correct player
    autoSwitchPlayer();

    // If after switching the player IDs still don't match, don't proceed
    if (gameData.value?.state?.currentPlayerId !== currentPlayerId.value) {
      console.error('Failed to align player IDs, aborting initial tile orientation');
      return false;
    }
  }

  // Get current turn ID from game state
  const currentTurnId = gameData.value?.state?.currentTurnId;
  if (!currentTurnId) {
    console.error('No current turn ID found in game state');
    return false;
  }

  // Set initial orientation
  ghostTileOrientation.value = parseOrientationString(pickedTile.value.orientation);

  return await handleInitialTileOrientation({
    position,
    pickedTile: pickedTile.value,
    pickedTileId: pickedTileId.value,
    gameData: gameData.value,
    currentPlayerId: currentPlayerId.value,
    gameId: id.value,
    currentTurnId,
    rotateTileApi: gameApi.rotateTile,
    onSuccess: (tile) => {
      pickedTile.value = tile;
      const newOrientation = parseOrientationString(tile.orientation);
      console.log('Initial orientation found - updating:', {
        oldOrientation: ghostTileOrientation.value,
        newOrientation,
        tileOrientation: tile.orientation
      });
      ghostTileOrientation.value = newOrientation;
    },
    onError: (err) => {
      console.error('Failed to rotate tile:', err);
    }
  });
};

// Function to handle keyboard controls for ghost tile
const handleKeyboardControls = (e) => {
  // Only handle keyboard if it's player's turn
  if (!isPlayerTurn.value) return;

  // If we have a picked tile, handle its movement
  if (pickedTileId.value && pickedTile.value && isPlacingTile.value) {
    if (e.key === 'r') {
      rotateGhostTileLocal();
      e.preventDefault();
    } else if (e.key === 'Enter') {
      // Place the tile at current ghost position
      if (ghostTilePosition.value) {
        handlePlaceClick(ghostTilePosition.value);
        e.preventDefault();
      }
      return;
    }
    return;
  }

  // Handle arrow keys for ghost tile placement
  const availablePlaces = getProcessedAvailablePlaces(gameData.value, 'placeTile');
  const availableMoves = getProcessedAvailablePlaces(gameData.value, 'moveTo');

  // Combine all valid positions (both empty places and already placed tiles)
  const allValidPositions = [...new Set([...availablePlaces.map(p => `${p.x},${p.y}`), ...availableMoves.map(p => `${p.x},${p.y}`)])];

  if (!allValidPositions.length) return;

  // Get current ghost position or player position as reference
  let currentPos;
  if (ghostTilePosition.value) {
    currentPos = ghostTilePosition.value;
  } else if (gameData.value?.field?.playerPositions?.[currentPlayerId.value]) {
    currentPos = gameData.value.field.playerPositions[currentPlayerId.value];
  } else {
    return;
  }

  const [currentX, currentY] = currentPos.split(',').map(Number);
  let targetPos;

  switch (e.key) {
    case 'ArrowUp':
      targetPos = `${currentX},${currentY - 1}`;
      e.preventDefault();
      break;
    case 'ArrowRight':
      targetPos = `${currentX + 1},${currentY}`;
      e.preventDefault();
      break;
    case 'ArrowDown':
      targetPos = `${currentX},${currentY + 1}`;
      e.preventDefault();
      break;
    case 'ArrowLeft':
      targetPos = `${currentX - 1},${currentY}`;
      e.preventDefault();
      break;
    case 'Enter':
      // If no ghost tile but we're on a valid place, pick a new tile or move
      if (!pickedTileId.value && allValidPositions.includes(currentPos)) {
        handlePlaceClick(currentPos);
        e.preventDefault();
      }
      break;
    default:
      return;
  }

  // Check if the target position is a valid move or place for a new tile
  if (targetPos && allValidPositions.includes(targetPos)) {
    // If we don't have a picked tile yet, simulate click on the position
    if (!pickedTileId.value) {
      handlePlaceClick(targetPos);
    } else if (availablePlaces.some(place => `${place.x},${place.y}` === targetPos)) {
      // If we already have a picked tile, just move the ghost (only to empty places)
      ghostTilePosition.value = targetPos;
    }
  }
};

// Add keyboard event listener setup in onMounted
onMounted(() => {
  loadGameData();
  window.addEventListener('resize', handleResize);

  // Add global event listeners for drag navigation
  window.addEventListener('mouseup', onMouseUp);
  window.addEventListener('mousemove', onMouseMove);

  // Add keyboard event listeners for CTRL key detection
  window.addEventListener('keydown', onKeyDown);
  window.addEventListener('keyup', onKeyUp);

  // Add keyboard listener for combined keyboard events
  window.addEventListener('keydown', handleKeyboardEvents);
});

// Update cleanup in onUnmounted
onUnmounted(() => {
  window.removeEventListener('resize', handleResize);

  // Remove global event listeners for drag navigation
  window.removeEventListener('mouseup', onMouseUp);
  window.removeEventListener('mousemove', onMouseMove);

  // Remove keyboard event listeners for CTRL key detection
  window.removeEventListener('keydown', onKeyDown);
  window.removeEventListener('keyup', onKeyUp);

  // Remove keyboard listener for combined keyboard events
  window.removeEventListener('keydown', handleKeyboardEvents);

  // Clear the polling interval
  if (gamePollingInterval.value) {
    clearInterval(gamePollingInterval.value);
    gamePollingInterval.value = null;
  }
});

// Use the imported getTileOrientationAt function with gameData.value
const getTileOrientationAtLocal = (position) => {
  return getTileOrientationAt(position, gameData.value);
};

// Function to switch between the current player and second player
const switchPlayer = () => {
  switchPlayerUtil({ 
    currentPlayerId, 
    secondPlayerId, 
    loadGameData 
  });
};

// Function to automatically switch to the current player based on game state
const autoSwitchPlayer = () => {
  // Use our enhanced checkStunnedPlayersAndSwitch function
  checkStunnedPlayersAndSwitch();
  
  // Show notification if player switching happened
  showPlayerSwitchNotification();
};

// Watch for changes in game data to ensure player IDs are always in sync
watch(() => gameData.value?.state?.currentPlayerId, (newCurrentPlayerId) => {
  if (newCurrentPlayerId && newCurrentPlayerId !== currentPlayerId.value) {
    console.log('Game state current player changed, syncing...');
    
    // Dismiss any open dialogs when player changes
    if (showItemPickupDialog.value) {
      console.log('Dismissing item pickup dialog due to player change');
      dismissItemPickupDialog();
    }
    
    autoSwitchPlayer();
  }
}, { immediate: true });

// Watch for turn changes to dismiss dialogs
watch(() => gameData.value?.state?.currentTurnId, (newTurnId, oldTurnId) => {
  if (newTurnId !== oldTurnId && oldTurnId !== undefined) {
    // Turn has changed, dismiss any open dialogs
    if (showItemPickupDialog.value) {
      console.log('Dismissing item pickup dialog due to turn change');
      dismissItemPickupDialog();
    }
    
    // Reset AI turn flag when turn changes - this ensures AI can play again
    // even if multiple AI turns happen in quick succession
    if (aiTurnInProgress) {
      console.log('Turn changed, resetting AI turn flag');
      aiTurnInProgress = false;
    }
  }
});

// Watch for changes in game status - center on player when game starts
watch(() => gameData.value?.state?.status, (newStatus, oldStatus) => {
  if (newStatus === 'started') {
    // When game starts, center on current player
    gameStarted.value = true;
    nextTick(() => {
      centerViewOnCurrentPlayer();
    });
  } else if (newStatus === 'finished') {
    // Game is finished, show the leaderboard
    console.log('Game finished, showing leaderboard');

    // Extract scores from players' treasures
    const entries = gameData.value.players.map(player => {
      const treasures = player.inventory?.treasures || [];
      const treasureValue = treasures.reduce((sum, treasure) => sum + (treasure.treasureValue || 0), 0);
      return {
        playerId: player.id,
        externalId: player.externalId || null,
        treasure: treasureValue
      };
    });

    // Sort entries by treasure value (highest first)
    entries.sort((a, b) => b.treasure - a.treasure);

    // Set the leaderboard data
    leaderboard.value = entries;

    // Get the winner (player with most treasure)
    winnerId.value = entries.length > 0 ? entries[0].playerId : null;
    
    // Also store the winner's externalId for authenticated user comparison
    const winnerExternalId = entries.length > 0 ? entries[0].externalId : null;
    console.log('Winner determined:', { winnerId: winnerId.value, winnerExternalId });

    // Close battle modal if it's still open before showing leaderboard
    if (showBattleReportModal.value) {
      console.log('Closing battle modal before showing leaderboard');
      showBattleReportModal.value = false;
      
      // Add a small delay to ensure DOM updates
      setTimeout(() => {
        showLeaderboardModal.value = true;
      }, 100);
    } else {
      // Show the leaderboard modal immediately if battle modal is not open
      showLeaderboardModal.value = true;
    }
  }
}, { immediate: true });

// Function to dismiss notification
const dismissNotification = () => {
  dismissNotificationUtil({ 
    playerSwitched, 
    notificationTimeout 
  });
};

// Function to show player switch notification
const showPlayerSwitchNotification = () => {
  showPlayerSwitchNotificationUtil({ 
    playerSwitched, 
    notificationTimeout 
  });
};

// Function to show healing notification
const showHealingNotificationForPlayer = (playerId, healAmount) => {
  healingNotification.value = {
    playerId: playerId,
    playerName: formatPlayerId(playerId),
    healAmount: healAmount
  };
  showHealingNotification.value = true;
  
  // Auto-dismiss after 3 seconds
  setTimeout(() => {
    dismissHealingNotification();
  }, 3000);
};

// Function to dismiss healing notification
const dismissHealingNotification = () => {
  showHealingNotification.value = false;
  healingNotification.value = null;
};

// We're using the imported formatPlayerId function directly

// Get current player data
const getCurrentPlayerData = computed(() => {
  if (!gameData.value || !gameData.value.players || !currentPlayerId.value) return null;
  return gameData.value.players.find(player => player.id === currentPlayerId.value);
});

// Get second player data
const getSecondPlayerData = computed(() => {
  if (!gameData.value || !gameData.value.players || !secondPlayerId.value) return null;
  return gameData.value.players.find(player => player.id === secondPlayerId.value);
});

// Get current player's field position
const currentPlayerFieldPosition = computed(() => {
  if (!gameData.value || !gameData.value.field || !currentPlayerId.value) return null;
  
  // Find the current player's position from the field data
  const playerPositions = gameData.value.field.playerPositions || {};
  for (const [position, playerIds] of Object.entries(playerPositions)) {
    if (playerIds.includes(currentPlayerId.value)) {
      const [x, y] = position.split(',').map(n => parseInt(n));
      return { x, y };
    }
  }
  
  return null;
});

// Check if player has inventory space for the battle reward
const hasInventorySpaceForReward = computed(() => {
  if (!battleInfo.value || !battleInfo.value.reward || !getCurrentPlayerData.value) return true;

  const reward = battleInfo.value.reward;
  const inventory = getCurrentPlayerData.value.inventory;

  if (!reward.type || !inventory) return true;

  // Define inventory limits for each category
  const inventoryLimits = {
    'key': 1,
    'dagger': 2,
    'sword': 2, 
    'axe': 2,
    'fireball': 3,
    'teleport': 3,
    'chest': Infinity, // No limit for treasures
    'ruby_chest': Infinity
  };

  // Map item types to inventory categories
  const categoryMap = {
    'key': 'keys',
    'dagger': 'weapons',
    'sword': 'weapons',
    'axe': 'weapons', 
    'fireball': 'spells',
    'teleport': 'spells',
    'chest': 'treasures',
    'ruby_chest': 'treasures'
  };

  const category = categoryMap[reward.type];
  const limit = inventoryLimits[reward.type];

  if (!category || limit === undefined) return true; // Unknown type, assume space available

  const currentCount = inventory[category] ? inventory[category].length : 0;
  return currentCount < limit;
});

// Function to select inventory item
const selectInventoryItem = (item) => {
  selectInventoryItemUtil(item, {
    onSpellSelected: async (spell) => {
      if (spell.type === 'teleport' && isPlayerTurn.value) {
        await handleTeleportSpellSelection(spell);
      }
    }
  });
};

// Function to select an item to replace when inventory is full
const selectItemToReplace = (item) => {
  selectItemToReplaceUtil({ 
    selectedItemToReplace, 
    item 
  });
};

// Function to replace an item in the inventory
const replaceItem = async () => {
  // Get current turn ID to pass to replaceItem
  const currentTurnId = gameData.value?.state?.currentTurnId;
  
  await replaceItemUtil({
    selectedItemToReplace,
    droppedItem,
    loading,
    loadingStatus,
    showInventoryFullDialog,
    error,
    gameApi,
    gameId: id.value,
    playerId: currentPlayerId.value,
    turnId: currentTurnId,
    loadGameData
  });
};

// Function to skip picking up an item
const skipItem = async () => {
  // Get current turn ID to pass to skipItem
  const currentTurnId = gameData.value?.state?.currentTurnId;
  
  await skipItemUtil({
    droppedItem,
    loading,
    loadingStatus,
    showInventoryFullDialog,
    error,
    gameApi,
    gameId: id.value,
    playerId: currentPlayerId.value,
    turnId: currentTurnId,
    loadGameData,
    isAfterBattle: isInventoryFullAfterBattle.value
  });
  
  // Reset the flag after use
  isInventoryFullAfterBattle.value = false;
};

// Function to handle inventory full response
const handleInventoryFullResponse = (response) => {
  handleInventoryFullResponseUtil({
    response,
    droppedItem,
    itemCategory,
    maxItemsInCategory,
    inventoryForCategory,
    showInventoryFullDialog,
    selectedItemToReplace,
    getCurrentPlayerData
  });
};

// Function to close battle report and end turn
const closeBattleReportAndEndTurn = async () => {
  try {
    // Reset request lock when battle ends
    resetRequestLock();

    // Check if player lost the battle - if so, we should update UI immediately
    const isPlayerDefeated = battleInfo.value && battleInfo.value.result === 'loose';
    if (isPlayerDefeated) {
      // Immediately update the UI to show reduced HP
      if (getCurrentPlayerData.value && getCurrentPlayerData.value.hp > 0) {
        getCurrentPlayerData.value.hp -= 1;
      }
      console.log('Battle lost - HP reduced to:', getCurrentPlayerData.value?.hp);
    }

    // Close the battle report modal first
    showBattleReportModal.value = false;
    
    // Get current turn ID
    const currentTurnId = gameData.value?.state?.currentTurnId;
    if (!currentTurnId) {
      console.error('No current turn ID found in game state');
      return;
    }
    
    // The backend automatically ends the turn only for LOSE/DRAW battle results
    // For WIN results, the turn ending is handled by the finalize-battle call
    loading.value = true;
    loadingStatus.value = 'Updating game state...';
    
    console.log('Battle completed - refreshing game state (turn was automatically ended by backend)');
    
    // Refresh game data to get the updated turn state
    const updatedGameState = await gameApi.getGame(id.value);
    doUpdateGameDataSelectively(updatedGameState);
    
    // If the turn advanced to a different player, update player IDs
    if (updatedGameState.state.currentPlayerId !== currentPlayerId.value) {
      console.log('Turn has advanced to next player:', updatedGameState.state.currentPlayerId);
      // If it's our second player, switch to them
      if (secondPlayerId.value === updatedGameState.state.currentPlayerId) {
        switchPlayer({
          currentPlayerId,
          secondPlayerId,
          loadGameData
        });
      } else {
        // Otherwise just update our current player ID
        currentPlayerId.value = updatedGameState.state.currentPlayerId;
        localStorage.setItem('currentPlayerId', currentPlayerId.value);
      }
    }
    
    // Clear battle info
    battleInfo.value = null;
    
    // Check if the next player is an AI player and trigger their turn
    // This is important for stunned players whose turn auto-ends
    await checkAndHandleVirtualPlayerTurn();
    
  } catch (err) {
    console.error('Failed to close battle report:', err);
    error.value = err.message || 'Failed to close battle report';
    // Still reset the lock even if there's an error
    resetRequestLock();
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Function to handle keyboard events, including Escape to close battle report
const handleKeyboardEvents = (e) => {
  handleKeyboardEventsUtil({
    e,
    isPlayerTurn,
    showBattleReportModal,
    closeBattleReportAndEndTurn,
    pickedTileId,
    pickedTile,
    isPlacingTile,
    rotateGhostTileLocal,
    ghostTilePosition,
    handlePlaceClick,
    getProcessedAvailablePlaces,
    gameData,
    currentPlayerId
  });
};

// Helper function to scroll to a specific position
const scrollToPosition = (x, y) => {
  scrollToPositionUtil(x, y, gameData.value, tileSize.value);
};

// Add a new function to handle item clicks from GameTile
const handleItemClick = async (itemData) => {
  try {
    // Check if player is on the same position as the item
    const playerPosition = gameData.value?.field?.playerPositions?.[currentPlayerId.value];
    if (playerPosition !== itemData.position) {
      console.log('Player is not on the same position as the item');
      return;
    }

    // Check if the item has an undefeated guard
    if (itemData.item && !itemData.item.guardDefeated && itemData.item.guardHP > 0) {
      console.log('Item has an undefeated guard, defeat the guard first');
      return;
    }

    // When player clicks directly on an item, show the pickup dialog
    // This handles the case where player is already on the tile
    // Only show the dialog if it's still the player's turn
    if (isPlayerTurn.value) {
      tileItem.value = itemData.item;
      itemPickupTurnId.value = gameData.value?.state?.currentTurnId;
      showItemPickupDialog.value = true;
    } else {
      console.log('Not showing item pickup dialog - not player turn');
    }
    return;

    // The original code below will only run if we don't show the dialog
    await handleItemClickUtil({
      itemData,
      isPlayerTurn,
      gameData,
      currentPlayerId,
      loading,
      loadingStatus,
      error,
      gameApi,
      gameId: id.value,
      showInventoryFullDialog,
      droppedItem,
      itemCategory,
      maxItemsInCategory,
      inventoryForCategory,
      loadGameData
    });
  } catch (err) {
    // Handle missing key error specifically
    if (err.message && err.message.startsWith('MISSING_KEY:')) {
      const chestType = err.message.split(':')[1];
      missingKeyChestType.value = chestType;
      showMissingKeyDialog.value = true;
    } else {
      // Re-throw other errors to be handled normally
      throw err;
    }
  }
};

// Function to handle picking up item and ending turn
const handlePickItemAndEndTurn = async () => {
  try {
    const currentTurnId = gameData.value?.state?.currentTurnId;
    
    // Determine the position where the item is located
    let itemPosition;
    
    // If this is a battle reward, use the battle position
    if (battleInfo.value && battleInfo.value.result === 'win' && battleInfo.value.position) {
      itemPosition = battleInfo.value.position;
      console.log('Using battle position for item pickup:', itemPosition);
    } else {
      // Otherwise, use the player's current position
      itemPosition = gameData.value?.field?.playerPositions?.[currentPlayerId.value];
    }

    if (!currentTurnId || !itemPosition) {
      console.error('Missing turn ID or item position');
      closeBattleReportAndEndTurn();
      return;
    }

    loading.value = true;
    loadingStatus.value = 'Picking up item...';

    // Try to pick up the item
    const response = await gameApi.pickItem({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: currentTurnId,
      position: itemPosition
    });

    // Check if inventory is full
    if (response.inventoryFull && battleReportModalRef.value) {
      // Special case for keys: all keys are the same, so auto-replace without asking
      if (response.itemCategory === 'key' || response.itemCategory === 'keys') {
        console.log('Auto-replacing key since all keys are functionally the same');

        // Get the first key from current inventory to replace
        const currentKeys = response.currentInventory || [];
        if (currentKeys.length > 0) {
          const keyToReplace = currentKeys[0].itemId;

          // Automatically replace the existing key
          await gameApi.pickItem({
            gameId: id.value,
            playerId: currentPlayerId.value,
            turnId: currentTurnId,
            position: itemPosition,
            itemIdToReplace: keyToReplace
          });

          // Item replaced successfully, now end the turn
          console.log('Key replaced successfully, ending turn...');
          
          // End the turn
          await gameApi.endTurn({
            gameId: id.value,
            playerId: currentPlayerId.value,
            turnId: currentTurnId
          });
          
          // Refresh game data
          await loadGameData();
          showBattleReportModal.value = false;
          battleInfo.value = null;
          resetRequestLock();
        } else {
          // Fallback: show normal inventory full dialog if no keys found
          isInventoryFullAfterBattle.value = true;
          showInventoryFullDialog.value = true;
          droppedItem.value = response.item;
          itemCategory.value = response.itemCategory;
          maxItemsInCategory.value = response.maxItemsInCategory;
          inventoryForCategory.value = response.currentInventory || [];
          
          // Close the battle modal since we're showing the inventory dialog
          showBattleReportModal.value = false;
          battleInfo.value = null;
        }
      } else {
        // Set up inventory full dialog state for non-key items after battle
        isInventoryFullAfterBattle.value = true;
        showInventoryFullDialog.value = true;
        droppedItem.value = response.item;
        itemCategory.value = response.itemCategory;
        maxItemsInCategory.value = response.maxItemsInCategory;
        inventoryForCategory.value = response.currentInventory || [];
        
        // Close the battle modal since we're showing the inventory dialog
        showBattleReportModal.value = false;
        battleInfo.value = null;
      }
    } else {
      // Item picked up successfully, now end the turn
      console.log('Item picked up successfully, ending turn...');
      
      // End the turn
      await gameApi.endTurn({
        gameId: id.value,
        playerId: currentPlayerId.value,
        turnId: currentTurnId
      });
      
      // Refresh game data
      await loadGameData();
      
      // Check if the next player is an AI player and trigger their turn
      await checkAndHandleVirtualPlayerTurn();
      
      showBattleReportModal.value = false;
      battleInfo.value = null;
      resetRequestLock();
    }
  } catch (err) {
    console.error('Failed to pick up item:', err);
    error.value = err.message || 'Failed to pick up item';
    closeBattleReportAndEndTurn();
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Function to handle picking up item with replacement
const handlePickItemWithReplacement = async (itemIdToReplace) => {
  try {
    const currentTurnId = gameData.value?.state?.currentTurnId;
    const playerPosition = gameData.value?.field?.playerPositions?.[currentPlayerId.value];

    if (!currentTurnId || !playerPosition) {
      console.error('Missing turn ID or player position');
      closeBattleReportAndEndTurn();
      return;
    }

    loading.value = true;
    loadingStatus.value = 'Replacing item...';

    // Pick up the item with replacement
    await gameApi.pickItem({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: currentTurnId,
      position: playerPosition,
      itemIdToReplace: itemIdToReplace
    });

    // Item replaced successfully, now end the turn
    console.log('Item replaced successfully, ending turn...');
    
    // End the turn
    await gameApi.endTurn({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: currentTurnId
    });
    
    // Refresh game data
    await loadGameData();
    
    // Check if the next player is an AI player and trigger their turn
    await checkAndHandleVirtualPlayerTurn();
    
    showBattleReportModal.value = false;
    battleInfo.value = null;
    resetRequestLock();
  } catch (err) {
    console.error('Failed to replace item:', err);
    error.value = err.message || 'Failed to replace item';
    closeBattleReportAndEndTurn();
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Function to handle finalize battle and pick up
const handleFinalizeBattleAndPickUp = async (finalizeBattleData) => {
  // Hide modal immediately if requested (to prevent showing intermediate battle state)
  if (finalizeBattleData.hideModalImmediately) {
    showBattleReportModal.value = false;
  }
  
  try {
    const currentTurnId = gameData.value?.state?.currentTurnId;
    const playerPosition = gameData.value?.field?.playerPositions?.[currentPlayerId.value];

    if (!currentTurnId || !playerPosition) {
      console.error('Missing turn ID or player position');
      closeBattleReportAndEndTurn();
      return;
    }

    loading.value = true;
    loadingStatus.value = 'Finalizing battle and picking up item...';

    // If there's no selected item to replace, check if inventory space is available
    if (!finalizeBattleData.replaceItemId && !hasInventorySpaceForReward.value) {
      // Special case for keys: all keys are the same, so auto-replace without asking
      if (battleInfo.value && battleInfo.value.reward && 
          (battleInfo.value.reward.type === 'key' || battleInfo.value.reward.name === 'key')) {
        console.log('Auto-replacing key since all keys are functionally the same');

        // Get the first key from current inventory to replace
        const currentKeys = getCurrentPlayerData.value?.inventory?.keys || [];
        if (currentKeys.length > 0) {
          // Update finalizeBattleData with the key to replace
          finalizeBattleData.replaceItemId = currentKeys[0].itemId;
        }
      } else {
        // Show inventory selection for non-key items
        loading.value = false;

        // Check if the battle report modal reference exists
        if (battleReportModalRef.value) {
          // Get current inventory for the specific category based on reward type
          const rewardType = battleInfo.value?.reward?.type;
          let inventoryCategory = 'treasures';

          if (['dagger', 'sword', 'axe'].includes(rewardType)) {
            inventoryCategory = 'weapons';
          } else if (['fireball', 'teleport'].includes(rewardType)) {
            inventoryCategory = 'spells';
          } else if (rewardType === 'key') {
            inventoryCategory = 'keys';
          }

          const inventoryForCategory = getCurrentPlayerData.value?.inventory?.[inventoryCategory] || [];

          // Show inventory selection UI within the battle report modal
          battleReportModalRef.value.showFinalizeBattleInventoryFullSelection(inventoryForCategory);
        } else {
          console.error('Cannot show inventory selection, battle report modal ref is missing');
          error.value = 'Cannot show inventory selection';
          closeBattleReportAndEndTurn();
        }
        return;
      }
    }

    // Use the enhanced finalizeBattle endpoint that now handles item pickup in a single request
    const response = await gameApi.finalizeBattle({
      battleId: finalizeBattleData.battleId,
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: currentTurnId,
      selectedConsumableIds: finalizeBattleData.selectedConsumableIds,
      pickupItem: true,
      replaceItemId: finalizeBattleData.replaceItemId // Pass the item to replace if inventory is full
    });

    // Check if the backend responded with itemPickedUp flag
    if (response && response.itemPickedUp === false) {
      console.log('Item was not picked up automatically, may need additional handling');
      // No additional handling needed since the turn was already processed
    }

    // Check if the picked up item ends the game (dragon's ruby chest)
    const itemEndsGame = battleInfo.value?.reward?.endsGame === true;
    if (itemEndsGame) {
      console.log('Picked up item that ends the game (dragon reward)');
      // Add a small delay to ensure backend has processed the game end
      await new Promise(resolve => setTimeout(resolve, 500));
    }

    // Refresh game data
    await loadGameData();

    // If the game should have ended but hasn't yet, try loading again
    if (itemEndsGame && gameData.value?.state?.status !== 'finished') {
      console.log('Game should have ended, waiting and trying again...');
      await new Promise(resolve => setTimeout(resolve, 1000));
      await loadGameData();
    }

    // Close the modal
    showBattleReportModal.value = false;
    battleInfo.value = null;
    
    // Check if the next player is an AI player and trigger their turn
    await checkAndHandleVirtualPlayerTurn();

    // The game status watcher should automatically show the leaderboard if the game ended
    resetRequestLock();
  } catch (err) {
    console.error('Failed to finalize battle and pick up:', err);
    error.value = err.message || 'Failed to finalize battle and pick up item';
    closeBattleReportAndEndTurn();
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Function to handle finalize battle
const handleFinalizeBattle = async (finalizeBattleData) => {
  // Hide modal immediately if requested (to prevent showing intermediate battle state)
  if (finalizeBattleData.hideModalImmediately) {
    showBattleReportModal.value = false;
  }
  
  try {
    const currentTurnId = gameData.value?.state?.currentTurnId;

    if (!currentTurnId) {
      console.error('Missing turn ID for battle finalization');
      closeBattleReportAndEndTurn();
      return;
    }

    loading.value = true;
    loadingStatus.value = 'Finalizing battle...';

    // Check predicted battle result based on damage vs monster HP
    const monster = battleInfo.value?.monster;
    const totalDamage = battleInfo.value?.totalDamage || 0;
    const predictedResult = monster && totalDamage < monster.hp ? 'loose' : 
                          (monster && totalDamage === monster.hp ? 'draw' : 'win');

    // If we predict a loss, immediately update the UI to show reduced HP
    if (predictedResult === 'loose') {
      if (getCurrentPlayerData.value && getCurrentPlayerData.value.hp > 0) {
        getCurrentPlayerData.value.hp -= 1;
      }
      console.log('Battle likely lost - HP preemptively reduced to:', getCurrentPlayerData.value?.hp);
    }

    // Call the finalize battle API
    await gameApi.finalizeBattle({
      battleId: finalizeBattleData.battleId,
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: currentTurnId,
      selectedConsumableIds: finalizeBattleData.selectedConsumableIds,
      pickupItem: false // Explicitly set to false to ensure item is not picked up
    });
    
    // The backend only automatically ends the turn for LOSE/DRAW results
    // For WIN results where the player doesn't pick up the item, we need to manually end the turn
    if (predictedResult === 'win') {
      console.log('Player won but chose not to pick up item - ending turn manually');
      await gameApi.endTurn({
        gameId: id.value,
        playerId: currentPlayerId.value,
        turnId: currentTurnId
      });
    } else {
      console.log('Battle finalized - turn was automatically ended by backend (LOSE/DRAW)');
    }

    // Close the modal immediately since finalize-battle already ends the turn on the backend
    showBattleReportModal.value = false;
    battleInfo.value = null;

    // Refresh game data after closing the modal to get the updated game state
    await loadGameData();
    
    // Check if the next player is an AI player and trigger their turn
    await checkAndHandleVirtualPlayerTurn();

    resetRequestLock();
  } catch (err) {
    console.error('Failed to finalize battle:', err);
    error.value = err.message || 'Failed to finalize battle';
    closeBattleReportAndEndTurn();
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Check if all players are ready (used to hide player setup section)
const allPlayersReady = computed(() => {
  // If game is started or in progress, consider all players ready
  if (gameStarted.value || gameData.value?.state?.status === 'started' || gameData.value?.state?.status === 'turn_in_progress') {
    return true;
  }

  // For setup phase, check if we have the minimum required players and they're ready
  const hasMinimumPlayers = gameData.value?.players?.length >= 2;
  const currentPlayerReady = playerIsReady.value;
  const secondPlayerExists = isSecondPlayerInGame.value;

  // All players are ready if:
  // 1. We have minimum players (2)
  // 2. Current player is ready
  // 3. Second player exists (which implies they're ready since adding them automatically sets them ready)
  return hasMinimumPlayers && currentPlayerReady && secondPlayerExists;
});

// Note: checkForPickableItem function has been removed
// Item pickup is now handled through the move-player API response

// Function to handle automatic item pickup from dialog
const handleAutoItemPickup = async () => {
  if (!tileItem.value) return;

  try {
    loading.value = true;
    loadingStatus.value = 'Picking up item...';

    // Determine the position where the item is located
    let itemPosition;
    
    // If this is a battle reward, use the battle position
    if (battleInfo.value && battleInfo.value.result === 'win' && battleInfo.value.position) {
      itemPosition = battleInfo.value.position;
      console.log('Using battle position for item pickup:', itemPosition);
    } else {
      // Otherwise, use the player's current position
      itemPosition = gameData.value?.field?.playerPositions?.[currentPlayerId.value];
      if (!itemPosition) {
        throw new Error('Player has no position');
      }
    }

    // Use the turn ID that was stored when the dialog was shown
    const turnId = itemPickupTurnId.value;
    if (!turnId) {
      throw new Error('No turn ID found - dialog was shown without proper turn context');
    }
    
    console.log('Attempting to pick up item:');
    console.log('- Stored turnId:', turnId);
    console.log('- Current turnId in gameData:', gameData.value?.state?.currentTurnId);
    console.log('- Player ID:', currentPlayerId.value);
    console.log('- Position:', itemPosition);
    console.log('- Item:', tileItem.value);

    // Call API to pick up the item
    const response = await gameApi.pickItem({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: turnId,
      position: itemPosition
    });

    // Check if player is missing a key for a chest
    if (response.missingKey) {
      console.log('Player missing key for chest:', response.chestType);
      dismissItemPickupDialog();
      missingKeyChestType.value = response.chestType;
      showMissingKeyDialog.value = true;
      return;
    }

    // Check if inventory is full
    if (response.inventoryFull) {
      console.log('Inventory is full, showing replacement dialog');
      dismissItemPickupDialog();

      // Set up the inventory full dialog
      showInventoryFullDialog.value = true;
      droppedItem.value = response.item || tileItem.value;
      itemCategory.value = response.itemCategory;
      maxItemsInCategory.value = response.maxItemsInCategory;
      inventoryForCategory.value = response.currentInventory || [];
      selectedItemToReplace.value = null;
      
      // Store the position for later when we need to pick up the item
      if (!droppedItem.value.position) {
        droppedItem.value.position = itemPosition;
      }
      
      return;
    }

    // Dismiss the dialog immediately after successful pickup
    dismissItemPickupDialog();
    
    // Refresh game data
    await loadGameData();
    
    // Check if the turn has already changed (delayed pickup)
    const currentTurnNow = gameData.value?.state?.currentTurnId;
    if (currentTurnNow !== turnId) {
      console.log('Turn has already advanced - this was a delayed pickup, not ending turn');
    } else {
      // End turn after successfully picking up the item
      console.log('Ending turn after item pickup');
      try {
        await gameApi.endTurn({
          gameId: id.value,
          playerId: currentPlayerId.value,
          turnId: turnId
        });
        
        // Refresh game data again to get the updated turn state
        await loadGameData();
      } catch (endTurnErr) {
        console.error('Failed to end turn after item pickup:', endTurnErr);
      }
    }
  } catch (err) {
    console.error('Failed to pick up item:', err);
    error.value = `Failed to pick up item: ${err.message}`;
    // Only dismiss dialog on error if it wasn't already dismissed
    if (showItemPickupDialog.value) {
      dismissItemPickupDialog();
    }
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Function to dismiss the item pickup dialog
const dismissItemPickupDialog = () => {
  showItemPickupDialog.value = false;
  tileItem.value = null;
  itemPickupTurnId.value = null;
};

// Function to dismiss item pickup dialog and end turn when player skips item
const skipItemAndEndTurn = async () => {
  console.log('Player skipped item pickup, ending turn');
  
  // First dismiss the dialog
  dismissItemPickupDialog();
  
  // Then end the turn
  try {
    // Use the turn ID that was stored when the dialog was shown
    const turnId = itemPickupTurnId.value;
    if (!turnId) {
      console.error('No turn ID found - dialog was shown without proper turn context');
      return;
    }
    
    loading.value = true;
    loadingStatus.value = 'Ending turn...';
    
    await gameApi.endTurn({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: turnId
    });
    
    // Refresh game data to get the updated turn state
    await loadGameData();
    
    // Check if the next player is an AI player and trigger their turn
    await checkAndHandleVirtualPlayerTurn();
  } catch (err) {
    console.error('Failed to end turn after skipping item:', err);
    error.value = `Failed to end turn: ${err.message}`;
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Teleport spell handling functions
const handleTeleportSpellSelection = async (spell) => {
  console.log('handleTeleportSpellSelection called with spell:', spell);
  console.log('Spell itemId:', spell.itemId);
  console.log('Spell type:', spell.type);
  
  // Get healing fountain positions from the game data
  if (gameData.value && gameData.value.field && gameData.value.field.healingFountainPositions) {
    // Enter teleport mode
    isTeleportMode.value = true;
    selectedTeleportSpell.value = spell;
    
    console.log('Entered teleport mode with spell:', spell);
  } else {
    console.error('No healing fountain positions found in game data');
    error.value = 'No healing fountain positions available';
  }
};

const handleTeleportClick = async (position) => {
  if (!isTeleportMode.value || !selectedTeleportSpell.value) return;
  
  try {
    loading.value = true;
    loadingStatus.value = 'Teleporting...';
    
    // Convert position string to coordinates object with correct keys for backend
    const [x, y] = position.split(',').map(n => parseInt(n));
    const targetPosition = { positionX: x, positionY: y };
    
    console.log('Using teleport spell with parameters:', {
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: gameData.value.state.currentTurnId,
      spellId: selectedTeleportSpell.value.itemId,
      targetPosition
    });
    
    // Use the teleport spell
    await gameApi.useSpell(
      id.value,
      currentPlayerId.value,
      gameData.value.state.currentTurnId,
      selectedTeleportSpell.value.itemId,
      targetPosition
    );
    
    // Exit teleport mode
    isTeleportMode.value = false;
    selectedTeleportSpell.value = null;
    
    // Reload game data to see the updated position
    await loadGameData();
    
    console.log('Teleport successful');
  } catch (err) {
    console.error('Failed to use teleport spell:', err);
    error.value = `Failed to teleport: ${err.message}`;
    // Exit teleport mode on error
    isTeleportMode.value = false;
    selectedTeleportSpell.value = null;
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

const cancelTeleportMode = () => {
  isTeleportMode.value = false;
  selectedTeleportSpell.value = null;
  console.log('Teleport mode cancelled');
};

// Helper to check if a position is the current player's position
const isCurrentPlayerPosition = (position) => {
  if (!currentPlayerFieldPosition.value) return false;
  const [x, y] = position.split(',').map(n => parseInt(n));
  return currentPlayerFieldPosition.value.x === x && currentPlayerFieldPosition.value.y === y;
};

// Helper to check if a player is an AI/virtual player
const isVirtualPlayer = (playerId) => {
  const virtualPlayerId = localStorage.getItem('virtualPlayerId');
  return virtualPlayerId && playerId === virtualPlayerId;
};

// Helper to get the current user's Privy ID
const getCurrentUserPrivyId = () => {
  const storedPrivyUser = localStorage.getItem('privyUser');
  if (storedPrivyUser) {
    try {
      const privyUser = JSON.parse(storedPrivyUser);
      return privyUser.id;
    } catch (e) {
      console.error('Failed to parse stored Privy user:', e);
    }
  }
  return null;
};

// Helper to check if current user is the winner
const isCurrentUserWinner = () => {
  // First priority: Check if any entry in the leaderboard is the current user and is #1
  if (leaderboard.value?.length > 0) {
    const winner = leaderboard.value[0]; // First place
    
    // Check if this winner is the current user
    const currentPrivyId = getCurrentUserPrivyId();
    
    console.log('isCurrentUserWinner check:', {
      currentPrivyId,
      winner,
      humanPlayerId: humanPlayerId.value
    });
    
    // If we have a Privy ID and the winner has an externalId, compare them
    if (currentPrivyId && winner.externalId) {
      const isWinner = winner.externalId === currentPrivyId;
      console.log('Privy ID comparison:', {
        winnerExternalId: winner.externalId,
        currentPrivyId,
        isWinner
      });
      return isWinner;
    }
    
    // Fallback: compare playerId with humanPlayerId for non-authenticated users
    if (humanPlayerId.value && !isVirtualPlayer(humanPlayerId.value)) {
      const isWinner = winner.playerId === humanPlayerId.value;
      console.log('PlayerId fallback comparison:', {
        winnerPlayerId: winner.playerId,
        humanPlayerId: humanPlayerId.value,
        isWinner
      });
      return isWinner;
    }
  }
  
  return false;
};

// Helper to calculate total treasure value for a player
const calculatePlayerTreasure = (player) => {
  if (!player?.inventory?.treasures) return 0;
  return player.inventory.treasures.reduce((sum, treasure) => sum + (treasure.treasureValue || 0), 0);
};

// Helper to check if a leaderboard entry is the current user
const isCurrentUserEntry = (entry) => {
  const currentPrivyId = getCurrentUserPrivyId();
  
  // If we have a Privy ID, use that for comparison
  if (currentPrivyId && entry.externalId) {
    return entry.externalId === currentPrivyId;
  }
  
  // Fallback to humanPlayerId for non-authenticated users
  if (humanPlayerId.value && !isVirtualPlayer(humanPlayerId.value)) {
    return entry.playerId === humanPlayerId.value;
  }
  
  return false;
};

// Navigation functions for end game
const navigateToLobby = () => {
  router.push('/');
};

const reloadPage = () => {
  // Close the modal to view the final game board
  showLeaderboardModal.value = false;
};

// Function to handle manual end turn by player
const handleManualEndTurn = async () => {
  try {
    const currentTurnId = gameData.value?.state?.currentTurnId;
    if (!currentTurnId) {
      console.error('No current turn ID found');
      return;
    }
    
    // Double-check it's the player's turn
    if (!isPlayerTurn.value) {
      console.error('Not the player\'s turn');
      return;
    }
    
    loading.value = true;
    loadingStatus.value = 'Ending turn...';
    
    await gameApi.endTurn({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: currentTurnId
    });
    
    // Refresh game data to get the updated turn state
    await loadGameData();
    
    console.log('Turn ended manually by player');
    
    // Check if the next player is an AI player and trigger their turn
    await checkAndHandleVirtualPlayerTurn();
  } catch (err) {
    console.error('Failed to end turn:', err);
    error.value = `Failed to end turn: ${err.message}`;
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Note: We no longer need to watch for position changes to check for items
// This is now handled directly in the move response from the API

// Add this after the script setup line
onMounted(() => {
  // Existing mounted code...

  // Add watcher for debugging deck and place tile issues
  watch(() => gameData.value?.state?.availablePlaces?.placeTile, (newValue) => {
    if (newValue) {
      console.log('Available placeTile locations:', newValue);
      console.log('Deck state:', gameData.value?.state?.deck);
    }
  });

  // Enhanced watch function for debugging
  watch(() => gameData.value?.state?.availablePlaces, (newValue) => {
    if (newValue) {
      console.log('Available places update:', {
        moveTo: newValue.moveTo?.length || 0,
        placeTile: newValue.placeTile?.length || 0,
        moveToPaths: newValue.moveTo,
        placeTilePaths: newValue.placeTile,
        deckState: gameData.value?.state?.deck
      });

      // Check if there are moveTo places but no placeTile places despite having remaining tiles
      if (newValue.moveTo?.length > 0 && 
          newValue.placeTile?.length === 0 && 
          gameData.value?.state?.deck?.remainingTiles > 0) {
        console.warn('Possible issue: There are moveTo places but no placeTile places despite having remaining tiles');
      }
    }
  }, { deep: true });
});

const showLeaderboardModal = ref(false);
const leaderboard = ref([]);
const winnerId = ref(null);

// Listen for GameEnded event from backend (assuming websocket or polling updates gameData)
function handleGameEnded(event) {
  if (!event || !event.scores) return;
  // Convert scores object to sorted array
  // Map scores and add externalId from player data
  const entries = Object.entries(event.scores).map(([playerId, treasure]) => {
    const player = gameData.value?.players?.find(p => p.id === playerId);
    return { 
      playerId, 
      treasure,
      externalId: player?.externalId || null
    };
  });
  entries.sort((a, b) => b.treasure - a.treasure);
  leaderboard.value = entries;
  winnerId.value = event.winnerId;
  
  // Close battle modal if it's still open before showing leaderboard
  if (showBattleReportModal.value) {
    console.log('Closing battle modal before showing leaderboard');
    showBattleReportModal.value = false;
    
    // Add a small delay to ensure DOM updates
    setTimeout(() => {
      showLeaderboardModal.value = true;
    }, 100);
  } else {
    showLeaderboardModal.value = true;
  }
}

// Also watch for gameEnded flag
watch(() => gameData.value?.state?.gameEnded, (isEnded) => {
  console.log('Game ended flag changed to:', isEnded);
  if (isEnded) {
    console.log('Game has ended! Preparing leaderboard...');
    
    // Extract scores from players' treasures
    const entries = gameData.value.players.map(player => {
      const treasures = player.inventory?.treasures || [];
      const treasureValue = treasures.reduce((sum, treasure) => sum + (treasure.treasureValue || 0), 0);
      return {
        playerId: player.id,
        externalId: player.externalId || null,
        treasure: treasureValue
      };
    });

    // Sort entries by treasure value (highest first)
    entries.sort((a, b) => b.treasure - a.treasure);

    // Set the leaderboard data
    leaderboard.value = entries;

    // Get the winner (player with most treasure)
    winnerId.value = entries.length > 0 ? entries[0].playerId : null;
    
    // Also store the winner's externalId for authenticated user comparison
    const winnerExternalId = entries.length > 0 ? entries[0].externalId : null;
    console.log('Winner determined:', { winnerId: winnerId.value, winnerExternalId });

    // Close battle modal if it's still open before showing leaderboard
    if (showBattleReportModal.value) {
      console.log('Closing battle modal before showing leaderboard');
      showBattleReportModal.value = false;
      
      // Add a small delay to ensure DOM updates
      setTimeout(() => {
        showLeaderboardModal.value = true;
      }, 100);
    } else {
      // Show the leaderboard modal immediately if battle modal is not open
      showLeaderboardModal.value = true;
    }
  }
});

// Watch for game status changes to detect when game is finished
watch(() => gameData.value?.state?.status, (newStatus, oldStatus) => {
  console.log('Game status changed from', oldStatus, 'to', newStatus);
  if (newStatus === 'finished') {
    console.log('Game finished, preparing leaderboard...');
    console.log('Current game data:', gameData.value);

    // Extract scores from players' treasures
    const entries = gameData.value.players.map(player => {
      const treasures = player.inventory?.treasures || [];
      const treasureValue = treasures.reduce((sum, treasure) => sum + (treasure.treasureValue || 0), 0);
      return {
        playerId: player.id,
        externalId: player.externalId || null,
        treasure: treasureValue
      };
    });

    // Sort entries by treasure value (highest first)
    entries.sort((a, b) => b.treasure - a.treasure);

    // Set the leaderboard data
    leaderboard.value = entries;

    // Get the winner (player with most treasure)
    winnerId.value = entries.length > 0 ? entries[0].playerId : null;
    
    // Also store the winner's externalId for authenticated user comparison
    const winnerExternalId = entries.length > 0 ? entries[0].externalId : null;
    console.log('Winner determined:', { winnerId: winnerId.value, winnerExternalId });

    // Close battle modal if it's still open before showing leaderboard
    if (showBattleReportModal.value) {
      console.log('Closing battle modal before showing leaderboard');
      showBattleReportModal.value = false;
      
      // Add a small delay to ensure DOM updates
      setTimeout(() => {
        showLeaderboardModal.value = true;
      }, 100);
    } else {
      // Show the leaderboard modal immediately if battle modal is not open
      showLeaderboardModal.value = true;
    }
  }
}, { immediate: true });

// Example: If using a websocket or event bus, register handler here
onMounted(() => {
  if (window && window.addEventListener) {
    window.addEventListener('GameEnded', (e) => handleGameEnded(e.detail));
  }
  // If using a global event bus or websocket, register there instead
});

// Add computed property to check if current player is stunned
const isCurrentPlayerStunned = computed(() => {
  if (!gameData.value || !currentPlayerId.value) return false;
  
  // If battle report is showing or we're in replay mode, don't show stun dialog
  if (showBattleReportModal.value || isReplaying.value) return false;
  
  // Check if the current player in our UI matches the server's current player
  const isCurrentPlayersTurn = gameData.value?.state?.currentPlayerId === currentPlayerId.value;
  if (!isCurrentPlayersTurn) return false;
  
  // Check if player data exists and has HP = 0
  const playerData = gameData.value.players?.find(p => p.id === currentPlayerId.value);
  if (!playerData) return false;
  
  // Show stunned dialog if it's player's turn AND they're stunned
  const isStunned = playerData.hp === 0 || playerData.defeated === true;
  
  console.log(`Stun check for ${currentPlayerId.value}: isCurrentTurn=${isCurrentPlayersTurn}, isStunned=${isStunned}`);
  
  return isStunned;
});

// Add method to skip turn for stunned players
const skipStunnedPlayerTurn = async () => {
  if (!isCurrentPlayerStunned.value) return;

  try {
    loading.value = true;
    loadingStatus.value = 'Skipping turn...';

    const currentTurnId = gameData.value?.state?.currentTurnId;
    if (!currentTurnId) throw new Error('No current turn ID found in game state');

    await gameApi.endTurn({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: currentTurnId
    });

    // Poll for turn advancement
    let attempts = 0;
    let updatedGameState;
    do {
      await new Promise(res => setTimeout(res, 300));
      updatedGameState = await gameApi.getGame(id.value);
      attempts++;
    } while (
      updatedGameState.state.currentPlayerId === currentPlayerId.value &&
      attempts < 10
    );

    if (updatedGameState.state.currentPlayerId === currentPlayerId.value) {
      error.value = 'Turn did not advance. Please refresh or contact support.';
    } else {
      doUpdateGameDataSelectively(updatedGameState);
      // Optionally switch player here if needed
    }
  } catch (err) {
    console.error('Failed to skip turn:', err);
    error.value = `Failed to skip turn: ${err.message}`;
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Add function to check for stunned players and handle player switching
const checkStunnedPlayersAndSwitch = () => {
  if (!gameData.value || !gameData.value.players) return;
  
  const serverCurrentPlayerId = gameData.value.state?.currentPlayerId;
  if (!serverCurrentPlayerId) return;
  
  // First check if our current player is stunned and it's their turn
  const currentPlayerData = gameData.value.players.find(p => p.id === currentPlayerId.value);
  const isCurrentPlayerStunned = currentPlayerData && (currentPlayerData.hp === 0 || currentPlayerData.defeated === true);
  
  // If we have both players stored locally
  if (currentPlayerId.value && secondPlayerId.value) {
    // If our UI player doesn't match the server player, switch to match server
    if (serverCurrentPlayerId !== currentPlayerId.value) {
      console.log('Server player doesn\'t match UI player, switching...');
      
      // Check if the server's current player matches our second player
      if (serverCurrentPlayerId === secondPlayerId.value) {
        console.log('Switching to second player');
        switchPlayer({
          currentPlayerId,
          secondPlayerId,
          loadGameData
        });
      } else {
        // Just update to match server
        console.log('Updating current player to match server');
        currentPlayerId.value = serverCurrentPlayerId;
        localStorage.setItem('currentPlayerId', currentPlayerId.value);
      }
    }
  } else {
    // Only one player stored, update to match server
    currentPlayerId.value = serverCurrentPlayerId;
    localStorage.setItem('currentPlayerId', currentPlayerId.value);
  }
  
  console.log('Player check complete. Current player: ', currentPlayerId.value);
  console.log('Server player: ', serverCurrentPlayerId);
  console.log('Is current player stunned: ', isCurrentPlayerStunned);
};

// Call this function when game data changes
watch(() => gameData.value?.state?.currentPlayerId, (newPlayerId, oldPlayerId) => {
  if (newPlayerId !== oldPlayerId) {
    console.log('Server player changed from', oldPlayerId, 'to', newPlayerId);
    checkStunnedPlayersAndSwitch();
  }
}, { immediate: true });
</script>

<style>
/* Import centralized styles */
@import '@/styles/index.css';

/* Both players inventory styles */
.player-inventory-section {
  margin-bottom: 1rem;
  padding: 0.75rem;
  background: rgba(42, 42, 74, 0.1);
  border-radius: 8px;
  border: 1px solid rgba(42, 42, 74, 0.2);
  transition: all 0.3s ease;
}

.player-inventory-section.current-turn {
  background: rgba(42, 42, 74, 0.2);
  border-color: #ffcc00;
  box-shadow: 0 0 10px rgba(255, 204, 0, 0.2);
}

.player-inventory-section.is-current-user {
  border-color: #4a90e2;
  background: rgba(74, 144, 226, 0.1);
}

.player-inventory-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
  font-weight: 600;
}

.player-emoji {
  font-size: 1.5rem;
}

.player-name {
  flex: 1;
  font-size: 0.9rem;
}

.turn-badge {
  animation: pulse 2s infinite;
}

.unified-inventory-grid.compact {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(40px, 1fr));
  gap: 4px;
}

.inventory-item.compact {
  width: 40px;
  height: 40px;
  padding: 2px;
  position: relative;
}

.inventory-item.compact .item-icon {
  font-size: 1.2rem;
}

.item-damage-small {
  position: absolute;
  bottom: -2px;
  right: -2px;
  background: rgba(0, 0, 0, 0.8);
  color: #ffcc00;
  font-size: 0.6rem;
  padding: 1px 3px;
  border-radius: 3px;
  font-weight: bold;
}

.item-value-small {
  position: absolute;
  bottom: -2px;
  right: -2px;
  background: rgba(255, 204, 0, 0.9);
  color: #000;
  font-size: 0.6rem;
  padding: 1px 3px;
  border-radius: 3px;
  font-weight: bold;
}

.player-treasure-total {
  grid-column: 1 / -1;
  text-align: center;
  font-weight: bold;
  color: #ffcc00;
  padding: 4px;
  background: rgba(0, 0, 0, 0.3);
  border-radius: 4px;
  margin-top: 4px;
}

.leaderboard-modal-overlay {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.8);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.leaderboard-modal {
  background: #1a1a2e;
  border-radius: 12px;
  padding: 2rem;
  min-width: 350px;
  box-shadow: 0 4px 32px rgba(0,0,0,0.4);
  text-align: center;
  color: #e6e6e6;
  border: 1px solid #333;
}
.leaderboard-modal h2 {
  color: #ffcc00;
  margin-bottom: 1.5rem;
}
.leaderboard-table {
  width: 100%;
  margin: 1rem 0;
  border-collapse: collapse;
}
.leaderboard-table th {
  padding: 0.5rem 1rem;
  border-bottom: 1px solid #444;
  color: #ccc;
}
.leaderboard-table td {
  padding: 0.5rem 1rem;
  border-bottom: 1px solid #333;
}
.leaderboard-table .winner {
  font-weight: bold;
  background: rgba(255, 204, 0, 0.2);
  color: #ffcc00;
}

.leaderboard-table .current-player {
  background-color: rgba(100, 150, 255, 0.2);
  border: 2px solid rgba(100, 150, 255, 0.5);
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0%, 100% { 
    box-shadow: 0 0 0 0 rgba(100, 150, 255, 0.4);
  }
  50% { 
    box-shadow: 0 0 10px 5px rgba(100, 150, 255, 0.4);
  }
}

.leaderboard-table .current-player.winner {
  background: linear-gradient(135deg, rgba(255, 215, 0, 0.3), rgba(100, 150, 255, 0.3));
  border: 2px solid gold;
  animation: winner-pulse 1.5s infinite;
}

@keyframes winner-pulse {
  0%, 100% { 
    box-shadow: 0 0 0 0 rgba(255, 215, 0, 0.6);
    transform: scale(1);
  }
  50% { 
    box-shadow: 0 0 20px 10px rgba(255, 215, 0, 0.6);
    transform: scale(1.02);
  }
}

.player-result-message {
  padding: 1rem;
  margin: 1rem 0;
  border-radius: 8px;
  text-align: center;
  font-size: 1.2rem;
  font-weight: 600;
}

.player-result-message.winner-message {
  background: linear-gradient(135deg, rgba(255, 215, 0, 0.2), rgba(255, 255, 255, 0.1));
  color: gold;
  border: 2px solid gold;
  animation: celebration 1s ease-in-out;
}

.player-result-message.loser-message {
  background: rgba(100, 100, 100, 0.2);
  color: #ccc;
  border: 1px solid rgba(100, 100, 100, 0.3);
}

@keyframes celebration {
  0% { transform: scale(0.8); opacity: 0; }
  50% { transform: scale(1.1); }
  100% { transform: scale(1); opacity: 1; }
}

.you-badge {
  background: linear-gradient(135deg, #4a90e2, #357abd);
  color: white;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 0.7rem;
  font-weight: bold;
  margin-left: 8px;
  animation: badge-appear 0.5s ease-out;
}

@keyframes badge-appear {
  from { 
    transform: scale(0);
    opacity: 0;
  }
  to { 
    transform: scale(1);
    opacity: 1;
  }
}

.rank-medal {
  margin-left: 4px;
  font-size: 1.2rem;
}

.your-score-indicator {
  margin-left: 10px;
  color: #4a90e2;
  font-size: 0.85rem;
  font-weight: 600;
  animation: slide-in 0.5s ease-out;
}

@keyframes slide-in {
  from { 
    transform: translateX(-20px);
    opacity: 0;
  }
  to { 
    transform: translateX(0);
    opacity: 1;
  }
}
.player-info-row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.player-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 50%;
  font-size: 18px;
}

.hp-indicator {
  margin-left: 10px;
  font-size: 0.85em;
  padding: 2px 6px;
  background: rgba(204, 51, 51, 0.2);
  border-radius: 10px;
  color: #fff;
  display: inline-flex;
  align-items: center;
  vertical-align: middle;
  transition: all 0.3s ease;
}

.hp-indicator.hp-reduced {
  background: rgba(255, 0, 0, 0.5);
  transform: scale(1.1);
  animation: hp-flash 0.5s ease-in-out;
}

@keyframes hp-flash {
  0%, 100% { background: rgba(204, 51, 51, 0.2); }
  50% { background: rgba(255, 0, 0, 0.5); }
}
.winner-crown {
  margin-left: 2px;
  font-size: 18px;
  filter: drop-shadow(0 0 3px rgba(255, 204, 0, 0.5));
}
.leaderboard-modal button {
  margin-top: 1rem;
  padding: 0.5rem 1.5rem;
  background-color: #2a2a4a;
  color: #fff;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-weight: bold;
  transition: background-color 0.3s;
}
.leaderboard-modal button:hover {
  background-color: #3a3a6a;
}

/* Leaderboard action buttons */
.leaderboard-actions {
  display: flex;
  gap: 1rem;
  justify-content: center;
  margin-top: 1.5rem;
}

.leaderboard-actions button {
  padding: 0.75rem 1.5rem;
  background-color: #2a2a4a;
  color: #fff;
  border: 2px solid transparent;
  border-radius: 8px;
  cursor: pointer;
  font-size: 1rem;
  font-weight: 600;
  transition: all 0.3s ease;
}

.new-game-button:hover {
  background-color: #3a3a5a;
  border-color: #4a4a6a;
  transform: translateY(-2px);
}

.replay-button {
  background-color: #1a3a1a;
}

.replay-button:hover {
  background-color: #2a4a2a;
  border-color: #3a5a3a;
  transform: translateY(-2px);
}

/* Game finished styles */
.game-finished-banner {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  background: linear-gradient(135deg, #2a2a4a 0%, #3a3a5a 100%);
  color: #fff;
  padding: 1rem;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  z-index: 100;
  animation: slideDown 0.5s ease-out;
}

@keyframes slideDown {
  from {
    transform: translateY(-100%);
  }
  to {
    transform: translateY(0);
  }
}

.game-finished-banner .trophy-icon {
  font-size: 1.5rem;
  animation: bounce 2s infinite;
}

@keyframes bounce {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-5px); }
}

.game-finished-banner .finished-text {
  font-size: 1.2rem;
  font-weight: 600;
}

.view-results-button {
  padding: 0.5rem 1rem;
  background: rgba(255, 255, 255, 0.2);
  color: #fff;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-radius: 6px;
  cursor: pointer;
  font-weight: 600;
  transition: all 0.3s ease;
}

.view-results-button:hover {
  background: rgba(255, 255, 255, 0.3);
  border-color: rgba(255, 255, 255, 0.5);
  transform: scale(1.05);
}

.game-interface.game-finished .game-field {
  opacity: 0.8;
  /* Allow scrolling but prevent game interactions */
  pointer-events: auto;
}

/* Prevent interactions with game elements when finished */
.game-interface.game-finished .game-field .tiles-container > * {
  pointer-events: none;
}

.game-interface.game-finished .game-controls {
  opacity: 0.5;
  pointer-events: none;
}

/* Add styles for stunned player overlay */
.stunned-player-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background-color: var(--bg-overlay);
  z-index: var(--z-overlay);
  display: flex;
  justify-content: center;
  align-items: center;
}

.stunned-message {
  background-color: var(--bg-modal);
  padding: 2rem;
  border-radius: var(--radius-lg);
  text-align: center;
  box-shadow: 0 0 20px rgba(255, 0, 0, 0.5);
  max-width: 400px;
  color: var(--text-primary);
}

.stunned-player-icon {
  font-size: 48px;
  margin-bottom: 1rem;
  background-color: rgba(255, 255, 255, 0.1);
  width: 80px;
  height: 80px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 1rem auto;
  border: 2px solid var(--color-danger);
  box-shadow: 0 0 15px var(--color-danger);
  animation: pulse-shadow 2s infinite;
}

@keyframes pulse-shadow {
  0% { box-shadow: 0 0 10px var(--color-danger); }
  50% { box-shadow: 0 0 20px var(--color-danger); }
  100% { box-shadow: 0 0 10px var(--color-danger); }
}

.stunned-message h3 {
  color: var(--color-danger);
  margin-bottom: 1rem;
}

.skip-turn-button {
  margin-top: 1rem;
  padding: 0.5rem 1rem;
  background-color: var(--color-primary);
  color: white;
  border: none;
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: background-color 0.2s;
}

.skip-turn-button:hover {
  background-color: var(--color-primary-dark);
}

/* Teleport mode styles */
.teleport-controls {
  background-color: var(--color-tertiary-light);
  border: 2px solid var(--color-tertiary);
}

.teleport-controls h3 {
  color: var(--color-tertiary-dark);
  margin-bottom: 0.5rem;
}

.teleport-controls p {
  margin-bottom: 1rem;
  font-size: 0.9rem;
}

.cancel-teleport-btn {
  background-color: var(--color-secondary);
  padding: var(--spacing-md);
  font-size: var(--font-size-md);
  font-weight: bold;
  color: white;
  border: none;
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: background-color 0.2s;
}

.cancel-teleport-btn:hover {
  background-color: var(--color-secondary-dark);
}

/* Healing fountain markers */
.healing-fountain-marker {
  z-index: 20;
  display: flex;
  align-items: center;
  justify-content: center;
  background: radial-gradient(circle, rgba(76, 175, 80, 0.3) 0%, rgba(76, 175, 80, 0.1) 70%);
  animation: pulse 2s infinite;
}

.healing-fountain-indicator {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
}

.fountain-emoji {
  font-size: 2.5rem;
  filter: drop-shadow(0 0 4px rgba(76, 175, 80, 0.8));
}

.current-position-label {
  background: rgba(0, 0, 0, 0.7);
  color: white;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: bold;
}

@keyframes pulse {
  0% {
    box-shadow: 0 0 0 0 rgba(76, 175, 80, 0.4);
  }
  70% {
    box-shadow: 0 0 0 15px rgba(76, 175, 80, 0);
  }
  100% {
    box-shadow: 0 0 0 0 rgba(76, 175, 80, 0);
  }
}

/* Hide regular UI elements during teleport mode */
.game-field.teleport-mode .available-move-marker {
  display: none;
}

/* Clickable spell items */
.inventory-item.spell-item.clickable {
  cursor: pointer;
  transition: transform 0.2s, box-shadow 0.2s;
}

.inventory-item.spell-item.clickable:hover {
  transform: scale(1.1);
  box-shadow: 0 0 10px rgba(138, 43, 226, 0.6);
  background: rgba(138, 43, 226, 0.2);
}
</style>
