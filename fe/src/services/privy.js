import PrivyClient from '@privy-io/js-sdk-core';

/**
 * Privy authentication service for Vue 3
 * Using the actual Privy JS SDK Core
 */
class PrivyService {
  constructor() {
    this.privy = null;
    this.appId = import.meta.env.VITE_PRIVY_APP_ID;
    this.user = null;
    this.isReady = false;
    this.isMockMode = !this.appId || this.appId === 'mock-privy-app';
    this.userFetchPromise = null; // Cache for ongoing user fetch requests
    this.userCacheTime = null; // Track when user data was cached
    this.CACHE_DURATION = 60000; // Cache for 1 minute
  }

  /**
   * Initialize Privy service
   */
  async init() {
    if (this.privy) return;

    // If no app ID, use mock mode
    if (this.isMockMode) {
      console.warn('No Privy App ID found, using mock mode. Set VITE_PRIVY_APP_ID in .env');
      this.privy = this.createMockPrivy();
      this.isReady = true;
      return;
    }

    try {
      // Initialize real Privy SDK
      this.privy = new PrivyClient({
        appId: this.appId,
        storage: this.createStorage()
      });

      this.isReady = true;
      console.log('Privy SDK initialized successfully');
      console.log('Available auth methods:', Object.keys(this.privy.auth || {}));
      
      // Check for existing session
      try {
        const token = await this.privy.getAccessToken();
        if (token) {
          this.user = this.privy.user;
          console.log('Found existing Privy session');
        }
      } catch (e) {
        console.log('No existing session found');
      }
    } catch (error) {
      console.error('Failed to initialize Privy SDK:', error);
      // Fallback to mock mode
      console.log('Falling back to mock mode');
      this.privy = this.createMockPrivy();
      this.isReady = true;
    }
  }

  /**
   * Create storage adapter for Privy
   */
  createStorage() {
    return {
      get: (key) => {
        try {
          const value = localStorage.getItem(key);
          return value ? JSON.parse(value) : null;
        } catch {
          return null;
        }
      },
      put: (key, value) => {
        try {
          localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
          console.error('Storage error:', e);
        }
      },
      del: (key) => {
        try {
          localStorage.removeItem(key);
        } catch (e) {
          console.error('Storage error:', e);
        }
      },
      getMany: (keys) => {
        return keys.map(key => {
          try {
            const value = localStorage.getItem(key);
            return value ? JSON.parse(value) : null;
          } catch {
            return null;
          }
        });
      }
    };
  }

  /**
   * Create mock Privy for development/testing
   */
  createMockPrivy() {
    const mockOtpCodes = new Map();
    
    return {
      email: {
        sendCode: async ({ email }) => {
          console.log('Mock Mode: Sending OTP code to', email);
          const code = Math.floor(100000 + Math.random() * 900000).toString();
          mockOtpCodes.set(email, code);
          console.log(`📧 Mock OTP Code: ${code}`);
          setTimeout(() => {
            alert(`Mock OTP Code: ${code}\n\n(In production with real Privy App ID, this would be sent via email)`);
          }, 500);
          return true;
        },
        loginWithCode: async ({ email, code }) => {
          console.log('Mock Mode: Verifying code');
          const expectedCode = mockOtpCodes.get(email);
          if (code === expectedCode || code === '123456') {
            mockOtpCodes.delete(email);
            const user = {
              id: 'mock-' + Date.now(),
              email: { address: email, verified: true },
              createdAt: new Date().toISOString()
            };
            return user;
          }
          throw new Error('Invalid verification code');
        }
      },
      embeddedWallet: {
        create: async () => {
          console.log('Mock Mode: Creating embedded wallet');
          return {
            address: '0x' + Array.from({length: 40}, () => 
              Math.floor(Math.random() * 16).toString(16)
            ).join('')
          };
        }
      },
      getAccessToken: async () => 'mock-token-' + Date.now(),
      logout: async () => {
        console.log('Mock Mode: Logged out');
        this.user = null;
      },
      authenticated: !!this.user,
      user: this.user
    };
  }

