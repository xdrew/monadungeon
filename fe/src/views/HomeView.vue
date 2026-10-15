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

      <!-- Monad Games ID sign-in exists only in the monad theme -->
      <div v-if="theme.monadGamesId && authenticatedUser" class="user-info">
        <p class="welcome">
          Welcome back,
          <strong>{{ getDisplayName() }}</strong>!
        </p>
        <p class="wallet" v-if="getWalletAddress()">
          {{ getWalletAddress().slice(0, 6) }}...{{ getWalletAddress().slice(-4) }}
        </p>
        <button @click="logout" class="logout-button">Logout</button>
      </div>

      <div class="actions">
        <button
            v-if="theme.monadGamesId && !authenticatedUser"
            class="monad-button"
            @click="loginWithMonad"
            :disabled="loading"
        >
          <img src="/assets/monad-logo-black.webp" alt="" class="monad-icon" />
          Sign in with Monad Games ID
        </button>

        <div v-if="theme.monadGamesId && !authenticatedUser" class="divider">
          <span>OR</span>
        </div>

        <!-- Hero selection (hero skills from the Karak board game) -->
        <div class="hero-picker" data-testid="hero-picker">
          <p class="hero-picker-title">Choose your hero</p>

          <!-- roster: one portrait tile per hero; the skills live in the card below -->
          <div class="hero-roster" role="radiogroup" aria-label="Hero">
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
          <transition name="hero-card" mode="out-in">
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
              <strong>👑 Lord Karak variant</strong>
              <span>At the fifth Lord Karak room the weakest hero becomes Lord Karak</span>
            </span>
          </label>
        </div>

        <button
            class="primary-button"
            @click="createNewGame"
            :disabled="loading"
        >
          Play Against AI 🤖
        </button>
        
        <button 
          class="secondary-button leaderboard-button" 
          @click="viewLeaderboard"
          style="display: block !important; visibility: visible !important;"
        >
          View Leaderboard 🏆
        </button>
        
        <button 
          class="secondary-button rules-button" 
          @click="viewRules"
          style="display: block !important; visibility: visible !important;"
        >
          📜 How to Play
        </button>
        <div class="version-info">
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

  <!-- Privy Authentication Modal (monad theme only) -->
  <PrivyAuth
      v-if="theme.monadGamesId"
      :show="showPrivyModal"
      @close="showPrivyModal = false"
      @success="handlePrivySuccess"
  />
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { gameApi } from '@/services/api';
import { privyService } from '@/services/privy';
import PrivyAuth from '@/components/PrivyAuth.vue';
import MusicToggle from '@/components/game/MusicToggle.vue';
import ThemeLogo from '@/components/ThemeLogo.vue';
import ThemedImage from '@/components/ThemedImage.vue';
import { musicService } from '@/services/musicService';
import { theme, getHero } from '@/theme';

const router = useRouter();
const errorMessage = ref('');
const loading = ref(false);
const loadingMessage = ref('Creating your adventure...');
const authenticatedUser = ref(null);
const showPrivyModal = ref(false);

// Build version for debugging deployment issues
const buildVersion = ref(import.meta.env.VITE_BUILD_VERSION || 'dev');

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

