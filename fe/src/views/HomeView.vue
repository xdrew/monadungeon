<template>
  <div class="home-container" :style="lobbyStyle">
    <MusicToggle />
    
    <div class="main-content">
      <!-- Game Options Panel -->
      <div class="game-panel">
        <ThemeLogo />
        <h1 class="title">
          {{ theme.appName }}
        </h1>
        <p class="description">
          Embark on an epic journey through dangerous dungeons filled with monsters, treasures, and ancient secrets.
        </p>

      <div class="actions">
        <!-- Hero selection (hero skills from the Karak board game) -->
        <div class="hero-picker" data-testid="hero-picker">
          <p class="hero-picker-title">{{ randomHeroes ? 'Heroes are dealt at random' : 'Choose your hero' }}</p>

          <!-- Random heroes (the board game rule): you and the AI are dealt different heroes when the game is created -->
          <label class="regent-toggle random-heroes-toggle" data-testid="random-heroes-toggle">
            <input v-model="randomHeroes" type="checkbox" data-testid="random-heroes-toggle-input">
            <span class="regent-toggle-text">
              <strong>Random heroes</strong>
              <span>Off: pick your hero, the AI is dealt one of the others</span>
            </span>
          </label>

          <div v-if="randomHeroes" class="hero-card hero-card-random" data-testid="hero-card-random">
            <div class="hero-card-figure">
              <span class="hero-card-emoji"><GameIcon name="dice" :size="44" /></span>
            </div>
            <div class="hero-card-body">
              <span class="hero-card-role">Dealt at the start</span>
              <h3 class="hero-card-name">A hero awaits</h3>
              <p class="hero-skills-none">
                You and the AI each get a different hero{{ regentExpansion ? ', Karak: Regent heroes included' : '' }}.
              </p>
            </div>
          </div>

          <!-- roster: one portrait tile per hero; the skills live in the card below -->
          <div v-if="!randomHeroes" class="hero-roster" role="radiogroup" aria-label="Hero">
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
                <ThemedImage
                  :src="card.image"
                  :emoji="card.emoji"
                  :alt="card.name"
                  img-class="hero-tile-image"
                  emoji-class="hero-tile-emoji"
                />
              </span>
              <span class="hero-tile-name">{{ card.shortName }}</span>
            </button>
          </div>

          <!-- the selected hero's card, laid out like a rulebook entry -->
          <transition v-if="!randomHeroes" name="hero-card" mode="out-in">
            <div
              :key="selectedCard.testid"
              class="hero-card"
              data-testid="hero-card"
            >
              <div class="hero-card-figure">
                <ThemedImage
                  :src="selectedCard.image"
                  :emoji="selectedCard.emoji"
                  :alt="selectedCard.name"
                  img-class="hero-card-image"
                  emoji-class="hero-card-emoji"
                />
              </div>
              <div class="hero-card-body">
                <span class="hero-card-role">{{ selectedCard.role }}</span>
                <h3 class="hero-card-name">{{ selectedCard.shortName }}</h3>
                <ul v-if="selectedCard.skills.length" class="hero-skills">
                  <li v-for="skill in selectedCard.skills" :key="skill.name" class="hero-skill">
                    <span class="hero-skill-name">{{ skill.name }}</span>
                    <span class="hero-skill-text">{{ skill.text }}</span>
                  </li>
                </ul>
                <p v-else class="hero-skills-none">No special skills. Plain dice, plain luck.</p>
              </div>
            </div>
          </transition>

          <!-- Karak: Regent expansion: mixes the Lord Karak rooms and the new monsters into the game -->
          <label class="regent-toggle" data-testid="regent-toggle">
            <input v-model="regentExpansion" type="checkbox" data-testid="regent-toggle-input">
            <span class="regent-toggle-text">
              <strong>Karak: Regent expansion</strong>
              <span>Lord Karak rooms, Giant Bats and Skeleton Mages</span>
            </span>
          </label>
          <!-- Lord Karak variant: the weakest hero becomes Lord Karak when the fifth Lord Karak room is discovered -->
          <label v-if="regentExpansion" class="regent-toggle lord-karak-toggle" data-testid="lord-karak-toggle">
            <input v-model="lordKarakVariant" type="checkbox" data-testid="lord-karak-toggle-input">
            <span class="regent-toggle-text">
              <strong>Lord Karak variant</strong>
              <span>At the fifth Lord Karak room the weakest hero becomes Lord Karak</span>
            </span>
          </label>
        </div>

        <button
            class="primary-button btn btn--ember btn--lg"
            @click="createNewGame"
            :disabled="loading"
        >
          Play Against AI
        </button>
        
        <button 
          class="secondary-button leaderboard-button btn btn--ghost btn--lg"
          @click="viewLeaderboard"
        >
          <GameIcon name="trophy" />
          View Leaderboard
        </button>
        
        <button 
          class="secondary-button rules-button btn btn--ghost btn--lg"
          @click="viewRules"
        >
          <GameIcon name="journal" />
          How to Play
        </button>
        <div v-if="isDev" class="version-info">
          Build: {{ buildVersion }}
        </div>
      </div>

        <div
            v-if="errorMessage"
            class="error-message"
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
      </div>
    </div>
  </div>

