// Replay Management Utilities
import { nextTick } from 'vue';

export class ReplayManager {
  constructor(gameApi, gameData, replayState, centerViewOnMiddle, centerViewOnTile) {
    this.gameApi = gameApi;
    this.gameData = gameData;
    this.replayState = replayState;
    this.centerViewOnMiddle = centerViewOnMiddle;
    this.centerViewOnTile = centerViewOnTile;
    this.replayTimeout = null;
  }

  async startReplay(gameId) {
    if (this.replayState.isReplaying) return;

    try {
      // Fetch all turns for the game
      const response = await this.gameApi.getGameTurns(gameId);

      if (!response?.turns?.length) {
        alert('No turns available for replay');
        return;
      }

      // Process the turns
      this.replayState.gameTurns = response.turns.map(turn => ({
        turnId: turn.turn_id,
        turnNumber: turn.turn_number,
        playerId: turn.player_id,
        actions: turn.actions,
        startTime: turn.start_time,
        endTime: null
      }));

      // Save original game data
      this.replayState.originalGameData = JSON.parse(JSON.stringify(this.gameData.value));

      // Setup replay UI
      this.replayState.isReplaying = true;
      this.replayState.currentReplayTurn = 0;
      this.replayState.replaySpeed = 1;

      this.setupReplayUI();
      this.cleanFieldForReplay();
      this.centerViewOnMiddle();

      // Start replay sequence
      setTimeout(() => {
        this.replayNextTurn();
      }, 500);

    } catch (error) {
      console.error('Failed to start replay:', error);
      alert('Failed to start replay: ' + (error.message || 'Unknown error'));
      this.replayState.isReplaying = false;
    }
  }

  setupReplayUI() {
    const gameField = document.querySelector('.game-field');
    if (gameField) {
      gameField.classList.add('is-replaying');
    }

    nextTick(() => {
      const overlay = this.createReplayOverlay();
      const speedIndicator = this.createSpeedIndicator();
      
      const gameContainer = document.querySelector('.game-container');
      gameContainer.appendChild(overlay);
      gameContainer.appendChild(speedIndicator);
    });
  }

  createReplayOverlay() {
    const overlay = document.createElement('div');
    overlay.className = 'replay-overlay';
    overlay.id = 'replay-overlay';

    const status = document.createElement('div');
    status.className = 'replay-status';
    status.textContent = 'REPLAY MODE';

    overlay.appendChild(status);
    return overlay;
  }

  createSpeedIndicator() {
    const speedIndicator = document.createElement('div');
    speedIndicator.className = 'replay-speed-indicator';
    speedIndicator.id = 'replay-speed-indicator';
    speedIndicator.textContent = `Speed: ${this.replayState.replaySpeed}x`;
    return speedIndicator;
  }

  cleanFieldForReplay() {
    if (this.gameData.value?.field) {
      // Keep only starting tile and reset positions
      if (this.gameData.value.field.tiles?.length > 0) {
        const startingTileIndex = this.gameData.value.field.tiles.findIndex(
          tile => tile.position === '0,0'
        );

        if (startingTileIndex >= 0) {
          const startingTile = this.gameData.value.field.tiles[startingTileIndex];
          this.gameData.value.field.tiles = [startingTile];
        } else {
          this.gameData.value.field.tiles = [];
        }
      }

      // Reset player positions
      if (this.gameData.value.field.playerPositions) {
        for (const playerId in this.gameData.value.field.playerPositions) {
          this.gameData.value.field.playerPositions[playerId] = '0,0';
        }
      }

      // Reset field size
      if (this.gameData.value.field.size) {
        this.gameData.value.field.size.minX = -1;
        this.gameData.value.field.size.maxX = 1;
        this.gameData.value.field.size.minY = -1;
        this.gameData.value.field.size.maxY = 1;
      }
    }
  }

  replayNextTurn() {
    if (!this.replayState.isReplaying || 
        this.replayState.currentReplayTurn >= this.replayState.gameTurns.length) {
      this.finishReplay();
      return;
    }

    const turn = this.replayState.gameTurns[this.replayState.currentReplayTurn];
    
    // Clean up previous animations
    this.cleanupPreviousAnimations();

    // Process turn actions
    const activeTilePos = this.processTurnActions(turn);

    // Center view on active tile
    if (activeTilePos) {
      this.centerViewOnTile(activeTilePos);
    } else {
      this.centerViewOnMiddle();
    }

    // Add visual feedback
    this.addTurnTransitionEffect();

    this.replayState.currentReplayTurn++;

    // Calculate duration and continue
    const duration = this.calculateTurnDuration(turn);
    this.replayTimeout = setTimeout(() => {
      this.replayNextTurn();
    }, duration / this.replayState.replaySpeed);
  }

