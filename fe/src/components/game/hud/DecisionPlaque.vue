<template>
  <!-- A choice the player must make now (portal, Dark Thorn, duel, curse, retreat …), boxed like the canvas clash -->
  <section
    class="decision-plaque"
    aria-live="polite"
  >
    <slot />
  </section>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue';
import { audio } from '@/services/audio/audioEngine';

// a decision appears with a parchment unfolded, and goes with it folded
onMounted(() => audio.cue('ui_open'));
onUnmounted(() => audio.cue('ui_close'));
</script>

<style scoped>
.decision-plaque {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px 18px;
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.92) 0%, rgba(12, 7, 5, 0.92) 100%);
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  box-shadow: 0 0 30px rgba(255, 100, 20, 0.25), 0 10px 24px rgba(0, 0, 0, 0.8);
  color: var(--text-primary);
}

/* The mode panels inside keep their classes and testids; only their look is set here */
.decision-plaque :deep(h4) {
  margin: 0 0 6px;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 24px;
  line-height: 1.1;
  color: var(--text-heading);
}

.decision-plaque :deep(p) {
  margin: 0 0 8px;
  font-style: italic;
  font-size: 14px;
  line-height: 1.4;
  color: var(--text-secondary);
}

.decision-plaque :deep(.portal-targets) {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin: 8px 0;
}

.decision-plaque :deep(.portal-target.selected) {
  border-color: var(--color-primary);
  color: var(--text-heading);
  box-shadow: inset 0 0 12px rgba(var(--ember-rgb), 0.3);
}

.decision-plaque :deep(.btn--block + .btn--block) {
  margin-top: 6px;
}

/* Dialogs that live in the plaque (item found, full pack, missing key, stealth, fateweaver) */
.decision-plaque :deep(.plaque-dialog) {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.decision-plaque :deep(.plaque-dialog h4) {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
}

.decision-plaque :deep(.plaque-dialog p) {
  margin: 0;
}

.decision-plaque :deep(.plaque-dialog__item) {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}

.decision-plaque :deep(.plaque-dialog__img) {
  width: 48px;
  height: 48px;
  object-fit: contain;
  filter: drop-shadow(0 4px 4px #000);
}

.decision-plaque :deep(.plaque-dialog__img--monster) {
  width: 72px;
  height: 72px;
}

.decision-plaque :deep(.plaque-dialog__icon) {
  color: #d9c08a;
}

.decision-plaque :deep(.plaque-dialog__name) {
  font-weight: 600;
  font-size: 16px;
  color: var(--text-heading);
}

.decision-plaque :deep(.plaque-dialog__stat) {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-family: var(--font-display);
  font-size: 16px;
  color: var(--text-heading);
}

.decision-plaque :deep(.plaque-dialog__stat--gold) {
  color: #d9c08a;
}

.decision-plaque :deep(.plaque-dialog__stat--hp) {
  color: var(--color-danger);
}

.decision-plaque :deep(.plaque-dialog__actions) {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.decision-plaque :deep(.plaque-dialog__actions .btn) {
  flex: 1 1 auto;
  min-height: 44px;
  gap: 6px;
}

.decision-plaque :deep(.kbd-hint) {
  font-size: 12px;
  font-weight: 400;
  opacity: 0.6;
}
</style>
