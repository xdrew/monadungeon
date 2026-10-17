<template>
  <!-- Karak: Regent — a Cursed Room roll on the field (an AI's or a rival's, played back): the hero and the Curse
       of Karak token, the die lands, then the outcome. Positioned over the room's tile by the parent, like FieldFight. -->
  <div
    class="field-curse"
    :class="[`field-curse--${phase}`, phase === 'done' ? `field-curse--${outcome}` : '']"
    :style="{ '--speed': speed }"
    data-testid="field-curse"
    :data-roll="roll"
    :data-cursed="cursed"
    role="status"
    :aria-label="ariaLabel"
  >
    <div class="field-curse__duel">
      <span class="field-curse__ring field-curse__ring--hero">
        <img
          :src="heroImage"
          alt=""
        >
      </span>
      <img
        ref="token"
        src="/images/curse.webp"
        alt=""
        class="field-curse__token"
      >
    </div>

    <div
      v-if="!alreadyCursed"
      class="field-curse__roll"
    >
      <DieFace
        :value="roll || 1"
        :rolling="phase === 'rolling'"
        :landed="phase !== 'rolling'"
        :tilt="-7"
        :throw-from="0.6"
      />
    </div>

    <div
      v-if="!alreadyCursed"
      class="field-curse__scale"
      aria-hidden="true"
    >
      <span
        v-for="n in 6"
        :key="n"
        class="field-curse__pip"
        :class="{ 'field-curse__pip--bad': n <= 3, 'field-curse__pip--hit': phase !== 'rolling' && n === roll }"
      >{{ n }}</span>
    </div>
    <div class="field-curse__outcome">
      {{ t(`fieldCurse.${outcome}`) }}
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import DieFace from '@/components/game/fight/DieFace.vue';
import { getHero } from '@/theme';

const props = defineProps({
  heroKey: { type: String, default: null },
  name: { type: String, default: '' },
  roll: { type: Number, default: null },
  cursed: { type: Boolean, default: false },
  /** a hero already cursed does not roll: the room only shows the entry */
  alreadyCursed: { type: Boolean, default: false },
  /** the playback speed: 2 plays it twice as fast */
  speed: { type: Number, default: 1 },
});

// once the outcome is shown: the token's place on screen, for its flight onto the hero's card
const emit = defineEmits(['settled']);

const { t } = useI18n();
const heroImage = computed(() => getHero(props.heroKey).image);
const outcome = computed(() => (props.alreadyCursed ? 'already' : props.cursed ? 'cursed' : 'spared'));
const ariaLabel = computed(() => t('fieldCurse.label', { who: props.name, roll: props.roll ?? '–', outcome: t(`fieldCurse.${outcome.value}`) }));

// the beats of the Cursed Room scene, shorter: the die tumbles, lands, then the outcome
const token = ref(null);
const phase = ref(props.alreadyCursed ? 'landed' : 'rolling');
const timers = [];
onMounted(() => {
  const at = (ms) => ms / Math.max(0.25, props.speed);
  timers.push(setTimeout(() => { phase.value = 'landed'; }, at(props.alreadyCursed ? 0 : 850)));
  timers.push(setTimeout(() => {
    phase.value = 'done';
    emit('settled', token.value?.getBoundingClientRect() || null);
  }, at(props.alreadyCursed ? 300 : 1150)));
});
onBeforeUnmount(() => timers.forEach(clearTimeout));
</script>

<style scoped>
.field-curse {
  --die-size: 26px;
  --die-hop: 0.25;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  min-width: 132px;
  padding: 8px 10px 7px;
  background: linear-gradient(180deg, rgba(18, 10, 22, 0.95) 0%, rgba(8, 5, 10, 0.95) 100%);
  border: 1px solid rgba(170, 80, 240, 0.5);
  box-shadow: 0 0 22px rgba(170, 80, 240, 0.3), 0 8px 18px rgba(0, 0, 0, 0.8);
  font-family: var(--font-body);
  color: var(--text-primary);
  pointer-events: none;
  animation: fieldCurseIn calc(0.25s / var(--speed)) ease-out;
}

