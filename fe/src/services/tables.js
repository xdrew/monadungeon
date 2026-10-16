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
  create: async ({ seats, regent, lordKarak, randomHeroes, turnSeconds }) => (await client.post('/tables', { seats, regent, lordKarak, randomHeroes, turnSeconds })).data,
  list: async () => (await client.get('/tables')).data.tables || [],
  get: async (gameId) => (await client.get(`/tables/${gameId}`)).data,
  /** @returns {Promise<{playerId: string}>} the seat (the same one when the account already sits there) */
  join: async (gameId) => (await client.post(`/tables/${gameId}/join`)).data,
  seatAi: async (gameId) => (await client.post(`/tables/${gameId}/ai`)).data,
  leave: async (gameId) => (await client.post(`/tables/${gameId}/leave`)).data,
  start: async (gameId) => (await client.post(`/tables/${gameId}/start`)).data,
  /** I am at the table again: my away seat is mine from the next turn on */
  present: async (gameId) => (await client.post(`/game/${gameId}/present`)).data
};

/** The time limits a host may choose (seconds; null: none) */
export const TURN_LIMITS = [60, 120, 300, null];
export const DEFAULT_TURN_SECONDS = 120;

/** The server's refusal code ('table.full', 'table.started', …) or null. */
export const tableRefusal = (err) => err?.response?.data?.code || null;
