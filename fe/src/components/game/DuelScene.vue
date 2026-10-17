<template>
  <!-- Karak: Regent — the Battle Arena duel as a scene, like a fight with a monster: the two heroes face each other;
       both choose their spells before any die is rolled, then the dice, the totals, the outcome and the loot. -->
  <div
    class="duel-scene"
    :class="[`duel-scene--${phase}`, outcomeClass]"
    data-testid="duel-scene"
    role="dialog"
    aria-modal="true"
  >
    <header class="duel-scene__head">
      <span class="duel-scene__caption">{{ t('duelScene.caption') }}</span>
      <h2 class="duel-scene__title">
        {{ left.name }} — {{ right.name }}
      </h2>
    </header>

    <div class="duel-scene__stage">
      <div
        v-for="side in [left, right]"
        :key="side.role"
        class="duel-side"
        :class="[`duel-side--${side.place}`, { 'duel-side--loser': duel.loser === side.id, 'duel-side--winner': winnerId === side.id }]"
        :data-testid="`duel-side-${side.role}`"
      >
        <FighterFigure
          :src="side.sprite"
          :name="side.name"
          :side="side.place === 'left' ? 'hero' : 'foe'"
          :state="figureState(side)"
        />
        <div class="duel-side__hearts">
          <GameIcon
            v-for="n in side.maxHp"
            :key="n"
            name="heart"
            :size="15"
            class="duel-side__heart"
            :class="{ filled: n <= side.hp }"
          />
        </div>

        <!-- what the side spends, chosen before the roll -->
        <p
          class="duel-side__prep"
          :data-testid="`duel-prep-${side.role}`"
        >
          <template v-if="side.prep">
            <template v-if="side.prep.items.length || side.prep.sacrifice">
              <span
                v-for="item in side.prep.items"
                :key="item.itemId"
                class="duel-chip"
              >
                <img
                  v-if="getItemImage(item)"
                  :src="getItemImage(item)"
                  alt=""
                >{{ itemName(item.type) }} +{{ getItemDamage(item) }}
              </span>
              <span
                v-if="side.prep.sacrifice"
                class="duel-chip duel-chip--blood"
              >{{ t('skills.sacrifice.name') }} +1</span>
            </template>
            <template v-else>
              {{ t('duelScene.noSpells') }}
            </template>
          </template>
          <template v-else-if="isPreparing(side.role)">
            {{ t('duelScene.choosing') }}
          </template>
        </p>
      </div>

      <!-- the middle: the choice before the roll, then the outcome -->
      <div class="duel-scene__middle">
        <GameIcon
          name="sword"
          :size="34"
          class="duel-scene__vs"
        />

        <!-- the scoreboard: each side's dice tumble when its total arrives, then lie -->
        <div
          v-if="phaseRank >= 2"
          class="duel-board"
        >
          <div
            v-for="side in [left, right]"
            :key="`board-${side.role}`"
            class="duel-side__roll"
            :class="{ 'duel-side--loser': duel.loser === side.id, 'duel-side--winner': winnerId === side.id }"
          >
            <template v-if="side.total !== null">
              <div class="duel-side__dice">
                <DieFace
                  v-for="(value, i) in side.dice"
                  :key="`${side.role}-${i}`"
                  :value="value"
                  :rolling="rolling[side.role]"
                  :landed="!rolling[side.role]"
                  :tilt="i ? 8 : -6"
                  :delay="i * 0.07"
                />
              </div>
              <div
                class="duel-side__total"
                :class="{ 'duel-side__total--shown': !rolling[side.role] }"
                :data-testid="`duel-total-${side.role}`"
              >
                {{ side.total }}
              </div>
              <div
                class="duel-side__breakdown"
                :class="{ 'duel-side__total--shown': !rolling[side.role] }"
              >
                {{ side.breakdown }}
              </div>
            </template>
            <div
              v-else-if="phaseRank >= 2"
              class="duel-side__waiting"
            >
              {{ t('duelScene.rolls') }}
            </div>
          </div>
        </div>

        <section
          v-if="myChoice"
          class="duel-prepare"
          data-testid="duel-prepare"
        >
          <h3 class="duel-prepare__title">
            {{ t('duelScene.prepareTitle', { who: actorName }) }}
          </h3>
          <p class="duel-prepare__hint">
            {{ t('duelScene.prepareHint') }}
          </p>
          <div
            v-if="spells.length || canSacrifice"
            class="duel-prepare__cards"
          >
            <ConsumableCard
              v-for="spell in spells"
              :key="spell.itemId"
              :name="itemName(spell.type)"
              :effect="t('duelScene.plus', { n: getItemDamage(spell) })"
              :image="getItemImage(spell)"
              :selected="picked.includes(spell.itemId)"
              :data-testid="`duel-spell-${spell.itemId}`"
              @toggle="toggle(spell.itemId)"
            />
            <ConsumableCard
              v-if="canSacrifice"
              :name="t('skills.sacrifice.name')"
              :effect="t('duelScene.sacrifice')"
              icon="heart"
              :selected="sacrifice"
              data-testid="duel-sacrifice"
              @toggle="sacrifice = !sacrifice"
            />
          </div>
          <p
            v-else
            class="duel-prepare__none"
          >
            {{ t('duelScene.nothingToSpend') }}
          </p>
          <button
            type="button"
            class="btn btn--ember btn-primary duel-prepare__go"
            :disabled="disabled || sending"
            data-testid="duel-prepare-go"
            @click="go"
          >
            {{ t('duelScene.go') }}
          </button>
        </section>

        <p
          v-else-if="phaseRank < 2"
          class="duel-scene__note"
        >
          {{ t('duelScene.waitsFor', { who: actorName }) }}
        </p>

        <div
          v-if="duel.result && revealed"
          class="duel-scene__slam"
          :class="{ 'duel-scene__slam--told': !meInDuel && duel.result !== 'tie' }"
          data-testid="duel-slam"
        >
          {{ slamText }}
        </div>

        <DuelResultPanel
          v-if="duel.result && revealed"
          in-scene
          :duel="duel"
          :players="labels"
          :current-player-id="lootChooserId"
          :inventory="inventory"
          :disabled="disabled"
          @loot="emit('loot', $event)"
          @dismiss="emit('dismiss')"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import GameIcon from '@/components/ui/GameIcon.vue';
