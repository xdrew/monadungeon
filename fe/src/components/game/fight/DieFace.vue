<template>
  <!-- One die of the fight: a real cube with six faces that tumbles while rolling and settles on its value.
       `.dice-face` and `data-value` are e2e hooks. -->
  <div
    class="dice-face"
    :class="{ 'rolling-dice': rolling, 'dice-landed': landed, 'die-rested': rested || !rolling }"
    :data-value="value"
    :style="dieStyle"
  >
    <!-- first, so the die is drawn over its shadow -->
    <div
      class="die-shadow"
      aria-hidden="true"
    />
    <div class="die-hop">
      <div
        class="die-cube"
        @animationend="onThrowEnd"
      >
        <div
          v-for="face in FACES"
          :key="face.n"
          class="die-side"
          :class="[`die-side--${face.side}`, { 'die-side--value': face.n === value }]"
          aria-hidden="true"
        >
          <span
            v-for="(pip, i) in PIPS[face.n]"
            :key="i"
            class="die-pip"
            :style="{ left: `${pip[0]}%`, top: `${pip[1]}%` }"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
  value: { type: Number, default: 1 },
  rolling: { type: Boolean, default: false },
  landed: { type: Boolean, default: false },
  /** the die lies a little askew on the table, in degrees */
  tilt: { type: Number, default: 0 },
  /** stagger between dice, in seconds: the next one lands a little later */
  delay: { type: Number, default: 0 },
  /** where the throw comes from, in die sizes to the left (a little different per die) */
  throwFrom: { type: Number, default: 1 }
});

const L = 26;
const M = 50;
const R = 74;
const PIPS = {
  1: [[M, M]],
  2: [[L, L], [R, R]],
  3: [[L, L], [M, M], [R, R]],
  4: [[L, L], [R, L], [L, R], [R, R]],
  5: [[L, L], [R, L], [M, M], [L, R], [R, R]],
  6: [[L, L], [R, L], [L, M], [R, M], [L, R], [R, R]]
};

// Opposite sides add up to 7, like a real die
const FACES = [
  { n: 1, side: 'front' },
  { n: 6, side: 'back' },
  { n: 3, side: 'right' },
  { n: 4, side: 'left' },
  { n: 5, side: 'top' },
  { n: 2, side: 'bottom' }
];

// The cube rotation that brings each value to the front
const SHOW = {
  1: [0, 0],
  2: [90, 0],
  3: [0, -90],
  4: [0, 90],
  5: [-90, 0],
  6: [0, 180]
};

// Once the throw has stopped only the face that came up is drawn: the faces turned edge-on
// would otherwise show as a dark sliver under the die
const rested = ref(false);
watch(() => props.rolling, (now) => { if (now) rested.value = false; });
const onThrowEnd = (e) => {
  if (String(e.animationName).startsWith('dieThrowSpin')) rested.value = true;
};

// The value is known from the start: the throw ends on it. `throwFrom` varies the path per die
const dieStyle = computed(() => {
  const [rx, ry] = SHOW[props.value] || SHOW[1];
  return {
    '--rx': `${rx}deg`,
    '--ry': `${ry}deg`,
    '--tilt': `${props.tilt}deg`,
    '--from': props.throwFrom,
    '--delay': `${props.delay}s`
  };
});
</script>

<style scoped>
.dice-face {
  --s: var(--die-size, 64px);
  position: relative;
  width: var(--s);
  height: var(--s);
  perspective: 520px;
}

.die-hop {
  width: 100%;
  height: 100%;
  transform: rotate(var(--tilt));
  /* keep the cube in 3D through this layer (a flat layer would draw it as if seen from below) */
  transform-style: preserve-3d;
}

.die-cube {
  position: relative;
  width: 100%;
  height: 100%;
  transform-style: preserve-3d;
  /* the cube turns about its centre, sunk half a die behind the screen: at rest the face that came up
     lies exactly where the die is laid out, not nearer the eye (larger and pushed down over its shadow) */
  transform: translateZ(calc(var(--s) / -2)) rotateX(var(--rx)) rotateY(var(--ry));
}

