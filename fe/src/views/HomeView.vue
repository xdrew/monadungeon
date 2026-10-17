<template>
  <!-- The lobby: the dungeon full-bleed, the title on the left, preparing the descent on the right -->
  <div
    class="home-container"
    :style="lobbyStyle"
  >
    <div
      class="lobby-shade"
      aria-hidden="true"
    />

    <!-- Corner tools: language and music -->
    <div class="lobby-tools">
      <LanguageSwitch />
      <SoundControl />
    </div>

    <header class="lobby-title">
      <ThemeLogo />
      <h1 class="title">
        {{ t('common.appName') }}
      </h1>
      <p class="description">
        {{ t('lobby.description') }}
      </p>
    </header>

    <main class="game-panel">
      <!-- Who plays: a name once (a guest account behind a cookie), then "You: Name" with a pen -->
      <div
        v-if="account"
        class="account-line"
        data-testid="account-line"
      >
        <template v-if="!renaming">
          <span class="account-line__label">{{ t('account.you') }}</span>
          <strong class="account-line__name">{{ account.name }}</strong>
          <button
            type="button"
            class="account-line__pen"
            :title="t('account.rename')"
            :aria-label="t('account.rename')"
            data-testid="account-rename"
            @click="startRename"
          >
            <GameIcon
              name="pen"
              :size="15"
            />
          </button>
          <!-- kept with Google: on any device; a guest may keep the account that way -->
          <span
            v-if="account.google"
            class="account-line__google"
            data-testid="account-google"
          >
            <GameIcon
              name="google"
              :size="14"
            />
            {{ account.google.email ? t('account.keptWith', { email: account.google.email }) : t('account.keptWithShort') }}
            <button
              type="button"
              class="account-line__link"
              data-testid="account-sign-out"
              @click="signOut"
            >{{ t('account.signOut') }}</button>
          </span>
          <a
            v-else-if="google.available"
            class="account-line__link account-line__keep"
            :href="googleUrl"
            :title="t('account.keepGoogleHint')"
            data-testid="account-keep-google"
          >
            <GameIcon
              name="google"
              :size="14"
            />
            {{ t('account.keepGoogle') }}
          </a>
        </template>
        <form
          v-else
          class="account-line__form"
          @submit.prevent="saveRename"
        >
          <input
            v-model="nameInput"
            class="name-input"
            maxlength="24"
            data-testid="account-name-input"
            :aria-label="t('account.askName')"
          >
          <button
            type="submit"
            class="btn btn--ember btn--sm"
            data-testid="account-save"
          >
            {{ t('account.save') }}
          </button>
          <button
            type="button"
            class="btn btn--ghost btn--sm"
            @click="renaming = false"
          >
            {{ t('common.cancel') }}
          </button>
        </form>
      </div>
      <!-- Two ways down, one tab each, so the panel fits the screen: against the AI, or at a table online -->
      <div
        class="lobby-tabs lobby-caption"
        role="tablist"
        :aria-label="t('lobby.prepare')"
      >
        <button
          v-for="name in LOBBY_TABS"
          :key="name"
          type="button"
          role="tab"
          class="lobby-tab"
          :class="{ 'lobby-tab--active': tab === name }"
          :aria-selected="tab === name"
          :data-testid="`lobby-tab-${name}`"
          @click="tab = name"
        >
          {{ t(`lobby.tabs.${name}`) }}
        </button>
      </div>

      <!-- Hero selection (hero skills from the Karak board game) -->
      <div
        v-if="tab === 'ai'"
        class="hero-picker"
        data-testid="hero-picker"
      >
        <!-- Random heroes (the board game rule): you and the AI are dealt different heroes when the game is created -->
        <div
          v-if="randomHeroes"
          class="hero-card hero-card-random"
          data-testid="hero-card-random"
        >
          <span class="hero-medallion">
            <GameIcon
              name="dice"
              :size="40"
            />
          </span>
          <div class="hero-card-body">
            <h3 class="hero-card-name">
              {{ t('lobby.heroAwaits') }}
            </h3>
            <p class="hero-skills-none">
              {{ regentExpansion ? t('lobby.eachGetsHeroRegent') : t('lobby.eachGetsHero') }}
            </p>
          </div>
        </div>

        <template v-else>
          <p class="hero-picker-title">
            {{ t('lobby.chooseHero') }}
          </p>
          <!-- roster: one medallion per hero; the skills live in the card below -->
          <div
            class="hero-roster"
            :aria-label="t('lobby.heroGroup')"
            role="radiogroup"
          >
            <button
              v-for="card in heroCards"
              :key="card.testid"
              type="button"
              class="hero-tile"
              :class="{ selected: selectedHero === card.key }"
              role="radio"
              :aria-checked="selectedHero === card.key"
              :data-testid="card.testid"
              :title="card.name"
              @click="selectHero(card.key)"
            >
              <span class="hero-tile-figure">
                <img
                  :src="card.image"
                  :alt="card.name"
                  class="hero-tile-image"
                >
              </span>
              <span class="hero-tile-name">{{ card.shortName }}</span>
            </button>
          </div>

          <!-- the selected hero's card, laid out like a rulebook entry -->
          <transition
            name="hero-card"
            mode="out-in"
          >
            <div
              :key="selectedCard.testid"
              class="hero-card"
              data-testid="hero-card"
            >
              <span class="hero-medallion hero-medallion--portrait">
                <img
                  :src="selectedCard.image"
                  :alt="selectedCard.name"
                  class="hero-card-image"
                >
              </span>
              <div class="hero-card-body">
                <span class="hero-card-role">{{ selectedCard.role }}</span>
                <h3 class="hero-card-name">
                  {{ selectedCard.shortName }}
                </h3>
                <ul
                  v-if="selectedCard.skills.length"
                  class="hero-skills"
                >
                  <li
                    v-for="skill in selectedCard.skills"
                    :key="skill.key"
                    class="hero-skill"
                  >
                    <span class="hero-skill-name">{{ skill.name }}</span>
                    <span class="hero-skill-text">{{ skill.text }}</span>
                  </li>
                </ul>
                <p
                  v-else
                  class="hero-skills-none"
                >
                  {{ t('lobby.noSkills') }}
                </p>
              </div>
            </div>
          </transition>
        </template>
      </div>

      <!-- The options, one row each (a game against the AI and a table alike) -->
      <div class="lobby-options">
        <label
          class="regent-toggle random-heroes-toggle"
          data-testid="random-heroes-toggle"
        >
          <input
            v-model="randomHeroes"
            type="checkbox"
            data-testid="random-heroes-toggle-input"
          >
          <span class="regent-toggle-text">
            <strong>{{ t('lobby.randomHeroes') }}</strong>
            <span>{{ t('lobby.randomHeroesHint') }}</span>
          </span>
        </label>
        <!-- Karak: Regent expansion: mixes the Lord Karak rooms and the new monsters into the game -->
        <label
          class="regent-toggle"
          data-testid="regent-toggle"
        >
          <input
            v-model="regentExpansion"
            type="checkbox"
            data-testid="regent-toggle-input"
          >
          <span class="regent-toggle-text">
            <strong>{{ t('lobby.regent') }}</strong>
            <span>{{ t('lobby.regentHint') }}</span>
          </span>
        </label>
        <!-- Lord Karak variant (needs Regent): the weakest hero becomes Lord Karak at the fifth Lord Karak room -->
        <label
          class="regent-toggle lord-karak-toggle"
          :class="{ 'regent-toggle--off': !regentExpansion }"
          data-testid="lord-karak-toggle"
        >
          <input
            v-model="lordKarakVariant"
            type="checkbox"
            :disabled="!regentExpansion"
            data-testid="lord-karak-toggle-input"
          >
          <span class="regent-toggle-text">
            <strong>{{ t('lobby.lordKarak') }}</strong>
            <span>{{ regentExpansion ? t('lobby.lordKarakHint') : t('lobby.lordKarakNeedsRegent') }}</span>
          </span>
        </label>
      </div>

      <div
        v-if="accountNotice"
        class="account-notice"
        role="status"
        data-testid="account-notice"
      >
        {{ accountNotice }}
      </div>
      <form
        v-if="accountChecked && !account"
        class="name-card"
        data-testid="name-card"
        @submit.prevent="saveName"
      >
        <label
          class="name-card__ask"
          for="name-card-input"
        >{{ t('account.askName') }}</label>
        <div class="name-card__row">
          <input
            id="name-card-input"
            v-model="nameInput"
            class="name-input"
            maxlength="24"
            data-testid="name-card-input"
          >
          <button
            type="submit"
            class="btn btn--ember"
            :disabled="savingName"
            data-testid="name-card-save"
          >
            {{ t('account.enter') }}
          </button>
        </div>
        <span class="name-card__hint">{{ nameErrorText || t('account.hint') }}</span>
        <a
          v-if="google.available"
          class="name-card__google"
          :href="googleUrl"
          data-testid="name-card-google"
        >
          <GameIcon
            name="google"
            :size="15"
          />
          {{ t('account.signInGoogle') }}
        </a>
      </form>
      <button
        v-else-if="tab === 'ai'"
        type="button"
        class="primary-button btn btn--ember btn--lg"
        :disabled="loading || !account"
        @click="createNewGame"
      >
        {{ t('lobby.playAi') }}
      </button>

      <!-- Online play: a table for friends, the open tables -->
      <TablesPanel
        v-if="tab === 'online'"
        :disabled="!account"
        :regent="regentExpansion"
        :lord-karak="lordKarakVariant"
        :random-heroes="randomHeroes"
      />

      <div class="lobby-links">
        <button
          type="button"
          class="secondary-button rules-button btn btn--ghost"
          @click="viewRules"
        >
          <GameIcon
            name="journal"
            :size="18"
          />
          {{ t('lobby.rules') }}
        </button>
        <button
          type="button"
          class="secondary-button leaderboard-button btn btn--ghost"
          @click="viewLeaderboard"
        >
          <GameIcon
            name="trophy"
            :size="18"
          />
          {{ t('lobby.leaderboard') }}
        </button>
      </div>

      <div
        v-if="errorMessage"
        class="error-message"
        role="alert"
      >
        {{ errorMessage }}
      </div>
      <div
        v-if="loading"
        class="loading-indicator"
      >
        <div class="spinner">
          <div class="spinner-inner" />
        </div>
        <div class="loading-text">
          {{ loadingMessage }}
        </div>
      </div>
      <div
        v-if="isDev"
        class="version-info"
      >
        {{ t('lobby.build', { version: buildVersion }) }}
        · <a
          href="/dev/sounds"
          class="version-info__link"
          data-testid="dev-sound-bench"
        >{{ t('devSounds.title') }}</a>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { gameApi } from '@/services/api';
