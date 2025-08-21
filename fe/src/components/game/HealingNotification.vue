<template>
  <div class="healing-notification">
    <div class="notification-content">
      <span class="notification-emoji">⛲</span>
      <span class="notification-text">{{ playerName }} healed at the fountain!</span>
      <span class="heal-amount">+{{ healAmount }} HP</span>
      <button
        class="dismiss-btn"
        @click="dismiss"
      >
        ×
      </button>
    </div>
    <div class="healing-particles">
      <span
        v-for="i in 5"
        :key="i"
        class="particle"
        :style="{ animationDelay: `${i * 0.1}s` }"
      >
        ✨
      </span>
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
  playerName: {
    type: String,
    required: true
  },
  healAmount: {
    type: Number,
    default: 0
  }
});

const emit = defineEmits(['dismiss']);

const dismiss = () => {
  emit('dismiss');
};
</script>

<style scoped>
.healing-notification {
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
  background: linear-gradient(135deg, rgba(64, 224, 208, 0.95) 0%, rgba(32, 178, 170, 0.95) 100%);
  color: white;
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3), 0 0 20px rgba(64, 224, 208, 0.4);
  animation: slideIn 0.3s ease-out, healPulse 2s ease-in-out;
  position: relative;
  overflow: hidden;
}

.notification-content::before {
  content: '';
  position: absolute;
  top: -2px;
  left: -2px;
  right: -2px;
  bottom: -2px;
  background: linear-gradient(45deg, #40e0d0, #20b2aa, #40e0d0);
  border-radius: 8px;
  opacity: 0.5;
  z-index: -1;
  animation: shimmer 2s linear infinite;
}

.notification-emoji {
  font-size: 1.8rem;
  filter: drop-shadow(0 0 4px rgba(255, 255, 255, 0.6));
}

.notification-text {
  flex: 1;
  font-weight: 600;
  font-size: 1.1rem;
  line-height: 1.5;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.heal-amount {
  font-size: 1.2rem;
  font-weight: bold;
  color: #90EE90;
  text-shadow: 0 0 4px rgba(144, 238, 144, 0.8);
  animation: bounce 0.5s ease-out;
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

.healing-particles {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  pointer-events: none;
}

.particle {
  position: absolute;
  font-size: 1.2rem;
  animation: particleFloat 2s ease-out forwards;
  opacity: 0;
}

@keyframes slideIn {
  from {
    transform: translateX(-50%) translateY(-20px);
    opacity: 0;
  }
  to {
    transform: translateX(-50%) translateY(0);
    opacity: 1;
  }
}

@keyframes healPulse {
  0%, 100% {
    transform: translateX(-50%) scale(1);
  }
  50% {
    transform: translateX(-50%) scale(1.02);
  }
}

@keyframes shimmer {
  0% {
    background-position: -200% center;
  }
  100% {
    background-position: 200% center;
  }
}

@keyframes bounce {
  0% {
    transform: scale(0.8);
  }
  50% {
    transform: scale(1.2);
  }
  100% {
    transform: scale(1);
  }
}

@keyframes particleFloat {
  0% {
    transform: translate(0, 0) scale(0);
    opacity: 1;
  }
  100% {
    transform: translate(calc(var(--i) * 30px - 60px), -40px) scale(1.5);
    opacity: 0;
  }
}

.particle:nth-child(1) { --i: 0; }
.particle:nth-child(2) { --i: 1; }
.particle:nth-child(3) { --i: 2; }
.particle:nth-child(4) { --i: 3; }
.particle:nth-child(5) { --i: 4; }
</style>