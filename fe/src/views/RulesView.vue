<template>
  <!-- The rules book: a contents list on the left, one column of text, Torchlight type -->
  <div class="rules-container">
    <div class="rules-tools">
      <LanguageSwitch />
      <MusicToggle />
    </div>
    <header class="rules-header">
      <button
        type="button"
        class="rules-back btn btn--ghost"
        @click="$router.push('/')"
      >
        <GameIcon name="home" :size="18" /> {{ t('rules.back') }}
      </button>
      <h1 class="rules-title">{{ t('rules.title', { app: t('common.appName') }) }}</h1>
    </header>

    <div class="rules-book">
      <nav
        class="rules-toc"
        :aria-label="t('rules.contents')"
      >
        <span class="rules-toc__caption">{{ t('rules.contents') }}</span>
        <ol>
          <li
            v-for="section in SECTIONS"
            :key="section.id"
          >
            <a
              :href="`#${section.id}`"
              :class="{ active: activeSection === section.id }"
              :aria-current="activeSection === section.id ? 'location' : undefined"
              @click.prevent="goTo(section.id)"
            ><GameIcon :name="section.icon" :size="16" /> {{ t(section.title) }}</a>
          </li>
        </ol>
      </nav>

    <div class="rules-content">

      <!-- Game Overview -->
      <section
        id="overview"
        class="rules-section"
      >
        <h2><GameIcon name="explore" :size="24" /> {{ t('rules.overview.title') }}</h2>
        <p>
          {{ t('rules.overview.text', { app: t('common.appName'), dragon: dragonAcc }) }}
        </p>
        <div class="overview-grid">
          <div class="overview-item">
            <span class="overview-icon"><GameIcon name="explore" :size="26" /></span>
            <span>{{ t('rules.overview.players') }}</span>
          </div>
          <div class="overview-item">
            <span class="overview-icon"><GameIcon name="heart" :size="26" /></span>
            <span>{{ t('rules.overview.hp') }}</span>
          </div>
          <div class="overview-item">
            <span class="overview-icon"><GameIcon name="sprint" :size="26" /></span>
            <span>{{ t('rules.overview.moves') }}</span>
          </div>
          <div class="overview-item">
            <span class="overview-icon"><GameIcon name="trophy" :size="26" /></span>
            <span>{{ t('rules.overview.vp') }}</span>
          </div>
        </div>
      </section>

      <!-- Victory Conditions -->
      <section
        id="win"
        class="rules-section"
      >
        <h2><GameIcon name="trophy" :size="24" /> {{ t('rules.win.title') }}</h2>
        <div class="win-conditions">
          <div class="win-step">
            <span class="step-number">1</span>
            <p>{{ t('rules.win.step1', { dragon: dragonAcc }) }}</p>
          </div>
          <div class="win-step">
            <span class="step-number">2</span>
            <p>{{ t('rules.win.step2') }}</p>
          </div>
        </div>
        <div class="vp-sources">
          <h3>{{ t('rules.win.sources') }}</h3>
          <ul>
            <li><img class="inline-marker" src="/images/chest-opened.webp" alt=""> {{ t('rules.win.chest') }} <strong>{{ t('dialogs.vp', { n: 2 }) }}</strong></li>
            <li><img class="inline-marker" src="/images/ruby-chest.webp" alt=""> {{ t('rules.win.ruby', { dragon: dragonAcc }) }} <strong>{{ t('dialogs.vp', { n: 3 }) }}</strong></li>
            <li><GameIcon name="skull" :size="18" class="inline-icon" /> {{ t('rules.win.fallen', { fallen: monsterName('fallen', 'acc') }) }} <strong>{{ t('dialogs.vp', { n: 2 }) }}</strong></li>
          </ul>
        </div>
      </section>

      <!-- Monsters -->
      <section
        id="monsters"
        class="rules-section"
      >
        <h2><GameIcon name="skull" :size="24" /> {{ t('rules.monsters.title') }}</h2>
        <p class="section-intro">
          {{ t('rules.monsters.intro') }}
        </p>
        <div class="monsters-grid">
          <div
            v-for="monster in monsterCards"
            :key="monster.key"
            class="monster-card"
            :class="{ 'boss-card': monster.boss }"
          >
            <div class="monster-header">
              <img
                :src="monster.image"
                :alt="monster.name"
                class="monster-image"
                :class="{ 'boss-image': monster.boss }"
              >
              <span class="monster-name">{{ monster.name }}<template v-if="monster.boss"> ({{ t('rules.monsters.boss') }})</template></span>
              <span v-if="monster.regent" class="regent-chip" :title="t('lobby.regent')">{{ t('hud.regent') }}</span>
            </div>
            <div class="monster-stats">
              <div class="stat stat--hp"><GameIcon name="heart" :size="14" /> {{ t('common.hpCount', monster.hp) }}</div>
              <div class="stat"><img v-if="monster.dropImage" class="inline-marker" :src="monster.dropImage" alt=""> {{ monster.drop }}</div>
              <div v-if="monster.extra" class="stat">{{ monster.extra }}</div>
            </div>
          </div>
        </div>
      </section>

      <!-- Items & Consumables -->
      <section
        id="items"
        class="rules-section"
      >
        <h2><GameIcon name="treasure" :size="24" /> {{ t('rules.items.title') }}</h2>
        
        <div class="items-category">
          <h3><GameIcon name="sword" :size="18" /> {{ t('rules.items.weapons') }}</h3>
          <div class="items-list">
            <div class="item">
              <img class="item-image" src="/images/dagger.webp" :alt="itemName('dagger')" />
              <span class="item-name">{{ itemName('dagger') }}</span>
              <span class="item-effect">{{ t('rules.items.dagger') }}</span>
            </div>
            <div class="item">
              <img class="item-image" src="/images/sword.webp" :alt="itemName('sword')" />
              <span class="item-name">{{ itemName('sword') }}</span>
              <span class="item-effect">{{ t('rules.items.sword') }}</span>
            </div>
            <div class="item">
              <img class="item-image" src="/images/axe.webp" :alt="itemName('axe')" />
              <span class="item-name">{{ itemName('axe') }}</span>
              <span class="item-effect">{{ t('rules.items.axe') }}</span>
            </div>
          </div>
        </div>

        <div class="items-category">
          <h3><GameIcon name="spells" :size="18" /> {{ t('rules.items.spells') }}</h3>
          <div class="items-list">
            <div class="item">
              <img class="item-image" src="/images/fireball.webp" :alt="itemName('fireball')" />
              <span class="item-name">{{ itemName('fireball') }}</span>
              <span class="item-effect">{{ t('rules.items.fireball') }}</span>
            </div>
            <div class="item">
              <img class="item-image" src="/images/hf-teleport.webp" :alt="itemName('teleport')" />
              <span class="item-name">{{ itemName('teleport') }}</span>
              <span class="item-effect">{{ t('rules.items.teleport') }}</span>
            </div>
            <div class="item">
              <img class="item-image" src="/images/frost-fist.webp" :alt="itemName('frost_fist')" />
              <span class="item-name">{{ itemName('frost_fist') }} <span class="regent-chip">{{ t('hud.regent') }}</span></span>
              <span class="item-effect">{{ t('rules.items.frostFist') }}</span>
            </div>
            <div class="item">
              <img class="item-image" src="/images/dark-thorn.webp" :alt="itemName('dark_thorn')" />
              <span class="item-name">{{ itemName('dark_thorn') }} <span class="regent-chip">{{ t('hud.regent') }}</span></span>
              <span class="item-effect">{{ t('rules.items.darkThorn') }}</span>
            </div>
          </div>
        </div>

        <div class="items-category">
          <h3><GameIcon name="key" :size="18" /> {{ t('rules.items.keys') }}</h3>
          <div class="items-list">
            <div class="item">
              <img class="item-image" src="/images/key.webp" :alt="itemName('key')" />
              <span class="item-name">{{ itemName('key') }}</span>
              <span class="item-effect">{{ t('rules.items.key') }}</span>
            </div>
            <div class="item">
              <img class="item-image" src="/images/chest-opened.webp" :alt="itemName('chest')" />
              <span class="item-name">{{ itemName('chest') }}</span>
              <span class="item-effect">{{ t('rules.items.victoryPoints', { n: 2 }, 2) }}</span>
            </div>
            <div class="item">
              <img class="item-image" src="/images/ruby-chest.webp" :alt="itemName('ruby_chest')" />
              <span class="item-name">{{ itemName('ruby_chest') }}</span>
              <span class="item-effect">{{ t('rules.items.victoryPoints', { n: 3 }, 3) }}</span>
            </div>
          </div>
        </div>

        <div class="inventory-limits">
          <h3><GameIcon name="treasure" :size="18" /> {{ t('rules.items.limits') }}</h3>
          <ul>
            <li><GameIcon name="key" :size="16" class="inline-icon" /> {{ t('rules.items.limitKeys') }}</li>
            <li><GameIcon name="sword" :size="16" class="inline-icon" /> {{ t('rules.items.limitWeapons') }}</li>
            <li><GameIcon name="spells" :size="16" class="inline-icon" /> {{ t('rules.items.limitSpells') }}</li>
            <li><GameIcon name="treasure" :size="16" class="inline-icon" /> {{ t('rules.items.limitTreasures') }}</li>
          </ul>
        </div>
      </section>

      <!-- Battle System -->
      <section
        id="battle"
        class="rules-section"
      >
        <h2><GameIcon name="sword" :size="24" /> {{ t('rules.battle.title') }}</h2>
        <div class="battle-flow">
          <div class="battle-step">
            <span class="step-icon"><GameIcon name="dice" :size="28" /></span>
            <h3>{{ t('rules.battle.roll') }}</h3>
            <p>{{ t('rules.battle.rollText') }}</p>
          </div>
          <div class="battle-step">
            <span class="step-icon"><GameIcon name="sword" :size="28" /></span>
            <h3>{{ t('rules.battle.bonuses') }}</h3>
            <p>{{ t('rules.battle.bonusesText') }}</p>
          </div>
          <div class="battle-step">
            <span class="step-icon"><GameIcon name="swap" :size="28" /></span>
            <h3>{{ t('rules.battle.compare') }}</h3>
            <p>{{ t('rules.battle.compareText') }}</p>
          </div>
        </div>

        <div class="battle-outcomes">
          <h3>{{ t('rules.battle.outcomes') }}</h3>
          <div class="outcome win">
            <strong>{{ t('rules.battle.win') }}</strong> {{ t('rules.battle.winText') }}
          </div>
          <div class="outcome draw">
            <strong>{{ t('rules.battle.draw') }}</strong> {{ t('rules.battle.drawText') }}
          </div>
          <div class="outcome lose">
            <strong>{{ t('rules.battle.lose') }}</strong> {{ t('rules.battle.loseText') }}
          </div>
        </div>
      </section>

      <!-- Special Mechanics -->
      <section
        id="mechanics"
        class="rules-section"
      >
        <h2><GameIcon name="spells" :size="24" /> {{ t('rules.mechanics.title') }}</h2>
        
        <div class="mechanic">
          <h3><GameIcon name="skull" :size="18" /> {{ t('rules.mechanics.stunned') }}</h3>
          <p>{{ t('rules.mechanics.stunnedIntro') }}</p>
          <ul>
            <li v-for="n in 3" :key="`stunned-${n}`">{{ t(`rules.mechanics.stunned${n}`) }}</li>
          </ul>
        </div>

        <div class="mechanic">
          <h3><GameIcon name="heart" :size="18" /> {{ t('rules.mechanics.fountains') }}</h3>
          <p>{{ t('rules.mechanics.fountainsIntro') }}</p>
          <ul>
            <li v-for="n in 2" :key="`fountain-${n}`">{{ t(`rules.mechanics.fountain${n}`) }}</li>
          </ul>
        </div>

        <div class="mechanic">
          <h3><GameIcon name="swap" :size="18" /> {{ t('rules.mechanics.gates') }}</h3>
          <p>{{ t('rules.mechanics.gatesText') }}</p>
        </div>
      </section>

      <!-- Karak: Regent expansion -->
      <section
        id="regent"
        class="rules-section"
        data-testid="rules-regent"
      >
        <h2><GameIcon name="crown" :size="24" /> {{ t('rules.regent.title') }}</h2>
        <p class="section-intro">
          {{ t('rules.regent.intro') }}
        </p>

        <div class="mechanic">
          <h3><img class="item-image inline-marker" src="/images/arena.webp" alt="" /> {{ t('field.arena') }}</h3>
          <ul>
            <li v-for="n in 6" :key="`arena-${n}`">{{ t(`rules.regent.arena${n}`) }}</li>
          </ul>
        </div>

        <div class="mechanic">
          <h3><img class="item-image inline-marker" src="/images/cursed-room.webp" alt="" /> {{ t('field.cursedRoom') }}</h3>
          <ul>
            <li v-for="n in 2" :key="`cursed-${n}`">{{ t(`rules.regent.cursed${n}`) }}</li>
          </ul>
        </div>

        <div class="mechanic" data-testid="rules-hero-dealing">
          <h3><GameIcon name="dice" :size="18" /> {{ t('rules.regent.dealing') }}</h3>
          <ul>
            <I18nT scope="global" keypath="rules.regent.dealing1" tag="li">
              <template #toggle><strong>{{ t('lobby.randomHeroes') }}</strong></template>
            </I18nT>
            <li>{{ t('rules.regent.dealing2') }}</li>
            <li>{{ t('rules.regent.dealing3') }}</li>
          </ul>
        </div>

        <div class="mechanic" data-testid="rules-regent-heroes">
          <h3><GameIcon name="crown" :size="18" /> {{ t('rules.regent.heroes') }} <span class="regent-chip">{{ t('hud.regent') }}</span></h3>
          <ul>
            <li v-for="hero in REGENT_HEROES" :key="hero.key">
              <I18nT scope="global" :keypath="`rules.regent.${hero.key}`" tag="span">
                <template #hero><strong>{{ heroName(hero.key) }}</strong></template>
                <template #skill1><em>{{ t(`skills.${hero.skills[0]}.name`) }}</em></template>
                <template #skill2><em>{{ t(`skills.${hero.skills[1]}.name`) }}</em></template>
                <template #healLimit><em>{{ t('hud.ring.healLimit') }}</em></template>
              </I18nT>
            </li>
          </ul>
        </div>

        <div class="mechanic" data-testid="rules-lord-karak">
          <h3><GameIcon name="crown" :size="18" /> {{ t('lobby.lordKarak') }} <span class="regent-chip">{{ t('hud.regent') }}</span></h3>
          <I18nT scope="global" keypath="rules.regent.lordKarakText" tag="p">
            <template #room><strong>{{ t('rules.regent.fifthRoom') }}</strong></template>
          </I18nT>
          <ul>
            <li v-for="n in 3" :key="`lk-${n}`">{{ t(`rules.regent.lk${n}`) }}</li>
          </ul>
        </div>
      </section>

      <!-- Turn Structure -->
      <section
        id="turn"
        class="rules-section"
      >
        <h2><GameIcon name="hourglass" :size="24" /> {{ t('rules.turn.title') }}</h2>
        <div class="turn-info">
          <I18nT scope="global" keypath="rules.turn.moves" tag="p">
            <template #actions><strong>{{ t('rules.turn.actions') }}</strong></template>
          </I18nT>
          <h3>{{ t('rules.turn.canTake') }}</h3>
          <ul>
            <li v-for="n in 6" :key="`action-${n}`">{{ t(`rules.turn.a${n}`) }}</li>
          </ul>
          
          <h3>{{ t('rules.turn.endsWhen') }}</h3>
          <ul>
            <li v-for="n in 5" :key="`end-${n}`">{{ t(`rules.turn.e${n}`) }}</li>
          </ul>
        </div>
      </section>

      <!-- Quick Tips -->
      <section
        id="tips"
        class="rules-section"
      >
        <h2><GameIcon name="eye" :size="24" /> {{ t('rules.tips.title') }}</h2>
        <div class="tips-grid">
          <div class="tip">
            <span class="tip-icon"><img class="item-tip-image" src="/images/key.webp" :alt="itemName('key')" /></span>
            <p>{{ t('rules.tips.key') }}</p>
          </div>
          <div class="tip">
            <span class="tip-icon"><img class="item-tip-image" src="/images/axe.webp" :alt="itemName('axe')" /></span>
            <p>{{ t('rules.tips.weapons') }}</p>
          </div>
          <div class="tip">
            <span class="tip-icon"><img class="item-tip-image" src="/images/hf-teleport.webp" :alt="itemName('teleport')" /></span>
            <p>{{ t('rules.tips.healing') }}</p>
          </div>
          <div class="tip">
            <span class="tip-icon"><img class="item-tip-image" :src="dragon.image" :alt="monsterName('dragon')"></span>
            <p>{{ t('rules.tips.dragon', { dragon: dragonAcc }) }}</p>
          </div>
        </div>
      </section>
    </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import { I18nT, useI18n } from 'vue-i18n';
