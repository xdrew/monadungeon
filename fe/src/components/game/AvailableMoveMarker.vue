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
      zIndex: '55',
      '--phase': `${-(((x * 7 + y * 13) % 10 + 10) % 10) * 0.45}s`
    }"
    :title="`Available position: ${position} - ${canPlaceTile ? 'Can place tile and move' : 'Can move'}`"
    @click="handleClick"
  >
    <div class="marker-content">
      <!-- an unexplored doorway: darkness and drifting mist, a dim compass (e2e clicks .place-icon) -->
      <span
        v-if="canPlaceTile"
        class="unexplored-mist"
        aria-hidden="true"
      />
      <span
        v-if="canPlaceTile"
        class="place-icon"
      >
        <GameIcon name="compass" :size="30" />
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

/* An unexplored doorway: the dark beyond the door — a faint ember edge that breathes like a far torch,
   mist drifting inside; neighbours breathe out of step (--phase from the position).
   Only opacity and transform are animated (a glow layer fades in and out), so it runs on the compositor. */
.move-marker.place-tile {
  overflow: hidden;
  border: 1px solid rgba(var(--ember-rgb), 0.14);
  background-color: rgba(5, 3, 2, 0.72);
  box-shadow: inset 0 0 40px rgba(0, 0, 0, 0.85);
}

.move-marker.place-tile::after {
  content: '';
  position: absolute;
  inset: -1px;
  pointer-events: none;
  border: 1px solid rgba(var(--ember-rgb), 0.4);
  box-shadow: inset 0 0 26px rgba(var(--ember-rgb), 0.16);
  opacity: 0;
  animation: farTorch 4.5s ease-in-out infinite;
  animation-delay: var(--phase, 0s);
  will-change: opacity;
}

@keyframes farTorch {
  0%, 100% { opacity: 0; }
  50% { opacity: 1; }
}

.unexplored-mist {
  position: absolute;
  inset: -30%;
  pointer-events: none;
  background:
    radial-gradient(ellipse 40% 30% at 35% 45%, rgba(234, 220, 192, 0.11) 0%, rgba(234, 220, 192, 0) 70%),
    radial-gradient(ellipse 35% 25% at 65% 60%, rgba(234, 220, 192, 0.09) 0%, rgba(234, 220, 192, 0) 70%),
    radial-gradient(ellipse 30% 40% at 50% 30%, rgba(var(--ember-rgb), 0.05) 0%, rgba(var(--ember-rgb), 0) 70%);
  animation: mistDrift 7s ease-in-out infinite alternate;
  animation-delay: var(--phase, 0s);
  will-change: opacity, transform;
}

@keyframes mistDrift {
  0% { transform: translate(-6%, 2%) scale(1); opacity: 0.7; }
  50% { transform: translate(4%, -3%) scale(1.08); opacity: 1; }
  100% { transform: translate(-2%, 5%) scale(1.02); opacity: 0.8; }
}

@media (prefers-reduced-motion: reduce) {
  .unexplored-mist,
  .move-marker.place-tile::after {
    animation: none;
  }
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
  position: relative;
  color: rgba(var(--ember-rgb), 0.42);
  filter: drop-shadow(0 2px 3px rgba(0, 0, 0, 0.9));
  transition: color 0.3s ease, transform 0.4s ease;
}

/* the breathing stops under the pointer so the hover light holds */
.move-marker.place-tile.clickable:hover::after {
  animation: none;
  opacity: 0;
}

/* hovering the dark: the compass catches the light and its needle swings */
.available-place.clickable:hover .place-icon {
  color: var(--text-accent);
  transform: rotate(25deg) scale(1.08);
}

@media (prefers-reduced-motion: reduce) {
  .available-place.clickable:hover .place-icon {
    transform: none;
  }
}
</style>
