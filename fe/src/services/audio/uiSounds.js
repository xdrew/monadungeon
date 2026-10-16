import { audio } from './audioEngine';

/**
 * The interface's sounds, delegated from the document: a click on a button or a link clicks, a
 * checkbox or a switch ticks. `data-sound="none"` on the element or an ancestor opts out (the sound
 * ring, controls with a sound of their own).
 */
const CLICKABLE = 'button, .btn, [role="button"], [role="menuitemradio"], [role="tab"], a[href]';
const TOGGLES = 'input[type="checkbox"], input[type="radio"], [role="switch"]';

export function installUiSounds() {
  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target || target.closest('[data-sound="none"]')) return;
    const toggle = target.closest(TOGGLES);
    if (toggle) {
      if (!toggle.disabled) audio.cue('ui_toggle');
      return;
    }
    const el = target.closest(CLICKABLE);
    if (!el || el.disabled || el.getAttribute('aria-disabled') === 'true') return;
    audio.cue('ui_click');
  }, { passive: true });
}