import MusicToggle from '@/components/game/MusicToggle.vue';
import LanguageSwitch from '@/components/ui/LanguageSwitch.vue';
import GameIcon from '@/components/ui/GameIcon.vue';
import { musicService } from '@/services/musicService';
import { getMonster } from '@/theme';
import { monsterName, itemName, heroName } from '@/i18n/names';
import { getItemImage } from '@/utils/itemUtils';

const { t } = useI18n();

// Monster stats from the rulebook; images come from the active theme, names and drops from the locale
const MONSTER_RULES = [
  { key: 'giant_rat', hp: 5, reward: 'dagger' },
  { key: 'giant_spider', hp: 6, reward: 'teleport' },
  { key: 'mummy', hp: 7, reward: 'fireball' },
  { key: 'skeleton_turnkey', hp: 8, reward: 'key' },
  { key: 'skeleton_warrior', hp: 9, reward: 'sword' },
  { key: 'skeleton_king', hp: 10, reward: 'axe' },
  { key: 'fallen', hp: 12, reward: 'chest' },
  { key: 'dragon', hp: 15, reward: 'ruby_chest', boss: true },
  // Karak: Regent expansion
  { key: 'giant_bat', hp: 6, reward: 'dark_thorn', regent: true },
  { key: 'skeleton_mage', hp: 11, reward: 'frost_fist', regent: true }
];

