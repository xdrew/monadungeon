<template>
  <div class="inventory-full-dialog">
    <div class="dialog-content">
      <h3>Inventory Full</h3>
      
      <!-- New item information -->
      <div class="new-item-section">
        <h4>Found Item:</h4>
        <div class="item-card new-item">
          <div class="item-header">
            <img
              v-if="getItemImage(droppedItem)"
              :src="getItemImage(droppedItem)"
              :alt="getDisplayName(droppedItem)"
              class="item-image"
            />
            <span v-else class="item-emoji">{{ getItemEmoji(droppedItem) }}</span>
            <div class="item-details">
              <div class="item-name">
                {{ getDisplayName(droppedItem) }}
              </div>
            </div>
          </div>
          <div class="item-stats">
            <div
              v-if="getItemDamage(droppedItem) > 0"
              class="stat damage-stat"
            >
              <span class="stat-label">Damage:</span>
              <span class="stat-value">+{{ getItemDamage(droppedItem) }}</span>
            </div>
            <div
              v-if="droppedItem.treasureValue > 0"
              class="stat treasure-stat"
            >
              <span class="stat-label">Value:</span>
              <span class="stat-value">{{ droppedItem.treasureValue }}</span>
            </div>
            <div
              v-if="droppedItem.type === 'fireball'"
              class="stat consumable-stat"
            >
              <span class="stat-label">Consumable</span>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Current inventory to replace -->
      <div class="replace-section">
        <h4>Replace an item from your {{ formatItemCategory(itemCategory) }}:</h4>
        <div class="inventory-items">
          <div 
            v-for="item in inventoryForCategory" 
            :key="item.itemId" 
            class="item-card inventory-item" 
            :class="{ 'selected': selectedItemToReplace && selectedItemToReplace.itemId === item.itemId }"
            @click="selectItemToReplace(item)"
          >
            <div class="item-header">
              <img
                v-if="getItemImage(item)"
                :src="getItemImage(item)"
                :alt="getDisplayName(item)"
                class="item-image"
              />
              <span v-else class="item-emoji">{{ getItemEmoji(item) }}</span>
              <div class="item-details">
                <div class="item-name">
                  {{ getDisplayName(item) }}
                </div>
              </div>
            </div>
            <div class="item-stats">
              <div
                v-if="getItemDamage(item) > 0"
                class="stat damage-stat"
              >
                <span class="stat-label">Damage:</span>
                <span class="stat-value">+{{ getItemDamage(item) }}</span>
              </div>
              <div
                v-if="item.treasureValue > 0"
                class="stat treasure-stat"
              >
                <span class="stat-label">Value:</span>
                <span class="stat-value">{{ item.treasureValue }}</span>
              </div>
              <div
                v-if="item.type === 'fireball'"
                class="stat consumable-stat"
              >
                <span class="stat-label">Consumable</span>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <div class="dialog-actions">
        <button 
          class="action-btn replace-btn" 
          :disabled="!selectedItemToReplace" 
          @click="replaceItem"
        >
          Replace Selected Item
        </button>
        <button 
          class="action-btn skip-btn" 
          @click="skipItem"
        >
          Leave Item
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { defineProps, defineEmits, ref } from 'vue';
import { getItemEmoji, formatItemName, getItemDamage } from '@/utils/itemUtils';

const props = defineProps({
  droppedItem: {
    type: Object,
    required: true
  },
  itemCategory: {
    type: String,
    required: true
  },
  inventoryForCategory: {
    type: Array,
    required: true
  }
});

const emit = defineEmits(['replace-item', 'skip-item', 'select-item']);

const selectedItemToReplace = ref(null);

// Helper function to get item image
const getItemImage = (item) => {
  if (!item) return null;
  
  switch (item.type) {
    case 'key':
      return '/images/key.png';
    case 'chest':
      // In inventory, show opened chest
      return '/images/chest-opened.png';
    case 'ruby_chest':
      return '/images/ruby-chest.png';
    case 'dagger':
      return '/images/dagger.png';
    case 'sword':
      return '/images/sword.png';
    case 'axe':
      return '/images/axe.png';
    case 'fireball':
      return '/images/fireball.png';
    case 'teleport':
      return '/images/hf-teleport.png';
    default:
      return null;
  }
};

// Helper function to check if item is a chest type
const isChestType = (item) => {
  return item && (item.type === 'chest' || item.type === 'ruby_chest');
};

// Helper function to get display name (hide monster names)
const getDisplayName = (item) => {
  if (!item || !item.name) return 'Unknown Item';
  
  // List of monster names to hide
  const monsterNames = [
    'skeleton_king', 'skeleton_warrior', 'skeleton_turnkey', 'skeleton_mage',
    'dragon', 'fallen', 'giant_rat', 'giant_spider', 'mummy'
  ];
  
  // Check if the name is a monster name
  const isMonster = monsterNames.some(monster => 
    item.name.toLowerCase().includes(monster)
  );
  
  if (isMonster) {
    // Return generic name based on item type
    if (item.type === 'key') return 'Key';
    if (item.type === 'dagger') return 'Dagger';
    if (item.type === 'sword') return 'Sword';
    if (item.type === 'axe') return 'Axe';
    if (item.type === 'fireball') return 'Fireball';
    if (item.type === 'teleport') return 'Teleport';
    if (item.type === 'chest') return 'Treasure Chest';
    if (item.type === 'ruby_chest') return 'Ruby Chest';
    return 'Treasure';
  }
  
  // For non-monster items, show the actual name
  return formatItemName(item.name);
};

