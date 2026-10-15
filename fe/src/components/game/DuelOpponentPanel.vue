<template>
  <div class="duel-panel" data-testid="duel-opponent-choice">
    <div class="duel-header">⚔️ Battle Arena</div>
    <p class="duel-prompt">
      You are the first hero in this arena. Choose your opponent — their figure is moved here and the duel begins.
      You roll first; the loser loses 1 HP and the winner takes one item.
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
        ⚔️ {{ player.label }}<span v-if="player.treasures" class="duel-hint"> · 💰 {{ player.treasures }}</span>
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  /** The rivals: [{ id, label, treasures }] */
  players: { type: Array, default: () => [] },
  disabled: { type: Boolean, default: false }
});

// opponentId
const emit = defineEmits(['choose']);
</script>

<style scoped>
.duel-panel {
  margin: 10px 0;
  padding: 12px 14px;
  border-radius: 10px;
  border: 1px solid rgba(224, 122, 42, 0.45);
  background: rgba(70, 35, 15, 0.4);
  color: #ffeedd;
  text-align: center;
}

.duel-header {
  font-size: 0.78rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #f0b27a;
  margin-bottom: 6px;
}

.duel-prompt {
  margin: 0 0 10px;
  font-size: 0.88rem;
  line-height: 1.4;
  color: #f3dcc6;
}

.duel-targets {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: center;
}

.duel-target {
  padding: 8px 12px;
  border: none;
  border-radius: 8px;
  font-size: 0.88rem;
  font-weight: 600;
  cursor: pointer;
  color: #fff;
  background: linear-gradient(135deg, #b5541c, #e07a2a);
  box-shadow: 0 4px 14px rgba(224, 122, 42, 0.35);
  transition: transform 0.15s ease;
}

.duel-target:hover:not(:disabled) {
  transform: translateY(-2px);
}

.duel-target:disabled {
  opacity: 0.5;
  cursor: default;
}

.duel-hint {
  font-size: 0.75rem;
  opacity: 0.85;
  font-weight: 400;
}
</style>