// Karak: Regent heroes and their two skills, in the order of the rules list
const REGENT_HEROES = [
  { key: 'barbarian', skills: ['berserk', 'tireless'] },
  { key: 'battle_mage', skills: ['sword_master', 'lightning_attack'] },
  { key: 'acrobat', skills: ['dagger_throwing', 'sprint'] },
  { key: 'ranger', skills: ['bear_attack', 'listening'] }
];

const monsterCards = computed(() => MONSTER_RULES.map((rule) => ({
  ...rule,
  ...getMonster(rule.key),
  name: monsterName(rule.key),
  drop: t(`rules.monsters.drops.${rule.key}`),
  dropImage: getItemImage({ type: rule.reward }),
  extra: rule.boss ? t('rules.monsters.endsGame') : null
})));
const dragon = getMonster('dragon');
const dragonAcc = computed(() => monsterName('dragon', 'acc'));

// The contents: one entry per section, the section in view is highlighted
const SECTIONS = [
  { id: 'overview', icon: 'explore', title: 'rules.overview.title' },
  { id: 'win', icon: 'trophy', title: 'rules.win.title' },
  { id: 'monsters', icon: 'skull', title: 'rules.monsters.title' },
  { id: 'items', icon: 'treasure', title: 'rules.items.title' },
  { id: 'battle', icon: 'sword', title: 'rules.battle.title' },
  { id: 'mechanics', icon: 'spells', title: 'rules.mechanics.title' },
  { id: 'regent', icon: 'crown', title: 'rules.regent.title' },
  { id: 'turn', icon: 'hourglass', title: 'rules.turn.title' },
  { id: 'tips', icon: 'eye', title: 'rules.tips.title' }
];
const activeSection = ref(SECTIONS[0].id);
// a section picked in the contents stays lit while the page scrolls to it: the last ones never reach
// the upper third, so the observer would light the section above them instead
let pickedUntil = 0;
const goTo = (id) => {
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  activeSection.value = id;
  pickedUntil = Date.now() + 1500;
  history.replaceState(null, '', `#${id}`);
};
let observer = null;
// scrolled to the very end: the last section is the current one
const onScroll = () => {
  if (Date.now() < pickedUntil) return;
  const root = document.scrollingElement || document.documentElement;
  if (root.scrollTop > 0 && root.scrollTop + window.innerHeight >= root.scrollHeight - 2) {
    activeSection.value = SECTIONS[SECTIONS.length - 1].id;
  }
};

