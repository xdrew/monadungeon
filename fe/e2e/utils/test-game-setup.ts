import { APIRequestContext } from '@playwright/test';
import { v4 as uuidv4 } from 'uuid';

export interface PlayerTestConfig {
  maxHp?: number;
  maxActions?: number;
}

// Note: The test API only supports setting maxHp and maxActions.
// Properties like hp, actionsRemaining, isStunned, and inventory
// cannot be set directly via the test API and must be achieved
// through game mechanics (e.g., losing battles to become stunned).

export interface TestGameConfig {
  gameId?: string;
  diceRolls?: number[];
  tileSequence?: string[];
  itemSequence?: string[];
  playerConfigs?: { [playerId: string]: PlayerTestConfig };
}

export class TestGameSetup {
  private apiUrl: string;

  constructor(apiUrl: string = process.env.API_URL || 'http://localhost:18080') {
    this.apiUrl = apiUrl;
  }

  async enableTestMode(request: APIRequestContext): Promise<void> {
    const response = await request.post(`${this.apiUrl}/api/test/toggle-mode`, {
      data: { enabled: true }
    });
    
    if (!response.ok()) {
      console.warn('Failed to enable test mode:', response.status());
    }
  }

  async disableTestMode(request: APIRequestContext): Promise<void> {
    await request.post(`${this.apiUrl}/api/test/toggle-mode`, {
      data: { enabled: false }
    });
  }

  async setupGame(request: APIRequestContext, config: TestGameConfig): Promise<string> {
    const gameId = config.gameId || uuidv4();
    
    // The SetupTestGameHandler now creates the game automatically
    const setupResponse = await request.post(`${this.apiUrl}/api/test/setup-game`, {
      data: {
        gameId,
        diceRolls: config.diceRolls || [],
        tileSequence: config.tileSequence || [],
        itemSequence: config.itemSequence || [],
        playerConfigs: config.playerConfigs || {},
      }
    });
    
    if (!setupResponse.ok()) {
      throw new Error(`Failed to setup test game: ${setupResponse.status()}`);
    }
    
    console.log('GameId: ' + gameId);
    return gameId;
  }

  async createGameWithPlayers(
    request: APIRequestContext,
    gameId: string,
    players: Array<{ id: string; name: string; characterType: string }>
  ): Promise<void> {
    for (const player of players) {
      const response = await request.post(`${this.apiUrl}/api/game/player`, {
        data: {
          gameId,
          playerId: player.id,
          name: player.name,
          characterType: player.characterType
        }
      });
      
      if (!response.ok()) {
        throw new Error(`Failed to add player ${player.name}: ${response.status()}`);
      }
    }
  }

  async startGame(request: APIRequestContext, gameId: string): Promise<void> {
    const response = await request.post(`${this.apiUrl}/api/game/start`, {
      data: { gameId }
    });
    
    if (!response.ok()) {
      throw new Error(`Failed to start game: ${response.status()}`);
    }
  }

  async setPlayerState(
    request: APIRequestContext, 
    gameId: string, 
    playerId: string, 
    config: PlayerTestConfig
  ): Promise<void> {
    const response = await request.post(`${this.apiUrl}/api/test/player-state`, {
      data: {
        gameId,
        playerId,
        ...config
      }
    });
    
    if (!response.ok()) {
      console.warn('Failed to set player state:', response.status());
    }
  }

