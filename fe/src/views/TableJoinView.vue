<template>
  <!-- An invite link: a name first if there is none, then a seat at the table and on into its waiting room -->
  <div
    class="table-join"
    data-testid="table-join"
  >
    <div class="page-tools">
      <LanguageSwitch />
      <SoundControl />
    </div>

    <form
      v-if="phase === 'name'"
      class="join-plaque name-card"
      data-testid="name-card"
      @submit.prevent="saveName"
    >
      <GameIcon
        name="table"
        :size="34"
      />
      <label
        class="join-plaque__title"
        for="join-name-input"
      >{{ t('account.askName') }}</label>
      <div class="join-plaque__row">
        <input
          id="join-name-input"
          v-model="nameInput"
          class="name-input"
          maxlength="24"
          data-testid="name-card-input"
        >
        <button
          type="submit"
          class="btn btn--ember"
          :disabled="busy"
          data-testid="name-card-save"
        >
          {{ t('account.enter') }}
        </button>
      </div>
      <span class="join-plaque__hint">{{ nameErrorText || t('account.hint') }}</span>
      <a
        v-if="google.available"
        class="join-plaque__google"
        :href="accountApi.googleStartUrl(`/table/${tableId}`, google)"
        data-testid="name-card-google"
      >
        <GameIcon
          name="google"
          :size="15"
        />
        {{ t('account.signInGoogle') }}
      </a>
    </form>

    <div
      v-else-if="phase === 'refused'"
      class="join-plaque"
      data-testid="table-refused"
      :data-reason="reason"
    >
      <GameIcon
        name="table"
        :size="34"
      />
      <h2 class="join-plaque__title">
        {{ refusalText }}
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
    </div>

    <div
      v-else
      class="join-plaque join-plaque--busy"
    >
      <div class="spinner" />
      <span>{{ t('tables.joining') }}</span>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import GameIcon from '@/components/ui/GameIcon.vue';
import LanguageSwitch from '@/components/ui/LanguageSwitch.vue';
import SoundControl from '@/components/ui/SoundControl.vue';
import { useMusicSituation } from '@/composables/useMusicSituation';
import { accountApi, nameError } from '@/services/account';
import { tablesApi, tableRefusal } from '@/services/tables';

// main.js renders the views itself (no props): the table comes from the route
const route = useRoute();
const tableId = computed(() => String(route.params.id || ''));
const { t } = useI18n();
const router = useRouter();
useMusicSituation('lobby');

const phase = ref('loading'); // loading | name | joining | refused
const reason = ref('');
const busy = ref(false);
const nameInput = ref(accountApi.suggestName());
const nameErrorText = ref('');
const google = ref({ available: false, fake: false });
accountApi.googleStatus().then((status) => { google.value = status; });

const REFUSALS = {
  'table.full': 'tables.full',
  'table.started': 'tables.started',
  'table.closed': 'tables.closed',
  'table.not_found': 'tables.notFound'
};
const refusalText = computed(() => t(REFUSALS[reason.value] || 'tables.joinFailed'));
const refuse = (code) => {
  console.warn('[table] the invite is refused:', code);
  reason.value = code;
  phase.value = 'refused';
};

const enter = async () => {
  phase.value = 'joining';
  try {
    const table = await tablesApi.get(tableId.value);
    if (table.mySeat) {
      router.replace(`/game/${tableId.value}`);
      return;
    }
    if (table.status === 'finished') return refuse(table.endReason === 'abandoned' ? 'table.closed' : 'table.started');
    if (table.status !== 'lobby') return refuse('table.started');
    if (table.taken >= table.seats) return refuse('table.full');
    await tablesApi.join(tableId.value);
    console.info('[table] took a seat at', tableId.value);
    router.replace(`/game/${tableId.value}`);
  } catch (err) {
    refuse(tableRefusal(err) || 'table.error');
  }
};

const saveName = async () => {
  if (busy.value) return;
  busy.value = true;
  nameErrorText.value = '';
  try {
    await accountApi.createGuest(nameInput.value);
    await enter();
  } catch (err) {
    nameErrorText.value = nameError(err) ? t('account.badName') : t('account.failed');
  } finally {
    busy.value = false;
  }
};

onMounted(async () => {
  try {
    const account = await accountApi.me();
    if (!account) {
      phase.value = 'name';
      return;
    }
  } catch (err) {
    console.warn('[table] could not load the account:', err?.message || err);
    phase.value = 'name';
    return;
  }
  await enter();
});
</script>

<style scoped>
.page-tools {
  position: fixed;
  top: 20px;
  right: 20px;
  z-index: 3;
  display: flex;
  gap: 10px;
}

.table-join {
  min-height: 100vh;
  min-height: 100dvh;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 72px 16px 24px;
  font-family: var(--font-body);
  color: var(--text-primary);
  background-color: var(--bg-field);
  background-image: radial-gradient(circle at 50% 40%, rgba(var(--ember-rgb), 0.16) 0%, rgba(0, 0, 0, 0) 45%);
}

.join-plaque {
  width: 100%;
  max-width: 440px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
  padding: 24px 22px;
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.94) 0%, rgba(12, 7, 5, 0.94) 100%);
  box-shadow: 0 0 26px rgba(var(--ember-rgb), 0.18);
  text-align: center;
  color: var(--text-accent);
}

.join-plaque--busy {
  flex-direction: row;
  justify-content: center;
  color: var(--text-secondary);
}

.join-plaque__title {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 28px;
  line-height: 1.15;
  color: var(--text-heading);
}

.join-plaque__row {
  display: flex;
  gap: 10px;
  width: 100%;
}

.join-plaque__hint {
  font-size: 13px;
  font-style: italic;
  color: var(--text-muted);
}

.join-plaque__google {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--text-accent);
  font-size: 14px;
  text-decoration: underline;
  text-underline-offset: 3px;
}

.name-input {
  flex: 1;
  min-width: 0;
  padding: 10px 12px;
  border: 1px solid var(--iron);
  border-radius: 2px;
  background: #0c0806;
  color: var(--text-heading);
  font-family: var(--font-body);
  font-size: 18px;
}

.name-input:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 1px;
}

.join-plaque .btn {
  gap: 8px;
}

.spinner {
  width: 24px;
  height: 24px;
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
</style>
