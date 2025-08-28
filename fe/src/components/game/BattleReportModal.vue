<template>
  <div class="battle-report-modal">
    <div class="battle-report-content">
      <div class="battle-report-header">
        <h3
          :class="{
            'victory-title': dynamicResult === 'win',
            'defeat-title': dynamicResult === 'loose',
            'draw-title': dynamicResult === 'draw'
          }"
        >
          {{ dynamicResultText }}
        </h3>
        <button
          class="close-battle-btn"
          @click="leaveItemAndEndTurn"
        >
          ×
        </button>
      </div>
      
      <div class="battle-report-body">
        <div class="damage-comparison">
          <div class="player-damage">
            <div class="big-number">
              {{ totalCalculatedDamage }}
            </div>
            <div class="damage-details">
              <div class="dice-results">
                <div class="dice-container">
                  <div
                    v-for="(value, index) in battleInfo.diceResults"
                    :key="index"
                    class="dice-face"
                    :data-value="value"
                  >
                    <span 
                      v-for="pip in value"
                      :key="`pip-${index}-${pip}`"
                      class="pip"
                      :class="`pip-${value}-${pip}`"
                    ></span>
                  </div>
                </div>
                <div
                  v-if="equippedWeapons.length > 0 || consumableDamageTotal > 0"
                  class="damage-breakdown"
                >
                  <span
                    v-for="(weapon, index) in equippedWeapons"
                    :key="`breakdown-weapon-${index}`"
                    class="weapon-text"
                  >
                    {{ getUsedItemEmoji(weapon) }} +{{ getItemTypeDamage(weapon.type) }}
                  </span>
                  <span
                    v-if="consumableDamageTotal > 0"
                    class="consumable-text"
                  >
                    🔮 +{{ consumableDamageTotal }}
                  </span>
                </div>
              </div>
            </div>
          </div>
          
          <div
            class="comparison-symbol"
            :class="{
              'greater-than': totalCalculatedDamage > battleInfo.monster,
              'less-than': totalCalculatedDamage < battleInfo.monster,
              'equal-to': totalCalculatedDamage === battleInfo.monster
            }"
          >
            {{ totalCalculatedDamage > battleInfo.monster ? '>' : 
              (totalCalculatedDamage < battleInfo.monster ? '<' : '=') }}
          </div>
          
          <div class="monster-stats">
            <div class="big-number">
              {{ battleInfo.monster }}
            </div>
            <div class="monster-details">
              <img 
                v-if="displayMonsterImage"
                :src="displayMonsterImage"
                :alt="'Monster'"
                class="monster-battle-image"
              />
              <div v-else class="monster-emoji">
                {{ displayMonsterEmoji }}
              </div>
            </div>
          </div>
        </div>
        
        <!-- Modify the reward section to also display when consumables would result in victory -->
        <div
          v-if="shouldShowReward || (showConsumableSelection && potentialVictoryWithConsumables)"
          class="reward-section"
        >
          <div class="reward-content">
            <div
              v-if="battleInfo.reward"
              class="reward-item"
            >
              <div class="reward-icon">
                <span class="item-emoji">{{ displayItemEmoji }}</span>
                <span
                  v-if="isPotentialReward && selectedConsumables.length === 0"
                  class="potential-badge"
                >?</span>
              </div>
              <div class="item-details">
                <div class="item-header">
                  <span class="item-name">{{ formattedItemName }}</span>
                  <span
                    v-if="getItemTypeDamage(battleInfo.reward.type) > 0"
                    class="item-damage-badge"
                  >+{{ getItemTypeDamage(battleInfo.reward.type) }}</span>
                  <span
                    v-if="battleInfo.reward.treasureValue && battleInfo.reward.treasureValue > 0"
                    class="item-value-badge"
                  >💰 {{ battleInfo.reward.treasureValue }}</span>
                </div>
                <div
                  v-if="isGuardChestReward"
                  class="auto-collect-info"
                >
                  Chest auto-opened!
                </div>
                <div
                  v-if="isPotentialReward && selectedConsumables.length === 0"
                  class="potential-info"
                >
                  Will be yours if you use consumables
                </div>
              </div>
            </div>
            <div
              v-else-if="showConsumableSelection && potentialVictoryWithConsumables"
              class="reward-item generic-reward"
            >
              <img 
                v-if="displayMonsterImage"
                :src="displayMonsterImage"
                :alt="'Monster'"
                class="monster-reward-image"
              />
              <div v-else class="item-emoji">
                {{ displayMonsterEmoji }}
              </div>
              <div class="item-details">
                <div class="item-name">
                  Monster Treasure
                </div>
                <div class="potential-reward-notice">
                  Using your consumables will defeat the monster and reveal its treasure!
                </div>
              </div>
            </div>
            <div
              v-else
              class="no-reward"
            >
              Monster defeated! No treasure found.
            </div>
          </div>
        </div>
        
        <!-- Merged items section - shows used consumables and selectable consumables -->
        <div
          v-if="(usedConsumables && usedConsumables.length > 0) || showConsumableSelection || showInventorySelection"
          class="used-items-section"
        >
          <div class="used-items-title">
            {{ showConsumableSelection ? 'Select consumables to use:' : 
              showInventorySelection ? 'Choose an item to replace:' : 
              usedConsumables && usedConsumables.length > 0 ? 'Used consumables:' : '' }}
          </div>
          <div class="used-items-list">
            <!-- Show only used consumables (non-selectable) only when not doing inventory replacement -->
            <div
              v-for="(item, index) in usedConsumables"
              v-if="!showInventorySelection && !showConsumableSelection"
              :key="`used-${index}`"
              class="used-item consumable-chip"
            >
              <span class="item-emoji">{{ getUsedItemEmoji(item) }}</span>
              <span class="item-name">{{ getCorrectItemName(item) }}</span>
              <span class="item-damage">+{{ getItemTypeDamage(item.type) }}</span>
            </div>
            
            <!-- Show selectable consumable items -->
            <div 
              v-for="(item, index) in availableConsumables"
              v-if="showConsumableSelection" 
              :key="`consumable-${index}`"
              class="used-item selectable-item"
              :class="{ 'selected': selectedConsumables.includes(item.itemId) }"
              @click="toggleConsumable(item)"
            >
              <span class="item-emoji">{{ getInventoryItemEmoji(item) }}</span>
              <span class="item-name">{{ getSpellDisplayName(item) }}</span>
              <span class="item-damage">+{{ getItemTypeDamage(item.type) }}</span>
            </div>
            
            <!-- Show selectable inventory items for replacement -->
            <div 
              v-for="(item, index) in inventoryForSelection"
              v-if="showInventorySelection" 
              :key="`inventory-${index}`"
              class="used-item selectable-item inventory-item-replace"
              :class="{ 'selected': selectedItemForReplacement?.itemId === item.itemId }"
              @click="selectedItemForReplacement = item"
            >
              <span class="item-emoji">{{ getInventoryItemEmoji(item) }}</span>
              <span class="item-name">{{ formatItemName(item.type || item.name) }}</span>
              <span
                v-if="getItemTypeDamage(item.type || item.name) > 0"
                class="item-damage"
              >+{{ getItemTypeDamage(item.type || item.name) }}</span>
              <span
                v-else-if="item.treasureValue > 0"
                class="item-value"
              >💰{{ item.treasureValue }}</span>
            </div>
          </div>
        </div>

        <!-- OLD INVENTORY SELECTION SECTION - REMOVED -->
        <!--
        <div v-if="showInventorySelection" class="inventory-selection">
          <div class="selection-title">Inventory is full! Choose an item to replace:</div>
          <div class="inventory-items">
            <div 
              v-for="(item, index) in inventoryForSelection" 
              :key="index"
              class="inventory-item"
              :class="{ 'selected': selectedItemForReplacement?.itemId === item.itemId }"
              @click="selectedItemForReplacement = item"
            >
              <div class="item-emoji">{{ getInventoryItemEmoji(item) }}</div>
              <div class="item-name">{{ formatItemName(item.name || item.type) }}</div>
              <div v-if="item.treasureValue > 0" class="item-value">💰 {{ item.treasureValue }}</div>
            </div>
          </div>
        </div>
        -->
      </div>
      
      <div class="battle-report-footer">
        <!-- Show different buttons based on victory state and inventory status -->
        <!-- Special case for keys: if player already has a key, only show end turn button -->
        <div v-if="battleInfo.result === 'win' && battleInfo.reward && isKeyReward && !hasInventorySpace && !showInventorySelection && !showConsumableSelection">
          <button
            class="end-turn-btn"
            @click="leaveItemAndEndTurn"
          >
            End Turn (All keys are the same)
          </button>
        </div>
        <!-- Special case for chest rewards from defeated guards: treasure is automatically collected -->
        <div v-else-if="battleInfo.result === 'win' && battleInfo.reward && isGuardChestReward && !showInventorySelection && !showConsumableSelection">
          <button
            class="pick-up-btn"
            @click="pickUpAndEndTurn"
          >
            End Turn (Treasure collected automatically)
          </button>
        </div>
        <!-- Normal victory with reward (non-key, non-guard-chest, or has inventory space) -->
        <div v-else-if="battleInfo.result === 'win' && battleInfo.reward && !showInventorySelection && !showConsumableSelection" class="button-group">
          <button
            class="pick-up-btn"
            @click="pickUpAndEndTurn"
          >
            🎒 Pick up and end turn
          </button>
          <!-- Only show leave item button when inventory is full (and not a key or guard chest) -->
          <button
            v-if="!hasInventorySpace && !isKeyReward && !isGuardChestReward"
            class="leave-item-btn"
            @click="leaveItemAndEndTurn"
          >
            ⏭️ Leave item and end turn
          </button>
        </div>
        <div v-else-if="showConsumableSelection" class="button-group">
          <!-- Show victory buttons if consumables would improve outcome -->
          <template v-if="potentialImprovementWithConsumables">
            <button
              v-if="potentialVictoryWithConsumables"
              class="pick-up-btn"
              @click="finalizeBattleAndPickUp"
            >
              🎒 Fight, win, and pick up reward
            </button>
            <button
              v-else
              class="finalize-battle-btn"
              @click="finalizeBattleWithConsumables"
            >
              ⚔️ Fight for a draw
            </button>
            <!-- Only show leave item button when inventory is full for the reward type -->
            <button
              v-if="!hasInventorySpaceForEstimatedReward"
              class="leave-item-btn"
              @click="finalizeBattleAndLeaveItem"
            >
              ⏭️ Fight, win, and leave reward
            </button>
          </template>
          <!-- Show standard finalization buttons if consumables wouldn't result in victory -->
          <template v-else>
            <button 
              v-if="selectedConsumables.length > 0"
              class="finalize-battle-btn" 
              @click="finalizeBattleWithConsumables"
            >
              ⚔️ Fight with selected items
            </button>
            <button
              class="accept-defeat-btn"
              @click="finalizeBattleWithoutConsumables"
            >
              {{ battleInfo.result === 'draw' ? '⬅️ Retreat without consumables' : '😵 Accept defeat (lose 1 HP)' }}
            </button>
          </template>
        </div>
        <div v-else-if="showInventorySelection" class="button-group">
          <button 
            :disabled="!selectedItemForReplacement" 
            class="confirm-replacement-btn"
            @click="confirmReplacement"
          >
            ✅ Replace and end turn
          </button>
          <button
            class="cancel-replacement-btn"
            @click="cancelReplacement"
          >
            ❌ Cancel
          </button>
        </div>
        <div v-else>
          <button
            class="end-turn-btn"
            @click="leaveItemAndEndTurn"
          >
            End Turn
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { defineProps, defineEmits, computed, ref, watch, nextTick } from 'vue';
import { getMonsterImage } from '@/utils/monsterUtils';

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

