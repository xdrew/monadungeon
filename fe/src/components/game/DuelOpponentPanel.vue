<template>
  <!-- Karak: Regent — the Battle Arena: the hero who opened it calls a rival out, as a scene like the duel itself -->
  <div
    class="duel-choice"
    data-testid="arena-banner"
    role="dialog"
    aria-modal="true"
  >
    <div
      class="duel-choice__inner"
      data-testid="duel-opponent-choice"
    >
      <span class="duel-choice__caption">{{ $t('duelScene.caption') }}</span>
      <h2 class="duel-choice__title">
        {{ $t('duelScene.chooseRival') }}
      </h2>
      <p class="duel-choice__prompt">
        {{ $t('dialogs.duel.prompt') }}
        {{ $t('dialogs.duel.promptRules') }}
      </p>
      <div class="duel-choice__rivals">
        <button
          v-for="player in players"
          :key="player.id"
          type="button"
          class="duel-rival"
          :disabled="disabled"
          :data-testid="`duel-opponent-${player.id}`"
          @click="emit('choose', player.id)"
        >
          <span class="duel-rival__ring">
            <img
              :src="player.portrait"
              alt=""
            >
          </span>
          <span class="duel-rival__name">{{ player.label }}</span>
          <span class="duel-rival__stats">
            <span :title="$t('dialogs.lordKarak.weapons')"><GameIcon
              name="sword"
              :size="14"
            /> +{{ player.weapons }}</span>
            <span :title="$t('dialogs.lordKarak.treasure')"><GameIcon
              name="treasure"
              :size="14"
            /> {{ player.treasures }}</span>
          </span>
          <span class="duel-rival__call">{{ $t('duelScene.callOut') }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import GameIcon from '@/components/ui/GameIcon.vue';

defineProps({
  /** The rivals: [{ id, label, portrait, weapons, treasures }] */
  players: { type: Array, default: () => [] },
  disabled: { type: Boolean, default: false }
});

// opponentId
const emit = defineEmits(['choose']);
</script>

<style scoped>
.duel-choice {
  position: fixed;
  inset: 0;
  z-index: var(--z-modal);
  display: flex;
  align-items: center;
  justify-content: safe center;
  flex-direction: column;
  padding: 24px 16px;
  box-sizing: border-box;
  overflow-y: auto;
  font-family: var(--font-body);
  color: var(--text-primary);
  background-color: rgba(5, 3, 2, 0.96);
  background-image:
    radial-gradient(ellipse at 50% 45%, rgba(var(--ember-rgb), 0.16) 0%, rgba(0, 0, 0, 0) 50%),
    radial-gradient(ellipse at 50% 100%, rgba(60, 35, 20, 0.6) 0%, rgba(0, 0, 0, 0) 60%);
  animation: duelChoiceIn 0.35s ease-out;
}

@keyframes duelChoiceIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.duel-choice__inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  max-width: 960px;
  text-align: center;
}

.duel-choice__caption {
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.32em;
  text-transform: uppercase;
  color: var(--text-accent);
}

.duel-choice__title {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: clamp(32px, 5vw, 52px);
  line-height: 1;
  color: var(--text-heading);
  text-shadow: 0 0 24px rgba(var(--ember-rgb), 0.35), 0 4px 12px #000;
}

.duel-choice__prompt {
  max-width: 560px;
  margin: 0 0 8px;
  font-style: italic;
  font-size: 16px;
  line-height: 1.45;
  color: var(--text-secondary);
}

.duel-choice__rivals {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 18px;
}

.duel-rival {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  width: 200px;
  padding: 18px 14px 14px;
  border: 1px solid rgba(var(--ember-rgb), 0.4);
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.92) 0%, rgba(12, 7, 5, 0.92) 100%);
  color: var(--text-primary);
  font: inherit;
  cursor: pointer;
  transition: border-color var(--transition-fast), box-shadow var(--transition-fast), transform var(--transition-fast);
}

.duel-rival:hover:not(:disabled),
.duel-rival:focus-visible {
  outline: none;
  border-color: rgba(var(--ember-rgb), 0.9);
  box-shadow: 0 0 26px rgba(255, 100, 20, 0.35);
  transform: translateY(-3px);
}

.duel-rival:disabled {
  opacity: 0.5;
  cursor: default;
}

.duel-rival__ring {
  display: flex;
  align-items: flex-start;
  width: 120px;
  height: 120px;
  overflow: hidden;
  border-radius: 50%;
  background: radial-gradient(circle at 50% 35%, #4a3020 0%, #140c07 75%);
  box-shadow: 0 0 0 3px var(--iron), 0 0 0 5px var(--iron-edge);
}

.duel-rival__ring img {
  width: 100%;
  height: 150%;
  object-fit: cover;
  object-position: top;
  transform: scaleX(-1);
}

.duel-rival__name {
  font-family: var(--font-display);
  font-size: 20px;
  line-height: 1.1;
  color: var(--text-heading);
}

.duel-rival__stats {
  display: flex;
  gap: 14px;
  font-size: 14px;
  color: var(--text-secondary);
}

.duel-rival__stats span {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.duel-rival__call {
  margin-top: 4px;
  padding: 6px 16px;
  border: 1px solid rgba(var(--ember-rgb), 0.7);
  background: rgba(var(--ember-rgb), 0.18);
  font-family: var(--font-display);
  font-size: 18px;
  color: var(--text-heading);
}

@media (prefers-reduced-motion: reduce) {
  .duel-choice,
  .duel-rival {
    animation: none;
    transition: none;
  }
}
</style>
