<template>
  <div class="duel-panel" data-testid="duel-opponent-choice">
    <div class="duel-header"><GameIcon name="sword" :size="18" /> {{ $t('field.arena') }}</div>
    <p class="duel-prompt">
      {{ $t('dialogs.duel.prompt') }}
      {{ $t('dialogs.duel.promptRules') }}
    </p>
    <div class="duel-targets">
      <button
        v-for="player in players"
        :key="player.id"
        type="button"
        class="duel-target"
        :disabled="disabled"
        :data-testid="`duel-opponent-${player.id}`"
        @click="emit('choose', player.id)"
      >
        <GameIcon name="sword" :size="16" /> {{ player.label }}<span v-if="player.treasures" class="duel-hint"> · <GameIcon name="treasure" :size="14" /> {{ player.treasures }}</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import GameIcon from '@/components/ui/GameIcon.vue';
defineProps({
  /** The rivals: [{ id, label, treasures }] */
  players: { type: Array, default: () => [] },
  disabled: { type: Boolean, default: false }
});

// opponentId
const emit = defineEmits(['choose']);
</script>

<style scoped>
/* Battle Arena: the challenger picks the rival — in the decision plaque */
.duel-panel {
  margin: 10px 0;
  padding: 12px 14px;
  border: 1px solid rgba(var(--ember-rgb), 0.35);
  background: rgba(0, 0, 0, 0.35);
  color: var(--text-primary);
  text-align: center;
}

.duel-header {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  margin-bottom: 6px;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 1.1;
  color: var(--text-heading);
}

.duel-prompt {
  margin: 0 0 10px;
  font-size: 14px;
  font-style: italic;
  line-height: 1.4;
  color: var(--text-secondary);
}

.duel-targets {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: center;
}

.duel-target {
  min-height: 44px;
  padding: 0 14px;
  border: 1px solid rgba(var(--ember-rgb), 0.7);
  background: rgba(var(--ember-rgb), 0.18);
  color: var(--text-heading);
  font: inherit;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: background var(--transition-fast), box-shadow var(--transition-fast);
}

.duel-target:hover:not(:disabled),
.duel-target:focus-visible {
  outline: none;
  box-shadow: 0 0 14px rgba(var(--ember-rgb), 0.45);
}

.duel-target:disabled {
  opacity: 0.5;
  cursor: default;
}

.duel-hint {
  font-size: 12px;
  font-weight: 400;
  opacity: 0.85;
}
</style>
