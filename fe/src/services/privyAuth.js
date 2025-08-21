import { Privy } from '@privy-io/js-sdk';

/**
 * Privy authentication service for Monad Games ID
 * Uses the official Privy JS SDK
 */
class PrivyAuthService {
  constructor() {
    this.privyAppId = import.meta.env.VITE_PRIVY_APP_ID;
    this.privy = null;
    this.isInitialized = false;
    this.user = null;
  }

  /**
   * Initialize Privy SDK
   */
  async initialize() {
    if (this.isInitialized) return;

    try {
      this.privy = new Privy({
        appId: this.privyAppId,
        config: {
          // Use email as primary login method
          loginMethods: ['email', 'wallet'],
          // Customize appearance
          appearance: {
            theme: 'light',
            accentColor: '#9333ea',
            logo: '/assets/monad-logo-black.webp',
            showWalletLoginFirst: false
          },
          // Enable global wallet
          embeddedWallets: {
            createOnLogin: 'users-without-wallets',
            noPromptOnSignature: false
          }
        }
      });

      await this.privy.init();
      this.isInitialized = true;
      
      console.log('Privy SDK initialized successfully');
    } catch (error) {
      console.error('Failed to initialize Privy:', error);
      throw new Error('Privy initialization failed');
    }
  }

  /**
   * Login with Privy
   */
  async login() {
    try {
      await this.initialize();

      // Open Privy login modal
      const user = await this.privy.login();
      
      if (!user) {
        throw new Error('No user returned from Privy login');
      }

      console.log('Privy login successful:', user);
      
      // Store user data
      this.user = user;
      localStorage.setItem('privyUser', JSON.stringify(user));
      
      // Get wallet address
      let walletAddress = null;
      
      // Check for embedded wallet
      if (user.wallet) {
        walletAddress = user.wallet.address;
      } else if (user.linkedAccounts) {
        // Check for linked wallet accounts
        const walletAccount = user.linkedAccounts.find(
          account => account.type === 'wallet'
        );
        if (walletAccount) {
          walletAddress = walletAccount.address;
        }
      }
      
      // If no wallet, create one from email
      if (!walletAddress && user.email) {
        // Generate deterministic address from email
        walletAddress = '0x' + Array.from(user.email.address).reduce((acc, char) => {
          return acc + char.charCodeAt(0).toString(16);
        }, '').padEnd(40, '0').slice(0, 40);
      }

      return {
        success: true,
        user: user,
        walletAddress: walletAddress,
        email: user.email?.address,
        id: user.id
      };
    } catch (error) {
      console.error('Privy login failed:', error);
      return {
        success: false,
        error: error.message
      };
    }
  }

  /**
   * Get current user
   */
  async getCurrentUser() {
    if (this.user) return this.user;
    
    // Try to get from Privy SDK
    if (this.privy && this.privy.authenticated) {
      this.user = this.privy.user;
      return this.user;
    }
    
    // Try to get from localStorage
    const userStr = localStorage.getItem('privyUser');
    if (userStr) {
      try {
        this.user = JSON.parse(userStr);
        return this.user;
      } catch (e) {
        return null;
      }
    }
    
    return null;
  }

  /**
   * Get auth token
   */
  async getAuthToken() {
    if (!this.privy || !this.privy.authenticated) {
      return null;
    }
    
    try {
      const token = await this.privy.getAccessToken();
      return token;
    } catch (error) {
      console.error('Failed to get auth token:', error);
      return null;
    }
  }

  /**
   * Logout
   */
  async logout() {
    localStorage.removeItem('privyUser');
    this.user = null;
    
    if (this.privy) {
      try {
        await this.privy.logout();
      } catch (error) {
        console.error('Privy logout error:', error);
      }
    }
  }

  /**
   * Check if user is authenticated
   */
  isAuthenticated() {
    if (this.privy && this.privy.authenticated) {
      return true;
    }
    return !!localStorage.getItem('privyUser');
  }

  /**
   * Sign message with wallet
   */
  async signMessage(message) {
    if (!this.privy || !this.privy.authenticated) {
      throw new Error('Not authenticated');
    }

    try {
      const signature = await this.privy.signMessage(message);
      return signature;
    } catch (error) {
      console.error('Failed to sign message:', error);
      throw error;
    }
  }
}

export const privyAuth = new PrivyAuthService();