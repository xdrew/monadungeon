<template>
  <div class="rules-container">
    <MusicToggle />
    <div class="rules-content">
      <div class="rules-header">
        <h1 class="rules-title">📜 {{ t('rules.title', { app: t('common.appName') }) }}</h1>
        <button class="back-button" @click="$router.push('/')">
          ← {{ t('rules.back') }}
        </button>
      </div>

      <!-- Game Overview -->
      <section class="rules-section">
        <h2>🎮 {{ t('rules.overview.title') }}</h2>
        <p>
          {{ t('rules.overview.text', { app: t('common.appName'), dragon: dragonAcc }) }}
        </p>
        <div class="overview-grid">
          <div class="overview-item">
            <span class="overview-icon">👥</span>
            <span>{{ t('rules.overview.players') }}</span>
          </div>
          <div class="overview-item">
            <span class="overview-icon">❤️</span>
            <span>{{ t('rules.overview.hp') }}</span>
          </div>
          <div class="overview-item">
            <span class="overview-icon">👟</span>
            <span>{{ t('rules.overview.moves') }}</span>
          </div>
          <div class="overview-item">
            <span class="overview-icon">🏆</span>
            <span>{{ t('rules.overview.vp') }}</span>
          </div>
        </div>
      </section>

      <!-- Victory Conditions -->
      <section class="rules-section">
        <h2>🏆 {{ t('rules.win.title') }}</h2>
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
            <li>🎁 {{ t('rules.win.chest') }} <strong>{{ t('dialogs.vp', { n: 2 }) }}</strong></li>
            <li>💎 {{ t('rules.win.ruby', { dragon: dragonAcc }) }} <strong>{{ t('dialogs.vp', { n: 3 }) }}</strong></li>
            <li>☠️ {{ t('rules.win.fallen', { fallen: monsterName('fallen', 'acc') }) }} <strong>{{ t('dialogs.vp', { n: 2 }) }}</strong></li>
          </ul>
        </div>
      </section>

      <!-- Monsters -->
      <section class="rules-section">
        <h2>👹 {{ t('rules.monsters.title') }}</h2>
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
              <ThemedImage
                :src="monster.image"
                :emoji="monster.emoji"
                :alt="monster.name"
                :img-class="['monster-image', { 'boss-image': monster.boss }]"
                :emoji-class="['monster-image', 'monster-emoji', { 'boss-image': monster.boss }]"
              />
              <span class="monster-name">{{ monster.name }}<template v-if="monster.boss"> ({{ t('rules.monsters.boss') }})</template></span>
              <span v-if="monster.regent" class="regent-chip" :title="t('lobby.regent')">{{ t('hud.regent') }}</span>
            </div>
            <div class="monster-stats">
              <div class="stat">❤️ {{ t('common.hpCount', monster.hp) }}</div>
              <div class="stat">{{ monster.drop }}</div>
              <div v-if="monster.extra" class="stat">{{ monster.extra }}</div>
            </div>
          </div>
        </div>
      </section>

      <!-- Items & Consumables -->
      <section class="rules-section">
        <h2>🎒 {{ t('rules.items.title') }}</h2>
        
        <div class="items-category">
          <h3>⚔️ {{ t('rules.items.weapons') }}</h3>
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
          <h3>🪄 {{ t('rules.items.spells') }}</h3>
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
          <h3>🗝️ {{ t('rules.items.keys') }}</h3>
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
          <h3>📦 {{ t('rules.items.limits') }}</h3>
          <ul>
            <li>🗝️ {{ t('rules.items.limitKeys') }}</li>
            <li>⚔️ {{ t('rules.items.limitWeapons') }}</li>
            <li>🪄 {{ t('rules.items.limitSpells') }}</li>
            <li>🎁 {{ t('rules.items.limitTreasures') }}</li>
          </ul>
        </div>
      </section>

      <!-- Battle System -->
      <section class="rules-section">
        <h2>⚔️ {{ t('rules.battle.title') }}</h2>
        <div class="battle-flow">
          <div class="battle-step">
            <span class="step-icon">🎲</span>
            <h3>{{ t('rules.battle.roll') }}</h3>
            <p>{{ t('rules.battle.rollText') }}</p>
          </div>
          <div class="battle-step">
            <span class="step-icon">➕</span>
            <h3>{{ t('rules.battle.bonuses') }}</h3>
            <p>{{ t('rules.battle.bonusesText') }}</p>
          </div>
          <div class="battle-step">
            <span class="step-icon">⚖️</span>
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
      <section class="rules-section">
        <h2>✨ {{ t('rules.mechanics.title') }}</h2>
        
        <div class="mechanic">
          <h3>💀 {{ t('rules.mechanics.stunned') }}</h3>
          <p>{{ t('rules.mechanics.stunnedIntro') }}</p>
          <ul>
            <li v-for="n in 3" :key="`stunned-${n}`">{{ t(`rules.mechanics.stunned${n}`) }}</li>
          </ul>
        </div>

        <div class="mechanic">
          <h3>⛲ {{ t('rules.mechanics.fountains') }}</h3>
          <p>{{ t('rules.mechanics.fountainsIntro') }}</p>
          <ul>
            <li v-for="n in 2" :key="`fountain-${n}`">{{ t(`rules.mechanics.fountain${n}`) }}</li>
          </ul>
        </div>

        <div class="mechanic">
          <h3>🌀 {{ t('rules.mechanics.gates') }}</h3>
          <p>{{ t('rules.mechanics.gatesText') }}</p>
        </div>
      </section>

      <!-- Karak: Regent expansion -->
      <section class="rules-section" data-testid="rules-regent">
        <h2>👑 {{ t('rules.regent.title') }}</h2>
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
          <h3>🎲 {{ t('rules.regent.dealing') }}</h3>
          <ul>
            <I18nT scope="global" keypath="rules.regent.dealing1" tag="li">
              <template #toggle><strong>{{ t('lobby.randomHeroes') }}</strong></template>
            </I18nT>
            <li>{{ t('rules.regent.dealing2') }}</li>
            <li>{{ t('rules.regent.dealing3') }}</li>
          </ul>
        </div>

        <div class="mechanic" data-testid="rules-regent-heroes">
          <h3>🦸 {{ t('rules.regent.heroes') }} <span class="regent-chip">{{ t('hud.regent') }}</span></h3>
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
          <h3>👑 {{ t('lobby.lordKarak') }} <span class="regent-chip">{{ t('hud.regent') }}</span></h3>
          <I18nT scope="global" keypath="rules.regent.lordKarakText" tag="p">
            <template #room><strong>{{ t('rules.regent.fifthRoom') }}</strong></template>
          </I18nT>
          <ul>
            <li v-for="n in 3" :key="`lk-${n}`">{{ t(`rules.regent.lk${n}`) }}</li>
          </ul>
        </div>
      </section>

      <!-- Turn Structure -->
      <section class="rules-section">
        <h2>🎯 {{ t('rules.turn.title') }}</h2>
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
      <section class="rules-section">
        <h2>💡 {{ t('rules.tips.title') }}</h2>
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
            <span class="tip-icon"><ThemedImage :src="dragon.image" :emoji="dragon.emoji" :alt="monsterName('dragon')" img-class="monster-image" emoji-class="monster-image monster-emoji" /></span>
            <p>{{ t('rules.tips.dragon', { dragon: dragonAcc }) }}</p>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount } from 'vue';
