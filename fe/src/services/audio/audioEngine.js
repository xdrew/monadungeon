/**
 * The game's audio: one AudioContext, buses master → music / sfx, music by situation with crossfades,
 * one-shot stings over a ducked loop, sound cues as decoded buffers. Nothing sounds before the first
 * user gesture (browser autoplay rules); what is asked before that is remembered (the music) or dropped
 * (cues). Settings live in localStorage: audio.music, audio.sfx (0–1), audio.muted.
 * In dev builds every cue, track and sting is recorded in window.__audioCues for the e2e.
 */
import { reactive, watch } from 'vue';
import { SFX, STINGS, TRACKS, sfxFiles } from './cues';

const CROSSFADE_S = 1.5;
const DUCK_LEVEL = 0.25; // the loop under a sting
const DEDUPE_MS = 60; // the same cue twice within this is one sound
const KEYS = { music: 'audio.music', sfx: 'audio.sfx', muted: 'audio.muted' };

const storage = {
  get(key) { try { return localStorage.getItem(key); } catch { return null; } },
  set(key, value) { try { localStorage.setItem(key, value); } catch { /* private mode */ } },
  remove(key) { try { localStorage.removeItem(key); } catch { /* private mode */ } },
};

// a missing value (null, '') is the default, not 0: Number(null) is 0
const clamp01 = (v, fallback) => {
  if (v === null || v === undefined || v === '') return fallback;
  const n = Number(v);
  return Number.isFinite(n) ? Math.min(1, Math.max(0, n)) : fallback;
};

function loadSettings() {
  // The first engine read a missing volume as 0 and could store the music at 0: start those from the defaults
  if (storage.get('audio.version') === null) {
    if (storage.get(KEYS.music) === '0') storage.remove(KEYS.music);
    if (storage.get(KEYS.sfx) === '0') storage.remove(KEYS.sfx);
    storage.set('audio.version', '2');
  }
  const music = storage.get(KEYS.music);
  const settings = {
    music: clamp01(music, 0.5),
    sfx: clamp01(storage.get(KEYS.sfx), 0.8),
    muted: storage.get(KEYS.muted) === 'true',
  };
  // The old music switch: off meant no music, read once
  const legacy = storage.get('musicEnabled');
  if (legacy !== null) {
    if (legacy === 'false' && music === null) settings.music = 0;
    storage.remove('musicEnabled');
    storage.set(KEYS.music, String(settings.music));
  }
  return settings;
}

/** The settings, reactive for the sound control; changing them takes effect at once and is kept. */
export const audioSettings = reactive(loadSettings());

const devLog = (kind, id) => {
  if (!import.meta.env.DEV || typeof window === 'undefined') return;
  window.__audioCues = window.__audioCues || [];
  window.__audioCues.push({ kind, id, at: Math.round(performance.now()) });
};

// A slider position to a gain: the ear hears loudness roughly as the square
const toGain = (v) => v * v;

class AudioEngine {
  constructor() {
    this.ctx = null;
    this.unlocked = false;
    this.buffers = new Map(); // url → Promise<AudioBuffer>
    this.lastCue = new Map();
    this.wantedTrack = null; // the situation's track, also before the unlock
    this.slot = null; // { track, index, el, source, gain }
    this.switching = false;
    this.hidden = typeof document !== 'undefined' && document.hidden;
    if (typeof window !== 'undefined') this.listen();
  }

  listen() {
    const unlock = () => this.unlock();
    for (const type of ['pointerdown', 'keydown', 'touchend']) {
      window.addEventListener(type, unlock, { capture: true, passive: true });
    }
    document.addEventListener('visibilitychange', () => {
      this.hidden = document.hidden;
      this.applyDuck(0.6);
    });
    watch(audioSettings, (s) => {
      storage.set(KEYS.music, String(s.music));
      storage.set(KEYS.sfx, String(s.sfx));
      storage.set(KEYS.muted, String(s.muted));
      this.applyVolumes();
      if (s.muted || s.music === 0) this.pauseMusicSoon();
      else this.resumeMusic();
    });
    if (import.meta.env.DEV) window.__audio = this;
  }

