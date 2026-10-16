<template>
  <!-- The waiting room of an online table: who sits where, my hero, the invite link, the host's controls -->
  <div
    class="table-room"
    data-testid="table-room"
  >
    <div class="table-room__tools">
      <LanguageSwitch />
      <SoundControl />
    </div>

    <section
      v-if="table && closed"
      class="room-plaque room-plaque--closed"
      data-testid="table-closed"
    >
      <GameIcon
        name="table"
        :size="34"
      />
      <h2 class="room-plaque__title">
        {{ t('tables.closed') }}
      </h2>
      <button
        type="button"
        class="btn btn--ember"
        @click="router.push('/')"
      >
        <GameIcon
          name="home"
          :size="18"
        />
        {{ t('notFound.home') }}
      </button>
    </section>

    <section
      v-else-if="table"
      class="room-plaque"
    >
      <header class="room-plaque__head">
        <GameIcon
          name="table"
          :size="28"
        />
        <div>
          <h2 class="room-plaque__title">
            {{ t('tables.roomTitle') }}
          </h2>
          <span class="room-plaque__options">{{ optionsLine }}</span>
        </div>
      </header>

      <ol
        class="room-seats"
        data-testid="table-seats"
      >
        <li
          v-for="seat in seatRows"
          :key="seat.key"
          class="room-seat"
          :class="{ 'room-seat--free': !seat.player, 'room-seat--you': seat.player?.isYou }"
          data-testid="table-seat"
          :data-free="seat.player ? null : 'true'"
        >
          <span class="room-seat__figure">
            <img
              v-if="seat.player?.hero"
              :src="heroImage(seat.player.hero)"
              :alt="heroName(seat.player.hero, 'short')"
            >
            <GameIcon
              v-else
              name="person"
              :size="22"
            />
          </span>
          <span class="room-seat__who">
            <template v-if="seat.player">
              <strong class="room-seat__name">{{ seat.player.isAi ? t('tables.aiMark') : seat.player.name }}</strong>
              <span class="room-seat__hero">{{ seat.player.hero ? heroName(seat.player.hero, 'short') : (table.randomHeroes ? t('lobby.dealtAtStart') : t('tables.choosing')) }}</span>
            </template>
            <span
              v-else
              class="room-seat__free"
            >{{ t('tables.freeSeat') }}</span>
          </span>
          <span
            v-if="seat.player?.isHost || seat.player?.isYou"
            class="room-seat__marks"
          >
            {{ [seat.player.isHost && t('tables.hostMark'), seat.player.isYou && t('tables.youMark')].filter(Boolean).join(' · ') }}
          </span>
        </li>
      </ol>

      <!-- my hero (unless dealt at random) -->
      <div
        v-if="!table.randomHeroes && mySeat"
        class="room-heroes"
      >
        <span class="room-label">{{ t('tables.yourHero') }}</span>
        <div
          class="room-roster"
          role="radiogroup"
          :aria-label="t('tables.yourHero')"
        >
          <button
            v-for="hero in heroChoices"
            :key="hero.key"
            type="button"
            role="radio"
            class="room-hero"
            :class="{ 'room-hero--on': mySeat.hero === hero.key, 'room-hero--taken': hero.taken }"
            :aria-checked="mySeat.hero === hero.key"
            :disabled="hero.taken || busy"
            :title="hero.taken ? `${hero.name} — ${t('tables.heroTaken')}` : hero.name"
            :data-testid="`table-hero-${hero.key}`"
            @click="pickHero(hero.key)"
          >
            <img
              :src="hero.image"
              :alt="hero.name"
            >
            <span>{{ hero.name }}</span>
          </button>
        </div>
      </div>
      <p
        v-else-if="table.randomHeroes"
        class="room-note"
      >
        {{ t('tables.heroesDealt') }}
      </p>

      <div class="room-invite">
        <span class="room-label">
          <GameIcon
            name="link"
            :size="15"
          />
          {{ t('tables.invite') }}
        </span>
        <div class="room-invite__row">
          <input
            class="room-invite__url"
            :value="inviteUrl"
            readonly
            data-testid="table-invite-url"
            @focus="$event.target.select()"
          >
          <button
            type="button"
            class="btn btn--ghost btn--sm"
            data-testid="table-invite-copy"
            @click="copyInvite"
          >
            {{ copied ? t('tables.copied') : t('tables.copy') }}
          </button>
        </div>
      </div>

      <div class="room-actions">
        <template v-if="table.isHost">
          <button
            type="button"
            class="btn btn--ghost"
            :disabled="busy || table.taken >= table.seats"
            data-testid="table-seat-ai"
            @click="seatAi"
          >
            {{ t('tables.seatAi') }}
          </button>
          <button
            type="button"
            class="btn btn--ember"
            :disabled="busy || !!startBlocker"
            :title="startBlocker"
            data-testid="table-start"
            @click="startTable"
          >
            {{ t('tables.start') }}
          </button>
        </template>
        <span
          v-else
          class="room-wait"
          data-testid="table-waiting"
        >{{ t('tables.waitingHost', { host: table.hostName || '?' }) }}</span>
      </div>
      <span
        v-if="table.isHost && startBlocker"
        class="room-note"
      >{{ startBlocker }}</span>

      <div
        v-if="error"
        class="room-error"
        role="alert"
      >
        {{ error }}
      </div>

      <button
        type="button"
        class="btn btn--blood btn--sm room-leave"
        :disabled="busy"
        data-testid="table-leave"
        @click="leave"
      >
        {{ table.isHost ? t('tables.close') : t('tables.leave') }}
      </button>
    </section>

    <div
      v-else
      class="room-plaque room-plaque--busy"
    >
      <div class="spinner" />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import GameIcon from '@/components/ui/GameIcon.vue';