onMounted(() => {
  // Music continues from the lobby if it is already playing
  musicService.init('/music/game-theme.mp3');

  // The topmost section crossing the upper third of the screen is the current one
  observer = new IntersectionObserver((entries) => {
    const visible = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
    if (visible.length && Date.now() >= pickedUntil) activeSection.value = visible[0].target.id;
  }, { rootMargin: '0px 0px -66% 0px' });
  window.addEventListener('scroll', onScroll, { passive: true });
  SECTIONS.forEach(({ id }) => {
    const el = document.getElementById(id);
    if (el) observer.observe(el);
  });
  if (location.hash) document.getElementById(location.hash.slice(1))?.scrollIntoView();
});

onBeforeUnmount(() => {
  observer?.disconnect();
  window.removeEventListener('scroll', onScroll);
});
</script>

<style scoped>
/* ===== The rules book ===== */
.rules-container {
  position: relative;
  min-height: 100vh;
  box-sizing: border-box;
  padding: 28px 32px 64px;
  font-family: var(--font-body);
  color: var(--text-primary);
  background-color: var(--bg-field);
  background-image:
    radial-gradient(ellipse at 50% -10%, rgba(var(--ember-rgb), 0.14) 0%, rgba(0, 0, 0, 0) 45%),
    radial-gradient(ellipse at 50% 120%, rgba(60, 35, 20, 0.5) 0%, rgba(0, 0, 0, 0) 60%);
}

