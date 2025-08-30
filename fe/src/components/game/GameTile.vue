<template>
  <div
    class="tile"
    :class="[
      isRoom ? 'room' : 'corridor', 
      orientationClass,
      { 'starting-tile': isStartingTile },
      { 'has-player': hasPlayer },
      { 'replaying': isReplaying && currentReplayTurn > turnNumber - 1 },
      { 'is-current-action': isCurrentAction },
      { 'tile-placing-animation': isPlacingAnimation },
      { 'current-player-position': isCurrentPlayerPosition },
      { 'has-background-image': tileBackgroundImage }
    ]"
    :style="{
      left: `${(x - minX) * tileSize}px`,
      top: `${(y - minY) * tileSize}px`,
      opacity: 1,
      '--start-x': animationStartX !== undefined ? `${animationStartX}px` : '0px',
      '--start-y': animationStartY !== undefined ? `${animationStartY}px` : '0px',
      '--end-x': `${(x - minX) * tileSize}px`,
      '--end-y': `${(y - minY) * tileSize}px`
    }"
    :data-x="x"
    :data-y="y"
    :data-position="position"
    @mouseenter="onTileMouseEnter"
    @mouseleave="onTileMouseLeave"
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
        :title="`Position: ${position} - Orientation: ${orientationChar} - Class: ${orientationClass}`" 
        :class="{ 'room-symbol': isRoom }"
      >
        {{ orientationSymbol }}
      </div>
      <span
        v-if="hasItem"
        class="tile-items" 
        :class="{ 
          'has-guard': item && !item.guardDefeated && item.guardHP > 0,
          'is-reward': item && (item.guardDefeated || item.guardHP === 0),
          'pickable': isItemPickable,
          'has-monster-image': monsterImage && !item.guardDefeated && item.guardHP > 0,
          'has-chest-image': chestImage
        }" 
        :title="itemTooltip"
        @click="onItemClick"
      >
        <img 
          v-if="monsterImage && item && !item.guardDefeated && item.guardHP > 0"
          :src="monsterImage"
          :alt="item.name"
          class="monster-image"
        />
        <img
          v-else-if="chestImage"
          :src="chestImage"
          :alt="item.type"
          class="chest-image"
        />
        <img
          v-else-if="weaponImage"
          :src="weaponImage"
          :alt="item.type"
          class="weapon-image"
        />
        <span v-else>{{ itemEmoji }}</span>
        <span
          v-if="item && !item.guardDefeated && item.guardHP > 0"
          class="monster-hp"
        >
          {{ item.guardHP }}
        </span>
        <span
          v-else-if="isItemPickable && item && ['dagger', 'sword', 'axe', 'fireball'].includes(item.type)"
          class="weapon-damage"
        >
          +{{ getItemDamage(item) }}
        </span>
      </span>
      <div
        v-if="hasPlayer"
        class="player-indicator"
      >
        <span 
          v-for="(player, index) in allPlayerEmojis" 
          :key="player.playerId"
          class="player-emoji"
          :style="{ left: `${index * 8}px` }"
          :title="`Player ${player.playerId}`"
        >
          <img 
            v-if="isCurrentPlayerPosition && index === 0"
            src="/images/player.webp" 
            alt="Current Player" 
            class="player-image"
          />
          <img 
            v-else-if="isAIPlayer(player.playerId)"
            src="/images/ai.webp" 
            alt="AI Player" 
            class="ai-player-image"
          />
          <span v-else>{{ player.emoji }}</span>
        </span>
      </div>
      <div
        v-if="hasHealingFountain"
        class="healing-fountain-indicator"
        title="Healing Fountain - Restores HP when you enter this tile"
      >
        <img src="/images/hf.webp" alt="Healing Fountain" class="healing-fountain-image" />
      </div>
      <div
        v-if="hasTeleportationGate"
        class="teleportation-gate-indicator"
        title="Teleportation Gate - Travel to other portal tiles"
      >
        <img src="/images/portal.webp" alt="Portal" class="portal-image" />
      </div>
      <div
        v-if="isHighlighted"
        class="tile-coordinates"
      >
        ({{ x }},{{ y }})
        <button 
          class="center-view-button" 
          title="Center view on this tile"
          @click.stop="centerView"
        >
          🔍
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
import { defineProps, defineEmits, computed, ref, onMounted } from 'vue';
import { getPlayerEmoji } from '@/utils/playerUtils';
import { getItemDamage } from '@/utils/itemUtils';
import { getMonsterImage } from '@/utils/monsterUtils';
import { getTileImageWithRotation } from '@/config/tileImageConfig';

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
  orientationChar: {
    type: String,
    required: true
  },
  orientationClass: {
    type: String,
    required: true
  },
  orientationSymbol: {
    type: String,
    required: true
  },
  isRoom: {
    type: Boolean,
    default: false
  },
  isStartingTile: {
    type: Boolean,
    default: false
  },
  hasPlayer: {
    type: Boolean,
    default: false
  },
  playerId: {
    type: String,
    default: ''
  },
  allPlayerIds: {
    type: Array,
    default: () => []
  },
  hasItem: {
    type: Boolean,
    default: false
  },
  item: {
    type: Object,
    default: null
  },
  itemEmoji: {
    type: String,
    default: ''
  },
  itemTooltip: {
    type: String,
    default: ''
  },
  playerEmoji: {
    type: String,
    default: ''
  },
  isReplaying: {
    type: Boolean,
    default: false
  },
  currentReplayTurn: {
    type: Number,
    default: 0
  },
  turnNumber: {
    type: Number,
    default: 0
  },
  isCurrentAction: {
    type: Boolean,
    default: false
  },
  isPlacingAnimation: {
    type: Boolean,
    default: false
  },
  isCurrentPlayerPosition: {
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
  animationStartX: {
    type: Number,
    default: undefined
  },
  animationStartY: {
    type: Number,
    default: undefined
  },
  isHighlighted: {
    type: Boolean,
    default: false
  },
  openings: {
    type: Object,
    default: () => ({
      top: false,
      right: false,
      bottom: false,
      left: false
    })
  },
  hasHealingFountain: {
    type: Boolean,
    default: false
  },
  hasTeleportationGate: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['highlight', 'unhighlight', 'center-view', 'item-click']);