import { I18nT, useI18n } from 'vue-i18n';
import MusicToggle from '@/components/game/MusicToggle.vue';
import ThemedImage from '@/components/ThemedImage.vue';
import { musicService } from '@/services/musicService';
import { getMonster } from '@/theme';
import { monsterName, itemName, heroName } from '@/i18n/names';

const { t } = useI18n();

// Monster stats from the rulebook; images come from the active theme, names and drops from the locale
const MONSTER_RULES = [
  { key: 'giant_rat', hp: 5, icon: '🗡️' },
  { key: 'giant_spider', hp: 6, icon: '✨' },
  { key: 'mummy', hp: 7, icon: '🔥' },
  { key: 'skeleton_turnkey', hp: 8, icon: '🗝️' },
  { key: 'skeleton_warrior', hp: 9, icon: '🗡️' },
  { key: 'skeleton_king', hp: 10, icon: '🪓' },
  { key: 'fallen', hp: 12, icon: '🎁' },
  { key: 'dragon', hp: 15, icon: '💎', boss: true },
  // Karak: Regent expansion
  { key: 'giant_bat', hp: 6, icon: '🌑', regent: true },
  { key: 'skeleton_mage', hp: 11, icon: '🧊', regent: true }
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
  drop: `${rule.icon} ${t(`rules.monsters.drops.${rule.key}`)}`,
  extra: rule.boss ? `🏁 ${t('rules.monsters.endsGame')}` : null
})));
const dragon = getMonster('dragon');
const dragonAcc = computed(() => monsterName('dragon', 'acc'));