.rules-tools {
  position: fixed;
  top: 20px;
  right: 20px;
  z-index: 3;
  display: flex;
  gap: 10px;
}

.rules-header {
  max-width: 1100px;
  margin: 0 auto 28px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  text-align: center;
}

.rules-back {
  align-self: flex-start;
  gap: 8px;
  min-height: 44px;
}

.rules-title {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: clamp(40px, 6vw, 72px);
  line-height: 1;
  color: var(--text-heading);
  text-shadow: 0 0 24px rgba(var(--ember-rgb), 0.4), 0 4px 12px #000;
}

.rules-book {
  max-width: 1100px;
  margin: 0 auto;
  display: grid;
  grid-template-columns: 240px minmax(0, 760px);
  justify-content: center;
  gap: 48px;
}

/* ===== Contents ===== */
.rules-toc {
  position: sticky;
  top: 24px;
  align-self: start;
}

.rules-toc__caption {
  display: block;
  margin-bottom: 10px;
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.3em;
  text-transform: uppercase;
  color: var(--text-accent);
}

.rules-toc ol {
  margin: 0;
  padding: 0;
  list-style: none;
  border-left: 1px solid rgba(234, 220, 192, 0.15);
}

.rules-toc a {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 36px;
  padding: 4px 12px;
  margin-left: -1px;
  border-left: 2px solid transparent;
  color: var(--text-secondary);
  text-decoration: none;
  font-size: 15px;
  transition: color var(--transition-fast), border-color var(--transition-fast);
}

