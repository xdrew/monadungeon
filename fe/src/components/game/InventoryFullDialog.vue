<template>
  <!-- A full pack: one click on the item that goes swaps it for the find, or leave the find. A centred plaque over the dimmed field, like the fight's
       choice plaque; `.inventory-full-overlay` and `.replace-item` are e2e hooks -->
  <div class="inventory-full-overlay">
    <section
      class="inventory-full-card"
      role="dialog"
      aria-modal="true"
      :aria-label="$t('dialogs.inventoryFull')"
    >
      <span class="inv-caption">{{ $t('dialogs.inventoryFull') }}</span>
      <p class="inv-prompt">
        {{ replaceLabel(itemCategory) }}
      </p>

      <!-- the find, with an ember arrow to every item it could take the place of -->
      <div
        ref="swapEl"
        class="inv-swap"
      >
        <svg
          class="inv-arrows"
          :width="arrowBox.w"
          :height="arrowBox.h"
          aria-hidden="true"
        >
          <defs>
            <!-- fixed-size heads (not scaled with the stroke): the base sits on the line's end -->
            <marker
              id="inv-arrowhead"
              viewBox="0 0 10 10"
              refX="0"
              refY="5"
              markerWidth="10"
              markerHeight="10"
              markerUnits="userSpaceOnUse"
              orient="auto"
            >
              <path
                d="M0 0 L10 5 L0 10 z"
                class="inv-arrowhead"
              />
            </marker>
            <marker
              id="inv-arrowhead-hot"
              viewBox="0 0 10 10"
              refX="0"
              refY="5"
              markerWidth="10"
              markerHeight="10"
              markerUnits="userSpaceOnUse"
              orient="auto"
            >
              <path
                d="M0 0 L10 5 L0 10 z"
                class="inv-arrowhead inv-arrowhead--hot"
              />
            </marker>
          </defs>
          <path
            v-for="(arrow, index) in arrows"
            :key="index"
            :d="arrow"
            class="inv-arrow"
            :class="{ 'inv-arrow--hot': hotIndex === index }"
            :marker-end="hotIndex === index ? 'url(#inv-arrowhead-hot)' : 'url(#inv-arrowhead)'"
          />
        </svg>
        <div
          ref="foundEl"
          class="inv-found"
          :class="itemCategoryClass(droppedItem)"
        >
          <span class="inv-found__icon">
            <img
              v-if="getItemImage(droppedItem)"
              :src="getItemImage(droppedItem)"
              :alt="getDisplayName(droppedItem)"
              class="inv-found__img"
              :class="`inv-img--${droppedItem.type}`"
            >
            <GameIcon
              v-else
              name="treasure"
              :size="44"
            />
          </span>
          <span class="inv-found__text">
            <span class="inv-found__name">{{ getDisplayName(droppedItem) }}</span>
            <span class="inv-found__stats">
              <span
                v-if="getItemDamage(droppedItem) > 0"
                class="inv-stat"
              ><GameIcon
                name="sword"
                :size="15"
              /> +{{ getItemDamage(droppedItem) }}</span>
              <span
                v-if="droppedItem.treasureValue > 0"
                class="inv-stat inv-stat--gold"
              ><GameIcon
                name="treasure"
                :size="15"
              /> {{ droppedItem.treasureValue }}</span>
              <span
                v-if="['fireball', 'frost_fist', 'dark_thorn'].includes(droppedItem.type)"
                class="inv-stat inv-stat--note"
              ><GameIcon
                name="spells"
                :size="15"
              /> {{ $t('dialogs.consumable') }}</span>
            </span>
          </span>
        </div>

        <!-- what goes -->
        <div class="replace-items">
          <button
            v-for="(item, index) in inventoryForCategory"
            :key="item.itemId"
            :ref="(el) => { cardEls[index] = el; }"
            type="button"
            class="replace-item"
            :class="{ selected: selectedItemToReplace && selectedItemToReplace.itemId === item.itemId }"
            :aria-label="`${getDisplayName(item)} — ${$t('dialogs.dropForFind')}`"
            @click="dropAndTake(item)"
            @mouseenter="hotIndex = index"
            @mouseleave="hotIndex = null"
            @focus="hotIndex = index"
            @blur="hotIndex = null"
          >
            <img
              v-if="getItemImage(item)"
              :src="getItemImage(item)"
              alt=""
              class="replace-item-img"
              :class="`inv-img--${item.type}`"
            >
            <GameIcon
              v-else
              name="treasure"
              :size="32"
            />
            <span class="replace-item-text">
              <span class="replace-item-name">{{ getDisplayName(item) }}</span>
              <span
                v-if="getItemDamage(item) > 0"
                class="replace-item-stat"
              >+{{ getItemDamage(item) }}</span>
              <span
                v-else-if="item.treasureValue > 0"
                class="replace-item-stat replace-item-stat--gold"
              >{{ item.treasureValue }}</span>
              <span class="replace-item-hint">{{ $t('dialogs.dropForFind') }}</span>
            </span>
          </button>
        </div>
      </div>

      <div class="inv-actions">
        <button
          type="button"
          class="inv-btn inv-btn--ghost btn-secondary"
          @click="skipItem"
        >
          {{ $t('dialogs.leaveItem') }}
          <span class="kbd-hint">Esc</span>
        </button>
      </div>
    </section>
  </div>
