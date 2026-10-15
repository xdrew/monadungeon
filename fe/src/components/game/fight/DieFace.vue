<template>
  <!-- One die of the fight: a real cube with six faces that tumbles while rolling and settles on its value.
       `.dice-face` and `data-value` are e2e hooks. -->
  <div
    class="dice-face"
    :class="{ 'rolling-dice': rolling, 'dice-landed': landed }"
    :data-value="value"
    :style="dieStyle"
  >
    <div class="die-hop">
      <div class="die-cube">
        <div
          v-for="face in FACES"
          :key="face.n"
          class="die-side"
          :class="`die-side--${face.side}`"
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
    <div
      class="die-shadow"
      aria-hidden="true"
    />
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  value: { type: Number, default: 1 },
  rolling: { type: Boolean, default: false },
  landed: { type: Boolean, default: false },
  /** the die lies a little askew on the table, in degrees */
  tilt: { type: Number, default: 0 },
  /** stagger between dice, in seconds: out of step while rolling, the next one lands a little later */
  delay: { type: Number, default: 0 }
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

// While rolling the style stays fixed (the faces flicker by themselves as the cube turns);
// the value only decides the final orientation once the die lands
const dieStyle = computed(() => {
  if (props.rolling) {
    return { '--rx': '0deg', '--ry': '0deg', '--tilt': `${props.tilt}deg`, '--delay': `${-props.delay * 2.2}s` };
  }
  const [rx, ry] = SHOW[props.value] || SHOW[1];
  return { '--rx': `${rx}deg`, '--ry': `${ry}deg`, '--tilt': `${props.tilt}deg`, '--delay': `${props.delay * 0.4}s` };
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
}

.die-cube {
  position: relative;
  width: 100%;
  height: 100%;
  transform-style: preserve-3d;
  transform: rotateX(var(--rx)) rotateY(var(--ry));
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

/* Rolling: the die hops along the table and tumbles over all its axes */
.rolling-dice .die-hop {
  animation: dieHop 0.42s cubic-bezier(0.33, 0, 0.66, 1) infinite;
  animation-delay: var(--delay);
}

.rolling-dice .die-cube {
  animation: dieTumble 0.42s linear infinite;
  animation-delay: var(--delay);
}

.rolling-dice .die-shadow {
  animation: dieShadow 0.42s cubic-bezier(0.33, 0, 0.66, 1) infinite;
  animation-delay: var(--delay);
}

/* Landing: a last fall, the cube slows down onto its value, one small bounce, the die settles askew */
.dice-landed .die-hop {
  animation: dieDrop 0.6s cubic-bezier(0.3, 0.7, 0.35, 1) both;
  animation-delay: var(--delay);
}

.dice-landed .die-cube {
  animation: dieSettle 0.6s cubic-bezier(0.2, 0.55, 0.3, 1) both;
  animation-delay: var(--delay);
}

.dice-landed .die-shadow {
  animation: dieShadowLand 0.6s cubic-bezier(0.3, 0.7, 0.35, 1) both;
  animation-delay: var(--delay);
}

@keyframes dieHop {
  0%, 100% { transform: translateY(0) rotate(var(--tilt)); }
  50% { transform: translateY(-34px) rotate(var(--tilt)); }
}

@keyframes dieTumble {
  from { transform: rotateX(0deg) rotateY(0deg) rotateZ(0deg); }
  to { transform: rotateX(360deg) rotateY(270deg) rotateZ(90deg); }
}

@keyframes dieShadow {
  0%, 100% { transform: scale(1); opacity: 1; }
  50% { transform: scale(0.55); opacity: 0.45; }
}

/* the last hop from mid-air (the rolling hop peaks at -34px), a soft bounce, then the die slides to rest */
@keyframes dieDrop {
  0% { transform: translateY(-20px) rotate(calc(var(--tilt) - 30deg)); }
  35% { transform: translateY(0) rotate(calc(var(--tilt) - 12deg)); }
  52% { transform: translateY(-6px) rotate(calc(var(--tilt) - 5deg)); }
  68% { transform: translateY(0) rotate(calc(var(--tilt) - 1deg)); }
  100% { transform: translateY(0) rotate(var(--tilt)); }
}

/* the tumble slows down onto the value: fast at first (like the roll), easing out over the bounce */
@keyframes dieSettle {
  0% { transform: rotateX(calc(var(--rx) + 450deg)) rotateY(calc(var(--ry) + 300deg)); }
  100% { transform: rotateX(var(--rx)) rotateY(var(--ry)); }
}

@keyframes dieShadowLand {
  0% { transform: scale(0.7); opacity: 0.6; }
  35% { transform: scale(1); opacity: 1; }
  52% { transform: scale(0.9); opacity: 0.85; }
  68%, 100% { transform: scale(1); opacity: 1; }
}

@media (prefers-reduced-motion: reduce) {
  .die-hop,
  .die-cube,
  .die-shadow {
    animation: none !important;
  }
}
</style>
