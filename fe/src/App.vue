<template>
  <div id="app">
    <router-view></router-view>
  </div>
</template>

<script>
export default {
  name: 'App',
  mounted() {
    console.log('App component mounted')
  }
}
</script>

<style>
body {
  font-family: var(--font-body);
  margin: 0;
  padding: 0;
  background-color: var(--bg-primary);
}

#app {
  width: 100%;
  min-height: 100vh;
  color: var(--text-primary);
}
</style>