.rules-toc a:hover,
.rules-toc a:focus-visible {
  outline: none;
  color: var(--text-heading);
}

.rules-toc a.active {
  border-left-color: var(--color-primary);
  color: var(--text-heading);
}

.rules-toc a.active :deep(svg) {
  color: var(--color-primary);
}

/* ===== Text ===== */
.rules-content {
  font-size: 17px;
  line-height: 1.6;
}

.rules-section {
  padding: 8px 0 32px;
  margin-bottom: 24px;
  border-bottom: 1px solid rgba(234, 220, 192, 0.1);
  scroll-margin-top: 24px;
}

.rules-section h2 {
  display: flex;
  align-items: center;
  gap: 12px;
  margin: 0 0 14px;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 36px;
  line-height: 1.1;
  color: var(--text-heading);
}

.rules-section h2 :deep(svg) {
  color: var(--color-primary);
}

.rules-section h3 {
  margin: 20px 0 8px;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 24px;
  line-height: 1.15;
  color: var(--text-heading);
}

.rules-section h3 :deep(svg) {
  margin-right: 6px;
  vertical-align: -2px;
  color: var(--text-accent);
}

.rules-section p {
  margin: 0 0 10px;
}

.rules-section ul {
  margin: 0 0 10px;
  padding-left: 22px;
}

