<template>
  <div
    v-if="active && lights.length"
    class="torchlight"
    :style="frameStyle"
    aria-hidden="true"
  >
    <!-- darkness with a hole of light around every hero -->
    <div class="torchlight-dark" :style="darkStyle"></div>
    <!-- the acting hero's torch: a warm halo -->
    <div v-if="haloStyle" class="torchlight-halo" :style="haloStyle"></div>
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

const darkStyle = computed(() => {
  const holes = props.lights.map((light) => {
    const { cx, cy } = centreOf(light);
    const [inner, outer] = light.acting ? ACTING_RADIUS : OTHER_RADIUS;
    return `radial-gradient(circle at ${cx}px ${cy}px, rgba(0, 0, 0, 0) ${inner * props.tileSize}px, #000 ${outer * props.tileSize}px)`;
  });
  const mask = holes.join(', ');
  return { maskImage: mask, WebkitMaskImage: mask };
});

const haloStyle = computed(() => {
  const acting = props.lights.find((light) => light.acting);
  if (!acting) return null;
  const { cx, cy } = centreOf(acting);
  return {
    background: `radial-gradient(circle at ${cx}px ${cy}px, rgba(255, 140, 40, 0.2) 0%, rgba(255, 140, 40, 0) ${HALO_RADIUS * props.tileSize}px)`
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
  inset: 0;
  pointer-events: none;
}

/* Atmospheric: far tiles dimmed to ~70%, monsters and chests stay readable */
.torchlight-dark {
  background: rgba(7, 5, 4, 0.7);
  -webkit-mask-composite: source-in;
  mask-composite: intersect;
  transition: opacity 0.3s ease;
}

.torchlight-halo {
  mix-blend-mode: screen;
}

/* Without mask compositing several holes would cancel each other out: fall back to an edge vignette */
@supports not ((mask-composite: intersect) or (-webkit-mask-composite: source-in)) {
  .torchlight-dark {
    -webkit-mask-image: none !important;
    mask-image: none !important;
    background: radial-gradient(ellipse at 50% 50%, rgba(7, 5, 4, 0) 40%, rgba(7, 5, 4, 0.7) 100%);
  }
}

@media (prefers-reduced-motion: reduce) {
  .torchlight-dark {
    transition: none;
  }
}
</style>
