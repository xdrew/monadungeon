<template>
  <div class="loading-screen">
    <div class="loading-content">
      <div class="loading-logo">
        <img src="/assets/monad-logo-black.webp" alt="Monad" class="loading-logo-img" />
        <div class="loading-title">MONADUNGEON</div>
      </div>
      <div class="loading-spinner">
        <div class="spinner-icon" />
      </div>
      <div class="loading-text">
        Loading adventure...
      </div>
      <div class="loading-tips">
        Prepare to face monsters and discover treasures
      </div>
    </div>
  </div>
</template>

<script setup>
// No props or functionality needed for this simple component
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

.loading-logo-img {
  width: 80px;
  height: 80px;
  object-fit: contain;
  filter: drop-shadow(0 0 15px rgba(123, 63, 242, 0.5));
}

.loading-title {
  font-size: 2.5rem;
  font-weight: bold;
  color: var(--monad-purple-light, #9F6EFF);
  text-shadow: 0 0 10px rgba(159, 110, 255, 0.5);
  letter-spacing: 2px;
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
  border: 8px solid rgba(123, 63, 242, 0.1);
  border-top-color: var(--monad-purple, #7B3FF2);
  animation: spin 1s linear infinite;
  box-shadow: 0 0 20px rgba(123, 63, 242, 0.3);
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