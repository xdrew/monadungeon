<template>
  <div
    class="available-place move-marker"
    :class="{ 
      'place-tile': canPlaceTile,
      'clickable': canClick,
      'disabled': !canClick || isRequestInProgress
    }"
    :style="{
      left: `${(x - minX) * tileSize}px`,
      top: `${(y - minY) * tileSize}px`,
      zIndex: '55'
    }"
    :title="`Available position: ${position} - ${canPlaceTile ? 'Can place tile and move' : 'Can move'}`"
    @click="handleClick"
  >
    <div class="marker-content">
      <span
        v-if="canPlaceTile"
        class="place-icon"
      />
      <span
        v-else
        class="move-icon"
      >👣</span>
    </div>
  </div>
</template>

<script setup>
import { defineProps, defineEmits } from 'vue';

const props = defineProps({
  position: {
    type: String,
    required: true
  },
  x: {
    type: Number,
    required: true
  },
  y: {
    type: Number,
    required: true
  },
  canPlaceTile: {
    type: Boolean,
    default: false
  },
  isPlayerTurn: {
    type: Boolean,
    default: false
  },
  minX: {
    type: Number,
    default: 0
  },
  minY: {
    type: Number,
    default: 0
  },
  tileSize: {
    type: Number,
    default: 100
  },
  canClick: {
    type: Boolean,
    default: true
  },
  isRequestInProgress: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['click']);

const handleClick = () => {
  if (!props.canClick || props.isRequestInProgress) {
    console.log('Click ignored - marker is disabled');
    return;
  }
  
  if (props.isPlayerTurn) {
    emit('click', props.position);
  }
};
</script>

<style scoped>
.available-place {
  position: absolute;
  width: var(--tile-size, 100px);
  height: var(--tile-size, 100px);
  display: flex;
  justify-content: center;
  align-items: center;
  pointer-events: none;
  z-index: 55 !important;
}

.move-marker {
  border: 3px dashed rgba(0, 180, 255, 0.8);
  border-radius: 10px;
  background-color: rgba(0, 180, 255, 0.25);
  animation: pulse 2s infinite;
}

.move-marker.place-tile {
  border: 3px dashed rgba(50, 205, 50, 0.8);
  background-color: rgba(50, 205, 50, 0.3);
  animation: pulse-place 2s infinite;
}

/* Animation for available places */
@keyframes pulse {
  0% { transform: scale(0.95); opacity: 0.8; }
  50% { transform: scale(1.05); opacity: 1; }
  100% { transform: scale(0.95); opacity: 0.8; }
}

@keyframes pulse-place {
  0% { transform: scale(0.95); opacity: 0.9; }
  50% { transform: scale(1.05); opacity: 1; }
  100% { transform: scale(0.95); opacity: 0.9; }
}

/* Add clickable class for available places */
.available-place.clickable {
  pointer-events: auto !important;
  cursor: pointer;
}

.available-place.clickable:hover {
  transform: scale(1.1);
  box-shadow: 0 0 15px rgba(76, 175, 80, 0.8);
  z-index: 60 !important;
}

.available-place.disabled {
  opacity: 0.3;
  cursor: not-allowed;
  pointer-events: none;
}

.marker-content {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  font-size: 24px;
  opacity: 0.8;
}

.place-icon {
  position: relative;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background-color: rgba(50, 205, 50, 0.9);
  box-shadow: 0 0 10px rgba(50, 205, 50, 0.5);
}

.place-icon::before,
.place-icon::after {
  content: "";
  position: absolute;
  background-color: white;
}

.place-icon::before {
  width: 4px;
  height: 18px;
  top: 6px;
  left: 13px;
}

.place-icon::after {
  width: 18px;
  height: 4px;
  top: 13px;
  left: 6px;
}

.move-icon {
  color: rgba(0, 180, 255, 0.9);
  filter: drop-shadow(0 0 3px rgba(0, 0, 0, 0.5));
}
</style> 