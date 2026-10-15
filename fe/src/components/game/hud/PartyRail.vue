<template>
  <!-- The company in turn order down the left edge ("C · Five players") -->
  <section
    class="party-rail"
    aria-label="Order of the turn"
  >
    <span class="party-rail__caption">Order of the turn</span>
    <ol class="party-rail__list">
      <li
        v-for="entry in entries"
        :key="entry.player.id"
        class="party-member"
        :class="[`party-member--${entry.state}`, { 'party-member--you': entry.isYou }]"
        :data-player-id="entry.player.id"
      >
        <span class="party-member__numeral">{{ entry.numeral }}</span>
        <button
          type="button"
          class="party-member__medallion"
          :aria-label="`${entry.name}: show the kit`"
          :aria-expanded="openId === entry.player.id"
          @click="toggle(entry.player.id)"
          @mouseenter="hoverId = entry.player.id"
          @mouseleave="hoverId = null"
        >
          <img
            :src="portrait(entry.player)"
            :alt="entry.name"
            @error="emit('portrait-error', $event, entry.player)"
          >
        </button>
        <div class="party-member__body">
          <span class="party-member__name">{{ entry.name }}</span>
          <span class="party-member__who">
            {{ whoLabel(entry) }}<template v-if="entry.player.hero || entry.player.lordKarak"> · </template><span
              v-if="entry.player.hero || entry.player.lordKarak"
              class="hero-badge"
              :class="{ 'awaiting-reincarnation': entry.player.awaitingReincarnation, cursed: entry.player.cursed, 'lord-karak': entry.player.lordKarak }"
              :title="heroTooltip(entry.player)"
              data-testid="hero-badge"
            ><img
              v-if="entry.player.cursed && !entry.player.awaitingReincarnation"
              src="/images/curse.webp"
              alt="Cursed"
              class="curse-token-icon"
              data-testid="curse-token"
            ><GameIcon
              v-else-if="entry.player.awaitingReincarnation"
              name="skull"
              :size="12"
            />{{ heroRole(entry.player) }}</span><span
              v-if="entry.player.lordKarak && entry.player.hero"
              class="lord-karak-was"
              data-testid="lord-karak-was"
            > · was {{ heroShortName(entry.player) }}</span>
          </span>
          <span class="party-member__stats">
            <span
              class="party-member__hearts"
              :aria-label="`${hp(entry.player)} of ${maxHp(entry.player)} HP`"
              :title="`HP ${hp(entry.player)}/${maxHp(entry.player)}`"
            >
              <GameIcon
                v-for="n in maxHp(entry.player)"
                :key="n"
                name="heart"
                :size="entry.state === 'acting' ? 16 : 13"
                class="hp-heart"
                :class="{ filled: n <= hp(entry.player) }"
              />
              <span class="party-member__hp-number">{{ hp(entry.player) }}</span>
            </span>
            <span
              class="party-member__treasure"
              title="Victory points"
            ><GameIcon
              name="treasure"
              :size="13"
            /> {{ treasure(entry.player) }}</span>
            <span
              v-if="startingRoll(entry.player)"
              class="party-member__roll starting-roll"
              title="Roll for the starting player"
              data-testid="starting-roll"
            ><GameIcon
              name="dice"
              :size="13"
            /> {{ startingRoll(entry.player) }}</span>
          </span>
        </div>
        <div
          v-if="openId === entry.player.id || hoverId === entry.player.id"
          class="party-member__kit"
          role="group"
          :aria-label="`${entry.name}'s kit`"
        >
          <InventorySlots
            :inventory="entry.player.inventory || {}"
            :hero-flags="entry.player.heroFlags || {}"
            size="sm"
          />
        </div>
      </li>
    </ol>
    <slot />
  </section>
</template>

<script setup>
import { ref } from 'vue';
import GameIcon from '@/components/ui/GameIcon.vue';
import InventorySlots from './InventorySlots.vue';

const props = defineProps({
  /** useTurnOrder().entries */
  entries: { type: Array, default: () => [] },
  portrait: { type: Function, required: true },
  heroTooltip: { type: Function, default: () => '' },
  heroShortName: { type: Function, default: () => '' },
  treasure: { type: Function, default: () => 0 },
  /** player => the starting roll to show, or null */
  startingRoll: { type: Function, default: () => null },
  /** player => true for an AI player */
  isAi: { type: Function, default: () => false }
});

const emit = defineEmits(['portrait-error']);

const openId = ref(null);
const hoverId = ref(null);
const toggle = (playerId) => {
  openId.value = openId.value === playerId ? null : playerId;
};

const hp = (player) => (player.hp !== undefined ? player.hp : 5);
const maxHp = (player) => player.maxHp ?? 5;

