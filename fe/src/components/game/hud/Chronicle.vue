<template>
  <!-- Chronicle, top right: the newest line burns brightest, older ones fade ("C · Torchlight") -->
  <ol
    class="chronicle"
    :aria-label="$t('hud.chronicle')"
    aria-live="polite"
  >
    <li
      v-for="(line, index) in lines"
      :key="line.key"
      class="chronicle__line"
      :style="{ opacity: opacityAt(index) }"
    >
      {{ line.text }}
    </li>
  </ol>
</template>

<script setup>
const props = defineProps({
  /** chronicleTail(): oldest first */
  lines: { type: Array, default: () => [] }
});

const FADE = [1, 0.8, 0.55, 0.35];
const opacityAt = (index) => FADE[props.lines.length - 1 - index] ?? 0.3;
</script>

<style scoped>
.chronicle {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
  text-align: right;
  text-shadow: 0 2px 4px #000, 0 0 12px #000;
  pointer-events: none;
}

/* while a tile is being placed the chronicle steps back, so it never hides where the room goes */
.chronicle {
  transition: opacity var(--transition-normal);
}

.chronicle.chronicle--quiet {
  opacity: 0.2;
}

.chronicle__line {
  font-style: italic;
  font-size: 15px;
  line-height: 1.35;
  color: var(--text-primary);
  transition: opacity var(--transition-slow);
}
</style>
