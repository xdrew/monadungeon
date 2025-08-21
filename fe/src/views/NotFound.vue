<template>
  <div class="not-found">
    <div class="content">
      <h1>404</h1>
      <h2>Page Not Found</h2>
      <p>The dungeon path you seek doesn't exist...</p>
      <router-link
        to="/"
        class="home-button"
      >
        Return to Home
      </router-link>
    </div>
  </div>
</template>

<script>
export default {
  name: 'NotFound',
  methods: {
    goToHome() {
      this.$router.push({ name: 'home' });
    }
  }
}
</script>

<style scoped>
.not-found {
  height: 100vh;
  display: flex;
  justify-content: center;
  align-items: center;
  background-color: #1e1e1e;
  color: white;
  text-align: center;
}

.content {
  background-color: rgba(0, 0, 0, 0.7);
  padding: 2rem;
  border-radius: 8px;
  max-width: 600px;
}

h1 {
  font-size: 6rem;
  margin: 0;
  color: #ff6b6b;
  line-height: 1;
}

h2 {
  font-size: 2rem;
  margin-top: 0;
  margin-bottom: 1rem;
}

p {
  font-size: 1.2rem;
  margin-bottom: 2rem;
  color: #ccc;
  opacity: 0.8;
}

.home-button {
  display: inline-block;
  padding: 0.8rem 1.5rem;
  background-color: #8B4513;
  color: white;
  text-decoration: none;
  border-radius: 4px;
  font-weight: bold;
  transition: background-color 0.3s;
}

.home-button:hover {
  background-color: #A0522D;
}

button {
  background-color: #4CAF50;
  color: white;
  padding: 0.8rem 1.5rem;
  border: none;
  border-radius: 4px;
  font-size: 1.1rem;
  font-weight: bold;
  cursor: pointer;
  transition: all 0.3s ease;
}

button:hover {
  background-color: #45a049;
  transform: translateY(-2px);
}
</style>
