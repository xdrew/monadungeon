<template>
  <div class="action-log">
    <h3>Recent Actions</h3>
    <div class="turns-container">
      <div 
        v-for="turn in recentTurns" 
        :key="turn.turnId" 
        class="turn-log"
        :class="{ 'current-player': turn.isCurrentPlayer }"
      >
        <div class="turn-header">
          <span class="turn-player">
            <img 
              v-if="isCurrentUser(turn.playerId)"
              src="/images/player.webp" 
              alt="Player" 
              class="player-avatar-small"
            />
            <img 
              v-else
              src="/images/ai.webp" 
              alt="AI Player" 
              class="ai-avatar-small"
            />
          </span>
          <span class="turn-number">
            {{ (typeof localStorage !== 'undefined' && localStorage.getItem('virtualPlayerId') === turn.playerId) ? 'AI Player' : 'Player' }} - Turn {{ turn.turnNumber }}
          </span>
        </div>
        <div class="actions-list">
          <div 
            v-for="(action, index) in turn.actions" 
            :key="index" 
            class="action-item"
            :class="action.type || action.action"
            v-if="formatActionText(action) !== null"
          >
            <span class="action-icon">{{ getActionIcon(action.type || action.action) }}</span>
            <span 
              class="action-text"
              v-if="typeof formatActionText(action) === 'string'"
            >{{ formatActionText(action) }}</span>
            <span 
              class="action-text"
              v-else-if="formatActionText(action) && formatActionText(action).html"
              v-html="formatActionText(action).html"
            ></span>
          </div>
        </div>
      </div>
      
      <div v-if="recentTurns.length === 0" class="no-actions">
        No recent actions to display
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  turns: {
    type: Array,
    default: () => []
  },
  currentPlayerId: {
    type: String,
    default: ''
  }
});

// Get the last 2 turns (or fewer if not available)
const recentTurns = computed(() => {
  if (!props.turns || props.turns.length === 0) return [];
  
  // Sort turns by turn number descending and take last 2
  const sortedTurns = [...props.turns]
    .sort((a, b) => (b.turnNumber || b.turn_number || 0) - (a.turnNumber || a.turn_number || 0))
    .slice(0, 2)
    .reverse(); // Reverse to show oldest first
  
  return sortedTurns.map(turn => {
    let actions = Array.isArray(turn.actions) ? turn.actions : [];
    
    // Filter out duplicate battle entries - keep only the one with higher damage (finalized)
    const battleActions = actions.filter(a => (a.type || a.action) === 'fight_monster');
    if (battleActions.length > 1) {
      // Find the battle with the highest total damage (the finalized one)
      const maxDamageBattle = battleActions.reduce((max, battle) => {
        const battleDamage = battle.details?.totalDamage || battle.additionalData?.totalDamage || 0;
        const maxDamage = max.details?.totalDamage || max.additionalData?.totalDamage || 0;
        return battleDamage > maxDamage ? battle : max;
      });
      
      // Remove all battle actions except the one with max damage
      actions = actions.filter(a => {
        if ((a.type || a.action) !== 'fight_monster') return true;
        return a === maxDamageBattle;
      });
    }
    
    return {
      ...turn,
      turnId: turn.turnId || turn.id,
      turnNumber: turn.turnNumber || turn.turn_number,
      playerId: turn.playerId || turn.player_id,
      actions: actions,
      isCurrentPlayer: (turn.playerId || turn.player_id) === props.currentPlayerId
    };
  });
});

// Check if the player is the current user
const isCurrentUser = (playerId) => {
  if (!playerId) return false;
  const currentPlayerId = typeof localStorage !== 'undefined' ? localStorage.getItem('currentPlayerId') : null;
  return currentPlayerId === playerId;
};

// Check if the player is AI
const isAIPlayer = (playerId) => {
  if (!playerId) return false;
  const virtualPlayerId = typeof localStorage !== 'undefined' ? localStorage.getItem('virtualPlayerId') : null;
  return virtualPlayerId && playerId === virtualPlayerId;
};

