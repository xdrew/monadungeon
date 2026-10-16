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
          'has-chest-image': chestImage,
          'is-boss': isBoss
        }" 
        :title="itemTooltip"
        @click="onItemClick"
      >
        <ThemedImage
          v-if="monsterImage && item && !item.guardDefeated && item.guardHP > 0"
          :src="monsterImage"
          :emoji="monsterEmoji"
          :alt="formatItemName(item.name)"
          img-class="monster-image"
          emoji-class="monster-emoji"
          fallback-icon="skull"
          :fallback-icon-size="isBoss ? 72 : 56"
        />
        <img
          v-else-if="chestImage"
          :src="chestImage"
          :alt="formatItemName(item.type)"
          class="chest-image"
        />
        <!-- a locked chest says so: it opens only with a key -->
        <span
          v-if="chestImage && item && item.locked !== false && (item.guardDefeated || item.guardHP === 0)"
          class="chest-lock"
          :title="$t('field.chestLocked')"
          data-testid="chest-lock"
        ><GameIcon
          name="key"
          :size="14"
        /></span>
        <img
          v-else-if="weaponImage"
          :src="weaponImage"
          :alt="formatItemName(item.type)"
          class="weapon-image"
          :class="`weapon-image--${item.type}`"
        />
        <GameIcon
          v-else
          :name="itemIconName"
          :size="40"
          class="token-icon"
        />
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
      <!-- Hero standees: fanned out across the tile, a ground mark for you (blue) and the acting hero (ember) -->
      <div
        v-if="hasPlayer"
        class="player-indicator"
        :class="`standees-${Math.min(allPlayerEmojis.length, 5)}`"
      >
        <span
          v-for="(player, index) in allPlayerEmojis"
          :key="player.playerId"
          class="player-emoji hero-standee"
          :class="{
            'swap-target': swapTargetable && !isCurrentUser(player.playerId),
            'is-you': isCurrentUser(player.playerId),
            'is-acting': player.playerId === actingPlayerId
          }"
          :style="standeeStyle(index, allPlayerEmojis.length)"
          :title="swapTargetable && !isCurrentUser(player.playerId) ? $t('field.swapTarget') : $t('field.playerTitle', { id: player.playerId })"
          :data-testid="`player-figure-${player.playerId}`"
          @click.stop="onPlayerClick(player.playerId)"
        >
          <!-- hero portrait from the theme when known, else the generic figure -->
          <img
            :src="figureSrc(player.playerId)"
            :alt="isCurrentUser(player.playerId) ? $t('field.currentPlayer') : $t('field.aiPlayer')"
            :class="isCurrentUser(player.playerId) ? 'player-image' : 'ai-player-image'"
            @error="onFigureError($event, player.playerId)"
          />
        </span>
      </div>
      <div
        v-if="hasHealingFountain"
        class="healing-fountain-indicator"
        :title="$t('field.fountainTitle')"
      >
        <img src="/images/hf.webp" :alt="$t('field.fountain')" class="healing-fountain-image" />
      </div>
      <div
        v-if="hasTeleportationGate"
        class="teleportation-gate-indicator"
        :title="$t('field.gateTitle')"
      >
        <img src="/images/portal.webp" :alt="$t('field.gate')" class="portal-image" />
      </div>
      <!-- Karak: Regent — Lord Karak rooms -->
      <div
        v-if="hasBattleArena"
        class="arena-indicator"
        :class="{ 'arena-used': arenaUsed }"
        :title="arenaUsed ? $t('field.arenaUsed') : $t('field.arenaTitle')"
        data-testid="arena-marker"
      >
        <img src="/images/arena.webp" :alt="$t('field.arena')" class="arena-image" />
        <!-- open: crossed swords, a duel waits; used: a check, it is a plain room now -->
        <span
          class="arena-badge"
          :class="arenaUsed ? 'arena-badge--used' : 'arena-badge--open'"
          :data-testid="arenaUsed ? 'arena-used-badge' : 'arena-open-badge'"
          aria-hidden="true"
        >
          <GameIcon
            :name="arenaUsed ? 'check' : 'sword'"
            :size="14"
          />
        </span>
      </div>
      <div
        v-if="hasCursedRoom"
        class="cursed-room-indicator"
        :title="$t('field.cursedRoomTitle')"
        data-testid="cursed-room-marker"
      >
        <img src="/images/cursed-room.webp" :alt="$t('field.cursedRoom')" class="cursed-room-image" />
      </div>
      <div
        v-if="isHighlighted"
        class="tile-coordinates"
      >
        ({{ x }},{{ y }})
        <button
          class="center-view-button btn btn--iron btn--sm"
          :title="$t('field.centerTile')"
          :aria-label="$t('field.centerTile')"
          @click.stop="centerView"
        >
          <GameIcon name="center" :size="16" />
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
import GameIcon from '@/components/ui/GameIcon.vue';
import { defineProps, defineEmits, computed, ref, onMounted } from 'vue';
import { getPlayerEmoji } from '@/utils/playerUtils';
import { getItemDamage, getItemImage, getItemIconName, formatItemName } from '@/utils/itemUtils';
import { getMonsterImage, getMonsterEmoji } from '@/utils/monsterUtils';
import { getHero } from '@/theme';
import ThemedImage from '@/components/ThemedImage.vue';
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
  /** { [playerId]: heroKey|null } — theme hero portraits for the figures */
  playerHeroes: {
    type: Object,
    default: () => ({})
  },
  // Warlock "Magic Swap": the other heroes' figures can be clicked to trade places
  swapTargetable: {
    type: Boolean,
    default: false
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
  /** The hero whose turn it is (ember ground mark) */
  actingPlayerId: {
    type: String,
    default: null
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
    default: TILE_SIZE
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
  },
  // Karak: Regent — Lord Karak rooms
  hasBattleArena: {
    type: Boolean,
    default: false
  },
  hasCursedRoom: {
    type: Boolean,
    default: false
  },
  arenaUsed: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['highlight', 'unhighlight', 'center-view', 'item-click', 'player-click']);

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

