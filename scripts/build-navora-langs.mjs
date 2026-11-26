/**
 * Copy templates/navora/langs/pt → langs/{lang}
 * then apply EN body pack + per-lang chrome, and patch config SITE_LANG.
 *
 *   node scripts/build-navora-langs.mjs
 *   node scripts/build-navora-langs.mjs it
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { LOCALES } from './navora-i18n/locales.mjs';
import { chromePack } from './navora-i18n/chrome.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const LANGS_DIR = path.resolve(__dirname, '..', 'templates', 'navora', 'langs');
const PT_DIR = path.join(LANGS_DIR, 'pt');
const EN_PACK = JSON.parse(fs.readFileSync(path.join(__dirname, 'navora-i18n', 'en.json'), 'utf8'));

const TEXT_EXT = new Set(['.php', '.js', '.css', '.html', '.json', '.xml', '.webmanifest', '.md', '.txt']);

function walkFiles(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) walkFiles(p, out);
    else if (entry.isFile()) out.push(p);
  }
  return out;
}

function isTextFile(filePath) {
  const base = path.basename(filePath);
  if (base === '.htaccess') return true;
  return TEXT_EXT.has(path.extname(filePath).toLowerCase());
}

function copyDir(src, dest) {
  fs.mkdirSync(dest, { recursive: true });
  for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
    const from = path.join(src, entry.name);
    const to = path.join(dest, entry.name);
    if (entry.isDirectory()) copyDir(from, to);
    else {
      fs.mkdirSync(path.dirname(to), { recursive: true });
      fs.copyFileSync(from, to);
    }
  }
}

function escapeRegExp(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function replaceSafe(text, from, to) {
  if (!from || from === to || !text.includes(from)) return { text, count: 0 };
  const looksLikeToken = /^[\p{L}\p{N}'’]+$/u.test(from) && from.length <= 48;
  if (looksLikeToken) {
    const re = new RegExp(`(?<![\\p{L}\\p{N}_])${escapeRegExp(from)}(?![\\p{L}\\p{N}_])`, 'gu');
    let count = 0;
    const next = text.replace(re, () => {
      count += 1;
      return to;
    });
    return { text: next, count };
  }
  const parts = text.split(from);
  return { text: parts.join(to), count: Math.max(0, parts.length - 1) };
}

function applyPack(langDir, pack) {
  const pairs = Object.entries(pack)
    .filter(([from, to]) => from && to && from !== to)
    .sort((a, b) => b[0].length - a[0].length);
  const files = walkFiles(langDir).filter((f) => isTextFile(f) && !f.includes(`${path.sep}integration${path.sep}`));
  let replacements = 0;
  for (const [from, to] of pairs) {
    for (const file of files) {
      const text = fs.readFileSync(file, 'utf8');
      const { text: next, count } = replaceSafe(text, from, to);
      if (count > 0) {
        fs.writeFileSync(file, next, 'utf8');
        replacements += count;
      }
    }
  }
  return replacements;
}

function patchConfig(langDir, loc) {
  const configPath = path.join(langDir, 'includes', 'config.php');
  let text = fs.readFileSync(configPath, 'utf8');
  const patches = [
    [/define\(\s*'SITE_LANG'\s*,\s*'[^']*'\s*\)/, `define('SITE_LANG', '${loc.siteLang}')`],
    [/define\(\s*'CRM_COUNTRY'\s*,\s*'[^']*'\s*\)/, `define('CRM_COUNTRY', '${loc.crmCountry}')`],
    [/define\(\s*'CURRENCY'\s*,\s*'[^']*'\s*\)/, `define('CURRENCY', '${loc.currency}')`],
    [/define\(\s*'FORM_PHONE_COUNTRY'\s*,\s*'[^']*'\s*\)/, `define('FORM_PHONE_COUNTRY', '${loc.phoneCountry}')`],
    [/define\(\s*'FORM_ALLOWED_COUNTRIES'\s*,\s*'[^']*'\s*\)/, `define('FORM_ALLOWED_COUNTRIES', '${loc.phoneCountry}')`],
  ];
  for (const [re, rep] of patches) {
    text = text.replace(re, rep);
  }
  fs.writeFileSync(configPath, text, 'utf8');
}

const only = process.argv[2] ? [process.argv[2]] : Object.keys(LOCALES);

for (const lang of only) {
  const loc = LOCALES[lang];
  if (!loc) throw new Error(`Unknown lang ${lang}`);
  const dest = path.join(LANGS_DIR, lang);
  if (dest !== PT_DIR) {
    fs.rmSync(dest, { recursive: true, force: true });
    copyDir(PT_DIR, dest);
  }
  const chromeHits = lang === 'pt' ? 0 : applyPack(dest, chromePack(lang));
  const enHits = lang === 'pt' ? 0 : applyPack(dest, EN_PACK);
  patchConfig(dest, loc);
  console.log(`${lang}: chrome=${chromeHits} en=${enHits}`);
}
