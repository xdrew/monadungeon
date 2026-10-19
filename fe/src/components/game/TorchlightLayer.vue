<template>
  <div
    v-if="active && lights.length"
    class="torchlight"
    :style="frameStyle"
    aria-hidden="true"
  >
    <!-- darkness with a hole of light around every hero: a small picture stretched over the field
         (a field-sized CSS mask was re-rasterized at full resolution on every step, slow on phones) -->
    <div
      class="torchlight-dark"
      :style="darkStyle"
    />
    <!-- the acting hero's torch: a warm halo -->
    <div
      v-if="haloStyle"
      class="torchlight-halo"
      :style="haloStyle"
    />
  </div>
</template>

<script setup>
/**
 * Torchlight over the field: the dungeon outside the heroes' torches is dimmed,
 * every hero carries a small light, the acting hero the widest one plus a warm halo.
 * Purely visual: pointer-events none, sits under the move marks (z-index 20).
 * Coordinates: `lights` are field positions; the layer covers the field plus one tile
 * on every side.
 */
import { computed } from 'vue';

const props = defineProps({
  /** [{ x, y, acting }] in field coordinates */
  lights: { type: Array, default: () => [] },
  minX: { type: Number, default: 0 },
  minY: { type: Number, default: 0 },
  cols: { type: Number, default: 1 },
  rows: { type: Number, default: 1 },
  tileSize: { type: Number, required: true },
  active: { type: Boolean, default: true }
});

// Light radii in tiles: [inner (fully lit), outer (fully dark)]
const ACTING_RADIUS = [1.0, 2.8];
const OTHER_RADIUS = [0.35, 1.4];
const HALO_RADIUS = 1.9;
// Canvas pixels per tile: the darkness is soft gradients only, so a small canvas scales up without visible steps
const CANVAS_PX_PER_TILE = 24;
const DARKNESS = 'rgba(7, 5, 4, 0.7)';

const frameStyle = computed(() => ({
  left: `${-props.tileSize}px`,
  top: `${-props.tileSize}px`,
  width: `${(props.cols + 2) * props.tileSize}px`,
  height: `${(props.rows + 2) * props.tileSize}px`
}));

/** Centre of a field position in the layer's own pixels (the layer starts one tile out) */
const centreOf = (light) => ({
  cx: (light.x - props.minX + 1.5) * props.tileSize,
  cy: (light.y - props.minY + 1.5) * props.tileSize
});

// Every light cuts its hole out of the darkness (destination-out): overlapping lights multiply, as mask-composite: intersect did
// Drawn on a canvas off the page: a canvas in the page is a layer of its own and lifts everything drawn over it into layers
const darkStyle = computed(() => {
  const width = (props.cols + 2) * CANVAS_PX_PER_TILE;
  const height = (props.rows + 2) * CANVAS_PX_PER_TILE;
  const canvas = Object.assign(document.createElement('canvas'), { width, height });
  const ctx = canvas.getContext('2d');
  if (!ctx) return { background: DARKNESS };
  const scale = CANVAS_PX_PER_TILE / props.tileSize;
  ctx.fillStyle = DARKNESS;
  ctx.fillRect(0, 0, width, height);
  ctx.globalCompositeOperation = 'destination-out';
  for (const light of props.lights) {
    const { cx, cy } = centreOf(light);
    const [inner, outer] = light.acting ? ACTING_RADIUS : OTHER_RADIUS;
    const x = cx * scale;
    const y = cy * scale;
    const r = outer * CANVAS_PX_PER_TILE;
    const hole = ctx.createRadialGradient(x, y, 0, x, y, r);
    hole.addColorStop(0, '#000');
    hole.addColorStop(inner / outer, '#000');
    hole.addColorStop(1, 'rgba(0, 0, 0, 0)');
    ctx.fillStyle = hole;
    ctx.fillRect(x - r, y - r, 2 * r, 2 * r);
  }
  return { backgroundImage: `url(${canvas.toDataURL()})` };
});

const haloStyle = computed(() => {
  const acting = props.lights.find((light) => light.acting);
  if (!acting) return null;
  const { cx, cy } = centreOf(acting);
  const r = HALO_RADIUS * props.tileSize;
  // only as big as the glow itself: a blended layer the size of the field was recomposited every frame
  return {
    left: `${cx - r}px`,
    top: `${cy - r}px`,
    width: `${2 * r}px`,
    height: `${2 * r}px`,
    background: 'radial-gradient(circle closest-side, rgba(255, 140, 40, 0.2) 0%, rgba(255, 140, 40, 0) 100%)'
  };
});
</script>

<style scoped>
.torchlight {
  position: absolute;
  z-index: 20;
  pointer-events: none;
}

.torchlight-dark,
.torchlight-halo {
  position: absolute;
  pointer-events: none;
}

/* Atmospheric: far tiles dimmed to ~70%, monsters and chests stay readable */
.torchlight-dark {
  inset: 0;
  background-size: 100% 100%;
}

.torchlight-halo {
  mix-blend-mode: screen;
  /* a living flame: an uneven flicker of the acting hero's halo */
  animation: torchFlicker 3.2s linear infinite;
}

@keyframes torchFlicker {
  0% { opacity: 0.92; }
  12% { opacity: 1; }
  20% { opacity: 0.86; }
  34% { opacity: 0.97; }
  47% { opacity: 0.9; }
  58% { opacity: 1; }
  71% { opacity: 0.84; }
  83% { opacity: 0.95; }
  100% { opacity: 0.92; }
}

/* A still flame on phones and with reduced motion: every running animation recomposites the whole field */
@media (prefers-reduced-motion: reduce), (pointer: coarse) {
  .torchlight-halo {
    animation: none;
  }
}
</style>