import { accountApi, nameError } from '@/services/account';
import SoundControl from '@/components/ui/SoundControl.vue';
import { useMusicSituation } from '@/composables/useMusicSituation';
import LanguageSwitch from '@/components/ui/LanguageSwitch.vue';
import ThemeLogo from '@/components/ThemeLogo.vue';
import GameIcon from '@/components/ui/GameIcon.vue';
import TablesPanel from '@/components/lobby/TablesPanel.vue';
import { useI18n } from 'vue-i18n';
import { theme, getHero } from '@/theme';
import { heroName, HERO_SKILL_KEYS } from '@/i18n/names';

const router = useRouter();
const route = useRoute();
useMusicSituation('lobby');

// The panel's tab, kept for this browser; an online flow (a table link, Google) may open on its own
const LOBBY_TABS = ['ai', 'online'];
const readTab = () => {
  try { return localStorage.getItem('lobby.tab'); } catch { return null; }
};
const tab = ref(LOBBY_TABS.includes(readTab()) ? readTab() : 'ai');
watch(tab, (name) => {
  try { localStorage.setItem('lobby.tab', name); } catch { /* private mode */ }
});
const { t } = useI18n();
const errorMessage = ref('');
const loading = ref(false);
const loadingMessage = ref('');

// Build version for debugging deployment issues
const buildVersion = ref(import.meta.env.VITE_BUILD_VERSION || 'dev');
const isDev = import.meta.env.DEV;

