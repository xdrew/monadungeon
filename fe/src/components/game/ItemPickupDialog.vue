<template>
  <div
    v-if="show"
    class="item-pickup-dialog"
  >
    <div class="dialog-content">
      <div class="item-preview">
        <div class="item-icon">
          <img
            v-if="itemImage"
            :src="itemImage"
            :alt="itemName"
            class="item-image"
          />
          <span v-else>{{ itemEmoji }}</span>
        </div>
        <div class="item-details">
          <h3>{{ itemName }}</h3>
          <p
            v-if="itemDamage > 0"
            class="item-stat"
          >
            Damage: +{{ itemDamage }}
          </p>
        </div>
      </div>
      <div class="dialog-message">
        <p
          v-if="itemValue > 0"
          class="item-value"
        >
          Value: {{ itemValue }}
        </p>
        <p>You found an item! Would you like to pick it up?</p>
      </div>
      <div class="dialog-actions">
        <button
          class="pickup-button"
          @click="pickupItem"
        >
          Pick Up
        </button>
        <button
          class="skip-button"
          @click="skipItem"
        >
          Leave It
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { getItemEmoji, formatItemName } from '@/utils/itemUtils';

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  item: {
    type: Object,
    default: () => ({})
  }
});

const emit = defineEmits(['pickup', 'skip']);

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
    if (item.type === 'chest') return 'Treasure Chest';
    if (item.type === 'ruby_chest') return 'Ruby Chest';
    return 'Treasure';
  }
  
  // For non-monster items, show the actual name
  return formatItemName(item.name);
};

// Computed properties for item display
const itemEmoji = computed(() => getItemEmoji(props.item));
const itemImage = computed(() => {
  if (!props.item) return null;
  
  if (props.item.type === 'chest') {
    // In pickup dialog, show closed chest (it's still on the field)
    return '/images/chest-closed.webp';
  } else if (props.item.type === 'ruby_chest') {
    return '/images/ruby-chest.webp';
  }
  
  return null;
});
const itemName = computed(() => getDisplayName(props.item));
const itemDamage = computed(() => {
  // Damage based on item type
  if (!props.item?.type) return 0;
  
  if (props.item.damage !== undefined) return props.item.damage;
  
  switch (props.item.type) {
    case 'dagger': return 1;
    case 'sword': return 2;
    case 'axe': return 3;
    case 'fireball': return 1;
    default: return 0;
  }
});
const itemValue = computed(() => props.item?.treasureValue || 0);

// Actions
const pickupItem = () => {
  emit('pickup', props.item);
};

const skipItem = () => {
  emit('skip');
};
</script>

<style scoped>
.item-pickup-dialog {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.dialog-content {
  background-color: #1a202c;
  border-radius: var(--radius-md);
  padding: var(--spacing-md);
  width: 350px;
  max-width: 90vw;
  box-shadow: 0 5px 20px rgba(0, 0, 0, 0.5);
  border: 1px solid #2d3748;
}

.item-preview {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  margin-bottom: var(--spacing-md);
  padding-bottom: var(--spacing-md);
  border-bottom: 1px solid #2d3748;
}

.item-icon {
  font-size: 2.5em;
  width: 60px;
  height: 60px;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #2a303c;
  border-radius: var(--radius-md);
}

.item-image {
  width: 48px;
  height: 48px;
  object-fit: contain;
}

.item-details h3 {
  margin: 0;
  color: var(--text-primary);
  font-size: 1.2em;
}

.item-stat {
  font-weight: bold;
  margin: var(--spacing-md) 0 var(--spacing-xs) 0;
}

.item-stat:last-of-type {
  margin-bottom: 0;
}

.item-value {
  font-weight: bold;
  color: var(--color-primary);
  margin: 0 0 var(--spacing-sm) 0;
  font-size: 1.1em;
}

.dialog-message {
  text-align: center;
  margin-bottom: var(--spacing-md);
  color: var(--text-primary);
}

.dialog-actions {
  display: flex;
  gap: var(--spacing-sm);
}

.pickup-button, .skip-button {
  flex: 1;
  padding: var(--spacing-sm) var(--spacing-md);
  border: none;
  border-radius: var(--radius-md);
  font-weight: bold;
  cursor: pointer;
  transition: all 0.2s ease;
}

.pickup-button {
  background-color: var(--color-primary);
  color: white;
}

.pickup-button:hover {
  background-color: var(--color-primary-hover);
  transform: translateY(-2px);
}

.skip-button {
  background-color: #2d3748;
  color: var(--text-primary);
}

.skip-button:hover {
  background-color: #3a4556;
  transform: translateY(-2px);
}
</style> 