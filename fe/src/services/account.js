import axios from 'axios';

/**
 * The guest account behind the session cookie (set by the server, HttpOnly): who plays, by name.
 */
const client = axios.create({
  baseURL: '/api',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  withCredentials: true,
  timeout: 10000
});

const SUGGESTIONS = ['Wanderer', 'Torchbearer', 'Delver', 'Seeker', 'Lantern', 'Ember'];

export const accountApi = {
  /** The account, or null without a session. */
  me: async () => {
    const response = await client.get('/account/me', { validateStatus: (s) => s === 200 || s === 204 });
    return response.status === 200 ? response.data : null;
  },
  /** A guest account with this name; the server sets the cookie. */
  createGuest: async (name) => (await client.post('/account/guest', { name })).data,
  rename: async (name) => (await client.patch('/account/me', { name })).data,
  /** Whether "Keep with Google" can be offered here ({available, fake}). */
  googleStatus: async () => {
    try {
      return (await client.get('/account/google')).data;
    } catch {
      return { available: false, fake: false };
    }
  },
  /**
   * Where the Google button goes; it comes back to returnPath with ?google=bound|signed_in|created|failed.
   * The dev/test fake signs in as localStorage.googleFakeAs ("sub:email") when it is set (the e2e).
   */
  googleStartUrl: (returnPath = '/', status = null) => {
    const params = new URLSearchParams({ return: returnPath });
    if (import.meta.env.DEV && status?.fake) {
      try {
        const as = localStorage.getItem('googleFakeAs');
        if (as) params.set('as', as);
      } catch { /* no storage: the fake's default identity */ }
    }
    return `/api/account/google/start?${params.toString()}`;
  },
  /** This device leaves an account kept with Google. */
  signOut: async () => (await client.post('/account/sign-out')).data,
  /** A name to start from ("Wanderer 42"), so the card needs one click. */
  suggestName: () => `${SUGGESTIONS[Math.floor(Math.random() * SUGGESTIONS.length)]} ${Math.floor(10 + Math.random() * 90)}`
};

/** The server's reason for a refused name, or null. */
export const nameError = (err) => (err?.response?.status === 422 ? err.response.data?.message || 'bad name' : null);