  cleanupPreviousAnimations() {
    // Remove animation classes from DOM
    const tiles = document.querySelectorAll('.tile');
    tiles.forEach(tile => {
      tile.classList.remove('is-current-action', 'tile-placing-animation');
    });

    // Clear animation flags from data model
    if (this.gameData.value?.field?.tiles) {
      this.gameData.value.field.tiles.forEach(tile => {
        tile.isCurrentAction = false;
        tile.isPlacingAnimation = false;
      });
    }
  }

  processTurnActions(turn) {
    let activeTilePos = null;

    const placeTileActions = turn.actions.filter(action => action.action === 'place_tile');
    const moveActions = turn.actions.filter(action => action.action === 'move');

    // Process tile placements
    if (placeTileActions.length > 0) {
      activeTilePos = this.processPlaceTileActions(placeTileActions, turn);
    }

    // Process moves
    if (moveActions.length > 0) {
      const movePos = this.processMoveActions(moveActions, turn);
      if (!activeTilePos) activeTilePos = movePos;
    }

    return activeTilePos;
  }

  processPlaceTileActions(actions, turn) {
    let activeTilePos = null;

    actions.forEach(action => {
      const fieldPlace = action.additionalData?.fieldPlace;
      if (fieldPlace && action.tileId) {
        const [x, y] = fieldPlace.split(',').map(Number);
        
        // Add new tile with animation data
        const newTile = {
          position: fieldPlace,
          tileId: action.tileId,
          isCurrentAction: true,
          isPlacingAnimation: true,
          turnNumber: this.replayState.currentReplayTurn + 1
        };

        this.gameData.value.field.tiles.push(newTile);
        this.updateFieldSize(x, y);
        
        activeTilePos = { x, y };
      }
    });

    return activeTilePos;
  }

  processMoveActions(actions, turn) {
    let activeTilePos = null;

    actions.forEach(action => {
      const toPosition = action.additionalData?.toPosition;
      if (toPosition && this.gameData.value.field.playerPositions) {
        this.gameData.value.field.playerPositions[turn.playerId] = toPosition;
        
        const [x, y] = toPosition.split(',').map(Number);
        activeTilePos = { x, y };
      }
    });

    return activeTilePos;
  }

  updateFieldSize(x, y) {
    if (this.gameData.value.field.size) {
      this.gameData.value.field.size.minX = Math.min(this.gameData.value.field.size.minX || 0, x);
      this.gameData.value.field.size.maxX = Math.max(this.gameData.value.field.size.maxX || 0, x);
      this.gameData.value.field.size.minY = Math.min(this.gameData.value.field.size.minY || 0, y);
      this.gameData.value.field.size.maxY = Math.max(this.gameData.value.field.size.maxY || 0, y);
    }
  }

  addTurnTransitionEffect() {
    const gameBoard = document.querySelector('.game-board');
    if (gameBoard) {
      gameBoard.classList.add('turn-transition');
      setTimeout(() => {
        gameBoard.classList.remove('turn-transition');
      }, 300);
    }
  }

  calculateTurnDuration(turn) {
    const hasPlacement = turn.actions.some(action => action.action === 'place_tile');
    const hasRotation = turn.actions.some(action => action.action === 'rotate_tile');
    const hasMovement = turn.actions.some(action => action.action === 'move');

    if (hasPlacement) return 2500;
    if (hasRotation) return 2000;
    if (hasMovement) return 1500;
    return 2000;
  }

  finishReplay() {
    if (this.replayState.isReplaying && !confirm('Are you sure you want to stop the replay?')) {
      return;
    }

    this.replayState.isReplaying = false;
    clearTimeout(this.replayTimeout);

    this.cleanupReplayUI();
    this.restoreOriginalGameData();
    this.resetReplayState();

    setTimeout(() => {
      this.centerViewOnMiddle();
    }, 100);
  }

  cleanupReplayUI() {
    this.cleanupPreviousAnimations();

    const gameField = document.querySelector('.game-field');
    if (gameField) {
      gameField.classList.remove('is-replaying');
    }

    const overlay = document.getElementById('replay-overlay');
    const speedIndicator = document.getElementById('replay-speed-indicator');

    if (overlay) overlay.remove();
    if (speedIndicator) speedIndicator.remove();
  }

  restoreOriginalGameData() {
    if (this.replayState.originalGameData) {
      this.gameData.value = this.replayState.originalGameData;
    }
  }

  resetReplayState() {
    this.replayState.currentReplayTurn = 0;
  }

  changeReplaySpeed(newSpeed) {
    this.replayState.replaySpeed = newSpeed;
    
    const speedIndicator = document.getElementById('replay-speed-indicator');
    if (speedIndicator) {
      speedIndicator.textContent = `Speed: ${newSpeed}x`;
    }
  }
}

export function createReplayManager(gameApi, gameData, replayState, centerViewOnMiddle, centerViewOnTile) {
  return new ReplayManager(gameApi, gameData, replayState, centerViewOnMiddle, centerViewOnTile);
} 