.die-side {
  position: absolute;
  inset: 0;
  border-radius: 16%;
  background: radial-gradient(circle at 35% 30%, #fbf1dc 0%, #eadcc0 55%, #cdb994 100%);
  box-shadow: inset 0 0 0 1px rgba(90, 60, 30, 0.35), inset 0 -4px 8px rgba(90, 60, 30, 0.25);
  backface-visibility: hidden;
}

.die-side--front { transform: translateZ(calc(var(--s) / 2)); }
.die-side--back { transform: rotateY(180deg) translateZ(calc(var(--s) / 2)); }
.die-side--right { transform: rotateY(90deg) translateZ(calc(var(--s) / 2)); }
.die-side--left { transform: rotateY(-90deg) translateZ(calc(var(--s) / 2)); }
.die-side--top { transform: rotateX(90deg) translateZ(calc(var(--s) / 2)); }
.die-side--bottom { transform: rotateX(-90deg) translateZ(calc(var(--s) / 2)); }

.die-pip {
  position: absolute;
  width: 18%;
  height: 18%;
  border-radius: 50%;
  transform: translate(-50%, -50%);
  background: radial-gradient(circle at 40% 35%, #3a2414 0%, #120a05 70%);
  box-shadow: inset 0 1px 1px rgba(0, 0, 0, 0.6);
}

.die-shadow {
  position: absolute;
  left: 10%;
  right: 10%;
  bottom: -14%;
  height: 16%;
  border-radius: 50%;
  background: radial-gradient(ellipse, rgba(0, 0, 0, 0.75) 0%, rgba(0, 0, 0, 0) 70%);
}

.die-rested .die-side:not(.die-side--value) {
  visibility: hidden;
}

/* The throw: one motion, not a loop — the die flies in from the side, hits the table, bounces twice
   lower each time and rolls to a stop on its value. About one and a half turns in all, slowing down. */
.rolling-dice .die-hop {
  animation: dieThrowPath 0.85s linear both;
  animation-delay: var(--delay);
}

.rolling-dice .die-cube {
  animation: dieThrowSpin 0.85s cubic-bezier(0.2, 0.7, 0.3, 1) both;
  animation-delay: var(--delay);
}

/* while the die rolls to a stop the other faces fade out, so no sliver of a side, top or bottom
   is left at the edge as it settles — only the face that came up */
.rolling-dice .die-side:not(.die-side--value) {
  animation: dieSideFade 0.85s linear both;
  animation-delay: var(--delay);
}

@keyframes dieSideFade {
  0%, 70% { opacity: 1; }
  88%, 100% { opacity: 0; }
}

.rolling-dice .die-shadow {
  animation: dieThrowShadow 0.85s linear both;
  animation-delay: var(--delay);
}

@keyframes dieThrowPath {
  0% {
    transform: translate(calc(var(--from) * -1.1 * var(--s)), calc(-42px * var(--die-hop, 1))) rotate(calc(var(--tilt) - 150deg));
    animation-timing-function: cubic-bezier(0.5, 0, 1, 1);
  }
  28% {
    transform: translate(calc(var(--from) * -0.45 * var(--s)), 0) rotate(calc(var(--tilt) - 70deg));
    animation-timing-function: cubic-bezier(0, 0, 0.5, 1);
  }
  44% {
    transform: translate(calc(var(--from) * -0.25 * var(--s)), calc(-16px * var(--die-hop, 1))) rotate(calc(var(--tilt) - 38deg));
    animation-timing-function: cubic-bezier(0.5, 0, 1, 1);
  }
  60% {
    transform: translate(calc(var(--from) * -0.1 * var(--s)), 0) rotate(calc(var(--tilt) - 16deg));
    animation-timing-function: cubic-bezier(0, 0, 0.5, 1);
  }
  70% {
    transform: translate(calc(var(--from) * -0.04 * var(--s)), calc(-5px * var(--die-hop, 1))) rotate(calc(var(--tilt) - 7deg));
    animation-timing-function: cubic-bezier(0.5, 0, 1, 1);
  }
  80% {
    transform: translate(0, 0) rotate(calc(var(--tilt) - 2deg));
    animation-timing-function: ease-out;
  }
  100% {
    transform: translate(0, 0) rotate(var(--tilt));
  }
}

@keyframes dieThrowSpin {
  0% { transform: translateZ(calc(var(--s) / -2)) rotateX(calc(var(--rx) + 450deg)) rotateY(calc(var(--ry) + 180deg)); }
  100% { transform: translateZ(calc(var(--s) / -2)) rotateX(var(--rx)) rotateY(var(--ry)); }
}

@keyframes dieThrowShadow {
  0% { transform: translateX(calc(var(--from) * -1.1 * var(--s))) scale(0.5); opacity: 0.3; }
  28% { transform: translateX(calc(var(--from) * -0.45 * var(--s))) scale(1); opacity: 1; }
  44% { transform: translateX(calc(var(--from) * -0.25 * var(--s))) scale(0.8); opacity: 0.7; }
  60% { transform: translateX(calc(var(--from) * -0.1 * var(--s))) scale(1); opacity: 1; }
  70% { transform: translateX(0) scale(0.92); opacity: 0.9; }
  80%, 100% { transform: translateX(0) scale(1); opacity: 1; }
}

@media (prefers-reduced-motion: reduce) {
  .die-hop,
  .die-cube,
  .die-side,
  .die-shadow {
    animation: none !important;
  }
}
</style>
