<template>
  <div class="curse-panel" data-testid="curse-choice">
    <div class="curse-header"><GameIcon name="skull" :size="18" /> Curse of Karak</div>
    <p class="curse-prompt">
      You defeated a mummy. Choose who carries the curse — their hero loses all skills until healed at a fountain.
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
        <GameIcon name="skull" :size="16" /> {{ player.label }}<span v-if="player.id === cursedPlayerId" class="holder-hint"> (already cursed)</span>
      </button>
      <button
        v-if="cursedPlayerId"
        type="button"
        class="curse-keep"
        :disabled="disabled"
        data-testid="curse-keep"
        @click="emit('choose', null)"
      >
        Keep it on {{ cursedLabel || 'the current holder' }}
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
.curse-panel {
  margin: 10px 0;
  padding: 12px 14px;
  border-radius: 10px;
  border: 1px solid rgba(120, 220, 120, 0.35);
  background: rgba(30, 60, 30, 0.35);
  color: #e6ffe6;
  text-align: center;
}

.curse-header {
  font-size: 0.78rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #a5e8a5;
  margin-bottom: 6px;
}

.curse-prompt {
  margin: 0 0 10px;
  font-size: 0.88rem;
  line-height: 1.4;
  color: #cfe9cf;
}

.curse-targets {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: center;
}

.curse-targets button {
  padding: 8px 12px;
  border: none;
  border-radius: 8px;
  font-size: 0.88rem;
  font-weight: 600;
  cursor: pointer;
  color: #fff;
  transition: transform 0.15s ease;
}

.curse-targets button:hover:not(:disabled) {
  transform: translateY(-2px);
}

.curse-targets button:disabled {
  opacity: 0.5;
  cursor: default;
}

.curse-target {
  background: linear-gradient(135deg, var(--color-primary), var(--color-primary-hover));
  box-shadow: 0 4px 14px rgba(102, 187, 106, 0.35);
}

.curse-target.is-holder {
  background: linear-gradient(135deg, #4e5d4e, #7a8c7a);
}

.holder-hint {
  font-size: 0.75rem;
  opacity: 0.8;
  font-weight: 400;
}

.curse-keep {
  background: linear-gradient(135deg, #37474f, #607d8b);
}
</style>
