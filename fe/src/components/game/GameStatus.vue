<template>
  <div class="sidebar-section">
    <h4>Game Status</h4>
    <div class="game-status-info">
      <p v-if="status">
        Status: <span class="status-value">{{ status }}</span>
      </p>
      <p v-if="currentTurn">
        Current Turn: <span class="status-value">{{ currentTurn }}</span>
      </p>
      <p v-if="playerCount !== undefined">
        Players: <span class="status-value">{{ playerCount }}</span>
      </p>
      
      <div
        v-if="showGameControls"
        class="game-controls"
      >
        <button 
          class="start-button" 
          :disabled="!canStartGame"
          @click="startGame"
        >
          Start Game
        </button>
        <button
          class="replay-button"
          :disabled="isReplaying"
          @click="replayGame"
        >
          {{ isReplaying ? 'Replaying...' : 'Replay Game' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { defineProps, defineEmits } from 'vue';

const props = defineProps({
  status: {
    type: String,
    default: ''
  },
  currentTurn: {
    type: Number,
    default: null
  },
  playerCount: {
    type: Number,
    default: undefined
  },
  canStartGame: {
    type: Boolean,
    default: false
  },
  isReplaying: {
    type: Boolean,
    default: false
  },
  showGameControls: {
    type: Boolean,
    default: true
  }
});

const emit = defineEmits(['start-game', 'replay-game']);

const startGame = () => {
  emit('start-game');
};

const replayGame = () => {
  emit('replay-game');
};
</script>

<style scoped>
.sidebar-section {
  background-color: #1a1a2e;
  border-radius: 8px;
  padding: 15px;
  margin-bottom: 20px;
  border: 1px solid #333;
  color: #e6e6e6;
}

h4 {
  color: #ffcc00;
  margin-top: 0;
  margin-bottom: 10px;
  border-bottom: 1px solid #444;
  padding-bottom: 5px;
}

.game-status-info {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.game-status-info p {
  margin: 0;
  padding: 5px 0;
  display: flex;
  justify-content: space-between;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.status-value {
  font-weight: bold;
  color: #4CAF50;
}

.game-controls {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 10px;
}

.start-button, .replay-button {
  padding: 10px;
  border: none;
  border-radius: 4px;
  font-weight: bold;
  cursor: pointer;
  transition: background-color 0.3s;
}

.start-button {
  background-color: #4CAF50;
  color: white;
}

.start-button:hover {
  background-color: #45a049;
}

.start-button:disabled {
  background-color: #7f8c8d;
  opacity: 0.7;
  cursor: not-allowed;
}

.replay-button {
  background-color: #2196F3;
  color: white;
}

.replay-button:hover {
  background-color: #0b7dda;
}

.replay-button:disabled {
  background-color: #7f8c8d;
  opacity: 0.7;
  cursor: not-allowed;
}
</style> 