<template>
  <!-- The HUD floats over the full-bleed dungeon ("C · Torchlight"): only its regions take clicks -->
  <div
    class="game-hud"
    :class="{ 'game-hud--with-rail': !!$slots.rail }"
  >
    <div
      v-if="$slots.rail"
      class="game-hud__scrim"
      aria-hidden="true"
    />
    <div class="game-hud__top">
      <slot name="banner" />
      <slot name="playback" />
      <slot name="notice" />
    </div>
    <div
      v-if="$slots.rail"
      class="game-hud__rail"
    >
      <slot name="rail" />
    </div>
    <div
      v-if="$slots.tools"
      class="game-hud__tools"
      :class="{ 'is-open': toolsOpen }"
    >
      <!-- phones: the tools fold behind one ring so they do not cover the field -->
      <button
        type="button"
        class="game-hud__tools-toggle"
        :aria-expanded="toolsOpen"
        :aria-label="t('hud.tools')"
        :title="t('hud.tools')"
        @click="toolsOpen = !toolsOpen"
      >
        <GameIcon
          :name="toolsOpen ? 'close' : 'menu'"
          :size="20"
        />
      </button>
      <div class="game-hud__tools-items">
        <slot name="tools" />
      </div>
    </div>
    <div
      v-if="$slots.chronicle"
      class="game-hud__chronicle"
    >
      <slot name="chronicle" />
    </div>
    <div
      v-if="$slots.plaque"
      class="game-hud__plaque"
    >
      <slot name="plaque" />
    </div>
    <div class="game-hud__bottom">
      <div
        v-if="$slots.pack"
        class="game-hud__pack"
      >
        <slot name="pack" />
      </div>
      <div
        v-if="$slots.rings"
        class="game-hud__rings"
      >
        <slot name="rings" />
      </div>
    </div>
    <slot />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import GameIcon from '@/components/ui/GameIcon.vue';

const { t } = useI18n();
const toolsOpen = ref(false);
</script>

<style scoped>
.game-hud {
  position: fixed;
  inset: 0;
  z-index: var(--z-hud);
  pointer-events: none;
  --hud-left: 0px;
  --hud-gutter: 32px;
}

/* The fight scene covers the field: the HUD fades so its texts do not show through the title */
.game-hud--under-fight {
  opacity: 0.08;
  transition: opacity var(--transition-normal);
}

.game-hud--with-rail {
  --hud-left: var(--rail-width);
}

.game-hud__tools,
.game-hud__chronicle,
.game-hud__plaque,
.game-hud__pack,
.game-hud__rings {
  pointer-events: auto;
}

/* The top column (the turn banner, the playback bar, the notices) spans the width over the field: only its
   controls take taps, the rest lets them through to the tiles under it (building upwards on a phone) */
.game-hud__top > :slotted(*) {
  pointer-events: none;
}

.game-hud__top :deep(button),
.game-hud__top :deep(a),
.game-hud__top :deep(input),
.game-hud__top :deep(select),
.game-hud__top :deep([role="button"]) {
  pointer-events: auto;
}

/* The rail: its heroes take taps, the space between them does not (on a phone it is a strip over the field) */
.game-hud__rail {
  pointer-events: none;
}

.game-hud__scrim {
  position: absolute;
  inset: 0 auto 0 0;
  width: var(--rail-width);
  background: linear-gradient(90deg, rgba(7, 5, 4, 0.92) 0%, rgba(7, 5, 4, 0.75) 70%, rgba(7, 5, 4, 0) 100%);
}

