<template>
  <div class="dialog-overlay">
    <div class="dialog-content missing-key-dialog">
      <div class="dialog-header">
        <h2>Missing Key</h2>
      </div>
      
      <div class="dialog-body">
        <div class="missing-key-icon">
          <img
            v-if="chestType === 'ruby_chest'"
            src="/images/ruby-chest.png"
            alt="Ruby Chest"
            class="chest-icon"
          />
          <img
            v-else
            src="/images/chest-closed.png"
            alt="Treasure Chest"
            class="chest-icon"
          />
          <span class="key-missing">🔑❌</span>
        </div>
        
        <p>You cannot open this {{ formatChestType }} without a key!</p>
        <p>Find a key first before attempting to open this chest.</p>
      </div>
      
      <div class="dialog-footer">
        <button
          class="close-button"
          @click="close"
        >
          Close
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { defineProps, defineEmits, computed } from 'vue';

const props = defineProps({
  chestType: {
    type: String,
    default: 'chest'
  }
});

const emit = defineEmits(['close']);

// Format the chest type for display
const formatChestType = computed(() => {
  if (props.chestType === 'ruby_chest') {
    return 'Ruby Chest';
  }
  return 'Treasure Chest';
});

// Close the dialog
const close = () => {
  emit('close');
};
</script>

<style scoped>
.dialog-overlay {
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
  background-color: #1a1a2e;
  border-radius: 8px;
  box-shadow: 0 0 20px rgba(0, 0, 0, 0.5);
  width: 400px;
  max-width: 90vw;
  overflow: hidden;
  animation: dialog-appear 0.3s ease-out;
}

@keyframes dialog-appear {
  from {
    opacity: 0;
    transform: translateY(-20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.dialog-header {
  background-color: #393e46;
  padding: 15px 20px;
  border-bottom: 1px solid #252a34;
}

.dialog-header h2 {
  margin: 0;
  color: #fff;
  font-size: 1.5rem;
}

.dialog-body {
  padding: 20px;
  color: #eeeeee;
}

.dialog-footer {
  padding: 15px 20px;
  display: flex;
  justify-content: flex-end;
  border-top: 1px solid #252a34;
}

.close-button {
  background-color: #4a6fa5;
  color: white;
  border: none;
  padding: 8px 20px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 1rem;
  transition: background-color 0.2s;
}

.close-button:hover {
  background-color: #5a8dd6;
}

.missing-key-icon {
  font-size: 3rem;
  display: flex;
  justify-content: center;
  margin-bottom: 20px;
  align-items: center;
}

.missing-key-icon span {
  margin: 0 10px;
}

.chest-icon {
  width: 60px;
  height: 60px;
  object-fit: contain;
  margin: 0 10px;
}

.key-missing {
  display: inline-block;
  position: relative;
}

p {
  text-align: center;
  margin: 10px 0;
  line-height: 1.5;
}
</style> 