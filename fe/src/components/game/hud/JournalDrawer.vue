<template>
  <!-- The Journal: every turn of the chronicle, in a side sheet (a bottom sheet on phones) -->
  <transition name="journal">
    <aside
      v-if="open"
      ref="sheet"
      class="journal-drawer"
      role="dialog"
      aria-modal="false"
      aria-labelledby="journal-title"
      tabindex="-1"
      @keydown.esc="emit('close')"
    >
      <header class="journal-drawer__head">
        <h2
          id="journal-title"
          class="journal-drawer__title"
        >
          Journal
        </h2>
        <button
          type="button"
          class="btn btn--iron btn--sm"
          aria-label="Close the journal"
          title="Close"
          @click="emit('close')"
        >
          <GameIcon
            name="close"
            :size="18"
          />
        </button>
      </header>
      <ol
        ref="list"
        class="journal-drawer__turns"
      >
        <li
          v-for="turn in turns"
          :key="turn.key"
          class="journal-turn"
        >
          <span class="journal-turn__head">Turn {{ turn.turnNumber }} · {{ turn.name }}</span>
          <p
            v-for="(line, i) in turn.lines"
            :key="i"
            class="journal-turn__line"
          >
            {{ line }}
          </p>
          <p
            v-if="!turn.lines.length"
            class="journal-turn__line journal-turn__line--quiet"
          >
            Nothing stirs.
          </p>
        </li>
        <li
          v-if="!turns.length"
          class="journal-turn__line journal-turn__line--quiet"
        >
          The journal is still empty.
        </li>
      </ol>
    </aside>
  </transition>
</template>

<script setup>
import { nextTick, ref, watch } from 'vue';
import GameIcon from '@/components/ui/GameIcon.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  /** chronicleTurns(): oldest first */
  turns: { type: Array, default: () => [] }
});

const emit = defineEmits(['close']);

const sheet = ref(null);
const list = ref(null);

// Opened: focus the sheet (Esc closes it) and show the newest turn
watch(() => props.open, async (open) => {
  if (!open) return;
  await nextTick();
  sheet.value?.focus();
  if (list.value) list.value.scrollTop = list.value.scrollHeight;
});
</script>

<style scoped>
.journal-drawer {
  position: fixed;
  top: 0;
  right: 0;
  bottom: 0;
  z-index: calc(var(--z-hud) + 1);
  width: min(380px, 100vw);
  display: flex;
  flex-direction: column;
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.97) 0%, rgba(12, 7, 5, 0.97) 100%);
  border-left: 1px solid var(--iron-dark);
  box-shadow: -12px 0 30px rgba(0, 0, 0, 0.7);
  outline: none;
}

.journal-drawer__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 20px 12px;
  border-bottom: 1px solid rgba(var(--ember-rgb), 0.3);
}

.journal-drawer__title {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 30px;
  color: var(--text-heading);
}

.journal-drawer__turns {
  flex: 1;
  overflow-y: auto;
  list-style: none;
  margin: 0;
  padding: 12px 20px 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.journal-turn__head {
  display: block;
  margin-bottom: 4px;
  font-weight: 600;
  font-size: 14px;
  letter-spacing: 0.2em;
  font-variant-caps: all-small-caps;
  color: var(--text-accent);
}

.journal-turn__line {
  margin: 0 0 2px;
  font-style: italic;
  font-size: 15px;
  line-height: 1.4;
  color: var(--text-primary);
}

.journal-turn__line--quiet {
  color: var(--text-muted);
}

.journal-enter-active,
.journal-leave-active {
  transition: transform var(--transition-normal), opacity var(--transition-normal);
}

.journal-enter-from,
.journal-leave-to {
  transform: translateX(24px);
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .journal-enter-active,
  .journal-leave-active {
    transition: none;
  }
}
</style>