onMounted(() => {
  // Initialize music service with placeholder URL
  // Music continues from home view if already playing
  musicService.init('/music/game-theme.mp3');
});

onBeforeUnmount(() => {
  // Music continues playing when navigating away
});
</script>

<style scoped>
.rules-container {
  min-height: 100vh;
  background: var(--gradient-dark);
  color: #e0e0e0;
  padding: 20px;
}

.rules-content {
  max-width: 1200px;
  margin: 0 auto;
}

.rules-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 40px;
  padding-bottom: 20px;
  border-bottom: 2px solid #ffd700;
}

.rules-title {
  font-size: 3rem;
  text-transform: uppercase;
  background: linear-gradient(45deg, #ffd700, #ffed4e);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  margin: 0;
  text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
}

.back-button {
  background: rgba(255, 215, 0, 0.2);
  border: 2px solid #ffd700;
  color: #ffd700;
  padding: 10px 20px;
  border-radius: 8px;
  font-size: 1rem;
  cursor: pointer;
  transition: all 0.3s;
}

.back-button:hover {
  background: rgba(255, 215, 0, 0.3);
  transform: translateY(-2px);
}

.rules-section {
  background: rgba(0, 0, 0, 0.3);
  border-radius: 12px;
  padding: 30px;
  margin-bottom: 30px;
  border: 1px solid rgba(255, 215, 0, 0.2);
}

.rules-section h2 {
  color: #ffd700;
  font-size: 2rem;
  margin-bottom: 20px;
  border-bottom: 2px solid rgba(255, 215, 0, 0.3);
  padding-bottom: 10px;
}

.rules-section h3 {
  color: #ffed4e;
  font-size: 1.3rem;
  margin: 20px 0 10px;
}

.section-intro {
  font-size: 1.1rem;
  margin-bottom: 20px;
  color: #b0b0b0;
}

/* Overview Grid */
.overview-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 20px;
  margin-top: 20px;
}

.overview-item {
  background: rgba(255, 215, 0, 0.1);
  padding: 15px;
  border-radius: 8px;
  text-align: center;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.overview-icon {
  font-size: 2rem;
}

/* Win Conditions */
.win-conditions {
  display: flex;
  gap: 20px;
  margin-bottom: 20px;
}

.win-step {
  flex: 1;
  background: rgba(255, 215, 0, 0.1);
  padding: 20px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: 15px;
}

.step-number {
  background: #ffd700;
  color: var(--bg-field);
  width: 40px;
  height: 40px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  font-size: 1.5rem;
}

.vp-sources {
  background: rgba(0, 0, 0, 0.3);
  padding: 20px;
  border-radius: 8px;
}

.vp-sources ul {
  list-style: none;
  padding: 0;
}

.vp-sources li {
  padding: 8px 0;
  font-size: 1.1rem;
}

/* Monsters Grid */
.monsters-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
  gap: 20px;
  margin-top: 20px;
}

.regent-chip {
  display: inline-block;
  margin-left: 6px;
  padding: 1px 7px;
  border-radius: 8px;
  font-size: 0.7rem;
  font-weight: 700;
  color: #ffe9c7;
  background: rgba(181, 84, 28, 0.55);
  vertical-align: middle;
}

.inline-marker {
  width: 24px;
  height: 24px;
  vertical-align: middle;
}

.monster-card {
  background: rgba(0, 0, 0, 0.5);
  border: 2px solid rgba(255, 215, 0, 0.3);
  border-radius: 12px;
  padding: 20px;
  transition: all 0.3s;
}

.monster-card:hover {
  transform: translateY(-5px);
  border-color: #ffd700;
  box-shadow: 0 5px 20px rgba(255, 215, 0, 0.3);
}

.monster-card.boss-card {
  grid-column: span 2;
  background: linear-gradient(135deg, rgba(255, 0, 0, 0.1), rgba(0, 0, 0, 0.5));
  border-color: #ff6b6b;
}

.monster-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 15px;
}

