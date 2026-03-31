<template>
  <div class="sidebar-section ai-playback">
    <h4>
      <img
        src="/images/ai.webp"
        alt="AI"
        class="ai-avatar-tiny"
      >
      AI Turn
    </h4>

    <div class="playback-progress">
      <span class="step-counter">Step {{ currentStep }} of {{ totalSteps }}</span>
      <div class="progress-bar">
        <div
          class="progress-fill"
          :style="{ width: `${(currentStep / totalSteps) * 100}%` }"
        />
      </div>
    </div>

    <div
      v-if="currentAction"
      class="current-action"
    >
      <span class="action-icon">{{ currentAction.icon }}</span>
      <span class="action-label">{{ currentAction.label }}</span>
    </div>

    <div class="speed-controls">
      <span class="speed-label">Speed:</span>
      <button
        class="speed-btn"
        :class="{ active: playbackSpeed === 0.5 }"
        @click="$emit('speed-change', 0.5)"
      >
        Slow
      </button>
      <button
        class="speed-btn"
        :class="{ active: playbackSpeed === 1 }"
        @click="$emit('speed-change', 1)"
      >
        Normal
      </button>
      <button
        class="speed-btn"
        :class="{ active: playbackSpeed === 2 }"
        @click="$emit('speed-change', 2)"
      >
        Fast
      </button>
    </div>

    <button
      class="skip-btn"
      @click="$emit('skip')"
    >
      Skip
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
</script>

<style scoped>
.ai-playback {
  background-color: #1a1a2e;
  border-radius: 8px;
  padding: 15px;
  margin-bottom: 20px;
  border: 1px solid #7b3ff2;
  color: #e6e6e6;
}

h4 {
  color: #ffcc00;
  margin-top: 0;
  margin-bottom: 12px;
  border-bottom: 1px solid #444;
  padding-bottom: 5px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.ai-avatar-tiny {
  width: 24px;
  height: 24px;
  border-radius: 50%;
}

.playback-progress {
  margin-bottom: 12px;
}

.step-counter {
  font-size: 0.85em;
  color: #bbb;
}

.progress-bar {
  width: 100%;
  height: 8px;
  background-color: #333;
  border-radius: 4px;
  margin-top: 5px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #7b3ff2, #a855f7);
  transition: width 0.3s ease;
  border-radius: 4px;
}

.current-action {
  background-color: #2a2a40;
  border-radius: 6px;
  padding: 8px 10px;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.9em;
  min-height: 36px;
}

.action-icon {
  font-size: 1.1em;
}

.action-label {
  color: #e6e6e6;
}

.speed-controls {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 12px;
}

.speed-label {
  font-size: 0.85em;
  color: #bbb;
  margin-right: 2px;
}

.speed-btn {
  padding: 4px 10px;
  background-color: #333;
  color: #ccc;
  border: 1px solid #555;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.8em;
  transition: all 0.2s;
}

.speed-btn:hover {
  background-color: #444;
  color: #fff;
}

.speed-btn.active {
  background-color: #7b3ff2;
  color: #fff;
  border-color: #a855f7;
}

.skip-btn {
  width: 100%;
  padding: 8px;
  background-color: #333;
  color: #ccc;
  border: 1px solid #555;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.85em;
  transition: all 0.2s;
}

.skip-btn:hover {
  background-color: #555;
  color: #fff;
}
</style>