// Check for existing authentication on mount
onMounted(async () => {
  // Initialize music service with placeholder URL
  // Replace this with your actual music track URL
  musicService.init('/music/game-theme.mp3');

  // No Monad Games ID in this theme: no OAuth redirect handling, no Privy init
  if (!theme.monadGamesId) {
    console.info('[theme] Monad Games ID sign-in disabled');
    return;
  }

  // First check if we're returning from OAuth redirect
  const urlParams = new URLSearchParams(window.location.search);
  const oauthCode = urlParams.get('privy_oauth_code');
  const oauthState = urlParams.get('privy_oauth_state');
  const oauthProvider = urlParams.get('privy_oauth_provider');

  if (oauthCode && oauthState) {
    // Check if this OAuth code was already processed
    const processedCode = sessionStorage.getItem('processed_oauth_code');
    if (processedCode === oauthCode) {
      console.log('OAuth code already processed, skipping');
      // Just clean up the URL
      const cleanUrl = window.location.pathname;
      window.history.replaceState({}, document.title, cleanUrl);
      return;
    }

    console.log('OAuth redirect detected:', { oauthCode, oauthState, oauthProvider });

    // Mark this code as being processed
    sessionStorage.setItem('processed_oauth_code', oauthCode);

    // We're returning from OAuth redirect
    loading.value = true;
    loadingMessage.value = 'Completing authentication...';

    // Get stored verifier and original state
    const codeVerifier = sessionStorage.getItem('monad_code_verifier');
    const originalStateCode = sessionStorage.getItem('monad_state_code');

    console.log('OAuth completion - Code from URL:', decodeURIComponent(oauthCode));
    console.log('OAuth completion - State from URL:', decodeURIComponent(oauthState));
    console.log('OAuth completion - Original state:', originalStateCode);
    console.log('OAuth completion - Code verifier:', codeVerifier);

    if (codeVerifier && originalStateCode) {
      try {
        // Clean up URL immediately to prevent re-processing
        const cleanUrl = window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);

        // Use the state code from URL that Privy expects
        await completeMonadOAuth(decodeURIComponent(oauthCode), decodeURIComponent(oauthState), codeVerifier);
      } catch (error) {
        console.error('Failed to complete OAuth:', error);
        errorMessage.value = 'Authentication failed. Please try again.';
      }
    } else {
      console.error('Missing code verifier or state in session storage');
      errorMessage.value = 'Session expired. Please try logging in again.';
    }

    // Clean up session storage
    sessionStorage.removeItem('monad_code_verifier');
    sessionStorage.removeItem('monad_state_code');
    sessionStorage.removeItem('processed_oauth_code');
    loading.value = false;
  }

  // Normal authentication check
  try {
    // Check if we have any stored auth data that might be stale
    const storedPrivyUser = localStorage.getItem('privyUser');
    const refreshToken = localStorage.getItem('privy:refresh_token');
    const storedUsername = localStorage.getItem('monadUsername');
    const storedWallet = localStorage.getItem('walletAddress');

    // If we have OAuth user data stored, use it
    if (storedPrivyUser) {
      try {
        const parsedUser = JSON.parse(storedPrivyUser);
        console.log('Found stored user data:', parsedUser);

        // Check if this is a valid OAuth user (has cross_app linked account)
        if (parsedUser.linked_accounts?.some(acc => acc.type === 'cross_app')) {
          authenticatedUser.value = parsedUser;
          console.log('✅ User authenticated from stored OAuth data');
          console.log('Username:', storedUsername, 'Wallet:', storedWallet);
          return;
        }
      } catch (e) {
        console.error('Failed to parse stored user:', e);
      }
    }

    // If no stored OAuth data, check Privy SDK
    if (!storedPrivyUser && !refreshToken) {
      console.log('No stored authentication data found');
      authenticatedUser.value = null;
      return;
    }

    await privyService.init();

    // Try to get fresh user data from Privy
    const user = await privyService.getUser();
    const isAuthenticated = await privyService.isAuthenticated();

    console.log('Mount check - Fresh user from Privy:', user);
    console.log('Mount check - Is authenticated:', isAuthenticated);

    // For OAuth users, the Privy SDK might not return linked_accounts
    // So also check if we have stored OAuth user data
    if (isAuthenticated && storedPrivyUser) {
      try {
        const parsedUser = JSON.parse(storedPrivyUser);
        if (parsedUser.id) {
          authenticatedUser.value = parsedUser;
          console.log('✅ Using stored OAuth user data');
          return;
        }
      } catch (e) {
        console.error('Error parsing stored user:', e);
      }
    }

    // Only clear data if we're really not authenticated
    if (!user && !isAuthenticated) {
      console.log('❌ No valid authentication found - clearing stored data');
      localStorage.removeItem('privyUser');
      localStorage.removeItem('privy:refresh_token');
      localStorage.removeItem('monadUsername');
      localStorage.removeItem('walletAddress');
      authenticatedUser.value = null;
    }
  } catch (error) {
    console.error('Authentication check failed:', error);
    // Don't automatically clear auth data on error - might be temporary
    authenticatedUser.value = null;
  }
});

