<template>
  <!-- Actions, bottom right: the rings come from the default slot, a popover (heal limit, dev plaques) from #above -->
  <div
    class="action-rings"
    :class="{ 'action-rings--waiting': !!waitingText }"
  >
    <div
      v-if="$slots.above"
      class="action-rings__above"
    >
      <slot name="above" />
    </div>
    <div class="action-rings__row">
      <span
        v-if="waitingText"
        class="action-rings__waiting"
      >{{ waitingText }}</span>
      <slot />
    </div>
  </div>
</template>

<script setup>
defineProps({
  /** "Your turn comes after …" while the others act */
  waitingText: { type: String, default: '' }
});
</script>

<style scoped>
.action-rings {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 14px;
}

.action-rings__row {
  display: flex;
  align-items: flex-start;
  gap: 16px;
}

.action-rings__waiting {
  align-self: center;
  margin-bottom: 26px;
  max-width: 260px;
  font-style: italic;
  font-size: 15px;
  color: #a8957a;
  text-align: right;
  text-shadow: 0 2px 4px #000;
}

.action-rings__above {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;
}
</style>
