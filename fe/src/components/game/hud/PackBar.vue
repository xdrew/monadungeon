<template>
  <!-- Your own kit stays at hand, bottom left ("C · Five players") -->
  <section
    class="pack-bar"
    :aria-label="t('hud.yourPack')"
  >
    <span class="pack-bar__caption">{{ t('hud.yourPack') }}</span>
    <InventorySlots
      :inventory="inventory"
      :hero-flags="heroFlags"
      :castable="castable"
      :active-item-id="activeItemId"
      @cast="emit('cast', $event)"
    />
  </section>
</template>

<script setup>
import { useI18n } from 'vue-i18n';
import InventorySlots from './InventorySlots.vue';

defineProps({
  inventory: { type: Object, default: () => ({}) },
  heroFlags: { type: Object, default: () => ({}) },
  castable: { type: Function, default: () => false },
  activeItemId: { type: String, default: null }
});

const emit = defineEmits(['cast']);
const { t } = useI18n();
</script>

<style scoped>
.pack-bar {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.pack-bar__caption {
  font-weight: 600;
  font-size: 14px;
  letter-spacing: 0.3em;
  font-variant-caps: all-small-caps;
  color: var(--text-muted);
  text-shadow: 0 2px 4px #000;
}

@media (max-width: 768px), (max-height: 500px) {
  .pack-bar__caption {
    display: none;
  }

  .pack-bar :deep(.inventory-slots) {
    flex-wrap: nowrap;
    --slot: 32px;
    --slot-img: 26px;
  }
}
</style>
