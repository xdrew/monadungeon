<template>
  <button
    class="music-toggle"
    :class="{ 'music-off': !isEnabled }"
    :title="isEnabled ? 'Mute Music' : 'Play Music'"
    @click="toggleMusic"
  >
    {{ isEnabled ? '🔊' : '🔇' }}
  </button>
</template>

<script setup>
import { ref } from 'vue';
import { musicService } from '@/services/musicService';

const isEnabled = ref(musicService.getEnabled());

const toggleMusic = () => {
  isEnabled.value = musicService.toggle();
};
</script>

<style scoped>
.music-toggle {
  position: fixed;
  bottom: 20px;
  right: 20px;
  width: 50px;
  height: 50px;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.7);
  border: 2px solid #ffd700;
  color: #ffd700;
  font-size: 24px;
  cursor: pointer;
  transition: all 0.3s ease;
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
}

.music-toggle:hover {
  background: rgba(0, 0, 0, 0.9);
  transform: scale(1.1);
}

.music-toggle.music-off {
  border-color: #666;
  color: #666;
}

.music-toggle.music-off:hover {
  border-color: #999;
  color: #999;
}

@media (max-width: 768px) {
  .music-toggle {
    bottom: 15px;
    right: 15px;
    width: 40px;
    height: 40px;
    font-size: 20px;
  }
}
</style>