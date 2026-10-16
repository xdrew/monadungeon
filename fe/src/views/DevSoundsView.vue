<template>
  <!-- The sound bench (dev builds only, /dev/sounds): every track, sting and sound with the game's picture -->
  <div class="bench">
    <header class="bench__head">
      <div>
        <h1 class="bench__title">
          {{ t('devSounds.title') }}
        </h1>
        <p class="bench__intro">
          {{ t('devSounds.intro') }}
        </p>
      </div>
      <div class="bench__tools">
        <canvas
          ref="meterCanvas"
          class="bench__meter"
          width="120"
          height="34"
          :title="t('devSounds.meter')"
        />
        <button
          type="button"
          class="btn btn--ghost btn--sm"
          data-testid="bench-stop"
          @click="stopMusic"
        >
          {{ t('devSounds.stopMusic') }}
        </button>
        <LanguageSwitch />
        <SoundControl />
        <a
          class="btn btn--ghost btn--sm"
          href="/"
        >{{ t('devSounds.back') }}</a>
      </div>
    </header>

    <section class="bench__section">
      <h2 class="bench__h2">
        {{ t('devSounds.music') }}
      </h2>
      <div class="bench__grid bench__grid--wide">
        <article
          v-for="track in TRACK_IDS"
          :key="track"
          class="track"
          :class="{ 'track--on': nowPlaying === track }"
          :data-testid="`bench-track-${track}`"
        >
          <SoundScene
            :scene="track"
            :run="nowPlaying === track ? 1 : 0"
            :duration="3"
            loop
          />
          <div class="track__body">
            <div class="track__head">
              <code>{{ track }}</code>
              <span class="track__files">{{ TRACKS[track].length > 1 ? t('devSounds.alternates', { n: TRACKS[track].length }) : '' }}</span>
            </div>
            <p class="track__when">
              {{ t(`devSounds.when.${track}`) }}
            </p>
            <button
              type="button"
              class="btn btn--sm"
              :class="nowPlaying === track ? 'btn--ghost' : 'btn--ember'"
              data-sound="none"
              @click="nowPlaying === track ? stopMusic() : playTrack(track)"
            >
              {{ nowPlaying === track ? t('devSounds.stop') : t('devSounds.play') }}
            </button>
          </div>
        </article>
      </div>
    </section>

    <section class="bench__section">
      <h2 class="bench__h2">
        {{ t('devSounds.stings') }}
      </h2>
      <div class="bench__grid">
        <SoundCard
          v-for="sting in STING_IDS"
          :key="sting"
          :ref="(el) => { cards[`sting:${sting}`] = el; }"
          :id="sting"
          :files="[STINGS[sting]]"
          :when="t(`devSounds.when.${sting}`)"
          bus="music"
        />
      </div>
    </section>

    <!-- Takes waiting for a choice: tools/audio writes them to public/audio/candidates (gitignored) -->
    <section
      v-for="(takes, cue) in candidates"
      :key="`cand-${cue}`"
      class="bench__section"
      data-testid="bench-candidates"
    >
      <h2 class="bench__h2">
        {{ t('devSounds.candidates', { cue }) }}
      </h2>
      <p class="bench__note">
        {{ t('devSounds.candidatesNote') }}
      </p>
      <div class="bench__grid">
        <SoundCard
          v-for="take in takes"
          :id="`${cue} #${take.id}`"
          :key="take.file"
          :files="[take.file]"
          :bus="take.bus || 'sfx'"
          :scene="cue"
          :when="take.label[locale] || take.label.en"
        />
      </div>
    </section>

    <section class="bench__section">
      <h2 class="bench__h2">
        {{ t('devSounds.moments') }}
      </h2>
      <p class="bench__note">
        {{ t('devSounds.momentsNote') }}
      </p>
      <div class="bench__moments">
        <button
          v-for="moment in MOMENTS"
          :key="moment.id"
          type="button"
          class="btn btn--ember btn--sm"
          data-sound="none"
          :data-testid="`bench-moment-${moment.id}`"
          @click="playMoment(moment)"
        >
          {{ t(`devSounds.moment.${moment.id}`) }}
        </button>
      </div>
    </section>

    <section
      v-for="group in GROUPS"
      :key="group.id"
      class="bench__section"
    >
      <h2 class="bench__h2">
        {{ t(`devSounds.groups.${group.id}`) }}
      </h2>
      <div class="bench__grid">
        <SoundCard
          v-for="cue in group.cues"
          :key="cue"
          :ref="(el) => { cards[cue] = el; }"
          :id="cue"
          :files="sfxFiles(cue)"
          :when="t(`devSounds.when.${cue}`)"
        />
      </div>
    </section>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import LanguageSwitch from '@/components/ui/LanguageSwitch.vue';
import SoundControl from '@/components/ui/SoundControl.vue';
import SoundCard from '@/components/dev/SoundCard.vue';
import SoundScene from '@/components/dev/SoundScene.vue';
import { audio } from '@/services/audio/audioEngine';
import { STINGS, TRACKS, sfxFiles } from '@/services/audio/cues';

const { t, locale } = useI18n();