</template>

<script setup>
import GameIcon from '@/components/ui/GameIcon.vue';
import { ref, onMounted, onUnmounted, nextTick, watch } from 'vue';
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

// Arrows from the find to every item it could replace: curves in an SVG laid over .inv-swap
const swapEl = ref(null);
const foundEl = ref(null);
const cardEls = [];
const arrows = ref([]);
const arrowBox = ref({ w: 0, h: 0 });
const hotIndex = ref(null);
const drawArrows = () => {
  if (!swapEl.value || !foundEl.value) return;
  const box = swapEl.value.getBoundingClientRect();
  const icon = foundEl.value.querySelector('.inv-found__icon') || foundEl.value;
  const from = icon.getBoundingClientRect();
  const fx = from.left + from.width / 2 - box.left;
  const fy = from.bottom - box.top - 4;
  arrowBox.value = { w: box.width, h: box.height };
  arrows.value = props.inventoryForCategory.map((_, index) => {
    const card = cardEls[index];
    if (!card) return '';
    const to = card.getBoundingClientRect();
    const tx = to.left + to.width / 2 - box.left;
    // the line stops a head's length (10px) above the card: the head's base sits on its end, the tip near the card
    const ty = to.top - box.top - 14;
    const bend = Math.max(18, (ty - fy) * 0.55);
    return `M ${fx} ${fy} C ${fx} ${fy + bend}, ${tx} ${ty - bend}, ${tx} ${ty}`;
  });
};
watch(() => props.inventoryForCategory, () => nextTick(drawArrows), { deep: true });

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

// the backend names the category in the singular ("weapon"); the texts are keyed by the pack's groups
const CATEGORY_GROUP = { key: 'keys', weapon: 'weapons', spell: 'spells', treasure: 'treasures' };
const replaceLabel = (category) => {
  const group = CATEGORY_GROUP[category] || category;
  return ['keys', 'weapons', 'spells', 'treasures'].includes(group)
    ? t(`dialogs.replaceFrom.${group}`)
    : t('dialogs.replaceFrom.other', { category });
};

const selectItemToReplace = (item) => {
  selectedItemToReplace.value = item;
  emit('select-item', item);
};

// one click: the item goes, the find takes its place
const dropAndTake = (item) => {
  selectItemToReplace(item);
  emit('replace-item', item);
};

const skipItem = () => {
  emit('skip-item');
};

const onKeyDown = (e) => {
  if (e.key === 'Escape') {
    skipItem();
    e.preventDefault();
    e.stopPropagation();
  }
};

