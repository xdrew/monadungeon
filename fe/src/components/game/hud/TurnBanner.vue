<template>
  <div class="turn-banner">
    <div class="turn-banner__meta">
      <!-- The first <h3> on the page reads "Turn: N" (the e2e reads it): no text-transform here,
           Playwright's innerText would apply it -->
      <h3
        v-if="turn"
        class="turn-banner__turn"
      >Turn: {{ turn }}</h3>
      <h3
        v-else
        class="turn-banner__turn"
      >Game Setup</h3>
      <span
        v-if="sub"
        class="turn-banner__sub"
      >· {{ sub }}</span>
      <span
        v-if="regent"
        class="turn-banner__sub regent-badge"
        data-testid="regent-badge"
        title="Karak: Regent expansion — Lord Karak rooms, Giant Bats and Skeleton Mages"
      >· <GameIcon
        name="crown"
        :size="13"
      /> Regent</span>
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
import GameIcon from '@/components/ui/GameIcon.vue';

defineProps({
  turn: { type: Number, default: null },
  headline: { type: String, default: '' },
  sub: { type: String, default: '' },
  regent: { type: Boolean, default: false }
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
</style>