// Warlock "Magic Swap": clicking another hero's figure
const onPlayerClick = (playerId) => {
  emit('player-click', { playerId, position: props.position });
};

// Helper function to check if player is current user
const isCurrentUser = (playerId) => {
  const currentPlayerId = typeof localStorage !== 'undefined' ? localStorage.getItem('currentPlayerId') : null;
  return currentPlayerId && playerId === currentPlayerId;
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

// Standee spots on the tile, in % of the tile, by how many heroes stand on it (left, top)
// (the item token sits bottom-right, so the heroes keep to the left and the top)
const STANDEE_SPOTS = {
  1: [[8, 6]],
  2: [[2, 6], [30, 2]],
  3: [[0, 14], [22, 2], [44, 0]],
  4: [[0, 20], [16, 2], [34, 20], [52, 0]],
  5: [[0, 24], [14, 4], [28, 24], [44, 2], [62, 0]]
};

const standeeStyle = (index, count) => {
  const spots = STANDEE_SPOTS[Math.min(Math.max(count, 1), 5)];
  const [left, top] = spots[Math.min(index, spots.length - 1)];
  return { left: `${left}%`, top: `${top}%`, zIndex: 6 + (top > 10 ? 1 : 0) };
};

const defaultFigure = (playerId) => (isCurrentUser(playerId) ? '/images/player.webp' : '/images/ai.webp');

const figureSrc = (playerId) => {
  const heroKey = props.playerHeroes?.[playerId];
  return (heroKey && getHero(heroKey).image) || defaultFigure(playerId);
};

const onFigureError = (event, playerId) => {
  const fallback = defaultFigure(playerId);
  if (event.target.getAttribute('src') !== fallback) {
    console.warn('[theme] hero portrait missing, using default figure:', event.target.getAttribute('src'));
    event.target.src = fallback;
  }
};

// Computed property to get monster image
// The dragon (the monster that ends the game) is drawn larger and lit, so it stands out on the dark floor
const isBoss = computed(() => !!props.item && !props.item.guardDefeated && props.item.guardHP > 0
  && (props.item.endsGame === true || props.item.name === 'dragon'));

const monsterImage = computed(() => {
  if (!props.item) return null;
  
  // Create a battle-like object for the getMonsterImage function
  const battleInfo = {
    monster_name: props.item.name,
    monster: props.item.guardHP || 0
  };
  
  return getMonsterImage(battleInfo);
});

// Emoji passed to ThemedImage (the field shows the skull icon instead when the image is missing)
const monsterEmoji = computed(() => {
  if (!props.item) return '';
  return getMonsterEmoji({ monster_name: props.item.name, monster: props.item.guardHP || 0 });
});

// Line icon for an item without a picture
const itemIconName = computed(() => getItemIconName(props.item));

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

// Picture of a reward lying on the tile (key, weapons, spells — Frost Fist and Dark Thorn included)
const weaponImage = computed(() => {
  if (!props.item) return null;
  const guardDefeated = props.item.guardDefeated || props.item.guardHP === 0;
  if (!guardDefeated || ['chest', 'ruby_chest'].includes(props.item.type)) return null;
  return getItemImage(props.item);
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
.chest-lock {
  position: absolute;
  right: 18%;
  bottom: 14%;
  display: inline-flex;
  padding: 3px;
  border-radius: 50%;
  border: 1px solid rgba(var(--ember-rgb), 0.6);
  background: rgba(12, 7, 5, 0.9);
  color: var(--color-primary);
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.8);
  pointer-events: none;
}

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
  background-color: var(--bg-modal);
}

/* Tiles touch edge to edge; hover: a faint bone hairline inside */
.tile::after {
  content: '';
  position: absolute;
  inset: 0;
  z-index: 9;
  pointer-events: none;
  box-shadow: inset 0 0 0 1px rgba(234, 220, 192, 0);
  transition: box-shadow 0.2s ease;
}

.tile:hover::after {
  box-shadow: inset 0 0 0 1px rgba(234, 220, 192, 0.28);
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
  /* above the fog fringe (z-index 4) that darkens the edge of the explored area: tokens, HP and figures stay readable */
  z-index: 5;
}

.room-symbol {
  /* Removed color to match regular tile orientation colors */
}

.starting-tile {
  //box-shadow: 0 0 10px rgba(var(--accent-rgb), 0.8);
  z-index: 2;
}

.has-player .tile-content::after {
  /* Removing the circle around player positions */
  display: none;
}

/* Hero standees: free figures with a shadow, no glow discs */
.player-indicator {
  position: absolute;
  inset: 0;
  z-index: 6;
  pointer-events: none;
}

.hero-standee {
  position: absolute;
  width: 40%;
  height: 60%;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  pointer-events: none;
}

.standees-4 .hero-standee,
.standees-5 .hero-standee {
  width: 34%;
  height: 52%;
}

.hero-standee img {
  position: relative;
  z-index: 1;
  width: 100%;
  height: 100%;
  object-fit: contain;
  object-position: bottom;
  display: block;
  filter: drop-shadow(0 4px 4px rgba(0, 0, 0, 0.9));
}

/* Ground mark under the feet */
.hero-standee.is-you::after,
.hero-standee.is-acting::after {
  content: '';
  position: absolute;
  left: 12%;
  right: 12%;
  bottom: -4%;
  height: 14%;
  border-radius: 50%;
}

.hero-standee.is-you::after {
  border: 2px solid rgba(217, 192, 138, 0.8);
  background: rgba(217, 192, 138, 0.12);
}

.hero-standee.is-acting::after {
  border: 2px solid rgba(var(--ember-rgb), 0.85);
  background: rgba(var(--ember-rgb), 0.18);
  box-shadow: 0 0 14px rgba(var(--ember-rgb), 0.6);
}

/* Where the action is: the ember ring under the acting hero's feet pulses and sends out
   a thin ripple every couple of seconds. Only transform and opacity are animated (smooth, on the compositor). */
.hero-standee.is-acting::after {
  animation: groundPulse 2.2s ease-in-out infinite;
}

.hero-standee.is-acting::before {
  content: '';
  position: absolute;
  left: 12%;
  right: 12%;
  bottom: -4%;
  height: 14%;
  border-radius: 50%;
  border: 1px solid rgba(var(--ember-rgb), 0.8);
  pointer-events: none;
  animation: groundRipple 2.2s ease-out infinite;
}

@keyframes groundPulse {
  0%, 100% { transform: scale(1); opacity: 0.75; }
  50% { transform: scale(1.08); opacity: 1; }
}

@keyframes groundRipple {
  0% { transform: scale(1); opacity: 0.8; }
  100% { transform: scale(1.9); opacity: 0; }
}

@media (prefers-reduced-motion: reduce) {
  .hero-standee.is-acting::after,
  .hero-standee.is-acting::before {
    animation: none;
  }
}

/* Magic Swap: the heroes you can trade places with */
.hero-standee.swap-target {
  cursor: pointer;
  pointer-events: auto;
  z-index: 8 !important;
}

.hero-standee.swap-target img {
  animation: swapTargetPulse 1s ease-in-out infinite;
}

/* a dashed ember ring at the target's feet: plain to see on a dark tile */
.hero-standee.swap-target::after {
  border: 2px dashed rgba(var(--ember-rgb), 0.95);
  background: rgba(var(--ember-rgb), 0.18);
}

@keyframes swapTargetPulse {
  0%, 100% {
    filter: drop-shadow(0 4px 4px rgba(0, 0, 0, 0.9)) drop-shadow(0 0 6px rgba(var(--ember-rgb), 0.7));
  }
  50% {
    filter: drop-shadow(0 4px 4px rgba(0, 0, 0, 0.9)) drop-shadow(0 0 16px rgba(var(--ember-rgb), 1));
  }
}

@media (prefers-reduced-motion: reduce) {
  .hero-standee.swap-target img {
    animation: none;
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
  /* above the hero standees: the HP number must always read */
  z-index: 8;
  right: 14%;
  bottom: 14%;
  width: 48%;
  height: 48%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--text-primary);
  filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.9));
}