</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { gameApi } from '@/services/api';
import MusicToggle from '@/components/game/MusicToggle.vue';
import ThemeLogo from '@/components/ThemeLogo.vue';
import ThemedImage from '@/components/ThemedImage.vue';
import GameIcon from '@/components/ui/GameIcon.vue';
import { musicService } from '@/services/musicService';
import { theme, getHero } from '@/theme';

const router = useRouter();
const errorMessage = ref('');
const loading = ref(false);
const loadingMessage = ref('Creating your adventure...');

// Build version for debugging deployment issues
const buildVersion = ref(import.meta.env.VITE_BUILD_VERSION || 'dev');
const isDev = import.meta.env.DEV;

// Hero cards: skills from the Karak rulebook; name and portrait/emoji from the active theme
const HERO_SKILLS = [
  { key: null, testid: 'hero-none', shortName: 'No hero', skills: [] },
  { key: 'warrior', testid: 'hero-warrior', skills: [
    { name: 'Double Attack', text: 'reroll the dice once per fight, the second roll stands' },
    { name: 'Reincarnation', text: 'losing the last HP teleports him to a healing fountain with full HP' }
  ] },
  { key: 'thief', testid: 'hero-thief', skills: [
    { name: 'Backstab', text: 'a tie in combat counts as her victory' },
    { name: 'Stealth', text: 'may sneak past a monster instead of fighting and keep moving' }
  ] },
  { key: 'swordsman', testid: 'hero-swordsman', skills: [
    { name: 'Combat Training', text: 'every die showing 1 is rerolled until it shows better' },
    { name: 'Unstoppable', text: 'a 6 on the dice keeps his turn going, even after a lost fight' }
  ] },
  { key: 'warlock', testid: 'hero-warlock', shortName: 'Xanros', skills: [
    { name: 'Sacrifice', text: 'after the roll, pay 1 HP for +1 strength (once per fight)' },
    { name: 'Magic Swap', text: 'trade places with any hero as the first action of the turn, for all 4 moves' }
  ] },
  { key: 'wizard', testid: 'hero-wizard', skills: [
    { name: 'Magical Affinity', text: 'fireballs are not consumed in combat' },
    { name: 'Astral Walking', text: 'may step onto any adjacent discovered tile, even through a wall' }
  ] },
  { key: 'oracle', testid: 'hero-oracle', skills: [
    { name: 'Farseeing', text: '+1 to the roll when fighting on the first move of the turn' },
    { name: 'Fateweaver', text: 'draws two tokens for a new room, places one and returns the other' }
  ] },
  // Karak: Regent heroes (only with the expansion switch on)
  { key: 'barbarian', testid: 'hero-barbarian', regent: true, skills: [
    { name: 'Berserk', text: '+1 strength at 3–4 HP, +2 at 1–2 HP; chooses how many HP to heal' },
    { name: 'Tireless', text: 'after a won fight he may leave the loot and keep moving' }
  ] },
  { key: 'battle_mage', testid: 'hero-battle_mage', shortName: 'Markul', regent: true, skills: [
    { name: 'Sword Master', text: 'a sword has strength 3 in his hands' },
    { name: 'Lightning Attack', text: 'for all 4 moves, teleport onto any monster and fight it' }
  ] },
  { key: 'acrobat', testid: 'hero-acrobat', regent: true, skills: [
    { name: 'Dagger Throwing', text: 'daggers also fit her spell slots, +1 each, never used up' },
    { name: 'Sprint', text: 'up to 8 moves over discovered tiles instead of exploring' }
  ] },
  { key: 'ranger', testid: 'hero-ranger', shortName: 'Lorraine', regent: true, skills: [
    { name: 'Bear Attack', text: 'a die showing 1 counts as 6' },
    { name: 'Listening', text: 'for one move, open an adjacent tile without entering it' }
  ] }
];
const allHeroCards = HERO_SKILLS.map((card) => {
  const hero = getHero(card.key);
  // "Horan, the Warrior" → short name "Horan", role "Warrior"; the Adventurer has no role
  const [firstName, role] = hero.name.includes(', the ') ? hero.name.split(', the ') : [hero.name, 'No hero'];
  return { ...card, ...hero, key: card.key, shortName: card.shortName || firstName, role };
});

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
const heroCards = computed(() => allHeroCards.filter((card) => !card.regent || regentExpansion.value));

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