.rules-section li {
  margin-bottom: 6px;
}

.rules-section li::marker {
  color: var(--color-secondary);
}

.rules-section strong {
  color: var(--text-heading);
}

.rules-section em {
  color: var(--text-accent);
  font-style: normal;
}

.section-intro {
  font-style: italic;
  color: var(--text-secondary);
}

.inline-marker {
  width: 22px;
  height: 22px;
  object-fit: contain;
  vertical-align: -5px;
}

.inline-icon {
  vertical-align: -3px;
  color: var(--text-accent);
}

.regent-chip {
  display: inline-block;
  padding: 1px 8px;
  border: 1px solid rgba(var(--ember-rgb), 0.5);
  font-family: var(--font-body);
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--text-accent);
  vertical-align: middle;
}

/* overview: four facts in a row */
.overview-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 10px;
  margin-top: 14px;
}

.overview-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border: 1px solid rgba(234, 220, 192, 0.15);
  background: rgba(0, 0, 0, 0.3);
  font-size: 15px;
}

.overview-icon {
  display: inline-flex;
  color: var(--color-primary);
}

/* victory: numbered steps */
.win-conditions {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.win-step {
  display: flex;
  align-items: baseline;
  gap: 14px;
}

.step-number {
  flex: none;
  font-family: var(--font-display);
  font-size: 30px;
  line-height: 1;
  color: var(--color-primary);
}

.vp-sources ul {
  list-style: none;
  padding-left: 0;
}

/* monsters: rows like a bestiary */
.monsters-grid {
  display: flex;
  flex-direction: column;
  margin-top: 10px;
}

.monster-card {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr);
  align-items: center;
  gap: 16px;
  padding: 10px 0;
  border-bottom: 1px solid rgba(234, 220, 192, 0.08);
}

