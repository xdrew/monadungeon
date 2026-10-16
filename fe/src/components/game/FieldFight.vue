<template>
  <!-- A fight on the field (an AI's or a rival's, played back): the hero and the foe in medallions, the dice
       tumble and land, then the score and the outcome. Positioned over the fight's tile by the parent. -->
  <div
    class="field-fight"
    :class="[`field-fight--${phase}`, phase === 'done' ? `field-fight--${result}` : '']"
    :style="{ '--speed': speed }"
    data-testid="field-fight"
    :data-result="result"
    role="status"
    :aria-label="ariaLabel"
  >
    <div class="field-fight__duel">
      <span class="field-fight__ring field-fight__ring--hero">
        <img
          :src="heroImage"
          alt=""
        >
      </span>
      <GameIcon
        name="sword"
        :size="18"
        class="field-fight__vs"
      />
      <span class="field-fight__ring field-fight__ring--foe">
        <img
          :src="monsterImage"
          alt=""
        >
        <span
          class="field-fight__slash"
          aria-hidden="true"
        />
      </span>
    </div>

    <div class="field-fight__roll">
      <DieFace
        v-for="(value, i) in shownDice"
        :key="i"
        :value="value"
        :rolling="phase === 'rolling'"
        :landed="phase !== 'rolling'"
        :tilt="i ? 9 : -7"
        :delay="i * 0.07"
        :throw-from="0.6"
      />
    </div>

    <div class="field-fight__score">
      <strong class="field-fight__total">{{ total }}</strong>
      <span class="field-fight__colon">:</span>
      <span class="field-fight__hp">{{ monsterHp }}</span>
    </div>
    <div class="field-fight__outcome">
      {{ t(`fieldFight.${result}`) }}
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import GameIcon from '@/components/ui/GameIcon.vue';
import DieFace from '@/components/game/fight/DieFace.vue';
import { getHero, getMonsterImage } from '@/theme';
import { monsterName } from '@/i18n/names';

const props = defineProps({
  heroKey: { type: String, default: null },
  monsterType: { type: String, default: '' },
  monsterHp: { type: Number, default: 0 },
  dice: { type: Array, default: () => [] },
  total: { type: Number, default: 0 },
  /** 'win' | 'lose' | 'draw' */
  result: { type: String, default: 'draw' },
  /** the playback speed: 2 plays it twice as fast */
  speed: { type: Number, default: 1 },
});

const { t } = useI18n();
const heroImage = computed(() => getHero(props.heroKey).image);
const monsterImage = computed(() => getMonsterImage(props.monsterType, props.monsterHp));
const shownDice = computed(() => (props.dice.length ? props.dice.slice(0, 2) : [1, 1]));
const ariaLabel = computed(() => `${monsterName(props.monsterType, 'name', props.monsterHp)}: ${props.total} : ${props.monsterHp}, ${t(`fieldFight.${props.result}`)}`);

// the same beats as the fight scene, shorter: the dice tumble, land, then the outcome
const phase = ref('rolling');
const timers = [];
onMounted(() => {
  const at = (ms) => ms / Math.max(0.25, props.speed);
  timers.push(setTimeout(() => { phase.value = 'landed'; }, at(850)));
  timers.push(setTimeout(() => { phase.value = 'done'; }, at(1150)));
});
onBeforeUnmount(() => timers.forEach(clearTimeout));
</script>

<style scoped>
.field-fight {
  --die-size: 26px;
  --die-hop: 0.25; /* a short throw: the dice stay in their row, under the medallions */
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  min-width: 132px;
  padding: 8px 10px 7px;
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.95) 0%, rgba(10, 6, 4, 0.95) 100%);
  border: 1px solid rgba(var(--ember-rgb), 0.5);
  box-shadow: 0 0 22px rgba(255, 100, 20, 0.3), 0 8px 18px rgba(0, 0, 0, 0.8);
  font-family: var(--font-body);
  color: var(--text-primary);
  pointer-events: none;
  animation: fieldFightIn calc(0.25s / var(--speed)) ease-out;
}

.field-fight__duel {
  display: flex;
  align-items: center;
  gap: 6px;
}

