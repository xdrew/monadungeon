<template>
  <div class="sidebar-section">
    <h4>Replay Controls</h4>
    <div class="replay-indicator">
      <div class="replay-progress">
        <span>Replaying turn {{ currentReplayTurn }} of {{ gameTurns.length }}</span>
        <div class="progress-bar">
          <div
            class="progress-fill"
            :style="{width: `${(currentReplayTurn / gameTurns.length) * 100}%`}"
          />
        </div>
      </div>
      
      <div class="replay-speed-control">
        <label for="replay-speed">Replay Speed:</label>
        <select 
          id="replay-speed" 
          :value="replaySpeed" 
          class="speed-select" 
          @change="onSpeedChange($event.target.value)"
        >
          <option value="0.5">
            Slow (0.5x)
          </option>
          <option value="1">
            Normal (1x)
          </option>
          <option value="2">
            Fast (2x)
          </option>
          <option value="4">
            Very Fast (4x)
          </option>
        </select>
      </div>
      
      <div
        v-if="currentReplayTurn > 0 && currentReplayTurn <= gameTurns.length"
        class="turn-info"
      >
        <h4>Turn {{ gameTurns[currentReplayTurn-1].turnNumber }} - Player {{ gameTurns[currentReplayTurn-1].playerId }}</h4>
        <div class="turn-actions">
          <p
            v-for="(action, index) in gameTurns[currentReplayTurn-1].actions"
            :key="index"
            class="turn-action"
          >
            <span class="action-type">{{ formatActionType(action.action) }}:</span>
            <span
              v-if="action.tileId"
              class="tile-id"
            >Tile {{ formatTileId(action.tileId) }}</span>
            <span
              v-if="action.additionalData"
              class="action-details"
            >
              <span v-if="action.additionalData.toPosition">
                Move to ({{ action.additionalData.toPosition }})
              </span>
              <span v-if="action.additionalData.fromPosition">
                from ({{ action.additionalData.fromPosition }})
              </span>
              <span v-if="action.additionalData.fieldPlace">
                at position ({{ action.additionalData.fieldPlace }})
              </span>
              <span v-if="action.additionalData.side !== undefined">
                rotation {{ action.additionalData.side }}
              </span>
            </span>
            <span
              v-if="action.performedAt"
              class="action-time"
            >
              {{ formatTime(action.performedAt) }}
            </span>
          </p>
        </div>
        <p class="turn-time">
          Started: {{ formatTime(gameTurns[currentReplayTurn-1].startTime) }}
        </p>
      </div>
      
      <button
        class="stop-replay-button"
        @click="stopReplay"
      >
        Stop Replay
      </button>
    </div>
  </div>
</template>

<script setup>
import { defineProps, defineEmits } from 'vue';

const props = defineProps({
  currentReplayTurn: {
    type: Number,
    required: true
  },
  gameTurns: {
    type: Array,
    required: true
  },
  replaySpeed: {
    type: Number,
    required: true
  }
});

const emit = defineEmits(['stop-replay', 'speed-change']);

// Helper functions for formatting
const formatActionType = (actionType) => {
  if (!actionType) return 'Unknown Action';
  return actionType.split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};

const formatTileId = (tileId) => {
  if (!tileId) return '';
  return tileId.substring(0, 8);
};

const formatTime = (timestamp) => {
  if (!timestamp) return 'Unknown';
  
  const date = new Date(timestamp);
  return date.toLocaleTimeString();
};

const stopReplay = () => {
  emit('stop-replay');
};

const onSpeedChange = (newValue) => {
  emit('speed-change', Number(newValue));
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

.replay-indicator {
  display: flex;
  flex-direction: column;
  gap: 15px;
}

.replay-progress {
  margin-bottom: 10px;
}

.progress-bar {
  width: 100%;
  height: 10px;
  background-color: #333;
  border-radius: 5px;
  margin-top: 5px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background-color: #4CAF50;
  transition: width 0.3s ease;
}

.replay-speed-control {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
}

.speed-select {
  background-color: #333;
  color: white;
  border: 1px solid #555;
  border-radius: 4px;
  padding: 5px;
}

.turn-info {
  background-color: #2a2a40;
  border-radius: 6px;
  padding: 10px;
  margin-bottom: 10px;
}

.turn-actions {
  display: flex;
  flex-direction: column;
  gap: 5px;
  margin-bottom: 10px;
}

.turn-action {
  margin: 0;
  padding: 5px;
  background-color: #333;
  border-radius: 4px;
}

.action-type {
  font-weight: bold;
  color: #ffcc00;
}

.action-details {
  margin-left: 5px;
  color: #bbb;
}

.action-time {
  margin-left: 10px;
  font-size: 0.9em;
  color: #888;
}

.stop-replay-button {
  padding: 10px;
  background-color: #c62828;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-weight: bold;
  transition: background-color 0.3s;
}

.stop-replay-button:hover {
  background-color: #e53935;
}
</style> 