<template>
  <!-- One die of the fight: bone face, dark pips; `.dice-face` is an e2e hook -->
  <div
    class="dice-face"
    :class="{ 'rolling-dice': rolling, 'dice-landed': landed }"
    :data-value="value"
    :style="{ '--tilt': `${tilt}deg`, animationDelay: rolling ? `${delay}s` : undefined }"
  >
    <svg
      viewBox="0 0 34 34"
      aria-hidden="true"
    >
      <rect
        x="2"
        y="2"
        width="30"
        height="30"
        rx="5"
        class="dice-face__body"
      />
      <circle
        v-for="(pip, i) in pips"
        :key="i"
        :cx="pip[0]"
        :cy="pip[1]"
        r="2.8"
        class="dice-face__pip"
      />
    </svg>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  value: { type: Number, default: 1 },
  rolling: { type: Boolean, default: false },
  landed: { type: Boolean, default: false },
  /** the die lies a little askew, in degrees */
  tilt: { type: Number, default: 0 },
  /** stagger of the rolling animation, in seconds */
  delay: { type: Number, default: 0 }
});

const L = 10;
const M = 17;
const R = 24;
const PIPS = {
  1: [[M, M]],
  2: [[L, L], [R, R]],
  3: [[L, L], [M, M], [R, R]],
  4: [[L, L], [R, L], [L, R], [R, R]],
  5: [[L, L], [R, L], [M, M], [L, R], [R, R]],
  6: [[L, L], [R, L], [L, M], [R, M], [L, R], [R, R]]
};

const pips = computed(() => PIPS[props.value] || []);
</script>

<style scoped>
.dice-face {
  width: var(--die-size, 64px);
  height: var(--die-size, 64px);
  transform: rotate(var(--tilt));
  filter: drop-shadow(0 6px 6px rgba(0, 0, 0, 0.85));
}

.dice-face svg {
  display: block;
  width: 100%;
  height: 100%;
}

.dice-face__body {
  fill: var(--text-primary);
}

.dice-face__pip {
  fill: #1a0f08;
}

.rolling-dice {
  animation: rollDice3D 0.45s linear infinite, floatDice 0.9s ease-in-out infinite;
}

.dice-landed {
  animation: diceLand 0.4s ease-out;
}

@keyframes rollDice3D {
  0% { transform: rotateX(0deg) rotateY(0deg) rotateZ(0deg); }
  25% { transform: rotateX(180deg) rotateY(90deg) rotateZ(45deg); }
  50% { transform: rotateX(360deg) rotateY(180deg) rotateZ(90deg); }
  75% { transform: rotateX(540deg) rotateY(270deg) rotateZ(135deg); }
  100% { transform: rotateX(720deg) rotateY(360deg) rotateZ(180deg); }
}

@keyframes floatDice {
  0%, 100% { translate: 0 0; scale: 1; }
  25% { translate: 0 -6px; scale: 1.05; }
  50% { translate: 0 0; scale: 1; }
  75% { translate: 0 -3px; scale: 1.02; }
}

@keyframes diceLand {
  0% { transform: rotate(var(--tilt)) scale(1.15); filter: drop-shadow(0 0 18px rgba(var(--ember-rgb), 0.7)); }
  50% { transform: rotate(var(--tilt)) scale(0.95); }
  100% { transform: rotate(var(--tilt)) scale(1); }
}
</style>
