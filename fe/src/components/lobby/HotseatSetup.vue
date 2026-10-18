<template>
  <!-- Hot-seat: several players at this screen (2–4 seats, each a human with a name and a hero, or the AI) -->
  <section
    class="hotseat-setup"
    data-testid="hotseat-setup"
  >
    <span class="hotseat-setup__title">{{ t('hotseat.seatsTitle') }}</span>
    <ol class="hotseat-seats">
      <li
        v-for="(seat, index) in seats"
        :key="seat.key"
        class="hotseat-seat"
        :class="`hotseat-seat--${seat.kind}`"
        :data-testid="`hotseat-seat-${index}`"
      >
        <span class="hotseat-seat__numeral">{{ index + 1 }}</span>
        <span class="hotseat-seat__figure">
          <img
            v-if="seat.kind === 'human' && seat.hero && !randomHeroes"
            :src="cardOf(seat.hero)?.image"
            :alt="cardOf(seat.hero)?.shortName"
          >
          <GameIcon
            v-else
            :name="seat.kind === 'ai' ? 'dice' : 'person'"
            :size="22"
          />
        </span>
        <div class="hotseat-seat__body">
          <!-- the first human seat is this account; the others are guests named here -->
          <span
            v-if="index === firstHumanIndex"
            class="hotseat-seat__name hotseat-seat__name--account"
          >{{ accountName }} <em>{{ t('hotseat.you') }}</em></span>
          <input
            v-else-if="seat.kind === 'human'"
            v-model="seat.name"
            class="hotseat-seat__input"
            maxlength="24"
            :placeholder="t('hotseat.namePlaceholder', { n: index + 1 })"
            :data-testid="`hotseat-name-${index}`"
          >
          <span
            v-else
            class="hotseat-seat__name"
          >{{ t('hotseat.ai') }}</span>
          <!-- a hero per human seat when heroes are not dealt; a hero taken by another seat is not offered -->
          <select
            v-if="seat.kind === 'human' && !randomHeroes"
            v-model="seat.hero"
            class="hotseat-seat__hero"
            :data-testid="`hotseat-hero-${index}`"
            :aria-label="t('lobby.chooseHero')"
          >
            <option :value="null">
              {{ t('lobby.noHero') }}
            </option>
            <option
              v-for="card in heroChoices"
              :key="card.key"
              :value="card.key"
              :disabled="takenBy(card.key, index)"
            >
              {{ card.shortName }}
            </option>
          </select>
        </div>
        <div class="hotseat-seat__actions">
          <button
            type="button"
            class="btn btn--ghost btn--sm"
            :disabled="!canToggle(index)"
            :data-testid="`hotseat-kind-${index}`"
            @click="toggleKind(index)"
          >
            {{ seat.kind === 'human' ? t('hotseat.makeAi') : t('hotseat.makeHuman') }}
          </button>
          <button
            type="button"
            class="btn btn--ghost btn--sm hotseat-seat__remove"
            :disabled="seats.length <= MIN_SEATS"
            :aria-label="t('hotseat.remove')"
            :title="t('hotseat.remove')"
            :data-testid="`hotseat-remove-${index}`"
            @click="removeSeat(index)"
          >
            <GameIcon
              name="close"
              :size="14"
            />
          </button>
        </div>
      </li>
    </ol>
    <div class="hotseat-setup__add">
      <button
        type="button"
        class="btn btn--ghost btn--sm"
        :disabled="seats.length >= MAX_SEATS"
        data-testid="hotseat-add-human"
        @click="addSeat('human')"
      >
        {{ t('hotseat.addHuman') }}
      </button>
      <button
        type="button"
        class="btn btn--ghost btn--sm"
        :disabled="seats.length >= MAX_SEATS"
        data-testid="hotseat-add-ai"
        @click="addSeat('ai')"
      >
        {{ t('hotseat.addAi') }}
      </button>
    </div>
    <p
      v-if="errorText"
      class="hotseat-setup__error"
      role="alert"
    >
      {{ errorText }}
    </p>
    <button
      type="button"
      class="primary-button btn btn--ember btn--lg"
      :disabled="disabled || creating || !valid"
      data-testid="hotseat-start"
      @click="start"
    >
      {{ creating ? t('lobby.creating') : t('hotseat.start') }}
    </button>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import GameIcon from '@/components/ui/GameIcon.vue';
import { gameApi } from '@/services/api';

const props = defineProps({
  regent: { type: Boolean, default: false },
  lordKarak: { type: Boolean, default: false },
  randomHeroes: { type: Boolean, default: false },
  /** the lobby's hero cards ({ key, image, shortName }), Regent heroes only with the switch */
  heroCards: { type: Array, default: () => [] },
  /** this account's name: the first human seat */
  accountName: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
});

const { t } = useI18n();
const router = useRouter();
const MIN_SEATS = 2;
const MAX_SEATS = 4;

let nextKey = 0;
const seatOf = (kind) => ({ key: nextKey++, kind, name: '', hero: null });
const seats = ref([seatOf('human'), seatOf('human')]);
const creating = ref(false);
const errorText = ref('');

const heroChoices = computed(() => props.heroCards.filter((card) => card.key));
const cardOf = (key) => heroChoices.value.find((card) => card.key === key);
const firstHumanIndex = computed(() => seats.value.findIndex((seat) => seat.kind === 'human'));
const takenBy = (hero, index) => seats.value.some((seat, other) => other !== index && seat.kind === 'human' && seat.hero === hero);
const humans = computed(() => seats.value.filter((seat) => seat.kind === 'human').length);
const valid = computed(() => seats.value.length >= MIN_SEATS && seats.value.length <= MAX_SEATS && humans.value >= 1);
// the last human seat stays human: a hot-seat game needs someone at the screen
const canToggle = (index) => seats.value[index].kind === 'ai' || humans.value > 1;

