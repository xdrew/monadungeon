<template>
  <div class="lord-karak-overlay" @click.self="emit('close')">
    <div class="lord-karak-modal" data-testid="lord-karak-modal" role="dialog" aria-modal="true">
      <h2 class="lord-karak-header"><GameIcon name="crown" :size="26" /> {{ $t('dialogs.lordKarak.rises') }}</h2>

      <div class="lord-karak-body">
        <div v-if="portrait" class="lord-karak-portrait-wrap"><img :src="portrait" :alt="heroName('lord_karak')" class="lord-karak-portrait"></div>
        <div class="lord-karak-text">
          <I18nT scope="global" keypath="dialogs.lordKarak.becomes" tag="p" class="lord-karak-who" data-testid="lord-karak-who">
            <template #who><strong>{{ nameOf(reveal.playerId) }}</strong></template>
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

      <button type="button" class="btn btn--ember lord-karak-close" data-testid="lord-karak-close" @click="emit('close')">
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
const nameOf = (playerId) => props.players.find((p) => p.id === playerId)?.name || labelOf(playerId);
const portrait = computed(() => getHero('lord_karak')?.image || null);
const criterionText = computed(() => (CRITERIA.includes(props.reveal.criterion) ? t(`dialogs.lordKarak.criteria.${props.reveal.criterion}`) : ''));
const hasRolls = computed(() => (props.reveal.standings || []).some((row) => row.rolls?.length));
const skillsTitle = computed(() => (props.reveal.keepsOwn
  ? t('dialogs.lordKarak.keepsOwn')
  : t('dialogs.lordKarak.others')));
</script>

<style scoped>
/* Lord Karak rises: a cinematic over the dungeon — the dark, the portrait in ember light, the standings */
.lord-karak-overlay {
  position: fixed;
  inset: 0;
  z-index: var(--z-modal);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background-color: rgba(5, 3, 2, 0.92);
  background-image: radial-gradient(ellipse at 50% 35%, rgba(var(--ember-rgb), 0.2) 0%, rgba(0, 0, 0, 0) 50%);
  animation: lordKarakIn 0.5s ease-out;
}

@keyframes lordKarakIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.lord-karak-modal {
  width: min(560px, 100%);
  max-height: 92vh;
  overflow-y: auto;
  padding: 24px 28px;
  box-sizing: border-box;
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.96) 0%, rgba(12, 7, 5, 0.96) 100%);
  box-shadow: 0 0 60px rgba(var(--ember-rgb), 0.22), 0 16px 40px rgba(0, 0, 0, 0.85);
  color: var(--text-primary);
  font-family: var(--font-body);
}

.lord-karak-header {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  margin: 0 0 16px;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 36px;
  line-height: 1.1;
  color: var(--text-heading);
  text-shadow: 0 0 18px rgba(var(--ember-rgb), 0.5), 0 2px 6px #000;
}

.lord-karak-header :deep(svg) {
  color: var(--color-primary);
}

.lord-karak-body {
  display: flex;
  gap: 18px;
  align-items: center;
}

.lord-karak-portrait-wrap {
  flex: none;
  width: 132px;
  display: flex;
  justify-content: center;
  background: radial-gradient(ellipse at 50% 60%, rgba(var(--ember-rgb), 0.3) 0%, rgba(0, 0, 0, 0) 70%);
}

.lord-karak-portrait {
  width: 120px;
  height: auto;
  filter: drop-shadow(0 0 22px rgba(var(--ember-rgb), 0.45)) drop-shadow(0 8px 8px #000);
}

.lord-karak-text p {
  margin: 4px 0;
}

.lord-karak-who {
  font-size: 17px;
  color: var(--text-heading);
}

.lord-karak-why,
.lord-karak-effects {
  font-size: 14px;
  font-style: italic;
  color: var(--text-secondary);
}

.lord-karak-standings {
  width: 100%;
  margin: 18px 0 12px;
  border-collapse: collapse;
  font-size: 14px;
}

.lord-karak-standings th,
.lord-karak-standings td {
  padding: 6px 8px;
  text-align: center;
  border-bottom: 1px solid rgba(234, 220, 192, 0.12);
}

.lord-karak-standings th {
  color: var(--text-muted);
  font-weight: 600;
}

.lord-karak-standings th:first-child,
.lord-karak-standings td:first-child {
  text-align: left;
}

.lord-karak-standings tr.weakest td {
  color: var(--text-accent);
  font-weight: 600;
}

.lord-karak-skills {
  font-size: 14px;
}

.lord-karak-skills-title {
  margin-bottom: 6px;
  font-weight: 600;
  letter-spacing: 0.16em;
  font-variant-caps: all-small-caps;
  color: var(--text-muted);
}

.lord-karak-skills ul {
  margin: 0;
  padding-left: 18px;
}

.lord-karak-none {
  margin: 0;
  color: var(--text-secondary);
}

.lord-karak-close {
  display: flex;
  min-height: 48px;
  margin: 18px auto 0;
  padding: 0 28px;
  font-family: var(--font-display);
  font-size: 22px;
}

@media (max-width: 480px) {
  .lord-karak-body {
    flex-direction: column;
    text-align: center;
  }

  .lord-karak-header {
    font-size: 28px;
  }
}
</style>