// Tile background image and rotation
const tileBackgroundImage = ref(null);
const tileRotation = ref(0);

// Load tile image on mount
onMounted(() => {
  // Get the base image and rotation for this tile
  const imageConfig = getTileImageWithRotation(props.orientationChar, props.isRoom);
  
  if (imageConfig) {
    // Directly set the image since we know our tile images exist
    tileBackgroundImage.value = imageConfig.image;
    tileRotation.value = imageConfig.rotation;
  }
});

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
  
  // Apply rotation to the background image
  if (tileRotation.value !== 0) {
    // Use CSS transform on a pseudo-element or inner div for the background rotation
    style.transform = `rotate(${tileRotation.value}deg)`;
    // Ensure the rotated background fills the tile
    style.transformOrigin = 'center center';
  }
  
  return style;
});

// Helper function to check if player is AI
const isAIPlayer = (playerId) => {
  const virtualPlayerId = typeof localStorage !== 'undefined' ? localStorage.getItem('virtualPlayerId') : null;
  return virtualPlayerId && playerId === virtualPlayerId;
};

// Computed properties for openings
const hasTopOpening = computed(() => props.openings.top);
const hasRightOpening = computed(() => props.openings.right);
const hasBottomOpening = computed(() => props.openings.bottom);
const hasLeftOpening = computed(() => props.openings.left);

// Computed property to determine if the item can be picked up
const isItemPickable = computed(() => {
  return props.hasItem && 
         props.item && 
         (props.item.guardDefeated || props.item.guardHP === 0);
});

// Computed property to get emojis for all players at this position
const allPlayerEmojis = computed(() => {
  if (!props.allPlayerIds || props.allPlayerIds.length === 0) {
    // Fallback to single player for backward compatibility
    return props.playerId ? [{ playerId: props.playerId, emoji: props.playerEmoji }] : [];
  }
  
  return props.allPlayerIds.map(playerId => ({
    playerId,
    emoji: getPlayerEmoji(playerId)
  }));
});

// Computed property to get monster image
const monsterImage = computed(() => {
  if (!props.item) return null;
  
  // Create a battle-like object for the getMonsterImage function
  const battleInfo = {
    monster_name: props.item.name,
    monster: props.item.guardHP || 0
  };
  
  return getMonsterImage(battleInfo);
});

// Computed property to get chest image
const chestImage = computed(() => {
  if (!props.item) return null;
  
  // Check if it's a chest type item and guard is defeated or no guard
  const isChest = ['chest', 'ruby_chest'].includes(props.item.type);
  const guardDefeated = props.item.guardDefeated || props.item.guardHP === 0;
  
  if (isChest && guardDefeated) {
    // Determine which chest image to use
    if (props.item.type === 'ruby_chest') {
      return '/images/ruby-chest.webp';
    } else if (props.item.type === 'chest') {
      // Chests on the field are always closed (they disappear when opened)
      return '/images/chest-closed.webp';
    }
  }
  
  return null;
});

