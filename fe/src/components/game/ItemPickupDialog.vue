<template>
  <!-- An item on the tile: shown in the decision plaque; `.item-pickup-overlay` is an e2e hook -->
  <div
    v-if="show"
    class="item-pickup-overlay plaque-dialog"
    :class="itemCategoryClass"
  >
    <h4>{{ $t('dialogs.itemFound') }}</h4>
    <div class="plaque-dialog__item">
      <img
        v-if="itemImage"
        :src="itemImage"
        :alt="itemName"
        class="plaque-dialog__img"
      >
      <GameIcon
        v-else
        name="treasure"
        :size="40"
        class="plaque-dialog__icon"
      />
      <span class="plaque-dialog__name">{{ itemName }}</span>
      <span
        v-if="itemDamage > 0"
        class="plaque-dialog__stat"
      ><GameIcon
        name="sword"
        :size="14"
      /> +{{ itemDamage }}</span>
      <span
        v-if="itemValue > 0"
        class="plaque-dialog__stat plaque-dialog__stat--gold"
      ><GameIcon
        name="treasure"
        :size="14"
      /> {{ $t('dialogs.vp', { n: itemValue }) }}</span>
    </div>
    <p>{{ $t('dialogs.pickUpPrompt') }}</p>
    <div class="plaque-dialog__actions">
      <button
        type="button"
        class="btn btn--ghost btn-leave"
        @click="skipItem"
      >
        {{ $t('dialogs.leaveIt') }} <span class="kbd-hint">(Esc)</span>
      </button>
      <button
        type="button"
        class="btn btn--ember btn-pickup"
        @click="pickupItem"
      >
        {{ $t('dialogs.pickUp') }} <span class="kbd-hint">(Enter)</span>
      </button>
    </div>
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


