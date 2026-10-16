<template>
  <!-- One side of the fight: the figure in its light, a floor shadow, the name and what lies under it -->
  <figure
    class="fighter"
    :class="[`fighter--${side}`, state ? `fighter--${state}` : null]"
  >
    <div class="fighter__stage">
      <div
        class="fighter__glow"
        aria-hidden="true"
      />
      <img
        :src="src"
        :alt="alt || name"
        class="fighter__img combatant-sprite"
        :data-testid="imgTestid || undefined"
        @error="emit('error', $event)"
      >
    </div>
    <div
      class="fighter__floor"
      aria-hidden="true"
    />
    <figcaption class="fighter__caption">
      <span class="fighter__name">{{ name }}</span>
      <slot />
    </figcaption>
  </figure>
</template>

<script setup>
defineProps({
  src: { type: String, required: true },
  alt: { type: String, default: '' },
  name: { type: String, required: true },
  /** hero = left, cold light; foe = right, mirrored, ember light */
  side: { type: String, default: 'hero' },
  /** entrance | idle | victory | defeat | draw (or empty) */
  state: { type: String, default: '' },
  imgTestid: { type: String, default: '' }
});

const emit = defineEmits(['error']);
</script>

<style scoped>
.fighter {
  margin: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  width: var(--fighter-w, 320px);
  --glow-rgb: 234, 220, 192;
}

.fighter--foe {
  --glow-rgb: var(--ember-rgb);
}

.fighter__stage {
  position: relative;
  display: flex;
  justify-content: center;
}

.fighter__glow {
  position: absolute;
  inset: 10% 5% 0;
  border-radius: 50%;
  background: radial-gradient(ellipse, rgba(var(--glow-rgb), 0.28) 0%, rgba(0, 0, 0, 0) 70%);
  animation: glowPulse 3s ease-in-out infinite;
}

.fighter__img {
  position: relative;
  width: var(--fighter-img-w, 280px);
  height: var(--fighter-img-h, 390px);
  object-fit: contain;
  filter: drop-shadow(0 0 30px rgba(var(--glow-rgb), 0.4)) drop-shadow(0 10px 10px #000);
}

.fighter--foe .fighter__img {
  scale: -1 1;
}

.fighter__floor {
  margin-top: -8px;
  width: 75%;
  height: 22px;
  border-radius: 50%;
  background: radial-gradient(ellipse, rgba(0, 0, 0, 0.9) 0%, rgba(0, 0, 0, 0) 70%);
}

.fighter__caption {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  text-align: center;
}

.fighter__name {
  font-family: var(--font-display);
  font-size: var(--fighter-name, 30px);
  line-height: 1.1;
  color: var(--text-heading);
  text-shadow: 0 2px 6px #000;
}

/* Animation states (the timing comes from the fight: entrance → idle while rolling → result) */
.fighter--hero.fighter--entrance .fighter__img { animation: heroSlideIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) both; }
.fighter--foe.fighter--entrance .fighter__img { animation: monsterSlideIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1) both; }
.fighter--idle .fighter__img { animation: characterIdle 1.6s ease-in-out infinite; }
.fighter--hero.fighter--victory .fighter__img { animation: victoryCelebration 0.8s ease-out forwards; }
.fighter--hero.fighter--defeat .fighter__img { animation: defeatRecoil 0.6s ease-out; filter: drop-shadow(0 0 20px rgba(217, 69, 42, 0.45)) drop-shadow(0 10px 10px #000) brightness(0.85); }
.fighter--draw .fighter__img { animation: drawPulse 1.4s ease-in-out infinite; }
.fighter--foe.fighter--victory .fighter__img { animation: monsterTriumph 0.8s ease-out forwards; }
.fighter--foe.fighter--defeat .fighter__img { animation: monsterDefeat 0.8s ease-out forwards; }
.fighter--victory .fighter__glow { --glow-rgb: var(--ember-rgb); }
.fighter--defeat .fighter__glow { opacity: 0.3; animation: none; }

@keyframes glowPulse {
  0%, 100% { opacity: 0.5; transform: scale(1); }
  50% { opacity: 1; transform: scale(1.12); }
}

@keyframes heroSlideIn {
  0% { transform: translateX(-60px) scale(0.7); opacity: 0; }
  60% { transform: translateX(8px) scale(1.05); opacity: 1; }
  100% { transform: translateX(0) scale(1); opacity: 1; }
}

@keyframes monsterSlideIn {
  0% { transform: translateX(60px) scale(0.7); opacity: 0; }
  60% { transform: translateX(-8px) scale(1.05); opacity: 1; }
  100% { transform: translateX(0) scale(1); opacity: 1; }
}

@keyframes characterIdle {
  0%, 100% { transform: translateY(0) scale(1); }
  50% { transform: translateY(-3px) scale(1.02); }
}

@keyframes victoryCelebration {
  0% { transform: scale(1); filter: drop-shadow(0 10px 10px #000) brightness(1); }
  30% { transform: scale(1.12) translateY(-10px); filter: drop-shadow(0 0 40px rgba(var(--ember-rgb), 0.7)) brightness(1.35); }
  60% { transform: scale(1.05) translateY(-5px); filter: drop-shadow(0 0 34px rgba(var(--ember-rgb), 0.55)) brightness(1.2); }
  100% { transform: scale(1.06) translateY(-3px); filter: drop-shadow(0 0 30px rgba(var(--ember-rgb), 0.5)) drop-shadow(0 10px 10px #000) brightness(1.12); }
}

@keyframes defeatRecoil {
  0%, 100% { transform: translateX(0); }
  15% { transform: translateX(-10px) rotate(-5deg); }
  35% { transform: translateX(6px) rotate(3deg); }
  55% { transform: translateX(-3px); }
}

@keyframes drawPulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.82; }
}

@keyframes monsterTriumph {
  0% { transform: scale(1); }
  40% { transform: scale(1.12); }
  100% { transform: scale(1.08); }
}

@keyframes monsterDefeat {
  0% { transform: scale(1); opacity: 1; }
  40% { transform: scale(0.92) rotate(5deg); opacity: 0.75; }
  100% { transform: scale(0.86); opacity: 0.45; filter: drop-shadow(0 10px 10px #000) brightness(0.5) grayscale(0.6); }
}
</style>