// Helper function to format item category names
const formatItemCategory = (category) => {
  const categoryMap = {
    'keys': 'Keys',
    'weapons': 'Weapons', 
    'spells': 'Spells',
    'treasures': 'Treasures'
  };
  return categoryMap[category] || category;
};

const selectItemToReplace = (item) => {
  selectedItemToReplace.value = item;
  emit('select-item', item);
};

const replaceItem = () => {
  if (selectedItemToReplace.value) {
    emit('replace-item', selectedItemToReplace.value);
  }
};

const skipItem = () => {
  emit('skip-item');
};
</script>

<style scoped>
.inventory-full-dialog {
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

.dialog-content {
  background-color: #222;
  border-radius: 12px;
  padding: 1.5rem;
  max-width: 500px;
  width: 95%;
  max-height: 85vh;
  overflow-y: auto;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
  color: #eee;
  border: 1px solid #444;
}

h3 {
  color: #f44336;
  margin-top: 0;
  margin-bottom: 1rem;
  font-size: 1.3rem;
  text-align: center;
}

h4 {
  margin-bottom: 0.5rem;
  color: #ccc;
  font-size: 1rem;
}

/* New item section */
.new-item-section {
  margin-bottom: 1rem;
  padding: 0.75rem;
  background-color: #1a1a1a;
  border-radius: 8px;
  border: 2px solid #4CAF50;
}

.new-item-section h4 {
  color: #4CAF50;
  margin-bottom: 0.75rem;
}

/* Item cards */
.item-card {
  background-color: #333;
  border-radius: 6px;
  padding: 0.75rem;
  border: 2px solid transparent;
  transition: all 0.2s ease;
}

.new-item {
  border-color: #4CAF50;
  background-color: #2a3d2a;
}

.inventory-item {
  cursor: pointer;
  margin-bottom: 0.25rem;
}

.inventory-item:hover {
  background-color: #444;
  border-color: #666;
}

.inventory-item.selected {
  background-color: #2c3e50;
  border-color: #3498db;
  box-shadow: 0 0 10px rgba(52, 152, 219, 0.3);
}

.item-header {
  display: flex;
  align-items: center;
  margin-bottom: 0.5rem;
}

.item-emoji {
  font-size: 1.5rem;
  margin-right: 0.75rem;
  min-width: 2rem;
  text-align: center;
}

.item-image {
  width: 32px;
  height: 32px;
  object-fit: contain;
  margin-right: 0.75rem;
}

.item-details {
  flex: 1;
}

.item-name {
  font-weight: 600;
  color: #fff;
  font-size: 1rem;
}

.item-stats {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
}

.stat {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.25rem 0.75rem;
  border-radius: 20px;
  font-size: 0.9rem;
  font-weight: 500;
}

.damage-stat {
  background-color: rgba(255, 87, 34, 0.2);
  color: #FF5722;
  border: 1px solid #FF5722;
}

.treasure-stat {
  background-color: rgba(255, 193, 7, 0.2);
  color: #FFC107;
  border: 1px solid #FFC107;
}

.consumable-stat {
  background-color: rgba(156, 39, 176, 0.2);
  color: #9C27B0;
  border: 1px solid #9C27B0;
}

.stat-label {
  font-size: 0.8rem;
  opacity: 0.9;
}

.stat-value {
  font-weight: 700;
}

/* Replace section */
.replace-section {
  margin-bottom: 1rem;
}

.inventory-items {
  max-height: 200px;
  overflow-y: auto;
  border: 1px solid #444;
  border-radius: 8px;
  padding: 0.5rem;
  background-color: #1a1a1a;
}

/* Dialog actions */
.dialog-actions {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.action-btn {
  padding: 1rem 1.5rem;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  flex: 1;
  min-width: 150px;
  transition: all 0.2s ease;
  font-size: 1rem;
}

.replace-btn {
  background-color: #3498db;
  color: white;
}

.replace-btn:hover:not(:disabled) {
  background-color: #2980b9;
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
}

.replace-btn:disabled {
  background-color: #34495e;
  cursor: not-allowed;
  opacity: 0.6;
}

.skip-btn {
  background-color: #95a5a6;
  color: #2c3e50;
}

.skip-btn:hover {
  background-color: #7f8c8d;
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(149, 165, 166, 0.3);
}

/* Responsive design */
@media (max-width: 768px) {
  .comparison {
    flex-direction: column;
  }
  
  .comparison-arrow {
    transform: rotate(90deg);
    padding: 0.5rem 0;
  }
  
  .dialog-actions {
    flex-direction: column;
  }
  
  .action-btn {
    min-width: 100%;
  }
}
</style> 