// Player emoji mapping (same as in main game)
const getPlayerEmoji = (playerId) => {
  if (!playerId) return '❓';
  
  // Virtual players get robot emoji
  const virtualPlayerId = typeof localStorage !== 'undefined' ? localStorage.getItem('virtualPlayerId') : null;
  if (virtualPlayerId && playerId === virtualPlayerId) return '🤖';
  
  const emojis = ['👸', '🧞‍♂️', '🧙‍♀️', '⚔️'];
  const hash = playerId.split('').reduce((acc, char) => acc + char.charCodeAt(0), 0);
  return emojis[hash % emojis.length];
};

// Get item icon based on type
const getItemIcon = (itemType) => {
  if (!itemType) return '💎';
  
  const itemIcons = {
    'dagger': '🗡️',
    'sword': '⚔️',
    'axe': '🪓',
    'weapon': '⚔️',
    'fireball': '🔥',
    'teleport': '✨',
    'spell': '✨',
    'key': '🗝️',
    'chest': '📦',
    'treasure': '💎',
    'treasure_chest': '💎'
  };
  
  const lowerType = itemType.toLowerCase();
  const icon = itemIcons[lowerType];
  
  
  return icon || '💎';
};

// Get monster image HTML
const getMonsterImage = (monsterName) => {
  if (!monsterName) return 'monster';
  
  // Map monster names to their proper sprite images in /images/items/
  const monsterSprites = {
    'skeleton_king': '/images/items/molandak.webp',
    'skeleton_warrior': '/images/items/taekwonnad.webp',
    'skeleton_turnkey': '/images/items/bearded.webp',
    'dragon': '/images/items/bullish.webp',
    'fallen': '/images/items/bee.webp',
    'giant_rat': '/images/items/ikan.webp',
    'giant_spider': '/images/items/moyaki.webp',
    'mummy': '/images/items/ubur.webp',
    'treasure_chest': '/images/chest-opened.webp',
    'chest': '/images/chest-opened.webp'
  };
  
  const imagePath = monsterSprites[monsterName.toLowerCase()] || '/images/items/ubur.webp'; // Default fallback
  return `<img src="${imagePath}" style="width: 20px; height: 20px; vertical-align: middle; image-rendering: pixelated;" title="${monsterName}" />`;
};

// Get icon for different action types
const getActionIcon = (actionType) => {
  const icons = {
    // AI action types
    'tile_picked': '',
    'tile_placed': '🔷',
    'tile_rotated': '🔄',
    'player_moved': '👟',
    'battle_started': '⚔️',
    'battle_won': '🏆',
    'battle_lost': '💥',
    'item_picked': '💎',
    'item_picked_up': '💎',
    'item_dropped': '📦',
    'chest_locked': '🔒',
    'chest_opened': '🗃️',
    'spell_used': '✨',
    'use_teleport': '✨',
    'turn_ended': '✅',
    'player_healed': '💚',
    'player_stunned': '😵',
    // Database action types
    'pick_tile': '',
    'place_tile': '🔷',
    'rotate_tile': '🔄',
    'move': '👟',
    'fight_monster': '⚔️',
    'pick_item': '',
    'drop_item': '',
    'end_turn': '✅',
    // Virtual player specific actions
    'ai_thinking': '🤔',
    'ai_decision': '🎯',
    'battle_detected': '⚔️',
    'battle_analysis': '🎯',
    'battle_result': '⚔️'
  };
  
  return icons[actionType] || '';
};

