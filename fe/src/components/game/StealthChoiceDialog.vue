<template>
  <!-- Thief "Stealth": fight or sneak past; shown in the decision plaque -->
  <div
    v-if="show"
    class="stealth-overlay plaque-dialog"
    data-testid="stealth-dialog"
  >
    <h4><GameIcon
      name="eye"
      :size="18"
    /> {{ $t('skills.stealth.name') }}</h4>
    <div class="plaque-dialog__item">
      <img
        :src="monsterImage"
        :alt="monsterName"
        class="plaque-dialog__img plaque-dialog__img--monster"
      >
      <span class="plaque-dialog__name">{{ monsterName }}</span>
      <span
        v-if="monsterHp"
        class="plaque-dialog__stat plaque-dialog__stat--hp"
      ><GameIcon
        name="heart"
        :size="14"
      /> {{ monsterHp }}</span>
    </div>
    <p>{{ $t('dialogs.stealth.prompt') }}</p>
    <div class="plaque-dialog__actions">
      <button
        type="button"
        class="btn btn--ghost btn-sneak"
        data-testid="stealth-sneak"
        @click="choose(true)"
      >
        <GameIcon
          name="eye"
          :size="16"
        /> {{ $t('dialogs.stealth.sneak') }} <span class="kbd-hint">(S)</span>
      </button>
      <button
        type="button"
        class="btn btn--ember btn-fight"
        data-testid="stealth-fight"
        @click="choose(false)"
      >
        <GameIcon
          name="sword"
          :size="16"
        /> {{ $t('dialogs.stealth.fight') }} <span class="kbd-hint">(Enter)</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import GameIcon from '@/components/ui/GameIcon.vue';
import { getMonsterImage } from '@/utils/monsterUtils';
import { monsterName as localizedMonsterName } from '@/i18n/names';

const props = defineProps({
  show: { type: Boolean, default: false },
  /** Field item at the destination: { name, type, guardHP, guardDefeated } */
  monster: { type: Object, default: null }
});

const emit = defineEmits(['choose']);

const monsterKey = computed(() => props.monster?.name || props.monster?.type || '');
const monsterName = computed(() => localizedMonsterName(monsterKey.value, 'name', props.monster?.guardHP || 0));
const monsterImage = computed(() => getMonsterImage({ monster_name: monsterKey.value, monster: props.monster?.guardHP || 0 }));
const monsterHp = computed(() => props.monster?.guardHP || 0);

// true = sneak past (ignore the monster), false = fight
const choose = (sneak) => emit('choose', sneak);

const onKeyDown = (e) => {
  if (!props.show) return;
  if (e.key === 'Enter') {
    choose(false);
  } else if (e.key === 's' || e.key === 'S' || e.key === 'ы' || e.key === 'Ы') {
    choose(true);
  } else {
    return;
  }
  e.preventDefault();
  e.stopPropagation();
};

onMounted(() => window.addEventListener('keydown', onKeyDown, true));
onUnmounted(() => window.removeEventListener('keydown', onKeyDown, true));
</script>