  /**
   * Play a single turn via API: pick tile at first available position, auto-move, handle battle, end turn.
   * Used to rapidly advance the game to a desired turn without UI interaction.
   */
  async playTurnViaApi(request: APIRequestContext, gameId: string, options: {
    placeTile?: boolean;           // Whether to place a tile (default: true)
    battleAction?: 'pickup' | 'leave' | 'skip';  // How to handle battle reward (default: 'pickup')
    moveToPosition?: string;       // Specific position to move to (optional)
    skipTurn?: boolean;            // Just end turn without doing anything
  } = {}): Promise<void> {
    const { placeTile = true, battleAction = 'pickup', skipTurn = false } = options;

    // Get current game state
    const gameResp = await request.get(`${this.apiUrl}/api/game/${gameId}`);
    const gameData = await gameResp.json();
    const currentPlayerId = gameData.state?.currentPlayerId;
    const currentTurnId = gameData.state?.currentTurnId;

    if (!currentPlayerId || !currentTurnId) {
      throw new Error('No active turn found');
    }

    if (skipTurn) {
      await request.post(`${this.apiUrl}/api/game/end-turn`, {
        data: { gameId, playerId: currentPlayerId, turnId: currentTurnId }
      });
      return;
    }

    if (placeTile) {
      const availablePlaces = gameData.state?.availablePlaces;
      const placeTilePositions = availablePlaces?.placeTile || [];
      const moveToPositions = availablePlaces?.moveTo || [];

      // Find a position to place a tile (prefer placeTile positions, fall back to moveTo empty spots)
      let targetPosition = placeTilePositions[0] || moveToPositions.find((p: string) => {
        return !gameData.field?.tiles?.some((t: any) => t.position === p);
      });

      if (!targetPosition && moveToPositions.length > 0) {
        // Just move to an existing tile
        targetPosition = moveToPositions[0];
      }

      if (targetPosition) {
        const [tx, ty] = targetPosition.split(',').map(Number);

        // Determine required open side
        const playerPos = gameData.field?.playerPositions?.[currentPlayerId];
        let requiredOpenSide = 0; // TOP by default
        if (playerPos) {
          const [px, py] = playerPos.split(',').map(Number);
          if (tx > px) requiredOpenSide = 3; // LEFT (new tile needs left open to connect)
          else if (tx < px) requiredOpenSide = 1; // RIGHT
          else if (ty > py) requiredOpenSide = 0; // TOP
          else if (ty < py) requiredOpenSide = 2; // BOTTOM
        }

        // Check if position already has a tile (just move, don't pick)
        const existingTile = gameData.field?.tiles?.find((t: any) => t.position === targetPosition);

        if (!existingTile) {
          // Pick tile from deck
          const tileId = uuidv4();
          const pickResp = await request.post(`${this.apiUrl}/api/game/pick-tile`, {
            data: {
              gameId, tileId, playerId: currentPlayerId, turnId: currentTurnId,
              requiredOpenSide, fieldPlace: targetPosition
            }
          });

          if (pickResp.ok()) {
            // Place the tile
            await request.post(`${this.apiUrl}/api/game/place-tile`, {
              data: {
                gameId, tileId, fieldPlace: targetPosition,
                playerId: currentPlayerId, turnId: currentTurnId
              }
            });
          }
        }

        // Move player to the position
        const moveResp = await request.post(`${this.apiUrl}/api/game/move-player`, {
          data: {
            gameId, playerId: currentPlayerId, turnId: currentTurnId,
            fromPosition: gameData.field?.playerPositions?.[currentPlayerId],
            toPosition: targetPosition
          }
        });

        const moveData = await moveResp.json();

        // Handle battle if one occurred
        if (moveData.battleInfo) {
          const battleId = moveData.battleInfo.battleId;
          if (battleId) {
            await request.post(`${this.apiUrl}/api/game/finalize-battle`, {
              data: {
                gameId, battleId, playerId: currentPlayerId, turnId: currentTurnId,
                action: battleAction === 'leave' ? 'leave' : 'pickup'
              }
            });
          }
        }
      }
    } else if (options.moveToPosition) {
      const playerPos = gameData.field?.playerPositions?.[currentPlayerId];
      await request.post(`${this.apiUrl}/api/game/move-player`, {
        data: {
          gameId, playerId: currentPlayerId, turnId: currentTurnId,
          fromPosition: playerPos, toPosition: options.moveToPosition
        }
      });
    }

    // End turn
    // Re-fetch state in case turn already ended (e.g., from battle loss)
    const freshState = await (await request.get(`${this.apiUrl}/api/game/${gameId}`)).json();
    if (freshState.state?.currentTurnId === currentTurnId) {
      await request.post(`${this.apiUrl}/api/game/end-turn`, {
        data: { gameId, playerId: currentPlayerId, turnId: currentTurnId }
      }).catch(() => {}); // Ignore errors if turn already ended
    }
  }

