<template>
  <div class="zoom-controls">
    <button
      class="zoom-button"
      title="Center Map"
      @click="centerMap"
    >
      <span class="center-icon">◎</span>
    </button>
    <button
      class="zoom-button"
      title="Center on Player"
      @click="centerOnPlayer"
    >
      <span class="center-icon">👤</span>
    </button>
    <button
      class="zoom-button"
      title="Center on Available Places"
      @click="centerOnAvailablePlaces"
    >
      <span class="center-icon">⊕</span>
    </button>
  </div>
</template>

<script setup>
import { defineEmits } from 'vue';

const emit = defineEmits(['center-map', 'center-player', 'center-available']);

const centerMap = () => {
  emit('center-map');
};

const centerOnPlayer = () => {
  emit('center-player');
};

const centerOnAvailablePlaces = () => {
  emit('center-available');
};
</script>

<style scoped>
.zoom-controls {
  position: absolute;
  top: 10px;
  right: 10px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  z-index: 30;
  background-color: rgba(34, 34, 34, 0.8);
  border-radius: 4px;
  padding: 6px;
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
}

.zoom-button {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 4px;
  background-color: #333;
  border: 1px solid #555;
  cursor: pointer;
  transition: all 0.2s;
  color: #ddd;
}

.zoom-button:hover {
  background-color: #444;
  border-color: #666;
  color: #fff;
}

.zoom-button:active {
  background-color: #555;
  transform: translateY(1px);
}

.center-icon {
  font-size: 18px;
  line-height: 1;
}
</style> 