// Reactive data for inventory selection
const showInventorySelection = ref(false);
const inventoryForSelection = ref([]);
const selectedItemForReplacement = ref(null);
const pendingPickupItem = ref(null);

// Reactive data for consumable selection
const showConsumableSelection = ref(false);
const availableConsumables = ref([]);
const selectedConsumables = ref([]);

// Add flag to track if battle has been finalized
const battleFinalized = ref(false);

// Helper function to get damage value for an item type
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

// Computed to check if using all consumables would result in victory
const potentialVictoryWithConsumables = computed(() => {
  if (!showConsumableSelection.value || !availableConsumables.value) return false;
  
  const currentDamage = (props.battleInfo.diceRollDamage || 0) + weaponDamageTotal.value;
  const maxConsumableDamage = availableConsumables.value
    .filter(item => getItemTypeDamage(item.type) > 0)
    .reduce((total, item) => total + getItemTypeDamage(item.type), 0);
  
  // Only return true for actual victory (not draw)
  return (currentDamage + maxConsumableDamage) > props.battleInfo.monster;
});

// Computed to check if consumables could improve outcome (draw or win)
const potentialImprovementWithConsumables = computed(() => {
  if (!showConsumableSelection.value || !availableConsumables.value) return false;
  
  const currentDamage = (props.battleInfo.diceRollDamage || 0) + weaponDamageTotal.value;
  const maxConsumableDamage = availableConsumables.value
    .filter(item => getItemTypeDamage(item.type) > 0)
    .reduce((total, item) => total + getItemTypeDamage(item.type), 0);
  
  const totalPossibleDamage = currentDamage + maxConsumableDamage;
  
  // Return true if consumables would improve the outcome
  if (props.battleInfo.result === 'loose') {
    return totalPossibleDamage >= props.battleInfo.monster; // Can achieve draw or win
  }
  if (props.battleInfo.result === 'draw') {
    return totalPossibleDamage > props.battleInfo.monster; // Can achieve win
  }
  return false;
});

