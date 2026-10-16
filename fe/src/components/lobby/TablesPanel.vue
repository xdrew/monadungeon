<template>
  <!-- Online play: open a table for friends, the open tables to join (live from the server) -->
  <section
    class="tables-panel"
    data-testid="tables-panel"
  >
    <button
      v-if="!formOpen"
      type="button"
      class="btn btn--ghost tables-panel__open"
      :disabled="disabled"
      data-testid="open-table"
      @click="formOpen = true"
    >
      <GameIcon
        name="table"
        :size="18"
      />
      {{ t('tables.open') }}
    </button>

    <form
      v-else
      class="table-form"
      data-testid="table-form"
      @submit.prevent="createTable"
    >
      <span class="table-form__title">{{ t('tables.formTitle') }}</span>
      <div
        class="table-form__seats"
        role="radiogroup"
        :aria-label="t('tables.seats')"
      >
        <span class="table-form__label">{{ t('tables.seats') }}</span>
        <button
          v-for="n in SEAT_CHOICES"
          :key="n"
          type="button"
          role="radio"
          class="seat-choice"
          :class="{ 'seat-choice--on': seats === n }"
          :aria-checked="seats === n"
          :data-testid="`table-seats-${n}`"
          @click="seats = n"
        >
          {{ n }}
        </button>
      </div>
      <div
        class="table-form__seats"
        role="radiogroup"
        :aria-label="t('tables.turnLimit')"
      >
        <span class="table-form__label">{{ t('tables.turnLimit') }}</span>
        <button
          v-for="limit in TURN_LIMITS"
          :key="String(limit)"
          type="button"
          role="radio"
          class="seat-choice seat-choice--wide"
          :class="{ 'seat-choice--on': turnSeconds === limit }"
          :aria-checked="turnSeconds === limit"
          :data-testid="`table-limit-${limit ?? 'none'}`"
          @click="turnSeconds = limit"
        >
          {{ limit ? t(`tables.limit${limit}`) : t('tables.limitNone') }}
        </button>
      </div>
      <span class="table-form__hint">{{ t('tables.formHint') }}</span>
      <div class="table-form__actions">
        <button
          type="submit"
          class="btn btn--ember"
          :disabled="busy"
          data-testid="table-create"
        >
          {{ t('tables.create') }}
        </button>
        <button
          type="button"
          class="btn btn--ghost"
          @click="formOpen = false"
        >
          {{ t('common.cancel') }}
        </button>
      </div>
    </form>

    <div
      class="open-tables"
      data-testid="open-tables"
    >
      <span class="open-tables__title">{{ t('tables.listTitle') }}</span>
      <p
        v-if="!tables.length"
        class="open-tables__none"
      >
        {{ t('tables.none') }}
      </p>
      <ul
        v-else
        class="open-tables__list"
      >
        <li
          v-for="table in tables"
          :key="table.gameId"
          class="open-table"
          :class="{ 'open-table--mine': table.isMine }"
          data-testid="open-table-row"
          :data-game-id="table.gameId"
        >
          <span class="open-table__host">{{ t('tables.hostTable', { name: table.hostName || '?' }) }}</span>
          <span class="open-table__marks">
            <span
              v-if="table.regent"
              class="open-table__mark"
            >{{ table.lordKarak ? t('tables.lordKarakMark') : t('tables.regentMark') }}</span>
            <span
              v-if="table.randomHeroes"
              class="open-table__mark"
            >{{ t('tables.randomMark') }}</span>
          </span>
          <span
            class="open-table__seats"
            :title="t('tables.seats')"
          >
            <GameIcon
              name="person"
              :size="14"
            />
            {{ t('tables.taken', { taken: table.taken, seats: table.seats }) }}
          </span>
          <button
            type="button"
            class="btn btn--sm"
            :class="table.isMine ? 'btn--ghost' : 'btn--ember'"
            :disabled="disabled"
            data-testid="open-table-join"
            @click="joinTable(table)"
          >
            {{ table.isMine ? t('tables.rejoin') : t('tables.join') }}
          </button>
        </li>
      </ul>
    </div>

    <div
      v-if="error"
      class="tables-panel__error"
      role="alert"
    >
      {{ error }}
    </div>
  </section>
</template>

<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import GameIcon from '@/components/ui/GameIcon.vue';
import { tablesApi, TURN_LIMITS, DEFAULT_TURN_SECONDS } from '@/services/tables';
import { subscribe, topics } from '@/services/push';

const props = defineProps({
  /** No account yet: the list waits and nothing can be opened or joined */
  disabled: { type: Boolean, default: false },
  regent: { type: Boolean, default: false },
  lordKarak: { type: Boolean, default: false },
  randomHeroes: { type: Boolean, default: false }
});

