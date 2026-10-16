<template>
  <div class="turn-banner">
    <div class="turn-banner__meta">
      <!-- The first <h3> on the page reads "Turn: N" in en (the e2e reads it): no text-transform here,
           Playwright's innerText would apply it -->
      <h3
        v-if="turn"
        class="turn-banner__turn"
      >{{ t('hud.turn', { n: turn }) }}</h3>
      <h3
        v-else
        class="turn-banner__turn"
      >{{ t('hud.turnSetup') }}</h3>
      <span
        v-if="sub"
        class="turn-banner__sub"
      >· {{ sub }}</span>
      <!-- an online table with a time limit: what is left of the current turn -->
      <span
        v-if="clock"
        class="turn-banner__sub turn-banner__clock"
        :class="{ 'turn-banner__clock--low': secondsLeft < 20 }"
        data-testid="turn-clock"
        :title="t('tables.turnLimit')"
      >· <GameIcon
        name="hourglass"
        :size="13"
      /> {{ clock }}</span>
      <span
        v-if="regent"
        class="turn-banner__sub regent-badge"
        data-testid="regent-badge"
        :title="t('hud.regentTitle')"
      >· <GameIcon
        name="crown"
        :size="13"
      /> {{ t('hud.regent') }}</span>
    </div>
    <p
      v-if="headline"
      class="turn-banner__headline"
    >{{ headline }}</p>
    <div
      class="turn-banner__rule"
      aria-hidden="true"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import GameIcon from '@/components/ui/GameIcon.vue';

const props = defineProps({
  turn: { type: Number, default: null },
  headline: { type: String, default: '' },
  sub: { type: String, default: '' },
  regent: { type: Boolean, default: false },
  /** when the current turn runs out, in this browser's clock (ms), or null */
  deadline: { type: Number, default: null }
});

const { t } = useI18n();

// the countdown ticks only while there is a deadline
const now = ref(Date.now());
let ticker = null;
watch(() => props.deadline, (deadline) => {
  clearInterval(ticker);
  ticker = null;
  if (deadline) {
    now.value = Date.now();
    ticker = setInterval(() => { now.value = Date.now(); }, 250);
  }
}, { immediate: true });
onBeforeUnmount(() => clearInterval(ticker));
const secondsLeft = computed(() => (props.deadline ? Math.max(0, Math.ceil((props.deadline - now.value) / 1000)) : 0));
const clock = computed(() => {
  if (!props.deadline) return '';
  const s = secondsLeft.value;
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
});
</script>

<style scoped>
.turn-banner {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  text-shadow: 0 2px 6px #000;
  text-align: center;
  max-width: 100%;
}

.turn-banner__clock {
  display: inline-flex;
  align-items: baseline;
  gap: 4px;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.12em;
}

.turn-banner__clock--low {
  color: var(--color-primary);
  text-shadow: 0 0 10px rgba(var(--ember-rgb), 0.6), 0 2px 6px #000;
}

.turn-banner__meta {
  display: flex;
  align-items: baseline;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: center;
}

.turn-banner__turn,
.turn-banner__sub {
  margin: 0;
  font-family: var(--font-body);
  font-weight: 600;
  font-size: 15px;
  line-height: 1.2;
  letter-spacing: 0.28em;
  font-variant-caps: all-small-caps;
  color: #c9a77a;
}

.turn-banner__sub {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.turn-banner__headline {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 40px;
  line-height: 1;
  color: var(--text-heading);
}

.turn-banner__rule {
  width: 420px;
  max-width: 80vw;
  height: 2px;
  margin-top: 6px;
  background: linear-gradient(90deg, rgba(var(--ember-rgb), 0) 0%, var(--color-primary) 50%, rgba(var(--ember-rgb), 0) 100%);
}

@media (max-width: 768px), (max-height: 500px) {
  .turn-banner__turn,
  .turn-banner__sub {
    font-size: 13px;
    letter-spacing: 0.2em;
  }

  .turn-banner__headline {
    font-size: 22px;
  }

  .turn-banner__rule {
    display: none;
  }
}
</style>
