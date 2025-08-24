class MusicService {
  constructor() {
    this.audio = null;
    this.isEnabled = this.loadMusicPreference();
    this.isInitialized = false;
    this.trackUrl = null;
    this.autoplayPending = false;
    this.setupAutoplayHandler();
  }

  loadMusicPreference() {
    const saved = localStorage.getItem('musicEnabled');
    const enabled = saved === null ? true : saved === 'true';
    console.log('Loading music preference from localStorage:', saved, '-> enabled:', enabled);
    return enabled;
  }

  saveMusicPreference(enabled) {
    localStorage.setItem('musicEnabled', enabled.toString());
  }

  setupAutoplayHandler() {
    // Set up a one-time event listener for user interaction to resume music
    const resumeMusic = async () => {
      if (this.autoplayPending && this.isEnabled && this.audio) {
        console.log('User interaction detected, attempting to resume music...');
        try {
          // Try to play directly
          await this.audio.play();
          console.log('Music resumed after user interaction');
          this.autoplayPending = false;
          // Remove listeners after successful play
          document.removeEventListener('click', resumeMusic);
          document.removeEventListener('keydown', resumeMusic);
          document.removeEventListener('touchstart', resumeMusic);
        } catch (err) {
          console.error('Still cannot play music:', err);
        }
      }
    };

    // Add listeners for user interaction
    document.addEventListener('click', resumeMusic, { once: false });
    document.addEventListener('keydown', resumeMusic, { once: false });
    document.addEventListener('touchstart', resumeMusic, { once: false });
  }

  async init(trackUrl) {
    // Always reinitialize on init call to handle page refreshes
    console.log('Initializing music service, enabled:', this.isEnabled);
    
    this.trackUrl = trackUrl;
    
    // Clean up any existing audio
    if (this.audio) {
      this.audio.pause();
      this.audio.src = '';
      this.audio.load();
    }

    // Create new audio element
    this.audio = new Audio(trackUrl);
    this.audio.loop = true;
    this.audio.volume = 0.175;
    
    this.audio.addEventListener('error', (e) => {
      console.error('Music playback error:', e);
    });

    this.isInitialized = true;

    // Try to play if music was enabled
    if (this.isEnabled) {
      console.log('Music is enabled, attempting to play...');
      
      // First attempt
      await this.play();
      
      // If autoplay was blocked, try again after page loads
      if (this.autoplayPending) {
        // Try after DOM is ready
        if (document.readyState === 'complete') {
          setTimeout(() => this.retryPlay(), 100);
        } else {
          window.addEventListener('load', () => {
            setTimeout(() => this.retryPlay(), 100);
          }, { once: true });
        }
      }
    } else {
      console.log('Music is disabled, not playing');
    }
  }
  
  async retryPlay() {
    if (this.autoplayPending && this.isEnabled && this.audio) {
      console.log('Retrying music playback after page load...');
      try {
        await this.audio.play();
        console.log('Music started on retry!');
        this.autoplayPending = false;
      } catch (err) {
        console.log('Retry failed, waiting for user interaction');
        // Still blocked, wait for user interaction
      }
    }
  }

  async play() {
    if (!this.audio || !this.isInitialized) {
      console.warn('Music service not initialized');
      return;
    }

    try {
      // Ensure audio is loaded before playing
      if (this.audio.readyState < 2) { // HAVE_CURRENT_DATA
        console.log('Audio not ready, waiting for canplay event...');
        await new Promise((resolve) => {
          this.audio.addEventListener('canplay', resolve, { once: true });
        });
      }
      
      const playPromise = this.audio.play();
      if (playPromise !== undefined) {
        await playPromise;
        console.log('Music started playing successfully');
        this.autoplayPending = false; // Clear pending flag on successful play
      }
    } catch (error) {
      console.error('Failed to play music:', error);
      if (error.name === 'NotAllowedError') {
        console.info('Autoplay blocked. Music will start after user interaction.');
        this.autoplayPending = true; // Set flag to retry on user interaction
      }
    }
  }

  pause() {
    if (this.audio) {
      this.audio.pause();
    }
  }

  toggle() {
    this.isEnabled = !this.isEnabled;
    this.saveMusicPreference(this.isEnabled);

    if (this.isEnabled) {
      this.play();
    } else {
      this.pause();
    }

    return this.isEnabled;
  }

  setVolume(volume) {
    if (this.audio) {
      this.audio.volume = Math.max(0, Math.min(1, volume));
    }
  }

  getEnabled() {
    return this.isEnabled;
  }

  destroy() {
    if (this.audio) {
      this.pause();
      this.audio.src = '';
      this.audio.load();
      this.audio = null;
    }
    this.isInitialized = false;
  }
}

export const musicService = new MusicService();