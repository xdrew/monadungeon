<template>
  <!-- A spell or a skill the fighter may add to the roll: checkbox, image, name and effect -->
  <label
    class="consumable-card"
    :class="{ 'consumable-card--on': selected, 'consumable-card--off': disabled }"
    :title="title || undefined"
  >
    <input
      type="checkbox"
      :checked="selected"
      :disabled="disabled"
      @change="emit('toggle')"
    >
    <img
      v-if="image"
      :src="image"
      alt=""
      class="consumable-card__img"
    >
    <GameIcon
      v-else
      :name="icon"
      :size="30"
      class="consumable-card__icon"
    />
    <span class="consumable-card__text">
      <span class="consumable-card__name">{{ name }}</span>
      <span
        v-if="effect"
        class="consumable-card__effect"
      >{{ effect }}</span>
    </span>
  </label>
</template>

<script setup>
import GameIcon from '@/components/ui/GameIcon.vue';

defineProps({
  name: { type: String, required: true },
  effect: { type: String, default: '' },
  image: { type: String, default: null },
  /** GameIcon name when there is no image */
  icon: { type: String, default: 'spells' },
  selected: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  title: { type: String, default: '' }
});

const emit = defineEmits(['toggle']);
</script>

<style scoped>
.consumable-card {
  flex: 1 1 200px;
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 64px;
  padding: 10px 12px;
  box-sizing: border-box;
  border: 1px solid rgba(234, 220, 192, 0.2);
  background: rgba(0, 0, 0, 0.35);
  cursor: pointer;
  transition: border-color var(--transition-fast), background var(--transition-fast);
}

.consumable-card--on {
  border-color: rgba(var(--ember-rgb), 0.75);
  background: rgba(var(--ember-rgb), 0.12);
}

.consumable-card--off {
  cursor: default;
  opacity: 0.6;
}

.consumable-card:focus-within {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
}

.consumable-card input {
  width: 20px;
  height: 20px;
  margin: 0;
  flex: none;
  accent-color: var(--color-primary);
}

.consumable-card__img {
  width: 38px;
  height: 38px;
  flex: none;
  object-fit: contain;
}

.consumable-card__icon {
  flex: none;
  color: var(--color-danger);
}

.consumable-card__text {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
  min-width: 0;
}

.consumable-card__name {
  font-weight: 600;
  font-size: 15px;
  color: var(--text-heading);
}

.consumable-card__effect {
  font-size: 13px;
  color: var(--text-secondary);
}
</style>