// Computed property for dynamic battle result based on consumable selection
const dynamicResult = computed(() => {
  // If consumables are being selected, calculate potential outcome
  if (showConsumableSelection.value && selectedConsumables.value.length > 0) {
    const totalDamage = totalCalculatedDamage.value;
    const monsterHP = props.battleInfo.monster;
    
    if (totalDamage > monsterHP) return 'win';
    if (totalDamage === monsterHP) return 'draw';
    return 'loose';
  }
  
  // Otherwise use the actual battle result
  return props.battleInfo.result;
});

// Computed property for dynamic header text
const dynamicResultText = computed(() => {
  // If consumables would change the outcome, show updated text
  if (showConsumableSelection.value && selectedConsumables.value.length > 0) {
    const totalDamage = totalCalculatedDamage.value;
    const monsterHP = props.battleInfo.monster;
    
    if (totalDamage > monsterHP) {
      return 'Victory!';
    }
    if (totalDamage === monsterHP) {
      return 'Draw';
    }
    return 'Defeat';
  }
  
  // Default text based on original result
  if (props.battleInfo.result === 'win') return 'Victory!';
  if (props.battleInfo.result === 'draw') return 'Draw';
  return 'Defeat';
});

// Computed to filter used consumables
const usedConsumables = computed(() => {
  if (!props.battleInfo.usedItems) return [];
  // Filter for consumables only (fireball, teleport)
  return props.battleInfo.usedItems.filter(item => 
    item.type && ['fireball', 'teleport'].includes(item.type)
  );
});

// Computed to filter equipped weapons
const equippedWeapons = computed(() => {
  if (!props.battleInfo.usedItems) return [];
  // Filter for weapons only (dagger, sword, axe)
  return props.battleInfo.usedItems.filter(item => 
    item.type && ['dagger', 'sword', 'axe'].includes(item.type)
  );
});

// Computed properties for damage calculations
const weaponDamageTotal = computed(() => {
  if (!props.battleInfo.usedItems) return 0;
  
  // Calculate damage from weapons only (non-consumables)
  return props.battleInfo.usedItems
    .filter(item => item.type && ['dagger', 'sword', 'axe'].includes(item.type))
    .reduce((total, item) => total + getItemTypeDamage(item.type), 0);
});

const consumableDamageTotal = computed(() => {
  if (!availableConsumables.value || selectedConsumables.value.length === 0) return 0;
  
  // Calculate damage from selected consumables
  return availableConsumables.value
    .filter(item => selectedConsumables.value.includes(item.itemId))
    .reduce((total, item) => total + getItemTypeDamage(item.type), 0);
});

const totalCalculatedDamage = computed(() => {
  return (props.battleInfo.diceRollDamage || 0) + (props.battleInfo.itemDamage || 0) + consumableDamageTotal.value;
});

// Computed property to check if the reward is a key
const isKeyReward = computed(() => {
  if (!props.battleInfo.reward) return false;
  
  // Check both type and name fields to be thorough
  const reward = props.battleInfo.reward;
  const isKeyType = reward.type === 'key';
  const isKeyName = reward.name === 'key' || (typeof reward.name === 'string' && reward.name.toLowerCase().includes('key'));
  
  // Log debug info
  console.log('Key reward check:', {
    rewardType: reward.type,
    rewardName: reward.name,
    isKeyType,
    isKeyName,
    hasInventorySpace: props.hasInventorySpace
  });
  
  return isKeyType || isKeyName;
});

// Computed property to check if the reward is a chest from a defeated guard
const isGuardChestReward = computed(() => {
  if (!props.battleInfo.reward) return false;
  
  const reward = props.battleInfo.reward;
  
  // Check if the backend has explicitly marked this as auto-collected
  if (reward.hasOwnProperty('autoCollected')) {
    return reward.autoCollected === true;
  }
  
  // Fallback logic for backward compatibility
  const isChestType = reward.type === 'chest' || reward.type === 'ruby_chest';
  const hasDirectTreasureValue = reward.treasureValue && reward.treasureValue > 0;
  
  // If it's a chest with treasure value, it's from a defeated guard
  // These are automatically opened and the treasure value is collected
  return isChestType && hasDirectTreasureValue;
});

// Computed property to check if there's inventory space for the reward when using consumables
const hasInventorySpaceForEstimatedReward = computed(() => {
  // For consumable selection, we assume the reward would be the same as shown in battleInfo
  // This is a reasonable assumption since the reward is determined by the monster/item, not the battle outcome
  return props.hasInventorySpace;
});

// Initialize consumable selection if needed
const initializeConsumableSelection = () => {
  console.log('initializeConsumableSelection called with battleInfo:', props.battleInfo);
  console.log('needsConsumableConfirmation:', props.battleInfo.needsConsumableConfirmation);
  console.log('availableConsumables:', props.battleInfo.availableConsumables);
  
  // Don't show consumable selection if battle has already been finalized
  if (battleFinalized.value) {
    console.log('Battle already finalized, skipping consumable selection');
    showConsumableSelection.value = false;
    return;
  }
  
  // Make sure the reward object is properly initialized if needed
  if (!props.battleInfo.reward && props.battleInfo.monster && props.battleInfo.monsterType) {
    console.log('No reward in battleInfo, but monster exists. This might be a bug.');
    // The reward field should be populated by the backend, but if missing for some reason
    // we won't try to construct it here since we don't know the reward structure
  }
  
  if (props.battleInfo.needsConsumableConfirmation && props.battleInfo.availableConsumables) {
    // Check if player did not win (includes both draw and lose)
    const didNotWin = props.battleInfo.result !== 'win';
    
    console.log('Player did not win:', didNotWin, 'result:', props.battleInfo.result);
    
    if (didNotWin) {
      // Calculate if consumables would be enough to change the outcome
      const currentDamage = (props.battleInfo.diceRollDamage || 0) + weaponDamageTotal.value;
      const consumablesWithDamage = props.battleInfo.availableConsumables.filter(item => getItemTypeDamage(item.type) > 0);
      
      console.log('Current damage:', currentDamage, 'consumables with damage:', consumablesWithDamage);
      
      // If no consumables with damage available, skip interface
      if (consumablesWithDamage.length === 0) {
        console.log('No damage-dealing consumables available, skipping consumables interface');
        console.log('Setting showConsumableSelection to false');
        showConsumableSelection.value = false;
        
        // IMPORTANT: Don't emit any events here - let the template handle the UI
        console.log('Template should now show End Turn button in final else clause');
        return;
      }
      
      // Calculate maximum possible damage with all consumables
      const maxConsumableDamage = consumablesWithDamage.reduce((total, item) => total + getItemTypeDamage(item.type), 0);
      const maxPossibleDamage = currentDamage + maxConsumableDamage;
      
      // Always show interface if player can potentially win with consumables
      // Even for draws, player might want to win to avoid having to retreat
      if (maxPossibleDamage > props.battleInfo.monster) {
        console.log(`Consumables could change outcome: ${maxPossibleDamage} damage vs ${props.battleInfo.monster} HP`);
        showConsumableSelection.value = true;
        availableConsumables.value = props.battleInfo.availableConsumables;
        // Start with no consumables selected - let player choose
        selectedConsumables.value = [];
        return;
      }
      
      // Show interface if consumables can achieve a draw (avoiding HP loss)
      if (maxPossibleDamage >= props.battleInfo.monster) {
        console.log(`Consumables could achieve draw: ${maxPossibleDamage} damage vs ${props.battleInfo.monster} HP`);
        showConsumableSelection.value = true;
        availableConsumables.value = props.battleInfo.availableConsumables;
        selectedConsumables.value = [];
        return;
      }
      
      // If consumables can't change the outcome at all, don't show interface
      console.log(`Consumables can't change outcome: ${maxPossibleDamage} damage vs ${props.battleInfo.monster} HP, skipping interface`);
      showConsumableSelection.value = false;
      return;
    }
    
    // If player won initially, no need for consumable selection
    console.log('Player won initially, no consumable selection needed');
    showConsumableSelection.value = false;
  } else {
    console.log('No consumable confirmation needed or no available consumables');
    showConsumableSelection.value = false;
  }
};