// Computed property to get weapon image
const weaponImage = computed(() => {
  if (!props.item) return null;
  
  // Check if guard is defeated and it's a weapon
  const guardDefeated = props.item.guardDefeated || props.item.guardHP === 0;
  
  if (guardDefeated && props.item.type) {
    switch (props.item.type) {
      case 'key':
        return '/images/key.webp';
      case 'dagger':
        return '/images/dagger.webp';
      case 'sword':
        return '/images/sword.webp';
      case 'axe':
        return '/images/axe.webp';
      case 'fireball':
        return '/images/fireball.webp';
      case 'teleport':
        return '/images/hf-teleport.webp';
    }
  }
  
  return null;
});

// Functions
const onTileMouseEnter = () => {
  emit('highlight', {
    x: props.x,
    y: props.y,
    position: props.position
  });
};

const onTileMouseLeave = () => {
  emit('unhighlight');
};

const centerView = () => {
  emit('center-view', {
    x: props.x,
    y: props.y
  });
};

// New function to handle item clicks
const onItemClick = () => {
  if (!isItemPickable.value) return;
  
  emit('item-click', {
    position: props.position,
    item: props.item
  });
};
</script>

<style scoped>
.tile {
  position: absolute;
  width: 100px;
  height: 100px;
  transition: transform 0.3s, box-shadow 0.3s;
  background-color: #1a1a2e;
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
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  font-size: 24px;
  color: rgba(255, 255, 255, 0.5);
  z-index: 1;
  pointer-events: none;
}

/* Hide orientation symbols when tile has background image */
.tile.has-background-image .tile-orientation {
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
.tile.has-background-image .tile-content {
  position: relative;
  z-index: 1;
}

.room-symbol {
  /* Removed color to match regular tile orientation colors */
}

.starting-tile {
  //box-shadow: 0 0 10px rgba(76, 175, 80, 0.8);
  z-index: 2;
}

.has-player .tile-content::after {
  /* Removing the circle around player positions */
  display: none;
}

/* Enhance the player indicator instead to make it more visible */
.player-indicator {
  position: absolute;
  top: 10px;
  left: 5px;
  font-size: 24px;
  z-index: 5;
  filter: drop-shadow(0 0 4px rgba(0, 0, 0, 0.8));
}

.player-emoji {
  position: absolute;
  top: 10px;
  font-size: 24px;
  filter: drop-shadow(0 0 4px rgba(0, 0, 0, 0.8));
}

.player-image {
  width: 45px;
  height: 45px;
  object-fit: contain;
  display: block;
  filter: drop-shadow(0 0 8px rgba(0, 255, 0, 0.8)) 
          drop-shadow(0 0 15px rgba(255, 255, 255, 0.6));
  animation: playerGlow 2s ease-in-out infinite;
}

@keyframes playerGlow {
  0%, 100% {
    filter: drop-shadow(0 0 8px rgba(0, 255, 0, 0.8)) 
            drop-shadow(0 0 15px rgba(255, 255, 255, 0.6));
  }
  50% {
    filter: drop-shadow(0 0 12px rgba(0, 255, 0, 1)) 
            drop-shadow(0 0 25px rgba(255, 255, 255, 0.9))
            drop-shadow(0 0 35px rgba(0, 255, 0, 0.4));
  }
}

.ai-player-image {
  width: 45px;
  height: 45px;
  object-fit: contain;
  display: block;
  filter: drop-shadow(0 0 8px rgba(0, 150, 255, 0.8)) 
          drop-shadow(0 0 15px rgba(255, 255, 255, 0.6));
  animation: aiGlow 2s ease-in-out infinite;
}

@keyframes aiGlow {
  0%, 100% {
    filter: drop-shadow(0 0 8px rgba(0, 150, 255, 0.8)) 
            drop-shadow(0 0 15px rgba(255, 255, 255, 0.6));
  }
  50% {
    filter: drop-shadow(0 0 12px rgba(0, 150, 255, 1)) 
            drop-shadow(0 0 25px rgba(255, 255, 255, 0.9))
            drop-shadow(0 0 35px rgba(0, 150, 255, 0.4));
  }
}

/* Keep the glow effect for current player position */
.current-player-position {
  //box-shadow: 0 0 20px 10px rgba(255, 255, 255, 0.9);
  z-index: 10;
  //background: radial-gradient(circle at center, rgba(255, 255, 255, 0.8) 0%, rgba(255, 255, 255, 0.3) 70%);
}

.tile-items {
  position: absolute;
  font-size: 24px;
  z-index: 3;
  bottom: 15px;
  right: 15px;
}

.tile-items.has-guard {
  color: #ff5252;
}

.tile-items.is-reward {
  color: #ffeb3b;
}

.tile-items.pickable {
  cursor: pointer;
  animation: pulse 1.5s infinite;
}

@keyframes pulse {
  0% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.1);
  }
  100% {
    transform: scale(1);
  }
}

.tile-coordinates {
  position: absolute;
  top: -30px;
  left: 0;
  width: 100%;
  text-align: center;
  background-color: rgba(0, 0, 0, 0.7);
  color: white;
  padding: 3px;
  border-radius: 4px;
  font-size: 12px;
  z-index: 100;
}

