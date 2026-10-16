<template>
  <!-- An iron ring action ("C · Torchlight"): the label is part of the button text -->
  <button
    type="button"
    class="iron-ring"
    :class="{ 'iron-ring--lit': lit, 'iron-ring--pressed': pressed }"
    :aria-pressed="pressed === null ? undefined : pressed"
  >
    <span
      class="iron-ring__disk"
      aria-hidden="true"
    >
      <GameIcon
        :name="icon"
        :size="28"
      />
      <span
        v-if="badge !== null && badge !== ''"
        class="iron-ring__badge"
      >{{ badge }}</span>
    </span>
    <span class="iron-ring__label"><slot>{{ label }}</slot></span>
  </button>
</template>

<script setup>
import GameIcon from '@/components/ui/GameIcon.vue';

defineProps({
  icon: { type: String, required: true },
  label: { type: String, default: '' },
  /** the ember glow: this is the move to make */
  lit: { type: Boolean, default: false },
  /** toggle rings (Listen, Sprint); null = not a toggle */
  pressed: { type: Boolean, default: null },
  badge: { type: [String, Number], default: null }
});
</script>

<style scoped>
.iron-ring {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  min-width: 64px;
  padding: 0;
  border: 0;
  background: none;
  color: var(--text-primary);
  font-family: var(--font-body);
  cursor: pointer;
  --disk: 64px;
}

/* the global button:hover plate (assets/main.css) must not paint a square behind the ring */
.iron-ring:hover:not(:disabled),
.iron-ring:active {
  background: none;
  border: 0;
}

.iron-ring__disk {
  position: relative;
  width: var(--disk);
  height: var(--disk);
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: radial-gradient(circle at 40% 35%, #4a4643 0%, #1c1a19 70%);
  box-shadow: 0 0 0 3px var(--iron), 0 0 0 5px var(--iron-edge);
  transition: box-shadow var(--transition-fast), transform var(--transition-fast), color var(--transition-fast);
}

.iron-ring__label {
  max-width: 96px;
  font-size: 14px;
  font-weight: 600;
  line-height: 1.1;
  letter-spacing: 0.14em;
  font-variant-caps: all-small-caps;
  color: #c9a77a;
  text-align: center;
  text-shadow: 0 2px 4px #000;
}

.iron-ring--lit .iron-ring__disk {
  box-shadow: 0 0 0 3px var(--iron), 0 0 0 5px var(--iron-edge), 0 0 18px rgba(var(--ember-rgb), 0.55);
}

.iron-ring--pressed .iron-ring__disk {
  color: var(--color-primary);
  box-shadow: 0 0 0 3px var(--color-secondary), 0 0 0 5px var(--iron-edge), 0 0 22px rgba(var(--ember-rgb), 0.7);
}

.iron-ring:hover:not(:disabled) .iron-ring__disk {
  transform: translateY(-2px);
  color: var(--text-heading);
  box-shadow: 0 0 0 3px #77716d, 0 0 0 5px var(--iron-edge), 0 0 22px rgba(var(--ember-rgb), 0.6);
}

.iron-ring:focus-visible {
  outline: none;
}

.iron-ring:focus-visible .iron-ring__disk {
  outline: 2px solid var(--color-primary);
  outline-offset: 5px;
}

.iron-ring:disabled {
  cursor: not-allowed;
  opacity: 0.4;
}

.iron-ring__badge {
  position: absolute;
  right: -4px;
  bottom: -4px;
  min-width: 22px;
  height: 22px;
  padding: 0 4px;
  border-radius: 11px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: var(--font-display);
  font-size: 15px;
  color: var(--text-heading);
  background: var(--iron-edge);
  box-shadow: 0 0 0 2px var(--iron);
}

/* Phones: smaller disks; the label stays in the button text (End Turn) but is visually hidden */
@media (max-width: 768px), (max-height: 500px) {
  .iron-ring {
    min-width: 48px;
    --disk: 48px;
  }

  .iron-ring__label {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    clip-path: inset(50%);
    white-space: nowrap;
  }
}
</style>