// Watch for changes in battleInfo to initialize consumable selection
watch(() => props.battleInfo, (newBattleInfo, oldBattleInfo) => {
  // Reset battleFinalized flag if this is a new battle
  if (!oldBattleInfo || newBattleInfo?.battleId !== oldBattleInfo?.battleId) {
    battleFinalized.value = false;
  }
  
  initializeConsumableSelection();
}, { immediate: true });

const toggleConsumable = (item) => {
  const index = selectedConsumables.value.indexOf(item.itemId);
  if (index === -1) {
    // Add the consumable
    selectedConsumables.value.push(item.itemId);
    
    // Check if this selection changes the battle outcome
    const newTotalDamage = (props.battleInfo.diceRollDamage || 0) + weaponDamageTotal.value + consumableDamageTotal.value;
    
    // Log for debugging
    console.log(`Toggled consumable ${item.name || item.type}. New total damage: ${newTotalDamage} vs monster HP: ${props.battleInfo.monster}`);
    
    // Check if we just crossed the victory threshold
    if (newTotalDamage > props.battleInfo.monster && 
        newTotalDamage - getItemTypeDamage(item.type) <= props.battleInfo.monster) {
      console.log('This selection would lead to victory!');
      
      // Force re-evaluation of computed properties
      nextTick(() => {
        console.log('Re-evaluating computed properties after crossing victory threshold');
        console.log('Current total damage:', totalCalculatedDamage);
        console.log('Monster HP:', props.battleInfo.monster);
        console.log('Would show reward:', shouldShowReward.value);
      });
    }
  } else {
    // Remove the consumable
    selectedConsumables.value.splice(index, 1);
    
    // Check if this deselection changes the battle outcome
    const newTotalDamage = (props.battleInfo.diceRollDamage || 0) + weaponDamageTotal.value + consumableDamageTotal.value;
    
    // Log for debugging
    console.log(`Removed consumable ${item.name || item.type}. New total damage: ${newTotalDamage} vs monster HP: ${props.battleInfo.monster}`);
    
    // Check if we just dropped below the victory threshold
    if (newTotalDamage <= props.battleInfo.monster && 
        newTotalDamage + getItemTypeDamage(item.type) > props.battleInfo.monster) {
      console.log('This deselection would prevent victory!');
      
      // Force re-evaluation of computed properties
      nextTick(() => {
        console.log('Re-evaluating computed properties after dropping below victory threshold');
        console.log('Current total damage:', totalCalculatedDamage);
        console.log('Monster HP:', props.battleInfo.monster);
        console.log('Would show reward:', shouldShowReward.value);
      });
    }
  }
};

const finalizeBattleWithConsumables = () => {
  if (!props.battleInfo.battleId) {
    console.error('No battleId available, cannot finalize battle');
    return;
  }
  
  battleFinalized.value = true;
  emit('finalize-battle', {
    battleId: props.battleInfo.battleId,
    selectedConsumableIds: selectedConsumables.value
  });
  
  // Reset state
  showConsumableSelection.value = false;
  selectedConsumables.value = [];
  availableConsumables.value = [];
};

const finalizeBattleWithoutConsumables = () => {
  battleFinalized.value = true;
  emit('finalize-battle', {
    battleId: props.battleInfo.battleId,
    selectedConsumableIds: []
  });
  
  // Reset state
  showConsumableSelection.value = false;
  selectedConsumables.value = [];
  availableConsumables.value = [];
};

const finalizeBattleAndPickUp = () => {
  console.log('🟢 Green button clicked - finalizeBattleAndPickUp called');
  console.log('Battle ID:', props.battleInfo.battleId);
  console.log('Selected consumables:', selectedConsumables.value);
  console.log('Replace item ID:', selectedItemForReplacement.value?.itemId);
  
  battleFinalized.value = true;
  
  // Hide the modal immediately to prevent showing intermediate state
  // The parent will handle re-showing if needed
  const eventData = {
    battleId: props.battleInfo.battleId,
    selectedConsumableIds: selectedConsumables.value,
    replaceItemId: selectedItemForReplacement.value?.itemId,
    hideModalImmediately: true
  };
  
  console.log('Emitting finalize-battle-and-pick-up with data:', eventData);
  emit('finalize-battle-and-pick-up', eventData);
  
  // Reset state
  showConsumableSelection.value = false;
  showInventorySelection.value = false;
  selectedConsumables.value = [];
  availableConsumables.value = [];
  selectedItemForReplacement.value = null;
};

const finalizeBattleAndLeaveItem = () => {
  battleFinalized.value = true;
  
  // Hide the modal immediately to prevent showing intermediate state
  emit('finalize-battle', {
    battleId: props.battleInfo.battleId,
    selectedConsumableIds: selectedConsumables.value,
    hideModalImmediately: true
  });
  
  // Reset state
  showConsumableSelection.value = false;
  selectedConsumables.value = [];
  availableConsumables.value = [];
};

const pickUpAndEndTurn = () => {
  // Emit to parent component to handle the pickup
  emit('pick-item-and-end-turn');
};

