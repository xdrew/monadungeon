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
      <!-- an unexplored doorway: a dark slot with the explore mark (e2e clicks .place-icon) -->
      <span
        v-if="canPlaceTile"
        class="place-icon"
      >
        <GameIcon name="explore" :size="34" />
      </span>
    </div>
  </div>
</template>

<script setup>
import { TILE_SIZE } from '@/utils/fieldUtils';
import { defineProps, defineEmits } from 'vue';
import GameIcon from '@/components/ui/GameIcon.vue';

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
    default: TILE_SIZE
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
  width: var(--tile-size, 150px);
  height: var(--tile-size, 150px);
  display: flex;
  justify-content: center;
  align-items: center;
  pointer-events: none;
  z-index: 55 !important;
}

/* Where the hero can step: an ember frame glowing inwards (canvas "reach" marks) */
.move-marker {
  box-sizing: border-box;
  border: 2px solid rgba(var(--ember-rgb), 0.55);
  box-shadow: inset 0 0 24px rgba(255, 120, 30, 0.35);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

/* An unexplored doorway: a dark slot with a dashed ember hairline */
.move-marker.place-tile {
  border: 2px dashed rgba(var(--ember-rgb), 0.6);
  background-color: rgba(7, 5, 4, 0.55);
  box-shadow: inset 0 0 30px rgba(0, 0, 0, 0.7);
}

.available-place.clickable {
  pointer-events: auto !important;
  cursor: pointer;
}

.available-place.clickable:hover {
  border-color: rgba(var(--ember-rgb), 0.95);
  box-shadow: inset 0 0 30px rgba(255, 120, 30, 0.5), 0 0 16px rgba(var(--ember-rgb), 0.35);
  z-index: 60 !important;
}

.available-place.disabled {
  border-color: var(--iron-dark);
  box-shadow: none;
  opacity: 0.4;
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

.place-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: rgba(var(--accent-light-rgb), 0.85);
  filter: drop-shadow(0 2px 3px rgba(0, 0, 0, 0.9));
  transition: color 0.2s ease, transform 0.2s ease;
}

.available-place.clickable:hover .place-icon {
  color: var(--text-heading);
  transform: scale(1.1);
}

@media (prefers-reduced-motion: reduce) {
  .available-place.clickable:hover .place-icon {
    transform: none;
  }
}
</style>