import LanguageSwitch from '@/components/ui/LanguageSwitch.vue';
import SoundControl from '@/components/ui/SoundControl.vue';
import { tablesApi } from '@/services/tables';
import { gameApi } from '@/services/api';
import { getHero } from '@/theme';
import { heroName } from '@/i18n/names';

const props = defineProps({
  gameId: { type: String, required: true },
  /** bumped by the game page on every push for this table */
  refreshKey: { type: Number, default: 0 }
});
const emit = defineEmits(['changed']);

const BASE_HEROES = ['warrior', 'thief', 'swordsman', 'warlock', 'wizard', 'oracle'];
const REGENT_HEROES = ['barbarian', 'battle_mage', 'acrobat', 'ranger'];

const { t } = useI18n();
const router = useRouter();
const table = ref(null);
const busy = ref(false);
const error = ref('');
const copied = ref(false);

const load = async () => {
  try {
    table.value = await tablesApi.get(props.gameId);
  } catch (err) {
    console.warn('[table] could not load the table:', err?.response?.data || err?.message || err);
  }
};
watch(() => props.refreshKey, load, { immediate: true });

const closed = computed(() => table.value?.status === 'finished');
const mySeat = computed(() => table.value?.players?.find((p) => p.isYou) || null);
const seatRows = computed(() => {
  const players = table.value?.players || [];
  return Array.from({ length: table.value?.seats || players.length }, (_, i) => ({ key: players[i]?.playerId || `free-${i}`, player: players[i] || null }));
});
const optionsLine = computed(() => {
  const parts = [];
  if (table.value?.regent) parts.push(table.value.lordKarak ? `${t('tables.regentMark')} · ${t('tables.lordKarakMark')}` : t('tables.regentMark'));
  if (table.value?.randomHeroes) parts.push(t('tables.randomMark'));
  if (!parts.length) parts.push(t('tables.roomOptions'));
  const limit = table.value?.turnSeconds;
  parts.push(limit ? t('tables.limitLine', { n: Math.max(1, Math.round(limit / 60)) }) : t('tables.noLimitLine'));
  return parts.join(' · ');
});
const heroImage = (key) => getHero(key).image;
const heroChoices = computed(() => {
  const taken = new Set((table.value?.players || []).filter((p) => !p.isYou && p.hero).map((p) => p.hero));
  return [...BASE_HEROES, ...(table.value?.regent ? REGENT_HEROES : [])].map((key) => ({
    key,
    name: heroName(key, 'short'),
    image: getHero(key).image,
    taken: taken.has(key)
  }));
});
const startBlocker = computed(() => {
  if (!table.value) return '';
  if (table.value.taken < 2) return t('tables.needTwo');
  if (!table.value.randomHeroes && table.value.players.some((p) => !p.hero)) return t('tables.needHeroes');
  return '';
});
const inviteUrl = computed(() => `${window.location.origin}/table/${props.gameId}`);

const act = async (what, fn) => {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await fn();
    await load();
    emit('changed');
  } catch (err) {
    const reason = err?.response?.data?.message || err?.message || '?';
    console.warn(`[table] ${what} refused:`, reason);
    error.value = t('tables.actionFailed', { reason });
    await load();
  } finally {
    busy.value = false;
  }
};
const pickHero = (hero) => act('pick hero', () => gameApi.pickHero(props.gameId, mySeat.value.playerId, hero));
const seatAi = () => act('seat the AI', () => tablesApi.seatAi(props.gameId));
const startTable = () => act('start', () => tablesApi.start(props.gameId));
const leave = () => act('leave', async () => {
  await tablesApi.leave(props.gameId);
  router.push('/');
});

