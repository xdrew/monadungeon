import { gameApi } from './api';
import { privyAuth } from './privyAuth';

/**
 * Monad Games ID authentication service
 * Integrates Privy authentication with the game backend
 */
class MonadAuthService {
  constructor() {
    this.currentUser = null;
  }

  /**
   * Login with Monad Games ID using Privy
   * @returns {Promise<Object>} User data including playerId, username, and wallet address
   */
  async loginWithMonad() {
    try {
      // Open Privy login modal
      const privyResult = await privyAuth.login();
      
      if (!privyResult.success) {
        throw new Error(privyResult.error || 'Authentication failed');
      }

      // Get wallet address and username from Privy result
      let walletAddress = privyResult.walletAddress;
      let username = privyResult.username || privyResult.user?.username;
      
      // Check for cross_app account with Monad Games ID
      if (privyResult.user?.linked_accounts) {
        const monadGamesAppId = import.meta.env.VITE_MONAD_GAMES_APP_ID;
        const crossAppAccount = privyResult.user.linked_accounts.find(account => 
          account.type === 'cross_app' && 
          account.provider_app?.id === monadGamesAppId
        );
        
        if (crossAppAccount) {
          console.log('Found Monad Games ID cross_app account:', crossAppAccount);
          
          // Get embedded wallet from cross_app account
          if (crossAppAccount.embedded_wallets && crossAppAccount.embedded_wallets.length > 0) {
            walletAddress = crossAppAccount.embedded_wallets[0].address;
            console.log('Using Monad Games ID wallet:', walletAddress);
          }
          
          // Get username from cross_app account
          if (crossAppAccount.username) {
            username = crossAppAccount.username;
            console.log('Using Monad Games ID username:', username);
          }
        }
      }
      
      // Fallback: If email login and no wallet, generate a wallet address
      if (!walletAddress && privyResult.user?.email) {
        // For email users, generate a deterministic wallet from email
        const emailAddress = privyResult.user.email.address || privyResult.user.email;
        walletAddress = '0x' + Array.from(emailAddress).reduce((acc, char) => {
          return acc + char.charCodeAt(0).toString(16);
        }, '').padEnd(40, '0').slice(0, 40);
        
        if (!username) {
          username = emailAddress.split('@')[0];
        }
        console.log('Generated fallback wallet and username for email user');
      }
      
      // Check if wallet has a Monad Games ID username
      try {
        const checkResponse = await fetch(`/api/check-wallet?walletAddress=${walletAddress}`);
        if (checkResponse.ok) {
          const checkData = await checkResponse.json();
          if (checkData.hasUsername) {
            username = checkData.username;
          }
        }
      } catch (e) {
        console.log('Could not check wallet username:', e);
      }
      
      // Generate signature for authentication
      let signature;
      try {
        // Try to sign a message with Privy
        const message = `Sign in to Monad Games ID\nTimestamp: ${Date.now()}`;
        signature = await privyAuth.signMessage(message);
      } catch (e) {
        console.log('Could not sign message, using access token:', e);
        // Fallback to using access token or mock signature
        const token = await privyAuth.getAuthToken();
        signature = token || ('0x' + Array.from({length: 130}, () => 
          Math.floor(Math.random() * 16).toString(16)
        ).join(''));
      }

      // Authenticate with backend using Monad Games ID
      const response = await fetch('/api/auth/monad-login', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          walletAddress: walletAddress,
          signature: signature
        })
      });

      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.message || 'Backend authentication failed');
      }

      const authData = await response.json();
      
      // Store player data in localStorage
      localStorage.setItem('currentPlayerId', authData.playerId);
      localStorage.setItem('monadUsername', username || authData.username);
      localStorage.setItem('walletAddress', walletAddress || authData.walletAddress);
      
      this.currentUser = {
        playerId: authData.playerId,
        username: username || authData.username,
        walletAddress: walletAddress || authData.walletAddress,
        privyUser: privyResult.user
      };
      
      return this.currentUser;
    } catch (error) {
      console.error('Monad login failed:', error);
      throw error;
    }
  }

  /**
   * Login and join a specific game
   * @param {string} gameId - The game ID to join after authentication
   * @returns {Promise<Object>} User and game data
   */
  async loginAndJoinGame(gameId) {
    try {
      // First login with Monad Games ID
      const user = await this.loginWithMonad();
      
      // Then join the game with username
      await gameApi.joinGame(gameId, user.playerId, user.walletAddress, user.username);
      
      return {
        ...user,
        gameId: gameId
      };
    } catch (error) {
      console.error('Monad login and join failed:', error);
      throw error;
    }
  }

  /**
   * Get current authenticated user
   * @returns {Object|null} Current user data or null if not authenticated
   */
  getCurrentUser() {
    if (this.currentUser) {
      return this.currentUser;
    }
    
    const playerId = localStorage.getItem('currentPlayerId');
    const username = localStorage.getItem('monadUsername');
    const walletAddress = localStorage.getItem('walletAddress');
    const privyUser = privyAuth.getCurrentUser();
    
    if (!playerId || !walletAddress) {
      return null;
    }
    
    this.currentUser = {
      playerId,
      username,
      walletAddress,
      privyUser
    };
    
    return this.currentUser;
  }

  /**
   * Check if user is authenticated
   */
  isAuthenticated() {
    return privyAuth.isAuthenticated() && !!this.getCurrentUser();
  }

  /**
   * Logout user
   */
  async logout() {
    localStorage.removeItem('currentPlayerId');
    localStorage.removeItem('monadUsername');
    localStorage.removeItem('walletAddress');
    this.currentUser = null;
    
    // Also logout from Privy
    privyAuth.logout();
  }

  /**
   * Submit player score on-chain
   * @param {number} score - The score to submit
   * @param {Object} metadata - Additional game metadata
   */
  async submitScore(score, metadata = {}) {
    const user = this.getCurrentUser();
    if (!user) {
      throw new Error('User not authenticated');
    }

    try {
      const response = await fetch('/api/score/submit', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          walletAddress: user.walletAddress,
          score: score,
          metadata: metadata
        })
      });

      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.message || 'Failed to submit score');
      }

      return await response.json();
    } catch (error) {
      console.error('Score submission failed:', error);
      throw error;
    }
  }
}

// Export singleton instance
export const monadAuth = new MonadAuthService();