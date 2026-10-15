<template>
  <div class="rules-container">
    <MusicToggle />
    <div class="rules-content">
      <div class="rules-header">
        <h1 class="rules-title">📜 {{ theme.appName }} RULES</h1>
        <button class="back-button" @click="$router.push('/')">
          ← Back to Lobby
        </button>
      </div>

      <!-- Game Overview -->
      <section class="rules-section">
        <h2>🎮 Game Overview</h2>
        <p>
          {{ theme.appName }} is a dungeon-crawling adventure where players explore a labyrinth,
          fight monsters, collect treasures, and race to defeat the {{ dragonName }} to claim victory!
        </p>
        <div class="overview-grid">
          <div class="overview-item">
            <span class="overview-icon">👥</span>
            <span>1-4 Players</span>
          </div>
          <div class="overview-item">
            <span class="overview-icon">❤️</span>
            <span>5 Starting HP</span>
          </div>
          <div class="overview-item">
            <span class="overview-icon">👟</span>
            <span>4 Moves per Turn</span>
          </div>
          <div class="overview-item">
            <span class="overview-icon">🏆</span>
            <span>Most VP Wins</span>
          </div>
        </div>
      </section>

      <!-- Victory Conditions -->
      <section class="rules-section">
        <h2>🏆 How to Win</h2>
        <div class="win-conditions">
          <div class="win-step">
            <span class="step-number">1</span>
            <p>Defeat the {{ dragonName }} to end the game</p>
          </div>
          <div class="win-step">
            <span class="step-number">2</span>
            <p>Player with most Victory Points (VP) wins</p>
          </div>
        </div>
        <div class="vp-sources">
          <h3>Victory Points Sources:</h3>
          <ul>
            <li>🎁 Regular Treasure Chest: <strong>2 VP</strong></li>
            <li>💎 Ruby Chest ({{ dragonName }}'s reward): <strong>3 VP</strong></li>
            <li>☠️ {{ fallenName }}'s Treasure: <strong>2 VP</strong></li>
          </ul>
        </div>
      </section>

      <!-- Monsters -->
      <section class="rules-section">
        <h2>👹 Monsters & Rewards</h2>
        <p class="section-intro">
          Battle monsters by rolling 2 dice. Win if your total damage exceeds their HP!
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
              <span class="monster-name">{{ monster.name }}<template v-if="monster.boss"> (BOSS)</template></span>
              <span v-if="monster.regent" class="regent-chip" title="Karak: Regent expansion">Regent</span>
            </div>
            <div class="monster-stats">
              <div class="stat">❤️ {{ monster.hp }} HP</div>
              <div class="stat">{{ monster.drop }}</div>
              <div v-if="monster.extra" class="stat">{{ monster.extra }}</div>
            </div>
          </div>
        </div>
      </section>

      <!-- Items & Consumables -->
      <section class="rules-section">
        <h2>🎒 Items & Equipment</h2>
        
        <div class="items-category">
          <h3>⚔️ Weapons (Permanent Damage Bonus)</h3>
          <div class="items-list">
            <div class="item">
              <img class="item-image" src="/images/dagger.webp" alt="Dagger" />
              <span class="item-name">Dagger</span>
              <span class="item-effect">+1 damage to all attacks</span>
            </div>
            <div class="item">
              <img class="item-image" src="/images/sword.webp" alt="Sword" />
              <span class="item-name">Sword</span>
              <span class="item-effect">+2 damage to all attacks</span>
            </div>
            <div class="item">
              <img class="item-image" src="/images/axe.webp" alt="Axe" />
              <span class="item-name">Axe</span>
              <span class="item-effect">+3 damage to all attacks</span>
            </div>
          </div>
        </div>

        <div class="items-category">
          <h3>🪄 Spells (One-Time Use)</h3>
          <div class="items-list">
            <div class="item">
              <img class="item-image" src="/images/fireball.webp" alt="Fireball" />
              <span class="item-name">Fireball</span>
              <span class="item-effect">+1 damage to next attack</span>
            </div>
            <div class="item">
              <img class="item-image" src="/images/hf-teleport.webp" alt="Teleport" />
              <span class="item-name">Teleport</span>
              <span class="item-effect">Move to any healing fountain</span>
            </div>
            <div class="item">
              <img class="item-image" src="/images/frost-fist.webp" alt="Frost Fist" />
              <span class="item-name">Frost Fist <span class="regent-chip">Regent</span></span>
              <span class="item-effect">+2 strength in a fight, after the roll; several per fight</span>
            </div>
            <div class="item">
              <img class="item-image" src="/images/dark-thorn.webp" alt="Dark Thorn" />
              <span class="item-name">Dark Thorn <span class="regent-chip">Regent</span></span>
              <span class="item-effect">On your turn another hero loses 1 HP; costs no move</span>
            </div>
          </div>
        </div>

        <div class="items-category">
          <h3>🗝️ Keys & Treasures</h3>
          <div class="items-list">
            <div class="item">
              <img class="item-image" src="/images/key.webp" alt="Key" />
              <span class="item-name">Key</span>
              <span class="item-effect">Unlocks treasure chests</span>
            </div>
            <div class="item">
              <img class="item-image" src="/images/chest-opened.webp" alt="Treasure Chest" />
              <span class="item-name">Treasure Chest</span>
              <span class="item-effect">2 Victory Points</span>
            </div>
            <div class="item">
              <img class="item-image" src="/images/ruby-chest.webp" alt="Ruby Chest" />
              <span class="item-name">Ruby Chest</span>
              <span class="item-effect">3 Victory Points</span>
            </div>
          </div>
        </div>

        <div class="inventory-limits">
          <h3>📦 Inventory Limits</h3>
          <ul>
            <li>🗝️ Keys: Maximum 1</li>
            <li>⚔️ Weapons: Maximum 2</li>
            <li>🪄 Spells: Maximum 3</li>
            <li>🎁 Treasures: Unlimited</li>
          </ul>
        </div>
      </section>

      <!-- Battle System -->
      <section class="rules-section">
        <h2>⚔️ Battle System</h2>
        <div class="battle-flow">
          <div class="battle-step">
            <span class="step-icon">🎲</span>
            <h3>Roll Dice</h3>
            <p>Roll 2 six-sided dice</p>
          </div>
          <div class="battle-step">
            <span class="step-icon">➕</span>
            <h3>Add Bonuses</h3>
            <p>Add weapon damage + spell bonus</p>
          </div>
          <div class="battle-step">
            <span class="step-icon">⚖️</span>
            <h3>Compare</h3>
            <p>Total damage vs Monster HP</p>
          </div>
        </div>

        <div class="battle-outcomes">
          <h3>Battle Outcomes:</h3>
          <div class="outcome win">
            <strong>WIN (damage > HP):</strong> Defeat monster, collect loot
          </div>
          <div class="outcome draw">
            <strong>DRAW (damage = HP):</strong> No damage, monster remains
          </div>
          <div class="outcome lose">
            <strong>LOSE (damage < HP):</strong> Lose 1 HP, move back
          </div>
        </div>
      </section>

      <!-- Special Mechanics -->
      <section class="rules-section">
        <h2>✨ Special Mechanics</h2>
        
        <div class="mechanic">
          <h3>💀 Defeated/Stunned</h3>
          <p>When HP reaches 0:</p>
          <ul>
            <li>Player is stunned on current tile</li>
            <li>Skip next turn</li>
            <li>Regenerate to 1 HP when turn is skipped</li>
          </ul>
        </div>

        <div class="mechanic">
          <h3>⛲ Healing Fountains</h3>
          <p>Special tiles that:</p>
          <ul>
            <li>Restore all HP instantly</li>
            <li>End your turn immediately</li>
          </ul>
        </div>

        <div class="mechanic">
          <h3>🌀 Teleportation Gates</h3>
          <p>Allow instant travel between discovered gates (costs 1 movement)</p>
        </div>
      </section>

      <!-- Karak: Regent expansion -->
      <section class="rules-section" data-testid="rules-regent">
        <h2>👑 Karak: Regent (expansion)</h2>
        <p class="section-intro">
          Switch it on in the lobby: 18 more tiles join the deck and 6 Giant Bats and 2 Skeleton Mages join the bag.
        </p>

        <div class="mechanic">
          <h3><img class="item-image inline-marker" src="/images/arena.webp" alt="" /> Battle Arena</h3>
          <ul>
            <li>The first hero to enter it on their own turn chooses another hero; that hero's figure is moved onto the arena and the two fight a duel.</li>
            <li>The challenger rolls and decides first (spells, skills); then the rival rolls against the challenger's total.</li>
            <li>Higher strength wins. The loser loses 1 HP; the winner takes one item the winner has room for. A tie changes nothing.</li>
            <li>All combat skills apply. The Oracle gets +1 when the arena is her first step, or when she is summoned.</li>
            <li>Both figures stay on the arena; the challenger's turn goes on unless knocked out.</li>
            <li>The arena activates only once; afterwards it is a normal corridor.</li>
          </ul>
        </div>

        <div class="mechanic">
          <h3><img class="item-image inline-marker" src="/images/cursed-room.webp" alt="" /> Cursed Room</h3>
          <ul>
            <li>Every hero entering it rolls a die: 1–3 brings the Curse of Karak, 4–6 is safe. A cursed hero does not roll.</li>
            <li>Several heroes may be cursed at once. A healing fountain or the Portal of Healing lifts every curse.</li>
          </ul>
        </div>
      </section>

      <!-- Turn Structure -->
      <section class="rules-section">
        <h2>🎯 Turn Structure</h2>
        <div class="turn-info">
          <p>Each player has <strong>4 movement actions</strong> per turn.</p>
          <h3>Actions you can take:</h3>
          <ul>
            <li>Move to adjacent tile (1 action)</li>
            <li>Discover new areas</li>
            <li>Use teleportation gates</li>
            <li>Pick up items</li>
            <li>Use consumable spells</li>
            <li>End turn early</li>
          </ul>
          
          <h3>Turn ends when:</h3>
          <ul>
            <li>All 4 actions used</li>
            <li>Battle occurs</li>
            <li>Chest unlocked</li>
            <li>Healing fountain used</li>
            <li>Player chooses to end</li>
          </ul>
        </div>
      </section>

      <!-- Quick Tips -->
      <section class="rules-section">
        <h2>💡 Strategy Tips</h2>
        <div class="tips-grid">
          <div class="tip">
            <span class="tip-icon"><img class="item-tip-image" src="/images/key.webp" alt="Key" /></span>
            <p>Always keep a key - chests give victory points!</p>
          </div>
          <div class="tip">
            <span class="tip-icon"><img class="item-tip-image" src="/images/axe.webp" alt="Axe" /></span>
            <p>Prioritize getting better weapons early</p>
          </div>
          <div class="tip">
            <span class="tip-icon"><img class="item-tip-image" src="/images/hf-teleport.webp" alt="Healing" /></span>
            <p>Save healing spells for emergencies</p>
          </div>
          <div class="tip">
            <span class="tip-icon"><ThemedImage :src="dragon.image" :emoji="dragon.emoji" :alt="dragon.name" img-class="monster-image" emoji-class="monster-image monster-emoji" /></span>
            <p>Prepare well before fighting the {{ dragonName }}!</p>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { onMounted, onBeforeUnmount } from 'vue';
import MusicToggle from '@/components/game/MusicToggle.vue';
import ThemedImage from '@/components/ThemedImage.vue';
import { musicService } from '@/services/musicService';
import { theme, getMonster } from '@/theme';

// Monster stats from the rulebook; names and images come from the active theme
const MONSTER_RULES = [
  { key: 'giant_rat', hp: 5, drop: '🗡️ Drops: Dagger (+1 damage)' },
  { key: 'giant_spider', hp: 6, drop: '✨ Drops: Teleport Spell' },
  { key: 'mummy', hp: 7, drop: '🔥 Drops: Fireball (+1 damage)' },
  { key: 'skeleton_turnkey', hp: 8, drop: '🗝️ Drops: Key (unlocks chests)' },
  { key: 'skeleton_warrior', hp: 9, drop: '🗡️ Drops: Sword (+2 damage)' },
  { key: 'skeleton_king', hp: 10, drop: '🪓 Drops: Axe (+3 damage)' },
  { key: 'fallen', hp: 12, drop: '🎁 Drops: Treasure Chest (2 VP)' },
  { key: 'dragon', hp: 15, drop: '💎 Drops: Ruby Chest (3 VP)', boss: true, extra: '🏁 Ends the game!' },
  // Karak: Regent expansion
  { key: 'giant_bat', hp: 6, drop: '🌑 Drops: Dark Thorn', regent: true },
  { key: 'skeleton_mage', hp: 11, drop: '🧊 Drops: Frost Fist (+2)', regent: true }
];

const monsterCards = MONSTER_RULES.map((rule) => ({ ...rule, ...getMonster(rule.key) }));
const dragon = getMonster('dragon');
const fallen = getMonster('fallen');
const dragonName = dragon.name;
const fallenName = fallen.name;

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