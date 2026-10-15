<template>
  <!-- A choice the player must make now (portal, Dark Thorn, duel, curse, retreat …), boxed like the canvas clash -->
  <section
    class="decision-plaque"
    aria-live="polite"
  >
    <slot />
  </section>
</template>

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
</style>
