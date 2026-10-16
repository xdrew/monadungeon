<template>
  <!-- The heroes' skills in a game: a ring among the HUD tools, a panel with mine first and the rivals' below -->
  <div
    class="skills-control"
    @keydown.esc="open = false"
  >
    <button
      type="button"
      class="skills-control__ring btn btn--iron btn--sm"
      :title="t('skillsPanel.title')"
      :aria-label="t('skillsPanel.title')"
      aria-haspopup="dialog"
      :aria-expanded="open"
      data-testid="skills-ring"
      @click="open = !open"
    >
      <GameIcon
        name="lightning"
        :size="18"
      />
    </button>
    <div
      v-if="open"
      class="skills-control__panel"
      role="dialog"
      :aria-label="t('skillsPanel.title')"
      data-testid="skills-panel"
    >
      <section
        v-for="hero in heroes"
        :key="hero.id"
        class="skills-hero"
        :class="{ 'skills-hero--mine': hero.mine }"
        :data-testid="hero.mine ? 'skills-mine' : 'skills-rival'"
      >
        <header class="skills-hero__head">
          <span class="skills-hero__ring"><img
            :src="hero.portrait"
            alt=""
          ></span>
          <span class="skills-hero__who">
            <strong>{{ hero.title }}</strong>
            <span>{{ hero.mine ? t('skillsPanel.mine') : hero.name }}</span>
          </span>
        </header>
        <p
          v-if="!hero.skills.length"
          class="skills-hero__none"
        >
          {{ t('skillsPanel.none') }}
        </p>
        <ul
          v-else
          class="skills-hero__list"
        >
          <li
            v-for="skill in hero.skills"
            :key="skill.key"
            class="skill"
            :data-testid="`skill-${skill.key}`"
          >
            <div class="skill__head">
              <span class="skill__name">{{ skill.name }}</span>
              <span
                class="skill__kind"
                :class="`skill__kind--${skill.kind}`"
              >{{ t(`skillsPanel.${hero.mine ? 'kind' : 'kindRival'}.${skill.kind}`) }}</span>
            </div>
            <p class="skill__text">
              {{ skill.summary }}
            </p>
            <p
              v-if="hero.mine && skill.how"
              class="skill__how"
            >
              {{ skill.how }}
            </p>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import GameIcon from '@/components/ui/GameIcon.vue';
import { getHero } from '@/theme';
import { heroName } from '@/i18n/names';

const props = defineProps({
  /** gameData.players */
  players: { type: Array, default: () => [] },
  /** my seat (hot-seat: the player whose turn is on this screen) */
  myId: { type: String, default: null },
  /** player => the name shown in the game */
  nameOf: { type: Function, default: (p) => p?.username || '' },
});

const { t, te } = useI18n();
const open = ref(false);

/**
 * passive: works by itself; prompt: the game asks when it applies; action: you start it with a ring
 * (heroSkills keys, HeroType::skills()).
 */
const KIND = {
  double_attack: 'prompt', reincarnation: 'prompt', backstab: 'passive', stealth: 'prompt',
  combat_training: 'passive', unstoppable: 'passive', sacrifice: 'prompt', magic_swap: 'action',
  magical_affinity: 'passive', astral_walking: 'passive', farseeing: 'passive', fateweaver: 'prompt',
  berserk: 'passive', tireless: 'prompt', sword_master: 'passive', lightning_attack: 'action',
  dagger_throwing: 'passive', sprint: 'action', bear_attack: 'passive', listening: 'action',
};

const skillOf = (skill) => {
  const key = skill?.key || '';
  const known = te(`skills.${key}.name`);
  return {
    key,
    name: known ? t(`skills.${key}.name`) : (skill?.name || key),
    summary: known && te(`skills.${key}.summary`) ? t(`skills.${key}.summary`) : (skill?.description || ''),
    kind: KIND[key] || 'passive',
    how: te(`skillsPanel.how.${key}`) ? t(`skillsPanel.how.${key}`) : '',
  };
};

const heroes = computed(() => {
  const list = props.players.map((p) => ({
    id: p.id,
    mine: p.id === props.myId,
    title: heroName(p),
    name: props.nameOf(p),
    portrait: getHero(p.lordKarak ? 'lord_karak' : p.hero).image,
    skills: (p.heroSkills || []).map(skillOf),
  }));
  return [...list.filter((h) => h.mine), ...list.filter((h) => !h.mine)];
});

// A click anywhere else closes the panel
const onDocumentClick = (event) => {
  if (open.value && !event.target.closest('.skills-control')) open.value = false;
};
onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));
</script>

<style scoped>
.skills-control {
  position: relative;
}

.skills-control__panel {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 6;
  display: grid;
  gap: 12px;
  width: min(360px, calc(100vw - 32px));
  max-height: min(70vh, 640px);
  overflow-y: auto;
  padding: 14px 16px;
  background: rgb(20, 12, 8);
  border: 1px solid var(--iron-dark);
  box-shadow: 0 10px 24px rgba(0, 0, 0, 0.8);
  font-family: var(--font-body);
  color: var(--text-primary);
  text-align: left;
}

.skills-hero + .skills-hero {
  padding-top: 12px;
  border-top: 1px solid rgba(234, 220, 192, 0.12);
}

.skills-hero__head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}

.skills-hero__ring {
  display: flex;
  align-items: flex-start;
  width: 40px;
  height: 40px;
  flex: none;
  overflow: hidden;
  border-radius: 50%;
  background: radial-gradient(circle at 50% 35%, #4a3020 0%, #140c07 75%);
  box-shadow: 0 0 0 2px var(--iron), 0 0 0 3px var(--iron-edge);
}

.skills-hero--mine .skills-hero__ring {
  box-shadow: 0 0 0 2px var(--color-primary), 0 0 0 3px var(--iron-edge);
}

.skills-hero__ring img {
  width: 100%;
  height: 150%;
  object-fit: cover;
  object-position: top;
}

.skills-hero__who {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
}

.skills-hero__who strong {
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 20px;
  color: var(--text-heading);
}

.skills-hero__who span {
  font-size: 13px;
  color: var(--text-muted);
}

.skills-hero__none {
  margin: 0;
  font-style: italic;
  font-size: 14px;
  color: var(--text-secondary);
}

.skills-hero__list {
  display: grid;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.skill__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.skill__name {
  font-weight: 600;
  font-size: 15px;
  color: var(--text-accent);
}

.skill__kind {
  flex: none;
  padding: 1px 7px;
  border: 1px solid rgba(234, 220, 192, 0.25);
  font-size: 11px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--text-muted);
}

.skill__kind--action {
  border-color: rgba(var(--ember-rgb), 0.7);
  color: var(--text-accent);
}

.skill__kind--prompt {
  border-color: rgba(234, 220, 192, 0.45);
  color: var(--text-secondary);
}

.skill__text {
  margin: 2px 0 0;
  font-size: 14px;
  line-height: 1.35;
}

.skill__how {
  margin: 4px 0 0;
  font-style: italic;
  font-size: 13px;
  line-height: 1.35;
  color: var(--text-secondary);
}
</style>
