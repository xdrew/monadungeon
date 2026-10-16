<template>
  <!-- The sound ring: music and sounds sliders and a mute under it (keeps .music-toggle for the e2e) -->
  <div
    class="sound-control"
    @keydown.esc="open = false"
  >
    <button
      ref="trigger"
      type="button"
      class="music-toggle btn btn--iron btn--sm"
      :class="{ 'music-off': silent }"
      :title="t('sound.title')"
      :aria-label="t('sound.title')"
      aria-haspopup="dialog"
      :aria-expanded="open"
      data-sound="none"
      @click="open = !open"
    >
      <GameIcon
        :name="silent ? 'sound-off' : 'sound-on'"
        :size="20"
      />
    </button>
    <div
      v-if="open"
      class="sound-control__panel"
      role="dialog"
      :aria-label="t('sound.title')"
      data-testid="sound-panel"
    >
      <label class="sound-control__row">
        <span class="sound-control__label">{{ t('sound.music') }}</span>
        <input
          v-model.number="audioSettings.music"
          type="range"
          min="0"
          max="1"
          step="0.05"
          class="sound-control__slider"
          data-testid="sound-music"
          :disabled="audioSettings.muted"
        >
        <span class="sound-control__value">{{ percent(audioSettings.music) }}</span>
      </label>
      <label class="sound-control__row">
        <span class="sound-control__label">{{ t('sound.sounds') }}</span>
        <input
          v-model.number="audioSettings.sfx"
          type="range"
          min="0"
          max="1"
          step="0.05"
          class="sound-control__slider"
          data-testid="sound-sfx"
          :disabled="audioSettings.muted"
          @change="audio.cue('ui_click')"
        >
        <span class="sound-control__value">{{ percent(audioSettings.sfx) }}</span>
      </label>
      <button
        type="button"
        class="sound-control__mute btn btn--ghost btn--sm"
        :aria-pressed="audioSettings.muted"
        data-testid="sound-mute"
        data-sound="none"
        @click="audioSettings.muted = !audioSettings.muted"
      >
        <GameIcon
          :name="audioSettings.muted ? 'sound-on' : 'sound-off'"
          :size="16"
        />
        {{ audioSettings.muted ? t('sound.unmute') : t('sound.mute') }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import GameIcon from '@/components/ui/GameIcon.vue';
import { audio, audioSettings } from '@/services/audio/audioEngine';

const { t } = useI18n();
const open = ref(false);
const trigger = ref(null);

const silent = computed(() => audioSettings.muted || (audioSettings.music === 0 && audioSettings.sfx === 0));
const percent = (v) => `${Math.round(v * 100)}%`;

// A click anywhere else closes the panel
const onDocumentClick = (event) => {
  if (open.value && !event.target.closest('.sound-control')) open.value = false;
};
onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));
</script>

<style scoped>
.sound-control {
  position: relative;
}

.music-toggle.music-off {
  color: var(--text-muted);
}

.sound-control__panel {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 5;
  display: grid;
  gap: 10px;
  width: min(260px, calc(100vw - 32px));
  padding: 12px 14px;
  /* opaque: the sliders sit over the title on a phone */
  background: rgb(20, 12, 8);
  border: 1px solid var(--iron-dark);
  box-shadow: 0 10px 24px rgba(0, 0, 0, 0.8);
  font-family: var(--font-body);
  color: var(--text-primary);
}

.sound-control__row {
  display: grid;
  grid-template-columns: 64px 1fr 40px;
  align-items: center;
  gap: 8px;
  font-size: 15px;
}

.sound-control__label {
  color: var(--text-secondary, var(--text-primary));
}

.sound-control__value {
  font-size: 12px;
  text-align: right;
  color: var(--text-muted);
  font-variant-numeric: tabular-nums;
}

.sound-control__slider {
  width: 100%;
  accent-color: rgb(var(--ember-rgb));
  cursor: pointer;
}

.sound-control__slider:disabled {
  opacity: 0.4;
  cursor: default;
}

.sound-control__mute {
  justify-self: start;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
</style>
