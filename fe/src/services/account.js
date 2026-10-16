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
  /** A name to start from ("Wanderer 42"), so the card needs one click. */
  suggestName: () => `${SUGGESTIONS[Math.floor(Math.random() * SUGGESTIONS.length)]} ${Math.floor(10 + Math.random() * 90)}`
};

/** The server's reason for a refused name, or null. */
export const nameError = (err) => (err?.response?.status === 422 ? err.response.data?.message || 'bad name' : null);
