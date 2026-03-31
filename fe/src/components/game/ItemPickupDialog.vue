<template>
  <div
    v-if="show"
    class="item-pickup-overlay"
  >
    <div :class="['dialog-card', itemCategoryClass]">
      <div class="card-header">
        <span class="header-label">Item Found</span>
      </div>

      <div class="item-showcase">
        <div class="item-icon-wrapper">
          <div class="item-glow" />
          <img
            v-if="itemImage"
            :src="itemImage"
            :alt="itemName"
            class="item-image"
          />
          <span v-else class="item-emoji">{{ itemEmoji }}</span>
        </div>
      </div>

      <h3 class="item-name">{{ itemName }}</h3>

      <div v-if="itemDamage > 0 || itemValue > 0" class="stat-badges">
        <span v-if="itemDamage > 0" class="badge badge-damage">
          ⚔️ +{{ itemDamage }}
        </span>
        <span v-if="itemValue > 0" class="badge badge-value">
          💎 {{ itemValue }} VP
        </span>
      </div>

      <p class="pickup-prompt">Pick up this item?</p>

      <div class="dialog-actions">
        <button
          class="btn-pickup"
          @click="pickupItem"
        >
          🎒 Pick Up <span class="kbd-hint">(Enter)</span>
        </button>
        <button
          class="btn-leave"
          @click="skipItem"
        >
          Leave It <span class="kbd-hint">(Esc)</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import { getItemEmoji, formatItemName } from '@/utils/itemUtils';

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  item: {
    type: Object,
    default: () => ({})
  }
});

const emit = defineEmits(['pickup', 'skip']);

// Helper function to get display name (hide monster names)
const getDisplayName = (item) => {
  if (!item || !item.name) return 'Unknown Item';
  
  // List of monster names to hide
  const monsterNames = [
    'skeleton_king', 'skeleton_warrior', 'skeleton_turnkey', 'skeleton_mage',
    'dragon', 'fallen', 'giant_rat', 'giant_spider', 'mummy'
  ];
  
  // Check if the name is a monster name
  const isMonster = monsterNames.some(monster => 
    item.name.toLowerCase().includes(monster)
  );
  
  if (isMonster) {
    // Return generic name based on item type
    if (item.type === 'key') return 'Key';
    if (item.type === 'dagger') return 'Dagger';
    if (item.type === 'sword') return 'Sword';
    if (item.type === 'axe') return 'Axe';
    if (item.type === 'fireball') return 'Fireball';
    if (item.type === 'chest') return 'Treasure Chest';
    if (item.type === 'ruby_chest') return 'Ruby Chest';
    return 'Treasure';
  }
  
  // For non-monster items, show the actual name
  return formatItemName(item.name);
};

// Computed properties for item display
const itemEmoji = computed(() => getItemEmoji(props.item));
const itemImage = computed(() => {
  if (!props.item) return null;
  
  switch (props.item.type) {
    case 'chest':
      // In pickup dialog, show closed chest (it's still on the field)
      return '/images/chest-closed.webp';
    case 'ruby_chest':
      return '/images/ruby-chest.webp';
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
    default:
      return null;
  }
});
const itemName = computed(() => getDisplayName(props.item));
const itemDamage = computed(() => {
  // Damage based on item type
  if (!props.item?.type) return 0;
  
  if (props.item.damage !== undefined) return props.item.damage;
  
  switch (props.item.type) {
    case 'dagger': return 1;
    case 'sword': return 2;
    case 'axe': return 3;
    case 'fireball': return 1;
    default: return 0;
  }
});
const itemValue = computed(() => props.item?.treasureValue || 0);

const itemCategoryClass = computed(() => {
  const type = props.item?.type;
  if (['dagger', 'sword', 'axe'].includes(type)) return 'category-weapon';
  if (type === 'key') return 'category-key';
  if (['fireball', 'teleport'].includes(type)) return 'category-spell';
  if (['chest', 'ruby_chest'].includes(type)) return 'category-treasure';
  return '';
});

// Actions
const pickupItem = () => {
  emit('pickup', props.item);
};

const skipItem = () => {
  emit('skip');
};

// Keyboard handler
const onKeyDown = (e) => {
  if (!props.show) return;
  if (e.key === 'Enter') {
    pickupItem();
    e.preventDefault();
    e.stopPropagation();
  } else if (e.key === 'Escape') {
    skipItem();
    e.preventDefault();
    e.stopPropagation();
  }
};

onMounted(() => window.addEventListener('keydown', onKeyDown, true));
onUnmounted(() => window.removeEventListener('keydown', onKeyDown, true));
</script>

<style scoped>
/* Overlay */
.item-pickup-overlay {
  position: fixed;
  inset: 0;
  background: var(--bg-overlay, rgba(9, 8, 15, 0.85));
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  animation: overlayIn 0.25s ease-out;
}