// a Regent hero leaves the seats when the switch goes off (the backend refuses it in a base game)
watch(heroChoices, (choices) => {
  for (const seat of seats.value) {
    if (seat.hero && !choices.some((card) => card.key === seat.hero)) seat.hero = null;
  }
});

const addSeat = (kind) => {
  if (seats.value.length < MAX_SEATS) seats.value.push(seatOf(kind));
};
const removeSeat = (index) => {
  if (seats.value.length <= MIN_SEATS) return;
  const removed = seats.value[index];
  if (removed.kind === 'human' && humans.value <= 1) return;
  seats.value.splice(index, 1);
};
const toggleKind = (index) => {
  if (!canToggle(index)) return;
  const seat = seats.value[index];
  seat.kind = seat.kind === 'human' ? 'ai' : 'human';
  seat.hero = null;
};

const uuid = () => (crypto?.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
  const r = (Math.random() * 16) | 0;
  return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
}));

const start = async () => {
  if (creating.value || !valid.value) return;
  creating.value = true;
  errorText.value = '';
  try {
    console.info('[hotseat] creating', { seats: seats.value.map((seat) => ({ kind: seat.kind, hero: seat.hero })) });
    const game = await gameApi.createGame(null, {
      regent: props.regent,
      lordKarak: props.regent && props.lordKarak,
      randomHeroes: props.randomHeroes,
      hotseat: true,
    });
    const gameId = game.gameId;
    const ids = [];
    let firstHuman = null;
    let firstAi = null;
    for (const seat of seats.value) {
      const playerId = uuid();
      ids.push(playerId);
      if (seat.kind === 'ai') {
        await gameApi.joinGame(gameId, playerId, 'AI Player', true);
        firstAi = firstAi || playerId;
      } else {
        await gameApi.joinGame(gameId, playerId, seat.name.trim() || null, false, props.randomHeroes ? null : seat.hero);
        firstHuman = firstHuman || playerId;
      }
    }
    // the screen starts with the first human seat; it follows the seats in the game (followHotseatSeat)
    localStorage.setItem('currentPlayerId', firstHuman);
    localStorage.setItem('humanPlayerId', firstHuman);
    localStorage.setItem(`humanPlayer:${gameId}`, firstHuman);
    if (firstAi) {
      localStorage.setItem('virtualPlayerId', firstAi);
    } else {
      localStorage.removeItem('virtualPlayerId');
    }
    for (const playerId of ids) {
      await gameApi.playerReady(gameId, playerId);
    }
    await gameApi.startGame(gameId);
    console.info('[hotseat] started', { gameId, seats: ids.length });
    router.push(`/game/${gameId}`);
  } catch (err) {
    console.error('[hotseat] could not start the game:', err);
    errorText.value = t('lobby.createFailed');
  } finally {
    creating.value = false;
  }
};
</script>

<style scoped>
.hotseat-setup {
  display: flex;
  flex-direction: column;
  gap: 10px;
  /* in a lobby that fits the screen the seats take what is left and scroll inside */
  flex: 0 1 auto;
  min-height: 0;
}

.hotseat-setup__title {
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: var(--text-muted);
}

.hotseat-seats {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
  overflow-y: auto;
  min-height: 0;
}

.hotseat-seat {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 8px;
  border: 1px solid rgba(var(--ember-rgb), 0.35);
  background: var(--bg-panel);
}

.hotseat-seat--ai {
  border-style: dashed;
  opacity: 0.9;
}

.hotseat-seat__numeral {
  width: 18px;
  flex: none;
  text-align: right;
  font-family: var(--font-display);
  font-size: 20px;
  color: #6d5b44;
}

.hotseat-seat__figure {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  flex: none;
  overflow: hidden;
  border-radius: 50%;
  background: radial-gradient(circle at 50% 35%, #4a3020 0%, #140c07 75%);
  box-shadow: 0 0 0 2px var(--iron-edge);
  color: var(--text-muted);
}

.hotseat-seat__figure img {
  width: 100%;
  height: 150%;
  object-fit: cover;
  object-position: top;
  align-self: start;
}

.hotseat-seat__body {
  display: flex;
  flex: 1 1 auto;
  min-width: 0;
  align-items: center;
  gap: 8px;
}

.hotseat-seat__name {
  flex: 1 1 0;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 600;
  color: var(--text-primary);
}

.hotseat-seat__name em {
  margin-left: 4px;
  font-weight: 400;
  color: var(--text-muted);
}

.hotseat-seat__input,
.hotseat-seat__hero {
  min-width: 0;
  flex: 1 1 0;
  padding: 4px 6px;
  border: 1px solid var(--iron-edge);
  background: #120b07;
  color: var(--text-primary);
  font: inherit;
}

.hotseat-seat__actions {
  display: flex;
  flex: none;
  gap: 6px;
}

.hotseat-setup__add {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.hotseat-setup__error {
  margin: 0;
  color: var(--color-danger);
}

@media (max-width: 600px) {
  .hotseat-seat {
    flex-wrap: wrap;
  }

  .hotseat-seat__body {
    flex: 1 1 calc(100% - 70px);
  }

  .hotseat-seat__actions {
    width: 100%;
    justify-content: flex-end;
  }
}
</style>