onMounted(() => {
  window.addEventListener('keydown', onKeyDown, true);
  window.addEventListener('resize', drawArrows);
  nextTick(drawArrows);
  // images change the layout once they load
  setTimeout(drawArrows, 350);
});
onUnmounted(() => {
  window.removeEventListener('keydown', onKeyDown, true);
  window.removeEventListener('resize', drawArrows);
});
</script>

<style scoped>
.inventory-full-overlay {
  position: fixed;
  inset: 0;
  z-index: var(--z-modal);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(5, 3, 2, 0.6);
  animation: invFade 0.25s ease-out;
}

@keyframes invFade {
  from { opacity: 0; }
  to { opacity: 1; }
}

.inventory-full-card {
  width: min(720px, 100%);
  max-height: calc(100dvh - 32px);
  overflow-x: hidden;
  overflow-y: auto;
  box-sizing: border-box;
  padding: 22px 26px 18px;
  display: flex;
  flex-direction: column;
  gap: 14px;
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.97) 0%, rgba(12, 7, 5, 0.97) 100%);
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  box-shadow: 0 0 40px rgba(var(--ember-rgb), 0.2), 0 14px 34px rgba(0, 0, 0, 0.85);
  color: var(--text-primary);
  font-family: var(--font-body);
  animation: invRise 0.3s ease-out;
}