const confirmReplacement = () => {
  if (!selectedItemForReplacement.value) {
    return;
  }

  // Emit to parent component to handle the replacement
  emit('pick-item-with-replacement', selectedItemForReplacement.value.itemId);
  
  // Reset state
  showInventorySelection.value = false;
  inventoryForSelection.value = [];
  selectedItemForReplacement.value = null;
  pendingPickupItem.value = null;
};

const cancelReplacement = () => {
  showInventorySelection.value = false;
  inventoryForSelection.value = [];
  selectedItemForReplacement.value = null;
  pendingPickupItem.value = null;
};

const leaveItemAndEndTurn = () => {
  // Debug logging to see what we have
  console.log('leaveItemAndEndTurn called with battleInfo:', props.battleInfo);
  console.log('Battle result:', props.battleInfo.result);
  console.log('Battle ID:', props.battleInfo.battleId);
  
  // If this battle has a battleId and needs finalization (defeats/draws/wins where item is left), call finalize-battle
  // This ensures the backend processes the battle result properly
  if (props.battleInfo.battleId && 
      (props.battleInfo.result === 'loose' || props.battleInfo.result === 'draw' || props.battleInfo.result === 'win')) {
    console.log('Finalizing battle for result:', props.battleInfo.result);
    battleFinalized.value = true;
    emit('finalize-battle', {
      battleId: props.battleInfo.battleId,
      selectedConsumableIds: []
    });
  } else {
    // For other cases, just end turn
    console.log('Ending turn without finalization');
    emit('end-turn');
  }
};

// Method to show inventory replacement UI when inventory is full
// This can be called from parent component
const showInventoryFullSelection = (inventory, item) => {
  showInventorySelection.value = true;
  inventoryForSelection.value = inventory || [];
  pendingPickupItem.value = item;
  selectedItemForReplacement.value = null;
  
  // When showing inventory selection, hide consumable selection
  showConsumableSelection.value = false;
};

// Method to handle finalize-battle-and-pick-up with inventory full
const showFinalizeBattleInventoryFullSelection = (inventory) => {
  showInventorySelection.value = true;
  inventoryForSelection.value = inventory || [];
  selectedItemForReplacement.value = null;
  
  // Keep consumable selection values - we'll use them when finalizing
  // But hide the consumable selection UI
  showConsumableSelection.value = false;
};

// Expose methods to parent component
defineExpose({
  showInventoryFullSelection,
  showFinalizeBattleInventoryFullSelection
});

// Helper function to format item names for display
const formatItemName = (name) => {
  if (!name) return 'Unknown';
  
  // Convert snake_case to Title Case
  return name.split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};