import FighterFigure from '@/components/game/fight/FighterFigure.vue';
import DieFace from '@/components/game/fight/DieFace.vue';
import ConsumableCard from '@/components/game/fight/ConsumableCard.vue';
import DuelResultPanel from '@/components/game/DuelResultPanel.vue';
import { getHero } from '@/theme';
import { itemName as localizedItemName } from '@/i18n/names';
import { getItemDamage, getItemImage } from '@/utils/itemUtils';
import { audio } from '@/services/audio/audioEngine';

const props = defineProps({
  /** state.duel */
  duel: { type: Object, required: true },
  /** gameData.players */
  players: { type: Array, default: () => [] },
  /** [{ id, label, name }] as the duel panel takes them */
  labels: { type: Array, default: () => [] },
  /** the seat this browser plays (online), or null: every human hero at this screen */
  mySeatId: { type: String, default: null },
  online: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['prepare', 'loot', 'dismiss']);
const { t } = useI18n();

const PHASES = ['challenger_prepare', 'opponent_prepare', 'challenger_roll', 'opponent_roll', 'loot', 'done'];
const phase = computed(() => props.duel.phase || 'challenger_prepare');
const phaseRank = computed(() => Math.max(0, PHASES.indexOf(phase.value)));
const playerOf = (id) => props.players.find((p) => p.id === id) || null;
const labelOf = (id) => props.labels.find((p) => p.id === id) || {};
const isHumanHere = (id) => {
  const player = playerOf(id);
  if (!player || player.isAi) return false;
  return props.online ? id === props.mySeatId : true;
};

const itemName = (type) => localizedItemName(type, 'nom');
const SPELLS = ['fireball', 'frost_fist'];

const sideOf = (role) => {
  const id = props.duel[`${role}Id`];
  const player = playerOf(id) || {};
  const details = props.duel[`${role}Details`] || null;
  const dice = details?.dice?.length ? details.dice : [1, 1];
  const extras = [];
  if (details?.itemDamage) extras.push(t('duelScene.items', { n: details.itemDamage }));
  if (details?.hpSacrificed) extras.push(t('skills.sacrifice.name'));
  if (details?.farseeing) extras.push(t('skills.farseeing.name'));
  if (details?.rerolled) extras.push(t('skills.double_attack.name'));
  return {
    role,
    id,
    name: labelOf(id).name || player.username || '',
    sprite: getHero(player.lordKarak ? 'lord_karak' : player.hero).image,
    hp: player.hp ?? 5,
    maxHp: player.maxHp ?? 5,
    prep: props.duel[`${role}Prep`] || null,
    total: props.duel[`${role}Total`] ?? null,
    dice,
    breakdown: [dice.join(' + '), ...extras].join(', '),
  };
};
// I stand on the left when I take part (the one human hero at this screen); else the challenger does
const humansHere = computed(() => [props.duel.challengerId, props.duel.opponentId].filter((id) => isHumanHere(id)));
const meInDuel = computed(() => (humansHere.value.length === 1 ? humansHere.value[0] : null));
const leftRole = computed(() => (meInDuel.value === props.duel.opponentId ? 'opponent' : 'challenger'));
const left = computed(() => ({ ...sideOf(leftRole.value), place: 'left' }));
const right = computed(() => ({ ...sideOf(leftRole.value === 'challenger' ? 'opponent' : 'challenger'), place: 'right' }));

const winnerId = computed(() => {
  if (props.duel.result === 'challenger') return props.duel.challengerId;
  if (props.duel.result === 'opponent') return props.duel.opponentId;
  return null;
});

// The choice before the roll: whose it is, and whether it is made at this screen
const actorRole = computed(() => (phase.value === 'challenger_prepare' ? 'challenger' : phase.value === 'opponent_prepare' ? 'opponent' : null));
const actorId = computed(() => (actorRole.value ? props.duel[`${actorRole.value}Id`] : null));
const actorName = computed(() => labelOf(actorId.value).name || '');
const myChoice = computed(() => !!actorId.value && isHumanHere(actorId.value));
const isPreparing = (role) => actorRole.value === role;
const actor = computed(() => playerOf(actorId.value));
const spells = computed(() => (actor.value?.inventory?.spells || []).filter((item) => SPELLS.includes(item.type)));
// a cursed hero has no skills (the Curse of Karak): no sacrifice
const canSacrifice = computed(() => !actor.value?.cursed
  && (actor.value?.heroSkills || []).some((s) => s.key === 'sacrifice') && (actor.value?.hp ?? 0) > 0);
const picked = ref([]);
const sacrifice = ref(false);
// one answer per choice: the button rests until the duel moves on to the next one
const sending = ref(false);
watch(actorId, () => { picked.value = []; sacrifice.value = false; sending.value = false; });
const toggle = (id) => {
  picked.value = picked.value.includes(id) ? picked.value.filter((x) => x !== id) : [...picked.value, id];
};
const go = () => {
  sending.value = true;
  emit('prepare', { playerId: actorId.value, consumableIds: [...picked.value], sacrifice: sacrifice.value });
  // a refused choice gives the button back
  timers.push(setTimeout(() => { sending.value = false; }, 4000));
};

// The rolls: each side's dice tumble once when its total arrives; the outcome waits for the last die
const rolling = reactive({ challenger: false, opponent: false });
const revealed = ref(!!props.duel.result);
const timers = [];
const ROLL_MS = 1000;
const seen = { challenger: props.duel.challengerTotal != null, opponent: props.duel.opponentTotal != null };
const roll = (role) => {
  rolling[role] = true;
  audio.cue('dice_roll');
  timers.push(setTimeout(() => { rolling[role] = false; }, ROLL_MS));
};
watch(() => [props.duel.challengerTotal, props.duel.opponentTotal], ([c, o]) => {
  // both totals may come in one answer: the challenger's dice first, then the summoned hero's
  let delay = 0;
  if (c != null && !seen.challenger) {
    seen.challenger = true;
    roll('challenger');
    delay = ROLL_MS + 200;
  }
  if (o != null && !seen.opponent) {
    seen.opponent = true;
    timers.push(setTimeout(() => roll('opponent'), delay));
    delay += ROLL_MS + 200;
  }
  if (props.duel.result && !revealed.value) {
    timers.push(setTimeout(reveal, delay + 150));
  }
});
watch(() => props.duel.result, (result) => {
  if (result && !revealed.value && !rolling.challenger && !rolling.opponent && seen.opponent) reveal();
});
const myResult = computed(() => {
  if (!props.duel.result) return '';
  if (props.duel.result === 'tie') return 'draw';
  // two heroes at this screen (or none): the outcome is told, not felt as mine
  if (!meInDuel.value) return 'win';
  return winnerId.value === meInDuel.value ? 'win' : 'lose';
});
function reveal() {
  revealed.value = true;
  const blow = { win: 'hit_win', lose: 'hit_lose', draw: 'draw' }[myResult.value];
  if (blow) audio.cue(blow);
  if (myResult.value === 'win') audio.sting('victory');
  if (myResult.value === 'lose') audio.sting('defeat');
}
onBeforeUnmount(() => timers.forEach(clearTimeout));

const slamText = computed(() => {
  if (props.duel.result === 'tie') return t('duelScene.tie');
  if (meInDuel.value) return winnerId.value === meInDuel.value ? t('duelScene.win') : t('duelScene.lose');
  return t('duelScene.wins', { who: labelOf(winnerId.value).name || '' });
});
const outcomeClass = computed(() => (props.duel.result && revealed.value ? `duel-scene--${myResult.value}` : ''));
const figureState = (side) => {
  if (!props.duel.result || !revealed.value) return 'idle';
  if (props.duel.result === 'tie') return 'draw';
  return winnerId.value === side.id ? 'victory' : 'defeat';
};

// The loot is picked by the winner when he plays at this screen
const lootChooserId = computed(() => (winnerId.value && isHumanHere(winnerId.value) ? winnerId.value : null));
const inventory = computed(() => playerOf(lootChooserId.value)?.inventory || {});
</script>

<style scoped>
.duel-scene {
  position: fixed;
  inset: 0;
  z-index: var(--z-modal);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 24px 16px 32px;
  box-sizing: border-box;
  font-family: var(--font-body);
  color: var(--text-primary);
  background-color: rgba(5, 3, 2, 0.98);
  background-image:
    radial-gradient(ellipse at 22% 50%, rgba(234, 220, 192, 0.1) 0%, rgba(0, 0, 0, 0) 35%),
    radial-gradient(ellipse at 78% 50%, rgba(var(--ember-rgb), 0.14) 0%, rgba(0, 0, 0, 0) 38%),
    radial-gradient(ellipse at 50% 100%, rgba(60, 35, 20, 0.6) 0%, rgba(0, 0, 0, 0) 60%);
  animation: duelSceneIn 0.35s ease-out;
}

.duel-scene--win {
  background-image:
    radial-gradient(ellipse at 50% 45%, rgba(var(--ember-rgb), 0.22) 0%, rgba(0, 0, 0, 0) 45%),
    radial-gradient(ellipse at 50% 100%, rgba(60, 35, 20, 0.6) 0%, rgba(0, 0, 0, 0) 60%);
}

.duel-scene--lose {
  background-image:
    radial-gradient(ellipse at 50% 45%, rgba(160, 30, 20, 0.22) 0%, rgba(0, 0, 0, 0) 45%),
    radial-gradient(ellipse at 50% 100%, rgba(40, 10, 8, 0.7) 0%, rgba(0, 0, 0, 0) 60%);
}

@keyframes duelSceneIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.duel-scene__head {
  text-align: center;
}

.duel-scene__caption {
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.32em;
  text-transform: uppercase;
  color: var(--text-accent);
}

.duel-scene__title {
  margin: 6px 0 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: clamp(30px, 5vw, 52px);
  line-height: 1;
  color: var(--text-heading);
  text-shadow: 0 0 24px rgba(var(--ember-rgb), 0.35), 0 4px 12px #000;
}

.duel-scene__stage {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(280px, 520px) minmax(0, 1fr);
  grid-template-areas: "left middle right";
  align-items: start;
  gap: 16px;
  width: min(1400px, 100%);
  margin-top: 16px;
}

.duel-side {
  /* the figure gives way on a low screen, so the dice and the totals stay in sight */
  --fighter-w: clamp(130px, min(22vw, 30vh), 300px);
  --fighter-img-h: clamp(220px, 50vh, 390px);
  --fighter-img-w: calc(var(--fighter-img-h) * 0.72);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.duel-side--left { grid-area: left; }
.duel-side--right { grid-area: right; }

.duel-side__hearts {
  display: flex;
  gap: 3px;
}

.duel-side__heart {
  color: var(--color-danger);
  fill: rgba(217, 69, 42, 0.1);
}

.duel-side__heart.filled {
  fill: var(--color-danger);
}

.duel-side--loser .duel-side__hearts {
  animation: duelHurt 0.5s ease-out;
}

.duel-side__prep {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 6px;
  min-height: 1.6em;
  margin: 0;
  font-style: italic;
  font-size: 14px;
  color: var(--text-secondary);
}

.duel-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px 2px 4px;
  border: 1px solid rgba(var(--ember-rgb), 0.5);
  background: rgba(var(--ember-rgb), 0.12);
  font-style: normal;
  font-size: 13px;
  color: var(--text-heading);
}

.duel-chip img {
  width: 20px;
  height: 20px;
  object-fit: contain;
}

.duel-chip--blood {
  border-color: rgba(217, 69, 42, 0.6);
  background: rgba(217, 69, 42, 0.14);
}

.duel-board {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  width: 100%;
}

.duel-side__roll {
  --die-size: 46px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  min-height: 132px;
}

.duel-side__dice {
  display: flex;
  gap: 14px;
  height: 64px;
  align-items: center;
}

.duel-side__total {
  font-family: var(--font-display);
  font-size: 56px;
  line-height: 1;
  color: var(--text-heading);
  opacity: 0;
  transform: scale(1.4);
  transition: opacity 0.25s, transform 0.25s;
}

.duel-side__breakdown {
  font-size: 14px;
  color: var(--text-secondary);
  opacity: 0;
  transition: opacity 0.25s;
}

.duel-side__total--shown {
  opacity: 1;
  transform: none;
}

.duel-side--winner .duel-side__total {
  color: var(--text-accent);
  text-shadow: 0 0 20px rgba(var(--ember-rgb), 0.8);
}

.duel-side--loser .duel-side__total {
  color: #e0705a;
}

.duel-side__waiting {
  margin-top: 30px;
  font-style: italic;
  color: var(--text-muted);
}

.duel-scene__middle {
  grid-area: middle;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding-top: 24px;
  text-align: center;
}

.duel-scene__vs {
  color: var(--text-accent);
  transform: rotate(45deg);
  filter: drop-shadow(0 0 10px rgba(var(--ember-rgb), 0.6));
}

.duel-scene__note {
  margin: 0;
  font-style: italic;
  font-size: 17px;
  color: var(--text-secondary);
}

.duel-prepare {
  width: 100%;
  padding: 16px 18px;
  box-sizing: border-box;
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.92) 0%, rgba(12, 7, 5, 0.92) 100%);
  box-shadow: 0 0 30px rgba(255, 100, 20, 0.2);
}