.center-view-button {
  background: none;
  border: none;
  color: white;
  cursor: pointer;
  padding: 2px 5px;
  margin-left: 5px;
  border-radius: 3px;
  background-color: rgba(0, 0, 0, 0.3);
}

.center-view-button:hover {
  background-color: rgba(76, 175, 80, 0.5);
}

/* Tile openings */
.tile-openings {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  z-index: 1; /* Above background but below items */
}

.opening {
  position: absolute;
  background-color: transparent;
  z-index: 2;
  display: none; /* Hide openings since we use background images */
}

/* Hide openings when tile has background image */
.tile.has-background-image .opening {
  display: none;
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
  /* background-color: #423426; */
}

/* Animation for tile placement */
.tile-placing-animation {
  animation: placeTile 0.8s ease-out;
  z-index: 15;
}

@keyframes placeTile {
  0% {
    transform: translate(calc(var(--start-x) - var(--end-x)), calc(var(--start-y) - var(--end-y))) scale(0.3);
    opacity: 0.3;
  }
  70% {
    opacity: 1;
  }
  100% {
    transform: translate(0, 0) scale(1);
  }
}

/* Highlight styles for current action in replay */
.is-current-action {
  box-shadow: 0 0 20px rgba(76, 175, 80, 1), 0 0 40px rgba(76, 175, 80, 0.5);
  z-index: 10;
}

.tile.replaying {
  z-index: 5;
  box-shadow: 0 0 8px rgba(230, 126, 34, 0.5);
}

.tile.is-current-action.tile-placing-animation {
  box-shadow: 0 0 15px 5px rgba(255, 215, 0, 0.8), 0 0 30px rgba(255, 255, 255, 0.5);
}

/* Add trail effect for tile placement animation */
.tile.tile-placing-animation::before {
  content: '';
  position: absolute;
  width: 100%;
  height: 100%;
  top: 0;
  left: 0;
  background: radial-gradient(circle, rgba(255,255,255,0.4) 0%, rgba(255,255,255,0) 70%);
  pointer-events: none;
  z-index: -1;
  opacity: 0.8;
  animation: trailFade 0.8s ease-out forwards;
}

@keyframes trailFade {
  0% {
    transform: scale(1.2);
    opacity: 0.7;
  }
  60% {
    opacity: 0.5;
  }
  100% {
    transform: scale(3);
    opacity: 0;
  }
}

.monster-hp {
  position: absolute;
  bottom: -8px;
  right: -8px;
  background-color: rgba(255, 82, 82, 0.9);
  color: white;
  font-size: 12px;
  font-weight: bold;
  padding: 2px 4px;
  border-radius: 6px;
  border: 1px solid #ff0000;
  min-width: 16px;
  text-align: center;
  box-shadow: 0 0 3px rgba(0, 0, 0, 0.5);
  z-index: 1;
}

.chest-image {
  width: 32px;
  height: 32px;
  object-fit: contain;
}

.monster-image {
  width: 32px;
  height: 32px;
  object-fit: contain;
}

.weapon-image {
  width: 32px;
  height: 32px;
  object-fit: contain;
}

.weapon-damage {
  position: absolute;
  bottom: -8px;
  right: -8px;
  background-color: rgba(255, 153, 0, 0.9);
  color: white;
  font-size: 12px;
  font-weight: bold;
  padding: 2px 4px;
  border-radius: 6px;
  border: 1px solid #ff9900;
  min-width: 16px;
  text-align: center;
  box-shadow: 0 0 3px rgba(0, 0, 0, 0.5);
  z-index: 1;
}

.healing-fountain-indicator {
  position: absolute;
  top: 25px;
  left: 33px;
  font-size: 20px;
  z-index: 4;
  filter: drop-shadow(0 0 4px rgba(64, 224, 208, 0.8));
  animation: fountain-glow 2s ease-in-out infinite;
}

.healing-fountain-image {
  width: 36px;
  height: 36px;
  object-fit: contain;
}

.teleportation-gate-indicator {
  position: absolute;
  top: 30px;
  left: 33px;
  font-size: 20px;
  z-index: 4;
  filter: drop-shadow(0 0 4px rgba(138, 43, 226, 0.8));
}

.portal-image {
  width: 36px;
  height: 36px;
  object-fit: contain;
}

@keyframes portal-spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}

@keyframes fountain-glow {
  0%, 100% {
    filter: drop-shadow(0 0 4px rgba(64, 224, 208, 0.6));
    transform: scale(1);
  }
  50% {
    filter: drop-shadow(0 0 8px rgba(64, 224, 208, 1));
    transform: scale(1.05);
  }
}
</style> 