@keyframes invRise {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

.inv-caption {
  align-self: center;
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.32em;
  text-transform: uppercase;
  color: var(--text-accent);
}

/* the find and the items it could replace, joined by arrows */
.inv-swap {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 56px;
}

.inv-arrows {
  position: absolute;
  inset: 0;
  overflow: visible;
  pointer-events: none;
}

.inv-arrow {
  fill: none;
  stroke: rgba(var(--ember-rgb), 0.55);
  stroke-width: 1.6;
  stroke-linecap: round;
  stroke-dasharray: 4 6;
  transition: stroke var(--transition-fast), stroke-width var(--transition-fast);
}

.inv-arrow--hot {
  stroke: var(--color-primary);
  stroke-width: 2;
  stroke-dasharray: none;
}

.inv-arrowhead {
  fill: rgba(var(--ember-rgb), 0.55);
}

.inv-arrowhead--hot {
  fill: var(--color-primary);
}

/* the find, centred */
.inv-found {
  align-self: center;
  display: flex;
  align-items: center;
  gap: 16px;
}

.inv-found__icon {
  width: 80px;
  height: 80px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #d9c08a;
  background: radial-gradient(circle, rgba(255, 170, 70, 0.28) 0%, rgba(var(--ember-rgb), 0.08) 45%, rgba(0, 0, 0, 0) 70%);
}

.inv-found__img {
  width: 80px;
  height: 80px;
  object-fit: contain;
  filter: brightness(1.2) drop-shadow(0 0 10px rgba(var(--ember-rgb), 0.35)) drop-shadow(0 4px 4px #000);
}

/* the dagger and the axe fill only half of their art */
.inv-img--dagger {
  transform: scale(1.5);
}

.inv-img--axe {
  transform: scale(1.2);
}

.inv-found__text {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.inv-found__name {
  font-family: var(--font-display);
  font-size: 30px;
  line-height: 1;
  color: var(--text-heading);
  text-shadow: 0 2px 6px #000;
}

.inv-found__stats {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.inv-stat {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-family: var(--font-display);
  font-size: 20px;
  color: var(--text-heading);
}

.inv-stat :deep(svg) {
  color: var(--text-accent);
}

.inv-stat--gold {
  color: #d9c08a;
}

.inv-stat--note {
  font-family: var(--font-body);
  font-size: 14px;
  font-style: italic;
  color: var(--text-secondary);
}

.inv-prompt {
  margin: -6px 0 0;
  font-style: italic;
  font-size: 16px;
  color: var(--text-secondary);
  text-align: center;
}

/* what goes: cards with the item, the chosen one in ember */
.replace-items {
  position: relative;
  display: flex;
  justify-content: center;
  gap: 12px;
}

.replace-item {
  /* one row whatever the count (the pack holds at most three of a kind): the item on top, the words under it */
  flex: 0 1 190px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 12px 10px 10px;
  box-sizing: border-box;
  border: 1px solid rgba(234, 220, 192, 0.2);
  background: rgba(0, 0, 0, 0.35);
  color: var(--text-primary);
  font: inherit;
  cursor: pointer;
  transition: border-color var(--transition-fast), background var(--transition-fast);
}

.replace-item:hover,
.replace-item:focus-visible {
  outline: none;
  border-color: rgba(var(--ember-rgb), 0.85);
  background: rgba(var(--ember-rgb), 0.12);
}

.replace-item.selected {
  border-color: rgba(var(--ember-rgb), 0.85);
  background: rgba(var(--ember-rgb), 0.14);
  box-shadow: 0 0 16px rgba(var(--ember-rgb), 0.25);
}

.replace-item-img {
  width: 56px;
  height: 56px;
  flex: none;
  object-fit: contain;
  filter: brightness(1.15) drop-shadow(0 3px 3px #000);
}

.replace-item-text {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  text-align: center;
}

.replace-item-name {
  font-weight: 600;
  font-size: 16px;
  color: var(--text-heading);
}

.replace-item-stat {
  font-family: var(--font-display);
  font-size: 20px;
  line-height: 1;
  color: var(--text-heading);
}

.replace-item-stat--gold {
  color: #d9c08a;
}

.replace-item-hint {
  margin-top: 2px;
  white-space: nowrap;
  font-size: 12px;
  font-style: italic;
  color: var(--text-muted);
  transition: color var(--transition-fast);
}

.replace-item:hover .replace-item-hint,
.replace-item:focus-visible .replace-item-hint {
  color: var(--text-accent);
}

/* the buttons, as in the fight: bone-rimmed "leave", an ember plate to replace, keycaps under them */
.inv-actions {
  display: flex;
  justify-content: center;
  flex-wrap: wrap;
  gap: 30px 12px;
  padding: 6px 0 24px;
}

.inv-btn {
  position: relative;
  flex: 0 1 320px;
  min-width: 200px;
  min-height: 56px;
  padding: 0 22px;
  border-radius: 2px;
  font-family: var(--font-display);
  font-size: 19px;
  line-height: 1.1;
  letter-spacing: 0.02em;
  text-align: center;
  cursor: pointer;
  transition: box-shadow var(--transition-fast), background var(--transition-fast), border-color var(--transition-fast);
}

.inv-btn--ember {
  border: 1px solid var(--color-primary);
  background: linear-gradient(180deg, rgba(255, 130, 40, 0.38) 0%, rgba(150, 60, 10, 0.38) 100%);
  color: #ffe2b8;
  text-shadow: 0 1px 2px #000;
  box-shadow: 0 0 26px rgba(var(--ember-rgb), 0.38), inset 0 1px 0 rgba(255, 220, 180, 0.3), inset 0 -2px 6px rgba(0, 0, 0, 0.35);
}

.inv-btn--ember:hover:not(:disabled),
.inv-btn--ember:focus-visible {
  outline: none;
  background: linear-gradient(180deg, rgba(255, 150, 60, 0.5) 0%, rgba(170, 70, 15, 0.5) 100%);
}

.inv-btn--ember:disabled {
  opacity: 0.4;
  cursor: default;
  box-shadow: none;
}

.inv-btn--ghost {
  border: 1px solid rgba(234, 220, 192, 0.35);
  background: rgba(0, 0, 0, 0.3);
  color: var(--text-primary);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
}

.inv-btn--ghost:hover,
.inv-btn--ghost:focus-visible {
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
  .inventory-full-card {
    padding: 18px 14px 12px;
  }

  .inv-found__name {
    font-size: 24px;
  }

  .replace-items {
    gap: 8px;
  }

  .replace-item-hint {
    white-space: normal;
  }
}
</style>