// Hero cards: names and skill summaries are translated (fe/src/i18n), portraits come from the theme
const HERO_CARDS = [
  { key: null, testid: 'hero-none' },
  { key: 'warrior', testid: 'hero-warrior' },
  { key: 'thief', testid: 'hero-thief' },
  { key: 'swordsman', testid: 'hero-swordsman' },
  { key: 'warlock', testid: 'hero-warlock' },
  { key: 'wizard', testid: 'hero-wizard' },
  { key: 'oracle', testid: 'hero-oracle' },
  // Karak: Regent heroes (only with the expansion switch on)
  { key: 'barbarian', testid: 'hero-barbarian', regent: true },
  { key: 'battle_mage', testid: 'hero-battle_mage', regent: true },
  { key: 'acrobat', testid: 'hero-acrobat', regent: true },
  { key: 'ranger', testid: 'hero-ranger', regent: true }
];
// Re-evaluated on a locale switch (t() reads the current locale)
const allHeroCards = computed(() => HERO_CARDS.map((card) => {
  const hero = getHero(card.key);
  return {
    ...card,
    image: hero.image,
    name: card.key ? heroName(card.key) : t('lobby.noHero'),
    shortName: card.key ? heroName(card.key, 'short') : t('lobby.noHero'),
    role: card.key ? heroName(card.key, 'role') : t('lobby.noHero'),
    skills: (HERO_SKILL_KEYS[card.key] || []).map((key) => ({ key, name: t(`skills.${key}.name`), text: t(`skills.${key}.summary`) }))
  };
}));

