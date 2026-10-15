<template>
  <div class="duel-result" data-testid="duel-result">
    <div class="duel-result-header"><GameIcon name="sword" :size="18" /> {{ $t('battle.duelHeader') }}</div>

    <p v-if="phaseText" class="duel-phase">{{ phaseText }}</p>

    <div class="duel-sides">
      <div
        v-for="side in sides"
        :key="side.role"
        class="duel-side"
        :class="{ winner: side.isWinner, loser: side.isLoser }"
      >
        <div class="duel-side-name">{{ side.label }}</div>
        <template v-if="side.total !== null">
          <div class="duel-side-total">{{ side.total }}</div>
          <div class="duel-side-detail">
            <GameIcon name="dice" :size="14" /> {{ side.dice }}<span v-if="side.itemDamage"> · <GameIcon name="sword" :size="14" /> +{{ side.itemDamage }}</span>
            <span v-if="side.bonuses.length"> · {{ side.bonuses.join(', ') }}</span>
          </div>
        </template>
        <div v-else class="duel-side-detail">{{ $t('dialogs.duel.rolling') }}</div>
      </div>
    </div>

    <p v-if="resultText" class="duel-outcome" data-testid="duel-outcome">{{ resultText }}</p>

    <!-- The human challenger who won picks the loot -->
    <div v-if="canPickLoot" class="duel-loot" data-testid="duel-loot-choice">
      <div class="duel-loot-title">{{ $t('dialogs.duel.lootFrom', { who: loserLabel }) }}</div>
      <div class="duel-loot-items">
        <button
          v-for="item in duel.lootCandidates"
          :key="item.itemId"
          type="button"
          class="duel-loot-item"
          :disabled="disabled"
          :data-testid="`duel-loot-${item.itemId}`"
          @click="emit('loot', item.itemId)"
        >
          <img v-if="getItemImage(item)" :src="getItemImage(item)" :alt="itemName(item)" class="duel-loot-img">
          <span>{{ itemName(item) }}</span>
        </button>
      </div>
    </div>
    <p v-else-if="lootText" class="duel-loot-taken">{{ lootText }}</p>

    <button
      v-if="dismissable"
      type="button"
      class="duel-dismiss"
      data-testid="duel-dismiss"
      @click="emit('dismiss')"
    >
      {{ $t('dialogs.duel.dismiss') }}
    </button>
  </div>
</template>

<script setup>
import GameIcon from '@/components/ui/GameIcon.vue';
import { computed } from 'vue';
import { getItemImage } from '@/utils/itemUtils';
import { t } from '@/i18n';
import { itemName as localizedItemName } from '@/i18n/names';

const props = defineProps({
  /** state.duel from the backend */
  duel: { type: Object, required: true },
  /** [{ id, label }] */
  players: { type: Array, default: () => [] },
  currentPlayerId: { type: String, default: null },
  disabled: { type: Boolean, default: false }
});

const emit = defineEmits(['loot', 'dismiss']);

const labelOf = (playerId) => props.players.find((p) => p.id === playerId)?.label || t('common.player');
const challengerLabel = computed(() => labelOf(props.duel.challengerId));
const opponentLabel = computed(() => labelOf(props.duel.opponentId));
const loserLabel = computed(() => labelOf(props.duel.loser));
const winnerId = computed(() => {
  if (props.duel.result === 'challenger') return props.duel.challengerId;
  if (props.duel.result === 'opponent') return props.duel.opponentId;
  return null;
});

// Duel detail flag → the skill that gave the bonus
const BONUS_SKILLS = {
  farseeing: 'farseeing',
  hpSacrificed: 'sacrifice',
  rerolled: 'double_attack',
  spellsKept: 'magical_affinity'
};

