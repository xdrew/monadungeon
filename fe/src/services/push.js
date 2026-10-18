/**
 * Push from the server (Mercure, Server-Sent Events through nginx at /.well-known/mercure).
 * subscribe() returns an unsubscribe function; onState gets 'open' / 'reconnecting' / 'reopened' (the stream is back
 * after an error — also a first connection that failed at first: what was pushed meanwhile is missed, reload).
 */
const HUB = '/.well-known/mercure';
const MAX_RETRY_MS = 15000;

export const topics = {
  game: (gameId) => `karak/game/${gameId}`,
  tables: 'karak/tables'
};

export function subscribe(topicList, onMessage, { onState } = {}) {
  if (typeof EventSource === 'undefined') return () => {};
  const url = new URL(HUB, window.location.origin);
  (Array.isArray(topicList) ? topicList : [topicList]).forEach((topic) => url.searchParams.append('topic', topic));

  let source = null;
  let closed = false;
  let retryMs = 1000;
  let retryTimer = null;
  let wasOpen = false;
  let failed = false;

  const open = () => {
    source = new EventSource(url.toString());
    source.onopen = () => {
      retryMs = 1000;
      onState?.(wasOpen || failed ? 'reopened' : 'open');
      wasOpen = true;
    };
    source.onmessage = (event) => {
      try {
        onMessage(JSON.parse(event.data));
      } catch (err) {
        console.warn('[push] a message that is not JSON:', err?.message || err);
      }
    };
    source.onerror = () => {
      if (closed) return;
      failed = true;
      onState?.('reconnecting');
      // The browser retries by itself while CONNECTING; a CLOSED stream (the hub answered an error) is ours to reopen
      if (source.readyState === EventSource.CLOSED) {
        retryTimer = setTimeout(open, retryMs);
        retryMs = Math.min(retryMs * 2, MAX_RETRY_MS);
      }
    };
  };
  open();

  return () => {
    closed = true;
    if (retryTimer) clearTimeout(retryTimer);
    source?.close();
  };
}
