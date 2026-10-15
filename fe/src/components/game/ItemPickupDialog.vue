<template>
  <!-- An item on the tile: a centred plaque over the dimmed field, like the full-pack choice.
       `.item-pickup-overlay` is an e2e hook; the en labels "Pick Up" / "Leave It" too -->
  <div
    v-if="show"
    class="item-pickup-overlay"
    :class="itemCategoryClass"
  >
    <section
      class="pickup-card"
      role="dialog"
      aria-modal="true"
      :aria-label="$t('dialogs.itemFound')"
    >
      <span class="pickup-caption">{{ $t('dialogs.itemFound') }}</span>

      <div class="pickup-found">
        <span class="pickup-found__icon">
          <img
            v-if="itemImage"
            :src="itemImage"
            :alt="itemName"
            class="pickup-found__img"
            :class="`pickup-img--${item?.type}`"
          >
          <GameIcon
            v-else
            name="treasure"
            :size="48"
          />
        </span>
        <span class="pickup-found__text">
          <span class="pickup-found__name">{{ itemName }}</span>
          <span
            v-if="itemDamage > 0 || itemValue > 0"
            class="pickup-found__stats"
          >
            <span
              v-if="itemDamage > 0"
              class="pickup-stat"
            ><GameIcon
              name="sword"
              :size="15"
            /> +{{ itemDamage }}</span>
            <span
              v-if="itemValue > 0"
              class="pickup-stat pickup-stat--gold"
            ><GameIcon
              name="treasure"
              :size="15"
            /> {{ $t('dialogs.vp', { n: itemValue }) }}</span>
          </span>
        </span>
      </div>

      <div class="pickup-actions">
        <button
          type="button"
          class="pickup-btn pickup-btn--ghost btn-leave"
          @click="skipItem"
        >
          {{ $t('dialogs.leaveIt') }}
          <span class="kbd-hint">Esc</span>
        </button>
        <button
          type="button"
          class="pickup-btn pickup-btn--ember btn-pickup"
          @click="pickupItem"
        >
          {{ $t('dialogs.pickUp') }}
          <span class="kbd-hint">Enter</span>
        </button>
      </div>
    </section>
  </div>
</template>

<script setup>
import GameIcon from '@/components/ui/GameIcon.vue';
import { computed, onMounted, onUnmounted } from 'vue';
import { formatItemName } from '@/utils/itemUtils';
import { t } from '@/i18n';
import { itemName as localizedItemName } from '@/i18n/names';

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
  if (!item || !item.name) return t('items.tooltip.unknown');
  
  // List of monster names to hide
  const monsterNames = [
    'skeleton_king', 'skeleton_warrior', 'skeleton_turnkey', 'skeleton_mage',
    'dragon', 'fallen', 'giant_rat', 'giant_spider', 'mummy', 'giant_bat'
  ];
  
  // Check if the name is a monster name
  const isMonster = monsterNames.some(monster => 
    item.name.toLowerCase().includes(monster)
  );
  
  if (isMonster) {
    // Return generic name based on item type
    if (['key', 'dagger', 'sword', 'axe', 'fireball', 'frost_fist', 'dark_thorn', 'chest', 'ruby_chest'].includes(item.type)) return localizedItemName(item.type);
    return localizedItemName('treasure');
  }
  
  // For non-monster items, show the actual name
  return formatItemName(item.name);
};

// Computed properties for item display
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
    case 'frost_fist':
      return '/images/frost-fist.webp';
    case 'dark_thorn':
      return '/images/dark-thorn.webp';
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
    case 'frost_fist': return 2;
    default: return 0;
  }
});
const itemValue = computed(() => props.item?.treasureValue || 0);

const itemCategoryClass = computed(() => {
  const type = props.item?.type;
  if (['dagger', 'sword', 'axe'].includes(type)) return 'category-weapon';
  if (type === 'key') return 'category-key';
  if (['fireball', 'teleport', 'frost_fist', 'dark_thorn'].includes(type)) return 'category-spell';
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
.item-pickup-overlay {
  position: fixed;
  inset: 0;
  z-index: var(--z-modal);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(5, 3, 2, 0.6);
  animation: pickupFade 0.25s ease-out;
}

@keyframes pickupFade {
  from { opacity: 0; }
  to { opacity: 1; }
}

.pickup-card {
  width: min(560px, 100%);
  box-sizing: border-box;
  padding: 22px 26px 18px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 18px;
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.97) 0%, rgba(12, 7, 5, 0.97) 100%);
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  box-shadow: 0 0 40px rgba(var(--ember-rgb), 0.2), 0 14px 34px rgba(0, 0, 0, 0.85);
  color: var(--text-primary);
  font-family: var(--font-body);
  animation: pickupRise 0.3s ease-out;
}

