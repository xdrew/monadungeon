<template>
  <div class="battle-overlay">
    <!-- Screen flash effect -->
    <div
      v-if="showFlash"
      class="screen-flash"
      :class="{
        'flash-victory': dynamicResult === 'win',
        'flash-defeat': dynamicResult === 'lose',
        'flash-draw': dynamicResult === 'draw'
      }"
    />

    <div class="battle-card">
      <!-- Header -->
      <div class="battle-header">
        <div class="header-left">
          <span class="header-label">Battle</span>
        </div>
        <h3
          :class="{
            'result-title': true,
            'victory-title': !isRolling && dynamicResult === 'win',
            'defeat-title': !isRolling && dynamicResult === 'lose',
            'draw-title': !isRolling && dynamicResult === 'draw',
            'rolling-title': isRolling,
            'result-slam': showResultSlam
          }"
        >
          {{ dynamicResultText }}
        </h3>
        <button
          class="close-btn"
          @click="leaveItemAndEndTurn"
        >
          &times;
        </button>
      </div>

      <!-- Battle Body -->
      <div class="battle-body">
        <!-- Damage bar (centered above arena) -->
        <div class="damage-bar-center" :class="{ 'hp-bar-reveal': showResults }">
          <div class="hp-bar-track">
            <div
              class="hp-bar-fill"
              :class="'bar-' + dynamicResult"
              :style="{ width: (isRolling ? 0 : damageBarPercent) + '%' }"
            />
            <div class="hp-bar-text">
              <span class="hp-dealt" :class="!isRolling ? 'dealt-' + dynamicResult : 'dealt-hidden'">⚔ {{ totalCalculatedDamage }}</span>
              <span class="hp-separator" :class="{ 'dealt-hidden': isRolling }">vs</span>
              <span class="hp-total">♥ {{ battleInfo.monster }}</span>
            </div>
          </div>
        </div>

        <!-- Arena: Hero vs Monster confrontation -->
        <div class="battle-arena">
          <div class="combatant hero-side">
            <div class="combatant-sprite-wrap">
              <div class="combatant-glow hero-glow" />
              <img
                src="/images/player.webp"
                alt="Hero"
                class="combatant-sprite"
                :class="{
                  'hero-entrance': isAnimating,
                  'hero-idle': !isAnimating && isRolling,
                  'hero-victory': !isRolling && dynamicResult === 'win',
                  'hero-defeat': !isRolling && dynamicResult === 'lose',
                  'hero-draw': !isRolling && dynamicResult === 'draw'
                }"
              />
            </div>
            <!-- Weapon bonuses under hero -->
            <div
              v-if="(equippedWeapons.length > 0 || usedConsumableDamageTotal > 0) && !isRolling"
              class="hero-bonuses"
              :class="{ 'bonuses-reveal': showResults }"
            >
              <span
                v-for="(weapon, index) in equippedWeapons"
                :key="`weapon-${index}`"
                class="bonus-chip"
              >
                <img
                  v-if="getWeaponImage(weapon)"
                  :src="getWeaponImage(weapon)"
                  :alt="weapon.type"
                  class="bonus-chip-img"
                />
                <span v-else>{{ getUsedItemEmoji(weapon) }}</span>
                <span class="bonus-value">+{{ getItemTypeDamage(weapon.type) }}</span>
              </span>
              <span
                v-if="usedConsumableDamageTotal > 0"
                class="bonus-chip spell-chip"
              >
                🔮 <span class="bonus-value">+{{ usedConsumableDamageTotal }}</span>
              </span>
            </div>
          </div>

          <div class="vs-separator">
            <div class="vs-energy-line" />
            <div
              class="vs-badge"
              :class="{ 'vs-pulse': isRolling }"
            >
              <span class="vs-text">VS</span>
            </div>
            <div class="vs-energy-line" />
          </div>

          <div class="combatant monster-side">
            <div class="combatant-sprite-wrap">
              <div class="combatant-glow monster-glow" />
              <img
                v-if="displayMonsterImage"
                :src="displayMonsterImage"
                alt="Monster"
                class="combatant-sprite"
                :class="{
                  'monster-entrance': isAnimating,
                  'monster-idle': !isAnimating && isRolling,
                  'monster-victory': !isRolling && dynamicResult === 'lose',
                  'monster-defeat': !isRolling && dynamicResult === 'win',
                  'monster-draw': !isRolling && dynamicResult === 'draw'
                }"
              />
              <div v-else class="monster-emoji-large" :class="{ 'monster-entrance': isAnimating }">
                {{ displayMonsterEmoji }}
              </div>
            </div>
            <div class="monster-name-label">{{ formattedMonsterName }}</div>
          </div>
        </div>

        <!-- Dice Stage -->
        <div class="dice-stage" :class="{ 'dice-visible': showDice }">
          <div class="dice-container">
            <div
              v-for="(value, index) in (isRolling ? rollingValues : battleInfo.diceResults)"
              :key="index"
              class="dice-face"
              :class="{ 'rolling-dice': isRolling, 'dice-landed': !isRolling && showResults }"
              :data-value="value"
              :style="isRolling ? { animationDelay: `${index * 0.1}s` } : {}"
            >
              <span
                v-for="pip in value"
                :key="`pip-${index}-${pip}`"
                class="pip"
                :class="`pip-${value}-${pip}`"
              />
            </div>
          </div>
        </div>

        <!-- Combined reward + consumable selection (side by side when both present) -->
        <div
          v-if="!isRolling && showConsumableSelection && (shouldShowReward || potentialVictoryWithConsumables)"
          class="reward-consumable-row"
          :class="{ 'section-reveal': showResults }"
        >
          <!-- Reward (compact) -->
          <div class="reward-compact" :class="rewardCategoryClass">
            <div v-if="battleInfo.reward" class="reward-compact-inner">
              <div class="reward-icon-compact">
                <img
                  v-if="displayItemImage"
                  :src="displayItemImage"
                  :alt="formattedItemName"
                  class="reward-img-sm"
                />
                <span v-else class="reward-emoji-sm">{{ displayItemEmoji }}</span>
                <span
                  v-if="isPotentialReward && selectedConsumables.length === 0"
                  class="potential-badge"
                >?</span>
              </div>
              <div class="reward-compact-info">
                <span class="reward-compact-name">{{ formattedItemName }}</span>
                <span
                  v-if="getItemTypeDamage(battleInfo.reward.type) > 0"
                  class="stat-badge badge-damage badge-sm"
                >⚔️ +{{ getItemTypeDamage(battleInfo.reward.type) }}</span>
                <span
                  v-if="battleInfo.reward.treasureValue && battleInfo.reward.treasureValue > 0"
                  class="stat-badge badge-value badge-sm"
                >💰 {{ battleInfo.reward.treasureValue }}</span>
              </div>
            </div>
            <div v-else class="reward-compact-inner">
              <img v-if="displayMonsterImage" :src="displayMonsterImage" alt="Monster" class="reward-img-sm" />
              <span class="reward-compact-name">Treasure</span>
            </div>
          </div>

          <!-- Consumable selection (compact) -->
          <div class="consumable-compact">
            <div class="consumable-compact-label">Use:</div>
            <div class="consumable-compact-list">
              <div
                v-for="(item, index) in availableDamageConsumables"
                :key="`consumable-${index}`"
                class="item-chip selectable"
                :class="{ 'selected': selectedConsumables.includes(item.itemId) }"
                @click="toggleConsumable(item)"
              >
                <img
                  v-if="getInventoryItemImage(item)"
                  :src="getInventoryItemImage(item)"
                  :alt="getSpellDisplayName(item)"
                  class="chip-img"
                />
                <span v-else class="chip-emoji">{{ getInventoryItemEmoji(item) }}</span>
                <span class="chip-damage">+{{ getItemTypeDamage(item.type) }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Victory Reward Section (standalone, when no consumable selection) -->
        <div
          v-if="!isRolling && !showConsumableSelection && shouldShowReward"
          class="reward-section"
          :class="{ 'reward-rise': showResults }"
        >
          <!-- Victory sparkles -->
          <div v-if="dynamicResult === 'win'" class="victory-sparkles">
            <span v-for="i in 8" :key="`sparkle-${i}`" class="sparkle" :style="sparkleStyle(i)" />
          </div>

          <div class="reward-card" :class="rewardCategoryClass">
            <div
              v-if="battleInfo.reward"
              class="reward-item-inline"
            >
              <img
                v-if="displayItemImage"
                :src="displayItemImage"
                :alt="formattedItemName"
                class="reward-inline-img"
              />
              <span v-else class="reward-inline-emoji">{{ displayItemEmoji }}</span>
              <div class="reward-inline-info">
                <span class="reward-item-name">{{ formattedItemName }}</span>
                <div class="reward-badges">
                  <span
                    v-if="getItemTypeDamage(battleInfo.reward.type) > 0"
                    class="stat-badge badge-damage badge-sm"
                  >⚔️ +{{ getItemTypeDamage(battleInfo.reward.type) }}</span>
                  <span
                    v-if="battleInfo.reward.treasureValue && battleInfo.reward.treasureValue > 0"
                    class="stat-badge badge-value badge-sm"
                  >💰 {{ battleInfo.reward.treasureValue }}</span>
                </div>
                <div v-if="isGuardChestReward" class="reward-note success">
                  Chest auto-opened!
                </div>
              </div>
            </div>
            <div v-else class="no-reward">
              Monster defeated! No treasure found.
            </div>
          </div>
        </div>

        <!-- Consumable selection only (no reward to show) -->
        <div
          v-if="!isRolling && showConsumableSelection && !shouldShowReward && !potentialVictoryWithConsumables"
          class="items-section"
          :class="{ 'section-reveal': showResults }"
        >
          <div class="items-section-title">Select consumables to use:</div>
          <div class="items-list">
            <div
              v-for="(item, index) in availableDamageConsumables"
              :key="`consumable-${index}`"
              class="item-chip selectable"
              :class="{ 'selected': selectedConsumables.includes(item.itemId) }"
              @click="toggleConsumable(item)"
            >
              <img
                v-if="getInventoryItemImage(item)"
                :src="getInventoryItemImage(item)"
                :alt="getSpellDisplayName(item)"
                class="chip-img"
              />
              <span v-else class="chip-emoji">{{ getInventoryItemEmoji(item) }}</span>
              <span class="chip-name">{{ getSpellDisplayName(item) }}</span>
              <span class="chip-damage">+{{ getItemTypeDamage(item.type) }}</span>
            </div>
          </div>
        </div>

        <!-- Used consumables display (after battle finalized) -->
        <div
          v-if="!isRolling && !showConsumableSelection && !showInventorySelection && usedDamageConsumables && usedDamageConsumables.length > 0"
          class="items-section"
          :class="{ 'section-reveal': showResults }"
        >
          <div class="items-section-title">Used consumables:</div>
          <div class="items-list">
            <div
              v-for="(item, index) in usedDamageConsumables"
              :key="`used-${index}`"
              class="item-chip consumable-used"
            >
              <span class="chip-emoji">{{ getUsedItemEmoji(item) }}</span>
              <span class="chip-name">{{ getCorrectItemName(item) }}</span>
              <span class="chip-damage">+{{ getItemTypeDamage(item.type) }}</span>
            </div>
          </div>
        </div>

        <!-- Inventory replacement section -->
        <div
          v-if="!isRolling && showInventorySelection"
          class="items-section"
          :class="{ 'section-reveal': showResults }"
        >
          <div class="items-section-title">Choose an item to replace:</div>
          <div class="items-list">
            <div
              v-for="(item, index) in inventoryForSelection"
              :key="`inventory-${index}`"
              class="item-chip selectable inventory-replace"
              :class="{ 'selected': selectedItemForReplacement?.itemId === item.itemId }"
              @click="setSelectedItemForReplacement(item)"
            >
              <img
                v-if="getInventoryItemImage(item)"
                :src="getInventoryItemImage(item)"
                :alt="formatItemName(item.type || item.name)"
                class="chip-img"
              />
              <span v-else class="chip-emoji">{{ getInventoryItemEmoji(item) }}</span>
              <span class="chip-name">{{ formatItemName(item.type || item.name) }}</span>
              <span
                v-if="getItemTypeDamage(item.type || item.name) > 0"
                class="chip-damage"
              >+{{ getItemTypeDamage(item.type || item.name) }}</span>
              <span
                v-else-if="item.treasureValue > 0"
                class="chip-value"
              >💰{{ item.treasureValue }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div v-show="!isRolling" class="battle-footer" :class="{ 'footer-reveal': showResults }">
        <!-- Keys: player already has one -->
        <div v-if="battleInfo.result === 'win' && battleInfo.reward && isKeyReward && !hasInventorySpace && !showInventorySelection && !showConsumableSelection && !battleFinalized">
          <button
            class="btn-primary"
            :disabled="isProcessing"
            @click="leaveItemAndEndTurn"
          >
            End Turn (All keys are the same) <span class="kbd-hint">(Enter)</span>
          </button>
        </div>
        <!-- Guard chest: auto-collected -->
        <div v-else-if="battleInfo.result === 'win' && battleInfo.reward && isGuardChestReward && !showInventorySelection && !showConsumableSelection">
          <button
            class="btn-primary"
            :disabled="isProcessing"
            @click="pickUpAndEndTurn"
          >
            End Turn (Treasure collected automatically) <span class="kbd-hint">(Enter)</span>
          </button>
        </div>
        <!-- Normal victory with reward -->
        <div v-else-if="battleInfo.result === 'win' && battleInfo.reward && !showInventorySelection && !showConsumableSelection" class="button-group">
          <button
            class="btn-primary"
            :disabled="isProcessing"
            @click="pickUpAndEndTurn"
          >
            🎒 Pick up and end turn <span class="kbd-hint">(Enter)</span>
          </button>
          <button
            v-if="!hasInventorySpace && !isKeyReward && !isGuardChestReward"
            class="btn-secondary"
            :disabled="isProcessing"
            @click="leaveItemAndEndTurn"
          >
            Leave item and end turn <span class="kbd-hint">(Esc)</span>
          </button>
        </div>
        <!-- Consumable selection buttons -->
        <div v-else-if="showConsumableSelection" class="button-group">
          <button
            v-if="selectedConsumables.length > 0 && totalCalculatedDamage > battleInfo.monster"
            class="btn-primary"
            :disabled="isProcessing"
            @click="finalizeBattleAndPickUp"
          >
            🎒 Fight, win, and pick up reward
          </button>
          <button
            v-if="selectedConsumables.length > 0 && totalCalculatedDamage > battleInfo.monster"
            class="btn-secondary"
            :disabled="isProcessing"
            @click="finalizeBattleAndLeaveItem"
          >
            Leave reward after victory
          </button>
          <button
            v-else-if="selectedConsumables.length > 0 && totalCalculatedDamage === battleInfo.monster"
            class="btn-draw"
            :disabled="isProcessing"
            @click="finalizeBattleWithConsumables"
          >
            ⚔️ Fight for a draw
          </button>
          <button
            v-else
            class="btn-defeat"
            :disabled="isProcessing"
            @click="finalizeBattleWithoutConsumables"
          >
            {{ battleInfo.result === 'draw' ? '⬅️ Retreat' : '😵 Accept defeat' }} <span class="kbd-hint">(Enter)</span>
          </button>
        </div>
        <!-- Lost or draw without consumable selection -->
        <div v-else-if="(battleInfo.result === 'lose' || battleInfo.result === 'draw') && !showInventorySelection" class="button-group">
          <button
            class="btn-defeat"
            :disabled="isProcessing"
            @click="handleRetreat"
          >
            {{ battleInfo.result === 'draw' ? '⬅️ Retreat' : '😵 Accept defeat' }} <span class="kbd-hint">(Enter)</span>
          </button>
        </div>
        <!-- Inventory replacement -->
        <div v-else-if="showInventorySelection" class="button-group">
          <button
            :disabled="!selectedItemForReplacement || isProcessing"
            :class="potentialVictoryWithConsumables ? 'btn-primary' : 'btn-primary'"
            @click="confirmReplacement"
          >
            {{ potentialVictoryWithConsumables ? '✅ Replace & Pick Up Reward' : '✅ Replace and end turn' }}
          </button>
          <button
            class="btn-danger"
            :disabled="isProcessing"
            @click="cancelReplacement"
          >
            ❌ Cancel
          </button>
        </div>
        <!-- Fallback end turn -->
        <div v-else>
          <button
            class="btn-primary"
            :disabled="isProcessing"
            @click="leaveItemAndEndTurn"
          >
            End Turn <span class="kbd-hint">(Enter)</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { defineProps, defineEmits, computed, ref, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { getMonsterImage, getMonsterDisplayName } from '@/utils/monsterUtils';

const props = defineProps({
  battleInfo: {
    type: Object,
    required: true
  },
  hasInventorySpace: {
    type: Boolean,
    default: true
  }
});

const emit = defineEmits(['end-turn', 'pick-item-and-end-turn', 'pick-item-with-replacement', 'finalize-battle', 'finalize-battle-and-pick-up']);

// Animation state
const isRolling = ref(true);
const isAnimating = ref(true); // entrance animation phase
const showResults = ref(false);
const showDice = ref(false);
const showFlash = ref(false);
const showResultSlam = ref(false);
const rollingValues = ref([1, 1]);

// Sparkle position helper for victory particles
const sparkleStyle = (i) => {
  const angle = (i / 8) * 360;
  const rad = (angle * Math.PI) / 180;
  const distance = 40 + (i % 3) * 15;
  return {
    '--sparkle-x': `${Math.cos(rad) * distance}px`,
    '--sparkle-y': `${Math.sin(rad) * distance}px`,
    animationDelay: `${i * 0.08}s`
  };
};

// Start dice rolling animation
const startRollingAnimation = () => {
  // Reset states
  isRolling.value = true;
  isAnimating.value = true;
  showResults.value = false;
  showDice.value = false;
  showFlash.value = false;
  showResultSlam.value = false;

  // Initialize with same number of dice as actual roll
  if (props.battleInfo.diceResults) {
    rollingValues.value = props.battleInfo.diceResults.map(() => 1);
  }

  // Phase 1: Entrance animations (0-800ms)
  // Characters slide in via CSS (0.7s duration), let them finish

  // Phase 2: Show dice and start rolling (after entrances settle)
  setTimeout(() => {
    isAnimating.value = false;
    showDice.value = true;
  }, 800);

  const rollInterval = setInterval(() => {
    rollingValues.value = rollingValues.value.map(() => Math.floor(Math.random() * 6) + 1);
  }, 80);

  // Dice roll for 1.2s, then land (800 + 1200 = 2000ms)
  setTimeout(() => {
    clearInterval(rollInterval);
    rollingValues.value = [...props.battleInfo.diceResults];

    // Pause to let dice values register visually (500ms)
    setTimeout(() => {
      isRolling.value = false;

      // Screen flash after a beat (300ms later)
      setTimeout(() => {
        showFlash.value = true;
        showResultSlam.value = true;
        setTimeout(() => {
          showFlash.value = false;
        }, 500);
      }, 300);

      // Show damage numbers, reward, buttons (600ms after result)
      setTimeout(() => {
        showResults.value = true;
      }, 600);
    }, 500);
  }, 2000);
};

// Start animation when component mounts or battle info changes
onMounted(() => {
  startRollingAnimation();
});

// Restart animation if battle info changes (new battle)
watch(() => props.battleInfo?.battleId, (newId, oldId) => {
  if (newId && newId !== oldId) {
    startRollingAnimation();
    isProcessing.value = false;
    battleFinalized.value = false;
  }
});

// Reactive data for inventory selection
const showInventorySelection = ref(false);
const inventoryForSelection = ref([]);
const selectedItemForReplacement = ref(null);
const setSelectedItemForReplacement = (item) => {
  selectedItemForReplacement.value = item;
  try {
    console.log('[BattleReportModal] Selected item for replacement:', item?.itemId || item);
  } catch (e) {}
};
const pendingPickupItem = ref(null);

// Reactive data for consumable selection
const showConsumableSelection = ref(false);
const availableConsumables = ref([]);
const selectedConsumables = ref([]);

const setShowConsumableSelection = (value, reason = '') => {
  if (showConsumableSelection.value === value) return;
  try {
    const ts = new Date().toISOString();
    console.log(`[BattleReportModal] ${ts} showConsumableSelection ->`, value, reason ? `reason: ${reason}` : '');
  } catch (e) {}
  showConsumableSelection.value = value;
};

// Computed to filter available consumables for only damage-dealing ones
const availableDamageConsumables = computed(() => {
  if (!availableConsumables.value) return [];
  return availableConsumables.value.filter(item => getItemTypeDamage(item.type) > 0);
});

const battleFinalized = ref(false);
const isProcessing = ref(false);

const getItemTypeDamage = (itemType) => {
  const damageMap = {
    'dagger': 1,
    'sword': 2,
    'axe': 3,
    'fireball': 1,
    'teleport': 0,
    'key': 0,
    'chest': 0,
    'ruby_chest': 0
  };
  return damageMap[itemType] || 0;
};

const potentialVictoryWithConsumables = computed(() => {
  if (!availableConsumables.value) return false;

  const currentDamage = (props.battleInfo.diceRollDamage || 0) + weaponDamageTotal.value;
  const maxConsumableDamage = availableConsumables.value
    .filter(item => getItemTypeDamage(item.type) > 0)
    .reduce((total, item) => total + getItemTypeDamage(item.type), 0);
  console.log('Log damage:', {
    dice: props.battleInfo.diceRollDamage,
    weaponDamageTotal: weaponDamageTotal.value,
    maxConsumableDamage: maxConsumableDamage,
    monsterHp: props.battleInfo.monster,
    potentialVictory: (currentDamage + maxConsumableDamage) > props.battleInfo.monster,
  });

  return (currentDamage + maxConsumableDamage) > props.battleInfo.monster;
});

const potentialImprovementWithConsumables = computed(() => {
  if (!showConsumableSelection.value || !availableConsumables.value) return false;

  const currentDamage = (props.battleInfo.diceRollDamage || 0) + weaponDamageTotal.value;
  const maxConsumableDamage = availableConsumables.value
    .filter(item => getItemTypeDamage(item.type) > 0)
    .reduce((total, item) => total + getItemTypeDamage(item.type), 0);

  const totalPossibleDamage = currentDamage + maxConsumableDamage;

  if (props.battleInfo.result === 'lose') {
    return totalPossibleDamage >= props.battleInfo.monster;
  }
  if (props.battleInfo.result === 'draw') {
    return totalPossibleDamage > props.battleInfo.monster;
  }
  return false;
});

const dynamicResult = computed(() => {
  if (selectedConsumables.value.length > 0 && (showConsumableSelection.value || showInventorySelection.value)) {
    const totalDamage = totalCalculatedDamage.value;
    const monsterHP = props.battleInfo.monster;

    if (totalDamage > monsterHP) return 'win';
    if (totalDamage === monsterHP) return 'draw';
    return 'lose';
  }

  return props.battleInfo.result;
});

const dynamicResultText = computed(() => {
  if (isRolling.value) {
    return 'Rolling...';
  }

  if (selectedConsumables.value.length > 0 && (showConsumableSelection.value || showInventorySelection.value)) {
    const totalDamage = totalCalculatedDamage.value;
    const monsterHP = props.battleInfo.monster;

    if (totalDamage > monsterHP) return 'Victory!';
    if (totalDamage === monsterHP) return 'Draw';
    return 'Defeat';
  }

  if (props.battleInfo.result === 'win') return 'Victory!';
  if (props.battleInfo.result === 'draw') return 'Draw';
  return 'Defeat';
});

const usedDamageConsumables = computed(() => {
  if (!props.battleInfo.usedItems) return [];
  return props.battleInfo.usedItems.filter(item =>
    item.type && item.type === 'fireball'
  );
});

const usedConsumables = computed(() => {
  if (!props.battleInfo.usedItems) return [];
  return props.battleInfo.usedItems.filter(item =>
    item.type && ['fireball', 'teleport'].includes(item.type)
  );
});

const equippedWeapons = computed(() => {
  if (!props.battleInfo.usedItems) return [];
  return props.battleInfo.usedItems.filter(item =>
    item.type && ['dagger', 'sword', 'axe'].includes(item.type)
  );
});

const weaponDamageTotal = computed(() => {
  if (!props.battleInfo.usedItems) return 0;
  return props.battleInfo.usedItems
    .filter(item => item.type && ['dagger', 'sword', 'axe'].includes(item.type))
    .reduce((total, item) => total + getItemTypeDamage(item.type), 0);
});

const usedConsumableDamageTotal = computed(() => {
  if (!props.battleInfo.usedItems) return 0;
  return props.battleInfo.usedItems
    .filter(item => item.type === 'fireball')
    .reduce((total, item) => total + getItemTypeDamage(item.type), 0);
});

const consumableDamageTotal = computed(() => {
  if (!availableConsumables.value || selectedConsumables.value.length === 0) return 0;
  return availableConsumables.value
    .filter(item => selectedConsumables.value.includes(item.itemId))
    .reduce((total, item) => total + getItemTypeDamage(item.type), 0);
});

const totalCalculatedDamage = computed(() => {
  return (props.battleInfo.diceRollDamage || 0) + (props.battleInfo.itemDamage || 0) + consumableDamageTotal.value;
});

const damageBarPercent = computed(() => {
  const monsterHP = props.battleInfo.monster || 1;
  return Math.min(100, (totalCalculatedDamage.value / monsterHP) * 100);
});

const isKeyReward = computed(() => {
  if (!props.battleInfo.reward) return false;
  const reward = props.battleInfo.reward;
  const isKeyType = reward.type === 'key';
  const isKeyName = reward.name === 'key' || (typeof reward.name === 'string' && reward.name.toLowerCase().includes('key'));
  console.log('Key reward check:', {
    rewardType: reward.type,
    rewardName: reward.name,
    isKeyType,
    isKeyName,
    hasInventorySpace: props.hasInventorySpace
  });
  return isKeyType || isKeyName;
});

const isGuardChestReward = computed(() => {
  if (!props.battleInfo.reward) return false;
  const reward = props.battleInfo.reward;
  if (reward.hasOwnProperty('autoCollected')) {
    return reward.autoCollected === true;
  }
  const isChestType = reward.type === 'chest' || reward.type === 'ruby_chest';
  const hasDirectTreasureValue = reward.treasureValue && reward.treasureValue > 0;
  return isChestType && hasDirectTreasureValue;
});

const hasInventorySpaceForEstimatedReward = computed(() => {
  return props.hasInventorySpace;
});

// Reward category class for accent colors
const rewardCategoryClass = computed(() => {
  if (!props.battleInfo.reward) return '';
  const type = props.battleInfo.reward.type;
  if (['dagger', 'sword', 'axe'].includes(type)) return 'category-weapon';
  if (type === 'key') return 'category-key';
  if (['fireball', 'teleport'].includes(type)) return 'category-spell';
  if (['chest', 'ruby_chest'].includes(type)) return 'category-treasure';
  return '';
});

const initializeConsumableSelection = () => {
  console.log('initializeConsumableSelection called with battleInfo:', props.battleInfo);
  console.log('needsConsumableConfirmation:', props.battleInfo.needsConsumableConfirmation);
  console.log('availableConsumables:', props.battleInfo.availableConsumables);

  if (battleFinalized.value) {
    console.log('Battle already finalized, skipping consumable selection');
    setShowConsumableSelection(false, 'battle already finalized');
    return;
  }

  if (!props.battleInfo.reward && props.battleInfo.monster && props.battleInfo.monsterType) {
    console.log('No reward in battleInfo, but monster exists. This might be a bug.');
  }

  if (props.battleInfo.needsConsumableConfirmation && props.battleInfo.availableConsumables) {
    const didNotWin = props.battleInfo.result !== 'win';
    console.log('Player did not win:', didNotWin, 'result:', props.battleInfo.result);

    if (didNotWin) {
      const currentDamage = (props.battleInfo.diceRollDamage || 0) + weaponDamageTotal.value;
      const consumablesWithDamage = props.battleInfo.availableConsumables.filter(item => getItemTypeDamage(item.type) > 0);
      console.log('Current damage:', currentDamage, 'consumables with damage:', consumablesWithDamage);

      if (consumablesWithDamage.length === 0) {
        console.log('No damage-dealing consumables available, skipping consumables interface');
        setShowConsumableSelection(false, 'no damage-dealing consumables available');
        return;
      }

      const maxConsumableDamage = consumablesWithDamage.reduce((total, item) => total + getItemTypeDamage(item.type), 0);
      const maxPossibleDamage = currentDamage + maxConsumableDamage;

      if (maxPossibleDamage > props.battleInfo.monster) {
        console.log(`Consumables could change outcome: ${maxPossibleDamage} damage vs ${props.battleInfo.monster} HP`);
        setShowConsumableSelection(true, 'maxPossibleDamage > monster');
        availableConsumables.value = consumablesWithDamage;
        selectedConsumables.value = [];
        return;
      }

      if (maxPossibleDamage >= props.battleInfo.monster) {
        console.log(`Consumables could achieve draw: ${maxPossibleDamage} damage vs ${props.battleInfo.monster} HP`);
        setShowConsumableSelection(true, 'maxPossibleDamage >= monster (draw)');
        availableConsumables.value = consumablesWithDamage;
        selectedConsumables.value = [];
        return;
      }

      console.log(`Consumables can't change outcome: ${maxPossibleDamage} damage vs ${props.battleInfo.monster} HP, skipping interface`);
      setShowConsumableSelection(false, "consumables can't change outcome");
      return;
    }

    console.log('Player won initially, no consumable selection needed');
    setShowConsumableSelection(false, 'player already won');
  } else {
    console.log('No consumable confirmation needed or no available consumables');
    setShowConsumableSelection(false, 'no confirmation needed or no consumables in battleInfo');
  }
};

watch(() => props.battleInfo, (newBattleInfo, oldBattleInfo) => {
  if (!oldBattleInfo || newBattleInfo?.battleId !== oldBattleInfo?.battleId) {
    battleFinalized.value = false;
  }
  initializeConsumableSelection();
}, { immediate: true });

const toggleConsumable = (item) => {
  const index = selectedConsumables.value.indexOf(item.itemId);
  if (index === -1) {
    selectedConsumables.value.push(item.itemId);
    const newTotalDamage = (props.battleInfo.diceRollDamage || 0) + weaponDamageTotal.value + consumableDamageTotal.value;
    console.log(`Toggled consumable ${item.name || item.type}. New total damage: ${newTotalDamage} vs monster HP: ${props.battleInfo.monster}`);

    if (newTotalDamage > props.battleInfo.monster &&
        newTotalDamage - getItemTypeDamage(item.type) <= props.battleInfo.monster) {
      console.log('This selection would lead to victory!');
      nextTick(() => {
        console.log('Re-evaluating computed properties after crossing victory threshold');
      });
    }
  } else {
    selectedConsumables.value.splice(index, 1);
    const newTotalDamage = (props.battleInfo.diceRollDamage || 0) + weaponDamageTotal.value + consumableDamageTotal.value;
    console.log(`Removed consumable ${item.name || item.type}. New total damage: ${newTotalDamage} vs monster HP: ${props.battleInfo.monster}`);

    if (newTotalDamage <= props.battleInfo.monster &&
        newTotalDamage + getItemTypeDamage(item.type) > props.battleInfo.monster) {
      console.log('This deselection would prevent victory!');
    }
  }
};

const finalizeBattleWithConsumables = () => {
  if (isProcessing.value) return;
  if (!props.battleInfo.battleId) {
    console.error('No battleId available, cannot finalize battle');
    return;
  }
  isProcessing.value = true;
  battleFinalized.value = true;
  emit('finalize-battle', {
    battleId: props.battleInfo.battleId,
    selectedConsumableIds: selectedConsumables.value
  });
  setShowConsumableSelection(false, 'finalizeBattleWithConsumables reset');
  selectedConsumables.value = [];
  availableConsumables.value = [];
};

const finalizeBattleWithoutConsumables = () => {
  if (isProcessing.value) return;
  isProcessing.value = true;
  battleFinalized.value = true;
  emit('finalize-battle', {
    battleId: props.battleInfo.battleId,
    selectedConsumableIds: []
  });
  setShowConsumableSelection(false, 'finalizeBattleWithoutConsumables reset');
  selectedConsumables.value = [];
  availableConsumables.value = [];
};

const finalizeBattleAndPickUp = () => {
  if (isProcessing.value) return;
  console.log('🟢 Green button clicked - finalizeBattleAndPickUp called');
  console.log('Battle ID:', props.battleInfo.battleId);
  console.log('Selected consumables:', selectedConsumables.value);
  console.log('Replace item ID:', selectedItemForReplacement.value?.itemId);

  isProcessing.value = true;
  battleFinalized.value = true;

  const eventData = {
    battleId: props.battleInfo.battleId,
    selectedConsumableIds: selectedConsumables.value,
    replaceItemId: selectedItemForReplacement.value?.itemId
  };

  console.log('Emitting finalize-battle-and-pick-up with data:', eventData);
  emit('finalize-battle-and-pick-up', eventData);
};

const finalizeBattleAndLeaveItem = () => {
  if (isProcessing.value) return;
  isProcessing.value = true;
  battleFinalized.value = true;
  emit('finalize-battle', {
    battleId: props.battleInfo.battleId,
    selectedConsumableIds: selectedConsumables.value,
    hideModalImmediately: true
  });
  setShowConsumableSelection(false, 'finalizeBattleAndLeaveItem reset');
  selectedConsumables.value = [];
  availableConsumables.value = [];
};

const pickUpAndEndTurn = () => {
  if (isProcessing.value) return;
  isProcessing.value = true;
  emit('pick-item-and-end-turn');
};

const confirmReplacement = () => {
  if (isProcessing.value) return;
  if (!selectedItemForReplacement.value) return;

  isProcessing.value = true;

  if (selectedConsumables.value && selectedConsumables.value.length > 0) {
    console.log('Confirming replacement with consumables:', selectedConsumables.value);
    const eventData = {
      battleId: props.battleInfo.battleId,
      selectedConsumableIds: selectedConsumables.value,
      replaceItemId: selectedItemForReplacement.value.itemId,
      hideModalImmediately: true
    };
    emit('finalize-battle-and-pick-up', eventData);
    selectedConsumables.value = [];
    availableConsumables.value = [];
  } else {
    emit('pick-item-with-replacement', selectedItemForReplacement.value.itemId);
  }

  showInventorySelection.value = false;
  inventoryForSelection.value = [];
  selectedItemForReplacement.value = null;
  pendingPickupItem.value = null;
  setShowConsumableSelection(false, 'confirmReplacement reset after replacement');
  availableConsumables.value = [];
};

const cancelReplacement = () => {
  if (isProcessing.value) return;
  isProcessing.value = false;
  showInventorySelection.value = false;
  inventoryForSelection.value = [];
  selectedItemForReplacement.value = null;
  pendingPickupItem.value = null;
  if (selectedConsumables.value.length > 0) {
    setShowConsumableSelection(true, 'cancelReplacement showing consumable selection again');
  }
};

const leaveItemAndEndTurn = () => {
  if (isProcessing.value) return;
  console.log('leaveItemAndEndTurn called with battleInfo:', props.battleInfo);
  isProcessing.value = true;

  if (props.battleInfo.battleId &&
      (props.battleInfo.result === 'lose' || props.battleInfo.result === 'draw' || props.battleInfo.result === 'win')) {
    console.log('Finalizing battle for result:', props.battleInfo.result);
    battleFinalized.value = true;
    emit('finalize-battle', {
      battleId: props.battleInfo.battleId,
      selectedConsumableIds: []
    });
  } else {
    console.log('Ending turn without finalization');
    emit('end-turn');
  }
};

const handleRetreat = () => {
  if (isProcessing.value) return;
  console.log('handleRetreat called');
  isProcessing.value = true;

  if (props.battleInfo.battleId) {
    battleFinalized.value = true;
    emit('finalize-battle', {
      battleId: props.battleInfo.battleId,
      selectedConsumableIds: []
    });
  } else {
    emit('end-turn');
  }
};

const showInventoryFullSelection = (inventory, item) => {
  isProcessing.value = false;
  showInventorySelection.value = true;
  inventoryForSelection.value = inventory || [];
  pendingPickupItem.value = item;
  selectedItemForReplacement.value = null;
  setShowConsumableSelection(false, 'showInventoryFullSelection hides consumable selection');
  try { console.log('[BattleReportModal] Inventory full selection opened.'); } catch (e) {}
};

const showFinalizeBattleInventoryFullSelection = (inventory) => {
  console.log('showFinalizeBattleInventoryFullSelection called with inventory:', inventory);
  isProcessing.value = false;
  showInventorySelection.value = true;
  inventoryForSelection.value = inventory || [];
  selectedItemForReplacement.value = null;
  setShowConsumableSelection(false, 'showFinalizeBattleInventoryFullSelection hides consumable selection');
  console.log('Keeping selected consumables for later:', selectedConsumables.value);
  try { console.log('[BattleReportModal] Finalize battle inventory selection opened.'); } catch (e) {}
};

// Keyboard handler
const onKeyDown = (e) => {
  if (isRolling.value || isProcessing.value) return;

  if (e.key === 'Enter') {
    const bi = props.battleInfo;
    if (bi.result === 'win' && bi.reward && isKeyReward.value && !props.hasInventorySpace && !showInventorySelection.value && !showConsumableSelection.value && !battleFinalized.value) {
      leaveItemAndEndTurn();
    } else if (bi.result === 'win' && bi.reward && isGuardChestReward.value && !showInventorySelection.value && !showConsumableSelection.value) {
      pickUpAndEndTurn();
    } else if (bi.result === 'win' && bi.reward && !showInventorySelection.value && !showConsumableSelection.value) {
      pickUpAndEndTurn();
    } else if (showConsumableSelection.value) {
      if (selectedConsumables.value.length > 0 && totalCalculatedDamage.value > bi.monster) {
        finalizeBattleAndPickUp();
      } else if (selectedConsumables.value.length > 0 && totalCalculatedDamage.value === bi.monster) {
        finalizeBattleWithConsumables();
      } else {
        finalizeBattleWithoutConsumables();
      }
    } else if ((bi.result === 'lose' || bi.result === 'draw') && !showInventorySelection.value) {
      handleRetreat();
    } else if (showInventorySelection.value) {
      if (selectedItemForReplacement.value) {
        confirmReplacement();
      }
    } else {
      leaveItemAndEndTurn();
    }
    e.preventDefault();
    e.stopPropagation();
  } else if (e.key === 'Escape') {
    leaveItemAndEndTurn();
    e.preventDefault();
    e.stopPropagation();
  }
};

onMounted(() => window.addEventListener('keydown', onKeyDown, true));
onUnmounted(() => window.removeEventListener('keydown', onKeyDown, true));

defineExpose({
  showInventoryFullSelection,
  showFinalizeBattleInventoryFullSelection
});

watch(() => showInventorySelection.value, (now) => {
  if (now) {
    isProcessing.value = false;
  }
});

// Helper functions
const formatItemName = (name) => {
  if (!name) return 'Unknown';
  return name.split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};

const getCorrectItemName = (item) => {
  if (!item) return 'Unknown';
  const itemName = item.type || item.name || 'Unknown';
  return itemName.split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};

const formattedItemName = computed(() => {
  if (!props.battleInfo.reward || !props.battleInfo.reward.name) return '';
  return formatItemName(props.battleInfo.reward.name);
});

const formattedItemType = computed(() => {
  if (!props.battleInfo.reward || !props.battleInfo.reward.type) return '';
  return formatItemName(props.battleInfo.reward.type);
});

const displayItemEmoji = computed(() => {
  if (!props.battleInfo.reward) return '❓';
  const item = props.battleInfo.reward;
  switch (item.type) {
    case 'key': return '🔑';
    case 'chest': return '📦';
    case 'ruby_chest': return '💎';
    case 'dagger': return '🗡️';
    case 'sword': return '⚔️';
    case 'axe': return '🪓';
    case 'fireball': return '🔥';
    case 'teleport': return '✨';
    default: return '💰';
  }
});

const displayItemImage = computed(() => {
  if (!props.battleInfo.reward) return null;
  const item = props.battleInfo.reward;
  if (item.type === 'key') return '/images/key.webp';
  if (item.type === 'chest') return '/images/chest-opened.webp';
  if (item.type === 'ruby_chest') return '/images/ruby-chest.webp';
  if (item.type === 'fireball') return '/images/fireball.webp';
  if (item.type === 'teleport') return '/images/hf-teleport.webp';
  if (item.type === 'dagger') return '/images/dagger.webp';
  if (item.type === 'sword') return '/images/sword.webp';
  if (item.type === 'axe') return '/images/axe.webp';
  return null;
});

const getInventoryItemImage = (item) => {
  if (!item) return null;
  const itemType = item.type || item.name;
  switch (itemType) {
    case 'key': return '/images/key.webp';
    case 'chest': return '/images/chest-opened.webp';
    case 'ruby_chest': return '/images/ruby-chest.webp';
    case 'fireball': return '/images/fireball.webp';
    case 'teleport': return '/images/hf-teleport.webp';
    case 'dagger': return '/images/dagger.webp';
    case 'sword': return '/images/sword.webp';
    case 'axe': return '/images/axe.webp';
    default: return null;
  }
};

const getInventoryItemEmoji = (item) => {
  if (!item) return '❓';
  const itemType = item.type || item.name;
  switch (itemType) {
    case 'key': return '🔑';
    case 'chest': return '📦';
    case 'ruby_chest': return '💎';
    case 'dagger': return '🗡️';
    case 'sword': return '⚔️';
    case 'axe': return '🪓';
    case 'fireball': return '🔥';
    case 'teleport': return '✨';
    default: return '💰';
  }
};

const displayMonsterEmoji = computed(() => {
  if (!props.battleInfo) return '👹';
  const monsterType = props.battleInfo.monsterType || '';
  switch (monsterType) {
    case 'dragon': return '🐉';
    case 'skeleton_king': return '👑';
    case 'skeleton_warrior': return '🛡️';
    case 'skeleton_turnkey': return '🦴';
    case 'fallen': return '👻';
    case 'giant_rat': return '🐀';
    case 'giant_spider': return '🕷️';
    case 'mummy': return '🧟';
    default: return '👹';
  }
});

const displayMonsterImage = computed(() => {
  if (!props.battleInfo) return null;
  const battleData = {
    monster_name: props.battleInfo.monsterType || '',
    monster: props.battleInfo.monster || 0
  };
  return getMonsterImage(battleData);
});

const getUsedItemEmoji = (item) => {
  if (!item) return '❓';
  switch (item.type) {
    case 'key': return '🔑';
    case 'dagger': return '🗡️';
    case 'sword': return '⚔️';
    case 'axe': return '🪓';
    case 'fireball': return '🔥';
    case 'teleport': return '✨';
    default: return '🧪';
  }
};

const getWeaponImage = (item) => {
  if (!item || !item.type) return null;
  switch (item.type) {
    case 'dagger': return '/images/dagger.webp';
    case 'sword': return '/images/sword.webp';
    case 'axe': return '/images/axe.webp';
    default: return null;
  }
};

const formattedMonsterName = computed(() => {
  if (!props.battleInfo.monsterType) return 'Monster';
  return getMonsterDisplayName(props.battleInfo.monsterType);
});

const getSpellDisplayName = (item) => {
  if (!item) return 'Unknown';
  const monsterToSpellType = {
    'mummy': 'fireball',
    'giant_spider': 'teleport',
    'giant_rat': 'teleport',
    'skeleton_king': 'fireball',
    'dragon': 'fireball',
    'fallen': 'teleport',
    'skeleton_warrior': 'fireball',
    'skeleton_turnkey': 'teleport'
  };

  if (item.type === 'fireball' || item.type === 'teleport') {
    return formatItemName(item.type);
  }
  if (item.name && monsterToSpellType[item.name]) {
    return formatItemName(monsterToSpellType[item.name]);
  }
  if (item.type && ['dagger', 'sword', 'axe'].includes(item.type)) {
    return formatItemName(item.type);
  }
  return formatItemName(item.type || item.name || 'Unknown');
};

const shouldShowReward = computed(() => {
  if (!props.battleInfo.reward) return false;
  if (props.battleInfo.result === 'win') return true;
  if (showConsumableSelection.value && totalCalculatedDamage.value > props.battleInfo.monster) return true;
  if (props.battleInfo.reward.isPotentialReward) return true;
  return false;
});

const isPotentialReward = computed(() => {
  return props.battleInfo.result !== 'win' &&
         showConsumableSelection.value &&
         potentialVictoryWithConsumables.value;
});

const victoryText = computed(() => {
  if (isPotentialReward.value) return '🎉 Potential Victory Reward!';
  return '🎉 Victory Reward!';
});

const potentialRewardTip = computed(() => {
  if (!isPotentialReward.value) return '';
  const missingDamage = props.battleInfo.monster - (props.battleInfo.diceRollDamage + weaponDamageTotal.value);
  const selectedDamage = consumableDamageTotal.value;
  return `You need ${missingDamage} more damage to win. Your selected consumables provide +${selectedDamage} damage.`;
});
</script>

<style scoped>
/* ============================================
   OVERLAY & CARD (Monad Theme)
   ============================================ */
.battle-overlay {
  position: fixed;
  inset: 0;
  background: var(--bg-overlay, rgba(9, 8, 15, 0.88));
  backdrop-filter: blur(6px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  animation: overlayIn 0.25s ease-out;
}

@keyframes overlayIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.battle-card {
  background: var(--monad-bg-card, #1A1830);
  border: 1px solid rgba(123, 63, 242, 0.25);
  border-radius: 10px;
  width: 400px;
  max-width: 95vw;
  max-height: 90vh;
  overflow: hidden;
  box-shadow: 0 6px 24px rgba(0, 0, 0, 0.5);
  display: flex;
  flex-direction: column;
  color: var(--monad-text-primary, #F5F3FF);
  animation: cardIn 0.35s ease-out;
}

@keyframes cardIn {
  from {
    opacity: 0;
    transform: scale(0.9) translateY(15px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

/* Screen flash */
.screen-flash {
  position: fixed;
  inset: 0;
  pointer-events: none;
  z-index: 1001;
  animation: screenFlash 0.4s ease-out forwards;
}

.flash-victory { background: rgba(76, 175, 80, 0.4); }
.flash-defeat { background: rgba(244, 67, 54, 0.35); }
.flash-draw { background: rgba(255, 152, 0, 0.3); }

@keyframes screenFlash {
  0% { opacity: 0; }
  15% { opacity: 1; }
  100% { opacity: 0; }
}

/* ============================================
   HEADER
   ============================================ */
.battle-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 6px 12px;
  border-bottom: 1px solid rgba(123, 63, 242, 0.2);
  background: linear-gradient(135deg, rgba(123, 63, 242, 0.15) 0%, rgba(167, 139, 250, 0.08) 100%);
}

.header-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.header-label {
  font-size: 0.7em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.12em;
  color: var(--monad-text-secondary, #C4B5FD);
}

.result-title {
  margin: 0;
  font-size: 1.3rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1.5px;
}

.victory-title {
  color: #4caf50;
  text-shadow: 0 0 20px rgba(76, 175, 80, 0.6);
}

.defeat-title {
  color: #f44336;
  text-shadow: 0 0 20px rgba(244, 67, 54, 0.5);
}

.draw-title {
  color: #ff9800;
  text-shadow: 0 0 20px rgba(255, 152, 0, 0.5);
}

.rolling-title {
  color: var(--monad-text-primary, #F5F3FF);
  animation: titlePulse 0.8s infinite ease-in-out;
}

@keyframes titlePulse {
  0%, 100% { opacity: 0.6; }
  50% { opacity: 1; }
}

.result-slam {
  animation: resultSlam 0.7s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes resultSlam {
  0% { transform: scale(2.5); opacity: 0; filter: blur(8px); }
  40% { transform: scale(0.88); opacity: 1; filter: blur(0); }
  65% { transform: scale(1.12); }
  85% { transform: scale(0.97); }
  100% { transform: scale(1); }
}

.close-btn {
  background: none;
  border: none;
  font-size: 1.6rem;
  cursor: pointer;
  color: var(--monad-text-muted, #9CA3AF);
  line-height: 1;
  transition: color 0.2s;
}

.close-btn:hover {
  color: var(--monad-text-primary, #F5F3FF);
}

/* ============================================
   BATTLE BODY
   ============================================ */
.battle-body {
  padding: 6px;
  background: rgba(0, 0, 0, 0.15);
  flex: 1;
  overflow-y: auto;
  min-height: 0;
}

/* ============================================
   BATTLE ARENA (Hero vs Monster)
   ============================================ */
.battle-arena {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 8px;
  margin-bottom: 4px;
  background: rgba(0, 0, 0, 0.25);
  border-radius: 10px;
  border: 1px solid rgba(123, 63, 242, 0.15);
  position: relative;
  overflow: hidden;
}

.combatant {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex: 1;
  gap: 6px;
}

.combatant-sprite-wrap {
  position: relative;
  width: 60px;
  height: 60px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.combatant-glow {
  position: absolute;
  inset: -8px;
  border-radius: 50%;
  opacity: 0.7;
}

.hero-glow {
  background: radial-gradient(circle, rgba(123, 63, 242, 0.3) 0%, transparent 70%);
  animation: glowPulse 2.5s ease-in-out infinite;
}

.monster-glow {
  background: radial-gradient(circle, rgba(244, 67, 54, 0.25) 0%, transparent 70%);
  animation: glowPulse 2.5s ease-in-out infinite 0.5s;
}

@keyframes glowPulse {
  0%, 100% { opacity: 0.5; transform: scale(1); }
  50% { opacity: 1; transform: scale(1.12); }
}

.combatant-sprite {
  width: 52px;
  height: 52px;
  object-fit: contain;
  position: relative;
  filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.5));
}

.monster-emoji-large {
  font-size: 3rem;
  position: relative;
}

/* Character entrance animations */
.hero-entrance {
  animation: heroSlideIn 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}

.monster-entrance {
  animation: monsterSlideIn 0.7s cubic-bezier(0.34, 1.56, 0.64, 1) 0.1s forwards;
  opacity: 0;
}

@keyframes heroSlideIn {
  0% { transform: translateX(-60px) scale(0.7); opacity: 0; }
  60% { transform: translateX(8px) scale(1.05); opacity: 1; }
  100% { transform: translateX(0) scale(1); opacity: 1; }
}

@keyframes monsterSlideIn {
  0% { transform: translateX(60px) scale(0.7); opacity: 0; }
  60% { transform: translateX(-8px) scale(1.05); opacity: 1; }
  100% { transform: translateX(0) scale(1); opacity: 1; }
}

/* Idle breathing */
.hero-idle, .monster-idle {
  animation: characterIdle 2s ease-in-out infinite;
}

.monster-idle {
  animation-delay: 0.5s;
}

@keyframes characterIdle {
  0%, 100% { transform: translateY(0) scale(1); }
  50% { transform: translateY(-3px) scale(1.02); }
}

/* Victory/Defeat/Draw reactions */
.hero-victory {
  animation: victoryCelebration 0.8s ease-out forwards;
}

@keyframes victoryCelebration {
  0% { transform: scale(1); filter: brightness(1); }
  30% { transform: scale(1.18) translateY(-10px); filter: brightness(1.4); }
  60% { transform: scale(1.08) translateY(-5px); filter: brightness(1.2); }
  100% { transform: scale(1.1) translateY(-3px); filter: brightness(1.15); }
}

.hero-defeat {
  animation: defeatRecoil 0.7s ease-out;
}

@keyframes defeatRecoil {
  0%, 100% { transform: translateX(0); filter: brightness(1); }
  15% { transform: translateX(-10px) rotate(-5deg); filter: brightness(0.7); }
  35% { transform: translateX(6px) rotate(3deg); filter: brightness(0.85); }
  55% { transform: translateX(-3px); filter: brightness(0.9); }
}

.hero-draw, .monster-draw {
  animation: drawPulse 1.5s ease-in-out infinite;
}

@keyframes drawPulse {
  0%, 100% { filter: brightness(1) saturate(1); }
  50% { filter: brightness(1.15) saturate(1.2); }
}

.monster-victory {
  animation: monsterTriumph 0.8s ease-out forwards;
}

@keyframes monsterTriumph {
  0% { transform: scale(1); }
  40% { transform: scale(1.15); filter: brightness(1.3); }
  100% { transform: scale(1.1); filter: brightness(1.15); }
}

.monster-defeat {
  animation: monsterDefeat 0.7s ease-out forwards;
}

@keyframes monsterDefeat {
  0% { transform: scale(1); opacity: 1; filter: brightness(1); }
  40% { transform: scale(0.9) rotate(5deg); opacity: 0.7; filter: brightness(0.6); }
  100% { transform: scale(0.85); opacity: 0.5; filter: brightness(0.5) grayscale(0.5); }
}

/* Damage Bar (centered above arena) */
.damage-bar-center {
  width: 100%;
  max-width: 200px;
  margin: 0 auto 2px;
  position: relative;
}

.hp-bar-reveal {
  animation: hpBarReveal 0.4s ease-out;
}

@keyframes hpBarReveal {
  from { opacity: 0.5; transform: translateY(4px); }
  to { opacity: 1; transform: translateY(0); }
}

.hp-bar-track {
  height: 16px;
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.5);
  border: 1px solid rgba(255, 255, 255, 0.1);
  overflow: hidden;
  position: relative;
}

.hp-bar-fill {
  height: 100%;
  border-radius: 8px;
  transition: width 0.8s ease-out 0.1s;
  min-width: 0;
}

/* Bar color = damage result */
.hp-bar-fill.bar-win {
  background: linear-gradient(90deg, #43a047 0%, #66bb6a 100%);
  box-shadow: 0 0 8px rgba(76, 175, 80, 0.5);
}

.hp-bar-fill.bar-lose {
  background: linear-gradient(90deg, #c62828 0%, #ef5350 100%);
}

.hp-bar-fill.bar-draw {
  background: linear-gradient(90deg, #e65100 0%, #ffa726 100%);
  box-shadow: 0 0 6px rgba(255, 152, 0, 0.4);
}

.hp-bar-text {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  font-size: 0.65rem;
  font-weight: 700;
  color: #fff;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.9);
  pointer-events: none;
}

.dealt-hidden {
  opacity: 0;
}

.hp-dealt {
  font-weight: 800;
}

.hp-dealt.dealt-win { color: #81c784; }
.hp-dealt.dealt-lose { color: #ef9a9a; }
.hp-dealt.dealt-draw { color: #ffcc80; }

.hp-separator {
  opacity: 0.5;
  font-size: 0.6rem;
}

.hp-total {
  opacity: 0.7;
}

/* Hero weapon bonuses */
.hero-bonuses {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  flex-wrap: wrap;
}

.monster-name-label {
  font-size: 0.7rem;
  color: var(--monad-text-muted, #9CA3AF);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

/* ============================================
   VS SEPARATOR
   ============================================ */
.vs-separator {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  padding: 0 8px;
}

.vs-energy-line {
  width: 2px;
  height: 10px;
  background: linear-gradient(180deg, transparent, rgba(123, 63, 242, 0.5), transparent);
}

.vs-badge {
  font-size: 1.6rem;
  font-weight: 900;
  line-height: 1;
  padding: 4px;
}

.vs-pulse {
  animation: vsPulse 0.8s infinite ease-in-out;
}

.vs-text {
  background: linear-gradient(135deg, #C4B5FD, #7B3FF2);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  text-shadow: none;
  font-size: 1.2rem;
  letter-spacing: 3px;
}

@keyframes vsPulse {
  0%, 100% { transform: scale(1); opacity: 0.7; }
  50% { transform: scale(1.15); opacity: 1; }
}


/* ============================================
   DICE STAGE
   ============================================ */
.dice-stage {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  margin-bottom: 4px;
  opacity: 0;
  transform: translateY(10px);
  transition: opacity 0.4s ease, transform 0.4s ease;
}

.dice-stage.dice-visible {
  opacity: 1;
  transform: translateY(0);
}

.dice-container {
  display: flex;
  gap: 10px;
  justify-content: center;
  perspective: 1000px;
  min-height: 40px;
}

.dice-face {
  width: 40px;
  height: 40px;
  background:
    radial-gradient(circle at 30% 30%, rgba(255,255,255,0.9) 0%, transparent 50%),
    linear-gradient(145deg, #fafafa 0%, #d8d8d8 50%, #c0c0c0 100%);
  border: 2px solid #888;
  border-radius: 10px;
  display: grid;
  padding: 4px;
  box-shadow:
    0 4px 8px rgba(0,0,0,0.4),
    inset 0 2px 4px rgba(255,255,255,0.8),
    inset 0 -2px 4px rgba(0,0,0,0.2);
  position: relative;
  transform-style: preserve-3d;
  transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.dice-face.rolling-dice {
  animation:
    rollDice3D 0.3s infinite linear,
    floatDice 0.8s infinite ease-in-out;
  box-shadow:
    0 4px 12px rgba(123, 63, 242, 0.4),
    0 0 20px rgba(123, 63, 242, 0.2),
    inset 0 2px 4px rgba(255,255,255,0.8);
}

.dice-face.dice-landed {
  animation: diceLand 0.3s ease-out;
}

@keyframes diceLand {
  0% { transform: scale(1.15); box-shadow: 0 0 20px rgba(255, 255, 255, 0.5); }
  50% { transform: scale(0.95); }
  100% { transform: scale(1); }
}

@keyframes rollDice3D {
  0% { transform: rotateX(0deg) rotateY(0deg) rotateZ(0deg); }
  25% { transform: rotateX(180deg) rotateY(90deg) rotateZ(45deg); }
  50% { transform: rotateX(360deg) rotateY(180deg) rotateZ(90deg); }
  75% { transform: rotateX(540deg) rotateY(270deg) rotateZ(135deg); }
  100% { transform: rotateX(720deg) rotateY(360deg) rotateZ(180deg); }
}

@keyframes floatDice {
  0%, 100% { transform: translateY(0) scale(1); }
  25% { transform: translateY(-6px) scale(1.05); }
  50% { transform: translateY(0) scale(1); }
  75% { transform: translateY(-3px) scale(1.02); }
}

/* Pips */
.pip {
  width: 6px;
  height: 6px;
  background: radial-gradient(circle at 30% 30%, #444 0%, #111 100%);
  border-radius: 50%;
  position: absolute;
  box-shadow: inset 0 1px 2px rgba(0,0,0,0.8), 0 1px 1px rgba(255,255,255,0.1);
}

/* Pip positions for each dice value */
.dice-face[data-value="1"] .pip { top: 50%; left: 50%; transform: translate(-50%, -50%); }

.dice-face[data-value="2"] .pip-2-1 { top: 25%; left: 25%; transform: translate(-50%, -50%); }
.dice-face[data-value="2"] .pip-2-2 { bottom: 25%; right: 25%; transform: translate(50%, 50%); }

.dice-face[data-value="3"] .pip-3-1 { top: 25%; left: 25%; transform: translate(-50%, -50%); }
.dice-face[data-value="3"] .pip-3-2 { top: 50%; left: 50%; transform: translate(-50%, -50%); }
.dice-face[data-value="3"] .pip-3-3 { bottom: 25%; right: 25%; transform: translate(50%, 50%); }

.dice-face[data-value="4"] .pip-4-1 { top: 25%; left: 25%; transform: translate(-50%, -50%); }
.dice-face[data-value="4"] .pip-4-2 { top: 25%; right: 25%; transform: translate(50%, -50%); }
.dice-face[data-value="4"] .pip-4-3 { bottom: 25%; left: 25%; transform: translate(-50%, 50%); }
.dice-face[data-value="4"] .pip-4-4 { bottom: 25%; right: 25%; transform: translate(50%, 50%); }

.dice-face[data-value="5"] .pip-5-1 { top: 25%; left: 25%; transform: translate(-50%, -50%); }
.dice-face[data-value="5"] .pip-5-2 { top: 25%; right: 25%; transform: translate(50%, -50%); }
.dice-face[data-value="5"] .pip-5-3 { top: 50%; left: 50%; transform: translate(-50%, -50%); }
.dice-face[data-value="5"] .pip-5-4 { bottom: 25%; left: 25%; transform: translate(-50%, 50%); }
.dice-face[data-value="5"] .pip-5-5 { bottom: 25%; right: 25%; transform: translate(50%, 50%); }

.dice-face[data-value="6"] .pip-6-1 { top: 25%; left: 25%; transform: translate(-50%, -50%); }
.dice-face[data-value="6"] .pip-6-2 { top: 25%; right: 25%; transform: translate(50%, -50%); }
.dice-face[data-value="6"] .pip-6-3 { top: 50%; left: 25%; transform: translate(-50%, -50%); }
.dice-face[data-value="6"] .pip-6-4 { top: 50%; right: 25%; transform: translate(50%, -50%); }
.dice-face[data-value="6"] .pip-6-5 { bottom: 25%; left: 25%; transform: translate(-50%, 50%); }
.dice-face[data-value="6"] .pip-6-6 { bottom: 25%; right: 25%; transform: translate(50%, 50%); }

.bonuses-reveal {
  animation: bonusesSlideIn 0.3s ease-out;
}

@keyframes bonusesSlideIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

.bonus-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  border-radius: 12px;
  font-size: 0.75rem;
  font-weight: 600;
  background: rgba(76, 175, 80, 0.15);
  border: 1px solid rgba(76, 175, 80, 0.3);
  color: #81c784;
}

.bonus-chip.spell-chip {
  background: rgba(156, 39, 176, 0.15);
  border-color: rgba(156, 39, 176, 0.3);
  color: #ce93d8;
}

.bonus-chip-img {
  width: 18px;
  height: 18px;
  object-fit: contain;
}

.bonus-value {
  font-weight: 700;
}

/* ============================================
   REWARD SECTION
   ============================================ */
/* Combined reward + consumable row */
.reward-consumable-row {
  display: flex;
  gap: 8px;
  margin-bottom: 6px;
  align-items: stretch;
}

.reward-compact {
  flex: 1;
  padding: 8px;
  border-radius: 8px;
  background: rgba(76, 175, 80, 0.08);
  border: 1px solid rgba(76, 175, 80, 0.3);
  display: flex;
  align-items: center;
}

.reward-compact.category-weapon { border-color: rgba(255, 87, 34, 0.4); background: rgba(255, 87, 34, 0.06); }
.reward-compact.category-key { border-color: rgba(255, 193, 7, 0.4); background: rgba(255, 193, 7, 0.06); }
.reward-compact.category-spell { border-color: rgba(156, 39, 176, 0.4); background: rgba(156, 39, 176, 0.06); }
.reward-compact.category-treasure { border-color: rgba(255, 193, 7, 0.4); background: rgba(255, 193, 7, 0.06); }

.reward-compact-inner {
  display: flex;
  align-items: center;
  gap: 8px;
}

.reward-icon-compact {
  position: relative;
  flex-shrink: 0;
}

.reward-img-sm {
  width: 36px;
  height: 36px;
  object-fit: contain;
  filter: drop-shadow(0 1px 4px rgba(0, 0, 0, 0.4));
}

.reward-emoji-sm {
  font-size: 1.6rem;
}

.reward-compact-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.reward-compact-name {
  font-weight: 600;
  font-size: 0.85rem;
  color: var(--monad-text-primary, #F5F3FF);
}

.badge-sm {
  font-size: 0.65rem;
  padding: 1px 6px;
}

.consumable-compact {
  flex: 0 0 auto;
  padding: 8px;
  border-radius: 8px;
  background: rgba(123, 63, 242, 0.06);
  border: 1px solid rgba(123, 63, 242, 0.2);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.consumable-compact-label {
  font-size: 0.65rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--monad-text-muted, #9CA3AF);
}

.consumable-compact-list {
  display: flex;
  gap: 6px;
}

.reward-section {
  position: relative;
  margin-bottom: 4px;
}

.reward-rise {
  animation: rewardRise 0.6s ease-out;
}

@keyframes rewardRise {
  0% { transform: translateY(25px); opacity: 0; }
  60% { transform: translateY(-3px); opacity: 1; }
  100% { transform: translateY(0); opacity: 1; }
}

.reward-card {
  padding: 8px 10px;
  border-radius: 8px;
  background: linear-gradient(135deg, rgba(76, 175, 80, 0.1) 0%, rgba(76, 175, 80, 0.05) 100%);
  border: 1px solid rgba(76, 175, 80, 0.4);
}

.reward-card.category-weapon { border-color: rgba(255, 87, 34, 0.5); }
.reward-card.category-key { border-color: rgba(255, 193, 7, 0.5); }
.reward-card.category-spell { border-color: rgba(156, 39, 176, 0.5); }
.reward-card.category-treasure { border-color: rgba(255, 193, 7, 0.5); }

/* Inline reward layout (compact) */
.reward-item-inline {
  display: flex;
  align-items: center;
  gap: 10px;
}

.reward-inline-img {
  width: 40px;
  height: 40px;
  object-fit: contain;
  flex-shrink: 0;
  filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.4));
}

.reward-inline-emoji {
  font-size: 1.8rem;
  flex-shrink: 0;
}

.reward-inline-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.reward-monster-img {
  width: 36px;
  height: 36px;
  object-fit: contain;
  flex-shrink: 0;
}

.potential-badge {
  position: absolute;
  top: -4px;
  right: -6px;
  background: #ff9800;
  color: white;
  border-radius: 50%;
  width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7rem;
  font-weight: bold;
  border: 2px solid var(--monad-bg-card, #1A1830);
}

.reward-details {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.reward-item-name {
  font-weight: 700;
  font-size: 0.95rem;
  color: var(--monad-text-primary, #F5F3FF);
}

.reward-badges {
  display: flex;
  gap: 6px;
}

.stat-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 10px;
  border-radius: 16px;
  font-size: 0.8rem;
  font-weight: 600;
}

.badge-damage {
  background: rgba(255, 87, 34, 0.15);
  color: #FF8A65;
  border: 1px solid rgba(255, 87, 34, 0.3);
}

.badge-value {
  background: rgba(255, 193, 7, 0.15);
  color: #FFD54F;
  border: 1px solid rgba(255, 193, 7, 0.3);
}

.reward-note {
  font-size: 0.72rem;
  font-style: italic;
}

.reward-note.success { color: #4caf50; }
.reward-note.potential { color: #ff9800; }

.no-reward {
  color: var(--monad-text-muted, #9CA3AF);
  font-style: italic;
  font-size: 0.9rem;
  text-align: center;
}

.generic-reward {
  border-left: 3px dashed #ffd700;
  background: rgba(255, 215, 0, 0.06);
  padding: 8px 12px;
  border-radius: 6px;
  animation: pulseBorder 2s infinite;
}

@keyframes pulseBorder {
  0%, 100% { border-color: #ffd700; }
  50% { border-color: #ff9800; }
}

/* Victory sparkles */
.victory-sparkles {
  position: absolute;
  top: 50%;
  left: 50%;
  pointer-events: none;
  z-index: 1;
}

.sparkle {
  position: absolute;
  width: 6px;
  height: 6px;
  background: #ffd700;
  border-radius: 50%;
  box-shadow: 0 0 6px #ffd700, 0 0 12px rgba(255, 215, 0, 0.5);
  animation: sparkleFloat 1.2s ease-out forwards;
}

@keyframes sparkleFloat {
  0% { transform: translate(0, 0) scale(0); opacity: 1; }
  50% { opacity: 1; }
  100% { transform: translate(var(--sparkle-x), var(--sparkle-y)) scale(1.2); opacity: 0; }
}

/* ============================================
   ITEMS SECTION (Consumables / Inventory)
   ============================================ */
.items-section {
  margin-bottom: 6px;
  padding: 8px;
  border-radius: 8px;
  background: rgba(123, 63, 242, 0.06);
  border: 1px solid rgba(123, 63, 242, 0.2);
}

.section-reveal {
  animation: sectionFadeIn 0.3s ease-out;
}

@keyframes sectionFadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

.items-section-title {
  font-weight: 600;
  margin-bottom: 8px;
  color: var(--monad-text-secondary, #C4B5FD);
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.items-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.item-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 16px;
  font-size: 0.82rem;
  color: var(--monad-text-primary, #F5F3FF);
  background: rgba(123, 63, 242, 0.12);
  border: 1px solid rgba(123, 63, 242, 0.25);
}

.item-chip.consumable-used {
  background: linear-gradient(135deg, rgba(123, 63, 242, 0.2) 0%, rgba(99, 102, 241, 0.15) 100%);
  border-color: rgba(123, 63, 242, 0.4);
}

.chip-emoji {
  font-size: 1rem;
}

.chip-img {
  width: 20px;
  height: 20px;
  object-fit: contain;
}

.chip-name {
  font-weight: 500;
  font-size: 0.8rem;
}

.chip-damage {
  color: #81c784;
  font-weight: 600;
  font-size: 0.75rem;
}

.chip-value {
  color: #ffd700;
  font-weight: 600;
  font-size: 0.75rem;
}

/* Selectable items */
.item-chip.selectable {
  cursor: pointer;
  transition: all 0.2s;
}

.item-chip.selectable:hover {
  background: rgba(123, 63, 242, 0.2);
  border-color: rgba(123, 63, 242, 0.5);
  transform: translateY(-1px);
}

.item-chip.selectable.selected {
  background: rgba(76, 175, 80, 0.2);
  border-color: rgba(76, 175, 80, 0.5);
  box-shadow: 0 0 8px rgba(76, 175, 80, 0.2);
}

/* Inventory replacement items */
.item-chip.inventory-replace {
  border: 2px solid rgba(244, 67, 54, 0.4);
  background: rgba(244, 67, 54, 0.08);
  transition: all 0.2s ease;
}

.item-chip.inventory-replace:hover {
  background: rgba(244, 67, 54, 0.15);
  border-color: rgba(244, 67, 54, 0.6);
  transform: scale(1.03);
}

.item-chip.inventory-replace.selected {
  background: rgba(244, 67, 54, 0.25);
  border-color: #ff6b6b;
  box-shadow: 0 4px 12px rgba(244, 67, 54, 0.3);
  transform: scale(1.05);
}

.item-chip.inventory-replace.selected::after {
  content: '✓';
  position: absolute;
  top: -6px;
  right: -6px;
  background: #4caf50;
  color: white;
  border-radius: 50%;
  width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: bold;
  border: 2px solid var(--monad-bg-card, #1A1830);
}

.item-chip.inventory-replace {
  position: relative;
}

/* ============================================
   FOOTER & BUTTONS
   ============================================ */
.battle-footer {
  padding: 8px 12px;
  border-top: 1px solid rgba(123, 63, 242, 0.2);
  background: linear-gradient(180deg, rgba(26, 24, 48, 0.8) 0%, rgba(15, 14, 28, 0.9) 100%);
  flex-shrink: 0;
}

.footer-reveal {
  animation: footerSlideIn 0.4s ease-out 0.2s both;
}

@keyframes footerSlideIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

.button-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
  align-items: center;
  width: 100%;
}

.button-group button {
  width: 90%;
  max-width: 360px;
}

/* Primary button (Monad purple gradient) */
.btn-primary {
  background: var(--monad-gradient-primary, linear-gradient(135deg, #7B3FF2 0%, #A78BFA 100%));
  color: #fff;
  border: none;
  padding: 9px 16px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.9rem;
  font-weight: 600;
  transition: all 0.2s ease;
  box-shadow: 0 4px 14px rgba(123, 63, 242, 0.35);
}

.btn-primary:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(123, 63, 242, 0.5);
}

.btn-primary:disabled {
  background: rgba(123, 63, 242, 0.3);
  cursor: not-allowed;
  opacity: 0.5;
  transform: none;
  box-shadow: none;
}

/* Secondary button */
.btn-secondary {
  background: transparent;
  color: var(--monad-text-secondary, #C4B5FD);
  border: 1px solid rgba(123, 63, 242, 0.25);
  padding: 9px 16px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.9rem;
  font-weight: 600;
  transition: all 0.2s ease;
}

.btn-secondary:hover:not(:disabled) {
  background: rgba(123, 63, 242, 0.1);
  transform: translateY(-2px);
}

.btn-secondary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  transform: none;
}

/* Draw button */
.btn-draw {
  background: linear-gradient(135deg, #e65100, #ff9800);
  color: #fff;
  border: none;
  padding: 9px 16px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.9rem;
  font-weight: 600;
  transition: all 0.2s ease;
  box-shadow: 0 4px 14px rgba(255, 152, 0, 0.3);
}

.btn-draw:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(255, 152, 0, 0.4);
}

.btn-draw:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  transform: none;
}

/* Defeat/Retreat button */
.btn-defeat {
  background: transparent;
  color: var(--monad-text-muted, #9CA3AF);
  border: 1px solid rgba(156, 163, 175, 0.25);
  padding: 9px 16px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.9rem;
  font-weight: 600;
  transition: all 0.2s ease;
}

.btn-defeat:hover:not(:disabled) {
  background: rgba(244, 67, 54, 0.08);
  border-color: rgba(244, 67, 54, 0.3);
  transform: translateY(-2px);
}

.btn-defeat:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  transform: none;
}

/* Danger button (cancel) */
.btn-danger {
  background: linear-gradient(135deg, #d32f2f, #f44336);
  color: #fff;
  border: none;
  padding: 9px 16px;
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.9rem;
  font-weight: 600;
  transition: all 0.2s ease;
  box-shadow: 0 4px 14px rgba(244, 67, 54, 0.3);
}

.btn-danger:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(244, 67, 54, 0.4);
}

.btn-danger:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  transform: none;
}

/* ============================================
   KBD HINTS
   ============================================ */
.kbd-hint {
  font-size: 0.75em;
  opacity: 0.5;
  margin-left: 4px;
}

@media (hover: none) {
  .kbd-hint { display: none; }
}

/* ============================================
   RESPONSIVE
   ============================================ */
@media (max-width: 480px) {
  .battle-card {
    width: 100%;
    max-width: 100vw;
    border-radius: 10px;
  }

  .battle-arena {
    padding: 10px 4px;
  }

  .combatant-sprite-wrap {
    width: 56px;
    height: 56px;
  }

  .combatant-sprite {
    width: 48px;
    height: 48px;
  }

  .damage-bar-center {
    max-width: 160px;
  }

  .vs-badge {
    font-size: 1.2rem;
  }

  .dice-face {
    width: 40px;
    height: 40px;
  }

  .pip {
    width: 6px;
    height: 6px;
  }

  .reward-inline-img {
    width: 32px;
    height: 32px;
  }

  .battle-header {
    padding: 8px 12px;
  }

  .battle-body {
    padding: 8px;
  }

  .battle-footer {
    padding: 10px 12px;
  }
}
</style>