// "you", "ai" or the player's name, then where they stand in the round
const whoLabel = (entry) => {
  const base = entry.isYou ? 'you' : props.isAi(entry.player) ? 'ai' : (entry.player.username || 'player');
  // Your own turn shows in the glow alone; for the others the canvas spells it out
  if (!entry.isYou && entry.state === 'acting') return `${base} · acting`;
  if (!entry.isYou && entry.state === 'next') return `${base} · next`;
  return base;
};

const heroRole = (player) => (player.lordKarak ? 'Lord Karak' : `the ${props.heroShortName(player).toLowerCase()}`);
</script>

<style scoped>
.party-rail {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.party-rail__caption {
  font-weight: 600;
  font-size: 14px;
  letter-spacing: 0.3em;
  font-variant-caps: all-small-caps;
  color: var(--text-muted);
}

.party-rail__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.party-member {
  position: relative;
  display: flex;
  align-items: center;
  gap: 14px;
  --ring: var(--iron-dark);
  --glow: 0 0 0 0 transparent;
  --size: 56px;
}

.party-member--you {
  --ring: #4f7fae;
}

.party-member--acting {
  --ring: var(--color-secondary);
  --glow: 0 0 26px rgba(var(--ember-rgb), 0.85);
  --size: 84px;
}

.party-member--done {
  opacity: 0.55;
}

.party-member__numeral {
  width: 22px;
  flex: none;
  text-align: right;
  font-family: var(--font-display);
  font-size: 22px;
  color: #6d5b44;
}

.party-member--acting .party-member__numeral {
  color: var(--color-primary);
}

.party-member__medallion {
  width: var(--size);
  height: var(--size);
  flex: none;
  padding: 0;
  border: 0;
  border-radius: 50%;
  overflow: hidden;
  cursor: pointer;
  background: radial-gradient(circle at 50% 35%, #4a3020 0%, #140c07 75%);
  box-shadow: 0 0 0 3px var(--ring), 0 0 0 5px var(--iron-edge), var(--glow);
  transition: width var(--transition-normal), height var(--transition-normal), box-shadow var(--transition-normal);
}

.party-member__medallion:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 6px;
}

.party-member__medallion img {
  display: block;
  width: 100%;
  height: 150%;
  object-fit: cover;
  object-position: top;
}

.party-member__body {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.party-member__name {
  font-family: var(--font-display);
  font-size: 20px;
  line-height: 1;
  color: var(--text-heading);
  text-shadow: 0 2px 4px #000;
}

.party-member--acting .party-member__name {
  font-size: 28px;
}

.party-member__who {
  font-size: 13px;
  letter-spacing: 0.16em;
  font-variant-caps: all-small-caps;
  color: var(--text-muted);
}

.party-member--you .party-member__who {
  color: var(--color-info);
}

.party-member--acting .party-member__who {
  color: var(--text-accent);
}

.hero-badge.cursed {
  color: #c98bd9;
}

.hero-badge.awaiting-reincarnation {
  color: var(--color-danger);
}

.hero-badge.lord-karak {
  color: var(--color-warning);
}

.curse-token-icon {
  width: 14px;
  height: 14px;
  margin-right: 3px;
  vertical-align: -2px;
}

.party-member__stats {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.party-member__hearts {
  display: inline-flex;
  align-items: center;
  gap: 2px;
}

.hp-heart {
  color: var(--color-danger);
  fill: rgba(217, 69, 42, 0.1);
}

.hp-heart.filled {
  fill: var(--color-danger);
}

/* The number shows only where the hearts do not (the mobile strip) */
.party-member__hp-number {
  display: none;
}

.party-member__treasure,
.party-member__roll {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 13px;
  color: #d9c08a;
}

.party-member__roll {
  color: var(--text-secondary);
}

.party-member__kit {
  position: absolute;
  left: calc(36px + var(--size));
  top: calc(100% - 4px);
  z-index: 2;
  padding: 8px;
  background: var(--bg-panel);
  border: 1px solid rgba(234, 220, 192, 0.2);
  box-shadow: 0 10px 24px rgba(0, 0, 0, 0.8);
}

/* Phones: a strip of small medallions; hearts shrink to one heart and a number, a tap opens the kit */
@media (max-width: 768px), (max-height: 500px) {
  .party-rail__caption,
  .party-member__numeral,
  .party-member__name,
  .party-member__who,
  .party-member__treasure,
  .party-member__roll {
    display: none;
  }

  .party-rail__list {
    flex-direction: row;
    flex-wrap: wrap;
    gap: 10px;
  }

  .party-member {
    flex-direction: column;
    gap: 4px;
    --size: 40px;
  }

  .party-member--acting {
    --size: 48px;
  }

  .party-member__hearts .hp-heart:not(:first-child) {
    display: none;
  }

  .party-member__hp-number {
    display: inline;
    margin-left: 2px;
    font-size: 12px;
    color: var(--text-primary);
  }

  .party-member__kit {
    left: 0;
    top: 100%;
    margin-top: 4px;
  }
}
</style>
