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
    >
      <slot name="tools" />
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

<style scoped>
.game-hud {
  position: fixed;
  inset: 0;
  z-index: var(--z-hud);
  pointer-events: none;
  --hud-left: 0px;
  --hud-gutter: 32px;
}

.game-hud--with-rail {
  --hud-left: var(--rail-width);
}

.game-hud__rail,
.game-hud__tools,
.game-hud__chronicle,
.game-hud__plaque,
.game-hud__pack,
.game-hud__rings {
  pointer-events: auto;
}

/* The top column spans the width: only what it holds takes clicks */
.game-hud__top > :slotted(*) {
  pointer-events: auto;
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
  left: var(--hud-left);
  right: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

.game-hud__rail {
  position: absolute;
  top: 40px;
  left: 28px;
  width: calc(var(--rail-width) - 40px);
  max-height: calc(100dvh - 190px);
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
</style>
