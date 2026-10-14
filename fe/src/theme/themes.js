/**
 * All available themes. Plain ESM without import.meta.env so that both the app
 * (src/theme/index.js) and the Vite config (vite-plugins/themeHtml.js) can import it.
 */
import base from './base';
import monad from './monad';

export const themes = { base, monad };

export const DEFAULT_THEME_NAME = 'base';

/** Normalises any VITE_THEME value to a known theme name. */
export const resolveThemeName = (value) => (value in themes ? value : DEFAULT_THEME_NAME);