// Karak: Regent expansion switch, remembered per browser ('1' / '0')
const readStoredRegent = () => {
  try {
    return localStorage.getItem('regentExpansion') === '1';
  } catch (e) {
    return false;
  }
};
const regentExpansion = ref(readStoredRegent());
watch(regentExpansion, (enabled) => {
  try {
    localStorage.setItem('regentExpansion', enabled ? '1' : '0');
  } catch (e) {
    // localStorage unavailable (private mode) - the switch still works for this page
  }
});
// Lord Karak variant switch (inside the expansion), remembered per browser ('1' / '0')
const readStoredLordKarak = () => {
  try {
    return localStorage.getItem('lordKarakVariant') === '1';
  } catch (e) {
    return false;
  }
};
const lordKarakVariant = ref(readStoredLordKarak());
watch(lordKarakVariant, (enabled) => {
  try {
    localStorage.setItem('lordKarakVariant', enabled ? '1' : '0');
  } catch (e) {
    // localStorage unavailable (private mode) - the switch still works for this page
  }
});
// Random heroes switch (the board game deals heroes), on unless turned off in this browser ('1' / '0')
const readStoredRandomHeroes = () => {
  try {
    return localStorage.getItem('randomHeroes') !== '0';
  } catch (e) {
    return true;
  }
};
const randomHeroes = ref(readStoredRandomHeroes());
watch(randomHeroes, (enabled) => {
  try {
    localStorage.setItem('randomHeroes', enabled ? '1' : '0');
  } catch (e) {
    // localStorage unavailable (private mode) - the switch still works for this page
  }
});
// Hero cards marked `regent: true` come with the expansion (the backend refuses them in a base game)
const heroCards = computed(() => allHeroCards.value.filter((card) => !card.regent || regentExpansion.value));

// Lobby background art comes from the theme (none in the base theme)
const lobbyStyle = { '--lobby-bg': theme.lobbyBackground ? `url('${theme.lobbyBackground}')` : 'none' };

// Hero selection ('warrior' or null = no hero skills), remembered per browser
const readStoredHero = () => {
  try {
    const stored = localStorage.getItem('selectedHero');
    return ['warrior', 'thief', 'swordsman', 'warlock', 'wizard', 'oracle', 'barbarian', 'battle_mage', 'acrobat', 'ranger'].includes(stored) ? stored : null;
  } catch (e) {
    return null;
  }
};
const selectedHero = ref(readStoredHero());
const selectedCard = computed(() => heroCards.value.find((card) => card.key === selectedHero.value) || heroCards.value[0]);
const selectHero = (hero) => {
  selectedHero.value = hero;
  try {
    if (hero) {
      localStorage.setItem('selectedHero', hero);
    } else {
      localStorage.removeItem('selectedHero');
    }
  } catch (e) {
    // localStorage unavailable (private mode) - selection still works for this page
  }
};

// Custom UUID v4 implementation
const generateRandomId = () => {
  // Helper function to generate random hex digits
  const randomHex = (c) => {
    const r = Math.random() * 16 | 0;
    // For UUID v4 format, specific digits need special treatment
    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
  };

  // Format template for UUID v4 standard (xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx)
  // where y is 8, 9, a, or b
  const template = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx';

  // Replace template with random values
  return template.replace(/[xy]/g, randomHex);
};

onMounted(() => {
  loadAccount().then(showGoogleOutcome);
  accountApi.googleStatus().then((status) => { google.value = status; });
});

// ===== Google: keep the account, sign in on another device =====
const google = ref({ available: false, fake: false });
const googleUrl = computed(() => accountApi.googleStartUrl('/', google.value));
const accountNotice = ref('');
const GOOGLE_NOTICES = { bound: 'account.googleBound', signed_in: 'account.googleSignedIn', created: 'account.googleCreated', failed: 'account.googleFailed' };
// back from Google with ?google=…: one notice, then the address is clean again
const showGoogleOutcome = () => {
  const outcome = route.query.google;
  if (!outcome || !GOOGLE_NOTICES[outcome]) return;
  console.info('[account] back from Google:', outcome);
  accountNotice.value = t(GOOGLE_NOTICES[outcome], { name: account.value?.name || '' });
  const query = { ...route.query };
  delete query.google;
  router.replace({ query });
};
const signOut = async () => {
  try {
    await accountApi.signOut();
    account.value = null;
    nameInput.value = accountApi.suggestName();
    accountNotice.value = t('account.signedOut');
    console.info('[account] signed out on this device');
  } catch (err) {
    errorMessage.value = t('account.failed');
    console.warn('[account] sign-out failed:', err?.response?.data || err?.message || err);
  }
};