/* Card */
.dialog-card {
  background: var(--monad-bg-card, #1A1830);
  border: 1px solid rgba(123, 63, 242, 0.35);
  border-radius: 14px;
  width: 320px;
  max-width: 90vw;
  padding: 0 24px 24px;
  box-shadow:
    0 8px 32px rgba(0, 0, 0, 0.5),
    0 0 20px rgba(123, 63, 242, 0.15);
  animation: cardIn 0.3s ease-out;
  text-align: center;
}

/* Category-specific accent borders */
.dialog-card.category-weapon { border-color: rgba(255, 87, 34, 0.5); }
.dialog-card.category-key    { border-color: rgba(255, 193, 7, 0.5); }
.dialog-card.category-spell  { border-color: rgba(156, 39, 176, 0.5); }
.dialog-card.category-treasure { border-color: rgba(255, 193, 7, 0.5); }

/* Header */
.card-header {
  margin: 0 -24px;
  padding: 10px 24px;
  border-radius: 14px 14px 0 0;
  background: linear-gradient(135deg, rgba(123, 63, 242, 0.15) 0%, rgba(167, 139, 250, 0.08) 100%);
  border-bottom: 1px solid rgba(123, 63, 242, 0.2);
  margin-bottom: 20px;
}

.header-label {
  font-size: 0.75em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--monad-text-secondary, #C4B5FD);
}

/* Item showcase */
.item-showcase {
  display: flex;
  justify-content: center;
  margin-bottom: 14px;
}

.item-icon-wrapper {
  position: relative;
  width: 88px;
  height: 88px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.item-glow {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(123, 63, 242, 0.25) 0%, transparent 70%);
  animation: glowPulse 2.5s ease-in-out infinite;
}

.category-weapon .item-glow { background: radial-gradient(circle, rgba(255, 87, 34, 0.25) 0%, transparent 70%); }
.category-key .item-glow    { background: radial-gradient(circle, rgba(255, 193, 7, 0.25) 0%, transparent 70%); }
.category-spell .item-glow  { background: radial-gradient(circle, rgba(156, 39, 176, 0.3) 0%, transparent 70%); }
.category-treasure .item-glow { background: radial-gradient(circle, rgba(255, 193, 7, 0.3) 0%, transparent 70%); }

.item-image {
  width: 72px;
  height: 72px;
  object-fit: contain;
  position: relative;
  filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.4));
}

.item-emoji {
  font-size: 3em;
  position: relative;
}

/* Item name */
.item-name {
  margin: 0 0 10px;
  font-size: 1.35em;
  font-weight: 700;
  color: var(--monad-text-primary, #F5F3FF);
}

/* Stat badges */
.stat-badges {
  display: flex;
  justify-content: center;
  gap: 8px;
  margin-bottom: 14px;
}

.badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 0.85em;
  font-weight: 600;
}

.badge-damage {
  background: rgba(255, 87, 34, 0.15);
  color: #FF8A65;
  border: 1px solid rgba(255, 87, 34, 0.3);
}

.badge-value {
  background: rgba(255, 193, 7, 0.15);
  color: #FFD54F;
  border: 1px solid rgba(255, 193, 7, 0.3);
}

/* Prompt text */
.pickup-prompt {
  color: var(--monad-text-muted, #9CA3AF);
  font-size: 0.9em;
  margin: 0 0 18px;
}

/* Actions */
.dialog-actions {
  display: flex;
  gap: 10px;
}

.btn-pickup,
.btn-leave {
  flex: 1;
  padding: 11px 16px;
  border-radius: 8px;
  font-weight: 600;
  font-size: 0.95em;
  cursor: pointer;
  transition: all 0.2s ease;
  border: none;
}

.btn-pickup {
  background: var(--monad-gradient-primary, linear-gradient(135deg, #7B3FF2 0%, #A78BFA 100%));
  color: #fff;
  box-shadow: 0 4px 14px rgba(123, 63, 242, 0.35);
}

.btn-pickup:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(123, 63, 242, 0.5);
}

.btn-leave {
  background: transparent;
  color: var(--monad-text-secondary, #C4B5FD);
  border: 1px solid rgba(123, 63, 242, 0.25);
}

.btn-leave:hover {
  background: rgba(123, 63, 242, 0.1);
  transform: translateY(-2px);
}

/* Animations */
@keyframes overlayIn {
  from { opacity: 0; }
  to   { opacity: 1; }
}

@keyframes cardIn {
  from {
    opacity: 0;
    transform: scale(0.9) translateY(10px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

@keyframes glowPulse {
  0%, 100% { opacity: 0.6; transform: scale(1); }
  50%      { opacity: 1;   transform: scale(1.1); }
}

.kbd-hint {
  font-size: 0.75em;
  opacity: 0.6;
  margin-left: 4px;
}

@media (hover: none) {
  .kbd-hint { display: none; }
}

/* Responsive */
@media (max-width: 480px) {
  .dialog-card {
    width: 280px;
    padding: 0 18px 18px;
  }

  .card-header {
    margin: 0 -18px;
    padding: 8px 18px;
  }

  .item-image {
    width: 60px;
    height: 60px;
  }

  .item-icon-wrapper {
    width: 76px;
    height: 76px;
  }
}
</style> 