// Start the lobby music on mount
onMounted(() => {
  musicService.init('/music/game-theme.mp3');
});

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
    loadingMessage.value = 'Creating your adventure...';

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
    errorMessage.value = 'Failed to create game. Please try again.';
  } finally {
    loading.value = false;
  }
};

</script>

<style scoped>
.home-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background-color: #0a0a0a;
  padding: 20px;
  position: relative;
  overflow: hidden;
}

/* Background image with feathered edges */
.home-container::before {
  content: '';
  position: fixed;
  top: 50%;
  left: 0;
  transform: translateY(-50%);
  width: 60%;
  height: 80%;
  background-image: var(--lobby-bg, none);
  background-size: cover;
  background-position: center;
  background-repeat: no-repeat;
  opacity: 0.4;
  mask-image: radial-gradient(ellipse at left center, black 40%, transparent 70%);
  -webkit-mask-image: radial-gradient(ellipse at left center, black 40%, transparent 70%);
  z-index: 1;
  pointer-events: none;
  will-change: auto;
}

.main-content {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  max-width: 1400px;
  width: 100%;
  position: relative;
  z-index: 2;
  padding-right: 60px;
}

/* Game Panel */
.game-panel {
  background: transparent;
  padding: 40px;
  text-align: center;
  color: white;
  width: 100%;
  max-width: 500px;
}

.title {
  color: var(--text-accent);
  margin-bottom: 10px;
  font-size: 48px;
  text-shadow: 2px 2px 4px #000;
  font-weight: bold;
  letter-spacing: 2px;
  text-transform: uppercase;
}

.subtitle {
  color: #888;
  margin-bottom: 15px;
  font-style: italic;
  font-size: 16px;
}

.description {
  margin-bottom: 30px;
  line-height: 1.6;
  color: #666;
  font-size: 14px;
}

.version-info {
  font-size: 10px;
  color: #666;
  margin-top: -5px;
  margin-bottom: 15px;
}

.actions {
  display: flex;
  flex-direction: column;
  gap: 20px;
  margin-top: 20px;
}



.hero-picker {
  margin: 4px 0 14px;
  text-align: left;
}

.hero-picker-title {
  margin: 0 0 10px;
  font-size: 13px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #8f86a8;
}

/* Roster: a shelf of portrait tiles */
.hero-roster {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 5px;
}

.hero-tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 6px 1px 5px;
  border-radius: 6px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.04) 0%, rgba(0, 0, 0, 0.35) 100%);
  color: #9d95b8;
  cursor: pointer;
  transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease, color 0.15s ease;
}

.hero-tile:hover {
  border-color: rgba(var(--accent-light-rgb), 0.5);
  color: #ece8ff;
  transform: translateY(-2px);
}

.hero-tile:focus-visible {
  outline: 2px solid var(--color-primary-hover);
  outline-offset: 2px;
}

.hero-tile.selected {
  border-color: var(--color-primary-hover);
  color: var(--text-primary);
  background: linear-gradient(180deg, rgba(var(--accent-rgb), 0.16) 0%, rgba(0, 0, 0, 0.45) 100%);
  box-shadow: 0 0 0 1px rgba(var(--accent-rgb), 0.7), 0 6px 18px rgba(var(--accent-rgb), 0.16);
}

.hero-tile-figure {
  display: flex;
  align-items: flex-end;
  justify-content: center;
  width: 100%;
  height: 72px;
}

.hero-tile-image {
  height: 72px;
  width: auto;
  max-width: 100%;
  object-fit: contain;
  filter: drop-shadow(0 4px 4px rgba(0, 0, 0, 0.6));
  transition: filter 0.15s ease;
}

.hero-tile.selected .hero-tile-image {
  filter: drop-shadow(0 0 6px rgba(var(--accent-light-rgb), 0.45));
}

.hero-tile-emoji {
  font-size: 40px;
  line-height: 72px;
}

.hero-tile-name {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
}

/* Card: the selected hero, laid out like a rulebook entry */
.hero-card {
  display: flex;
  gap: 18px;
  align-items: stretch;
  margin-top: 10px;
  padding: 14px 16px;
  min-height: 150px;
  border-radius: 8px;
  border: 1px solid rgba(var(--accent-rgb), 0.35);
  background:
    radial-gradient(ellipse at 18% 60%, rgba(207, 113, 40, 0.14) 0%, transparent 55%),
    linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-primary) 100%);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04), 0 4px 16px rgba(0, 0, 0, 0.5);
  text-align: left;
}

