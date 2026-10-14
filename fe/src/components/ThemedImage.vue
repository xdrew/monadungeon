<template>
  <img
    v-if="src && !failed"
    v-bind="$attrs"
    :src="src"
    :alt="alt"
    :class="imgClass"
    @error="onError"
  />
  <span
    v-else
    v-bind="$attrs"
    :class="['themed-emoji', emojiClass]"
    :title="alt"
    role="img"
    :aria-label="alt"
  >{{ emoji }}</span>
</template>

<script setup>
/**
 * Image from the active theme with an emoji fallback: renders <img> while the file loads,
 * and swaps to the emoji when the theme has no image or the file is missing
 * (the base theme's webp files are added by hand, see fe/public/themes/base/README.md).
 */
import { ref, watch } from 'vue';

const props = defineProps({
  src: { type: String, default: null },
  emoji: { type: String, default: '❓' },
  alt: { type: String, default: '' },
  imgClass: { type: [String, Array, Object], default: '' },
  emojiClass: { type: [String, Array, Object], default: '' }
});

defineOptions({ inheritAttrs: false });

const failed = ref(false);

// A new src gets a fresh chance to load
watch(() => props.src, () => {
  failed.value = false;
});

const onError = () => {
  failed.value = true;
  warnOnce(props.src);
};
</script>

<script>
const warned = new Set();

const warnOnce = (src) => {
  if (!src || warned.has(src)) return;
  warned.add(src);
  console.warn('[theme] image missing, emoji fallback:', src);
};
</script>

<style scoped>
.themed-emoji {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
}
</style>
