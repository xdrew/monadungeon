import { onMounted, watch } from 'vue';
import { audio } from '@/services/audio/audioEngine';

/**
 * The page's music: a track name ('lobby' | 'explore' | 'fight' | 'dragon' | null), or a getter that
 * decides it from the page's state (the game: explore, the fight scene, the dragon). The engine
 * crossfades between them; the page that comes next sets its own.
 */
export function useMusicSituation(track) {
  const current = typeof track === 'function' ? track : () => track;
  onMounted(() => audio.music(current()));
  if (typeof track === 'function') watch(current, (next) => audio.music(next));
}