  context() {
    if (this.ctx) return this.ctx;
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return null;
    this.ctx = new Ctx();
    this.master = this.ctx.createGain();
    this.musicBus = this.ctx.createGain();
    this.duck = this.ctx.createGain(); // the loop goes through it, stings bypass it
    this.sfxBus = this.ctx.createGain();
    this.duck.connect(this.musicBus);
    this.musicBus.connect(this.master);
    this.sfxBus.connect(this.master);
    this.master.connect(this.ctx.destination);
    this.applyVolumes();
    return this.ctx;
  }

  async unlock() {
    const ctx = this.context();
    if (!ctx) return;
    if (ctx.state !== 'running') {
      try { await ctx.resume(); } catch { return; }
    }
    if (this.unlocked || ctx.state !== 'running') return;
    this.unlocked = true;
    console.info('[audio] unlocked');
    // the sounds are small: decode them all now so the first of each is not late
    for (const id of SFX) sfxFiles(id).forEach((url) => this.buffer(url));
    if (this.wantedTrack) this.startTrack(this.wantedTrack);
  }

  applyVolumes() {
    if (!this.ctx) return;
    const now = this.ctx.currentTime;
    this.master.gain.setTargetAtTime(audioSettings.muted ? 0 : 1, now, 0.05);
    this.musicBus.gain.setTargetAtTime(toGain(audioSettings.music), now, 0.05);
    this.sfxBus.gain.setTargetAtTime(toGain(audioSettings.sfx), now, 0.05);
  }

  applyDuck(seconds = 0.4) {
    if (!this.ctx) return;
    const level = this.hidden ? 0 : (this.stingUntil > this.ctx.currentTime ? DUCK_LEVEL : 1);
    this.duck.gain.setTargetAtTime(level, this.ctx.currentTime, seconds / 3);
  }

  buffer(url) {
    if (!this.buffers.has(url)) {
      const ctx = this.context();
      const p = fetch(url)
        .then((r) => {
          if (!r.ok) throw new Error(`HTTP ${r.status}`);
          return r.arrayBuffer();
        })
        .then((data) => new Promise((resolve, reject) => ctx.decodeAudioData(data, resolve, reject)))
        .catch((err) => {
          console.warn('[audio] cannot decode', url, err?.message || err);
          this.buffers.delete(url);
          return null;
        });
      this.buffers.set(url, p);
    }
    return this.buffers.get(url);
  }

  /** A sound cue by id (see cues.js). */
  cue(id) {
    const now = performance.now();
    if (now - (this.lastCue.get(id) || -Infinity) < DEDUPE_MS) return;
    this.lastCue.set(id, now);
    devLog('cue', id);
    if (!this.unlocked || audioSettings.muted || audioSettings.sfx === 0) return;
    const files = sfxFiles(id);
    const url = files[Math.floor(Math.random() * files.length)];
    this.buffer(url).then((buf) => {
      if (!buf || !this.ctx) return;
      const src = this.ctx.createBufferSource();
      src.buffer = buf;
      // a frequent sound with variants also varies a little in pitch, so a run of them does not grate
      if (files.length > 1) src.playbackRate.value = 0.96 + Math.random() * 0.08;
      src.connect(this.sfxBus);
      src.start();
    });
  }

  /** The situation's loop ('lobby' | 'explore' | 'fight' | 'dragon'), or null for silence. */
  music(track) {
    if (track && !TRACKS[track]) {
      console.warn('[audio] unknown track', track);
      return;
    }
    if (track === this.wantedTrack) return;
    this.wantedTrack = track;
    devLog('music', track);
    console.info('[audio] music →', track);
    if (!this.unlocked) return;
    if (track) this.startTrack(track);
    else this.stopSlot(this.slot);
  }