// Format action text for display
const formatActionText = (action) => {
  if (!action) return 'Unknown action';
  
  // DEBUG: Log all actions to see what we're processing
  const actionType = action.type || action.action;
  console.log('PROCESSING ACTION:', {
    actionType: actionType,
    fullAction: action,
    hasDetails: !!(action.details || action.additionalData)
  });
  
  // EXTRA DEBUG: Log pick_item actions in detail
  if (actionType === 'pick_item') {
    console.log('PICK_ITEM DETAILED:', {
      action: action.action,
      type: action.type,
      additionalData: action.additionalData,
      details: action.details,
      fullStructure: JSON.stringify(action, null, 2)
    });
  }
  
  // Handle both action format (from AI) and database format
  const type = action.type || action.action;
  const details = action.details || action.additionalData || {};
  
  switch (type) {
    case 'tile_picked':
    case 'pick_tile':
      // Skip this to avoid duplication - tile picking is atomic with placement
      return null;
    
    case 'tile_placed':
    case 'place_tile':
      const direction = details?.direction || '';
      return `Picked tile and placed ${direction} (exploring)`;
    
    case 'tile_rotated':
    case 'rotate_tile':
      return `Rotated tile ${details?.direction || ''}`;
    
    case 'player_moved':
    case 'move':
      const moveDirection = details?.direction || '';
      const moveStrategy = details?.strategy;
      if (moveStrategy === 'moving toward item') {
        return `Moved ${moveDirection} (seeking treasure)`;
      } else if (moveStrategy === 'exploration') {
        return `Moved ${moveDirection} (exploring)`;
      }
      return `Moved ${moveDirection}`;
    
    case 'battle_started':
      // Skip this to avoid duplication - battle info will come from fight_monster
      return null;
      
    case 'fight_monster':
      // Try multiple sources for monster name in order of priority
      const monsterName = details?.monsterType || details?.monster?.name || details?.monster || 'monster';
      const result = details?.result || '';
      const diceResults = details?.diceResults || [];
      const totalDamage = details?.totalDamage || details?.diceRollDamage || 0;
      const diceRollDamage = details?.diceRollDamage || (diceResults.length > 0 ? diceResults.reduce((a, b) => a + b, 0) : 0);
      const itemDamage = details?.itemDamage || (totalDamage - diceRollDamage);
      const monsterHP = details?.monster?.hp || details?.monsterHP || details?.monster || 0;
      const usedItems = details?.usedItems || [];
      
      // Create monster image element
      const monsterImg = getMonsterImage(monsterName);
      
      // Build damage breakdown string
      let damageBreakdown = '';
      if (diceResults.length > 0) {
        damageBreakdown = `🎲 [${diceResults.join(', ')}] = ${diceRollDamage}`;
        
        // Calculate actual item damage
        const actualItemDamage = totalDamage - diceRollDamage;
        
        if (actualItemDamage > 0) {
          // Separate weapons and consumables
          const weapons = usedItems.filter(item => 
            item.type === 'weapon' || 
            item.type === 'dagger' || 
            item.type === 'sword' || 
            item.type === 'axe'
          );
          const consumables = usedItems.filter(item => 
            item.type === 'spell' || 
            item.type === 'fireball'
          );
          
          // Validation: if actualItemDamage doesn't match expected damage from items, something is wrong
          const expectedWeaponDamage = weapons.reduce((total, weapon) => {
            const weaponPowers = { 'dagger': 1, 'sword': 2, 'axe': 3, 'weapon': 1 };
            return total + (weaponPowers[weapon.type] || 1);
          }, 0);
          const expectedConsumableDamage = consumables.length; // Each consumable adds +1
          const expectedTotal = expectedWeaponDamage + expectedConsumableDamage;
          
          // Special case: If this is early in the game and weapons are showing but damage doesn't match,
          // it likely means the AI just picked up the weapon after the battle (not before)
          const isInconsistentEarlyGame = weapons.length > 0 && actualItemDamage === 0;
          
          // Another check: if we have multiple weapons but damage suggests only one was used
          const multipleWeaponsButLowDamage = weapons.length > 1 && actualItemDamage > 0 && actualItemDamage < expectedWeaponDamage;
          
          // Also check: if we have weapons but no item damage at all, they weren't used
          const weaponsButNoItemDamage = weapons.length > 0 && actualItemDamage <= 0;
          
          // If reported damage doesn't match items, show generic damage instead
          if ((Math.abs(actualItemDamage - expectedTotal) > 0.1 && (weapons.length > 0 || consumables.length > 0)) || isInconsistentEarlyGame || multipleWeaponsButLowDamage || weaponsButNoItemDamage) {
            // Backend data inconsistent - show generic item damage
            damageBreakdown += ` + items (+${actualItemDamage})`;
          } else if (weapons.length > 0 || consumables.length > 0) {
            damageBreakdown += ' + ';
            const itemParts = [];
            if (weapons.length > 0) {
              itemParts.push(...weapons.map(item => {
                // Use icons for weapons like frontend does
                const weaponIcons = {
                  'dagger': '🗡️',
                  'sword': '⚔️', 
                  'axe': '🪓',
                  'weapon': '⚔️'
                };
                const weaponPowers = {
                  'dagger': 1,
                  'sword': 2,
                  'axe': 3,
                  'weapon': 1
                };
                const itemType = item.type || item.name || 'weapon';
                const icon = weaponIcons[itemType] || weaponIcons['weapon'];
                const power = weaponPowers[itemType] || weaponPowers['weapon'];
                return `${icon}(+${power})`;
              }));
            }
            if (consumables.length > 0) {
              itemParts.push(...consumables.map(item => {
                // Use icons for spells like frontend does
                const spellIcons = {
                  'fireball': '🔥',
                  'spell': '✨'
                };
                const itemType = item.type || item.name || 'spell';
                const icon = spellIcons[itemType] || spellIcons['spell'];
                return `${icon}(+1)`; // Spells typically add +1 damage
              }));
            }
            damageBreakdown += itemParts.join(' ');
          } else {
            // Show generic item damage if no specific items available
            damageBreakdown += ` + items (+${actualItemDamage})`;
          }
        }
        
        damageBreakdown += ` = ${totalDamage}`;
      }
      
      if (result === 'WIN') {
        return { html: `WON vs ${monsterImg} (HP:${monsterHP}) ${damageBreakdown} damage!` };
      } else if (result === 'LOSE') {
        return { html: `LOST to ${monsterImg} (HP:${monsterHP}) ${damageBreakdown} damage` };
      }
      return { html: `Battle with ${monsterImg} (HP:${monsterHP})` };
    
    case 'battle_detected':
      // Skip this to avoid duplication - battle info will come from fight_monster
      return null;
    
    case 'battle_analysis':
      // Skip this to avoid duplication - battle result is more important
      return null;
    
    case 'battle_result':
      // Skip this to avoid duplication - battle info will come from fight_monster
      return null;
    
    case 'battle_won':
      // Skip this to avoid duplication - battle info will come from fight_monster
      return null;
    
    case 'battle_lost':
      // Skip this to avoid duplication - battle info will come from fight_monster
      return null;
    
    case 'immediate_battle_pickup':
      // This is the successful immediate pickup - show as regular item pickup
      const reward = details?.reward;
      // Prioritize the itemType field we explicitly set in the AI
      let rewardType = details?.itemType || reward?.type || details?.itemName;
      
      // Additional fallbacks for itemType - check API response first
      if (!rewardType && details?.apiResult?.response?.item?.type) {
        rewardType = details.apiResult.response.item.type;
      }
      if (!rewardType && details?.apiResult?.response?.itemType) {
        rewardType = details.apiResult.response.itemType;
      }
      if (!rewardType && details?.apiResult?.response?.reward?.type) {
        rewardType = details.apiResult.response.reward.type;
      }
      if (!rewardType && reward?.name) {
        rewardType = reward.name;
      }
      
      // Last resort: try to extract from pickup result response
      if (!rewardType && details?.apiResult?.response) {
        const response = details.apiResult.response;
        // Look for any field that might contain item type
        rewardType = response.type || response.itemName || response.name;
      }
      
      // DEBUG: Log the entire action entry
      console.log('ENTIRE PICKUP ACTION:', {
        actionType: action.type,
        fullAction: action,
        details: details,
        reward: reward,
        itemType: details?.itemType,
        rewardType: reward?.type,
        finalType: rewardType,
        icon: getItemIcon(rewardType || 'item')
      });
      
      const rewardIcon = getItemIcon(rewardType || 'item');
      
      return `Picked up ${rewardIcon}`;

    case 'item_picked_up':
    case 'item_picked':
    case 'pick_item':
      const strategy = details?.strategy;
      let itemType = details?.itemType || details?.itemName;
      
      // Fallback: if no itemType, try to get it from API response first
      if (!itemType && details?.apiResult?.response?.item?.type) {
        itemType = details.apiResult.response.item.type;
      }
      
      // Then try reward type
      if (!itemType && details?.reward?.type) {
        itemType = details.reward.type;
      }
      
      // Try item field (from Battle.php)
      if (!itemType && details?.item?.type) {
        itemType = details.item.type;
      }
      
      // Try monster field (from Field.php) 
      if (!itemType && details?.monster?.type) {
        itemType = details.monster.type;
      }
      
      // DATABASE FORMAT: For pick_item actions from database, look for related fight_monster
      if (!itemType && action.action === 'pick_item') {
        console.log('TRYING TO EXTRACT MONSTER TYPE FOR PICK_ITEM');
        // Find the current turn's actions
        const currentTurn = recentTurns.value.find(turn => 
          turn.actions && turn.actions.some(a => a === action)
        );
        console.log('CURRENT TURN FOUND:', currentTurn);
        if (currentTurn && currentTurn.actions) {
          const actionIndex = currentTurn.actions.indexOf(action);
          console.log('ACTION INDEX:', actionIndex, 'TOTAL ACTIONS:', currentTurn.actions.length);
          
          // Look for fight_monster action both before AND after the pick_item action
          // Sometimes database records them in different orders
          for (let i = 0; i < currentTurn.actions.length; i++) {
            if (i === actionIndex) continue; // Skip the pick_item action itself
            
            const otherAction = currentTurn.actions[i];
            console.log('CHECKING ACTION:', i, otherAction.action || otherAction.type, otherAction);
            if ((otherAction.action || otherAction.type) === 'fight_monster') {
              const monster = otherAction.additionalData?.monster || otherAction.details?.monster;
              console.log('FOUND FIGHT_MONSTER, MONSTER:', monster);
              if (monster?.type) {
                itemType = monster.type;
                console.log('EXTRACTED MONSTER TYPE:', itemType);
                break;
              }
            }
          }
        }
      }
      
      // Final fallback: if it's a treasure, it's probably a chest
      if (!itemType && strategy === 'collected valuable item') {
        itemType = 'treasure';
      }
      
      const itemIcon = getItemIcon(itemType);
      
      // DEBUG: Log item type and resulting icon
      console.log('ITEM ICON DEBUG:', {
        itemType: itemType,
        resultingIcon: itemIcon,
        action: action.action
      });
      
      if (strategy === 'chest opened') {
        return `Opened chest and collected treasure!`;
      } else if (itemType === 'chest') {
        return `Found chest`;
      }
      return `Picked up ${itemIcon}`;
    
    case 'chest_locked':
      const chestType = details?.chestType || 'chest';
      return `🔒 Found locked ${chestType} - need key to open`;
    
    case 'chest_opened':
      return `🗃️ Successfully opened chest!`;
    
    case 'item_dropped':
    case 'drop_item':
      return `Dropped ${details?.item || 'item'}`;
    
    case 'spell_used':
      return `Used ${details?.spell || 'spell'}`;
    
    case 'use_teleport':
      const fromPos = details?.from || details?.fromPosition;
      const toPos = details?.to || details?.toPosition;
      if (fromPos && toPos) {
        return `Teleported from ${fromPos} to ${toPos}`;
      }
      return `Used teleport spell`;
    
    case 'turn_ended':
    case 'end_turn':
      return 'Ended turn';
    
    case 'player_healed':
      return `Healed ${details?.amount || 1} HP at fountain`;
    
    case 'player_stunned':
      return 'Stunned (skipping next turn)';
    
    // Virtual player actions
    case 'ai_thinking':
      return 'Thinking...';
    
    case 'ai_decision':
      return `Decided: ${details?.decision || 'unknown'}`;

    case 'ai_battle_analysis':
      // Skip this to avoid duplication with fight_monster
      return null;

    case 'battle_finalized':
      // Skip this internal AI action
      return null;

    case 'item_picked_up_manual':
      // Skip this to avoid duplication with immediate_battle_pickup or regular item_picked
      return null;

    case 'turn_ended_manual':
      // Skip this internal AI action
      return null;
    
    default:
      // Debug: log unhandled action types
      console.log('Unhandled action type in getActionText:', type, action);
      return `${type.replace(/_/g, ' ')}`;
  }
};
</script>

