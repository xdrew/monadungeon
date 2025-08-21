<template>
  <div
    class="tile ghost-tile"
    :class="[
      isRoom ? 'room' : 'corridor',
      orientationClass
    ]"
    :style="{
      left: `${(parseInt(position.split(',')[0]) - minX) * tileSize}px`,
      top: `${(parseInt(position.split(',')[1]) - minY) * tileSize}px`,
      zIndex: '50'
    }"
    :title="'Click to place tile (Enter)'"
    @click="placeTile"
  >
    <div
      class="tile-content"
      :class="{ 'room-content': isRoom }"
    >
      <div
        class="tile-orientation"
        :class="{ 'room-symbol': isRoom }"
      >
        {{ orientationSymbol }}
      </div>
      <div
        v-if="isPlacingTile"
        class="ghost-tile-controls"
      >
        <button
          class="ghost-rotate-btn"
          title="Rotate tile (R)"
          @click.stop="rotateGhostTile"
        >
          🔄
        </button>
      </div>
      <!-- Add visual indicators for openings in each direction -->
      <div class="tile-openings">
        <div
          v-if="hasTopOpening"
          class="opening top"
          :class="{ 'room-opening': isRoom }"
        />
        <div
          v-if="hasRightOpening"
          class="opening right"
          :class="{ 'room-opening': isRoom }"
        />
        <div
          v-if="hasBottomOpening"
          class="opening bottom"
          :class="{ 'room-opening': isRoom }"
        />
        <div
          v-if="hasLeftOpening"
          class="opening left"
          :class="{ 'room-opening': isRoom }"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { defineProps, defineEmits, computed } from 'vue';

const props = defineProps({
  position: {
    type: String,
    required: true
  },
  orientation: {
    type: String,
    required: true
  },
  orientationSymbol: {
    type: String,
    required: true
  },
  orientationClass: {
    type: String,
    required: true
  },
  isRoom: {
    type: Boolean,
    default: false
  },
  isPlacingTile: {
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
  openings: {
    type: Object,
    default: () => ({
      top: false,
      right: false,
      bottom: false,
      left: false
    })
  }
});

const emit = defineEmits(['rotate', 'place']);

// Computed properties for openings
const hasTopOpening = computed(() => props.openings.top);
const hasRightOpening = computed(() => props.openings.right);
const hasBottomOpening = computed(() => props.openings.bottom);
const hasLeftOpening = computed(() => props.openings.left);

// Function to rotate the ghost tile
const rotateGhostTile = () => {
  emit('rotate');
};

// Function to place the tile
const placeTile = () => {
  emit('place', props.position);
};
</script>

<style scoped>
.tile {
  position: absolute;
  width: 100px;
  height: 100px;
  transition: transform 0.3s, box-shadow 0.3s;
  background-color: #1a1a2e;
  border: 1px solid #444;
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
}

.corridor {
  background-color: #1a1a2e;
}

.room {
  background-color: #2a2a40; 
}

.tile-content {
  position: relative;
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.tile-orientation {
  font-size: 24px;
  color: rgba(255, 255, 255, 0.7);
}

.room-symbol {
  /* Removed color to match regular tile orientation colors */
}

/* Openings */
.tile-openings {
  position: absolute;
  width: 100%;
  height: 100%;
  top: 0;
  left: 0;
  pointer-events: none;
}

.opening {
  position: absolute;
  background-color: #333;
  z-index: 2;
}

.opening.top {
  top: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 30%;
  height: 10px;
  border-bottom-left-radius: 4px;
  border-bottom-right-radius: 4px;
}

.opening.right {
  top: 50%;
  right: 0;
  transform: translateY(-50%);
  height: 30%;
  width: 10px;
  border-top-left-radius: 4px;
  border-bottom-left-radius: 4px;
}

.opening.bottom {
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 30%;
  height: 10px;
  border-top-left-radius: 4px;
  border-top-right-radius: 4px;
}

.opening.left {
  top: 50%;
  left: 0;
  transform: translateY(-50%);
  height: 30%;
  width: 10px;
  border-top-right-radius: 4px;
  border-bottom-right-radius: 4px;
}

.room-opening {
  /*background-color: #423426;*/
}

/* Ghost tile specific styles */
.ghost-tile {
  opacity: 0.85;
  border: 2px dashed #4CAF50;
  animation: ghostPulse 2s infinite;
  pointer-events: auto;
  cursor: pointer;
  box-shadow: 0 0 10px rgba(76, 175, 80, 0.5);
}

.ghost-tile.room {
  border: 2px dashed #ffb700;
  box-shadow: 0 0 10px rgba(255, 183, 0, 0.5);
}

.ghost-tile:hover {
  opacity: 1;
  transform: scale(1.02);
  box-shadow: 0 0 15px rgba(76, 175, 80, 0.7);
}

@keyframes ghostPulse {
  0% { opacity: 0.7; }
  50% { opacity: 1; }
  100% { opacity: 0.7; }
}

/* Ghost tile controls */
.ghost-tile-controls {
  position: absolute;
  z-index: 100;
  display: flex;
  gap: 10px;
  pointer-events: auto;
  width: 100%;
  bottom: -40px;
  left: 0;
  justify-content: center;
}

.ghost-rotate-btn {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  border: none;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  cursor: pointer;
  transition: transform 0.2s, background-color 0.2s;
  background-color: #4CAF50;
  color: white;
  box-shadow: 0 0 8px rgba(0, 0, 0, 0.5);
  pointer-events: auto;
}

.ghost-rotate-btn:hover {
  transform: scale(1.1);
  filter: brightness(1.2);
}
</style> 