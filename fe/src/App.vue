<template>
  <div id="debug-app">
    <!-- App Root header -->
    <h2 style="color: red; text-align: center;">{{ message }}</h2>

    <!-- Direct component test -->
    <div style="border: 2px solid green; padding: 10px; margin: 10px; min-height: 100px;">
      <p style="color: white; text-align: center;">Direct Component Test:</p>
      <div style="background-color: #244; color: white; padding: 20px; text-align: center;">
        <h3>Direct Component Test Working</h3>
        <p>This confirms Vue can render components outside the router</p>
      </div>
    </div>

    <!-- Router view container -->
    <div style="border: 2px solid yellow; padding: 10px; margin: 10px; min-height: 300px;">
      <p style="color: white; text-align: center;">Router View Container - Content should appear below:</p>
      <router-view></router-view>
    </div>
  </div>
</template>

<script>
export default {
  name: 'App',
  data() {
    return {
      message: 'App Root Mounted'
    }
  },
  mounted() {
    console.log('App component mounted')
  }
}

.header-collapsed .collapse-button {
  background-color: #e74c3c;
}

.header-collapsed .collapse-button:hover {
  background-color: #c0392b;
}
</script>

<style>
body {
  font-family: 'Arial', sans-serif;
  margin: 0;
  padding: 0;
  background-color: #111;
}

#app {
  width: 100%;
  height: 100vh;
  color: white; /* Ensure text is visible against dark background */
  padding: 5px 0;
  width: 100%;
}

.main-header.header-collapsed {
  padding: 8px 15px;
}

.main-header.header-collapsed .header-controls {
  padding: 0;
}
</style>
