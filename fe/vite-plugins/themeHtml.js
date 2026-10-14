/**
 * Vite plugin: stamps the active theme into index.html at build/dev time so the page
 * starts with the right <html data-theme>, <title> and manifest (no flash of the wrong theme).
 * The theme name comes from VITE_THEME (see vite.config.js / vite.config.prod.js).
 */
import { themes, resolveThemeName } from '../src/theme/themes.js';

export default function themeHtml(themeName) {
  const name = resolveThemeName(themeName);
  const theme = themes[name];

  return {
    name: 'theme-html',
    transformIndexHtml(html) {
      let result = html;
      result = result.replace(/<html([^>]*)>/, (match, attrs) =>
        attrs.includes('data-theme=')
          ? `<html${attrs.replace(/data-theme="[^"]*"/, `data-theme="${name}"`)}>`
          : `<html${attrs} data-theme="${name}">`
      );
      result = result.replace(/<title>[^<]*<\/title>/, `<title>${theme.pageTitle}</title>`);
      result = result.replace(/<link rel="manifest" href="[^"]*"/, `<link rel="manifest" href="${theme.manifest}"`);
      return result;
    }
  };
}
