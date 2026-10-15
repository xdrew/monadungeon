#!/usr/bin/env node
/**
 * Locale check: ru and pl must have exactly the keys of en, the same {placeholders},
 * and plural messages must carry 3 forms in ru/pl where en carries 2 ("one | other").
 * Usage: npm run i18n:check (exit code 1 on any problem).
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const dir = new URL('../src/i18n/locales/', import.meta.url);

// The locale files are ES modules with one default export and no imports
const load = (name) => {
  const source = readFileSync(fileURLToPath(new URL(`${name}.js`, dir)), 'utf8').replace(/export\s+default/, 'module.exports =');
  const sandbox = { module: { exports: {} } };
  vm.runInNewContext(source, sandbox, { filename: `${name}.js` });
  return sandbox.module.exports;
};

const flatten = (obj, prefix = '', out = {}) => {
  for (const [key, value] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (value && typeof value === 'object') flatten(value, path, out);
    else out[path] = String(value);
  }
  return out;
};

const placeholders = (text) => [...new Set([...text.matchAll(/\{(\w+)\}/g)].map((m) => m[1]))].sort().join(',');
const forms = (text) => text.split(/\s\|\s/).length;

const en = flatten(load('en'));
let problems = 0;
const report = (locale, key, message) => {
  problems += 1;
  console.log(`${locale}  ${key}: ${message}`);
};

for (const locale of ['ru', 'pl']) {
  const other = flatten(load(locale));
  for (const key of Object.keys(en)) {
    if (!(key in other)) {
      report(locale, key, 'missing');
      continue;
    }
    if (placeholders(en[key]) !== placeholders(other[key])) {
      report(locale, key, `placeholders {${placeholders(en[key])}} vs {${placeholders(other[key])}}`);
    }
    const enForms = forms(en[key]);
    const expected = enForms === 1 ? 1 : 3;
    if (forms(other[key]) !== expected) {
      report(locale, key, `${forms(other[key])} plural forms, expected ${expected}`);
    }
  }
  for (const key of Object.keys(other)) {
    if (!(key in en)) report(locale, key, 'not in en');
  }
}

for (const [key, text] of Object.entries(en)) {
  if (forms(text) > 2) report('en', key, `${forms(text)} plural forms, expected 2`);
}

console.log(problems ? `\n${problems} problem(s)` : `i18n: ${Object.keys(en).length} keys, ru and pl complete`);
process.exit(problems ? 1 : 0);
