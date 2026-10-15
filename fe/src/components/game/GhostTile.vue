<template>
  <div
    class="tile ghost-tile"
    :class="[
      isRoom ? 'room' : 'corridor',
      orientationClass,
      { 'has-background-image': tileBackgroundImage }
    ]"
    :style="{
      left: `${(parseInt(position.split(',')[0]) - minX) * tileSize}px`,
      top: `${(parseInt(position.split(',')[1]) - minY) * tileSize}px`,
      zIndex: '50'
    }"
    :title="'Click to place tile (Enter)'"
    @click="placeTile"
  >
    <!-- Rotated background image layer -->
    <div 
      v-if="tileBackgroundImage"
      class="tile-background-layer"
      :style="tileBackgroundStyle"
    />
    
    <div
      class="tile-content"
      :class="{ 'room-content': isRoom }"
    >
      <div
        class="tile-orientation"
        :class="{ 'room-symbol': isRoom }"
      >
        {{ displayOrientationSymbol }}
      </div>
      <div
        v-if="isPlacingTile"
        class="ghost-tile-controls"
      >
        <button
          class="ghost-rotate-btn btn btn--iron btn--sm"
          title="Rotate tile (R)"
          aria-label="Rotate tile"
          @click.stop="rotateGhostTile"
        >
          <GameIcon name="rotate" :size="18" />
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
import { TILE_SIZE } from '@/utils/fieldUtils';
import { defineProps, defineEmits, computed, watch, ref, onMounted, onUpdated } from 'vue';
import GameIcon from '@/components/ui/GameIcon.vue';
import { getTileImageWithRotation } from '@/config/tileImageConfig';

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
    default: TILE_SIZE
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

// Tile background image and rotation
const tileBackgroundImage = ref(null);
const tileRotation = ref(0);

// Load tile image
const loadTileImage = async () => {
  if (props.orientationSymbol) {
    const imageConfig = getTileImageWithRotation(props.orientationSymbol, props.isRoom);
    
    if (imageConfig) {
      // Check if the image exists
      try {
        const response = await fetch(imageConfig.image, { method: 'HEAD' });
        if (response.ok) {
          tileBackgroundImage.value = imageConfig.image;
          tileRotation.value = imageConfig.rotation;
        }
      } catch (error) {
        console.error('Failed to load ghost tile image:', imageConfig.image, error);
      }
    }
  }
};

// Load image on mount and when orientation changes
onMounted(loadTileImage);
onUpdated(loadTileImage);

// Computed property for background style
const tileBackgroundStyle = computed(() => {
  if (!tileBackgroundImage.value) {
    return {};
  }
  
  const style = {
    backgroundImage: `url(${tileBackgroundImage.value})`,
    backgroundSize: 'cover',
    backgroundPosition: 'center',
    backgroundRepeat: 'no-repeat'
  };
  
  // Apply rotation to the background
  if (tileRotation.value !== 0) {
    style.transform = `rotate(${tileRotation.value}deg)`;
    style.transformOrigin = 'center center';
  }
  
  return style;
});

// Computed property for orientation symbol to ensure reactivity
const displayOrientationSymbol = computed(() => {
  console.log('GhostTile orientationSymbol:', props.orientationSymbol);
  return props.orientationSymbol;
});

// Watch for prop changes to debug
watch(() => props.orientationSymbol, (newVal, oldVal) => {
  console.log('GhostTile: orientationSymbol prop changed', { 
    from: oldVal, 
    to: newVal,
    orientation: props.orientation,
    orientationClass: props.orientationClass
  });
});

watch(() => props.orientation, (newVal, oldVal) => {
  console.log('GhostTile: orientation prop changed', { from: oldVal, to: newVal });
});

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
  width: var(--tile-size);
  height: var(--tile-size);
  transition: transform 0.3s, box-shadow 0.3s;
  background-color: var(--bg-modal);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
}

.corridor {
  background-color: var(--bg-modal);
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

/* Hide orientation symbol when tile has background image */
.ghost-tile.has-background-image .tile-orientation {
  display: none;
}

/* Background layer for rotated images */
.tile-background-layer {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  z-index: 0;
  pointer-events: none;
}

/* Ensure content is above background */
.ghost-tile.has-background-image .tile-content {
  position: relative;
  z-index: 1;
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
  background-color: transparent;
  z-index: 2;
  display: none; /* Hide openings since we use background images */
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

/* Ghost tile: the tile about to be placed, framed in ember */
.ghost-tile {
  opacity: 0.88;
  animation: ghostPulse 2s infinite;
  pointer-events: auto;
  cursor: pointer;
  box-sizing: border-box;
  border: 2px solid rgba(var(--ember-rgb), 0.75);
  box-shadow: 0 0 18px rgba(var(--ember-rgb), 0.4), inset 0 0 18px rgba(var(--ember-rgb), 0.2);
  background-size: cover !important;
  background-position: center !important;
  background-repeat: no-repeat !important;
}

.ghost-tile:hover {
  opacity: 1;
  border-color: var(--color-primary-hover);
  box-shadow: 0 0 24px rgba(var(--ember-rgb), 0.55), inset 0 0 18px rgba(var(--ember-rgb), 0.28);
}

@keyframes ghostPulse {
  0%, 100% { opacity: 0.75; }
  50% { opacity: 1; }
}

@media (prefers-reduced-motion: reduce) {
  .ghost-tile {
    animation: none;
  }
}

/* Ghost tile controls: a small iron ring under the tile (the ring itself is .btn--iron) */
.ghost-tile-controls {
  position: absolute;
  z-index: 100;
  display: flex;
  pointer-events: auto;
  bottom: -54px;
  left: 50%;
  transform: translateX(-50%);
  justify-content: center;
}

.ghost-rotate-btn {
  pointer-events: auto;
}
</style> 