// Helper functions for display
const getDisplayName = () => {
  // First try to get username from localStorage
  const storedUsername = localStorage.getItem('monadUsername');
  if (storedUsername) {
    return storedUsername;
  }

  // Then check authenticated user object
  if (authenticatedUser.value) {
    // Check for email in various formats
    if (authenticatedUser.value.email?.address) {
      return authenticatedUser.value.email.address;
    }
    if (authenticatedUser.value.email && typeof authenticatedUser.value.email === 'string') {
      return authenticatedUser.value.email;
    }
    // Check linked accounts for email
    if (authenticatedUser.value.linked_accounts) {
      const emailAccount = authenticatedUser.value.linked_accounts.find(acc => acc.type === 'email');
      if (emailAccount?.address) {
        return emailAccount.address;
      }
    }
  }

  return 'User';
};

const getWalletAddress = () => {
  // First try localStorage
  const storedWallet = localStorage.getItem('walletAddress');
  if (storedWallet) {
    return storedWallet;
  }

  // Then check authenticated user
  if (authenticatedUser.value) {
    // Check for wallet in various formats
    if (authenticatedUser.value.wallet?.address) {
      return authenticatedUser.value.wallet.address;
    }
    // Check linked accounts for cross_app wallet
    if (authenticatedUser.value.linked_accounts) {
      const crossApp = authenticatedUser.value.linked_accounts.find(acc => acc.type === 'cross_app');
      if (crossApp?.embedded_wallets?.[0]?.address) {
        return crossApp.embedded_wallets[0].address;
      }
    }
  }

  return null;
};

// Style event handlers
// Logout function
const logout = async () => {
  try {
    // First logout from Privy service
    await privyService.logout();
  } catch (error) {
    console.error('Privy logout error:', error);
  }

  // Clear component state
  authenticatedUser.value = null;
  errorMessage.value = '';

  // Clear all authentication-related localStorage
  localStorage.removeItem('currentPlayerId');
  localStorage.removeItem('humanPlayerId');
  localStorage.removeItem('virtualPlayerId');
  localStorage.removeItem('walletAddress');
  localStorage.removeItem('email');
  localStorage.removeItem('privyUser');
  localStorage.removeItem('monadUsername');
  localStorage.removeItem('privy:refresh_token');

  // Clear all Privy-related storage
  Object.keys(localStorage).forEach(key => {
    if (key.includes('privy') || key.includes('auth') || key.includes('Privy')) {
      localStorage.removeItem(key);
    }
  });

  // Clear sessionStorage as well
  sessionStorage.removeItem('monad_code_verifier');
  sessionStorage.removeItem('monad_state_code');
  Object.keys(sessionStorage).forEach(key => {
    if (key.includes('privy') || key.includes('auth')) {
      sessionStorage.removeItem(key);
    }
  });

  console.log('Logout completed - all data cleared');

  // Force a page refresh to ensure clean state
  window.location.reload();
};