<style scoped>
.action-log {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 8px;
  padding: 16px;
  margin-top: 16px;
  max-height: 300px;
  overflow-y: auto;
}

.action-log h3 {
  margin: 0 0 12px 0;
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  text-align: center;
}

.turns-container {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.turn-log {
  background: rgba(255, 255, 255, 0.05);
  border-radius: 6px;
  padding: 8px;
  border-left: 3px solid rgba(255, 255, 255, 0.3);
}

.turn-log.current-player {
  border-left-color: #4CAF50;
  background: rgba(76, 175, 80, 0.1);
}

.turn-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
  font-size: 12px;
  font-weight: 600;
  color: #fff;
}

.turn-player {
  font-size: 16px;
}

.player-avatar-small {
  width: 20px;
  height: 20px;
  object-fit: contain;
  display: inline-block;
  vertical-align: middle;
  filter: drop-shadow(0 0 2px rgba(0, 255, 0, 0.6));
}

.ai-avatar-small {
  width: 20px;
  height: 20px;
  object-fit: contain;
  display: inline-block;
  vertical-align: middle;
  filter: drop-shadow(0 0 2px rgba(0, 150, 255, 0.6));
}

.turn-number {
  color: #ccc;
}

.actions-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.action-item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: #ddd;
  padding: 2px 0;
}

.action-icon {
  font-size: 12px;
  width: 16px;
  text-align: center;
}

.action-text {
  flex: 1;
}

/* Different styling for different action types */
.action-item.battle_won {
  color: #4CAF50;
}

.action-item.battle_lost {
  color: #f44336;
}

.action-item.item_picked,
.action-item.item_picked_up {
  color: #FFD700;
}

.action-item.chest_locked {
  color: #ff9800;
}

.action-item.chest_opened {
  color: #4CAF50;
}

.action-item.player_healed {
  color: #4CAF50;
}

.action-item.player_stunned {
  color: #ff9800;
}

.action-item.ai_thinking {
  color: #2196F3;
  font-style: italic;
}

.action-item.ai_decision {
  color: #9C27B0;
  font-weight: 500;
}

.no-actions {
  text-align: center;
  color: #999;
  font-size: 12px;
  padding: 20px;
  font-style: italic;
}

/* Scrollbar styling */
.action-log::-webkit-scrollbar {
  width: 4px;
}

.action-log::-webkit-scrollbar-track {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 2px;
}

.action-log::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.3);
  border-radius: 2px;
}

.action-log::-webkit-scrollbar-thumb:hover {
  background: rgba(255, 255, 255, 0.5);
}
</style>