.duel-prepare__title {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 26px;
  color: var(--text-heading);
}

.duel-prepare__hint,
.duel-prepare__none {
  margin: 6px 0 12px;
  font-style: italic;
  font-size: 14px;
  color: var(--text-secondary);
}

.duel-prepare__cards {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
  text-align: left;
}

.duel-prepare__go {
  width: 100%;
  min-height: 56px;
  font-family: var(--font-display);
  font-size: 26px;
}

.duel-scene__slam {
  font-family: var(--font-display);
  font-size: clamp(48px, 7vw, 84px);
  line-height: 1;
  color: var(--text-heading);
  animation: duelSlam 0.4s cubic-bezier(0.2, 0.9, 0.3, 1.2);
}

.duel-scene--win .duel-scene__slam {
  color: var(--text-accent);
  text-shadow: 0 0 30px rgba(var(--ember-rgb), 0.85), 0 4px 12px #000;
}

.duel-scene--lose .duel-scene__slam {
  color: #e0705a;
  text-shadow: 0 0 30px rgba(217, 69, 42, 0.8), 0 4px 12px #000;
}

.duel-scene__slam--told {
  font-size: clamp(32px, 4.5vw, 52px);
}

@keyframes duelSlam {
  from { opacity: 0; transform: scale(2); }
  to { opacity: 1; transform: scale(1); }
}

@keyframes duelHurt {
  25% { transform: translateX(-5px); filter: brightness(1.8); }
  60% { transform: translateX(4px); }
}

@media (max-width: 860px) {
  .duel-scene__stage {
    grid-template-columns: 1fr 1fr;
    grid-template-areas: "left right" "middle middle";
  }

  .duel-side {
    --fighter-w: clamp(120px, 38vw, 200px);
    --fighter-img-h: 210px;
    --fighter-name: 22px;
  }

  .duel-side__roll {
    --die-size: 40px;
    min-height: 120px;
  }

  .duel-side__total {
    font-size: 48px;
  }

  .duel-scene__middle {
    padding-top: 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .duel-scene,
  .duel-scene * {
    animation: none !important;
    transition: none !important;
  }
}
</style>
