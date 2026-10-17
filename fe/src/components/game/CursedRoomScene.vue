<template>
  <!-- Karak: Regent — a Cursed Room: the hero rolls one die (1–3 cursed, 4–6 spared), big, as in a fight; then the
       Curse of Karak token lands on the hero, or stays in the room. Closing it lets the token fly to its place. -->
  <div
    class="curse-scene"
    :class="[`curse-scene--${stage}`, stage === 'done' ? (event.cursed ? 'curse-scene--cursed' : 'curse-scene--spared') : '']"
    data-testid="cursed-room-scene"
    :data-roll="event.roll"
    :data-cursed="event.cursed"
    role="dialog"
    aria-modal="true"
    @click.self="close"
  >
    <header class="curse-scene__head">
      <span class="curse-scene__caption">{{ t('curseScene.caption') }}</span>
      <h2 class="curse-scene__title">
        {{ event.alreadyCursed ? t('curseScene.enters', { who: name }) : t('curseScene.rolls', { who: name }) }}
      </h2>
      <p class="curse-scene__rule">
        {{ event.alreadyCursed ? t('curseScene.alreadyRule') : t('curseScene.rule') }}
      </p>
    </header>

    <div class="curse-scene__stage">
      <FighterFigure
        :src="sprite"
        :name="name"
        side="hero"
        :state="stage === 'done' ? (event.cursed ? 'defeat' : 'victory') : 'idle'"
      />
      <div class="curse-scene__middle">
        <div
          v-if="!event.alreadyCursed"
          class="curse-scene__die"
        >
          <DieFace
            :value="event.roll"
            :rolling="stage === 'rolling'"
            :landed="stage !== 'rolling'"
            :tilt="-6"
          />
        </div>
        <div
          v-if="!event.alreadyCursed"
          class="curse-scene__scale"
          aria-hidden="true"
        >
          <span
            v-for="n in 6"
            :key="n"
            class="curse-scene__pip"
            :class="{ 'curse-scene__pip--bad': n <= 3, 'curse-scene__pip--hit': stage !== 'rolling' && n === event.roll }"
          >{{ n }}</span>
        </div>
        <img
          ref="token"
          src="/images/curse.webp"
          :alt="t('curseScene.token')"
          class="curse-scene__token"
          data-testid="cursed-room-token"
        >
        <p
          v-if="stage === 'done'"
          class="curse-scene__outcome"
          data-testid="cursed-room-outcome"
        >
          {{ event.alreadyCursed ? t('curseScene.already', { who: name }) : event.cursed ? t('curseScene.cursed', { who: name }) : t('curseScene.spared', { who: name }) }}
        </p>
        <button
          v-if="stage === 'done'"
          type="button"
          class="btn btn--ember btn-primary curse-scene__ok"
          data-testid="cursed-room-ok"
          @click="close"
        >
          {{ t('curseScene.ok') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FighterFigure from '@/components/game/fight/FighterFigure.vue';
import DieFace from '@/components/game/fight/DieFace.vue';
import { audio } from '@/services/audio/audioEngine';

const props = defineProps({
  /** state.roomEvent: { playerId, position, roll, cursed } */
  event: { type: Object, required: true },
  name: { type: String, default: '' },
  sprite: { type: String, required: true },
  /** the scene closes by itself after this many ms once told (0: waits for the button) */
  autoCloseMs: { type: Number, default: 0 },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

// rolling → told (the die lies, the token moves) → done (the words and the button)
const stage = ref('rolling');
const token = ref(null);
const timers = [];
onMounted(() => {
  // a hero already cursed does not roll: the scene only says so
  if (props.event.alreadyCursed) {
    stage.value = 'done';
    audio.cue('curse');
    if (props.autoCloseMs) timers.push(setTimeout(close, props.autoCloseMs));
    return;
  }
  audio.cue('dice_roll');
  timers.push(setTimeout(() => { stage.value = 'told'; audio.cue(props.event.cursed ? 'curse' : 'curse_lifted'); }, 1300));
  timers.push(setTimeout(() => { stage.value = 'done'; }, 2300));
  if (props.autoCloseMs) timers.push(setTimeout(close, 2300 + props.autoCloseMs));
});
onBeforeUnmount(() => timers.forEach(clearTimeout));

let closed = false;
function close() {
  if (closed || stage.value !== 'done') return;
  closed = true;
  // the parent flies the token from here to the hero's card, or back into the room
  emit('close', token.value?.getBoundingClientRect() || null);
}
</script>

<style scoped>
.curse-scene {
  position: fixed;
  inset: 0;
  z-index: var(--z-modal);
  display: flex;
  flex-direction: column;
  align-items: center;
  /* centred while it fits, from the top when it does not (a phone): the title is never cut off */
  justify-content: safe center;
  gap: 12px;
  padding: 24px 16px;
  box-sizing: border-box;
  overflow-y: auto;
  font-family: var(--font-body);
  color: var(--text-primary);
  background-color: rgba(5, 3, 6, 0.97);
  background-image: radial-gradient(ellipse at 50% 55%, rgba(110, 50, 160, 0.22) 0%, rgba(0, 0, 0, 0) 55%);
  animation: curseSceneIn 0.35s ease-out;
}

.curse-scene--cursed {
  background-image: radial-gradient(ellipse at 50% 55%, rgba(140, 60, 200, 0.4) 0%, rgba(0, 0, 0, 0) 55%);
}

.curse-scene--spared {
  background-image: radial-gradient(ellipse at 50% 55%, rgba(var(--ember-rgb), 0.18) 0%, rgba(0, 0, 0, 0) 55%);
}

@keyframes curseSceneIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.curse-scene__head {
  text-align: center;
}

.curse-scene__caption {
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.32em;
  text-transform: uppercase;
  color: #c9a6ef;
}

.curse-scene__title {
  margin: 6px 0 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: clamp(30px, 5vw, 50px);
  line-height: 1;
  color: var(--text-heading);
  text-shadow: 0 0 24px rgba(140, 60, 200, 0.5), 0 4px 12px #000;
}

.curse-scene__rule {
  margin: 8px 0 0;
  font-style: italic;
  font-size: 16px;
  color: var(--text-secondary);
}

.curse-scene__stage {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: clamp(16px, 5vw, 64px);
  flex-wrap: wrap;
  --fighter-w: clamp(140px, min(24vw, 34vh), 280px);
  --fighter-img-h: clamp(200px, 46vh, 390px);
  --fighter-img-w: calc(var(--fighter-img-h) * 0.72);
}

.curse-scene__middle {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
  min-width: 260px;
}

.curse-scene__die {
  --die-size: 96px;
  height: 120px;
  display: flex;
  align-items: center;
}

.curse-scene__scale {
  display: flex;
  gap: 6px;
}

.curse-scene__pip {
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  font-weight: 600;
  font-size: 15px;
  color: var(--text-secondary);
  transition: transform 0.25s, background 0.25s;
}

.curse-scene__pip--bad {
  border-color: rgba(160, 90, 220, 0.6);
  color: #c9a6ef;
}

.curse-scene__pip--hit {
  transform: scale(1.3);
  background: rgba(var(--ember-rgb), 0.3);
  color: var(--text-heading);
}

.curse-scene__pip--bad.curse-scene__pip--hit {
  background: rgba(140, 60, 200, 0.55);
}

/* the token: waits in the room (dim), then rises onto the hero (cursed) or sinks back (spared) */
.curse-scene__token {
  width: 92px;
  height: 92px;
  object-fit: contain;
  opacity: 0.35;
  filter: grayscale(0.5) drop-shadow(0 0 6px rgba(140, 60, 200, 0.4));
  transition: transform 0.6s cubic-bezier(0.2, 0.9, 0.3, 1.2), opacity 0.4s, filter 0.4s;
}

.curse-scene--told.curse-scene--cursed .curse-scene__token,
.curse-scene--done.curse-scene--cursed .curse-scene__token,
.curse-scene--told .curse-scene__token {
  opacity: 1;
}

.curse-scene--done.curse-scene--cursed .curse-scene__token {
  opacity: 1;
  transform: scale(1.25);
  filter: drop-shadow(0 0 22px rgba(170, 80, 240, 0.95));
  animation: curseTokenPulse 1.6s ease-in-out infinite;
}

.curse-scene--done.curse-scene--spared .curse-scene__token {
  opacity: 0.3;
  transform: scale(0.8);
  filter: grayscale(1);
}

@keyframes curseTokenPulse {
  50% { filter: drop-shadow(0 0 32px rgba(190, 100, 255, 1)); }
}

.curse-scene__outcome {
  max-width: 360px;
  margin: 0;
  font-family: var(--font-display);
  font-size: 26px;
  line-height: 1.15;
  text-align: center;
  color: var(--text-heading);
  animation: curseOutcomeIn 0.35s ease-out;
}

.curse-scene--cursed .curse-scene__outcome {
  color: #d8b6ff;
  text-shadow: 0 0 18px rgba(140, 60, 200, 0.8);
}

@keyframes curseOutcomeIn {
  from { opacity: 0; transform: scale(1.4); }
  to { opacity: 1; transform: none; }
}

.curse-scene__ok {
  min-width: 200px;
  min-height: 50px;
  font-family: var(--font-display);
  font-size: 22px;
}

@media (max-width: 600px) {
  .curse-scene__stage {
    --fighter-w: 160px;
    --fighter-img-h: 190px;
    --fighter-name: 22px;
    flex-direction: column;
    gap: 8px;
  }

  .curse-scene__die {
    --die-size: 72px;
    height: 90px;
  }

  .curse-scene__token {
    width: 64px;
    height: 64px;
  }

  .curse-scene__outcome {
    font-size: 21px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .curse-scene,
  .curse-scene * {
    animation: none !important;
    transition: none !important;
  }
}
</style>