  startTrack(track, index = 0) {
    if (audioSettings.muted || audioSettings.music === 0) return; // started when the music is turned up
    const ctx = this.context();
    const files = TRACKS[track];
    const el = new Audio(files[index % files.length]);
    el.loop = files.length === 1;
    el.preload = 'auto';
    const source = ctx.createMediaElementSource(el);
    const gain = ctx.createGain();
    gain.gain.value = 0;
    source.connect(gain).connect(this.duck);
    const slot = { track, index, el, source, gain };
    // several files: the next one comes in before this one ends
    if (files.length > 1) {
      el.addEventListener('timeupdate', () => {
        if (this.slot === slot && el.duration && el.duration - el.currentTime < CROSSFADE_S + 0.5) {
          this.startTrack(track, index + 1);
        }
      });
    }
    el.addEventListener('error', () => console.warn('[audio] cannot play', el.src));
    el.play().then(() => {
      gain.gain.setTargetAtTime(1, ctx.currentTime, CROSSFADE_S / 3);
    }).catch((err) => console.warn('[audio] music did not start:', err?.message || err));
    const old = this.slot;
    this.slot = slot;
    this.stopSlot(old);
  }

  stopSlot(slot) {
    if (!slot) return;
    if (this.slot === slot) this.slot = null;
    const ctx = this.ctx;
    slot.gain.gain.setTargetAtTime(0, ctx.currentTime, CROSSFADE_S / 3);
    setTimeout(() => {
      slot.el.pause();
      slot.source.disconnect();
      slot.el.removeAttribute('src');
      slot.el.load();
    }, CROSSFADE_S * 1000 + 300);
  }

  pauseMusicSoon() {
    clearTimeout(this.pauseTimer);
    this.pauseTimer = setTimeout(() => {
      if (audioSettings.muted || audioSettings.music === 0) this.stopSlot(this.slot);
    }, 400);
  }

  resumeMusic() {
    if (this.unlocked && this.wantedTrack && !this.slot) this.startTrack(this.wantedTrack);
  }

  /** A one-shot musical cue ('victory' | 'defeat' | 'game_over' | 'lord_karak') over the ducked loop. */
  sting(id) {
    const url = STINGS[id];
    if (!url) return;
    devLog('sting', id);
    if (!this.unlocked || audioSettings.muted || audioSettings.music === 0) return;
    this.buffer(url).then((buf) => {
      if (!buf || !this.ctx) return;
      const src = this.ctx.createBufferSource();
      src.buffer = buf;
      src.connect(this.musicBus);
      src.start();
      this.stingUntil = this.ctx.currentTime + buf.duration;
      this.applyDuck();
      setTimeout(() => this.applyDuck(1.5), buf.duration * 1000 + 50);
    });
  }

  /**
   * The sound bench (dev page): one exact file now, on the sounds' or the music's bus, regardless of the
   * dedupe. Resolves with { duration, startedAt } (startedAt in this context's clock), or null.
   */
  async preview(url, bus = 'sfx') {
    await this.unlock();
    const buf = await this.buffer(url);
    if (!buf || !this.ctx) return null;
    devLog(bus === 'sfx' ? 'cue' : 'sting', url);
    const src = this.ctx.createBufferSource();
    src.buffer = buf;
    src.connect(bus === 'sfx' ? this.sfxBus : this.musicBus);
    const startedAt = this.ctx.currentTime;
    src.start();
    return { duration: buf.duration, startedAt, buffer: buf };
  }

  /** An analyser on everything that sounds (the bench's level meter), made on first use. */
  meter() {
    const ctx = this.context();
    if (!ctx) return null;
    if (!this.analyser) {
      this.analyser = ctx.createAnalyser();
      this.analyser.fftSize = 256;
      this.master.connect(this.analyser);
    }
    return this.analyser;
  }

  /** For the e2e and the console. */
  state() {
    return {
      unlocked: this.unlocked,
      track: this.wantedTrack,
      playing: !!this.slot && !this.slot.el.paused,
      settings: { ...audioSettings },
    };
  }
}

export const audio = new AudioEngine();
