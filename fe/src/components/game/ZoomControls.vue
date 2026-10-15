<template>
  <div class="zoom-controls">
    <button
      class="zoom-button btn btn--iron btn--sm"
      title="Center Map"
      aria-label="Center Map"
      @click="centerMap"
    >
      <GameIcon name="center" :size="18" />
    </button>
    <button
      class="zoom-button btn btn--iron btn--sm"
      title="Center on Player"
      aria-label="Center on Player"
      @click="centerOnPlayer"
    >
      <GameIcon name="pin" :size="18" />
    </button>
    <button
      class="zoom-button btn btn--iron btn--sm"
      title="Center on Available Places"
      aria-label="Center on Available Places"
      @click="centerOnAvailablePlaces"
    >
      <GameIcon name="explore" :size="18" />
    </button>
  </div>
</template>

<script setup>
import { defineEmits } from 'vue';
import GameIcon from '@/components/ui/GameIcon.vue';

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
/* A column of small iron rings; the rings come from .btn--iron */
.zoom-controls {
  position: absolute;
  top: 14px;
  right: 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  z-index: 30;
}
</style>
