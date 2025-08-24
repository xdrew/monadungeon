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
  font-family: 'Arial', sans-serif;
  margin: 0;
  padding: 0;
  background-color: #0a0a0a;
}

#app {
  width: 100%;
  min-height: 100vh;
  color: white;
}
</style>
