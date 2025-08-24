<template>
  <div class="home-container">
    <MusicToggle />
    <div class="game-options">
      <div class="monad-logo">
        <img src="/assets/monad-logo-black.webp" alt="Monad" />
      </div>
      <h1 class="title">
        MONADUNGEON
      </h1>
      <h2 class="subtitle">
        Powered by Monad Testnet
      </h2>
      <div class="version-info" style="font-size: 10px; color: #666; margin-top: -10px;">
        Build: {{ buildVersion }}
      </div>
      <p class="description">
        Embark on an epic journey through dangerous dungeons filled with monsters, treasures, and ancient secrets.
      </p>

      <div v-if="authenticatedUser" class="user-info">
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
            v-if="!authenticatedUser"
            class="monad-button"
            @click="loginWithMonad"
            :disabled="loading"
        >
          <img src="/assets/monad-logo-black.webp" alt="" class="monad-icon" />
          Sign in with Monad Games ID
        </button>

        <div v-if="!authenticatedUser" class="divider">
          <span>OR</span>
        </div>

        <button
            class="primary-button"
            @click="createNewGame"
            @mouseover="hoverButton"
            @mouseout="resetButton"
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

  <!-- Privy Authentication Modal -->
  <PrivyAuth
      :show="showPrivyModal"
      @close="showPrivyModal = false"
      @success="handlePrivySuccess"
  />
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { gameApi } from '@/services/api';
import { privyService } from '@/services/privy';
import PrivyAuth from '@/components/PrivyAuth.vue';
import MusicToggle from '@/components/game/MusicToggle.vue';
import { musicService } from '@/services/musicService';

const router = useRouter();
const errorMessage = ref('');
const loading = ref(false);
const loadingMessage = ref('Creating your adventure...');
const authenticatedUser = ref(null);
const showPrivyModal = ref(false);

// Build version for debugging deployment issues
const buildVersion = ref(import.meta.env.VITE_BUILD_VERSION || 'dev');

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
const hoverButton = (event) => {
  event.target.style.backgroundColor = '#45a049';
};

const resetButton = (event) => {
  event.target.style.backgroundColor = '#4CAF50';
};

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
  try {
    loading.value = true;
    errorMessage.value = '';
    loadingMessage.value = 'Creating your adventure...';

    // Call the API to create a new game
    const response = await gameApi.createGame();
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
    await gameApi.joinGame(response.gameId, playerId, privyUserId, playerUsername, walletAddress);
    console.log('Joined game as player ID:', playerId, 'with Privy ID:', privyUserId, 'Username:', playerUsername, 'Wallet:', walletAddress);

    // Add a virtual player as player 2 (use valid UUID)
    const virtualPlayerId = generateRandomId();
    await gameApi.joinGame(response.gameId, virtualPlayerId, null, 'AI Player', null);
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
  padding: 20px;
  background-color: #2a2a2a;
}

.game-options {
  background-color: #333;
  border-radius: 8px;
  padding: 40px;
  box-shadow: 0 0 20px rgba(0,0,0,0.5);
  width: 100%;
  max-width: 500px;
  text-align: center;
  color: white;
}

.title {
  color: #ffd700;
  margin-bottom: 10px;
  font-size: 48px;
  text-shadow: 2px 2px 4px #000;
}

.subtitle {
  color: #f8f8f8;
  margin-bottom: 15px;
  font-style: italic;
}

.description {
  margin-bottom: 30px;
  line-height: 1.6;
  color: #ccc;
}

.actions {
  display: flex;
  flex-direction: column;
  gap: 20px;
  margin-top: 20px;
}


.monad-button {
  background: linear-gradient(135deg, #9333ea 0%, #6b21a8 100%);
  color: white;
  padding: 14px 24px;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  font-size: 18px;
  font-weight: 600;
  transition: all 0.3s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  width: 100%;
  box-shadow: 0 4px 15px rgba(147, 51, 234, 0.3);
}

.monad-button:hover:not(:disabled) {
  background: linear-gradient(135deg, #a855f7 0%, #7c3aed 100%);
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(147, 51, 234, 0.4);
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
  background: linear-gradient(135deg, rgba(147, 51, 234, 0.1) 0%, rgba(107, 33, 168, 0.1) 100%);
  border: 1px solid #9333ea;
  border-radius: 8px;
  padding: 15px;
  margin-bottom: 20px;
  text-align: center;
}

.user-info .welcome {
  color: #fff;
  margin-bottom: 5px;
  font-size: 16px;
}

.user-info .welcome strong {
  color: #a855f7;
}

.user-info .wallet {
  color: #888;
  font-size: 14px;
  font-family: monospace;
  margin-bottom: 10px;
}

.logout-button {
  background: transparent;
  color: #a855f7;
  border: 1px solid #a855f7;
  padding: 6px 12px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  transition: all 0.3s;
}

.logout-button:hover {
  background: #a855f7;
  color: white;
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
  color: #888;
  font-size: 14px;
  text-transform: uppercase;
  letter-spacing: 1px;
}

.primary-button {
  background: linear-gradient(135deg, #7B3FF2 0%, #A78BFA 100%);
  color: white;
  box-shadow: 0 4px 15px rgba(123, 63, 242, 0.3);
  border: none;
  padding: 15px 30px;
  border-radius: 5px;
  cursor: pointer;
  font-size: 18px;
  font-weight: 500;
  transition: background-color 0.2s;
  box-shadow: 0 4px 8px rgba(0,0,0,0.3);
}

.primary-button:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(123, 63, 242, 0.4);
}

.secondary-button {
  background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
  color: #333;
  box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
  border: none;
  padding: 15px 30px;
  border-radius: 5px;
  cursor: pointer;
  font-size: 18px;
  font-weight: 500;
  transition: all 0.2s;
  margin-top: 10px;
}

.secondary-button:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(255, 215, 0, 0.4);
  background: linear-gradient(135deg, #FFA500 0%, #FFD700 100%);
}

.error-message {
  color: #ff6b6b;
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
  border-top-color: #4CAF50;
  border-bottom-color: #4CAF50;
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
  color: #4CAF50;
  font-size: 16px;
  font-weight: 500;
}

@keyframes rotate {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
</style>
