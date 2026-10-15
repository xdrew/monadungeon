<template>
  <div class="curse-panel" data-testid="curse-choice">
    <div class="curse-header"><GameIcon name="skull" :size="18" /> {{ $t('dialogs.curse.title') }}</div>
    <p class="curse-prompt">
      {{ $t('dialogs.curse.prompt') }}
    </p>
    <div class="curse-targets">
      <button
        v-for="player in players"
        :key="player.id"
        type="button"
        class="curse-target"
        :class="{ 'is-holder': player.id === cursedPlayerId }"
        :disabled="disabled"
        :data-testid="`curse-player-${player.id}`"
        @click="emit('choose', player.id)"
      >
        <GameIcon name="skull" :size="16" /> {{ player.label }}<span v-if="player.id === cursedPlayerId" class="holder-hint"> ({{ $t('dialogs.curse.alreadyCursed') }})</span>
      </button>
      <button
        v-if="cursedPlayerId"
        type="button"
        class="curse-keep"
        :disabled="disabled"
        data-testid="curse-keep"
        @click="emit('choose', null)"
      >
        {{ $t('dialogs.curse.keepOn', { who: cursedLabel || $t('dialogs.curse.currentHolder') }) }}
      </button>
    </div>
  </div>
</template>

<script setup>
import GameIcon from '@/components/ui/GameIcon.vue';
defineProps({
  /** The other players: [{ id, label }] */
  players: { type: Array, default: () => [] },
  cursedPlayerId: { type: String, default: null },
  cursedLabel: { type: String, default: '' },
  disabled: { type: Boolean, default: false }
});

// targetPlayerId, or null to leave the token where it is
const emit = defineEmits(['choose']);
</script>

<style scoped>
/* Curse of Karak: who carries the token — in the decision plaque or inside the fight scene */
.curse-panel {
  margin: 10px 0;
  padding: 12px 14px;
  border: 1px solid rgba(var(--ember-rgb), 0.35);
  background: rgba(0, 0, 0, 0.35);
  color: var(--text-primary);
  text-align: center;
  border-color: rgba(201, 139, 217, 0.45);
}

.curse-header {
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

.curse-header :deep(svg) {
  color: #c98bd9;
}

.curse-prompt {
  margin: 0 0 10px;
  font-size: 14px;
  font-style: italic;
  line-height: 1.4;
  color: var(--text-secondary);
}

.curse-targets {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: center;
}

.curse-targets button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
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

.curse-targets button:hover:not(:disabled),
.curse-targets button:focus-visible {
  outline: none;
  box-shadow: 0 0 14px rgba(var(--ember-rgb), 0.45);
}

.curse-targets button:disabled {
  opacity: 0.5;
  cursor: default;
}

.curse-target.is-holder,
.curse-keep {
  border-color: rgba(234, 220, 192, 0.35);
  background: transparent;
  color: var(--text-primary);
}

.holder-hint {
  font-size: 12px;
  font-weight: 400;
  opacity: 0.8;
}
</style>
