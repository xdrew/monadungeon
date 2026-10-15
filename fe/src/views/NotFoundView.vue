<template>
  <!-- Lost in the dark: a 404 in the Torchlight look -->
  <div class="not-found">
    <h1>404</h1>
    <h2>{{ $t('notFound.title') }}</h2>
    <p>{{ $t('notFound.text') }}</p>
    <button
      type="button"
      class="home-button btn btn--ember btn--lg"
      @click="goToHome"
    >
      <GameIcon name="home" :size="20" /> {{ $t('notFound.home') }}
    </button>
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router';
import GameIcon from '@/components/ui/GameIcon.vue';

const router = useRouter();

const goToHome = () => {
  router.push('/');
};
</script>

<style scoped>
.not-found {
  min-height: 100vh;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 24px 16px;
  text-align: center;
  font-family: var(--font-body);
  color: var(--text-primary);
  background-color: var(--bg-field);
  background-image: radial-gradient(circle at 50% 40%, rgba(var(--ember-rgb), 0.18) 0%, rgba(0, 0, 0, 0) 40%);
}

h1 {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: clamp(110px, 20vw, 200px);
  line-height: 0.9;
  color: var(--color-primary);
  text-shadow: 0 0 40px rgba(var(--ember-rgb), 0.55), 0 6px 18px #000;
}

h2 {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 36px;
  color: var(--text-heading);
}

p {
  max-width: 30em;
  margin: 0 0 16px;
  font-style: italic;
  color: var(--text-secondary);
}

.home-button {
  gap: 8px;
}
</style>