const sideOf = (role) => {
  const details = props.duel[`${role}Details`] || null;
  const playerId = props.duel[`${role}Id`];
  return {
    role,
    label: role === 'challenger' ? challengerLabel.value : opponentLabel.value,
    total: props.duel[`${role}Total`] ?? null,
    dice: (details?.dice || []).join(' + '),
    itemDamage: details?.itemDamage || 0,
    bonuses: Object.entries(BONUS_SKILLS).filter(([key]) => details?.[key]).map(([, skill]) => t(`skills.${skill}.name`)),
    isWinner: !!winnerId.value && winnerId.value === playerId,
    isLoser: !!props.duel.loser && props.duel.loser === playerId
  };
};
const sides = computed(() => [sideOf('challenger'), sideOf('opponent')]);

const phaseText = computed(() => {
  switch (props.duel.phase) {
    case 'challenger_roll': return t('dialogs.duel.challengerRolls', { who: challengerLabel.value });
    case 'opponent_roll': return t('dialogs.duel.opponentRolls', { who: opponentLabel.value, n: props.duel.challengerTotal });
    default: return '';
  }
});

const resultText = computed(() => {
  if (!props.duel.result) return '';
  if (props.duel.result === 'tie') return t('dialogs.duel.tie');
  const winner = props.duel.result === 'challenger' ? challengerLabel.value : opponentLabel.value;
  if (props.duel.reincarnatedTo) {
    return t('dialogs.duel.winsReincarnates', { winner, loser: loserLabel.value, place: props.duel.reincarnatedTo });
  }
  return t('dialogs.duel.wins', { winner, loser: loserLabel.value });
});

const itemName = (item, form = 'nom') => localizedItemName(item?.type || null, form);

const canPickLoot = computed(() => props.duel.phase === 'loot'
  && winnerId.value === props.currentPlayerId
  && (props.duel.lootCandidates || []).length > 0);

const lootText = computed(() => {
  if (!props.duel.result || props.duel.result === 'tie') return '';
  if (props.duel.loot) return t('dialogs.duel.takes', { who: labelOf(winnerId.value), item: itemName(props.duel.loot, 'acc') });
  if (props.duel.phase === 'done') return t('dialogs.duel.nothing');
  return '';
});

const dismissable = computed(() => props.duel.phase === 'done');
</script>

<style scoped>
.duel-result {
  margin: 10px 0;
  padding: 12px 14px;
  border-radius: 10px;
  border: 1px solid rgba(224, 122, 42, 0.45);
  background: rgba(70, 35, 15, 0.4);
  color: #ffeedd;
  text-align: center;
}

.duel-result-header {
  font-size: 0.78rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #f0b27a;
  margin-bottom: 6px;
}

.duel-phase,
.duel-outcome,
.duel-loot-taken {
  margin: 6px 0;
  font-size: 0.88rem;
  line-height: 1.4;
}

.duel-outcome {
  font-weight: 700;
}

.duel-sides {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.duel-side {
  padding: 6px;
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.25);
  border: 1px solid transparent;
}

.duel-side.winner {
  border-color: rgba(102, 187, 106, 0.7);
}

.duel-side.loser {
  border-color: rgba(229, 115, 115, 0.7);
}

.duel-side-name {
  font-size: 0.8rem;
  color: #f3dcc6;
}

.duel-side-total {
  font-size: 1.4rem;
  font-weight: 800;
}

.duel-side-detail {
  font-size: 0.72rem;
  color: #d9c2ad;
}

.duel-loot-title {
  font-size: 0.85rem;
  margin: 6px 0;
}

.duel-loot-items {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  justify-content: center;
}

.duel-loot-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border: none;
  border-radius: 8px;
  font-size: 0.85rem;
  font-weight: 600;
  color: #fff;
  cursor: pointer;
  background: linear-gradient(135deg, #b5541c, #e07a2a);
}

.duel-loot-item:disabled {
  opacity: 0.5;
  cursor: default;
}

.duel-loot-img {
  width: 24px;
  height: 24px;
  object-fit: contain;
}

.duel-dismiss {
  margin-top: 8px;
  padding: 6px 14px;
  border: none;
  border-radius: 8px;
  font-size: 0.82rem;
  color: #fff;
  cursor: pointer;
  background: linear-gradient(135deg, #37474f, #607d8b);
}
</style>
