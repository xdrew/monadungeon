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
        class="move-arrow"
        :class="arrowDirection"
      />
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
  x: {
    type: Number,
    required: true
  },
  y: {
    type: Number,
    required: true
  },
  playerX: {
    type: Number,
    default: null
  },
  playerY: {
    type: Number,
    default: null
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

const arrowDirection = computed(() => {
  if (props.playerX === null || props.playerY === null) return 'arrow-down';
  const dx = props.x - props.playerX;
  const dy = props.y - props.playerY;
  if (Math.abs(dx) > Math.abs(dy)) {
    return dx > 0 ? 'arrow-right' : 'arrow-left';
  }
  return dy > 0 ? 'arrow-down' : 'arrow-up';
});

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
  border-radius: 10px;
  background-color: rgba(123, 63, 242, 0.15);
  border: 2px solid rgba(123, 63, 242, 0.35);
  animation: markerPulse 2s infinite ease-in-out;
}

.move-marker.place-tile {
  background-color: rgba(50, 205, 50, 0.3);
  border-color: rgba(50, 205, 50, 0.5);
  animation: pulse-place 2s infinite;
}

@keyframes markerPulse {
  0%, 100% { opacity: 0.7; border-color: rgba(123, 63, 242, 0.3); }
  50% { opacity: 1; border-color: rgba(123, 63, 242, 0.6); }
}

@keyframes pulse-place {
  0%, 100% { transform: scale(0.95); opacity: 0.9; }
  50% { transform: scale(1.05); opacity: 1; }
}

.available-place.clickable {
  pointer-events: auto !important;
  cursor: pointer;
}

.available-place.clickable:hover {
  background-color: rgba(123, 63, 242, 0.3);
  border-color: rgba(167, 139, 250, 0.8);
  box-shadow: 0 0 18px rgba(123, 63, 242, 0.5);
  z-index: 60 !important;
}

.available-place.clickable:hover .move-arrow {
  transform: var(--arrow-hover-transform, scale(1.2));
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
}

/* Arrow indicator */
.move-arrow {
  width: 28px;
  height: 28px;
  position: relative;
  transition: transform 0.2s ease;
}

.move-arrow::before {
  content: "";
  position: absolute;
  width: 0;
  height: 0;
  filter: drop-shadow(0 0 4px rgba(123, 63, 242, 0.6));
}

/* Arrow pointing up */
.move-arrow.arrow-up::before {
  border-left: 12px solid transparent;
  border-right: 12px solid transparent;
  border-bottom: 18px solid rgba(167, 139, 250, 0.85);
  top: 2px;
  left: 2px;
}
.move-arrow.arrow-up { --arrow-hover-transform: scale(1.2) translateY(-3px); }

/* Arrow pointing down */
.move-arrow.arrow-down::before {
  border-left: 12px solid transparent;
  border-right: 12px solid transparent;
  border-top: 18px solid rgba(167, 139, 250, 0.85);
  bottom: 2px;
  left: 2px;
}
.move-arrow.arrow-down { --arrow-hover-transform: scale(1.2) translateY(3px); }

/* Arrow pointing left */
.move-arrow.arrow-left::before {
  border-top: 12px solid transparent;
  border-bottom: 12px solid transparent;
  border-right: 18px solid rgba(167, 139, 250, 0.85);
  left: 2px;
  top: 2px;
}
.move-arrow.arrow-left { --arrow-hover-transform: scale(1.2) translateX(-3px); }

/* Arrow pointing right */
.move-arrow.arrow-right::before {
  border-top: 12px solid transparent;
  border-bottom: 12px solid transparent;
  border-left: 18px solid rgba(167, 139, 250, 0.85);
  right: 2px;
  top: 2px;
}
.move-arrow.arrow-right { --arrow-hover-transform: scale(1.2) translateX(3px); }

/* Place tile icon (unchanged) */
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
</style>