.monster-header {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.monster-image {
  width: 56px;
  height: 56px;
  flex: none;
  object-fit: contain;
  filter: drop-shadow(0 4px 4px #000);
}

.boss-image {
  width: 72px;
  height: 72px;
  filter: drop-shadow(0 0 14px rgba(var(--ember-rgb), 0.5)) drop-shadow(0 4px 4px #000);
}

.monster-name {
  font-weight: 600;
  color: var(--text-heading);
}

.boss-card .monster-name {
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 24px;
  color: var(--text-accent);
}

.monster-stats {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  font-size: 15px;
  color: var(--text-secondary);
}

.stat {
  display: flex;
  align-items: center;
  gap: 6px;
}

.stat--hp {
  color: var(--text-heading);
}

.stat--hp :deep(svg) {
  color: var(--color-danger);
  fill: var(--color-danger);
}

/* items */
.items-list {
  display: flex;
  flex-direction: column;
}

.item {
  display: grid;
  grid-template-columns: 40px minmax(120px, 0.6fr) 1fr;
  align-items: center;
  gap: 12px;
  padding: 6px 0;
  border-bottom: 1px solid rgba(234, 220, 192, 0.08);
}

.item-image {
  width: 36px;
  height: 36px;
  object-fit: contain;
}

.item-name {
  font-weight: 600;
  color: var(--text-heading);
}

.item-effect {
  font-size: 15px;
  color: var(--text-secondary);
}

.inventory-limits ul {
  list-style: none;
  padding-left: 0;
}

/* battle: three steps, then the outcomes */
.battle-flow {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}

.battle-step {
  padding: 12px 14px;
  border: 1px solid rgba(234, 220, 192, 0.15);
  background: rgba(0, 0, 0, 0.3);
}

.battle-step h3 {
  margin-top: 6px;
  font-size: 20px;
}

.battle-step p {
  font-size: 15px;
  color: var(--text-secondary);
}

.step-icon {
  display: inline-flex;
  color: var(--color-primary);
}

.battle-outcomes {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 8px;
}

.outcome {
  padding: 8px 12px;
  border-left: 3px solid var(--iron);
  background: rgba(0, 0, 0, 0.3);
  font-size: 15px;
}

.outcome.win { border-left-color: var(--color-primary); }
.outcome.draw { border-left-color: var(--text-primary); }
.outcome.lose { border-left-color: var(--color-danger); }

.mechanic {
  margin-bottom: 8px;
}

/* tips: image + one line each */
.tips-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
}

.tip {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid rgba(234, 220, 192, 0.15);
  background: rgba(0, 0, 0, 0.3);
}

.tip p {
  margin: 0;
  font-size: 15px;
}

.tip-icon {
  flex: none;
}

.item-tip-image {
  width: 44px;
  height: 44px;
  object-fit: contain;
}

/* ===== Narrow screens: the contents above the text ===== */
@media (max-width: 900px) {
  .rules-container {
    padding: 80px 16px 48px;
  }

  .rules-book {
    grid-template-columns: minmax(0, 1fr);
    gap: 24px;
  }

  .rules-tools {
    position: absolute;
  }

  .rules-section h2 :deep(svg) {
    flex: none;
  }

  .rules-toc {
    position: static;
    padding: 12px 14px;
    border: 1px solid rgba(234, 220, 192, 0.15);
    background: rgba(0, 0, 0, 0.3);
  }

  .rules-toc ol {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    border-left: 0;
  }

  .rules-toc a {
    border-left: 0;
    padding-left: 0;
  }

  .rules-section h2 {
    font-size: 30px;
  }

  .battle-flow,
  .tips-grid {
    grid-template-columns: 1fr;
  }

  .monster-card {
    grid-template-columns: 1fr;
    gap: 6px;
  }

  .item {
    grid-template-columns: 40px 1fr;
    row-gap: 2px;
  }

  .item-image {
    grid-row: span 2;
  }

  .item-effect {
    grid-column: 2;
  }
}
</style>
