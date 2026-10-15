<template>
  <!-- A chest without a key: shown in the decision plaque -->
  <div class="missing-key-dialog plaque-dialog">
    <h4>{{ $t('dialogs.missingKey.title') }}</h4>
    <div class="plaque-dialog__item">
      <img
        :src="chestType === 'ruby_chest' ? '/images/ruby-chest.webp' : '/images/chest-closed.webp'"
        :alt="itemName(chestType === 'ruby_chest' ? 'ruby_chest' : 'chest')"
        class="plaque-dialog__img"
      >
      <span
        class="key-missing"
        :aria-label="$t('dialogs.missingKey.noKey')"
      ><GameIcon
        name="key"
        :size="30"
      /><GameIcon
        name="close"
        :size="16"
        class="key-missing-cross"
      /></span>
    </div>
    <p>{{ chestText }}</p>
    <p>{{ $t('dialogs.missingKey.hint') }}</p>
    <div class="plaque-dialog__actions">
      <button
        type="button"
        class="btn btn--ember close-button"
        @click="close"
      >
        {{ $t('common.close') }} <span class="kbd-hint">(Enter)</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import GameIcon from '@/components/ui/GameIcon.vue';
import { t } from '@/i18n';
import { itemName } from '@/i18n/names';

const props = defineProps({
  chestType: {
    type: String,
    default: 'chest'
  }
});

const emit = defineEmits(['close']);

// The message for the chest type
const chestText = computed(() => t(props.chestType === 'ruby_chest' ? 'dialogs.missingKey.ruby' : 'dialogs.missingKey.chest'));

// Close the dialog
const close = () => {
  emit('close');
};

// Keyboard handler
const onKeyDown = (e) => {
  if (e.key === 'Enter' || e.key === 'Escape') {
    close();
    e.preventDefault();
    e.stopPropagation();
  }
};

onMounted(() => window.addEventListener('keydown', onKeyDown, true));
onUnmounted(() => window.removeEventListener('keydown', onKeyDown, true));
</script>

<style scoped>
.key-missing {
  position: relative;
  display: inline-flex;
  color: var(--text-secondary);
}

.key-missing-cross {
  position: absolute;
  right: -6px;
  bottom: -4px;
  color: var(--color-danger);
}
</style>