const TRACK_IDS = Object.keys(TRACKS);
const STING_IDS = Object.keys(STINGS);
const GROUPS = [
  { id: 'ui', cues: ['ui_click', 'ui_toggle', 'ui_open', 'ui_close', 'ui_refused', 'notice'] },
  { id: 'field', cues: ['tile_draw', 'tile_place', 'tile_rotate', 'step', 'teleport', 'fountain_heal', 'chest_open', 'chest_locked', 'item_pickup', 'treasure', 'key', 'item_drop'] },
  { id: 'fight', cues: ['dice_roll', 'hit_win', 'hit_lose', 'draw', 'monster_roar', 'dragon_roar', 'stunned', 'reincarnate'] },
  { id: 'spells', cues: ['spell_fireball', 'spell_teleport', 'dark_thorn', 'frost_fist', 'curse', 'curse_lifted', 'sneak'] },
  { id: 'turns', cues: ['end_turn', 'your_turn', 'turn_tick', 'table_join', 'game_start', 'duel_start'] },
];
// Moments as the game times them (BattleReportModal: the dice at 400 ms, the blow at 1750 ms)
const MOMENTS = [
  { id: 'fightWon', steps: [[0, 'monster_roar'], [400, 'dice_roll'], [1750, 'hit_win'], [1750, 'sting:victory']] },
  { id: 'fightLost', steps: [[0, 'monster_roar'], [400, 'dice_roll'], [1750, 'hit_lose'], [1750, 'sting:defeat']] },
  { id: 'dragon', steps: [[0, 'dragon_roar'], [400, 'dice_roll'], [1750, 'hit_win'], [1750, 'sting:victory']] },
  { id: 'turn', steps: [[0, 'tile_draw'], [700, 'tile_rotate'], [1300, 'tile_place'], [1440, 'step'], [2400, 'item_pickup'], [3300, 'end_turn'], [3440, 'your_turn']] },
  { id: 'chest', steps: [[0, 'step'], [600, 'chest_open'], [740, 'treasure']] },
];

const cards = {};
const nowPlaying = ref(null);
const timers = [];

const playTrack = (track) => {
  audio.unlock();
  audio.music(track);
  nowPlaying.value = track;
};
const stopMusic = () => {
  audio.music(null);
  nowPlaying.value = null;
};

const playMoment = (moment) => {
  timers.splice(0).forEach(clearTimeout);
  for (const [at, id] of moment.steps) {
    timers.push(setTimeout(() => cards[id]?.play(), at));
  }
};

// the level meter: what comes out of the master bus
const meterCanvas = ref(null);
let meterFrame = null;
const drawMeter = () => {
  const analyser = audio.ctx ? audio.meter() : null;
  const canvas = meterCanvas.value;
  if (analyser && canvas) {
    const g = canvas.getContext('2d');
    const bins = new Uint8Array(analyser.frequencyBinCount);
    analyser.getByteFrequencyData(bins);
    g.clearRect(0, 0, canvas.width, canvas.height);
    const bars = 24;
    const per = Math.floor(bins.length / bars);
    for (let i = 0; i < bars; i++) {
      let v = 0;
      for (let j = 0; j < per; j++) v = Math.max(v, bins[i * per + j]);
      const h = (v / 255) * canvas.height;
      g.fillStyle = `rgba(255, ${140 + i * 3}, 40, ${0.4 + v / 425})`;
      g.fillRect(i * 5, canvas.height - h, 4, h);
    }
  }
  meterFrame = requestAnimationFrame(drawMeter);
};

const candidates = ref({});
const loadCandidates = async () => {
  try {
    const response = await fetch('/audio/candidates/index.json', { cache: 'no-store' });
    if (response.ok && (response.headers.get('content-type') || '').includes('json')) candidates.value = await response.json();
  } catch {
    // no takes waiting
  }
};

onMounted(() => {
  loadCandidates();
  // the bench starts quiet: the page's own music would mask what is being listened to
  audio.music(null);
  drawMeter();
});
onBeforeUnmount(() => {
  cancelAnimationFrame(meterFrame);
  timers.forEach(clearTimeout);
});
</script>

<style scoped>
.bench {
  min-height: 100vh;
  box-sizing: border-box;
  padding: 28px 32px 60px;
  font-family: var(--font-body);
  color: var(--text-primary);
  background: var(--bg-field, #070504);
}

.bench__head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
  margin-bottom: 10px;
}

.bench__title {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 44px;
  color: var(--text-heading);
}

.bench__intro,
.bench__note {
  max-width: 46em;
  margin: 4px 0 0;
  font-size: 15px;
  color: var(--text-secondary);
}

.bench__tools {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.bench__tools .btn {
  white-space: nowrap;
}

.bench__meter {
  width: 120px;
  height: 34px;
  background: #0b0705;
  border: 1px solid var(--iron-dark);
}

.bench__section {
  margin-top: 28px;
}

.bench__h2 {
  margin: 0 0 12px;
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.32em;
  text-transform: uppercase;
  color: var(--text-accent);
}

.bench__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 14px;
}

.bench__grid--wide {
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
}

.bench__moments {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 10px;
}

.track {
  display: flex;
  flex-direction: column;
  background: rgba(20, 12, 8, 0.92);
  border: 1px solid var(--iron-dark);
}

.track--on {
  border-color: rgba(var(--ember-rgb), 0.7);
  box-shadow: 0 0 18px rgba(var(--ember-rgb), 0.25);
}

.track__body {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  padding: 10px 12px 12px;
}

.track__head {
  display: flex;
  justify-content: space-between;
  width: 100%;
  font-size: 13px;
  color: var(--text-accent);
}

.track__files {
  font-size: 12px;
  color: var(--text-muted);
}

.track__when {
  margin: 0;
  font-size: 13px;
  color: var(--text-secondary);
}

@media (max-width: 600px) {
  .bench {
    padding: 20px 16px 40px;
  }

  .bench__title {
    font-size: 34px;
  }
}
</style>