// ===== Who plays: the guest account =====
const account = ref(null);
const accountChecked = ref(false);
const nameInput = ref(accountApi.suggestName());
const nameErrorText = ref('');
const savingName = ref(false);
const renaming = ref(false);
const loadAccount = async () => {
  try {
    account.value = await accountApi.me();
  } catch (err) {
    console.warn('[account] could not load the account:', err?.message || err);
  } finally {
    accountChecked.value = true;
  }
};
const saveName = async () => {
  if (savingName.value) return;
  savingName.value = true;
  nameErrorText.value = '';
  try {
    account.value = await accountApi.createGuest(nameInput.value);
    console.info('[account] signed in as a guest');
  } catch (err) {
    nameErrorText.value = nameError(err) ? t('account.badName') : t('account.failed');
  } finally {
    savingName.value = false;
  }
};
const startRename = () => {
  nameInput.value = account.value?.name || '';
  renaming.value = true;
};
const saveRename = async () => {
  try {
    account.value = await accountApi.rename(nameInput.value);
    renaming.value = false;
  } catch (err) {
    errorMessage.value = nameError(err) ? t('account.badName') : t('account.failed');
  }
};

// Game creation
const viewLeaderboard = () => {
  router.push('/leaderboard');
};

const viewRules = () => {
  router.push('/rules');
};

const createNewGame = async () => {
  // Prevent multiple clicks
  if (loading.value) {
    console.log('Already creating game, ignoring duplicate click');
    return;
  }
  
  try {
    loading.value = true;
    errorMessage.value = '';
    loadingMessage.value = t('lobby.creating');

    // Call the API to create a new game
    const response = await gameApi.createGame(null, {
      regent: regentExpansion.value,
      lordKarak: regentExpansion.value && lordKarakVariant.value,
      randomHeroes: randomHeroes.value
    });
    console.log('Game created with ID:', response.gameId);

    // Generate a player ID for the current user
    const playerId = generateRandomId();

    // Store the player ID in local storage for later use
    localStorage.setItem('currentPlayerId', playerId);
    // Store human player ID separately so it doesn't change
    localStorage.setItem('humanPlayerId', playerId);
    // ...and per game: another game started in another tab overwrites the keys above,
    // GameView then finds this game's own player here
    localStorage.setItem(`humanPlayer:${response.gameId}`, playerId);

    // The hero shown in the roster: a Regent hero remembered from an earlier game is dropped when the switch is off;
    // with random heroes the backend deals one
    const heroForGame = randomHeroes.value ? null : (selectedCard.value?.key ?? null);
    await gameApi.joinGame(response.gameId, playerId, null, false, heroForGame);
    console.log('Joined game as player ID:', playerId, 'Hero:', heroForGame);

    // Add a virtual player as player 2 (use valid UUID)
    const virtualPlayerId = generateRandomId();
    await gameApi.joinGame(response.gameId, virtualPlayerId, 'AI Player', true); // isAi = true
    console.log('Added virtual player as player 2:', virtualPlayerId);

    // Store the virtual player ID in localStorage to identify it later
    localStorage.setItem('virtualPlayerId', virtualPlayerId);

    // Mark both players as ready
    await gameApi.playerReady(response.gameId, playerId);
    await gameApi.playerReady(response.gameId, virtualPlayerId);
    console.log('Both players marked as ready');

    // Start the game
    await gameApi.startGame(response.gameId);
    console.log('Game started with virtual player');

    // Navigate to the new game - use path instead of name to ensure proper routing
    router.push(`/game/${response.gameId}`);
  } catch (error) {
    console.error('Failed to create game:', error);
    errorMessage.value = t('lobby.createFailed');
  } finally {
    loading.value = false;
  }
};

</script>

<style scoped>
/* ===== The dungeon behind everything ===== */
.home-container {
  position: relative;
  min-height: 100vh;
  min-height: 100dvh;
  box-sizing: border-box;
  display: grid;
  grid-template-columns: 1fr minmax(320px, 440px);
  grid-template-areas: "title panel";
  align-items: start;
  column-gap: 48px;
  padding: 70px 96px 48px;
  overflow-x: hidden;
  font-family: var(--font-body);
  color: var(--text-primary);
  background-color: var(--bg-field);
  background-image: var(--lobby-bg);
  background-size: cover;
  background-position: center;
}

