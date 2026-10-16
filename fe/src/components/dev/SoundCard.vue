<template>
  <!-- One sound on the bench: its scene, where the game plays it, its files and the waveform with a playhead -->
  <article
    class="sound-card"
    :class="{ 'sound-card--playing': playing }"
    :data-testid="`bench-${id}`"
  >
    <button
      type="button"
      class="sound-card__stage"
      data-sound="none"
      :aria-label="t('devSounds.play')"
      @click="play()"
    >
      <SoundScene
        :scene="scene || id"
        :run="run"
        :duration="duration"
      />
    </button>
    <div class="sound-card__body">
      <div class="sound-card__head">
        <code class="sound-card__id">{{ id }}</code>
        <span class="sound-card__len">{{ duration ? `${duration.toFixed(2)} s` : '' }}</span>
      </div>
      <p class="sound-card__when">
        {{ when }}
      </p>
      <canvas
        ref="wave"
        class="sound-card__wave"
        width="280"
        height="40"
      />
      <div class="sound-card__files">
        <button
          v-for="(file, i) in files"
          :key="file"
          type="button"
          class="btn btn--ghost btn--sm"
          :class="{ 'is-current': current === i }"
          data-sound="none"
          :data-testid="`bench-${id}-${i + 1}`"
          @click="play(i)"
        >
          {{ files.length > 1 ? t('devSounds.variant', { n: i + 1 }) : t('devSounds.play') }}
        </button>
        <button
          v-if="files.length > 1"
          type="button"
          class="btn btn--ghost btn--sm"
          data-sound="none"
          @click="play()"
        >
          {{ t('devSounds.random') }}
        </button>
      </div>
    </div>
  </article>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import SoundScene from '@/components/dev/SoundScene.vue';
import { audio } from '@/services/audio/audioEngine';

const props = defineProps({
  id: { type: String, required: true },
  files: { type: Array, required: true },
  when: { type: String, default: '' },
  /** 'sfx' (sounds) or 'music' (stings): which volume slider it follows */
  bus: { type: String, default: 'sfx' },
  /** another scene than the id's */
  scene: { type: String, default: '' },
});

const { t } = useI18n();
const run = ref(0);
const duration = ref(0);
const current = ref(-1);
const playing = ref(false);
const wave = ref(null);
let frame = null;

const drawWave = (buffer, progress) => {
  const canvas = wave.value;
  if (!canvas || !buffer) return;
  const g = canvas.getContext('2d');
  const { width, height } = canvas;
  const data = buffer.getChannelData(0);
  const step = Math.max(1, Math.floor(data.length / width));
  g.clearRect(0, 0, width, height);
  for (let x = 0; x < width; x++) {
    let peak = 0;
    for (let i = x * step; i < (x + 1) * step && i < data.length; i++) peak = Math.max(peak, Math.abs(data[i]));
    const h = Math.max(1, peak * height);
    g.fillStyle = x / width <= progress ? 'rgb(255, 140, 40)' : 'rgba(234, 220, 192, 0.35)';
    g.fillRect(x, (height - h) / 2, 1, h);
  }
};

/** One file (by index), or one at random as the game picks. */
const play = async (index = Math.floor(Math.random() * props.files.length)) => {
  current.value = index;
  const started = await audio.preview(props.files[index], props.bus);
  if (!started) return;
  duration.value = started.duration;
  run.value += 1;
  playing.value = true;
  cancelAnimationFrame(frame);
  const tickFrame = () => {
    const progress = Math.min(1, (audio.ctx.currentTime - started.startedAt) / started.duration);
    drawWave(started.buffer, progress);
    if (progress < 1) frame = requestAnimationFrame(tickFrame);
    else playing.value = false;
  };
  tickFrame();
};

// the first file's waveform and length before anything plays
onMounted(async () => {
  const buffer = await audio.buffer(props.files[0]);
  if (!buffer) return;
  duration.value = buffer.duration;
  drawWave(buffer, 0);
});

defineExpose({ play });
onBeforeUnmount(() => cancelAnimationFrame(frame));
</script>

<style scoped>
.sound-card {
  display: flex;
  flex-direction: column;
  background: rgba(20, 12, 8, 0.92);
  border: 1px solid var(--iron-dark);
}

.sound-card--playing {
  border-color: rgba(var(--ember-rgb), 0.7);
  box-shadow: 0 0 18px rgba(var(--ember-rgb), 0.25);
}

.sound-card__stage {
  display: block;
  padding: 0;
  border: 0;
  background: none;
  cursor: pointer;
}

.sound-card__body {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 10px 12px 12px;
}

.sound-card__head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
}

.sound-card__id {
  font-size: 13px;
  color: var(--text-accent);
}

.sound-card__len {
  font-size: 12px;
  color: var(--text-muted);
  font-variant-numeric: tabular-nums;
}

.sound-card__when {
  margin: 0;
  min-height: 2.6em;
  font-size: 13px;
  line-height: 1.3;
  color: var(--text-secondary);
}

.sound-card__wave {
  width: 100%;
  height: 40px;
  background: #0b0705;
}

.sound-card__files {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.sound-card__files .is-current {
  border-color: rgba(var(--ember-rgb), 0.7);
  color: var(--text-accent);
}
</style>