/* A reward lying free: smaller than a guard */
.tile-items.is-reward {
  width: 38%;
  height: 38%;
}

/* A reward the hero can pick up: a pool of torchlight on the floor under it, no frame */
.tile-items.pickable {
  cursor: pointer;
}

.tile-items.pickable::before {
  content: '';
  position: absolute;
  inset: -28%;
  z-index: -1;
  border-radius: 50%;
  background: radial-gradient(circle at 50% 50%, rgba(255, 170, 70, 0.38) 0%, rgba(var(--ember-rgb), 0.14) 40%, rgba(var(--ember-rgb), 0) 70%);
  animation: lightPool 2.4s ease-in-out infinite;
  will-change: opacity, transform;
  pointer-events: none;
}

@keyframes lightPool {
  0%, 100% { opacity: 0.65; transform: scale(0.94); }
  50% { opacity: 1; transform: scale(1.04); }
}

@media (prefers-reduced-motion: reduce) {
  .tile-items.pickable::before {
    animation: none;
  }
}

.tile-coordinates {
  position: absolute;
  top: 6px;
  left: 6px;
  z-index: 100;
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 2px 4px 2px 8px;
  border-radius: var(--radius-sm);
  background: rgba(7, 5, 4, 0.75);
  color: var(--text-secondary);
  font-size: 12px;
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

/* A new room comes out of the dark: light opens from the centre, the stone settles down a little
   and loses its shadow, and a puff of warm dust rises at the edges (≤ 0.8s; the flag is cleared at 1s) */
.tile-placing-animation {
  z-index: 15;
  animation: roomReveal 0.8s cubic-bezier(0.2, 0.7, 0.3, 1) both;
}

@keyframes roomReveal {
  0% {
    clip-path: circle(0% at 50% 50%);
    transform: translateY(-6px) scale(1.04);
    filter: brightness(0.25) sepia(0.4);
  }
  45% {
    clip-path: circle(72% at 50% 50%);
    filter: brightness(0.7) sepia(0.2);
  }
  70% {
    transform: translateY(1px) scale(0.995);
  }
  100% {
    clip-path: circle(75% at 50% 50%);
    transform: translateY(0) scale(1);
    filter: brightness(1) sepia(0);
  }
}

/* Highlight styles for current action in replay */
.is-current-action {
  box-shadow: 0 0 20px rgba(var(--accent-rgb), 1), 0 0 40px rgba(var(--accent-rgb), 0.5);
  z-index: 10;
}

.tile.replaying {
  z-index: 5;
  box-shadow: 0 0 8px rgba(230, 126, 34, 0.5);
}

.tile.is-current-action.tile-placing-animation {
  box-shadow: 0 0 15px 5px rgba(255, 215, 0, 0.8), 0 0 30px rgba(255, 255, 255, 0.5);
}

/* the dust of the opening: a ring of warm haze running out over the stone and fading */
.tile.tile-placing-animation::before {
  content: '';
  position: absolute;
  inset: 0;
  z-index: 2;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(255, 190, 120, 0) 35%, rgba(255, 170, 90, 0.35) 55%, rgba(var(--ember-rgb), 0) 72%);
  pointer-events: none;
  animation: roomDust 0.8s ease-out forwards;
}

