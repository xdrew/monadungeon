<template>
  <div class="loading-screen">
    <div class="loading-content">
      <div class="loading-logo">
        <ThemeLogo />
        <div class="loading-title">{{ $t('common.appName') }}</div>
      </div>
      <div class="loading-spinner">
        <div class="spinner-icon" />
      </div>
      <div class="loading-text">
        {{ $t('loading.adventure') }}
      </div>
      <div class="loading-tips">
        {{ $t('loading.adventureHint') }}
      </div>
    </div>
  </div>
</template>

<script setup>
import ThemeLogo from '@/components/ThemeLogo.vue';
</script>

<style scoped>
.loading-screen {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background-color: #252525;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.loading-content {
  text-align: center;
  max-width: 500px;
  padding: 2rem;
}

.loading-logo {
  margin-bottom: 1.5rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
}

.loading-title {
  font-size: 2.5rem;
  font-weight: bold;
  color: var(--color-secondary);
  text-shadow: 0 0 10px rgba(var(--accent-light-rgb), 0.5);
  letter-spacing: 2px;
  text-transform: uppercase;
}

.loading-spinner {
  margin: 0 auto 1.5rem;
  width: 80px;
  height: 80px;
  position: relative;
}

.spinner-icon {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  border: 8px solid rgba(var(--accent-rgb), 0.1);
  border-top-color: var(--color-primary);
  animation: spin 1s linear infinite;
  box-shadow: 0 0 20px rgba(var(--accent-rgb), 0.3);
}

.loading-text {
  font-size: 1.5rem;
  color: #fff;
  margin-bottom: 1rem;
}

.loading-tips {
  font-size: 1rem;
  color: #aaa;
  font-style: italic;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
</style> 