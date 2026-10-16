<template>
  <div
    class="loading-overlay"
    :data-loading-status="status || 'Processing...'"
  >
    <div class="loading-indicator-mini">
      <TorchFlame :size="14" />
      <span>{{ label }}</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import TorchFlame from '@/components/game/TorchFlame.vue';

const props = defineProps({
  /** English status from GameView; it stays English as the data-loading-status hook (the e2e reads it) */
  status: {
    type: String,
    default: ''
  }
});

const { t } = useI18n();

// English status → loading.* key; an unknown status is shown as it is
const STATUS_KEYS = {
  'Adding second player...': 'adding-second-player',
  'Casting Dark Thorn...': 'casting-dark-thorn',
  'Double Attack! Rerolling...': 'double-attack',
  'Ending turn...': 'ending-turn',
  'Finalizing battle and picking up item...': 'finalizing-pickup',
  'Finalizing battle...': 'finalizing',
  'Getting second player ready...': 'second-player-ready',
  'Lightning Attack...': 'lightning-attack',
  'Loading game data...': 'loading-game',
  'Locking in strength...': 'locking-strength',
  'Picking up item...': 'picking-up',
  'Reincarnating...': 'reincarnating',
  'Replacing item...': 'replacing',
  'Retreating...': 'retreating',
  'Skipping item...': 'skipping-item',
  'Skipping turn...': 'skipping-turn',
  'Summoning the rival...': 'summoning',
  'Swapping places...': 'swapping',
  'Teleporting...': 'teleporting',
  'Updating game state...': 'updating-state',
  'Updating game status...': 'updating-status',
  'Sneaking past...': 'sneaking',
  'Moving player...': 'moving',
  'Starting game...': 'starting',
  'Getting player ready...': 'player-ready'
};

const label = computed(() => {
  if (!props.status) return t('loading.processing');
  const key = STATUS_KEYS[props.status];
  return key ? t(`loading.${key}`) : props.status;
});
</script>

<style scoped>
/* A short wait in the game: a plaque with a small flame at the top says what happens; the field stays usable */
.loading-overlay {
  position: fixed;
  top: 104px; /* under the turn's headline */
  left: 50%;
  z-index: var(--z-overlay);
  transform: translateX(-50%);
  pointer-events: none;
  animation: loadingPlaqueIn 0.25s ease-out;
}

.loading-indicator-mini {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 20px 8px 16px;
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.96) 0%, rgba(12, 7, 5, 0.96) 100%);
  box-shadow: 0 0 24px rgba(255, 100, 20, 0.25), 0 8px 18px rgba(0, 0, 0, 0.8);
  font-family: var(--font-body);
  font-style: italic;
  font-size: 16px;
  white-space: nowrap;
  color: var(--text-primary);
}

@keyframes loadingPlaqueIn {
  from { opacity: 0; transform: translate(-50%, -8px); }
  to { opacity: 1; transform: translate(-50%, 0); }
}
</style>