@keyframes roomDust {
  0% { transform: scale(0.4); opacity: 0; }
  35% { opacity: 0.9; }
  100% { transform: scale(1.25); opacity: 0; }
}

@media (prefers-reduced-motion: reduce) {
  .tile-placing-animation,
  .tile.tile-placing-animation::before {
    animation: none;
  }
}

.monster-image,
.chest-image,
.weapon-image {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

/* the dagger and the axe fill only half of their art: scale them to read like the other tokens */
.weapon-image--dagger {
  transform: scale(1.45);
}

.weapon-image--axe {
  transform: scale(1.2);
}

.monster-emoji {
  font-size: 40px;
}

/* HP and weapon strength: a number in the display face, glowing like an ember */
.monster-hp,
.weapon-damage {
  position: absolute;
  right: -6px;
  bottom: -4px;
  z-index: 1;
  font-family: var(--font-display);
  line-height: 1;
  pointer-events: none;
}

.monster-hp {
  font-size: 24px;
  color: #ffd9a8;
  text-shadow: 0 0 6px #ff5a1a, 0 2px 2px #000;
}

/* the weapon art is a thin diagonal inside its box: the number sits by the blade, not in the box corner */
.weapon-damage {
  right: 8%;
  bottom: 10%;
  font-size: 20px;
  color: var(--text-heading);
  text-shadow: 0 0 4px rgba(0, 0, 0, 0.9), 0 2px 2px #000;
}

/* Boss: larger, with a fiery outline and a slow glow */
.tile-items.is-boss {
  width: 58%;
  height: 58%;
  right: 10%;
  bottom: 10%;
}

.tile-items.is-boss .monster-image {
  filter: drop-shadow(0 0 6px rgba(255, 176, 48, 0.8)) drop-shadow(0 0 14px rgba(255, 72, 0, 0.6));
  animation: bossGlow 1.8s ease-in-out infinite;
}

.tile-items.is-boss .monster-hp {
  font-size: 28px;
}

@keyframes bossGlow {
  0%, 100% {
    filter: drop-shadow(0 0 6px rgba(255, 176, 48, 0.8)) drop-shadow(0 0 14px rgba(255, 72, 0, 0.6));
  }
  50% {
    filter: drop-shadow(0 0 10px rgba(255, 196, 64, 1)) drop-shadow(0 0 22px rgba(255, 72, 0, 0.85));
  }
}

@media (prefers-reduced-motion: reduce) {
  .tile-items.is-boss .monster-image {
    animation: none;
  }
}

.healing-fountain-indicator,
.teleportation-gate-indicator,
.arena-indicator,
.cursed-room-indicator {
  position: absolute;
  top: 12%;
  left: 12%;
  width: 34%;
  height: 34%;
  z-index: 4;
  pointer-events: none;
}

.healing-fountain-image,
.portal-image,
.arena-image,
.cursed-room-image {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.healing-fountain-indicator {
  filter: drop-shadow(0 0 6px rgba(var(--ember-rgb), 0.5));
  animation: fountain-glow 2.4s ease-in-out infinite;
}

.teleportation-gate-indicator {
  filter: drop-shadow(0 0 6px rgba(var(--ember-rgb), 0.45));
}

.arena-indicator {
  filter: drop-shadow(0 0 6px rgba(var(--ember-rgb), 0.55));
  animation: arena-call 2.2s ease-in-out infinite;
}

/* used: no colour, no glow, no pulse — a plain room now */
.arena-indicator.arena-used {
  animation: none;
  filter: none;
}

.arena-indicator.arena-used .arena-image {
  filter: grayscale(1) brightness(0.5);
  opacity: 0.6;
}

.arena-badge {
  position: absolute;
  right: -10%;
  bottom: -10%;
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 1px solid var(--iron-edge, #8a7a66);
}

.arena-badge--open {
  background: radial-gradient(circle, #6b1d0e 0%, #2a0a04 100%);
  color: #ffd2a0;
  box-shadow: 0 0 8px rgba(217, 69, 42, 0.8);
}

.arena-badge--used {
  background: #1a130e;
  color: var(--text-muted);
}

@keyframes arena-call {
  0%, 100% { filter: drop-shadow(0 0 4px rgba(var(--ember-rgb), 0.45)); }
  50% { filter: drop-shadow(0 0 12px rgba(var(--ember-rgb), 0.95)); }
}

@media (prefers-reduced-motion: reduce) {
  .arena-indicator {
    animation: none;
  }
}

.cursed-room-indicator {
  filter: drop-shadow(0 0 6px rgba(217, 69, 42, 0.6));
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
    filter: drop-shadow(0 0 5px rgba(var(--ember-rgb), 0.4));
  }
  50% {
    filter: drop-shadow(0 0 10px rgba(var(--ember-rgb), 0.75));
  }
}

@media (prefers-reduced-motion: reduce) {
  .healing-fountain-indicator {
    animation: none;
  }
}
</style> 