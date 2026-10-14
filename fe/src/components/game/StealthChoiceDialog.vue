<template>
  <div
    v-if="show"
    class="stealth-overlay"
    data-testid="stealth-dialog"
  >
    <div class="stealth-card">
      <div class="stealth-header">
        <span class="stealth-label">🗡️ Stealth</span>
      </div>

      <div class="stealth-monster">
        <ThemedImage
          :src="monsterImage"
          :emoji="monsterEmoji"
          :alt="monsterName"
          img-class="stealth-monster-image"
          emoji-class="stealth-monster-emoji"
        />
        <div class="stealth-monster-name">{{ monsterName }}</div>
        <div v-if="monsterHp" class="stealth-monster-hp">♥ {{ monsterHp }}</div>
      </div>

      <p class="stealth-prompt">The Thief can slip past this monster without a fight. What will she do?</p>

      <div class="stealth-actions">
        <button
          class="btn-fight"
          data-testid="stealth-fight"
          @click="choose(false)"
        >
          ⚔️ Fight <span class="kbd-hint">(Enter)</span>
        </button>
        <button
          class="btn-sneak"
          data-testid="stealth-sneak"
          @click="choose(true)"
        >
          🥷 Sneak past <span class="kbd-hint">(S)</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import { getMonsterDisplayName, getMonsterImage, getMonsterEmoji } from '@/utils/monsterUtils';
import ThemedImage from '@/components/ThemedImage.vue';

const props = defineProps({
  show: { type: Boolean, default: false },
  /** Field item at the destination: { name, type, guardHP, guardDefeated } */
  monster: { type: Object, default: null }
});

const emit = defineEmits(['choose']);

const monsterKey = computed(() => props.monster?.name || props.monster?.type || '');
const monsterName = computed(() => getMonsterDisplayName(monsterKey.value) || monsterKey.value || 'Monster');
const monsterImage = computed(() => getMonsterImage({ monster_name: monsterKey.value, monster: props.monster?.guardHP || 0 }));
const monsterEmoji = computed(() => getMonsterEmoji({ monster_name: monsterKey.value, monster: props.monster?.guardHP || 0 }));
const monsterHp = computed(() => props.monster?.guardHP || 0);

// true = sneak past (ignore the monster), false = fight
const choose = (sneak) => emit('choose', sneak);

const onKeyDown = (e) => {
  if (!props.show) return;
  if (e.key === 'Enter') {
    choose(false);
  } else if (e.key === 's' || e.key === 'S' || e.key === 'ы' || e.key === 'Ы') {
    choose(true);
  } else {
    return;
  }
  e.preventDefault();
  e.stopPropagation();
};

onMounted(() => window.addEventListener('keydown', onKeyDown, true));
onUnmounted(() => window.removeEventListener('keydown', onKeyDown, true));
</script>

<style scoped>
.stealth-overlay {
  position: fixed;
  inset: 0;
  z-index: 1100;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(8, 4, 20, 0.72);
  backdrop-filter: blur(3px);
}

.stealth-card {
  width: min(360px, calc(100vw - 32px));
  padding: 18px 20px 20px;
  border-radius: 12px;
  border: 1px solid rgba(var(--accent-light-rgb), 0.45);
  background: linear-gradient(160deg, #1f1538 0%, #120c22 100%);
  box-shadow: 0 0 0 1px rgba(var(--accent-light-rgb), 0.2), 0 18px 40px rgba(0, 0, 0, 0.6);
  color: #e6e1ff;
  text-align: center;
}

.stealth-header {
  font-size: 0.78rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #b8adff;
  margin-bottom: 12px;
}

.stealth-monster {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  margin-bottom: 12px;
}

.stealth-monster-image {
  width: 84px;
  height: 84px;
  object-fit: contain;
  filter: drop-shadow(0 0 12px rgba(255, 82, 82, 0.5));
}

.stealth-monster-emoji {
  font-size: 56px;
  line-height: 1;
}

.stealth-monster-name {
  font-weight: 700;
  font-size: 1.05rem;
}

.stealth-monster-hp {
  font-size: 0.85rem;
  color: #ff8a80;
}

.stealth-prompt {
  margin: 0 0 14px;
  font-size: 0.9rem;
  color: #c9c2e6;
  line-height: 1.4;
}

.stealth-actions {
  display: flex;
  gap: 10px;
}

.stealth-actions button {
  flex: 1 1 0;
  padding: 10px 12px;
  border: none;
  border-radius: 8px;
  font-size: 0.9rem;
  font-weight: 600;
  cursor: pointer;
  color: #fff;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.stealth-actions button:hover {
  transform: translateY(-2px);
}

.btn-fight {
  background: linear-gradient(135deg, #b71c1c, #ff5252);
  box-shadow: 0 4px 14px rgba(255, 82, 82, 0.35);
}

.btn-sneak {
  background: linear-gradient(135deg, #4a2590, #836eff);
  box-shadow: 0 4px 14px rgba(var(--accent-light-rgb), 0.35);
}

.kbd-hint {
  font-size: 0.72rem;
  opacity: 0.75;
  font-weight: 400;
}
</style>
