<template>
  <!-- The AI's turn played back step by step: a slim plaque under the turn banner -->
  <div
    class="ai-playback hud-plaque"
    role="status"
  >
    <div class="hud-plaque__row">
      <span class="hud-plaque__caption">{{ $t('hud.aiTurn') }}</span>
      <span class="step-counter">{{ $t('hud.step', { n: currentStep, total: totalSteps }) }}</span>
    </div>
    <div
      class="progress-bar"
      aria-hidden="true"
    >
      <div
        class="progress-fill"
        :style="{ width: `${totalSteps ? (currentStep / totalSteps) * 100 : 0}%` }"
      />
    </div>
    <p
      v-if="currentAction"
      class="current-action"
    >
      <span class="action-label">{{ currentAction.label }}</span>
    </p>
    <div class="hud-plaque__row">
      <div
        class="speed-controls"
        role="group"
        :aria-label="$t('hud.speed')"
      >
        <button
          v-for="speed in SPEEDS"
          :key="speed.value"
          type="button"
          class="speed-btn btn btn--ghost btn--sm"
          :class="{ active: playbackSpeed === speed.value }"
          :aria-pressed="playbackSpeed === speed.value"
          @click="$emit('speed-change', speed.value)"
        >
          {{ $t(speed.labelKey) }}
        </button>
      </div>
      <button
        type="button"
        class="skip-btn btn btn--ghost btn--sm"
        @click="$emit('skip')"
      >
        {{ $t('common.skip') }}
      </button>
    </div>
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

const SPEEDS = [
  { value: 0.5, labelKey: 'hud.slow' },
  { value: 1, labelKey: 'hud.normal' },
  { value: 2, labelKey: 'hud.fast' }
];
</script>

<style scoped>
.current-action {
  margin: 0;
  min-height: 1.4em;
  font-style: italic;
  font-size: 15px;
  color: var(--text-primary);
  text-align: center;
}

.speed-controls {
  display: flex;
  flex-wrap: wrap;
}

/* a segmented row: the buttons grow with their label (ru/pl labels are longer than en) */
.speed-btn {
  width: auto;
  min-width: 0;
  height: 36px;
  padding: 0 12px;
  font-size: 13px;
  white-space: nowrap;
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
</style>
