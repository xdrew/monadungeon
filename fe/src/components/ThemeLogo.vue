<template>
  <div
    v-if="theme.logo"
    class="theme-logo"
    :class="`theme-logo--${size}`"
  >
    <img :src="theme.logo" :alt="theme.appName" />
  </div>
</template>

<script setup>
/**
 * Brand logo of the theme. Renders nothing when the theme has no logo.
 */
import { theme } from '@/theme';

defineProps({
  size: { type: String, default: 'large', validator: (value) => ['large', 'small'].includes(value) }
});
</script>

<style scoped>
.theme-logo {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
}

.theme-logo--large {
  margin-bottom: 20px;
}

.theme-logo--large img {
  height: 60px;
  width: auto;
  transition: filter 0.3s ease;
}

.theme-logo--small {
  margin-bottom: 10px;
}

.theme-logo--small img {
  height: 30px;
  width: auto;
  transition: filter 0.3s ease;
}
</style>