@keyframes pickupRise {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

.pickup-caption {
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.32em;
  text-transform: uppercase;
  color: var(--text-accent);
}

.pickup-found {
  display: flex;
  align-items: center;
  gap: 18px;
}

.pickup-found__icon {
  width: 96px;
  height: 96px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #d9c08a;
  background: radial-gradient(circle, rgba(255, 170, 70, 0.3) 0%, rgba(var(--ember-rgb), 0.08) 45%, rgba(0, 0, 0, 0) 70%);
}

.pickup-found__img {
  width: 96px;
  height: 96px;
  object-fit: contain;
  filter: brightness(1.2) drop-shadow(0 0 10px rgba(var(--ember-rgb), 0.35)) drop-shadow(0 4px 4px #000);
}

/* the dagger and the axe fill only half of their art */
.pickup-img--dagger {
  transform: scale(1.5);
}

.pickup-img--axe {
  transform: scale(1.2);
}

.pickup-found__text {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.pickup-found__name {
  font-family: var(--font-display);
  font-size: 32px;
  line-height: 1;
  color: var(--text-heading);
  text-shadow: 0 2px 6px #000;
}

.pickup-found__stats {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.pickup-stat {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-family: var(--font-display);
  font-size: 20px;
  color: var(--text-heading);
}

.pickup-stat :deep(svg) {
  color: var(--text-accent);
}

.pickup-stat--gold {
  color: #d9c08a;
}

/* the buttons, as in the fight: bone-rimmed "leave", an ember plate to take, keycaps under them */
.pickup-actions {
  align-self: stretch;
  display: flex;
  flex-wrap: wrap;
  gap: 30px 12px;
  padding: 4px 0 24px;
}

.pickup-btn {
  position: relative;
  flex: 1 1 0;
  min-width: 180px;
  min-height: 56px;
  padding: 0 22px;
  border-radius: 2px;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 1.1;
  letter-spacing: 0.02em;
  cursor: pointer;
  transition: box-shadow var(--transition-fast), background var(--transition-fast), border-color var(--transition-fast);
}

.pickup-btn--ember {
  border: 1px solid var(--color-primary);
  background: linear-gradient(180deg, rgba(255, 130, 40, 0.38) 0%, rgba(150, 60, 10, 0.38) 100%);
  color: #ffe2b8;
  text-shadow: 0 1px 2px #000;
  box-shadow: 0 0 26px rgba(var(--ember-rgb), 0.38), inset 0 1px 0 rgba(255, 220, 180, 0.3), inset 0 -2px 6px rgba(0, 0, 0, 0.35);
}

.pickup-btn--ember:hover,
.pickup-btn--ember:focus-visible {
  outline: none;
  background: linear-gradient(180deg, rgba(255, 150, 60, 0.5) 0%, rgba(170, 70, 15, 0.5) 100%);
}

.pickup-btn--ghost {
  border: 1px solid rgba(234, 220, 192, 0.35);
  background: rgba(0, 0, 0, 0.3);
  color: var(--text-primary);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
}

.pickup-btn--ghost:hover,
.pickup-btn--ghost:focus-visible {
  outline: none;
  border-color: rgba(234, 220, 192, 0.7);
  color: var(--text-heading);
}

.kbd-hint {
  position: absolute;
  top: calc(100% + 8px);
  left: 50%;
  transform: translateX(-50%);
  padding: 2px 7px;
  border: 1px solid currentColor;
  border-bottom-width: 2px;
  border-radius: 3px;
  font-family: var(--font-body);
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.05em;
  line-height: 1.1;
  color: var(--text-muted);
  text-shadow: none;
  pointer-events: none;
}

@media (max-width: 520px) {
  .pickup-found__name {
    font-size: 26px;
  }
}
</style>
