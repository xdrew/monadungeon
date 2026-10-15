<template>
  <div class="inventory-full-overlay">
    <div class="inventory-full-card">
      <div class="card-header">
        <span class="header-label">{{ $t('dialogs.inventoryFull') }}</span>
      </div>

      <!-- Found item -->
      <div class="found-item-section">
        <div class="found-item" :class="itemCategoryClass(droppedItem)">
          <div class="found-item-icon">
            <img
              v-if="getItemImage(droppedItem)"
              :src="getItemImage(droppedItem)"
              :alt="getDisplayName(droppedItem)"
              class="found-item-img"
            />
            <span v-else class="found-item-emoji">{{ getItemEmoji(droppedItem) }}</span>
          </div>
          <div class="found-item-info">
            <span class="found-item-name">{{ getDisplayName(droppedItem) }}</span>
            <div class="found-item-badges">
              <span
                v-if="getItemDamage(droppedItem) > 0"
                class="stat-badge badge-damage"
              ><GameIcon name="sword" :size="13" /> +{{ getItemDamage(droppedItem) }}</span>
              <span
                v-if="droppedItem.treasureValue > 0"
                class="stat-badge badge-value"
              ><GameIcon name="treasure" :size="13" /> {{ droppedItem.treasureValue }}</span>
              <span
                v-if="['fireball', 'frost_fist', 'dark_thorn'].includes(droppedItem.type)"
                class="stat-badge badge-spell"
              ><GameIcon name="spells" :size="13" /> {{ $t('dialogs.consumable') }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Replace from inventory -->
      <div class="replace-section">
        <div class="replace-label">{{ replaceLabel(itemCategory) }}</div>
        <div class="replace-items">
          <div
            v-for="item in inventoryForCategory"
            :key="item.itemId"
            class="replace-item"
            :class="{ 'selected': selectedItemToReplace && selectedItemToReplace.itemId === item.itemId }"
            @click="selectItemToReplace(item)"
          >
            <img
              v-if="getItemImage(item)"
              :src="getItemImage(item)"
              :alt="getDisplayName(item)"
              class="replace-item-img"
            />
            <span v-else class="replace-item-emoji">{{ getItemEmoji(item) }}</span>
            <div class="replace-item-info">
              <span class="replace-item-name">{{ getDisplayName(item) }}</span>
              <span
                v-if="getItemDamage(item) > 0"
                class="stat-badge badge-damage badge-sm"
              ><GameIcon name="sword" :size="12" /> +{{ getItemDamage(item) }}</span>
              <span
                v-if="item.treasureValue > 0"
                class="stat-badge badge-value badge-sm"
              ><GameIcon name="treasure" :size="12" /> {{ item.treasureValue }}</span>
            </div>
            <div v-if="selectedItemToReplace && selectedItemToReplace.itemId === item.itemId" class="selected-check"><GameIcon name="check" :size="14" /></div>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="dialog-actions">
        <button
          class="btn-primary"
          :disabled="!selectedItemToReplace"
          @click="replaceItem"
        >
          {{ $t('dialogs.replaceSelected') }} <span class="kbd-hint">(Enter)</span>
        </button>
        <button
          class="btn-secondary"
          @click="skipItem"
        >
          {{ $t('dialogs.leaveItem') }} <span class="kbd-hint">(Esc)</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import GameIcon from '@/components/ui/GameIcon.vue';
import { defineProps, defineEmits, ref, onMounted, onUnmounted } from 'vue';
import { getItemEmoji, formatItemName, getItemDamage } from '@/utils/itemUtils';
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

const getItemImage = (item) => {
  if (!item) return null;
  switch (item.type) {
    case 'key': return '/images/key.webp';
    case 'chest': return '/images/chest-opened.webp';
    case 'ruby_chest': return '/images/ruby-chest.webp';
    case 'dagger': return '/images/dagger.webp';
    case 'sword': return '/images/sword.webp';
    case 'axe': return '/images/axe.webp';
    case 'fireball': return '/images/fireball.webp';
    case 'teleport': return '/images/hf-teleport.webp';
    case 'frost_fist': return '/images/frost-fist.webp';
    case 'dark_thorn': return '/images/dark-thorn.webp';
    default: return null;
  }
};

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
/* Overlay */
.inventory-full-overlay {
  position: fixed;
  inset: 0;
  background: var(--bg-overlay, rgba(9, 8, 15, 0.88));
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  animation: overlayIn 0.25s ease-out;
}

@keyframes overlayIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

/* Card */
.inventory-full-card {
  background: var(--bg-secondary);
  border: 1px solid rgba(var(--accent-rgb), 0.3);
  border-radius: 12px;
  width: 360px;
  max-width: 95vw;
  max-height: 85vh;
  overflow-y: auto;
  padding: 0 20px 20px;
  box-shadow: 0 6px 24px rgba(0, 0, 0, 0.5);
  animation: cardIn 0.3s ease-out;
}

@keyframes cardIn {
  from { opacity: 0; transform: scale(0.92) translateY(10px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}

/* Header */
.card-header {
  margin: 0 -20px;
  padding: 10px 20px;
  border-radius: 12px 12px 0 0;
  background: linear-gradient(135deg, rgba(244, 67, 54, 0.15) 0%, rgba(var(--accent-rgb), 0.08) 100%);
  border-bottom: 1px solid rgba(244, 67, 54, 0.2);
  margin-bottom: 16px;
}

.header-label {
  font-size: 0.75em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: #ef9a9a;
}

/* Found item */
.found-item-section {
  margin-bottom: 14px;
}

.found-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px;
  border-radius: 10px;
  background: rgba(var(--accent-rgb), 0.08);
  border: 1px solid rgba(var(--accent-rgb), 0.35);
}

.found-item.category-weapon { border-color: rgba(255, 87, 34, 0.45); background: rgba(255, 87, 34, 0.06); }
.found-item.category-key { border-color: rgba(255, 193, 7, 0.45); background: rgba(255, 193, 7, 0.06); }
.found-item.category-spell { border-color: rgba(156, 39, 176, 0.45); background: rgba(156, 39, 176, 0.06); }
.found-item.category-treasure { border-color: rgba(255, 193, 7, 0.45); background: rgba(255, 193, 7, 0.06); }

.found-item-icon {
  flex-shrink: 0;
}

.found-item-img {
  width: 44px;
  height: 44px;
  object-fit: contain;
  filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.4));
}