  /**
   * Handle email login - send OTP code
   */
  async handleEmailLogin(email) {
    if (!this.privy) await this.init();

    try {
      if (this.privy.auth && this.privy.auth.email && this.privy.auth.email.sendCode) {
        await this.privy.auth.email.sendCode(email);
        console.log('OTP code sent to:', email);
        return {
          success: true,
          email,
          message: this.isMockMode ? 
            'Check the popup for your code (mock mode)' : 
            'Check your email for the verification code'
        };
      }
      throw new Error('Email login not available');
    } catch (error) {
      console.error('Email login error:', error);
      return {
        success: false,
        error: error.message || 'Failed to send verification code'
      };
    }
  }

  /**
   * Verify email OTP code
   */
  async verifyEmailCode(email, code) {
    if (!this.privy) await this.init();

    try {
      if (this.privy.auth && this.privy.auth.email && this.privy.auth.email.loginWithCode) {
        const result = await this.privy.auth.email.loginWithCode(email, code);
        
        console.log('Privy login result:', result);
        
        // Store refresh token if provided
        if (result.refresh_token) {
          localStorage.setItem('privy:refresh_token', result.refresh_token);
          console.log('Stored refresh token');
        }
        
        // Extract user from the result and normalize the structure
        const user = result.user || result;
        
        console.log('Extracted user:', user);
        
        // Normalize email structure for easier access
        if (user.linked_accounts && user.linked_accounts.length > 0) {
          const emailAccount = user.linked_accounts.find(account => account.type === 'email');
          if (emailAccount) {
            user.email = {
              address: emailAccount.address,
              verified: true
            };
            console.log('Normalized email:', user.email);
          }
        }
        
        this.user = user;
        localStorage.setItem('privyUser', JSON.stringify(user));
        console.log('Stored user in localStorage:', JSON.stringify(user));
        console.log('Email verification successful');
        
        // Get wallet address - check for cross_app account with embedded wallets first
        let walletAddress = null;
        let username = null;
        
        // Check for Monad Games ID cross_app account
        if (user.linked_accounts && user.linked_accounts.length > 0) {
          const monadGamesAppId = import.meta.env.VITE_MONAD_GAMES_APP_ID || 'cmd8euall0037le0my79qpz42';
          const crossAppAccount = user.linked_accounts.find(account => 
            account.type === 'cross_app' && 
            account.provider_app?.id === monadGamesAppId
          );
          
          if (crossAppAccount) {
            console.log('Found Monad Games ID cross_app account:', crossAppAccount);
            
            // Get embedded wallet from cross_app account
            if (crossAppAccount.embedded_wallets && crossAppAccount.embedded_wallets.length > 0) {
              walletAddress = crossAppAccount.embedded_wallets[0].address;
              console.log('Found Monad Games ID wallet:', walletAddress);
            }
            
            // Extract username from cross_app account if available
            if (crossAppAccount.username) {
              username = crossAppAccount.username;
              localStorage.setItem('monadUsername', username);
              console.log('Found Monad Games ID username:', username);
            }
          }
        }
        
        // Fallback to wallet or generate one
        if (!walletAddress) {
          if (user.wallet?.address) {
            walletAddress = user.wallet.address;
          } else {
            // For email users, generate a deterministic wallet address
            // This ensures consistency for the same email across sessions
            walletAddress = '0x' + Array.from(email).reduce((acc, char) => {
              return acc + char.charCodeAt(0).toString(16);
            }, '').padEnd(40, '0').slice(0, 40);
            console.log('Generated wallet address for email user:', walletAddress);
          }
        }
        
        // Set username from email if not found in cross_app
        if (!username && email) {
          username = email.split('@')[0];
          localStorage.setItem('monadUsername', username);
          console.log('Using email username:', username);
        }

        return {
          success: true,
          user,
          walletAddress,
          email,
          username,
          refresh_token: result.refresh_token || localStorage.getItem('privy:refresh_token')
        };
      }
      throw new Error('Email verification not available');
    } catch (error) {
      console.error('Email verification error:', error);
      return {
        success: false,
        error: error.message || 'Invalid verification code'
      };
    }
  }