.field-fight__ring {
  position: relative;
  display: flex;
  align-items: flex-start;
  width: 44px;
  height: 44px;
  overflow: hidden;
  border-radius: 50%;
  background: radial-gradient(circle at 50% 35%, #4a3020 0%, #140c07 75%);
  box-shadow: 0 0 0 2px var(--iron), 0 0 0 3px var(--iron-edge);
  transition: filter calc(0.3s / var(--speed)), box-shadow calc(0.3s / var(--speed)), opacity calc(0.3s / var(--speed));
}

.field-fight__ring img {
  width: 100%;
  height: 150%;
  object-fit: cover;
  object-position: top;
}

.field-fight__ring--foe img {
  transform: scaleX(-1); /* the foe faces the hero */
}

.field-fight__vs {
  color: var(--text-accent);
  transform: rotate(45deg);
}

.field-fight__slash {
  position: absolute;
  left: -10%;
  top: 48%;
  width: 120%;
  height: 3px;
  background: linear-gradient(90deg, rgba(255, 255, 255, 0) 0%, #fff 50%, rgba(255, 255, 255, 0) 100%);
  box-shadow: 0 0 10px #fff;
  transform: rotate(-35deg) scaleX(0);
  opacity: 0;
}

.field-fight__roll {
  display: flex;
  justify-content: center;
  gap: 10px;
  width: 100%;
  height: 34px;
  align-items: center;
}

.field-fight__score {
  display: flex;
  align-items: baseline;
  gap: 4px;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 1;
  opacity: 0;
  transform: translateY(4px);
  transition: opacity calc(0.2s / var(--speed)), transform calc(0.2s / var(--speed));
}

.field-fight__total {
  font-weight: 400;
  color: var(--text-heading);
}

.field-fight__colon,
.field-fight__hp {
  color: var(--text-muted);
}

.field-fight__outcome {
  min-height: 1.2em;
  font-weight: 600;
  font-size: 12px;
  letter-spacing: 0.24em;
  text-transform: uppercase;
  opacity: 0;
}

/* the dice lie: the score */
.field-fight--landed .field-fight__score,
.field-fight--done .field-fight__score {
  opacity: 1;
  transform: none;
}

/* the outcome */
.field-fight--done .field-fight__outcome {
  opacity: 1;
  animation: fieldFightSlam calc(0.3s / var(--speed)) ease-out;
}

.field-fight--win {
  border-color: rgba(var(--ember-rgb), 0.85);
}

.field-fight--win .field-fight__outcome {
  color: var(--text-accent);
  text-shadow: 0 0 12px rgba(var(--ember-rgb), 0.8);
}

.field-fight--win .field-fight__ring--foe {
  filter: grayscale(1) brightness(0.55);
}

.field-fight--win .field-fight__slash {
  animation: fieldFightSlash calc(0.35s / var(--speed)) ease-out forwards;
}

.field-fight--win .field-fight__ring--hero {
  box-shadow: 0 0 0 2px var(--color-primary), 0 0 0 3px var(--iron-edge), 0 0 14px rgba(var(--ember-rgb), 0.7);
}

.field-fight--lose {
  border-color: rgba(217, 69, 42, 0.85);
  box-shadow: 0 0 22px rgba(217, 69, 42, 0.4), 0 8px 18px rgba(0, 0, 0, 0.8);
}

.field-fight--lose .field-fight__outcome {
  color: #f08a74;
}

.field-fight--lose .field-fight__ring--hero {
  box-shadow: 0 0 0 2px #d9452a, 0 0 0 3px var(--iron-edge), 0 0 14px rgba(217, 69, 42, 0.8);
  animation: fieldFightHurt calc(0.45s / var(--speed)) ease-out;
}

.field-fight--draw .field-fight__outcome {
  color: var(--text-secondary);
}

.field-fight--draw .field-fight__ring--hero {
  animation: fieldFightClashL calc(0.35s / var(--speed)) ease-out;
}

.field-fight--draw .field-fight__ring--foe {
  animation: fieldFightClashR calc(0.35s / var(--speed)) ease-out;
}

@keyframes fieldFightIn {
  from { opacity: 0; transform: translateY(6px) scale(0.92); }
  to { opacity: 1; transform: none; }
}

@keyframes fieldFightSlam {
  from { transform: scale(1.6); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

@keyframes fieldFightSlash {
  0% { transform: rotate(-35deg) scaleX(0); opacity: 1; }
  100% { transform: rotate(-35deg) scaleX(1); opacity: 0.9; }
}

@keyframes fieldFightHurt {
  0%, 100% { transform: none; }
  25% { transform: translateX(-4px); filter: brightness(1.6); }
  50% { transform: translateX(3px); }
}

@keyframes fieldFightClashL {
  50% { transform: translateX(5px); }
}

@keyframes fieldFightClashR {
  50% { transform: translateX(-5px); }
}

@media (prefers-reduced-motion: reduce) {
  .field-fight,
  .field-fight * {
    animation: none !important;
    transition: none !important;
  }
}
</style>