.lobby-shade {
  position: absolute;
  inset: 0;
  pointer-events: none;
  background:
    radial-gradient(circle at 36% 60%, rgba(var(--ember-rgb), 0.16) 0%, rgba(var(--ember-rgb), 0) 30%),
    radial-gradient(circle at 36% 58%, rgba(5, 3, 2, 0) 0%, rgba(5, 3, 2, 0.25) 26%, rgba(5, 3, 2, 0.9) 62%),
    linear-gradient(90deg, rgba(5, 3, 2, 0) 45%, rgba(5, 3, 2, 0.92) 72%);
}

.lobby-tools {
  position: fixed;
  top: 20px;
  right: 20px;
  z-index: 3;
  display: flex;
  gap: 10px;
}

/* ===== Title over the dungeon ===== */
.lobby-title {
  grid-area: title;
  position: relative;
  z-index: 1;
  text-shadow: 0 4px 14px #000;
}

.title {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: clamp(72px, 10.5vw, 150px);
  line-height: 0.85;
  color: var(--text-heading);
  text-shadow: 0 0 30px rgba(var(--ember-rgb), 0.45), 0 6px 18px #000;
}

.description {
  max-width: 34em;
  margin: 14px 0 0;
  font-style: italic;
  font-size: 20px;
  line-height: 1.4;
  color: #d6c3a0;
}

/* ===== Preparing the descent ===== */
.game-panel {
  grid-area: panel;
  position: relative;
  z-index: 1;
  margin-top: 48px;
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.account-line {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 15px;
  color: var(--text-secondary);
}

.account-line__label {
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.24em;
  text-transform: uppercase;
  color: var(--text-muted);
}

.account-line__name {
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 24px;
  color: var(--text-heading);
}

.account-line__pen {
  display: inline-flex;
  padding: 4px;
  border: 0;
  background: none;
  color: var(--text-accent);
}

.account-line__form {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.name-input {
  flex: 1 1 160px;
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

/* the name card: in the place of the big button until the player has a name */
.name-card {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 16px 18px;
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.92) 0%, rgba(12, 7, 5, 0.92) 100%);
  box-shadow: 0 0 26px rgba(var(--ember-rgb), 0.18);
}

.name-card__ask {
  font-family: var(--font-display);
  font-size: 26px;
  line-height: 1.1;
  color: var(--text-heading);
}

.name-card__row {
  display: flex;
  gap: 10px;
}

.name-card__google,
.account-line__link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 0;
  border: none;
  background: none;
  color: var(--text-accent);
  font-family: var(--font-body);
  font-size: 14px;
  text-decoration: underline;
  text-underline-offset: 3px;
  cursor: pointer;
}

.name-card__google:hover,
.account-line__link:hover {
  color: var(--color-primary-hover);
}

.account-line__google {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--text-muted);
}

.account-notice {
  padding: 8px 12px;
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  background: rgba(20, 12, 8, 0.9);
  color: var(--text-secondary);
  font-style: italic;
}

.name-card__hint {
  font-size: 13px;
  font-style: italic;
  color: var(--text-muted);
}

.lobby-caption,
.hero-picker-title {
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.32em;
  text-transform: uppercase;
  color: var(--text-accent);
}

.hero-picker-title {
  margin: 0;
  letter-spacing: 0.2em;
  color: var(--text-muted);
}