  /**
   * Get current authenticated user - fetch fresh from Privy with caching
   */
  async getUser() {
    if (!this.privy) {
      await this.init();
    }

    // Check if cached user is still valid
    const now = Date.now();
    const isCacheValid = this.user && 
                        this.userCacheTime && 
                        (now - this.userCacheTime) < this.CACHE_DURATION;

    if (isCacheValid && this.userFetchPromise === null) {
      console.log('Using cached user data (age:', Math.round((now - this.userCacheTime) / 1000), 'seconds)');
      return this.user;
    }

    // If there's already a fetch in progress, wait for it
    if (this.userFetchPromise) {
      console.log('User fetch already in progress, waiting...');
      return await this.userFetchPromise;
    }

    // Check if we have an active Privy session
    const privyUser = this.privy?.user;
    
    if (privyUser) {
      console.log('Found Privy session, fetching full user profile');
      
      // Cache the promise to prevent duplicate requests
      this.userFetchPromise = this.fetchAndNormalizeUser(privyUser);
      
      try {
        const result = await this.userFetchPromise;
        return result;
      } finally {
        // Clear the promise after completion
        this.userFetchPromise = null;
      }
    }
    
    console.log('No active Privy session found');
    this.user = null;
    this.userCacheTime = null;
    this.userFetchPromise = null;
    return null;
  }

  /**
   * Internal method to fetch and normalize user data
   */
  async fetchAndNormalizeUser(privyUser) {
    try {
      // Fetch full user profile from Privy API
      const accessToken = await this.getAccessToken();
      if (accessToken) {
        const apiResponse = await this.fetchUserProfile(accessToken);
        if (apiResponse && apiResponse.user) {
          console.log('Fetched full user profile:', apiResponse);
          console.log('Extracting user from API response:', apiResponse.user);
          return this.normalizePrivyUser(apiResponse.user);
        }
      }
      
      // Fallback to basic user object if API call fails
      console.log('API fetch failed, using basic user object');
      return this.normalizePrivyUser(privyUser);
    } catch (error) {
      console.error('Error fetching user profile:', error);
      return this.normalizePrivyUser(privyUser);
    }
  }

  /**
   * Fetch full user profile from Privy API
   */
  async fetchUserProfile(accessToken) {
    try {
      const response = await fetch('https://auth.privy.io/api/v1/users/me', {
        headers: {
          'Authorization': `Bearer ${accessToken}`,
          'Content-Type': 'application/json',
          'privy-app-id': this.appId
        }
      });
      
      if (response.ok) {
        const userData = await response.json();
        console.log('Privy API user data:', userData);
        return userData;
      } else {
        console.error('Failed to fetch user profile:', response.status, response.statusText);
        const errorText = await response.text();
        console.error('Error response:', errorText);
        return null;
      }
    } catch (error) {
      console.error('Network error fetching user profile:', error);
      return null;
    }
  }

  /**
   * Get current authenticated user synchronously (for compatibility)
   */
  getUserSync() {
    return this.user;
  }

  /**
   * Normalize Privy user data
   */
  normalizePrivyUser(privyUser) {
    const normalizedUser = { ...privyUser };
    
    // Normalize email structure for easier access
    if (normalizedUser.linked_accounts && normalizedUser.linked_accounts.length > 0) {
      const emailAccount = normalizedUser.linked_accounts.find(account => account.type === 'email');
      if (emailAccount) {
        normalizedUser.email = {
          address: emailAccount.address,
          verified: true
        };
        console.log('Normalized email structure:', normalizedUser.email);
      }
      
      // Check for Monad Games ID cross_app account
      const monadGamesAppId = import.meta.env.VITE_MONAD_GAMES_APP_ID || 'cmd8euall0037le0my79qpz42';
      const crossAppAccount = normalizedUser.linked_accounts.find(account => 
        account.type === 'cross_app' && 
        account.provider_app?.id === monadGamesAppId
      );
      
      if (crossAppAccount) {
        console.log('Found Monad Games ID cross_app account in normalization:', crossAppAccount);
        
        // Store wallet address from cross_app account
        if (crossAppAccount.embedded_wallets && crossAppAccount.embedded_wallets.length > 0) {
          normalizedUser.monadWallet = crossAppAccount.embedded_wallets[0].address;
          localStorage.setItem('walletAddress', normalizedUser.monadWallet);
          console.log('Stored Monad wallet address:', normalizedUser.monadWallet);
        }
        
        // Store username from cross_app account
        if (crossAppAccount.username) {
          normalizedUser.monadUsername = crossAppAccount.username;
          localStorage.setItem('monadUsername', crossAppAccount.username);
          console.log('Stored Monad username:', crossAppAccount.username);
        }
      }
    }
    
    this.user = normalizedUser;
    this.userCacheTime = Date.now(); // Set cache timestamp
    // Store normalized user for reference (but always validate against Privy)
    localStorage.setItem('privyUser', JSON.stringify(normalizedUser));
    
    return normalizedUser;
  }

