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
// sentences use the plain name (third person: "Aderyn wins the duel", never "You wins")
const nameOf = (playerId) => props.players.find((p) => p.id === playerId)?.name || labelOf(playerId);
const challengerLabel = computed(() => labelOf(props.duel.challengerId));
const opponentLabel = computed(() => labelOf(props.duel.opponentId));
const loserLabel = computed(() => nameOf(props.duel.loser));
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
    case 'challenger_roll': return t('dialogs.duel.challengerRolls', { who: nameOf(props.duel.challengerId) });
    case 'opponent_roll': return t('dialogs.duel.opponentRolls', { who: nameOf(props.duel.opponentId), n: props.duel.challengerTotal });
    default: return '';
  }
});

const resultText = computed(() => {
  if (!props.duel.result) return '';
  if (props.duel.result === 'tie') return t('dialogs.duel.tie');
  const winner = nameOf(props.duel.result === 'challenger' ? props.duel.challengerId : props.duel.opponentId);
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
  if (props.duel.loot) return t('dialogs.duel.takes', { who: nameOf(winnerId.value), item: itemName(props.duel.loot, 'acc') });
  if (props.duel.phase === 'done') return t('dialogs.duel.nothing');
  return '';
});

const dismissable = computed(() => props.duel.phase === 'done');
</script>

<style scoped>
/* Battle Arena: the outcome of a duel and the loot — in the decision plaque */
.duel-result {
  margin: 10px 0;
  padding: 12px 14px;
  border: 1px solid rgba(var(--ember-rgb), 0.35);
  background: rgba(0, 0, 0, 0.35);
  color: var(--text-primary);
  text-align: center;
}

.duel-result-header {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  margin-bottom: 6px;
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 1.1;
  color: var(--text-heading);
}

.duel-phase,
.duel-outcome,
.duel-loot-taken {
  margin: 6px 0;
  font-size: 14px;
  line-height: 1.4;
  color: var(--text-secondary);
}

.duel-outcome {
  font-weight: 600;
  color: var(--text-heading);
}

.duel-sides {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.duel-side {
  padding: 8px 6px;
  background: rgba(0, 0, 0, 0.3);
  border: 1px solid rgba(234, 220, 192, 0.12);
}

.duel-side.winner {
  border-color: rgba(var(--ember-rgb), 0.7);
}

.duel-side.loser {
  border-color: rgba(217, 69, 42, 0.6);
}

.duel-side-name {
  font-size: 13px;
  color: var(--text-secondary);
}

.duel-side-total {
  font-family: var(--font-display);
  font-size: 34px;
  line-height: 1;
  color: var(--text-heading);
}

.duel-side.winner .duel-side-total {
  color: var(--text-accent);
}

.duel-side-detail {
  font-size: 12px;
  color: var(--text-muted);
}

.duel-loot-title {
  margin: 6px 0;
  font-size: 14px;
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
  min-height: 44px;
  padding: 0 14px;
  border: 1px solid rgba(var(--ember-rgb), 0.7);
  background: rgba(var(--ember-rgb), 0.18);
  color: var(--text-heading);
  font: inherit;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: background var(--transition-fast), box-shadow var(--transition-fast);
}

.duel-loot-item:hover:not(:disabled),
.duel-loot-item:focus-visible {
  outline: none;
  box-shadow: 0 0 14px rgba(var(--ember-rgb), 0.45);
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
  min-height: 44px;
  padding: 0 14px;
  border: 1px solid rgba(var(--ember-rgb), 0.7);
  background: rgba(var(--ember-rgb), 0.18);
  color: var(--text-heading);
  font: inherit;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: background var(--transition-fast), box-shadow var(--transition-fast);
  border-color: rgba(234, 220, 192, 0.35);
  background: transparent;
  color: var(--text-primary);
}
</style>
