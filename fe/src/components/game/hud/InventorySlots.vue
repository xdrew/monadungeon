<template>
  <!-- A hero's kit as slots: key, weapons, spells (padded to their limits), then treasures -->
  <div
    class="inventory-slots"
    :class="`inventory-slots--${size}`"
  >
    <template
      v-for="group in groups"
      :key="group.category"
    >
      <div
        v-for="item in group.items"
        :key="item.itemId"
        class="inventory-item"
        :class="[`${group.itemClass}`, {
          clickable: group.category === 'spells' && canCast(item),
          'teleport-active': group.category === 'spells' && activeItemId === item.itemId
        }]"
        :title="getItemTooltip(item)"
        :data-testid="item.type === 'dark_thorn' ? `dark-thorn-${item.itemId}` : undefined"
        :role="group.category === 'spells' && canCast(item) ? 'button' : undefined"
        :tabindex="group.category === 'spells' && canCast(item) ? 0 : undefined"
        @click="group.category === 'spells' && canCast(item) && emit('cast', item)"
        @keydown.enter="group.category === 'spells' && canCast(item) && emit('cast', item)"
      >
        <img
          v-if="getItemImage(item)"
          :src="getItemImage(item)"
          :alt="itemName(item)"
          class="item-image-icon"
        >
        <GameIcon
          v-else
          :name="getItemIconName(item)"
          :size="iconSize"
          :title="itemName(item)"
        />
        <span
          v-if="group.category === 'weapons' || (group.category === 'spells' && getItemStrength(item, heroFlags) > 0)"
          class="inventory-item__badge"
        >+{{ getItemStrength(item, heroFlags) }}</span>
        <span
          v-else-if="group.category === 'treasures' && item.treasureValue > 0"
          class="inventory-item__badge inventory-item__badge--gold"
        >{{ item.treasureValue }}</span>
      </div>
      <div
        v-for="n in group.empty"
        :key="`${group.category}-empty-${n}`"
        class="inventory-item inventory-item--empty"
        :title="t('hud.emptySlot', { kind: t(`hud.slotKinds.${group.category}`) })"
        aria-hidden="true"
      />
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import GameIcon from '@/components/ui/GameIcon.vue';
import { useI18n } from 'vue-i18n';
import { INVENTORY_LIMITS, getItemImage, getItemIconName, getItemStrength, getItemTooltip } from '@/utils/itemUtils';
import { itemName } from '@/i18n/names';

const props = defineProps({
  /** { keys, weapons, spells, treasures } as the game state sends it */
  inventory: { type: Object, default: () => ({}) },
  heroFlags: { type: Object, default: () => ({}) },
  /** item => whether clicking the spell casts it now (false for someone else's kit) */
  castable: { type: Function, default: () => false },
  activeItemId: { type: String, default: null },
  /** pad keys/weapons/spells with empty slots up to their limits */
  showEmpty: { type: Boolean, default: true },
  size: { type: String, default: 'md' }
});

const emit = defineEmits(['cast']);
const { t } = useI18n();

const iconSize = computed(() => (props.size === 'sm' ? 18 : 24));
const canCast = (item) => !!props.castable(item);

const GROUPS = [
  { category: 'keys', itemClass: 'key-item', label: 'key' },
  { category: 'weapons', itemClass: 'weapon-item', label: 'weapon' },
  { category: 'spells', itemClass: 'spell-item', label: 'spell' },
  { category: 'treasures', itemClass: 'treasure-item', label: 'treasure' }
];

const groups = computed(() => GROUPS.map((group) => {
  const items = props.inventory?.[group.category] || [];
  const limit = INVENTORY_LIMITS[group.category];
  const empty = props.showEmpty && limit !== undefined ? Math.max(0, limit - items.length) : 0;
  return { ...group, items, empty };
}));
</script>

<style scoped>
.inventory-slots {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  --slot: 38px;
  --slot-img: 30px;
}

.inventory-slots--sm {
  gap: 4px;
  --slot: 30px;
  --slot-img: 24px;
}

.inventory-item {
  position: relative;
  width: var(--slot);
  height: var(--slot);
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.6);
  box-shadow: inset 0 0 0 1px rgba(234, 220, 192, 0.25);
  color: var(--text-primary);
}

.inventory-item--empty {
  background: rgba(0, 0, 0, 0.35);
  box-shadow: inset 0 0 0 1px rgba(234, 220, 192, 0.12);
}

.item-image-icon {
  width: var(--slot-img);
  height: var(--slot-img);
  object-fit: contain;
  filter: drop-shadow(0 2px 2px rgba(0, 0, 0, 0.8));
}

.inventory-item__badge {
  position: absolute;
  right: -3px;
  bottom: -5px;
  font-family: var(--font-display);
  font-size: 15px;
  line-height: 1;
  color: var(--text-heading);
  text-shadow: 0 0 4px #000, 0 1px 1px #000;
  pointer-events: none;
}

.inventory-item__badge--gold {
  color: #d9c08a;
}

.inventory-item.clickable {
  cursor: pointer;
  box-shadow: inset 0 0 0 1px rgba(var(--ember-rgb), 0.7), 0 0 10px rgba(var(--ember-rgb), 0.35);
}

.inventory-item.clickable:hover,
.inventory-item.clickable:focus-visible {
  outline: none;
  box-shadow: inset 0 0 0 1px var(--color-primary), 0 0 16px rgba(var(--ember-rgb), 0.6);
}

.inventory-item.teleport-active {
  box-shadow: inset 0 0 0 2px var(--color-primary), 0 0 18px rgba(var(--ember-rgb), 0.8);
}
</style>
