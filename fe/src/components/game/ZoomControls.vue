<template>
  <div class="zoom-controls">
    <button
      class="zoom-button btn btn--iron btn--sm"
      :title="$t('hud.centerMap')"
      :aria-label="$t('hud.centerMap')"
      @click="centerMap"
    >
      <GameIcon name="center" :size="18" />
    </button>
    <button
      class="zoom-button btn btn--iron btn--sm"
      :title="$t('hud.centerPlayer')"
      :aria-label="$t('hud.centerPlayer')"
      @click="centerOnPlayer"
    >
      <GameIcon name="pin" :size="18" />
    </button>
    <button
      class="zoom-button btn btn--iron btn--sm"
      :title="$t('hud.centerAvailable')"
      :aria-label="$t('hud.centerAvailable')"
      @click="centerOnAvailablePlaces"
    >
      <GameIcon name="compass" :size="18" />
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
/* A row of small iron rings in the HUD's top-right corner; the rings come from .btn--iron */
.zoom-controls {
  display: flex;
  gap: 10px;
}
</style>
