<template>
  <!-- Into the dungeon: the lobby's picture in the dark, a torch, the game's name and a tip while the game loads -->
  <div
    class="loading-screen"
    :style="background ? { '--loading-bg': `url(${background})` } : {}"
  >
    <div
      class="loading-screen__shade"
      aria-hidden="true"
    />
    <div
      class="loading-content"
      role="status"
      aria-live="polite"
    >
      <TorchFlame :size="44" />
      <div class="loading-title">
        {{ $t('common.appName') }}
      </div>
      <div class="loading-text">
        {{ $t('loading.adventure') }}
      </div>
      <transition
        name="loading-tip"
        mode="out-in"
      >
        <p
          :key="tip"
          class="loading-tips"
        >
          {{ $t(`loading.tips.t${tip}`) }}
        </p>
      </transition>
    </div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import TorchFlame from '@/components/game/TorchFlame.vue';
import { theme } from '@/theme';

const TIPS = 8;
const background = theme.lobbyBackground;
const tip = ref(1 + Math.floor(Math.random() * TIPS));
let timer = null;
onMounted(() => {
  timer = setInterval(() => { tip.value = (tip.value % TIPS) + 1; }, 4500);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.loading-screen {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  background-color: var(--bg-field, #070504);
  background-image: var(--loading-bg, none);
  background-size: cover;
  background-position: center;
}

.loading-screen__shade {
  position: absolute;
  inset: 0;
  background:
    radial-gradient(circle at 50% 42%, rgba(var(--ember-rgb), 0.12) 0%, rgba(5, 3, 2, 0.55) 30%, rgba(5, 3, 2, 0.96) 70%);
  backdrop-filter: blur(2px);
}

.loading-content {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  max-width: 520px;
  padding: 2rem 16px;
  text-align: center;
}

.loading-title {
  margin-top: 8px;
  font-family: var(--font-display);
  font-size: clamp(56px, 9vw, 96px);
  line-height: 0.9;
  color: var(--text-heading);
  text-shadow: 0 0 30px rgba(var(--ember-rgb), 0.45), 0 6px 18px #000;
}

.loading-text {
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.32em;
  text-transform: uppercase;
  color: var(--text-accent);
}

.loading-tips {
  min-height: 3em;
  margin: 10px 0 0;
  font-style: italic;
  font-size: 17px;
  line-height: 1.45;
  color: #d6c3a0;
}

.loading-tip-enter-active,
.loading-tip-leave-active {
  transition: opacity 0.5s ease;
}

.loading-tip-enter-from,
.loading-tip-leave-to {
  opacity: 0;
}
</style>
