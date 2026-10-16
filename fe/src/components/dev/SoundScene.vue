<template>
  <!-- The sound bench's stage: the game's own pictures, animated as the sound plays (restarted per play) -->
  <div
    :key="run"
    class="scene"
    :class="{ 'scene--on': run > 0, 'scene--loop': loop }"
    :style="{ '--dur': `${Math.max(0.35, duration)}s` }"
  >
    <template
      v-for="(layer, i) in layers"
      :key="i"
    >
      <img
        v-if="layer.k === 'img'"
        :src="layer.src"
        alt=""
        class="layer layer--img"
        :class="layer.cls"
      >
      <span
        v-else-if="layer.k === 'text'"
        class="layer layer--text"
        :class="layer.cls"
      >{{ layer.text }}</span>
      <GameIcon
        v-else-if="layer.k === 'icon'"
        :name="layer.name"
        :size="44"
        class="layer layer--icon"
        :class="layer.cls"
      />
      <div
        v-else-if="layer.k === 'dice'"
        class="layer layer--dice"
      >
        <DieFace
          :value="6"
          :rolling="rolling"
          :landed="run > 0 && !rolling"
          :tilt="-8"
        />
        <DieFace
          :value="4"
          :rolling="rolling"
          :landed="run > 0 && !rolling"
          :tilt="11"
          :delay="0.07"
        />
      </div>
      <span
        v-else-if="layer.k === 'tick'"
        class="layer layer--text tick a-tick"
      >{{ tick }}</span>
      <span
        v-else
        class="layer"
        :class="[`layer--${layer.k}`, layer.cls]"
      >
        <template v-if="layer.k === 'btn'">{{ t('devSounds.scene.button') }}</template>
        <template v-else-if="layer.k === 'strip'">{{ t('devSounds.scene.notice') }}</template>
        <template v-else-if="layer.k === 'seat'">{{ t('devSounds.scene.seat') }}</template>
        <template v-else-if="layer.k === 'token'">{{ t('devSounds.scene.endTurn') }}</template>
      </span>
    </template>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import GameIcon from '@/components/ui/GameIcon.vue';
import DieFace from '@/components/game/fight/DieFace.vue';

const props = defineProps({
  /** a cue, a track or a sting id */
  scene: { type: String, required: true },
  /** bumped on each play: the animation starts over */
  run: { type: Number, default: 0 },
  /** the sound's length, seconds: the animation lasts as long */
  duration: { type: Number, default: 1 },
  /** a music track: the scene keeps moving while it plays */
  loop: { type: Boolean, default: false },
});

const { t } = useI18n();
const IMG = '/images/';
const M = '/themes/base/monsters/';
const H = '/themes/base/heroes/';
const hero = { k: 'img', src: `${H}warrior.webp`, cls: 'fig' };