const SEAT_CHOICES = [2, 3, 4, 5];
const { t } = useI18n();
const router = useRouter();
const formOpen = ref(false);
const seats = ref(4);
const turnSeconds = ref(DEFAULT_TURN_SECONDS);
const busy = ref(false);
const error = ref('');
const tables = ref([]);

const loadTables = async () => {
  try {
    tables.value = await tablesApi.list();
  } catch (err) {
    console.warn('[tables] could not list the open tables:', err?.message || err);
  }
};

// The list follows the server: any table change is pushed on karak/tables (a short debounce for bursts)
let unsubscribe = null;
let reloadTimer = null;
const listen = () => {
  unsubscribe = subscribe(topics.tables, () => {
    clearTimeout(reloadTimer);
    reloadTimer = setTimeout(loadTables, 250);
  }, { onState: (state) => { if (state === 'reopened') loadTables(); } });
};
watch(() => props.disabled, (disabled) => {
  if (disabled || unsubscribe) return;
  loadTables();
  listen();
}, { immediate: true });
onBeforeUnmount(() => {
  unsubscribe?.();
  clearTimeout(reloadTimer);
});

const createTable = async () => {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    const { gameId } = await tablesApi.create({
      seats: seats.value,
      regent: props.regent,
      lordKarak: props.regent && props.lordKarak,
      randomHeroes: props.randomHeroes,
      turnSeconds: turnSeconds.value
    });
    console.info('[tables] opened a table', gameId);
    router.push(`/game/${gameId}`);
  } catch (err) {
    console.error('[tables] could not open a table:', err?.response?.data || err);
    error.value = t('tables.createFailed');
  } finally {
    busy.value = false;
  }
};

// Joining goes through the invite route, which takes the seat (or finds the one already held)
const joinTable = (table) => router.push(table.isMine ? `/game/${table.gameId}` : `/table/${table.gameId}`);
</script>

<style scoped>
.tables-panel {
  display: flex;
  flex-direction: column;
  gap: 12px;
  /* in a lobby that fits the screen the panel takes what is left and the list scrolls inside */
  flex: 0 1 auto;
  min-height: 0;
}

.tables-panel__open {
  min-height: 48px;
  gap: 8px;
  font-size: 16px;
}

.table-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px 16px;
  border: 1px solid rgba(var(--ember-rgb), 0.4);
  background: var(--bg-panel);
}

.table-form__title,
.open-tables__title {
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: var(--text-accent);
}

.table-form__seats {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.table-form__label {
  margin-right: 6px;
  color: var(--text-secondary);
}

.seat-choice {
  width: 40px;
  height: 40px;
  border: 1px solid var(--iron);
  background: #0c0806;
  color: var(--text-secondary);
  font-family: var(--font-display);
  font-size: 20px;
  cursor: pointer;
}

.seat-choice--wide {
  width: auto;
  padding: 0 10px;
  font-family: var(--font-body);
  font-size: 14px;
}

.seat-choice--on {
  border-color: var(--color-primary);
  color: var(--text-heading);
  box-shadow: 0 0 12px rgba(var(--ember-rgb), 0.35);
}

.seat-choice:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 1px;
}

.table-form__hint,
.open-tables__none {
  margin: 0;
  font-size: 13px;
  font-style: italic;
  color: var(--text-muted);
}

.table-form__actions {
  display: flex;
  gap: 10px;
}

.open-tables {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 0;
}

.open-tables__list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-height: 240px;
  min-height: 46px;
  overflow-y: auto;
}

.open-table {
  display: grid;
  grid-template-columns: 1fr auto auto;
  grid-template-areas: "host seats join" "marks seats join";
  align-items: center;
  column-gap: 12px;
  padding: 8px 10px 8px 14px;
  border: 1px solid var(--iron-dark);
  background: rgba(12, 8, 6, 0.88);
}

.open-table--mine {
  border-color: rgba(var(--ember-rgb), 0.45);
}

.open-table__host {
  grid-area: host;
  color: var(--text-heading);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.open-table__marks {
  grid-area: marks;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.open-table__mark {
  font-size: 12px;
  color: var(--text-muted);
}

.open-table__seats {
  grid-area: seats;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: var(--text-secondary);
  font-variant-numeric: tabular-nums;
}

.open-table .btn {
  grid-area: join;
}

.tables-panel__error {
  padding: 8px 12px;
  border: 1px solid rgba(217, 69, 42, 0.6);
  background: rgba(217, 69, 42, 0.12);
  color: #f0b5a8;
}
</style>
