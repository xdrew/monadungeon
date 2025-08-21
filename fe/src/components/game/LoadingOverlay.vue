<template>
  <div class="loading-overlay">
    <div class="loading-indicator-mini">
      <div class="mini-spinner" />
      <span>{{ status || 'Processing...' }}</span>
    </div>
  </div>
</template>

<script setup>
import { defineProps } from 'vue';

const props = defineProps({
  status: {
    type: String,
    default: ''
  }
});
</script>

<style scoped>
.loading-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 900;
}

.loading-indicator-mini {
  background-color: rgba(37, 37, 37, 0.9);
  border-radius: 8px;
  padding: 1rem 1.5rem;
  display: flex;
  align-items: center;
  gap: 1rem;
  color: white;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}

.mini-spinner {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 3px solid rgba(255, 255, 255, 0.1);
  border-top-color: #ffc107;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
</style> 