// Get correct item name based on item type (for used items)
const getCorrectItemName = (item) => {
  if (!item) return 'Unknown';
  
  // Use item type to get the correct item name, fallback to name if type not available
  const itemName = item.type || item.name || 'Unknown';
  
  // Convert snake_case to Title Case
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

// Get item emoji based on item data
const displayItemEmoji = computed(() => {
  if (!props.battleInfo.reward) return '❓';
  
  // Show reward based on item type
  const item = props.battleInfo.reward;
  switch (item.type) {
    case 'key':
      return '🔑';
    case 'chest':
      return '📦';
    case 'ruby_chest':
      return '💎';
    case 'dagger':
      return '🗡️';
    case 'sword':
      return '⚔️';
    case 'axe':
      return '🪓';
    case 'fireball':
      return '🔥';
    case 'teleport':
      return '✨';
    default:
      return '💰'; // Default treasure
  }
});

// Get inventory item emoji
const getInventoryItemEmoji = (item) => {
  if (!item) return '❓';
  
  const itemType = item.type || item.name;
  switch (itemType) {
    case 'key':
      return '🔑';
    case 'chest':
      return '📦';
    case 'ruby_chest':
      return '💎';
    case 'dagger':
      return '🗡️';
    case 'sword':
      return '⚔️';
    case 'axe':
      return '🪓';
    case 'fireball':
      return '🔥';
    case 'teleport':
      return '✨';
    default:
      return '💰'; // Default treasure
  }
};

// Get monster emoji based on battleInfo
const displayMonsterEmoji = computed(() => {
  if (!props.battleInfo) return '👹';
  
  // Try to determine monster type from battleInfo
  const monsterType = props.battleInfo.monsterType || '';
  
  switch (monsterType) {
    case 'dragon':
      return '🐉';
    case 'skeleton_king':
      return '👑';
    case 'skeleton_warrior':
      return '🛡️';
    case 'skeleton_turnkey':
      return '🦴';
    case 'fallen':
      return '👻';
    case 'giant_rat':
      return '🐀';
    case 'giant_spider':
      return '🕷️';
    case 'mummy':
      return '🧟';
    default:
      return '👹'; // Default monster
  }
});

// Get monster image based on battleInfo
const displayMonsterImage = computed(() => {
  if (!props.battleInfo) return null;
  
  // Create battle object for getMonsterImage function
  const battleData = {
    monster_name: props.battleInfo.monsterType || '',
    monster: props.battleInfo.monster || 0
  };
  
  return getMonsterImage(battleData);
});

// Get emoji for used items
const getUsedItemEmoji = (item) => {
  if (!item) return '❓';
  
  switch (item.type) {
    case 'key':
      return '🔑';
    case 'dagger':
      return '🗡️';
    case 'sword':
      return '⚔️';
    case 'axe':
      return '🪓';
    case 'fireball':
      return '🔥';
    case 'teleport':
      return '✨';
    default:
      return '🧪'; // Default item
  }
};

const formattedMonsterName = computed(() => {
  if (!props.battleInfo.monsterType) return 'Monster';
  return formatItemName(props.battleInfo.monsterType);
});

// Helper function to get the correct display name for spells (use type, not monster name)
const getSpellDisplayName = (item) => {
  if (!item) return 'Unknown';
  
  // Monster name to spell type mapping
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
  
  // Check if this is a spell by looking at the type
  if (item.type === 'fireball' || item.type === 'teleport') {
    return formatItemName(item.type);
  }
  
  // If the item has a name that matches a monster name in our mapping, use the corresponding spell type
  if (item.name && monsterToSpellType[item.name]) {
    return formatItemName(monsterToSpellType[item.name]);
  }
  
  // For weapon items, always use the type
  if (item.type && ['dagger', 'sword', 'axe'].includes(item.type)) {
    return formatItemName(item.type);
  }
  
  // For other items, use the normal formatting logic
  return formatItemName(item.type || item.name || 'Unknown');
};

// Computed property to check if we should show reward
const shouldShowReward = computed(() => {
  // Debug logging to see why reward section isn't showing
  console.log('shouldShowReward check:', {
    hasReward: !!props.battleInfo.reward,
    result: props.battleInfo.result,
    showConsumableSelection: showConsumableSelection.value,
    totalDamage: totalCalculatedDamage,
    monsterHP: props.battleInfo.monster,
    wouldWin: totalCalculatedDamage.value > props.battleInfo.monster
  });
  
  // Always show if we have a reward item
  if (!props.battleInfo.reward) return false;
  
  // Show if player already won
  if (props.battleInfo.result === 'win') return true;
  
  // Show if consumables would result in victory
  if (showConsumableSelection.value && totalCalculatedDamage.value > props.battleInfo.monster) return true;
  
  // Show if isPotentialReward flag is set directly in the reward
  if (props.battleInfo.reward.isPotentialReward) return true;
  
  return false;
});

// Computed property to check if the reward is a potential reward (vs. already won)
const isPotentialReward = computed(() => {
  return props.battleInfo.result !== 'win' && 
         showConsumableSelection.value && 
         potentialVictoryWithConsumables.value;
});

// Computed property for potential victory text
const victoryText = computed(() => {
  if (isPotentialReward.value) {
    return '🎉 Potential Victory Reward!';
  }
  return '🎉 Victory Reward!';
});

// Computed property for potential reward tip text
const potentialRewardTip = computed(() => {
  if (!isPotentialReward.value) return '';
  
  const missingDamage = props.battleInfo.monster - (props.battleInfo.diceRollDamage + weaponDamageTotal.value);
  const selectedDamage = consumableDamageTotal.value;
  
  return `You need ${missingDamage} more damage to win. Your selected consumables provide +${selectedDamage} damage.`;
});
</script>

<style scoped>
.battle-report-modal {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0, 0, 0, 0.8);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.battle-report-content {
  background-color: #222;
  border-radius: 8px;
  width: 90%;
  max-width: 550px;
  max-height: 85vh;
  overflow-y: auto;
  box-shadow: 0 5px 20px rgba(0, 0, 0, 0.5);
  display: flex;
  flex-direction: column;
  color: #eee;
  border: 1px solid #444;
}

.battle-report-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.6rem 1rem;
  border-bottom: 1px solid #444;
  background: linear-gradient(180deg, #252525 0%, #1a1a1a 100%);
}

.battle-report-header h3 {
  margin: 0;
  font-size: 1.3rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 1px;
}

.victory-title {
  color: #4caf50;
}

.defeat-title {
  color: #f44336;
}

.draw-title {
  color: #ff9800;
}

.close-battle-btn {
  background: none;
  border: none;
  font-size: 1.8rem;
  cursor: pointer;
  color: #ccc;
  line-height: 1;
}

.close-battle-btn:hover {
  color: #fff;
}

.battle-report-body {
  padding: 0.75rem;
  background-color: #1e1e1e;
}

.damage-comparison {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
  padding: 0.75rem;
  background: rgba(0, 0, 0, 0.3);
  border-radius: 8px;
}

.player-damage, .monster-stats {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex: 1;
}

.big-number {
  font-size: 2.5rem;
  font-weight: bold;
  margin-bottom: 0.3rem;
  color: #fff;
  text-shadow: 0 2px 4px rgba(0,0,0,0.3);
}

.dice-container {
  display: flex;
  gap: 0.5rem;
  justify-content: center;
  margin-bottom: 0.4rem;
  perspective: 1000px;
}

.dice-face {
  width: 44px;
  height: 44px;
  background: 
    radial-gradient(circle at 30% 30%, rgba(255,255,255,0.9) 0%, transparent 50%),
    linear-gradient(145deg, #fafafa 0%, #d8d8d8 50%, #c0c0c0 100%);
  border: 2px solid #888;
  border-radius: 10px;
  display: grid;
  padding: 4px;
  box-shadow: 
    0 4px 8px rgba(0,0,0,0.4),
    0 2px 4px rgba(0,0,0,0.3),
    inset 0 2px 4px rgba(255,255,255,0.8),
    inset 0 -2px 4px rgba(0,0,0,0.2);
  position: relative;
  transform-style: preserve-3d;
  transition: transform 0.3s ease;
}

.dice-face:hover {
  transform: rotateY(10deg) rotateX(-10deg) scale(1.1);
  box-shadow: 
    0 6px 12px rgba(0,0,0,0.5),
    0 3px 6px rgba(0,0,0,0.4),
    inset 0 2px 4px rgba(255,255,255,0.8),
    inset 0 -2px 4px rgba(0,0,0,0.2);
}

/* Enhanced pip styles */
.pip {
  width: 7px;
  height: 7px;
  background: radial-gradient(circle at 30% 30%, #444 0%, #111 100%);
  border-radius: 50%;
  position: absolute;
  box-shadow: 
    inset 0 1px 2px rgba(0,0,0,0.8),
    0 1px 1px rgba(255,255,255,0.1);
}

/* One pip - center */
.dice-face[data-value="1"] .pip {
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
}

/* Two pips - diagonal */
.dice-face[data-value="2"] .pip-2-1 {
  top: 25%;
  left: 25%;
  transform: translate(-50%, -50%);
}
.dice-face[data-value="2"] .pip-2-2 {
  bottom: 25%;
  right: 25%;
  transform: translate(50%, 50%);
}

/* Three pips - diagonal */
.dice-face[data-value="3"] .pip-3-1 {
  top: 25%;
  left: 25%;
  transform: translate(-50%, -50%);
}
.dice-face[data-value="3"] .pip-3-2 {
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
}
.dice-face[data-value="3"] .pip-3-3 {
  bottom: 25%;
  right: 25%;
  transform: translate(50%, 50%);
}

/* Four pips - corners */
.dice-face[data-value="4"] .pip-4-1 {
  top: 25%;
  left: 25%;
  transform: translate(-50%, -50%);
}
.dice-face[data-value="4"] .pip-4-2 {
  top: 25%;
  right: 25%;
  transform: translate(50%, -50%);
}
.dice-face[data-value="4"] .pip-4-3 {
  bottom: 25%;
  left: 25%;
  transform: translate(-50%, 50%);
}
.dice-face[data-value="4"] .pip-4-4 {
  bottom: 25%;
  right: 25%;
  transform: translate(50%, 50%);
}

/* Five pips - corners + center */
.dice-face[data-value="5"] .pip-5-1 {
  top: 25%;
  left: 25%;
  transform: translate(-50%, -50%);
}
.dice-face[data-value="5"] .pip-5-2 {
  top: 25%;
  right: 25%;
  transform: translate(50%, -50%);
}
.dice-face[data-value="5"] .pip-5-3 {
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
}
.dice-face[data-value="5"] .pip-5-4 {
  bottom: 25%;
  left: 25%;
  transform: translate(-50%, 50%);
}
.dice-face[data-value="5"] .pip-5-5 {
  bottom: 25%;
  right: 25%;
  transform: translate(50%, 50%);
}

/* Six pips - two columns */
.dice-face[data-value="6"] .pip-6-1 {
  top: 25%;
  left: 25%;
  transform: translate(-50%, -50%);
}
.dice-face[data-value="6"] .pip-6-2 {
  top: 25%;
  right: 25%;
  transform: translate(50%, -50%);
}
.dice-face[data-value="6"] .pip-6-3 {
  top: 50%;
  left: 25%;
  transform: translate(-50%, -50%);
}
.dice-face[data-value="6"] .pip-6-4 {
  top: 50%;
  right: 25%;
  transform: translate(50%, -50%);
}
.dice-face[data-value="6"] .pip-6-5 {
  bottom: 25%;
  left: 25%;
  transform: translate(-50%, 50%);
}
.dice-face[data-value="6"] .pip-6-6 {
  bottom: 25%;
  right: 25%;
  transform: translate(50%, 50%);
}

.damage-breakdown {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.6rem;
  margin-top: 0.4rem;
  font-size: 0.8rem;
}

.weapon-text,
.consumable-text {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  font-weight: 600;
  font-size: 0.75rem;
}

.weapon-text {
  color: #81c784;
}

.consumable-text {
  color: #ba68c8;
}

.comparison-symbol {
  position: relative;
  font-size: 2rem;
  font-weight: bold;
  padding: 0 1rem;
  color: #fff;
}

.greater-than {
  color: #4caf50;
}

.less-than {
  color: #f44336;
}

.equal-to {
  color: #ff9800;
}

.monster-emoji {
  font-size: 1.8rem;
  margin-bottom: 0.3rem;
}

.monster-name {
  font-weight: 500;
  color: #ddd;
}

.reward-section {
  margin-bottom: 0.75rem;
  padding: 0.5rem;
  border-radius: 6px;
  background: linear-gradient(135deg, #2a4d3a 0%, #1e3a28 100%);
  border: 1px solid #4caf50;
}

.reward-content {
  display: flex;
  justify-content: center;
  align-items: center;
}

.reward-item {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  width: auto;
}

.reward-icon {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
}

.reward-icon .item-emoji {
  font-size: 2rem;
}

.potential-badge {
  position: absolute;
  top: -4px;
  right: -8px;
  background: #ff9800;
  color: white;
  border-radius: 50%;
  width: 16px;
  height: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7rem;
  font-weight: bold;
  border: 1px solid white;
}

.monster-battle-image {
  width: 56px;
  height: 56px;
  object-fit: contain;
  background: var(--monad-bg-card, #1A1830);
  padding: 4px;
  border-radius: 8px;
  border: 2px solid var(--monad-purple-light, #9F6EFF);
  box-shadow: 0 3px 6px rgba(123, 63, 242, 0.2);
  animation: monsterPulse 2s infinite;
}

.monster-reward-image {
  width: 48px;
  height: 48px;
  object-fit: contain;
  background: var(--monad-bg-card, #1A1830);
  padding: 3px;
  border-radius: 6px;
  border: 2px solid var(--monad-purple-light, #9F6EFF);
  box-shadow: 0 3px 6px rgba(123, 63, 242, 0.2);
}

@keyframes monsterPulse {
  0%, 100% {
    transform: scale(1);
    box-shadow: 0 4px 8px rgba(123, 63, 242, 0.25);
  }
  50% {
    transform: scale(1.02);
    box-shadow: 0 6px 12px rgba(159, 110, 255, 0.35);
  }
}

.item-details {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.3rem;
}

.item-header {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.item-name {
  font-weight: bold;
  color: #fff;
  font-size: 1rem;
}

.item-damage-badge,
.item-value-badge {
  padding: 2px 6px;
  border-radius: 12px;
  font-size: 0.75rem;
  font-weight: 600;
}

.item-damage-badge {
  background: rgba(76, 175, 80, 0.2);
  color: #4caf50;
  border: 1px solid rgba(76, 175, 80, 0.4);
}

.item-value-badge {
  background: rgba(255, 215, 0, 0.2);
  color: #ffd700;
  border: 1px solid rgba(255, 215, 0, 0.4);
}

.auto-collected {
  color: #4CAF50;
  font-size: 0.9rem;
  font-weight: 500;
  margin-left: 0.5rem;
}

.auto-collect-info,
.potential-info {
  font-size: 0.75rem;
  color: #aaa;
  font-style: italic;
}

.auto-collect-info {
  color: #4caf50;
}

.potential-info {
  color: #ff9800;
}

.no-reward {
  color: #ddd;
  font-style: italic;
  font-size: 0.9rem;
}

.used-items-section {
  margin-bottom: 0.75rem;
  padding: 0.5rem;
  border-radius: 6px;
  background-color: rgba(58, 58, 58, 0.5);
  border: 1px solid #444;
}

.used-items-title {
  font-weight: 600;
  margin-bottom: 0.4rem;
  color: #ccc;
  font-size: 0.85rem;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.used-items-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.used-item {
  background-color: #555;
  padding: 0.4rem 0.6rem;
  border-radius: 16px;
  font-size: 0.85rem;
  color: #ddd;
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.used-item.consumable-chip {
  background: linear-gradient(135deg, #673ab7 0%, #512da8 100%);
  border: 1px solid rgba(103, 58, 183, 0.5);
}

.used-item .item-emoji {
  font-size: 1rem;
}

.used-item .item-name {
  color: #fff;
  font-weight: 500;
  font-size: 0.8rem;
}

.used-item .item-damage {
  color: #81c784;
  font-weight: 600;
  font-size: 0.75rem;
}

/* Add styles for selectable items */
.used-item.selectable-item {
  cursor: pointer;
  transition: all 0.2s;
  border: 1px solid transparent;
}

.used-item.selectable-item:hover {
  background-color: #666;
  border-color: #888;
}

.used-item.selectable-item.selected {
  background-color: #2a4d3a;
  border-color: #4caf50;
}

/* Style for inventory replacement items */
.used-item.inventory-item-replace {
  border: 2px solid #f44336;
  background-color: #3a2a2a;
  position: relative;
  transform: scale(1);
  transition: all 0.3s ease;
}

.used-item.inventory-item-replace:hover {
  background-color: #4a3a3a;
  border-color: #ff6b6b;
  transform: scale(1.05);
  box-shadow: 0 4px 12px rgba(244, 67, 54, 0.3);
}

.used-item.inventory-item-replace.selected {
  background-color: #ff4444;
  border-color: #ff6b6b;
  color: #fff;
  transform: scale(1.08);
  box-shadow: 0 6px 20px rgba(244, 67, 54, 0.5);
}

.used-item.inventory-item-replace.selected::after {
  content: '✓';
  position: absolute;
  top: -8px;
  right: -8px;
  background-color: #4caf50;
  color: white;
  border-radius: 50%;
  width: 20px;
  height: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: bold;
  border: 2px solid white;
}

.used-item.inventory-item-replace.selected .item-name,
.used-item.inventory-item-replace.selected .item-damage,
.used-item.inventory-item-replace.selected .item-value {
  color: #fff !important;
  font-weight: bold;
}

.used-item .item-value {
  color: #ffd700;
  font-weight: bold;
  font-size: 0.8rem;
}

.consumable-selection {
  margin-bottom: 1rem;
  padding: 0.75rem;
  border-radius: 6px;
  background-color: #3a2a2a;
  border: 1px solid #f44336;
}

.selection-title {
  font-weight: bold;
  margin-bottom: 0.75rem;
  color: #f44336;
  text-align: center;
}

.selection-subtitle {
  font-size: 0.9rem;
  color: #ddd;
  text-align: center;
  margin-bottom: 0.75rem;
}

.consumable-items {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.consumable-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 0.5rem;
  border-radius: 4px;
  background-color: #444;
  border: 1px solid #666;
  cursor: pointer;
  transition: all 0.2s;
  min-width: 80px;
}

.consumable-item:hover {
  background-color: #555;
  border-color: #888;
}

.consumable-item.selected {
  background-color: #2a4d3a;
  border-color: #4caf50;
}

.consumable-item .item-checkbox {
  margin-bottom: 0.25rem;
}

.consumable-item .item-emoji {
  font-size: 1.5rem;
  margin-bottom: 0.25rem;
}

.consumable-item .item-details {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.consumable-item .item-name {
  font-size: 0.8rem;
  text-align: center;
  color: #ddd;
}

.consumable-item .item-damage {
  font-size: 0.7rem;
  color: #ffd700;
}

.damage-preview {
  margin-top: 0.75rem;
  padding: 0.75rem;
  border-radius: 6px;
  background-color: #2a4d3a;
  border: 1px solid #4caf50;
}

.preview-calculation {
  font-size: 0.9rem;
  color: #ddd;
  margin-bottom: 0.5rem;
}

.preview-result {
  font-size: 1.2rem;
  font-weight: bold;
  padding: 0.5rem;
  border-radius: 4px;
  color: #fff;
}

.victory-preview {
  background-color: #4caf50;
}

.draw-preview {
  background-color: #ff9800;
}

.defeat-preview {
  background-color: #f44336;
}

.inventory-selection {
  margin-bottom: 1rem;
  padding: 0.75rem;
  border-radius: 6px;
  background-color: #3a2a2a;
  border: 1px solid #f44336;
}

.inventory-items {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.inventory-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 0.5rem;
  border-radius: 4px;
  background-color: #444;
  border: 1px solid #666;
  cursor: pointer;
  transition: all 0.2s;
  min-width: 80px;
}

.inventory-item:hover {
  background-color: #555;
  border-color: #888;
}

.inventory-item.selected {
  background-color: #2a4d3a;
  border-color: #4caf50;
}

.inventory-item .item-emoji {
  font-size: 1.5rem;
  margin-bottom: 0.25rem;
}

.inventory-item .item-name {
  font-size: 0.8rem;
  text-align: center;
  color: #ddd;
}

.inventory-item .item-value {
  font-size: 0.7rem;
  color: #ffd700;
}

.battle-report-footer {
  padding: 0.75rem 1rem;
  border-top: 1px solid #444;
  background: linear-gradient(180deg, #1a1a1a 0%, #151515 100%);
}

.pick-up-btn {
  background-color: #4caf50;
  color: white;
  border: none;
  padding: 10px 16px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
  font-weight: bold;
  transition: all 0.2s;
  box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.pick-up-btn:hover {
  background-color: #45a049;
  transform: translateY(-1px);
  box-shadow: 0 3px 6px rgba(0,0,0,0.3);
}

.leave-item-btn {
  background-color: #666;
  color: white;
  border: none;
  padding: 10px 16px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
  transition: all 0.2s;
  box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.leave-item-btn:hover {
  background-color: #555;
  transform: translateY(-1px);
  box-shadow: 0 3px 6px rgba(0,0,0,0.3);
}

.confirm-replacement-btn {
  background: linear-gradient(145deg, #4caf50, #45a049);
  color: white;
  border: none;
  padding: 0.75rem 1.5rem;
  border-radius: 6px;
  cursor: pointer;
  font-weight: bold;
  transition: all 0.2s;
}

.confirm-replacement-btn:disabled {
  background: #666;
  cursor: not-allowed;
  transform: none;
}

.confirm-replacement-btn:hover:not(:disabled) {
  background: linear-gradient(145deg, #45a049, #3d8b40);
  transform: translateY(-1px);
}

.cancel-replacement-btn {
  background: linear-gradient(145deg, #f44336, #d32f2f);
  color: white;
  border: none;
  padding: 0.75rem 1.5rem;
  border-radius: 6px;
  cursor: pointer;
  font-weight: bold;
  transition: all 0.2s;
}

.cancel-replacement-btn:hover {
  background: linear-gradient(145deg, #d32f2f, #b71c1c);
  transform: translateY(-1px);
}

.end-turn-btn {
  background: linear-gradient(145deg, #2196f3, #1976d2);
  color: white;
  border: none;
  padding: 0.75rem 2rem;
  border-radius: 6px;
  cursor: pointer;
  font-weight: bold;
  transition: all 0.2s;
}

.end-turn-btn:hover {
  background: linear-gradient(145deg, #1976d2, #1565c0);
  transform: translateY(-1px);
}

.button-group {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  align-items: center;
  width: 100%;
}

.button-group button {
  width: 90%;
  max-width: 400px;
}

.finalize-battle-btn {
  background: linear-gradient(145deg, #2196f3, #1976d2);
  color: white;
  border: none;
  padding: 0.75rem 1.5rem;
  border-radius: 6px;
  cursor: pointer;
  font-weight: bold;
  transition: all 0.2s;
}

.finalize-battle-btn:hover {
  background: linear-gradient(145deg, #1976d2, #1565c0);
  transform: translateY(-1px);
}

.accept-defeat-btn {
  background: linear-gradient(145deg, #757575, #616161);
  color: white;
  border: none;
  padding: 0.75rem 1.5rem;
  border-radius: 6px;
  cursor: pointer;
  font-weight: bold;
  transition: all 0.2s;
}

.accept-defeat-btn:hover {
  background: linear-gradient(145deg, #616161, #424242);
  transform: translateY(-1px);
}

.reward-item.generic-reward {
  border-left: 3px dashed #ffd700;
  background-color: rgba(255, 215, 0, 0.1);
  padding: 8px 12px;
  border-radius: 4px;
  animation: pulse-border 2s infinite;
}

@keyframes pulse-border {
  0% {
    border-color: #ffd700;
  }
  50% {
    border-color: #ff9800;
  }
  100% {
    border-color: #ffd700;
  }
}
</style> 