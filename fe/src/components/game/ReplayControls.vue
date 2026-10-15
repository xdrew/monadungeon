<template>
  <!-- A finished game replayed turn by turn: the same slim plaque as the AI playback -->
  <div
    class="replay-controls hud-plaque"
    role="status"
  >
    <div class="hud-plaque__row">
      <span class="hud-plaque__caption">Replay</span>
      <span class="step-counter">Turn {{ currentReplayTurn }} of {{ gameTurns.length }}</span>
    </div>
    <div
      class="progress-bar"
      aria-hidden="true"
    >
      <div
        class="progress-fill"
        :style="{ width: `${gameTurns.length ? (currentReplayTurn / gameTurns.length) * 100 : 0}%` }"
      />
    </div>
    <div class="hud-plaque__row">
      <label
        for="replay-speed"
        class="hud-plaque__caption"
      >Speed</label>
      <select
        id="replay-speed"
        :value="replaySpeed"
        class="speed-select"
        @change="onSpeedChange($event.target.value)"
      >
        <option value="0.5">
          Slow (0.5x)
        </option>
        <option value="1">
          Normal (1x)
        </option>
        <option value="2">
          Fast (2x)
        </option>
        <option value="4">
          Very Fast (4x)
        </option>
      </select>
      <button
        type="button"
        class="stop-replay-button btn btn--ghost btn--sm"
        @click="stopReplay"
      >
        Stop Replay
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  currentReplayTurn: {
    type: Number,
    required: true
  },
  gameTurns: {
    type: Array,
    required: true
  },
  replaySpeed: {
    type: Number,
    required: true
  }
});

const emit = defineEmits(['stop-replay', 'speed-change']);

const stopReplay = () => {
  emit('stop-replay');
};

const onSpeedChange = (newValue) => {
  emit('speed-change', Number(newValue));
};
</script>

<style scoped>
.speed-select {
  padding: 2px 6px;
  border: 1px solid var(--iron);
  border-radius: var(--radius-sm);
  background: var(--bg-tertiary);
  color: var(--text-primary);
  font-family: var(--font-body);
}
</style>
