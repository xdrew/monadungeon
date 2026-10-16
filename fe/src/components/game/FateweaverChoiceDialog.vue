<template>
  <!-- Oracle "Fateweaver": which of two drawn tokens is placed; shown in the decision plaque -->
  <div
    v-if="show"
    class="fateweaver-overlay plaque-dialog"
    data-testid="fateweaver-dialog"
  >
    <h4><GameIcon
      name="eye"
      :size="18"
    /> {{ $t('skills.fateweaver.name') }}</h4>
    <p>{{ $t('dialogs.fateweaver.prompt') }}</p>
    <div class="fateweaver-tokens">
      <div
        v-for="(token, index) in tokens"
        :key="token.itemId || index"
        class="fateweaver-token"
        :class="{ 'is-monster': token.isMonster }"
      >
        <img
          v-if="token.image"
          :src="token.image"
          :alt="token.label"
          class="fateweaver-token-image"
        >
        <GameIcon
          v-else
          name="treasure"
          :size="40"
          class="fateweaver-token-icon"
        />
        <span class="fateweaver-token-name">{{ token.label }}</span>
        <span class="fateweaver-token-badges">
          <span
            v-if="token.isMonster"
            class="plaque-dialog__stat plaque-dialog__stat--hp"
          ><GameIcon
            name="sword"
            :size="13"
          /> {{ $t('dialogs.fateweaver.hp', { n: token.hp }) }}</span>
          <span
            v-if="token.value > 0"
            class="plaque-dialog__stat plaque-dialog__stat--gold"
          ><GameIcon
            name="treasure"
            :size="13"
          /> {{ $t('dialogs.vp', { n: token.value }) }}</span>
        </span>
        <button
          type="button"
          class="btn btn--ember btn--sm btn-place"
          :data-testid="`fateweaver-item-${index}`"
          @click="choose(token.itemId)"
        >
          {{ $t('dialogs.fateweaver.place') }} <span class="kbd-hint">({{ index + 1 }})</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import GameIcon from '@/components/ui/GameIcon.vue';
import { computed, onMounted, onUnmounted } from 'vue';
import { getItemImage, formatItemName } from '@/utils/itemUtils';
import { getMonsterImage } from '@/utils/monsterUtils';
import { monsterName } from '@/i18n/names';

const props = defineProps({
  show: { type: Boolean, default: false },
  /** Oracle "Fateweaver": { fieldPlace, items: [{ itemId, name, type, guardHP, guardDefeated, treasureValue }, ...] } */
  choice: { type: Object, default: null }
});

const emit = defineEmits(['choose']);

const tokens = computed(() => (props.choice?.items || []).map((item) => {
  const isMonster = !item.guardDefeated && (item.guardHP || 0) > 0;
  return {
    itemId: item.itemId,
    isMonster,
    hp: item.guardHP || 0,
    value: item.treasureValue || 0,
    label: isMonster
      ? monsterName(item.name, 'name', item.guardHP || 0)
      : formatItemName(item.name),
    image: isMonster ? getMonsterImage({ monster_name: item.name, monster: item.guardHP || 0 }) : getItemImage(item)
  };
}));

const choose = (itemId) => {
  if (itemId) emit('choose', itemId);
};

const onKeyDown = (e) => {
  if (!props.show) return;
  const index = ['1', '2'].indexOf(e.key);
  if (index === -1 || !tokens.value[index]) return;
  choose(tokens.value[index].itemId);
  e.preventDefault();
  e.stopPropagation();
};

onMounted(() => window.addEventListener('keydown', onKeyDown, true));
onUnmounted(() => window.removeEventListener('keydown', onKeyDown, true));
</script>

<style scoped>
.fateweaver-tokens {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.fateweaver-token {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 10px;
  border: 1px solid rgba(234, 220, 192, 0.2);
  background: rgba(0, 0, 0, 0.35);
  text-align: center;
}

.fateweaver-token.is-monster {
  border-color: rgba(var(--ember-rgb), 0.45);
}

.fateweaver-token-image {
  width: 64px;
  height: 64px;
  object-fit: contain;
  filter: drop-shadow(0 4px 4px #000);
}

.fateweaver-token-icon {
  color: #d9c08a;
}

.fateweaver-token-name {
  font-weight: 600;
  font-size: 14px;
  color: var(--text-heading);
}

.fateweaver-token-badges {
  display: flex;
  gap: 8px;
}

.fateweaver-token .btn-place {
  width: 100%;
}
</style>
