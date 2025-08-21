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