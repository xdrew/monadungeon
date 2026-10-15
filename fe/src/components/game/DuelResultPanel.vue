<template>
  <div class="duel-result" data-testid="duel-result">
    <div class="duel-result-header"><GameIcon name="sword" :size="18" /> Duel on the Battle Arena</div>

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
        <div v-else class="duel-side-detail">rolling…</div>
      </div>
    </div>

    <p v-if="resultText" class="duel-outcome" data-testid="duel-outcome">{{ resultText }}</p>

    <!-- The human challenger who won picks the loot -->
    <div v-if="canPickLoot" class="duel-loot" data-testid="duel-loot-choice">
      <div class="duel-loot-title">Take one item from {{ loserLabel }}:</div>
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
      Dismiss
    </button>
  </div>
</template>

<script setup>
import GameIcon from '@/components/ui/GameIcon.vue';
import { computed } from 'vue';
import { getItemImage } from '@/utils/itemUtils';

const props = defineProps({
  /** state.duel from the backend */
  duel: { type: Object, required: true },
  /** [{ id, label }] */
  players: { type: Array, default: () => [] },
  currentPlayerId: { type: String, default: null },
  disabled: { type: Boolean, default: false }
});

const emit = defineEmits(['loot', 'dismiss']);

const labelOf = (playerId) => props.players.find((p) => p.id === playerId)?.label || 'Player';
const challengerLabel = computed(() => labelOf(props.duel.challengerId));
const opponentLabel = computed(() => labelOf(props.duel.opponentId));
const loserLabel = computed(() => labelOf(props.duel.loser));
const winnerId = computed(() => {
  if (props.duel.result === 'challenger') return props.duel.challengerId;
  if (props.duel.result === 'opponent') return props.duel.opponentId;
  return null;
});

const BONUS_LABELS = {
  farseeing: 'Farseeing',
  hpSacrificed: 'Sacrifice',
  rerolled: 'Double Attack',
  spellsKept: 'Magical Affinity'
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
    bonuses: Object.entries(BONUS_LABELS).filter(([key]) => details?.[key]).map(([, label]) => label),
    isWinner: !!winnerId.value && winnerId.value === playerId,
    isLoser: !!props.duel.loser && props.duel.loser === playerId
  };
};
const sides = computed(() => [sideOf('challenger'), sideOf('opponent')]);

const phaseText = computed(() => {
  switch (props.duel.phase) {
    case 'challenger_roll': return `${challengerLabel.value} rolls…`;
    case 'opponent_roll': return `${opponentLabel.value} rolls against ${props.duel.challengerTotal}…`;
    default: return '';
  }
});

const resultText = computed(() => {
  if (!props.duel.result) return '';
  if (props.duel.result === 'tie') return 'Tie — nothing happens';
  const winner = props.duel.result === 'challenger' ? challengerLabel.value : opponentLabel.value;
  let text = `${winner} wins the duel; ${loserLabel.value} loses 1 HP`;
  if (props.duel.reincarnatedTo) {
    text += ` and reincarnates at ${props.duel.reincarnatedTo}`;
  }
  return text;
});

const itemName = (item) => (item?.type || 'item').split('_').map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');

const canPickLoot = computed(() => props.duel.phase === 'loot'
  && winnerId.value === props.currentPlayerId
  && (props.duel.lootCandidates || []).length > 0);

const lootText = computed(() => {
  if (!props.duel.result || props.duel.result === 'tie') return '';
  if (props.duel.loot) return `${labelOf(winnerId.value)} takes the ${itemName(props.duel.loot)}`;
  if (props.duel.phase === 'done') return 'Nothing to take';
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