.hero-card-figure {
  flex: 0 0 96px;
  display: flex;
  align-items: flex-end;
  justify-content: center;
}

.hero-card-image {
  height: 132px;
  width: auto;
  object-fit: contain;
  filter: drop-shadow(0 6px 8px rgba(0, 0, 0, 0.7));
}

.hero-card-emoji {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 132px;
  color: var(--text-accent);
}

.hero-card-body {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.hero-card-role {
  font-size: 11px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #8f86a8;
}

.hero-card-name {
  margin: 2px 0 10px;
  font-size: 22px;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: var(--text-accent);
  text-shadow: 0 1px 2px #000;
}

.hero-skills {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.hero-skill {
  display: grid;
  grid-template-columns: 112px 1fr;
  gap: 10px;
  align-items: baseline;
  font-size: 13px;
  line-height: 1.4;
}

.hero-skill-name {
  font-weight: 700;
  color: #ece8ff;
}

.hero-skill-name::before {
  content: '\25C6\00A0';
  color: var(--color-secondary-hover);
  font-size: 9px;
  vertical-align: 2px;
}

.hero-skill-text {
  color: #b7afd0;
}

.hero-skills-none {
  margin: 0;
  font-size: 13px;
  color: #b7afd0;
}

/* Karak: Regent expansion switch */
.regent-toggle {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 10px;
  padding: 8px 10px;
  border: 1px solid rgba(183, 175, 208, 0.25);
  border-radius: 8px;
  cursor: pointer;
  font-size: 13px;
  line-height: 1.35;
}

.lord-karak-toggle {
  margin-left: 16px;
}

.random-heroes-toggle {
  margin: 0 0 4px;
}

.regent-toggle input {
  width: 18px;
  height: 18px;
  flex-shrink: 0;
  accent-color: var(--color-secondary-hover);
  cursor: pointer;
}

.regent-toggle-text {
  display: flex;
  flex-direction: column;
  color: #b7afd0;
}

.regent-toggle-text strong {
  color: #ece8ff;
}

/* Card swap: the figure steps in from the roster */
.hero-card-enter-active,
.hero-card-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}

.hero-card-enter-from {
  opacity: 0;
  transform: translateY(6px);
}

.hero-card-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
  .hero-tile,
  .hero-card-enter-active,
  .hero-card-leave-active {
    transition: none;
  }
}

@media (max-width: 520px) {
  .hero-roster {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }

  .hero-card {
    flex-direction: column;
    align-items: center;
    text-align: center;
  }

  .hero-skill {
    grid-template-columns: 1fr;
    gap: 2px;
  }
}

.error-message {
  color: #aa4444;
  margin-top: 20px;
}

.loading-indicator {
  margin-top: 20px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 15px;
}

.spinner {
  width: 40px;
  height: 40px;
  position: relative;
}

.spinner-inner {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  border: 4px solid transparent;
  border-top-color: var(--color-primary);
  border-bottom-color: var(--color-primary);
  animation: rotate 1.5s linear infinite;
}

.spinner-inner:before {
  content: '';
  position: absolute;
  top: 3px;
  left: 3px;
  right: 3px;
  bottom: 3px;
  border-radius: 50%;
  border: 3px solid transparent;
  border-top-color: #2196F3;
  border-bottom-color: #2196F3;
  animation: rotate 2s linear infinite reverse;
}

.loading-text {
  color: #557755;
  font-size: 16px;
  font-weight: 400;
}

@keyframes rotate {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

/* Tablet and Mobile Responsive Design */
@media (max-width: 1024px) {
  .main-content {
    justify-content: center;
    padding-right: 20px;
  }
  
  .home-container::before {
    position: fixed;
    width: 100%;
    height: 50%;
    top: 0;
    left: 0;
    transform: none;
    mask-image: linear-gradient(to bottom, black 50%, transparent 100%);
    -webkit-mask-image: linear-gradient(to bottom, black 50%, transparent 100%);
    opacity: 0.3;
  }
}

@media (max-width: 768px) {
  .home-container::before {
    opacity: 0.25;
  }
  
  .title {
    font-size: 36px;
  }
  
  .game-panel {
    padding: 30px 20px;
  }
}

@media (max-width: 480px) {
  .title {
    font-size: 32px;
  }
  
  .subtitle {
    font-size: 14px;
  }
  
  .description {
    font-size: 13px;
  }
  
  .primary-button,
  .secondary-button {
    font-size: 17px;
    padding: 10px 20px;
  }
  
  .game-panel {
    padding: 25px 15px;
  }
}
</style>