  /**
   * Advance the game by playing N turns via API.
   * Each turn: pick tile, place, move, handle battle, end turn.
   */
  async advanceGameTurns(request: APIRequestContext, gameId: string, turns: number, options?: {
    battleAction?: 'pickup' | 'leave' | 'skip';
    turnOverrides?: { [turnNumber: number]: { battleAction?: 'pickup' | 'leave' | 'skip'; skipTurn?: boolean; placeTile?: boolean } };
  }): Promise<void> {
    for (let i = 0; i < turns; i++) {
      const turnOverride = options?.turnOverrides?.[i + 1] || {};
      await this.playTurnViaApi(request, gameId, {
        battleAction: turnOverride.battleAction || options?.battleAction || 'pickup',
        skipTurn: turnOverride.skipTurn,
        placeTile: turnOverride.placeTile,
      });
    }
  }

  async createStunnedPlayerGame(request: APIRequestContext): Promise<{ gameId: string; playerId: string }> {
    const gameId = uuidv4();
    const playerId = uuidv4();
    
    // Create game with player
    await this.setupGame(request, { 
      gameId,
      playerConfigs: {
        [playerId]: {}
      }
    });
    
    // Note: Stunning is no longer directly settable in test config
    // The game should handle stunning based on game logic
    
    return { gameId, playerId };
  }

  async createLowActionsGame(request: APIRequestContext): Promise<{ gameId: string; playerId: string }> {
    const gameId = uuidv4();
    const playerId = uuidv4();
    
    // Create game with player with limited actions
    await this.setupGame(request, { 
      gameId,
      playerConfigs: {
        [playerId]: {
          maxActions: 1
        }
      }
    });
    
    return { gameId, playerId };
  }
}

// Predefined test configurations
export const TEST_CONFIGS = {
  // Battle test with controlled dice rolls
  BATTLE_WIN_LOSE: {
    diceRolls: [6, 6, 1, 1], // First battle: win (12 damage), Second battle: lose (2 damage)
    tileSequence: ['fourSideRoom', 'twoSideStraightRoom'],
    itemSequence: ['skeleton', 'skeleton_king'] // Skeleton HP: 9, King HP: 10
  },
  
  // Item pickup test
  ITEM_PICKUP: {
    diceRolls: [5, 5], // Win battle with 10 damage
    tileSequence: ['fourSideRoom'],
    itemSequence: ['skeleton'], // Skeleton drops items
  },
  
  // Movement test with no battles
  MOVEMENT_ONLY: {
    tileSequence: ['fourSide', 'threeSide', 'twoSideCorner'], // No rooms = no monsters
    itemSequence: []
  },

  // Low HP player test
  LOW_HP_PLAYER: {
    diceRolls: [1, 1], // Will lose any battle
    tileSequence: ['fourSideRoom'],
    itemSequence: ['skeleton'],
    playerConfigs: {
      'player1': {
        maxHp: 5
      }
    }
  },

  // Limited actions test
  LIMITED_ACTIONS: {
    tileSequence: ['fourSide', 'threeSide'],
    itemSequence: [],
    playerConfigs: {
      'player1': {
        maxActions: 1
      }
    }
  },

  // Multiple players with different HP settings
  MULTIPLAYER_STATES: {
    tileSequence: ['fourSide', 'threeSide', 'twoSideCorner'],
    itemSequence: [],
    playerConfigs: {
      'player1': {
        maxHp: 5,
        maxActions: 3
      },
      'player2': {
        maxHp: 3,
        maxActions: 2
      }
    }
  }
};