.hero-picker {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.hero-card {
  display: flex;
  align-items: center;
  gap: 18px;
  padding: 16px 18px;
  border: 1px solid rgba(var(--ember-rgb), 0.35);
  background: rgba(10, 6, 4, 0.7);
}

.hero-medallion {
  width: 84px;
  height: 84px;
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  border-radius: 50%;
  color: var(--text-heading);
  background: radial-gradient(circle at 50% 35%, #3a2416 0%, #0d0805 80%);
  box-shadow: 0 0 0 3px var(--iron), 0 0 0 5px var(--iron-edge), 0 0 22px rgba(var(--ember-rgb), 0.35);
}

/* the portrait is taller than the ring and starts at its top: the head stays in, the legs go */
.hero-medallion--portrait {
  align-self: flex-start;
  align-items: flex-start;
}

.hero-card-image {
  width: 100%;
  height: 150%;
  object-fit: cover;
  object-position: top;
}

.hero-card-body {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.hero-card-role {
  font-size: 12px;
  letter-spacing: 0.2em;
  font-variant-caps: all-small-caps;
  color: var(--text-muted);
}

.hero-card-name {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 28px;
  line-height: 1;
  color: var(--text-heading);
}

.hero-skills-none {
  margin: 0;
  font-size: 15px;
  color: var(--text-secondary);
}

.hero-skills {
  margin: 4px 0 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.hero-skill {
  display: flex;
  flex-direction: column;
  font-size: 14px;
  line-height: 1.3;
}

.hero-skill-name {
  font-weight: 600;
  color: var(--text-accent);
}

.hero-skill-text {
  color: var(--text-secondary);
}

.hero-card-enter-active,
.hero-card-leave-active {
  transition: opacity var(--transition-fast), transform var(--transition-fast);
}

.hero-card-enter-from,
.hero-card-leave-to {
  opacity: 0;
  transform: translateY(6px);
}

/* the roster: small medallions in a row */
.hero-roster {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.hero-tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  width: 56px;
  padding: 0;
  border: 0;
  background: none;
  color: var(--text-secondary);
  font: inherit;
  cursor: pointer;
}

.hero-tile-figure {
  width: 48px;
  height: 48px;
  overflow: hidden;
  border-radius: 50%;
  background: radial-gradient(circle at 50% 35%, #4a3020 0%, #140c07 75%);
  box-shadow: 0 0 0 2px var(--iron-dark), 0 0 0 4px var(--iron-edge);
  transition: box-shadow var(--transition-fast);
}

.hero-tile-image {
  display: block;
  width: 100%;
  height: 150%;
  object-fit: cover;
  object-position: top;
}

.hero-tile-name {
  font-size: 12px;
  line-height: 1.1;
  text-align: center;
}

.hero-tile:hover .hero-tile-figure,
.hero-tile:focus-visible .hero-tile-figure {
  box-shadow: 0 0 0 2px var(--color-secondary), 0 0 0 4px var(--iron-edge);
}

.hero-tile:focus-visible {
  outline: none;
}

.hero-tile.selected {
  color: var(--text-heading);
}

.hero-tile.selected .hero-tile-figure {
  box-shadow: 0 0 0 2px var(--color-primary), 0 0 0 4px var(--iron-edge), 0 0 16px rgba(var(--ember-rgb), 0.6);
}

/* the two ways down: tabs in the caption's letters, an ember underline on the open one */
.lobby-tabs {
  display: flex;
  gap: 26px;
  border-bottom: 1px solid rgba(234, 220, 192, 0.14);
}

.lobby-tab {
  margin: 0 0 -1px;
  padding: 0 0 10px;
  border: 0;
  border-bottom: 2px solid transparent;
  background: none;
  font: inherit;
  letter-spacing: inherit;
  text-transform: inherit;
  color: var(--text-muted);
  cursor: pointer;
  transition: color var(--transition-fast), border-color var(--transition-fast);
}

.lobby-tab:hover,
.lobby-tab:focus-visible {
  outline: none;
  color: var(--text-secondary);
}

.lobby-tab--active,
.lobby-tab--active:hover {
  color: var(--text-accent);
  border-bottom-color: var(--color-primary);
}

/* the options: checkbox rows divided by hairlines */
.lobby-options {
  display: flex;
  flex-direction: column;
}

.regent-toggle {
  display: flex;
  align-items: center;
  gap: 14px;
  /* one height for every row (a title and two lines of hint): switching an option moves nothing */
  height: 66px;
  padding: 8px 14px;
  box-sizing: border-box;
  border-bottom: 1px solid rgba(234, 220, 192, 0.12);
  cursor: pointer;
}

.regent-toggle input {
  width: 22px;
  height: 22px;
  margin: 0;
  flex: none;
  accent-color: var(--color-primary);
  cursor: inherit;
}

.lord-karak-toggle {
  padding-left: 44px;
}

.regent-toggle--off {
  opacity: 0.45;
  cursor: default;
}

.regent-toggle-text {
  display: flex;
  flex-direction: column;
  line-height: 1.25;
}

.regent-toggle-text strong {
  font-weight: 600;
  font-size: 16px;
  color: var(--text-heading);
}

.regent-toggle-text span {
  font-size: 14px;
  color: #a8957a;
  /* at most two lines, so a row keeps its height whatever its hint says */
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* the descent and the links */
.primary-button {
  height: 68px;
  margin-top: 6px;
  font-family: var(--font-display);
  font-size: 34px;
  letter-spacing: 0.02em;
  box-shadow: 0 0 30px rgba(var(--ember-rgb), 0.4), inset 0 1px 0 rgba(255, 220, 180, 0.25);
}

.primary-button:lang(ru) {
  font-size: 26px;
}

.lobby-links {
  display: flex;
  gap: 12px;
}

.lobby-links .btn {
  flex: 1;
  min-height: 48px;
  gap: 8px;
  font-size: 15px;
}

.error-message {
  padding: 10px 14px;
  border: 1px solid rgba(217, 69, 42, 0.6);
  background: rgba(217, 69, 42, 0.12);
  color: #f0b5a8;
}

.loading-indicator {
  display: flex;
  align-items: center;
  gap: 12px;
  color: var(--text-secondary);
}

.spinner {
  width: 26px;
  height: 26px;
  border: 3px solid rgba(var(--ember-rgb), 0.2);
  border-top-color: var(--color-primary);
  border-radius: 50%;
  animation: spin 0.9s linear infinite;
}

.spinner-inner {
  display: none;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.version-info__link {
  color: inherit;
}

.version-info {
  font-size: 12px;
  color: var(--text-muted);
  text-align: center;
}

@media (prefers-reduced-motion: reduce) {
  .hero-card-enter-active,
  .hero-card-leave-active,
  .spinner {
    transition: none;
    animation: none;
  }
}

/* ===== Desktop: the lobby fits the screen, no page scroll; a long list of tables scrolls inside.
   Below 700px of height the page scrolls as before, so nothing is cut off ===== */
@media (min-width: 1025px) and (min-height: 700px) {
  .home-container {
    height: 100vh;
    height: 100dvh;
    grid-template-rows: minmax(0, 1fr);
    overflow: hidden;
  }

  /* the panel is as tall as the screen: the account and the tabs on top, the options, Play and the links at
     the bottom — always in the same place, whatever is switched on — and the hero picker between gives way */
  .game-panel {
    min-height: 0;
    height: calc(100% - 48px);
  }

  /* against the AI the options, Play and the links are one block at the bottom (the hero picker above takes
     the rest); online the options stay on top and the links go down */
  .game-panel > .hero-picker + .lobby-options,
  .game-panel > .tables-panel + .lobby-links {
    margin-top: auto;
  }

  .game-panel > * {
    flex-shrink: 0;
  }

  .game-panel > .tables-panel {
    flex-shrink: 1;
  }

  /* with many heroes (Regent: 11) the roster and the card scroll inside, the Play button stays in sight */
  .game-panel > .hero-picker {
    flex-shrink: 1;
    min-height: 120px;
    overflow-y: auto;
    padding-right: 6px;
    scrollbar-width: thin;
    scrollbar-color: rgba(var(--ember-rgb), 0.45) transparent;
  }
}

/* ===== Narrow screens: the title above, the panel below, the dungeon behind both ===== */
/* Desktop screens up to ~820px high: the right column tightens so "Descend against the AI" stays on screen */
@media (min-width: 1025px) and (max-height: 820px) {
  .home-container {
    padding-top: 36px;
    padding-bottom: 24px;
  }

  .game-panel {
    margin-top: 24px;
    height: calc(100% - 24px);
    gap: 12px;
  }

  .hero-card {
    padding: 10px 14px;
    gap: 14px;
  }

  .hero-medallion {
    width: 64px;
    height: 64px;
  }

  .regent-toggle {
    height: 60px;
    padding: 5px 12px;
  }

  .primary-button {
    height: 58px;
  }
}

@media (max-width: 1024px) {
  .home-container {
    grid-template-columns: 1fr;
    grid-template-areas: "title" "panel";
    padding: 72px 24px 32px;
    justify-items: center;
  }

  .lobby-shade {
    background:
      radial-gradient(circle at 50% 30%, rgba(var(--ember-rgb), 0.14) 0%, rgba(var(--ember-rgb), 0) 40%),
      linear-gradient(180deg, rgba(5, 3, 2, 0.35) 0%, rgba(5, 3, 2, 0.85) 45%, rgba(5, 3, 2, 0.95) 100%);
  }

  .lobby-title {
    text-align: center;
  }

  .description {
    margin-left: auto;
    margin-right: auto;
    font-size: 17px;
  }

  .game-panel {
    width: 100%;
    max-width: 480px;
    margin-top: 32px;
  }
}

@media (max-width: 480px) {
  .home-container {
    padding: 72px 16px 24px;
  }

  .primary-button {
    height: 60px;
    font-size: 28px;
  }

  .primary-button:lang(ru) {
    font-size: 22px;
  }

  .lobby-links {
    flex-direction: column;
  }
}
</style>