.monster-image {
  width: 48px;
  height: 48px;
  image-rendering: pixelated;
  object-fit: contain;
  border: 2px solid rgba(255, 215, 0, 0.3);
  border-radius: 8px;
  padding: 4px;
  background: rgba(0, 0, 0, 0.5);
}

.monster-image.boss-image {
  width: 56px;
  height: 56px;
  border-color: #ff6b6b;
  box-shadow: 0 0 10px rgba(255, 0, 0, 0.5);
}

.monster-image.monster-emoji {
  font-size: 26px;
}

.monster-image.monster-emoji.boss-image {
  font-size: 32px;
}

.monster-name {
  font-size: 1.2rem;
  font-weight: bold;
  color: #ffd700;
}

.monster-stats {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.stat {
  padding: 5px 0;
  font-size: 0.95rem;
}

/* Items */
.items-category {
  margin-bottom: 30px;
}

.items-list {
  display: grid;
  gap: 15px;
  margin-top: 15px;
}

.item {
  background: rgba(30, 30, 30, 0.3);
  padding: 15px;
  border-radius: 8px;
  display: grid;
  grid-template-columns: 48px 150px 1fr;
  align-items: center;
  gap: 15px;
  border: 1px solid #222;
}

.item-icon {
  font-size: 1.5rem;
}

.item-image {
  width: 48px;
  height: 48px;
  image-rendering: pixelated;
  object-fit: contain;
  border: 2px solid rgba(255, 215, 0, 0.2);
  border-radius: 8px;
  padding: 4px;
  background: rgba(0, 0, 0, 0.5);
}

.item-name {
  font-weight: bold;
  color: #ffd700;
}

.item-effect {
  color: #b0b0b0;
}

.inventory-limits {
  background: rgba(255, 215, 0, 0.05);
  padding: 20px;
  border-radius: 8px;
  margin-top: 20px;
}

.inventory-limits ul {
  list-style: none;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 10px;
}

/* Battle System */
.battle-flow {
  display: flex;
  justify-content: space-around;
  margin: 30px 0;
  flex-wrap: wrap;
  gap: 20px;
}

.battle-step {
  text-align: center;
  flex: 1;
  min-width: 150px;
}

.step-icon {
  font-size: 3rem;
  display: block;
  margin-bottom: 10px;
}

.battle-outcomes {
  background: rgba(0, 0, 0, 0.3);
  padding: 20px;
  border-radius: 8px;
}

.outcome {
  padding: 10px;
  margin: 10px 0;
  border-radius: 6px;
}

.outcome.win {
  background: rgba(var(--accent-light-rgb), 0.1);
  border-left: 4px solid var(--color-primary);
}

.outcome.draw {
  background: rgba(255, 255, 0, 0.1);
  border-left: 4px solid #ffff00;
}

.outcome.lose {
  background: rgba(255, 0, 0, 0.1);
  border-left: 4px solid #ff0000;
}

/* Special Mechanics */
.mechanic {
  background: rgba(255, 215, 0, 0.05);
  padding: 20px;
  border-radius: 8px;
  margin-bottom: 20px;
}

.mechanic h3 {
  margin-top: 0;
}

.mechanic ul {
  margin: 10px 0;
  padding-left: 25px;
}

/* Turn Structure */
.turn-info {
  background: rgba(0, 0, 0, 0.3);
  padding: 20px;
  border-radius: 8px;
}

.turn-info ul {
  margin: 10px 0;
  padding-left: 25px;
}

/* Tips */
.tips-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 20px;
  margin-top: 20px;
}

.tip {
  background: rgba(255, 215, 0, 0.1);
  padding: 20px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: 15px;
  border: 1px solid rgba(255, 215, 0, 0.2);
}

.tip-icon {
  font-size: 2rem;
  display: flex;
  align-items: center;
  justify-content: center;
}

.item-tip-image {
  width: 32px;
  height: 32px;
  image-rendering: pixelated;
  object-fit: contain;
}

.tip p {
  margin: 0;
  flex: 1;
}

/* Responsive */
@media (max-width: 768px) {
  .rules-title {
    font-size: 2rem;
  }
  
  .rules-header {
    flex-direction: column;
    gap: 20px;
  }
  
  .win-conditions {
    flex-direction: column;
  }
  
  .monster-card.boss-card {
    grid-column: span 1;
  }
  
  .battle-flow {
    flex-direction: column;
  }
}
</style>