const copyInvite = async () => {
  try {
    await navigator.clipboard.writeText(inviteUrl.value);
  } catch {
    // no clipboard (http, permissions): the field is selected so the link can be copied by hand
  }
  copied.value = true;
  setTimeout(() => { copied.value = false; }, 2000);
};
</script>

<style scoped>
.table-room {
  position: fixed;
  inset: 0;
  z-index: var(--z-modal);
  display: flex;
  align-items: flex-start;
  justify-content: center;
  overflow-y: auto;
  padding: 72px 16px 32px;
  font-family: var(--font-body);
  color: var(--text-primary);
  background-color: var(--bg-field);
  background-image: radial-gradient(circle at 50% 30%, rgba(var(--ember-rgb), 0.16) 0%, rgba(0, 0, 0, 0) 45%);
}

.table-room__tools {
  position: fixed;
  top: 20px;
  right: 20px;
  display: flex;
  gap: 10px;
}

.room-plaque {
  width: 100%;
  max-width: 560px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 22px 22px 18px;
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.95) 0%, rgba(12, 7, 5, 0.95) 100%);
  box-shadow: 0 0 30px rgba(var(--ember-rgb), 0.16);
}

.room-plaque--busy {
  align-items: center;
}

.room-plaque--closed {
  align-items: center;
  text-align: center;
  color: var(--text-accent);
}

.room-plaque__head {
  display: flex;
  align-items: center;
  gap: 12px;
  color: var(--text-accent);
}

.room-plaque__title {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 32px;
  line-height: 1.05;
  color: var(--text-heading);
}

.room-plaque__options {
  font-size: 14px;
  font-style: italic;
  color: var(--text-muted);
}

.room-seats {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.room-seat {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 6px 12px 6px 6px;
  border: 1px solid var(--iron-dark);
  background: rgba(12, 8, 6, 0.9);
}

.room-seat--you {
  border-color: rgba(var(--ember-rgb), 0.55);
}

.room-seat--free {
  border-style: dashed;
  background: transparent;
}

.room-seat__figure {
  width: 44px;
  height: 44px;
  flex: none;
  display: grid;
  place-items: center;
  overflow: hidden;
  border: 1px solid var(--iron);
  border-radius: 50%;
  background: #0c0806;
  color: var(--text-muted);
}

.room-seat__figure img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: top;
}

.room-seat__who {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.room-seat__name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--text-heading);
  font-weight: 600;
}

.room-seat__hero,
.room-seat__free {
  font-size: 14px;
  font-style: italic;
  color: var(--text-secondary);
}

.room-seat__free {
  color: var(--text-muted);
}

.room-seat__marks {
  display: flex;
  gap: 6px;
  font-size: 12px;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--text-accent);
}

.room-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: var(--text-muted);
}

.room-heroes,
.room-invite {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.room-roster {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(78px, 1fr));
  gap: 8px;
}

.room-hero {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 6px 4px;
  border: 1px solid var(--iron-dark);
  background: #0c0806;
  color: var(--text-secondary);
  font-family: var(--font-body);
  font-size: 13px;
  cursor: pointer;
}

.room-hero img {
  width: 52px;
  height: 52px;
  object-fit: cover;
  object-position: top;
  border-radius: 50%;
}

.room-hero--on {
  border-color: var(--color-primary);
  color: var(--text-heading);
  box-shadow: 0 0 14px rgba(var(--ember-rgb), 0.35);
}

.room-hero--taken {
  opacity: 0.35;
  cursor: not-allowed;
}

.room-hero:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 1px;
}

.room-invite__row {
  display: flex;
  gap: 8px;
}

.room-invite__url {
  flex: 1;
  min-width: 0;
  padding: 8px 10px;
  border: 1px solid var(--iron);
  background: #0c0806;
  color: var(--text-secondary);
  font-family: var(--font-body);
  font-size: 14px;
}

.room-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.room-actions .btn {
  flex: 1;
  min-height: 46px;
}

.room-wait {
  font-style: italic;
  color: var(--text-secondary);
}

.room-note {
  margin: 0;
  font-size: 13px;
  font-style: italic;
  color: var(--text-muted);
}

.room-error {
  padding: 8px 12px;
  border: 1px solid rgba(217, 69, 42, 0.6);
  background: rgba(217, 69, 42, 0.12);
  color: #f0b5a8;
}

.room-leave {
  align-self: flex-start;
}

.spinner {
  width: 26px;
  height: 26px;
  border: 3px solid rgba(var(--ember-rgb), 0.2);
  border-top-color: var(--color-primary);
  border-radius: 50%;
  animation: spin 0.9s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

@media (prefers-reduced-motion: reduce) {
  .spinner {
    animation: none;
  }
}

@media (max-width: 480px) {
  .room-plaque {
    padding: 16px 14px;
  }

  .room-plaque__title {
    font-size: 26px;
  }
}
</style>
