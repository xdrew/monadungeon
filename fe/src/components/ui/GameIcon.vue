<template>
  <svg
    class="game-icon"
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
    :role="title ? 'img' : undefined"
    :aria-label="title || undefined"
    :aria-hidden="title ? undefined : 'true'"
    focusable="false"
  >
    <title v-if="title">{{ title }}</title>
    <component
      :is="shape.tag"
      v-for="(shape, index) in shapes"
      :key="index"
      v-bind="shape.attrs"
    />
  </svg>
</template>

<script setup>
/**
 * Torchlight line icon (set in ./gameIcons.js), drawn in currentColor.
 * Decorative (aria-hidden) unless a `title` is given.
 */
import { computed } from 'vue';
import { ICONS } from './gameIcons';

const props = defineProps({
  name: {
    type: String,
    required: true,
    validator: (value) => value in ICONS
  },
  size: { type: [Number, String], default: 20 },
  title: { type: String, default: '' }
});

const shapes = computed(() => {
  const icon = ICONS[props.name];
  if (!icon) {
    console.warn('[GameIcon] unknown icon:', props.name);
    return [];
  }
  return icon;
});
</script>

<style scoped>
.game-icon {
  display: inline-block;
  flex: none;
  vertical-align: -0.15em;
}
</style>