// Login with Monad Games ID
const loginWithMonad = async () => {
  try {
    loading.value = true;
    loadingMessage.value = 'Connecting to Monad Games ID...';
    errorMessage.value = '';

    const monadGamesAppId = import.meta.env.VITE_MONAD_GAMES_APP_ID;

    // Generate code challenge and state for OAuth
    const generateRandomString = (length) => {
      const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
      let result = '';
      for (let i = 0; i < length; i++) {
        result += chars.charAt(Math.floor(Math.random() * chars.length));
      }
      return result;
    };

    // Generate a proper PKCE code verifier (43-128 chars)
    const codeVerifier = generateRandomString(43);
    const stateCode = generateRandomString(43);

    // Helper to create SHA256 hash for PKCE
    const sha256 = async (plain) => {
      if (typeof crypto !== 'undefined' && crypto.subtle && crypto.subtle.digest) {
        const encoder = new TextEncoder();
        const data = encoder.encode(plain);
        const hash = await crypto.subtle.digest('SHA-256', data);
        return btoa(String.fromCharCode(...new Uint8Array(hash)))
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=/g, '');
      } else {
        // Fallback for HTTP
        console.warn('crypto.subtle not available, using base64 fallback');
        return btoa(plain)
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=/g, '');
      }
    };

    // Create proper SHA256 challenge
    const codeChallenge = await sha256(codeVerifier);

    console.log('Generated PKCE:', {
      verifier: codeVerifier,
      verifierLength: codeVerifier.length,
      challenge: codeChallenge,
      state: stateCode
    });

    // Store these for later use
    sessionStorage.setItem('monad_code_verifier', codeVerifier);
    sessionStorage.setItem('monad_state_code', stateCode);

    // Initialize OAuth flow with Monad Games ID
    console.log('Initializing OAuth flow with Monad Games ID...');
    const oauthInitResponse = await fetch('https://auth.privy.io/api/v1/oauth/init', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'privy-app-id': import.meta.env.VITE_PRIVY_APP_ID,
        'privy-client': 'react-auth:2.21.3'
      },
      body: JSON.stringify({
        provider: `privy:${monadGamesAppId}`,
        redirect_to: window.location.href,
        code_challenge: codeChallenge,
        code_challenge_method: 'S256',  // Using SHA256 method
        state_code: stateCode
      }),
      credentials: 'include'
    });

    if (oauthInitResponse.ok) {
      const oauthData = await oauthInitResponse.json();
      console.log('OAuth init response:', oauthData);

      // Check for URL field (the actual field name returned by Privy)
      if (oauthData.url || oauthData.authorization_url) {
        const authUrl = oauthData.url || oauthData.authorization_url;
        console.log('Opening Monad Games ID authorization:', authUrl);

        // Redirect to the authorization URL in the same window
        window.location.href = authUrl;
      } else if (oauthData.authorization_code) {
        // If we got a code directly, user might already be logged in to Monad Games ID
        console.log('Got authorization code directly, completing auth...');
        await completeMonadOAuth(oauthData.authorization_code, stateCode, codeVerifier);
      }
    } else {
      console.error('OAuth init failed:', await oauthInitResponse.text());
      // Fallback to email auth
      showPrivyModal.value = true;
    }
  } catch (error) {
    console.error('Failed to initialize Monad Games ID login:', error);
    errorMessage.value = 'Failed to connect to Monad Games ID. Please try email login.';
    // Fallback to email auth
    showPrivyModal.value = true;
  } finally {
    loading.value = false;
  }
};

