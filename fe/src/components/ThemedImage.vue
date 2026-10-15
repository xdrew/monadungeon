<template>
  <img
    v-if="src && !failed"
    v-bind="$attrs"
    :src="src"
    :alt="alt"
    :class="imgClass"
    @error="onError"
  />
  <GameIcon
    v-else-if="fallbackIcon"
    v-bind="$attrs"
    :name="fallbackIcon"
    :size="fallbackIconSize"
    :title="alt"
    :class="['themed-icon', emojiClass]"
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
 * With `fallbackIcon` the fallback is a Torchlight line icon instead of the emoji.
 */
import { ref, watch } from 'vue';
import GameIcon from '@/components/ui/GameIcon.vue';

const props = defineProps({
  src: { type: String, default: null },
  emoji: { type: String, default: '❓' },
  alt: { type: String, default: '' },
  imgClass: { type: [String, Array, Object], default: '' },
  emojiClass: { type: [String, Array, Object], default: '' },
  /** GameIcon name shown instead of the emoji when the image is missing */
  fallbackIcon: { type: String, default: null },
  fallbackIconSize: { type: [Number, String], default: 32 }
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
  console.warn('[theme] image missing, fallback shown:', src);
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
