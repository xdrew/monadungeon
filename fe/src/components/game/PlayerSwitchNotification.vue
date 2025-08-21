<template>
  <div class="player-switch-notification">
    <div class="notification-content">
      <span class="notification-emoji">🔄</span>
      <span class="notification-text">Active player changed to: {{ formattedPlayerId }}</span>
      <button
        class="dismiss-btn"
        @click="dismiss"
      >
        ×
      </button>
    </div>
  </div>
</template>

<script setup>
import { defineProps, defineEmits } from 'vue';

const props = defineProps({
  playerId: {
    type: String,
    required: true
  },
  formattedPlayerId: {
    type: String,
    required: true
  }
});

const emit = defineEmits(['dismiss']);

const dismiss = () => {
  emit('dismiss');
};
</script>

<style scoped>
.player-switch-notification {
  position: fixed;
  top: 20px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 800;
  width: 400px;
  max-width: 90%;
}

.notification-content {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  background-color: rgba(33, 150, 243, 0.9);
  color: white;
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
  animation: slideIn 0.3s ease-out;
}

.notification-emoji {
  font-size: 1.5rem;
}

.notification-text {
  flex: 1;
  font-weight: 500;
  font-size: 1rem;
  line-height: 1.5;
}

.dismiss-btn {
  background: none;
  border: none;
  color: white;
  font-size: 1.5rem;
  cursor: pointer;
  padding: 0;
  line-height: 1;
  opacity: 0.8;
  transition: opacity 0.2s;
  margin-left: 10px;
}

.dismiss-btn:hover {
  opacity: 1;
}

@keyframes slideIn {
  from {
    transform: translateY(-20px);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}
</style> 