.game-hud__top {
  position: absolute;
  top: 22px;
  /* as wide as the rail on both sides: the banner stands at the middle of the screen, clear of the rail */
  left: var(--hud-left);
  right: var(--hud-left);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

.game-hud__rail {
  position: absolute;
  /* the padding is room for the acting medallion's ring and glow, which the scroll box would clip */
  top: 12px;
  left: 0;
  width: var(--rail-width);
  max-height: calc(100dvh - 162px);
  box-sizing: border-box;
  padding: 28px 12px 36px 28px;
  overflow-y: auto;
  scrollbar-width: none;
}

.game-hud__tools {
  position: absolute;
  top: 22px;
  right: var(--hud-gutter);
  display: flex;
  gap: 10px;
}

.game-hud__tools-items {
  display: flex;
  gap: 10px;
}

.game-hud__tools-toggle {
  display: none;
}

.game-hud__chronicle {
  position: absolute;
  top: 120px;
  right: var(--hud-gutter);
  width: 300px;
}

.game-hud__plaque {
  position: absolute;
  right: var(--hud-gutter);
  bottom: 140px;
  width: 340px;
  max-height: calc(100dvh - 400px);
  overflow-y: auto;
}

.game-hud__bottom {
  position: absolute;
  left: 28px;
  right: var(--hud-gutter);
  bottom: 28px;
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 24px;
}

.game-hud__pack {
  width: calc(var(--rail-width) - 40px);
}

.game-hud__rings {
  margin-left: auto;
}

/* Phones (and short landscape screens): banner on top, a strip of medallions under it, tools down the right edge,
   a bottom bar of pack and rings; the chronicle hides behind the Journal ring */
@media (max-width: 768px), (max-height: 500px) {
  .game-hud {
    --hud-left: 0px;
    --hud-gutter: 8px;
  }

  .game-hud__scrim,
  .game-hud__chronicle {
    display: none;
  }

  .game-hud__top {
    top: 6px;
    left: 8px;
    right: 8px;
    gap: 6px;
  }

  .game-hud__rail {
    top: 62px;
    left: 8px;
    right: 56px;
    width: auto;
    max-height: none;
    padding: 0;
    overflow: visible;
  }

  .game-hud__tools {
    top: 62px;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
  }

  .game-hud__tools-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    padding: 0;
    border-radius: 50%;
    border: 0;
    background: radial-gradient(circle at 40% 35%, #4a4643 0%, #1c1a19 70%);
    box-shadow: 0 0 0 2px var(--iron), 0 0 0 4px var(--iron-edge);
    color: var(--text-primary);
  }

  .game-hud__tools-toggle:hover:not(:disabled) {
    background: radial-gradient(circle at 40% 35%, #4a4643 0%, #1c1a19 70%);
  }

  .game-hud__tools-items {
    display: none;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
  }

  .game-hud__tools.is-open .game-hud__tools-items {
    display: flex;
  }

  /* the AI playback bar sits under the banner: the medallions move down below it */
  .game-hud:has(.game-hud__top .ai-playback) .game-hud__rail {
    top: 108px;
  }

  /* a transient notice goes under the medallions, across the screen, over the field */
  .game-hud__top :slotted(.action-notice) {
    position: fixed;
    top: 134px;
    left: 8px;
    right: 8px;
    max-width: none;
    z-index: 5;
    text-align: center;
  }

  .game-hud__tools :slotted(.zoom-controls) {
    flex-direction: column;
    gap: 8px;
  }

  .game-hud__plaque {
    left: 8px;
    right: 8px;
    bottom: calc(var(--hud-bar-height) + 8px);
    width: auto;
    max-height: 45dvh;
  }

  .game-hud__bottom {
    left: 0;
    right: 0;
    bottom: 0;
    min-height: var(--hud-bar-height);
    padding: 8px 8px max(8px, env(safe-area-inset-bottom));
    gap: 10px;
    align-items: flex-end;
    background: linear-gradient(0deg, rgba(7, 5, 4, 0.95) 0%, rgba(7, 5, 4, 0.85) 70%, rgba(7, 5, 4, 0) 100%);
    pointer-events: auto;
  }

  .game-hud__pack {
    flex: 1;
    min-width: 0;
    width: auto;
    overflow-x: auto;
    scrollbar-width: none;
  }
}
</style>
