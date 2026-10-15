/**
 * Localization: vue-i18n with en (the source), ru and pl.
 * The locale comes from localStorage.locale, else the browser language (ru*, pl*), else en;
 * setLocale() saves the choice and keeps <html lang> and the page title in step.
 * Terms come only from .ai-factory/references/karak-glossary.md.
 */
import { computed } from 'vue';
import { createI18n } from 'vue-i18n';
import en from './locales/en';
import ru from './locales/ru';
import pl from './locales/pl';

export const LOCALES = ['en', 'ru', 'pl'];
const STORAGE_KEY = 'locale';

/**
 * CLDR plural forms for ru and pl as vue-i18n choice indexes. Their messages carry three forms,
 * "one | few | many" (1 крыса · 2 крысы · 5 крыс; 1 ruch · 2 ruchy · 5 ruchów); en keeps "one | other".
 */
const slavicPlural = (locale) => (choice, choicesLength) => {
  const n = Math.abs(Math.trunc(choice));
  if (choicesLength < 3) return n === 1 ? 0 : Math.min(1, choicesLength - 1);
  const mod10 = n % 10;
  const mod100 = n % 100;
  if (locale === 'ru' ? mod10 === 1 && mod100 !== 11 : n === 1) return 0;
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 1;
  return 2;
};

const readStored = () => {
  try {
    const value = localStorage.getItem(STORAGE_KEY);
    return LOCALES.includes(value) ? value : null;
  } catch {
    return null;
  }
};

/** @returns {{ locale: string, source: 'storage'|'browser'|'default' }} */
export const detectLocale = () => {
  const stored = readStored();
  if (stored) return { locale: stored, source: 'storage' };
  const languages = typeof navigator !== 'undefined' ? (navigator.languages?.length ? navigator.languages : [navigator.language]) : [];
  for (const lang of languages) {
    const code = String(lang || '').toLowerCase();
    if (code.startsWith('ru')) return { locale: 'ru', source: 'browser' };
    if (code.startsWith('pl')) return { locale: 'pl', source: 'browser' };
    if (code.startsWith('en')) break;
  }
  return { locale: 'en', source: 'default' };
};

const detected = detectLocale();

export const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: detected.locale,
  fallbackLocale: 'en',
  messages: { en, ru, pl },
  pluralRules: { ru: slavicPlural('ru'), pl: slavicPlural('pl') },
  missingWarn: import.meta.env.DEV,
  fallbackWarn: false,
  warnHtmlMessage: false,
  missing: import.meta.env.DEV ? (locale, key) => console.warn('[i18n] missing key', key, locale) : undefined
});

/** Translate outside components (utils, composables). */
export const t = (...args) => i18n.global.t(...args);
/** Does the key exist in the current locale or the fallback? */
export const te = (key) => i18n.global.te(key) || i18n.global.te(key, 'en');

let titleResolver = () => '';
/** The router registers how the page title is built for the current route. */
export const setTitleResolver = (resolver) => {
  titleResolver = resolver;
  document.title = titleResolver();
};

const applyDocument = (locale) => {
  document.documentElement.lang = locale;
  document.title = titleResolver() || document.title;
};

/** Switch the UI language and remember it. */
export const setLocale = (locale) => {
  if (!LOCALES.includes(locale) || i18n.global.locale.value === locale) return;
  i18n.global.locale.value = locale;
  try {
    localStorage.setItem(STORAGE_KEY, locale);
  } catch {
    // private mode: the choice lasts for this page only
  }
  applyDocument(locale);
  console.debug('[i18n] switch', locale);
};

export const currentLocale = computed(() => i18n.global.locale.value);

applyDocument(detected.locale);
console.debug('[i18n] locale', detected.locale, 'from', detected.source);

export default i18n;
