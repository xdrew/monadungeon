<template>
  <div
    v-if="show"
    class="fateweaver-overlay"
    data-testid="fateweaver-dialog"
  >
    <div class="fateweaver-card">
      <div class="fateweaver-header">
        <span class="fateweaver-label"><GameIcon name="eye" :size="16" /> Fateweaver</span>
      </div>

      <p class="fateweaver-prompt">
        Two tokens were drawn for the new room. Choose the one to place — the other goes back into the bag.
      </p>

      <div class="fateweaver-tokens">
        <div
          v-for="(token, index) in tokens"
          :key="token.itemId || index"
          class="fateweaver-token"
          :class="{ 'is-monster': token.isMonster }"
        >
          <ThemedImage
            :src="token.image"
            :emoji="token.emoji"
            :alt="token.label"
            img-class="fateweaver-token-image"
            emoji-class="fateweaver-token-emoji"
          />
          <div class="fateweaver-token-name">{{ token.label }}</div>
          <div class="fateweaver-token-badges">
            <span v-if="token.isMonster" class="badge badge-hp"><GameIcon name="heart" :size="13" /> HP {{ token.hp }}</span>
            <span v-if="token.value > 0" class="badge badge-value"><GameIcon name="treasure" :size="13" /> {{ token.value }} VP</span>
          </div>
          <button
            class="btn-place"
            :data-testid="`fateweaver-item-${index}`"
            @click="choose(token.itemId)"
          >
            Place <span class="kbd-hint">({{ index + 1 }})</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import GameIcon from '@/components/ui/GameIcon.vue';
import { computed, onMounted, onUnmounted } from 'vue';
import { getItemEmoji, getItemImage, formatItemName } from '@/utils/itemUtils';
import { getMonsterDisplayName, getMonsterImage, getMonsterEmoji } from '@/utils/monsterUtils';
import ThemedImage from '@/components/ThemedImage.vue';

const props = defineProps({
  show: { type: Boolean, default: false },
  /** Oracle "Fateweaver": { fieldPlace, items: [{ itemId, name, type, guardHP, guardDefeated, treasureValue }, ...] } */
  choice: { type: Object, default: null }
});

const emit = defineEmits(['choose']);

const tokens = computed(() => (props.choice?.items || []).map((item) => {
  const isMonster = !item.guardDefeated && (item.guardHP || 0) > 0;
  return {
    itemId: item.itemId,
    isMonster,
    hp: item.guardHP || 0,
    value: item.treasureValue || 0,
    label: isMonster
      ? (getMonsterDisplayName(item.name) || formatItemName(item.name))
      : formatItemName(item.name),
    image: isMonster ? getMonsterImage({ monster_name: item.name, monster: item.guardHP || 0 }) : getItemImage(item),
    emoji: isMonster ? getMonsterEmoji({ monster_name: item.name, monster: item.guardHP || 0 }) : getItemEmoji(item)
  };
}));

const choose = (itemId) => {
  if (itemId) emit('choose', itemId);
};

const onKeyDown = (e) => {
  if (!props.show) return;
  const index = ['1', '2'].indexOf(e.key);
  if (index === -1 || !tokens.value[index]) return;
  choose(tokens.value[index].itemId);
  e.preventDefault();
  e.stopPropagation();
};

onMounted(() => window.addEventListener('keydown', onKeyDown, true));
onUnmounted(() => window.removeEventListener('keydown', onKeyDown, true));
</script>

<style scoped>
.fateweaver-overlay {
  position: fixed;
  inset: 0;
  z-index: 1100;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(8, 4, 20, 0.72);
  backdrop-filter: blur(3px);
}

.fateweaver-card {
  width: min(440px, calc(100vw - 32px));
  padding: 18px 20px 20px;
  border-radius: 12px;
  border: 1px solid rgba(var(--accent-light-rgb), 0.45);
  background: linear-gradient(160deg, #1f1538 0%, #120c22 100%);
  box-shadow: 0 0 0 1px rgba(var(--accent-light-rgb), 0.2), 0 18px 40px rgba(0, 0, 0, 0.6);
  color: #e6e1ff;
  text-align: center;
}

.fateweaver-header {
  font-size: 0.78rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #b8adff;
  margin-bottom: 12px;
}

.fateweaver-prompt {
  margin: 0 0 14px;
  font-size: 0.9rem;
  color: #c9c2e6;
  line-height: 1.4;
}

.fateweaver-tokens {
  display: flex;
  gap: 12px;
}

.fateweaver-token {
  flex: 1 1 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 12px 10px;
  border-radius: 10px;
  border: 1px solid rgba(var(--accent-light-rgb), 0.25);
  background: rgba(255, 255, 255, 0.03);
}

.fateweaver-token.is-monster {
  border-color: rgba(255, 82, 82, 0.35);
}

.fateweaver-token-image {
  width: 72px;
  height: 72px;
  object-fit: contain;
  filter: drop-shadow(0 0 10px rgba(var(--accent-light-rgb), 0.45));
}

.fateweaver-token.is-monster .fateweaver-token-image {
  filter: drop-shadow(0 0 12px rgba(255, 82, 82, 0.5));
}

.fateweaver-token-emoji {
  font-size: 48px;
  line-height: 1;
}

.fateweaver-token-name {
  font-weight: 700;
  font-size: 0.95rem;
}

.fateweaver-token-badges {
  display: flex;
  gap: 6px;
  min-height: 20px;
  font-size: 0.78rem;
}

.badge-hp {
  color: #ff8a80;
}

.badge-value {
  color: #ffd54f;
}

.btn-place {
  width: 100%;
  margin-top: 4px;
  padding: 9px 12px;
  border: none;
  border-radius: 8px;
  font-size: 0.9rem;
  font-weight: 600;
  cursor: pointer;
  color: #fff;
  background: linear-gradient(135deg, #4a2590, #836eff);
  box-shadow: 0 4px 14px rgba(var(--accent-light-rgb), 0.35);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.btn-place:hover {
  transform: translateY(-2px);
}

.kbd-hint {
  font-size: 0.72rem;
  opacity: 0.75;
  font-weight: 400;
}
</style>