.field-curse__duel {
  display: flex;
  align-items: center;
  gap: 10px;
}

.field-curse__ring {
  position: relative;
  display: flex;
  align-items: flex-start;
  width: 44px;
  height: 44px;
  overflow: hidden;
  border-radius: 50%;
  background: radial-gradient(circle at 50% 35%, #3a2840 0%, #100a12 75%);
  box-shadow: 0 0 0 2px var(--iron), 0 0 0 3px var(--iron-edge);
  transition: box-shadow calc(0.3s / var(--speed));
}

.field-curse__ring img {
  width: 100%;
  height: 150%;
  object-fit: cover;
  object-position: top;
}

/* the token waits dim in the room, then glows onto the hero (cursed) or sinks back (spared) */
.field-curse__token {
  width: 34px;
  height: 34px;
  object-fit: contain;
  opacity: 0.55;
  filter: grayscale(0.4);
  transition: transform calc(0.35s / var(--speed)) ease-out, opacity calc(0.35s / var(--speed)), filter calc(0.35s / var(--speed));
}

.field-curse--rolling .field-curse__token {
  animation: fieldCursePulse calc(0.9s / var(--speed)) ease-in-out infinite;
}

.field-curse--done.field-curse--cursed .field-curse__token,
.field-curse--done.field-curse--already .field-curse__token {
  opacity: 1;
  filter: drop-shadow(0 0 10px rgba(170, 80, 240, 0.9));
  transform: translateX(-8px) scale(1.12);
}

.field-curse--done.field-curse--spared .field-curse__token {
  opacity: 0.35;
  transform: translateY(4px) scale(0.85);
}

.field-curse__roll {
  display: flex;
  justify-content: center;
  width: 100%;
  height: 34px;
  align-items: center;
}

.field-curse__scale {
  display: flex;
  gap: 3px;
}

.field-curse__pip {
  width: 14px;
  font-size: 11px;
  line-height: 14px;
  text-align: center;
  color: var(--text-muted);
  border-bottom: 2px solid rgba(var(--ember-rgb), 0.45);
  transition: color calc(0.2s / var(--speed)), transform calc(0.2s / var(--speed));
}

.field-curse__pip--bad {
  border-bottom-color: rgba(170, 80, 240, 0.7);
}

.field-curse__pip--hit {
  color: var(--text-heading);
  font-weight: 700;
  transform: scale(1.3);
}

.field-curse__outcome {
  min-height: 1.2em;
  font-weight: 600;
  font-size: 12px;
  letter-spacing: 0.24em;
  text-transform: uppercase;
  opacity: 0;
}

.field-curse--done .field-curse__outcome {
  opacity: 1;
  animation: fieldCurseSlam calc(0.3s / var(--speed)) ease-out;
}

.field-curse--cursed,
.field-curse--already {
  border-color: rgba(170, 80, 240, 0.9);
}

.field-curse--cursed .field-curse__outcome,
.field-curse--already .field-curse__outcome {
  color: #c99af5;
  text-shadow: 0 0 12px rgba(170, 80, 240, 0.8);
}

.field-curse--done.field-curse--cursed .field-curse__ring--hero {
  box-shadow: 0 0 0 2px #a050f0, 0 0 0 3px var(--iron-edge), 0 0 14px rgba(170, 80, 240, 0.8);
}

.field-curse--spared {
  border-color: rgba(var(--ember-rgb), 0.85);
}

.field-curse--spared .field-curse__outcome {
  color: var(--text-accent);
  text-shadow: 0 0 12px rgba(var(--ember-rgb), 0.8);
}

@keyframes fieldCurseIn {
  from { opacity: 0; transform: translateY(6px) scale(0.92); }
  to { opacity: 1; transform: none; }
}

@keyframes fieldCurseSlam {
  from { transform: scale(1.6); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

@keyframes fieldCursePulse {
  50% { opacity: 0.8; }
}

@media (prefers-reduced-motion: reduce) {
  .field-curse,
  .field-curse * {
    animation: none !important;
    transition: none !important;
  }
}
</style>