// Complete Monad OAuth flow
const completeMonadOAuth = async (authorizationCode, stateCode, codeVerifier) => {
  try {
    loading.value = true;
    loadingMessage.value = 'Completing authentication...';

    const monadGamesAppId = import.meta.env.VITE_MONAD_GAMES_APP_ID;

    console.log('Attempting OAuth authenticate with:', {
      authorization_code: authorizationCode,
      state_code: stateCode,
      provider: `privy:${monadGamesAppId}`
    });

    // PKCE is required, send with code_verifier
    console.log('Sending OAuth authenticate with code_verifier length:', codeVerifier?.length);

    const oauthAuthResponse = await fetch('https://auth.privy.io/api/v1/oauth/authenticate', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'privy-app-id': import.meta.env.VITE_PRIVY_APP_ID,
        'privy-client': 'react-auth:2.21.3'
      },
      body: JSON.stringify({
        authorization_code: authorizationCode,
        state_code: stateCode,
        code_verifier: codeVerifier,
        provider: `privy:${monadGamesAppId}`
      }),
      credentials: 'include'
    });

    if (oauthAuthResponse.ok) {
      const authResult = await oauthAuthResponse.json();
      console.log('OAuth authentication successful:', authResult);

      // Clean URL query parameters after successful auth
      const cleanUrl = window.location.pathname;
      window.history.replaceState({}, document.title, cleanUrl);

      // Store tokens
      if (authResult.refresh_token) {
        localStorage.setItem('privy:refresh_token', authResult.refresh_token);
      }
      if (authResult.privy_access_token) {
        localStorage.setItem('privy:access_token', authResult.privy_access_token);
      }

      // Extract wallet address from cross_app account
      let walletAddress = null;
      if (authResult.user?.linked_accounts) {
        const crossAppAccount = authResult.user.linked_accounts.find(account =>
            account.type === 'cross_app'
        );
        if (crossAppAccount?.embedded_wallets?.[0]) {
          walletAddress = crossAppAccount.embedded_wallets[0].address;
          console.log('Got wallet address from OAuth:', walletAddress);
        }
      }

      // Fetch username from Monad Games ID if we have wallet
      let username = null;
      if (walletAddress) {
        try {
          const checkWalletResponse = await fetch(`https://monad-games-id-site.vercel.app/api/check-wallet?wallet=${walletAddress}`);
          if (checkWalletResponse.ok) {
            const walletData = await checkWalletResponse.json();
            console.log('Monad Games ID wallet data:', walletData);
            if (walletData.hasUsername && walletData.user?.username) {
              username = walletData.user.username;
              localStorage.setItem('monadUsername', username);
              console.log('Got username from Monad Games ID:', username);
            }
          }
        } catch (e) {
          console.log('Could not fetch username from Monad Games ID:', e);
        }
      }

      // Store user data
      if (authResult.user) {
        localStorage.setItem('privyUser', JSON.stringify(authResult.user));
        authenticatedUser.value = authResult.user;
      }

      if (walletAddress) {
        localStorage.setItem('walletAddress', walletAddress);
      }

      // Show success
      errorMessage.value = '';
      console.log('✅ OAuth login successful! Username:', username, 'Wallet:', walletAddress);

      // Don't call handlePrivySuccess here as it might trigger another OAuth flow
      // Just update the UI
      loading.value = false;
    } else {
      const errorText = await oauthAuthResponse.text();
      console.error('OAuth authentication failed:', errorText);

      // Parse error to show user
      try {
        const errorData = JSON.parse(errorText);
        if (errorData.error) {
          errorMessage.value = errorData.error;
        }
      } catch {
        errorMessage.value = 'Authentication failed. Please try again.';
      }
    }
  } catch (error) {
    console.error('Failed to complete OAuth:', error);
    errorMessage.value = 'Failed to complete authentication.';
  } finally {
    loading.value = false;
  }
};

// Handle successful Privy authentication
// Clean up on unmount
onBeforeUnmount(() => {
  // Music will continue playing as user navigates to game
  // Only destroy in GameView when leaving the game completely
});

