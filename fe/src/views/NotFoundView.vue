<template>
  <div class="not-found">
    <h1>404</h1>
    <h2>Page Not Found</h2>
    <p>The page you are looking for doesn't exist or has been moved.</p>
    <button
      class="home-button"
      @click="goToHome"
    >
      Return to Home
    </button>
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router';

const router = useRouter();

const goToHome = () => {
  router.push('/');
};
</script>

<style scoped>
.not-found {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  text-align: center;
  padding: 20px;
  background-color: #222;
  color: white;
}

h1 {
  font-size: 8rem;
  margin: 0;
  color: #ff6b6b;
}

h2 {
  font-size: 2rem;
  margin-bottom: 1rem;
  color: #f8f8f8;
}

p {
  font-size: 1.1rem;
  color: #ccc;
  margin-bottom: 2rem;
}

.home-button {
  padding: 12px 24px;
  background-color: #4CAF50;
  color: white;
  border: none;
  border-radius: 4px;
  font-size: 1rem;
  cursor: pointer;
  transition: background-color 0.2s;
}

.home-button:hover {
  background-color: #45a049;
}
</style>