  /**
   * Get access token
   */
  async getAccessToken() {
    if (!this.privy) await this.init();
    
    try {
      if (this.privy.getAccessToken) {
        return await this.privy.getAccessToken();
      }
      return null;
    } catch (error) {
      console.error('Failed to get access token:', error);
      return null;
    }
  }

  /**
   * Check if authenticated
   */
  async isAuthenticated() {
    const user = await this.getUser();
    return !!user;
  }

  /**
   * Check if authenticated synchronously (for compatibility)
   */
  isAuthenticatedSync() {
    return !!this.user;
  }

  /**
   * Logout
   */
  async logout() {
    console.log('Logging out...');
    
    if (!this.privy) await this.init();

    try {
      if (this.privy.logout) {
        await this.privy.logout();
      }
    } catch (error) {
      console.error('Logout error:', error);
    } finally {
      // Always clear local state regardless of API call success
      this.user = null;
      this.isReady = false;
      this.userFetchPromise = null; // Clear any cached fetch promises
      this.userCacheTime = null; // Clear cache timestamp
      
      // Clear all stored data
      localStorage.removeItem('privyUser');
      localStorage.removeItem('currentPlayerId');
      localStorage.removeItem('walletAddress');
      localStorage.removeItem('email');
      
      // Clear any Privy SDK storage
      Object.keys(localStorage).forEach(key => {
        if (key.includes('privy') || key.includes('auth')) {
          localStorage.removeItem(key);
        }
      });
      
      console.log('Logout completed - all data cleared');
    }
  }

  /**
   * Create embedded wallet
   */
  async createEmbeddedWallet() {
    if (!this.isAuthenticated()) {
      throw new Error('Must be authenticated to create wallet');
    }

    if (!this.privy || !this.privy.embeddedWallet) {
      console.log('Embedded wallet not available - using mock wallet');
      // Return a mock wallet if embedded wallets aren't available
      const mockWallet = {
        address: '0x' + Array.from({length: 40}, () => 
          Math.floor(Math.random() * 16).toString(16)
        ).join(''),
        chainId: 1
      };
      
      if (this.user) {
        this.user.wallet = mockWallet;
        localStorage.setItem('privyUser', JSON.stringify(this.user));
      }
      
      return mockWallet;
    }

    try {
      const wallet = await this.privy.embeddedWallet.create();
      console.log('Embedded wallet created:', wallet);
      
      if (this.user) {
        this.user.wallet = wallet;
        localStorage.setItem('privyUser', JSON.stringify(this.user));
      }
      
      return wallet;
    } catch (error) {
      console.warn('Failed to create embedded wallet, using deterministic address:', error.message);
      
      // Fallback: create a deterministic wallet address
      const user = this.getUser();
      const email = user?.email?.address;
      
      if (email) {
        const deterministicWallet = {
          address: '0x' + Array.from(email).reduce((acc, char) => {
            return acc + char.charCodeAt(0).toString(16);
          }, '').padEnd(40, '0').slice(0, 40),
          chainId: 1
        };
        
        if (this.user) {
          this.user.wallet = deterministicWallet;
          localStorage.setItem('privyUser', JSON.stringify(this.user));
        }
        
        return deterministicWallet;
      }
      
      throw error;
    }
  }
}

// Export singleton instance
export const privyService = new PrivyService();