<template>
  <!-- The AI's turn played back step by step: one slim line under the turn banner
       (the banner already tells what the AI does, so the step's label is only read out to screen readers) -->
  <div
    class="ai-playback hud-plaque"
    role="status"
    :aria-label="currentAction?.label || $t('hud.aiTurn')"
  >
    <span class="hud-plaque__caption">{{ $t('common.ai') }}</span>
    <span class="step-counter">{{ $t('hud.stepShort', { n: currentStep, total: totalSteps }) }}</span>
    <div
      class="progress-bar"
      aria-hidden="true"
    >
      <div
        class="progress-fill"
        :style="{ width: `${totalSteps ? (currentStep / totalSteps) * 100 : 0}%` }"
      />
    </div>
    <div
      class="speed-controls"
      role="group"
      :aria-label="$t('hud.speed')"
    >
      <button
        v-for="speed in SPEEDS"
        :key="speed.value"
        type="button"
        class="speed-btn"
        :class="{ active: playbackSpeed === speed.value }"
        :aria-pressed="playbackSpeed === speed.value"
        :title="$t(speed.labelKey)"
        :aria-label="$t(speed.labelKey)"
        @click="$emit('speed-change', speed.value)"
      >
        {{ speed.mark }}
      </button>
    </div>
    <button
      type="button"
      class="skip-btn"
      @click="$emit('skip')"
    >
      {{ $t('common.skip') }}
    </button>
  </div>
</template>

<script setup>
defineProps({
  currentStep: {
    type: Number,
    required: true
  },
  totalSteps: {
    type: Number,
    required: true
  },
  playbackSpeed: {
    type: Number,
    required: true
  },
  currentAction: {
    type: Object,
    default: null
  }
});

defineEmits(['speed-change', 'skip']);

// the marks are numbers, the same in every language; the words are in the tooltips
const SPEEDS = [
  { value: 0.5, labelKey: 'hud.slow', mark: '×½' },
  { value: 1, labelKey: 'hud.normal', mark: '×1' },
  { value: 2, labelKey: 'hud.fast', mark: '×2' }
];
</script>

<style scoped>
/* one line: caption, step, progress, speed, skip */
.ai-playback {
  flex-direction: row;
  align-items: center;
  gap: 10px;
  width: auto;
  max-width: calc(100vw - 32px);
  padding: 5px 6px 5px 12px;
}

.ai-playback .progress-bar {
  flex: 1 1 80px;
  min-width: 60px;
}

.speed-controls {
  display: flex;
}

.speed-btn,
.skip-btn {
  height: 28px;
  padding: 0 9px;
  border: 1px solid rgba(234, 220, 192, 0.22);
  background: transparent;
  color: var(--text-secondary);
  font-family: var(--font-body);
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  cursor: pointer;
}

.speed-btn + .speed-btn {
  margin-left: -1px;
}

.speed-btn.active {
  position: relative;
  border-color: var(--color-primary);
  background: rgba(var(--ember-rgb), 0.15);
  color: var(--text-heading);
}

.speed-btn:hover,
.skip-btn:hover,
.speed-btn:focus-visible,
.skip-btn:focus-visible {
  outline: none;
  color: var(--text-heading);
  border-color: rgba(234, 220, 192, 0.5);
}
</style>
