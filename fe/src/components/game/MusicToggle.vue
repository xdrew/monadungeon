<template>
  <button
    class="music-toggle btn btn--iron btn--sm"
    :class="{ 'music-off': !isEnabled }"
    :title="isEnabled ? 'Mute Music' : 'Play Music'"
    :aria-label="isEnabled ? 'Mute Music' : 'Play Music'"
    :aria-pressed="isEnabled"
    @click="toggleMusic"
  >
    <GameIcon :name="isEnabled ? 'sound-on' : 'sound-off'" :size="20" />
  </button>
</template>

<script setup>
import { ref } from 'vue';
import { musicService } from '@/services/musicService';
import GameIcon from '@/components/ui/GameIcon.vue';

const isEnabled = ref(musicService.getEnabled());

const toggleMusic = () => {
  isEnabled.value = musicService.toggle();
};
</script>

<style scoped>
/* Small iron ring in the HUD's top-right row; the ring itself comes from .btn--iron */
.music-toggle.music-off {
  color: var(--text-muted);
}

</style>
