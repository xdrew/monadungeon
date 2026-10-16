import axios from 'axios';

/**
 * Online tables: open one, list the open ones, join by link, seat the AI, leave, start (the host).
 * Every call needs the guest account (the session cookie).
 */
const client = axios.create({
  baseURL: '/api',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  withCredentials: true,
  timeout: 15000
});

export const tablesApi = {
  /** @returns {Promise<{gameId: string, playerId: string}>} */
  create: async ({ seats, regent, lordKarak, randomHeroes }) => (await client.post('/tables', { seats, regent, lordKarak, randomHeroes })).data,
  list: async () => (await client.get('/tables')).data.tables || [],
  get: async (gameId) => (await client.get(`/tables/${gameId}`)).data,
  /** @returns {Promise<{playerId: string}>} the seat (the same one when the account already sits there) */
  join: async (gameId) => (await client.post(`/tables/${gameId}/join`)).data,
  seatAi: async (gameId) => (await client.post(`/tables/${gameId}/ai`)).data,
  leave: async (gameId) => (await client.post(`/tables/${gameId}/leave`)).data,
  start: async (gameId) => (await client.post(`/tables/${gameId}/start`)).data
};

/** The server's refusal code ('table.full', 'table.started', …) or null. */
export const tableRefusal = (err) => err?.response?.data?.code || null;
