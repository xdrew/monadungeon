class MusicService {
  constructor() {
    this.audio = null;
    this.isEnabled = this.loadMusicPreference();
    this.isInitialized = false;
    this.trackUrl = null;
  }

  loadMusicPreference() {
    const saved = localStorage.getItem('musicEnabled');
    return saved === null ? true : saved === 'true';
  }

  saveMusicPreference(enabled) {
    localStorage.setItem('musicEnabled', enabled.toString());
  }

  async init(trackUrl) {
    if (this.isInitialized && this.trackUrl === trackUrl) {
      return;
    }

    this.trackUrl = trackUrl;
    
    if (this.audio) {
      this.audio.pause();
      this.audio.src = '';
      this.audio.load();
    }

    this.audio = new Audio(trackUrl);
    this.audio.loop = true;
    this.audio.volume = 0.175;
    
    this.audio.addEventListener('error', (e) => {
      console.error('Music playback error:', e);
    });

    this.isInitialized = true;

    if (this.isEnabled) {
      await this.play();
    }
  }

  async play() {
    if (!this.audio || !this.isInitialized) {
      console.warn('Music service not initialized');
      return;
    }

    try {
      await this.audio.play();
    } catch (error) {
      console.error('Failed to play music:', error);
      if (error.name === 'NotAllowedError') {
        console.info('Autoplay blocked. Music will start after user interaction.');
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