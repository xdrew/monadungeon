<template>
  <!-- Hot-seat: the screen is handed to the next player; their pack, skills and choices show once they reveal it -->
  <div
    class="hotseat-curtain"
    data-testid="hotseat-curtain"
    :data-player="playerName || name"
    role="dialog"
    aria-modal="true"
    :aria-label="t('hotseat.curtainTitle', { name })"
  >
    <div class="hotseat-curtain__card">
      <span class="hotseat-curtain__caption">{{ t('hotseat.curtainCaption') }}</span>
      <span class="hotseat-curtain__portrait">
        <img
          v-if="portrait"
          :src="portrait"
          :alt="name"
        >
      </span>
      <h2 class="hotseat-curtain__title">
        {{ t('hotseat.curtainTitle', { name }) }}
      </h2>
      <!-- the person at the screen, when the hero's name does not say it already (a seat without a hero is named so) -->
      <p
        v-if="playerName && playerName !== name"
        class="hotseat-curtain__player"
        data-testid="hotseat-player"
      >
        {{ t('hotseat.curtainPlayer', { player: playerName }) }}
      </p>
      <p class="hotseat-curtain__text">
        {{ reason === 'duel' ? t('hotseat.curtainDuel', { who: playerName || name }) : t('hotseat.curtainText', { who: playerName || name }) }}
      </p>
      <button
        ref="revealButton"
        type="button"
        class="btn btn--ember btn--lg hotseat-curtain__reveal"
        data-testid="hotseat-reveal"
        @click="emit('reveal')"
      >
        {{ t('hotseat.reveal', { who: playerName || name }) }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { nextTick, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

defineProps({
  /** the hero the screen goes to (players are named by their heroes in the game) */
  name: { type: String, required: true },
  /** the person at the screen (the seat's name: the account's or a guest's) */
  playerName: { type: String, default: '' },
  portrait: { type: String, default: '' },
  /** 'turn' — their turn; 'duel' — summoned to a duel during someone else's turn */
  reason: { type: String, default: 'turn' },
});
const emit = defineEmits(['reveal']);
const { t } = useI18n();
const revealButton = ref(null);
onMounted(() => nextTick(() => revealButton.value?.focus()));
</script>

<style scoped>
.hotseat-curtain {
  position: fixed;
  inset: 0;
  /* over the HUD, the plaques and the scenes: nothing of the last player's screen shows through */
  z-index: calc(var(--z-modal) + 20);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background-color: #050302;
  background-image: radial-gradient(ellipse at 50% 40%, rgba(var(--ember-rgb), 0.22) 0%, rgba(0, 0, 0, 0) 55%);
  animation: hotseatCurtainIn 0.35s ease-out;
}

@keyframes hotseatCurtainIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.hotseat-curtain__card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  max-width: 420px;
  text-align: center;
}

.hotseat-curtain__caption {
  font-size: 13px;
  letter-spacing: 0.3em;
  text-transform: uppercase;
  color: var(--text-muted);
}

.hotseat-curtain__portrait {
  width: 132px;
  height: 132px;
  overflow: hidden;
  border-radius: 50%;
  background: radial-gradient(circle at 50% 35%, #4a3020 0%, #140c07 75%);
  box-shadow: 0 0 0 3px var(--color-secondary), 0 0 0 5px var(--iron-edge), 0 0 32px rgba(var(--ember-rgb), 0.7);
}

.hotseat-curtain__portrait img {
  width: 100%;
  height: 150%;
  object-fit: cover;
  object-position: top;
}

.hotseat-curtain__title {
  margin: 0;
  font-family: var(--font-display);
  font-size: 40px;
  line-height: 1.05;
  color: var(--text-heading);
  text-shadow: 0 2px 10px #000;
}

.hotseat-curtain__player {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
  color: var(--text-accent);
}

.hotseat-curtain__text {
  margin: 0;
  font-style: italic;
  color: var(--text-secondary);
}

.hotseat-curtain__reveal {
  margin-top: 8px;
  min-width: 260px;
}

@media (prefers-reduced-motion: reduce) {
  .hotseat-curtain {
    animation: none;
  }
}
</style>
