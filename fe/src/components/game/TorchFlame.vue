<template>
  <!-- A small torch: an iron cup and a living flame (CSS only), for the loaders -->
  <span
    class="torch"
    :style="{ '--torch': `${size}px` }"
    aria-hidden="true"
  >
    <span class="torch__glow" />
    <span class="torch__flame torch__flame--outer" />
    <span class="torch__flame torch__flame--inner" />
    <span class="torch__cup" />
  </span>
</template>

<script setup>
defineProps({
  /** the flame's width in px; the torch is about twice as tall */
  size: { type: Number, default: 40 },
});
</script>

<style scoped>
.torch {
  position: relative;
  display: inline-block;
  width: var(--torch);
  height: calc(var(--torch) * 2.2);
}

.torch__glow {
  position: absolute;
  left: 50%;
  top: 30%;
  width: calc(var(--torch) * 4);
  height: calc(var(--torch) * 4);
  transform: translate(-50%, -50%);
  border-radius: 50%;
  background: radial-gradient(circle, rgba(255, 150, 50, 0.35) 0%, rgba(255, 110, 20, 0.12) 40%, rgba(0, 0, 0, 0) 70%);
  animation: torchGlow 2.6s ease-in-out infinite;
}

.torch__flame {
  position: absolute;
  left: 50%;
  bottom: 42%;
  border-radius: 50% 50% 45% 45% / 65% 65% 35% 35%;
  transform-origin: 50% 100%;
}

.torch__flame--outer {
  width: var(--torch);
  height: calc(var(--torch) * 1.35);
  margin-left: calc(var(--torch) * -0.5);
  background: radial-gradient(ellipse at 50% 80%, #ffd27a 0%, #ff8a1f 45%, rgba(200, 50, 10, 0.85) 75%, rgba(120, 20, 5, 0) 100%);
  filter: blur(0.5px);
  animation: torchFlicker 1.3s ease-in-out infinite alternate;
}

.torch__flame--inner {
  width: calc(var(--torch) * 0.45);
  height: calc(var(--torch) * 0.7);
  margin-left: calc(var(--torch) * -0.225);
  bottom: 44%;
  background: radial-gradient(ellipse at 50% 85%, #fffbe8 0%, #ffe39a 55%, rgba(255, 200, 90, 0) 100%);
  animation: torchFlicker 0.9s ease-in-out infinite alternate-reverse;
}

.torch__cup {
  position: absolute;
  left: 50%;
  bottom: 0;
  width: calc(var(--torch) * 0.7);
  height: 45%;
  margin-left: calc(var(--torch) * -0.35);
  clip-path: polygon(0 0, 100% 0, 70% 100%, 30% 100%);
  background: linear-gradient(90deg, #2a211a 0%, #6b5644 45%, #3a2e24 100%);
}

@keyframes torchFlicker {
  0% { transform: scale(1, 1) skewX(0deg); opacity: 0.95; }
  30% { transform: scale(0.94, 1.08) skewX(-3deg); }
  60% { transform: scale(1.05, 0.95) skewX(2deg); opacity: 1; }
  100% { transform: scale(0.97, 1.04) skewX(-1deg); opacity: 0.9; }
}

@keyframes torchGlow {
  0%, 100% { opacity: 0.85; transform: translate(-50%, -50%) scale(1); }
  50% { opacity: 1; transform: translate(-50%, -50%) scale(1.08); }
}

@media (prefers-reduced-motion: reduce) {
  .torch * {
    animation: none !important;
  }
}
</style>
