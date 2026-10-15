<template>
  <div
    class="loading-overlay"
    :data-loading-status="status || 'Processing...'"
  >
    <div class="loading-indicator-mini">
      <div class="mini-spinner" />
      <span>{{ label }}</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

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
.loading-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 900;
}

.loading-indicator-mini {
  background-color: rgba(37, 37, 37, 0.9);
  border-radius: 8px;
  padding: 1rem 1.5rem;
  display: flex;
  align-items: center;
  gap: 1rem;
  color: white;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}

.mini-spinner {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 3px solid rgba(255, 255, 255, 0.1);
  border-top-color: #ffc107;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
</style> 