.found-item-emoji {
  font-size: 2rem;
}

.found-item-info {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.found-item-name {
  font-weight: 700;
  font-size: 1.05rem;
  color: var(--text-primary);
}

.found-item-badges {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

/* Stat badges (matching ItemPickupDialog / BattleReportModal) */
.stat-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 8px;
  border-radius: 14px;
  font-size: 0.75rem;
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

.badge-spell {
  background: rgba(156, 39, 176, 0.15);
  color: #CE93D8;
  border: 1px solid rgba(156, 39, 176, 0.3);
}

.badge-sm {
  font-size: 0.65rem;
  padding: 1px 6px;
}

/* Replace section */
.replace-section {
  margin-bottom: 16px;
}

.replace-label {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--text-muted);
  margin-bottom: 8px;
}

.replace-items {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.replace-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 8px;
  background: rgba(var(--accent-rgb), 0.06);
  border: 1px solid rgba(var(--accent-rgb), 0.2);
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
}

.replace-item:hover {
  background: rgba(var(--accent-rgb), 0.12);
  border-color: rgba(var(--accent-rgb), 0.4);
}

.replace-item.selected {
  background: rgba(var(--accent-rgb), 0.18);
  border-color: rgba(var(--accent-light-rgb), 0.7);
  box-shadow: 0 0 10px rgba(var(--accent-rgb), 0.2);
}

.replace-item-img {
  width: 32px;
  height: 32px;
  object-fit: contain;
  flex-shrink: 0;
  filter: drop-shadow(0 1px 3px rgba(0, 0, 0, 0.3));
}

.replace-item-emoji {
  font-size: 1.4rem;
  flex-shrink: 0;
  min-width: 32px;
  text-align: center;
}

.replace-item-info {
  display: flex;
  align-items: center;
  gap: 6px;
  flex: 1;
  flex-wrap: wrap;
}

.replace-item-name {
  font-weight: 600;
  font-size: 0.85rem;
  color: var(--text-primary);
}

.selected-check {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: var(--color-primary);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7rem;
  font-weight: bold;
  flex-shrink: 0;
}

/* Actions */
.dialog-actions {
  display: flex;
  gap: 10px;
}

.btn-primary,
.btn-secondary {
  flex: 1;
  padding: 10px 14px;
  border-radius: 8px;
  font-weight: 600;
  font-size: 0.9rem;
  cursor: pointer;
  transition: all 0.2s ease;
  border: none;
}

.btn-primary {
  background: var(--gradient-primary);
  color: #fff;
  box-shadow: 0 4px 14px rgba(var(--accent-rgb), 0.35);
}

.btn-primary:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(var(--accent-rgb), 0.5);
}

.btn-primary:disabled {
  background: rgba(var(--accent-rgb), 0.3);
  cursor: not-allowed;
  opacity: 0.5;
  transform: none;
  box-shadow: none;
}

.btn-secondary {
  background: transparent;
  color: var(--text-secondary);
  border: 1px solid rgba(var(--accent-rgb), 0.25);
}

.btn-secondary:hover {
  background: rgba(var(--accent-rgb), 0.1);
  transform: translateY(-2px);
}

.kbd-hint {
  font-size: 0.75em;
  opacity: 0.5;
  margin-left: 4px;
}

@media (hover: none) {
  .kbd-hint { display: none; }
}

/* Responsive */
@media (max-width: 480px) {
  .inventory-full-card {
    width: 100%;
    padding: 0 14px 14px;
  }

  .card-header {
    margin: 0 -14px;
    padding: 8px 14px;
  }

  .dialog-actions {
    flex-direction: column;
  }

  .found-item-img {
    width: 36px;
    height: 36px;
  }
}
</style>