const SCENES = {
  ui_click: [{ k: 'btn', cls: 'a-press' }],
  ui_toggle: [{ k: 'check', cls: 'a-check' }],
  ui_open: [{ k: 'plaque', cls: 'a-unfold' }],
  ui_close: [{ k: 'plaque', cls: 'a-fold' }],
  ui_refused: [{ k: 'btn', cls: 'a-refuse' }],
  notice: [{ k: 'strip', cls: 'a-slide-in' }],
  tile_draw: [{ k: 'tile', cls: 'tile-under' }, { k: 'tile', cls: 'a-draw' }],
  tile_place: [{ k: 'grid' }, { k: 'tile', cls: 'a-drop' }],
  tile_rotate: [{ k: 'tile', cls: 'a-rotate' }],
  step: [{ k: 'grid' }, { ...hero, cls: 'fig a-hop' }],
  teleport: [{ k: 'img', src: `${IMG}portal.webp`, cls: 'bg' }, { ...hero, cls: 'fig a-swirl' }],
  fountain_heal: [{ k: 'img', src: `${IMG}hf.webp`, cls: 'bg' }, { ...hero, cls: 'fig a-heal' }, { k: 'text', text: '+1', cls: 'heal-text a-float' }],
  chest_open: [{ k: 'img', src: `${IMG}chest-closed.webp`, cls: 'a-out' }, { k: 'img', src: `${IMG}chest-opened.webp`, cls: 'a-in' }],
  chest_locked: [{ k: 'img', src: `${IMG}chest-closed.webp`, cls: 'a-rattle' }],
  item_pickup: [{ k: 'img', src: `${IMG}sword.webp`, cls: 'item a-rise' }],
  treasure: [{ k: 'img', src: `${IMG}chest-opened.webp` }, { k: 'icon', name: 'treasure', cls: 'coin a-coins' }],
  key: [{ k: 'img', src: `${IMG}key.webp`, cls: 'item a-rise' }],
  item_drop: [{ k: 'img', src: `${IMG}sword.webp`, cls: 'item a-fall' }],
  dice_roll: [{ k: 'dice' }],
  hit_win: [{ k: 'img', src: `${M}giant_rat.webp`, cls: 'a-slain' }, { k: 'slash', cls: 'a-slash' }],
  hit_lose: [{ ...hero, cls: 'fig a-hurt' }],
  draw: [{ k: 'img', src: `${IMG}sword.webp`, cls: 'item a-clash-l' }, { k: 'img', src: `${IMG}sword.webp`, cls: 'item a-clash-r' }],
  monster_roar: [{ k: 'img', src: `${M}skeleton_warrior.webp`, cls: 'a-roar' }],
  dragon_roar: [{ k: 'img', src: `${M}dragon.webp`, cls: 'big a-roar' }],
  stunned: [{ ...hero, cls: 'fig a-stun' }],
  reincarnate: [{ ...hero, cls: 'fig a-revive' }],
  spell_fireball: [{ k: 'img', src: `${M}mummy.webp`, cls: 'fig-right' }, { k: 'img', src: `${IMG}fireball.webp`, cls: 'item a-fly' }],
  spell_teleport: [{ k: 'img', src: `${IMG}hf-teleport.webp`, cls: 'a-flash' }],
  dark_thorn: [{ k: 'img', src: `${IMG}dark-thorn.webp`, cls: 'item a-thrust' }],
  frost_fist: [{ k: 'img', src: `${IMG}frost-fist.webp`, cls: 'item a-frost' }],
  curse: [{ k: 'img', src: `${H}thief.webp`, cls: 'fig a-cursed' }, { k: 'img', src: `${IMG}curse.webp`, cls: 'mark a-curse' }],
  curse_lifted: [{ k: 'img', src: `${H}thief.webp`, cls: 'fig a-uncursed' }, { k: 'img', src: `${IMG}curse.webp`, cls: 'mark a-uncurse' }],
  sneak: [{ k: 'img', src: `${M}giant_spider.webp`, cls: 'fig-right' }, { k: 'img', src: `${H}thief.webp`, cls: 'fig a-sneak' }],
  end_turn: [{ k: 'token', cls: 'a-thud' }],
  your_turn: [{ k: 'text', text: '', cls: 'banner a-banner' }],
  turn_tick: [{ k: 'tick' }],
  table_join: [{ k: 'seat', cls: 'a-slide-in' }],
  game_start: [{ k: 'img', src: `${IMG}portal.webp`, cls: 'a-open-door' }],
  duel_start: [{ k: 'img', src: `${IMG}arena.webp`, cls: 'bg' }, { k: 'img', src: `${IMG}sword.webp`, cls: 'item a-clash-l' }, { k: 'img', src: `${IMG}sword.webp`, cls: 'item a-clash-r' }],
  // music by situation, and the stings
  lobby: [{ k: 'img', src: '/themes/base/lobby-bg.webp', cls: 'cover a-pan' }],
  explore: [{ k: 'grid' }, { k: 'torch', cls: 'a-flicker' }, hero],
  fight: [{ ...hero, cls: 'fig-left a-sway' }, { k: 'img', src: `${M}skeleton_king.webp`, cls: 'fig-right a-sway' }],
  dragon: [{ ...hero, cls: 'fig-left a-sway' }, { k: 'img', src: `${M}dragon.webp`, cls: 'fig-right big a-sway' }],
  victory: [{ k: 'text', text: '', cls: 'title title--win a-title' }],
  defeat: [{ k: 'text', text: '', cls: 'title title--lose a-title' }],
  game_over: [{ k: 'icon', name: 'trophy', cls: 'a-title' }],
  lord_karak: [{ k: 'img', src: `${H}lord_karak.webp`, cls: 'fig a-rise-dark' }],
};
const TEXTS = { your_turn: 'yourTurn', victory: 'victory', defeat: 'defeat' };

