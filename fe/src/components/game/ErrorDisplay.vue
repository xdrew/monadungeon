<template>
  <div class="error">
    <h2>Error Loading Game</h2>
    <p>{{ errorMessage }}</p>
    <button
      class="refresh-button"
      @click="refreshPage"
    >
      Refresh Page
    </button>
  </div>
</template>

<script setup>
import { defineProps } from 'vue';

const props = defineProps({
  errorMessage: {
    type: String,
    required: true
  }
});

const refreshPage = () => {
  window.location.reload();
};
</script>

<style scoped>
.error {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 2rem;
  margin: 2rem auto;
  max-width: 500px;
  background-color: rgba(255, 0, 0, 0.1);
  border: 1px solid #ff5252;
  border-radius: 8px;
  text-align: center;
}

h2 {
  color: #ff5252;
  margin-bottom: 1rem;
}

p {
  margin-bottom: 1.5rem;
  color: #333;
}

.refresh-button {
  background-color: #ff5252;
  color: white;
  border: none;
  padding: 0.5rem 1rem;
  border-radius: 4px;
  cursor: pointer;
  font-weight: 500;
  transition: background-color 0.2s;
}

.refresh-button:hover {
  background-color: #ff3838;
}
</style> 