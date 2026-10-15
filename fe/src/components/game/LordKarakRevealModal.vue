<template>
  <div class="lord-karak-overlay" @click.self="emit('close')">
    <div class="lord-karak-modal" data-testid="lord-karak-modal" role="dialog" aria-modal="true">
      <div class="lord-karak-header"><GameIcon name="crown" :size="20" /> {{ $t('dialogs.lordKarak.rises') }}</div>

      <div class="lord-karak-body">
        <img v-if="portrait" :src="portrait" :alt="heroName('lord_karak')" class="lord-karak-portrait">
        <div class="lord-karak-text">
          <I18nT scope="global" keypath="dialogs.lordKarak.becomes" tag="p" class="lord-karak-who" data-testid="lord-karak-who">
            <template #who><strong>{{ labelOf(reveal.playerId) }}</strong></template>
          </I18nT>
          <p class="lord-karak-why">{{ criterionText }}</p>
          <p class="lord-karak-effects">{{ $t('dialogs.lordKarak.effects') }}</p>
        </div>
      </div>

      <table class="lord-karak-standings">
        <thead>
          <tr>
            <th>{{ $t('dialogs.lordKarak.hero') }}</th>
            <th :title="$t('dialogs.lordKarak.treasure')"><GameIcon name="treasure" :size="16" :title="$t('dialogs.lordKarak.treasure')" /></th>
            <th :title="$t('dialogs.lordKarak.weapons')"><GameIcon name="sword" :size="16" :title="$t('dialogs.lordKarak.weapons')" /></th>
            <th :title="$t('dialogs.lordKarak.items')"><GameIcon name="key" :size="16" :title="$t('dialogs.lordKarak.items')" /></th>
            <th v-if="hasRolls" :title="$t('dialogs.lordKarak.rolls')"><GameIcon name="dice" :size="16" :title="$t('dialogs.lordKarak.rolls')" /></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in reveal.standings"
            :key="row.playerId"
            :class="{ weakest: row.playerId === reveal.playerId }"
          >
            <td>{{ labelOf(row.playerId) }}</td>
            <td>{{ row.treasure }}</td>
            <td>{{ row.weapons }}</td>
            <td>{{ row.items }}</td>
            <td v-if="hasRolls">{{ row.rolls.length ? row.rolls.join(' → ') : '—' }}</td>
          </tr>
        </tbody>
      </table>

      <div class="lord-karak-skills">
        <div class="lord-karak-skills-title">{{ skillsTitle }}</div>
        <ul v-if="reveal.heroes.length">
          <li v-for="hero in reveal.heroes" :key="hero">{{ heroName(hero) }}</li>
        </ul>
        <p v-else class="lord-karak-none">{{ $t('dialogs.lordKarak.none') }}</p>
      </div>

      <button type="button" class="lord-karak-close" data-testid="lord-karak-close" @click="emit('close')">
        {{ $t('dialogs.lordKarak.continue') }}
      </button>
    </div>
  </div>
</template>

<script setup>
import GameIcon from '@/components/ui/GameIcon.vue';
import { computed } from 'vue';
import { getHero } from '@/theme';
import { I18nT } from 'vue-i18n';
import { t } from '@/i18n';
import { heroName } from '@/i18n/names';

const props = defineProps({
  /** state.lordKarak from the backend */
  reveal: { type: Object, required: true },
  /** [{ id, label }] */
  players: { type: Array, default: () => [] }
});

const emit = defineEmits(['close']);

const CRITERIA = ['treasure', 'weapons', 'items', 'roll'];

const labelOf = (playerId) => props.players.find((p) => p.id === playerId)?.label || t('common.player');
const portrait = computed(() => getHero('lord_karak')?.image || null);
const criterionText = computed(() => (CRITERIA.includes(props.reveal.criterion) ? t(`dialogs.lordKarak.criteria.${props.reveal.criterion}`) : ''));
const hasRolls = computed(() => (props.reveal.standings || []).some((row) => row.rolls?.length));
const skillsTitle = computed(() => (props.reveal.keepsOwn
  ? t('dialogs.lordKarak.keepsOwn')
  : t('dialogs.lordKarak.others')));
</script>

<style scoped>
.lord-karak-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(10, 6, 16, 0.72);
}

.lord-karak-modal {
  width: min(460px, 100%);
  max-height: 90vh;
  overflow-y: auto;
  padding: 18px 20px;
  border: 1px solid rgba(243, 210, 122, 0.6);
  border-radius: 12px;
  background: #1d1626;
  color: #efe6d2;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.6);
}

.lord-karak-header {
  font-size: 20px;
  font-weight: 700;
  color: #f3d27a;
  text-align: center;
  margin-bottom: 12px;
}

.lord-karak-body {
  display: flex;
  gap: 14px;
  align-items: center;
}

.lord-karak-portrait {
  width: 96px;
  height: auto;
  flex-shrink: 0;
}

.lord-karak-text p {
  margin: 4px 0;
}

.lord-karak-who {
  font-size: 16px;
}

.lord-karak-why,
.lord-karak-effects {
  font-size: 13px;
  opacity: 0.85;
}

.lord-karak-standings {
  width: 100%;
  margin: 14px 0 10px;
  border-collapse: collapse;
  font-size: 13px;
}

.lord-karak-standings th,
.lord-karak-standings td {
  padding: 4px 6px;
  text-align: center;
  border-bottom: 1px solid rgba(239, 230, 210, 0.12);
}

.lord-karak-standings th:first-child,
.lord-karak-standings td:first-child {
  text-align: left;
}

.lord-karak-standings tr.weakest td {
  color: #f3d27a;
  font-weight: 600;
}

.lord-karak-skills {
  font-size: 13px;
}

.lord-karak-skills-title {
  font-weight: 600;
  margin-bottom: 4px;
}

.lord-karak-skills ul {
  margin: 0;
  padding-left: 18px;
}

.lord-karak-none {
  margin: 0;
  opacity: 0.8;
}

.lord-karak-close {
  display: block;
  margin: 14px auto 0;
  padding: 8px 22px;
  border: none;
  border-radius: 8px;
  background: #f3d27a;
  color: #1d1626;
  font-weight: 700;
  cursor: pointer;
}
</style>