const layers = computed(() => (SCENES[props.scene] || [{ k: 'icon', name: 'sound-on', cls: 'a-press' }])
  .map((layer) => (TEXTS[props.scene] && layer.k === 'text' ? { ...layer, text: t(`devSounds.scene.${TEXTS[props.scene]}`) } : layer)));

// the dice tumble for most of the sound, then lie; the countdown ticks down once per play
const rolling = ref(false);
const tick = ref(10);
let timer = null;
watch(() => props.run, () => {
  clearTimeout(timer);
  rolling.value = true;
  timer = setTimeout(() => { rolling.value = false; }, Math.max(300, props.duration * 700));
  tick.value = tick.value > 1 ? tick.value - 1 : 10;
});
onBeforeUnmount(() => clearTimeout(timer));
</script>

<style scoped>
.scene {
  position: relative;
  height: 130px;
  overflow: hidden;
  background:
    radial-gradient(ellipse at 50% 70%, rgba(var(--ember-rgb), 0.14) 0%, rgba(0, 0, 0, 0) 60%),
    linear-gradient(180deg, #120b07 0%, #070504 100%);
  border: 1px solid var(--iron-dark);
}

.layer {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
}

.layer--img {
  height: 96px;
  width: auto;
  object-fit: contain;
}

.layer--img.bg,
.layer--img.cover {
  inset: 0;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  transform: none;
  opacity: 0.45;
}

.layer--img.cover {
  opacity: 0.8;
}

.layer--img.item {
  height: 64px;
}

.layer--img.big {
  height: 120px;
}

.layer--img.mark {
  height: 44px;
  left: 68%;
  top: 30%;
}

.fig-left {
  left: 30%;
}

.fig-right {
  left: 72%;
}

.layer--text {
  font-family: var(--font-display);
  font-size: 30px;
  color: var(--text-heading);
  white-space: nowrap;
}

.title--win {
  color: var(--text-accent);
  text-shadow: 0 0 24px rgba(var(--ember-rgb), 0.8);
}

.title--lose {
  color: #e0705a;
  text-shadow: 0 0 24px rgba(217, 69, 42, 0.8);
}

.banner {
  padding: 6px 18px;
  border-top: 1px solid rgba(var(--ember-rgb), 0.6);
  border-bottom: 1px solid rgba(var(--ember-rgb), 0.6);
  font-size: 26px;
}

.heal-text {
  left: 64%;
  top: 30%;
  font-size: 26px;
  color: #8fd18a;
}

.tick {
  font-family: var(--font-body);
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  font-size: 44px;
  color: #e0705a;
}

.layer--icon {
  color: var(--text-accent);
}

.coin {
  top: 30%;
}

.layer--dice {
  display: flex;
  gap: 18px;
}

.layer--btn {
  padding: 8px 22px;
  border: 1px solid rgba(var(--ember-rgb), 0.7);
  background: linear-gradient(180deg, #5a2f14 0%, #3a1d0c 100%);
  font-family: var(--font-display);
  font-size: 20px;
  color: var(--text-heading);
}

.layer--check {
  width: 30px;
  height: 30px;
  border: 2px solid var(--iron-edge, #8a7a66);
  background: #1a110b;
}

.layer--check.a-check::after {
  content: '';
  position: absolute;
  inset: 4px;
  background: var(--color-primary);
  animation: checkOn calc(var(--dur) * 0.6) ease-out both;
}

.layer--plaque,
.layer--strip,
.layer--seat {
  width: 70%;
  height: 64px;
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  background: rgba(20, 12, 8, 0.95);
  box-shadow: 0 0 20px rgba(255, 100, 20, 0.25);
}

.layer--strip,
.layer--seat {
  height: auto;
  padding: 8px 12px;
  font-size: 14px;
  color: var(--text-primary);
  text-align: center;
}

.layer--tile,
.layer--grid {
  width: 72px;
  height: 72px;
  background:
    linear-gradient(90deg, rgba(0, 0, 0, 0) 38%, #3b2a1d 38%, #3b2a1d 62%, rgba(0, 0, 0, 0) 62%),
    linear-gradient(0deg, rgba(0, 0, 0, 0) 38%, #3b2a1d 38%, #3b2a1d 62%, rgba(0, 0, 0, 0) 62%),
    #1b130d;
  border: 1px solid #4a3828;
  box-shadow: 0 6px 12px rgba(0, 0, 0, 0.7);
}

.layer--tile.tile-under {
  transform: translate(-46%, -46%);
  filter: brightness(0.6);
}

.layer--grid {
  width: 100%;
  height: 100%;
  left: 0;
  top: 0;
  transform: none;
  background-size: 40px 40px;
  background-image:
    linear-gradient(90deg, rgba(234, 220, 192, 0.06) 1px, rgba(0, 0, 0, 0) 1px),
    linear-gradient(0deg, rgba(234, 220, 192, 0.06) 1px, rgba(0, 0, 0, 0) 1px);
  border: 0;
  box-shadow: none;
}

.layer--slash {
  width: 120px;
  height: 4px;
  background: linear-gradient(90deg, rgba(255, 255, 255, 0) 0%, #fff 50%, rgba(255, 255, 255, 0) 100%);
  box-shadow: 0 0 12px #fff;
  opacity: 0;
}

.layer--token {
  width: 70px;
  height: 70px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  border: 2px solid var(--iron-edge, #8a7a66);
  background: radial-gradient(circle at 35% 30%, #4a3a2c 0%, #1a110b 70%);
  font-size: 11px;
  text-align: center;
  color: var(--text-primary);
}

.layer--torch {
  left: 20%;
  top: 30%;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(255, 170, 60, 0.9) 0%, rgba(255, 120, 20, 0.3) 45%, rgba(0, 0, 0, 0) 70%);
}

/* ===== The animations: they run once per play (a new key restarts them), for the sound's length ===== */
.scene:not(.scene--on) [class*='a-'] {
  animation: none !important;
}

.scene--loop [class*='a-'] {
  animation-iteration-count: infinite !important;
}

.a-press { animation: press var(--dur) ease-out both; }
.a-refuse { animation: refuse var(--dur) ease-out both; }
.a-unfold { animation: unfold var(--dur) ease-out both; }
.a-fold { animation: unfold var(--dur) ease-in reverse both; }
.a-slide-in { animation: slideIn var(--dur) ease-out both; }
.a-draw { animation: draw var(--dur) ease-out both; }
.a-drop { animation: drop var(--dur) cubic-bezier(0.3, 0, 0.5, 1) both; }
.a-rotate { animation: rotate var(--dur) ease-in-out both; }
.a-hop { animation: hop var(--dur) ease-in-out both; }
.a-swirl { animation: swirl var(--dur) ease-in-out both; }
.a-heal { animation: heal var(--dur) ease-out both; }
.a-float { animation: float var(--dur) ease-out both; }
.a-out { animation: fadeOut var(--dur) ease-out both; }
.a-in { animation: fadeIn var(--dur) ease-out both; }
.a-rattle { animation: rattle var(--dur) linear both; }
.a-rise { animation: rise var(--dur) ease-out both; }
.a-coins { animation: coins var(--dur) ease-out both; }
.a-fall { animation: fall var(--dur) cubic-bezier(0.5, 0, 0.7, 1) both; }
.a-slain { animation: slain var(--dur) ease-in both; }
.a-slash { animation: slash calc(var(--dur) * 0.4) ease-out both; }
.a-hurt { animation: hurt var(--dur) ease-out both; }
.a-clash-l { animation: clashL var(--dur) ease-out both; }
.a-clash-r { animation: clashR var(--dur) ease-out both; }
.a-roar { animation: roar var(--dur) ease-out both; }
.a-stun { animation: stun var(--dur) ease-out both; }
.a-revive { animation: revive var(--dur) ease-out both; }
.a-fly { animation: fly var(--dur) ease-in both; }
.a-flash { animation: flash var(--dur) ease-out both; }
.a-thrust { animation: thrust var(--dur) ease-out both; }
.a-frost { animation: frost var(--dur) ease-out both; }
.a-cursed { animation: cursed var(--dur) ease-out both; }
.a-uncursed { animation: cursed var(--dur) ease-out reverse both; }
.a-curse { animation: curseMark var(--dur) ease-out both; }
.a-uncurse { animation: curseMark var(--dur) ease-in reverse both; }
.a-sneak { animation: sneak var(--dur) ease-in-out both; }
.a-thud { animation: thud var(--dur) cubic-bezier(0.4, 0, 0.6, 1) both; }
.a-banner { animation: banner var(--dur) ease-out both; }
.a-tick { animation: tickPulse var(--dur) ease-out both; }
.a-open-door { animation: openDoor var(--dur) ease-out both; }
.a-pan { animation: pan 18s ease-in-out alternate; }
.a-flicker { animation: flicker 1.4s ease-in-out; }
.a-sway { animation: sway 2.4s ease-in-out alternate; }
.a-title { animation: titleIn var(--dur) ease-out both; }
.a-rise-dark { animation: riseDark var(--dur) ease-out both; }

@keyframes press { 0% { transform: translate(-50%, -50%) scale(1); } 15% { transform: translate(-50%, -48%) scale(0.92); filter: brightness(1.4); } 100% { transform: translate(-50%, -50%) scale(1); } }
@keyframes refuse { 0%, 100% { transform: translate(-50%, -50%); } 15%, 45% { transform: translate(-54%, -50%); border-color: #d9452a; } 30%, 60% { transform: translate(-46%, -50%); } }
@keyframes checkOn { from { transform: scale(0); } 60% { transform: scale(1.2); } to { transform: scale(1); } }
@keyframes unfold { from { transform: translate(-50%, -50%) scaleY(0.05); opacity: 0; } to { transform: translate(-50%, -50%) scaleY(1); opacity: 1; } }
@keyframes slideIn { from { transform: translate(40%, -50%); opacity: 0; } to { transform: translate(-50%, -50%); opacity: 1; } }
@keyframes draw { from { transform: translate(-46%, -46%); } to { transform: translate(-50%, -50%) translateX(60px) rotate(6deg); } }
@keyframes drop { 0% { transform: translate(-50%, -160%) scale(1.15); opacity: 0.4; } 70% { transform: translate(-50%, -50%) scale(1); opacity: 1; } 80% { transform: translate(-50%, -50%) scale(0.97); } 100% { transform: translate(-50%, -50%) scale(1); } }
@keyframes rotate { from { transform: translate(-50%, -50%) rotate(0); } to { transform: translate(-50%, -50%) rotate(90deg); } }
@keyframes hop { 0% { transform: translate(-130%, -50%); } 50% { transform: translate(-90%, -62%); } 100% { transform: translate(-50%, -50%); } }
@keyframes swirl { 0% { transform: translate(-50%, -50%) rotate(0) scale(1); opacity: 1; } 50% { transform: translate(-50%, -50%) rotate(360deg) scale(0.1); opacity: 0; } 100% { transform: translate(-50%, -50%) rotate(720deg) scale(1); opacity: 1; } }
@keyframes heal { 0% { filter: drop-shadow(0 0 0 #8fd18a); } 50% { filter: drop-shadow(0 0 18px #8fd18a) brightness(1.3); } 100% { filter: drop-shadow(0 0 0 #8fd18a); } }
@keyframes float { from { transform: translate(-50%, 0); opacity: 1; } to { transform: translate(-50%, -60px); opacity: 0; } }
@keyframes fadeOut { 0%, 30% { opacity: 1; } 60%, 100% { opacity: 0; } }
@keyframes fadeIn { 0%, 30% { opacity: 0; transform: translate(-50%, -50%) scale(0.95); } 60%, 100% { opacity: 1; transform: translate(-50%, -50%) scale(1); filter: drop-shadow(0 0 14px rgba(255, 200, 80, 0.7)); } }
@keyframes rattle { 0%, 100% { transform: translate(-50%, -50%) rotate(0); } 10%, 30%, 50%, 70% { transform: translate(-50%, -50%) rotate(-4deg); } 20%, 40%, 60%, 80% { transform: translate(-50%, -50%) rotate(4deg); } }
@keyframes rise { 0% { transform: translate(-50%, 0) scale(1); opacity: 1; } 100% { transform: translate(-50%, -120%) scale(0.5); opacity: 0; } }
@keyframes coins { 0% { transform: translate(-50%, 20px); opacity: 0; } 30% { opacity: 1; } 100% { transform: translate(-50%, -40px) scale(1.3); opacity: 0; } }
@keyframes fall { 0% { transform: translate(-50%, -150%) rotate(-30deg); } 70% { transform: translate(-50%, -30%) rotate(80deg); } 85% { transform: translate(-50%, -40%) rotate(86deg); } 100% { transform: translate(-50%, -30%) rotate(90deg); } }
@keyframes slain { 0%, 25% { transform: translate(-50%, -50%); opacity: 1; filter: none; } 35% { filter: brightness(3); } 100% { transform: translate(-50%, -30%) rotate(18deg); opacity: 0; filter: grayscale(1); } }
@keyframes slash { 0% { transform: translate(-50%, -50%) rotate(-30deg) scaleX(0); opacity: 1; } 100% { transform: translate(-50%, -50%) rotate(-30deg) scaleX(1.2); opacity: 0; } }
@keyframes hurt { 0%, 100% { transform: translate(-50%, -50%); filter: none; } 10% { transform: translate(-56%, -50%); filter: drop-shadow(0 0 14px #d9452a) brightness(1.5); } 25% { transform: translate(-46%, -50%); } 40% { transform: translate(-52%, -50%); filter: drop-shadow(0 0 8px #d9452a); } }
@keyframes clashL { 0% { transform: translate(-160%, -50%) rotate(-40deg); } 30% { transform: translate(-75%, -50%) rotate(20deg); } 45% { transform: translate(-85%, -50%) rotate(14deg); } 100% { transform: translate(-80%, -50%) rotate(16deg); } }
@keyframes clashR { 0% { transform: translate(60%, -50%) rotate(40deg) scaleX(-1); } 30% { transform: translate(-25%, -50%) rotate(-20deg) scaleX(-1); } 45% { transform: translate(-15%, -50%) rotate(-14deg) scaleX(-1); } 100% { transform: translate(-20%, -50%) rotate(-16deg) scaleX(-1); } }
@keyframes roar { 0% { transform: translate(-50%, -50%) scale(0.9); } 15% { transform: translate(-50%, -50%) scale(1.12); filter: drop-shadow(0 0 16px rgba(217, 69, 42, 0.8)); } 20%, 40%, 60% { transform: translate(-52%, -50%) scale(1.1); } 30%, 50% { transform: translate(-48%, -50%) scale(1.1); } 100% { transform: translate(-50%, -50%) scale(1); } }
@keyframes stun { 0% { transform: translate(-50%, -50%) rotate(0); filter: none; } 40% { transform: translate(-50%, -40%) rotate(-70deg); } 100% { transform: translate(-50%, -30%) rotate(-85deg); filter: grayscale(1) brightness(0.6); } }
@keyframes revive { 0% { transform: translate(-50%, -30%); filter: grayscale(1) brightness(0.5); opacity: 0.4; } 100% { transform: translate(-50%, -50%); filter: drop-shadow(0 0 16px rgba(255, 220, 150, 0.8)); opacity: 1; } }
@keyframes fly { 0% { transform: translate(-200%, -50%) scale(0.6); } 70% { transform: translate(-10%, -50%) scale(1); opacity: 1; } 100% { transform: translate(10%, -50%) scale(2); opacity: 0; filter: brightness(2.5); } }
@keyframes flash { 0% { opacity: 0; transform: translate(-50%, -50%) scale(0.6); } 30% { opacity: 1; filter: brightness(2) drop-shadow(0 0 20px #b9a0ff); } 100% { opacity: 0; transform: translate(-50%, -50%) scale(1.4); } }
@keyframes thrust { 0% { transform: translate(-50%, 60%) scale(0.6); opacity: 0; } 30% { transform: translate(-50%, -55%) scale(1.15); opacity: 1; } 100% { transform: translate(-50%, -50%) scale(1); } }
@keyframes frost { 0% { transform: translate(-50%, -50%) scale(0.6); filter: none; } 30% { transform: translate(-50%, -50%) scale(1.15); filter: drop-shadow(0 0 18px #9fd8ff) brightness(1.4); } 100% { transform: translate(-50%, -50%) scale(1); filter: drop-shadow(0 0 4px #9fd8ff); } }
@keyframes cursed { from { filter: none; } to { filter: hue-rotate(250deg) saturate(1.6) brightness(0.7) drop-shadow(0 0 12px #8a4fd1); } }
@keyframes curseMark { from { opacity: 0; transform: translate(-50%, -50%) scale(1.6); } to { opacity: 1; transform: translate(-50%, -50%) scale(1); } }
@keyframes sneak { 0% { transform: translate(-150%, -50%); opacity: 1; } 30% { opacity: 0.25; } 70% { opacity: 0.25; } 100% { transform: translate(60%, -50%); opacity: 1; } }
@keyframes thud { 0% { transform: translate(-50%, -110%) scale(1.2); opacity: 0.5; } 55% { transform: translate(-50%, -50%) scale(1); opacity: 1; } 65% { transform: translate(-50%, -50%) scale(1.06, 0.92); } 100% { transform: translate(-50%, -50%) scale(1); } }
@keyframes banner { 0% { opacity: 0; letter-spacing: 0.5em; } 40% { opacity: 1; letter-spacing: 0.05em; text-shadow: 0 0 20px rgba(var(--ember-rgb), 0.9); } 100% { opacity: 1; } }
@keyframes tickPulse { 0% { transform: translate(-50%, -50%) scale(1.3); } 100% { transform: translate(-50%, -50%) scale(1); opacity: 0.8; } }
@keyframes openDoor { 0% { transform: translate(-50%, -50%) scaleX(0.1); filter: brightness(0.4); } 100% { transform: translate(-50%, -50%) scaleX(1); filter: brightness(1.2) drop-shadow(0 0 16px rgba(255, 200, 120, 0.6)); } }
@keyframes pan { from { transform: scale(1.05) translateX(-3%); } to { transform: scale(1.1) translateX(3%); } }
@keyframes flicker { 0%, 100% { opacity: 0.8; transform: scale(1); } 30% { opacity: 1; transform: scale(1.1); } 60% { opacity: 0.6; transform: scale(0.95); } }
@keyframes sway { from { transform: translate(-50%, -50%); } to { transform: translate(-50%, -54%); } }
@keyframes titleIn { 0% { opacity: 0; transform: translate(-50%, -50%) scale(1.6); } 25% { opacity: 1; transform: translate(-50%, -50%) scale(1); } 100% { opacity: 1; } }
@keyframes riseDark { 0% { transform: translate(-50%, -10%); opacity: 0; filter: brightness(0); } 100% { transform: translate(-50%, -50%); opacity: 1; filter: drop-shadow(0 0 20px #8a4fd1); } }
</style>
