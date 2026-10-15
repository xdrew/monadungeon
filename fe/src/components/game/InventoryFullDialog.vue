<template>
  <!-- A full pack: what goes to make room; shown in the decision plaque, `.inventory-full-overlay` is an e2e hook -->
  <div class="inventory-full-overlay plaque-dialog">
    <h4>{{ $t('dialogs.inventoryFull') }}</h4>
    <div
      class="plaque-dialog__item"
      :class="itemCategoryClass(droppedItem)"
    >
      <img
        v-if="getItemImage(droppedItem)"
        :src="getItemImage(droppedItem)"
        :alt="getDisplayName(droppedItem)"
        class="plaque-dialog__img"
      >
      <GameIcon
        v-else
        name="treasure"
        :size="40"
        class="plaque-dialog__icon"
      />
      <span class="plaque-dialog__name">{{ getDisplayName(droppedItem) }}</span>
      <span
        v-if="getItemDamage(droppedItem) > 0"
        class="plaque-dialog__stat"
      ><GameIcon
        name="sword"
        :size="14"
      /> +{{ getItemDamage(droppedItem) }}</span>
      <span
        v-if="droppedItem.treasureValue > 0"
        class="plaque-dialog__stat plaque-dialog__stat--gold"
      ><GameIcon
        name="treasure"
        :size="14"
      /> {{ droppedItem.treasureValue }}</span>
      <span
        v-if="['fireball', 'frost_fist', 'dark_thorn'].includes(droppedItem.type)"
        class="plaque-dialog__stat"
      ><GameIcon
        name="spells"
        :size="14"
      /> {{ $t('dialogs.consumable') }}</span>
    </div>

    <p>{{ replaceLabel(itemCategory) }}</p>
    <div class="replace-items">
      <button
        v-for="item in inventoryForCategory"
        :key="item.itemId"
        type="button"
        class="replace-item"
        :class="{ selected: selectedItemToReplace && selectedItemToReplace.itemId === item.itemId }"
        :aria-pressed="!!(selectedItemToReplace && selectedItemToReplace.itemId === item.itemId)"
        @click="selectItemToReplace(item)"
      >
        <img
          v-if="getItemImage(item)"
          :src="getItemImage(item)"
          alt=""
          class="replace-item-img"
        >
        <GameIcon
          v-else
          name="treasure"
          :size="24"
        />
        <span class="replace-item-name">{{ getDisplayName(item) }}</span>
        <span
          v-if="getItemDamage(item) > 0"
          class="replace-item-stat"
        >+{{ getItemDamage(item) }}</span>
        <span
          v-if="item.treasureValue > 0"
          class="replace-item-stat replace-item-stat--gold"
        >{{ item.treasureValue }}</span>
      </button>
    </div>

    <div class="plaque-dialog__actions">
      <button
        type="button"
        class="btn btn--ghost btn-secondary"
        @click="skipItem"
      >
        {{ $t('dialogs.leaveItem') }} <span class="kbd-hint">(Esc)</span>
      </button>
      <button
        type="button"
        class="btn btn--ember btn-primary"
        :disabled="!selectedItemToReplace"
        @click="replaceItem"
      >
        {{ $t('dialogs.replaceSelected') }} <span class="kbd-hint">(Enter)</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import GameIcon from '@/components/ui/GameIcon.vue';
import { ref, onMounted, onUnmounted } from 'vue';
import { formatItemName, getItemDamage, getItemImage as itemImage } from '@/utils/itemUtils';
import { t } from '@/i18n';
import { itemName as localizedItemName } from '@/i18n/names';

const props = defineProps({
  droppedItem: {
    type: Object,
    required: true
  },
  itemCategory: {
    type: String,
    required: true
  },
  inventoryForCategory: {
    type: Array,
    required: true
  }
});

const emit = defineEmits(['replace-item', 'skip-item', 'select-item']);

const selectedItemToReplace = ref(null);

// in this choice the chest shows open (it is already in a pack)
const getItemImage = (item) => itemImage(item);

const getDisplayName = (item) => {
  if (!item || !item.name) return t('items.tooltip.unknown');
  const monsterNames = [
    'skeleton_king', 'skeleton_warrior', 'skeleton_turnkey', 'skeleton_mage',
    'dragon', 'fallen', 'giant_rat', 'giant_spider', 'mummy', 'giant_bat'
  ];
  const isMonster = monsterNames.some(monster =>
    item.name.toLowerCase().includes(monster)
  );
  if (isMonster) {
    if (['key', 'dagger', 'sword', 'axe', 'fireball', 'frost_fist', 'dark_thorn', 'teleport', 'chest', 'ruby_chest'].includes(item.type)) return localizedItemName(item.type);
    return localizedItemName('treasure');
  }
  return formatItemName(item.name);
};

const itemCategoryClass = (item) => {
  if (!item) return '';
  const type = item.type;
  if (['dagger', 'sword', 'axe'].includes(type)) return 'category-weapon';
  if (type === 'key') return 'category-key';
  if (['fireball', 'teleport', 'frost_fist', 'dark_thorn'].includes(type)) return 'category-spell';
  if (['chest', 'ruby_chest'].includes(type)) return 'category-treasure';
  return '';
};

const replaceLabel = (category) => (['keys', 'weapons', 'spells', 'treasures'].includes(category)
  ? t(`dialogs.replaceFrom.${category}`)
  : t('dialogs.replaceFrom.other', { category }));

const selectItemToReplace = (item) => {
  selectedItemToReplace.value = item;
  emit('select-item', item);
};

const replaceItem = () => {
  if (selectedItemToReplace.value) {
    emit('replace-item', selectedItemToReplace.value);
  }
};

const skipItem = () => {
  emit('skip-item');
};

const onKeyDown = (e) => {
  if (e.key === 'Enter') {
    if (selectedItemToReplace.value) {
      replaceItem();
    }
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
.replace-items {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.replace-item {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  padding: 6px 12px;
  border: 1px solid rgba(234, 220, 192, 0.2);
  background: rgba(0, 0, 0, 0.35);
  color: var(--text-primary);
  font: inherit;
  font-size: 14px;
  cursor: pointer;
}

.replace-item:hover,
.replace-item:focus-visible {
  outline: none;
  border-color: rgba(var(--ember-rgb), 0.6);
}

.replace-item.selected {
  border-color: rgba(217, 69, 42, 0.85);
  background: rgba(217, 69, 42, 0.15);
}

.replace-item-img {
  width: 28px;
  height: 28px;
  object-fit: contain;
}

.replace-item-stat {
  font-family: var(--font-display);
  font-size: 16px;
  color: var(--text-heading);
}

.replace-item-stat--gold {
  color: #d9c08a;
}
</style>