const handlePrivySuccess = async (result) => {
  try {
    loading.value = true;
    loadingMessage.value = 'Setting up your account...';

    console.log('Login success - Full Result:', JSON.stringify(result, null, 2));
    console.log('Login success - Result.user:', result.user);
    console.log('Login success - Result.privyUser:', result.privyUser);

    // The result might have privyUser instead of user based on monadAuth service
    const privyUser = result.privyUser || result.user;
    console.log('Using privyUser:', privyUser);

    let username = null;
    let walletAddress = result.walletAddress;

    // Step 1: Try to get wallet from result or existing linked accounts
    if (!walletAddress && privyUser?.linked_accounts) {
      // First check if we already have a wallet in linked accounts
      const walletAccount = privyUser.linked_accounts.find(account => account.type === 'wallet');
      if (walletAccount?.address) {
        walletAddress = walletAccount.address;
        console.log('Found existing wallet:', walletAddress);
      }
    }

    // Step 2: If still no wallet, establish cross-app connection with Monad Games ID
    if (!walletAddress) {
      try {
        const monadGamesAppId = import.meta.env.VITE_MONAD_GAMES_APP_ID;

        // Generate code challenge and state for OAuth
        const generateRandomString = (length) => {
          const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
          let result = '';
          for (let i = 0; i < length; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
          }
          return result;
        };

        // Helper to create SHA256 hash for PKCE
        const sha256 = async (plain) => {
          // Check if crypto.subtle is available (requires HTTPS)
          if (typeof crypto !== 'undefined' && crypto.subtle && crypto.subtle.digest) {
            const encoder = new TextEncoder();
            const data = encoder.encode(plain);
            const hash = await crypto.subtle.digest('SHA-256', data);
            return btoa(String.fromCharCode(...new Uint8Array(hash)))
                .replace(/\+/g, '-')
                .replace(/\//g, '_')
                .replace(/=/g, '');
          } else {
            // Fallback: use a simpler hash for development/HTTP environments
            console.warn('crypto.subtle not available (HTTP?), using fallback hash');
            let hash = 0;
            for (let i = 0; i < plain.length; i++) {
              const char = plain.charCodeAt(i);
              hash = ((hash << 5) - hash) + char;
              hash = hash & hash; // Convert to 32bit integer
            }
            const hashStr = Math.abs(hash).toString(36) + plain.length.toString(36);
            return (hashStr + plain.slice(0, 43))
                .slice(0, 43)
                .replace(/\+/g, '-')
                .replace(/\//g, '_')
                .replace(/=/g, '');
          }
        };

        const codeVerifier = generateRandomString(128);
        const stateCode = generateRandomString(43);

        // Create code challenge from verifier using SHA256
        const codeChallenge = await sha256(codeVerifier);

        // Step 2a: Initialize OAuth flow
        console.log('Initializing OAuth flow with Monad Games ID...');
        const oauthInitResponse = await fetch('https://auth.privy.io/api/v1/oauth/init', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'privy-app-id': import.meta.env.VITE_PRIVY_APP_ID,
            'privy-client': 'react-auth:2.21.3'
          },
          body: JSON.stringify({
            provider: `privy:${monadGamesAppId}`,
            redirect_to: window.location.origin,
            code_challenge: codeChallenge,
            state_code: stateCode
          }),
          credentials: 'include'
        });

        if (oauthInitResponse.ok) {
          const oauthData = await oauthInitResponse.json();
          console.log('OAuth init response:', oauthData);

          // If we get an authorization URL, we might need to open it
          if (oauthData.authorization_url) {
            // For now, log it - in production, might need to handle popup/redirect
            console.log('Authorization URL:', oauthData.authorization_url);
          }

          // If we got an authorization code, authenticate immediately
          if (oauthData.authorization_code) {
            // Step 2b: Complete OAuth authentication
            console.log('Completing OAuth authentication...');
            const oauthAuthResponse = await fetch('https://auth.privy.io/api/v1/oauth/authenticate', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'privy-app-id': import.meta.env.VITE_PRIVY_APP_ID,
                'privy-client': 'react-auth:2.21.3'
              },
              body: JSON.stringify({
                authorization_code: oauthData.authorization_code,
                state_code: stateCode,
                code_verifier: codeVerifier,
                provider: `privy:${monadGamesAppId}`
              }),
              credentials: 'include'
            });

            if (oauthAuthResponse.ok) {
              const authResult = await oauthAuthResponse.json();
              console.log('OAuth authentication successful:', authResult);

              // Store tokens if provided
              if (authResult.refresh_token) {
                localStorage.setItem('privy:refresh_token', authResult.refresh_token);
              }
              if (authResult.privy_access_token) {
                // Update access token if needed
                console.log('Got new access token from OAuth');
              }
            }
          }
        }

        // Step 2c: Now refresh session to get the cross_app linked account
        const accessToken = await privyService.getAccessToken();
        const refreshToken = localStorage.getItem('privy:refresh_token') || result.refresh_token;

        if (accessToken && refreshToken) {
          console.log('Refreshing session to get cross-app account...');
          const sessionResponse = await fetch('https://auth.privy.io/api/v1/sessions', {
            method: 'POST',
            headers: {
              'Authorization': `Bearer ${accessToken}`,
              'Content-Type': 'application/json',
              'privy-app-id': import.meta.env.VITE_PRIVY_APP_ID
            },
            body: JSON.stringify({
              refresh_token: refreshToken
            })
          });

          if (sessionResponse.ok) {
            const sessionData = await sessionResponse.json();
            console.log('Session data after OAuth:', sessionData);

            // Store the new refresh token if provided
            if (sessionData.refresh_token) {
              localStorage.setItem('privy:refresh_token', sessionData.refresh_token);
            }

            // Look for cross_app account to get wallet address
            if (sessionData.user?.linked_accounts) {
              const crossAppAccount = sessionData.user.linked_accounts.find(account =>
                  account.type === 'cross_app' &&
                  (account.provider_app_id === monadGamesAppId ||
                      account.provider_app?.id === monadGamesAppId)
              );

              if (crossAppAccount?.embedded_wallets?.[0]) {
                walletAddress = crossAppAccount.embedded_wallets[0].address;
                console.log('Found wallet from cross_app account:', walletAddress);
              }
            }
          }
        }
      } catch (e) {
        console.log('Could not establish cross-app connection:', e);
      }
    }

    // Step 2: Check Monad Games ID for username using wallet
    if (walletAddress) {
      try {
        const checkWalletResponse = await fetch(`https://monad-games-id-site.vercel.app/api/check-wallet?wallet=${walletAddress}`);

        if (checkWalletResponse.ok) {
          const walletData = await checkWalletResponse.json();
          console.log('Monad Games ID wallet data:', walletData);

          if (walletData.hasUsername && walletData.user?.username) {
            username = walletData.user.username;
            console.log('Found Monad Games ID username:', username);
          }
        }
      } catch (e) {
        console.log('Could not check Monad Games ID wallet:', e);
      }
    }

    // Fallback: extract from email if still no username
    if (!username && (result.email || privyUser?.email?.address)) {
      const emailAddress = result.email || privyUser.email.address;
      username = emailAddress.split('@')[0]; // Extract username from email
      console.log('Using email-based username:', username);
    }

    // Store username if found
    if (username) {
      localStorage.setItem('monadUsername', username);
      console.log('Stored username:', username);
    }

    // Update authenticated user
    authenticatedUser.value = privyUser;

    // Store Privy user in localStorage for future use
    if (privyUser) {
      localStorage.setItem('privyUser', JSON.stringify(privyUser));
      console.log('Stored Privy user in localStorage');
    }

    if (walletAddress) {
      localStorage.setItem('walletAddress', walletAddress);
    }
    if (result.email) {
      localStorage.setItem('email', result.email);
    }

    // Close the modal and show success
    showPrivyModal.value = false;
    loadingMessage.value = 'Successfully logged in!';

    // Refresh user data to ensure UI updates
    const user = await privyService.getUser();
    if (user) {
      authenticatedUser.value = user;
    }
  } catch (error) {
    console.error('Failed to complete login:', error);
    errorMessage.value = 'Failed to complete login. Please try again.';
  } finally {
    loading.value = false;
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
    loadingMessage.value = 'Creating your adventure...';

    // Call the API to create a new game
    const response = await gameApi.createGame(null, {
      regent: regentExpansion.value,
      lordKarak: regentExpansion.value && lordKarakVariant.value
    });
    console.log('Game created with ID:', response.gameId);

    // Generate a player ID for the current user
    const playerId = generateRandomId();

    // Store the player ID in local storage for later use
    localStorage.setItem('currentPlayerId', playerId);
    // Store human player ID separately so it doesn't change
    localStorage.setItem('humanPlayerId', playerId);

    // Check if we have a Privy user stored (from previous authentication)
    let privyUserId = null;
    const storedPrivyUser = localStorage.getItem('privyUser');
    if (storedPrivyUser) {
      try {
        const privyUser = JSON.parse(storedPrivyUser);
        privyUserId = privyUser.id;
        console.log('Found stored Privy user ID:', privyUserId);
      } catch (e) {
        console.error('Failed to parse stored Privy user:', e);
      }
    }

    // Join the newly created game as player with Privy ID, username, and wallet if available
    const playerUsername = localStorage.getItem('monadUsername') || null;
    const walletAddress = localStorage.getItem('walletAddress') || null;
    // The hero shown in the roster: a Regent hero remembered from an earlier game is dropped when the switch is off
    const heroForGame = selectedCard.value?.key ?? null;
    await gameApi.joinGame(response.gameId, playerId, privyUserId, playerUsername, walletAddress, false, heroForGame);
    console.log('Joined game as player ID:', playerId, 'with Privy ID:', privyUserId, 'Username:', playerUsername, 'Wallet:', walletAddress, 'Hero:', heroForGame);

    // Add a virtual player as player 2 (use valid UUID)
    const virtualPlayerId = generateRandomId();
    await gameApi.joinGame(response.gameId, virtualPlayerId, null, 'AI Player', null, true); // isAi = true
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


.monad-button {
  background: linear-gradient(135deg, #4a1e5c 0%, #3a1545 100%);
  color: #b8b8b8;
  padding: 14px 24px;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  font-size: 18px;
  font-weight: 500;
  transition: all 0.3s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  width: 100%;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
}

.monad-button:hover:not(:disabled) {
  background: linear-gradient(135deg, #5a2470 0%, #451a55 100%);
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.6);
}

.monad-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.monad-icon {
  width: 24px;
  height: 24px;
  filter: brightness(0) invert(1);
}

.user-info {
  background: rgba(30, 30, 30, 0.5);
  border: 1px solid #333;
  border-radius: 8px;
  padding: 15px;
  margin-bottom: 20px;
  text-align: center;
}

.user-info .welcome {
  color: #999;
  margin-bottom: 5px;
  font-size: 16px;
}

.user-info .welcome strong {
  color: #7a4a9a;
}

.user-info .wallet {
  color: #888;
  font-size: 14px;
  font-family: monospace;
  margin-bottom: 10px;
}

.logout-button {
  background: transparent;
  color: #6a4080;
  border: 1px solid #3a2050;
  padding: 6px 12px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  transition: all 0.3s;
}

.logout-button:hover {
  background: #2a1540;
  color: #888;
  border-color: #4a3060;
}

.divider {
  display: flex;
  align-items: center;
  text-align: center;
  margin: 20px 0;
}

.divider::before,
.divider::after {
  content: '';
  flex: 1;
  border-bottom: 1px solid #555;
}

.divider span {
  padding: 0 15px;
  color: #444;
  font-size: 14px;
  text-transform: uppercase;
  letter-spacing: 1px;
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
  font-size: 72px;
  line-height: 132px;
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

.primary-button {
  background: var(--gradient-primary);
  color: var(--text-primary);
  border: 1px solid rgba(var(--accent-light-rgb), 0.35);
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.6);
  border: none;
  padding: 15px 30px;
  border-radius: 5px;
  cursor: pointer;
  font-size: 18px;
  font-weight: 400;
  transition: all 0.2s;
  box-shadow: 0 2px 8px rgba(0,0,0,0.5);
}

.primary-button:hover:not(:disabled) {
  background: var(--gradient-secondary);
  border-color: rgba(var(--accent-light-rgb), 0.6);
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.6);
}

.primary-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  background: linear-gradient(135deg, var(--bg-tertiary) 0%, var(--bg-secondary) 100%);
}

.secondary-button {
  background: linear-gradient(180deg, var(--bg-tertiary) 0%, var(--bg-secondary) 100%);
  color: var(--text-accent);
  border: 1px solid rgba(var(--accent-rgb), 0.45);
  padding: 15px 30px;
  border-radius: 5px;
  cursor: pointer;
  font-size: 18px;
  font-weight: 400;
  transition: all 0.2s;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.6);
}

.secondary-button:hover:not(:disabled) {
  border-color: rgba(var(--accent-light-rgb), 0.7);
  color: var(--text-primary);
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.6);
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
  .secondary-button,
  .monad-button {
    font-size: 16px;
    padding: 12px 20px;
  }
  
  .game-panel {
    padding: 25px 15px;
  }
}
</style>
