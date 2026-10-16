<template>
  <!-- The fight as a full-screen scene: the hero left, the foe right, the roll between them, the choice below -->
  <div
    class="battle-overlay"
    :class="[`battle-overlay--${isRolling ? 'rolling' : dynamicResult}`, { 'battle-overlay--duel': duel }]"
  >
    <div
      v-if="showFlash"
      class="screen-flash"
      :class="{
        'flash-victory': dynamicResult === 'win',
        'flash-defeat': dynamicResult === 'lose',
        'flash-draw': dynamicResult === 'draw'
      }"
    />

    <div
      class="battle-card"
      role="dialog"
      aria-modal="true"
      :aria-label="sceneTitle"
    >
      <!-- Caption, title, the result -->
      <header class="battle-header">
        <span class="header-label">{{ duel ? $t('battle.duelHeader') : $t('battle.caption') }}</span>
        <h2 class="scene-title">
          {{ sceneTitle }}
        </h2>
        <h3
          :class="{
            'result-title': true,
            'victory-title': !isRolling && dynamicResult === 'win',
            'defeat-title': !isRolling && dynamicResult === 'lose',
            'draw-title': !isRolling && dynamicResult === 'draw',
            'rolling-title': isRolling,
            'result-slam': showResultSlam
          }"
        >
          {{ dynamicResultText }}
        </h3>
      </header>

      <div class="battle-body">
        <!-- The hero -->
        <FighterFigure
          class="combatant hero-side"
          side="hero"
          :src="heroSprite"
          :alt="heroLabel"
          :name="heroLabel"
          :state="heroState"
          @error="onHeroSpriteError"
        >
          <span
            class="hero-hp"
            :class="{ 'hp-loss': !isRolling && (dynamicResult === 'lose' || sacrificeHp) }"
            :aria-label="$t('hud.hpOf', { hp: shownHp, max: 5 })"
          >
            <GameIcon
              v-for="n in 5"
              :key="n"
              name="heart"
              :size="20"
              class="hp-heart"
              :class="{ filled: n <= shownHp }"
            />
          </span>
        </FighterFigure>

        <!-- The roll between them -->
        <div class="roll-centre">
          <div
            class="dice-stage"
            :class="{ 'dice-visible': showDice }"
          >
            <div class="dice-container">
              <DieFace
                v-for="(value, index) in battleInfo.diceResults"
                :key="`${battleInfo.battleId}-${battleInfo.rerolled ? 'r' : ''}-${index}`"
                :value="value"
                :rolling="isRolling && showDice"
                :landed="!isRolling"
                :tilt="index % 2 ? 8 : -12"
                :delay="index * 0.07"
                :throw-from="index % 2 ? 0.7 : 1"
              />
            </div>
          </div>

          <div
            class="score"
            :class="{ 'score--shown': !isRolling }"
          >
            <div class="score__side">
              <div
                class="score__num score__num--hero"
                :class="!isRolling ? 'dealt-' + dynamicResult : 'dealt-hidden'"
              >
                {{ isRolling ? '–' : totalCalculatedDamage }}
              </div>
              <div
                v-if="!isRolling && breakdown.length"
                class="score__detail"
              >
                <span
                  v-for="(part, i) in breakdown"
                  :key="i"
                  :class="{ 'score__part--accent': part.accent }"
                >{{ i ? `, ${part.text}` : part.text }}</span>
              </div>
            </div>
            <template v-if="!isDuelChallenger">
              <span class="score__vs">{{ $t('battle.vs') }}</span>
              <div class="score__side">
                <div class="score__num score__num--foe">
                  {{ battleInfo.monster }}
                </div>
                <div class="score__detail">
                  {{ duel ? $t('battle.rivalStrength') : $t('battle.itsStrength') }}
                </div>
              </div>
            </template>
          </div>

          <!-- How far the strike reaches against the foe -->
          <div
            v-if="!isDuelChallenger"
            class="damage-bar-center"
            :class="{ 'hp-bar-reveal': showResults }"
          >
            <div class="hp-bar-track">
              <div
                class="hp-bar-fill"
                :class="'bar-' + dynamicResult"
                :style="{ width: (isRolling ? 0 : damageBarPercent) + '%' }"
              />
            </div>
          </div>

          <!-- What the heroes' skills did to the roll -->
          <div
            v-if="!isRolling"
            class="verdicts"
          >
            <div
              v-if="battleInfo.rerolled && battleInfo.firstDiceResults"
              class="first-roll-note"
              data-testid="first-roll"
            >
              <I18nT
                scope="global"
                keypath="battle.notes.doubleAttack"
                tag="span"
              >
                <template #skill><b>{{ $t('skills.double_attack.name') }}</b></template>
                <template #roll>
                  <s>{{ battleInfo.firstDiceResults.join(' + ') }}</s>
                </template>
              </I18nT>
            </div>
            <div
              v-if="battleInfo.tieIsWin && battleInfo.result === 'win' && battleInfo.totalDamage === battleInfo.monster"
              class="first-roll-note"
              data-testid="backstab-note"
            >
              <I18nT
                scope="global"
                keypath="battle.notes.backstab"
                tag="span"
              >
                <template #skill><b>{{ $t('skills.backstab.name') }}</b></template>
              </I18nT>
            </div>
            <div
              v-if="battleInfo.onesRerolled > 0"
              class="first-roll-note"
              data-testid="combat-training-note"
            >
              <I18nT
                scope="global"
                keypath="battle.notes.combatTraining"
                tag="span"
              >
                <template #skill><b>{{ $t('skills.combat_training.name') }}</b></template>
                <template #n>{{ battleInfo.onesRerolled }}</template>
              </I18nT>
            </div>
            <div
              v-if="battleInfo.hpSacrificed"
              class="first-roll-note"
              data-testid="sacrifice-note"
            >
              <I18nT
                scope="global"
                keypath="battle.notes.sacrifice"
                tag="span"
              >
                <template #skill><b>{{ $t('skills.sacrifice.name') }}</b></template>
              </I18nT>
            </div>
            <div
              v-if="battleInfo.spellsKept"
              class="first-roll-note"
              data-testid="affinity-note"
            >
              <I18nT
                scope="global"
                keypath="battle.notes.affinity"
                tag="span"
              >
                <template #skill><b>{{ $t('skills.magical_affinity.name') }}</b></template>
              </I18nT>
            </div>
            <div
              v-if="swordMasterBonus > 0"
              class="first-roll-note"
              data-testid="sword-master-note"
            >
              <I18nT
                scope="global"
                keypath="battle.notes.swordMaster"
                tag="span"
              >
                <template #skill><b>{{ $t('skills.sword_master.name') }}</b></template>
                <template #n>{{ swordMasterBonus }}</template>
              </I18nT>
            </div>
            <div
              v-if="battleInfo.farseeing"
              class="first-roll-note"
              data-testid="farseeing-note"
            >
              <I18nT
                scope="global"
                keypath="battle.notes.farseeing"
                tag="span"
              >
                <template #skill><b>{{ $t('skills.farseeing.name') }}</b></template>
              </I18nT>
            </div>
            <div
              v-if="battleInfo.berserk > 0"
              class="first-roll-note"
              data-testid="berserk-note"
            >
              <I18nT
                scope="global"
                keypath="battle.notes.berserk"
                tag="span"
              >
                <template #skill><b>{{ $t('skills.berserk.name') }}</b></template>
                <template #bonus>{{ battleInfo.berserk }}</template>
                <template #range>{{ battleInfo.berserk === 2 ? '1–2' : '3–4' }}</template>
              </I18nT>
            </div>
            <div
              v-if="battleInfo.onesAsSixes > 0"
              class="first-roll-note"
              data-testid="bear-attack-note"
            >
              <I18nT
                scope="global"
                keypath="battle.notes.bearAttack"
                tag="span"
                :plural="battleInfo.onesAsSixes"
              >
                <template #skill><b>{{ $t('skills.bear_attack.name') }}</b></template>
                <template #n>{{ battleInfo.onesAsSixes }}</template>
              </I18nT>
            </div>
            <div
              v-if="battleInfo.retreatOptions?.length"
              class="first-roll-note"
              data-testid="lightning-retreat-note"
            >
              <I18nT
                scope="global"
                keypath="battle.notes.lightningRetreat"
                tag="span"
              >
                <template #skill><b>{{ $t('skills.lightning_attack.name') }}</b></template>
              </I18nT>
            </div>
            <div
              v-if="battleInfo.curseResolvedLabel"
              class="first-roll-note first-roll-note--curse"
              data-testid="curse-note"
            >
              <img
                src="/images/curse.webp"
                alt=""
                class="first-roll-note__icon"
              >{{ battleInfo.curseResolvedLabel }}
            </div>
            <div
              v-if="turnContinues"
              class="first-roll-note"
              data-testid="unstoppable-note"
            >
              <I18nT
                scope="global"
                keypath="battle.notes.unstoppable"
                tag="span"
              >
                <template #skill><b>{{ $t('skills.unstoppable.name') }}</b></template>
              </I18nT>
            </div>
          </div>
        </div>

        <!-- The foe: a monster, or the rival hero of a Battle Arena duel -->
        <FighterFigure
          v-if="duel"
          class="combatant monster-side"
          side="foe"
          :src="rivalSprite"
          :alt="rivalLabel"
          :name="rivalLabel"
          img-testid="duel-rival"
        >
          <span
            class="monster-name-label"
            data-testid="duel-rival-label"
          >{{ duelRivalText }}</span>
        </FighterFigure>
        <FighterFigure
          v-else
          class="combatant monster-side"
          side="foe"
          :src="displayMonsterImage"
          :alt="formattedMonsterName"
          :name="formattedMonsterName"
          :state="foeState"
        >
          <span
            v-if="guardNote"
            class="monster-name-label"
          >{{ guardNote }}</span>
        </FighterFigure>
      </div>

      <!-- The choice after the roll -->
      <section
        v-show="!isRolling"
        class="choice-plaque"
        :class="{ 'section-reveal': showResults }"
      >
        <div
          v-if="plaqueTitle"
          class="choice-plaque__head"
        >
          <span class="choice-plaque__title">{{ plaqueTitle }}</span>
          <span
            v-if="plaqueHint"
            class="choice-plaque__hint"
          >{{ plaqueHint }}</span>
        </div>

        <!-- Spells and Sacrifice to add to the roll -->
        <div
          v-if="!isRolling && showConsumableSelection && (availableDamageConsumables.length || sacrificeAvailable)"
          class="consumable-row"
        >
          <ConsumableCard
            v-for="item in availableDamageConsumables"
            :key="item.itemId"
            class="item-chip selectable"
            :class="{ selected: selectedConsumables.includes(item.itemId) }"
            :data-item-id="item.itemId"
            :name="getSpellDisplayName(item)"
            :effect="$t('battle.adds', { n: getItemTypeDamage(item.type) })"
            :image="getItemImage(item)"
            :selected="selectedConsumables.includes(item.itemId)"
            @toggle="toggleConsumable(item)"
          />
          <ConsumableCard
            v-if="sacrificeAvailable"
            class="item-chip selectable sacrifice-chip"
            :class="{ selected: sacrificeHp }"
            data-testid="sacrifice-hp"
            :name="$t('battle.sacrificeChip')"
            :effect="$t('battle.adds', { n: 1 })"
            icon="heart"
            :title="$t('battle.sacrificeTitle', { skill: $t('skills.sacrifice.name') })"
            :selected="sacrificeHp"
            @toggle="toggleSacrifice"
          />
        </div>

        <!-- Spells spent in this fight -->
        <div
          v-if="!isRolling && !showConsumableSelection && !showInventorySelection && usedDamageConsumables.length > 0"
          class="items-section"
        >
          <span class="items-section-title">{{ battleInfo.spellsKept ? $t('battle.spellsKept', { skill: $t('skills.magical_affinity.name') }) : $t('battle.usedConsumables') }}</span>
          <span
            v-for="(item, index) in usedDamageConsumables"
            :key="`used-${index}`"
            class="item-chip consumable-used"
            :class="{ 'consumable-kept': battleInfo.spellsKept }"
          >
            <img
              v-if="getItemImage(item)"
              :src="getItemImage(item)"
              alt=""
              class="chip-img"
            >
            <span class="chip-name">{{ getCorrectItemName(item) }}</span>
            <span class="chip-damage">+{{ getItemTypeDamage(item.type) }}</span>
          </span>
        </div>

        <!-- A full pack: the same plaque as outside the fight — the reward, an arrow to every item it could
             replace, one click swaps; "leave the find" wins the fight and leaves the reward -->
        <InventoryFullDialog
          v-if="!isRolling && showInventorySelection"
          :dropped-item="pendingPickupItem || battleInfo.reward"
          :item-category="rewardCategory"
          :inventory-for-category="inventoryForSelection"
          @replace-item="replaceAndTake"
          @skip-item="finalizeBattleAndLeaveItem"
        />

        <!-- Curse of Karak: choose the victim before picking up the reward or ending the turn -->
        <CurseChoicePanel
          v-if="curseChoicePending"
          :players="curseChoice?.players || []"
          :cursed-player-id="curseChoice?.cursedPlayerId || null"
          :cursed-label="curseChoice?.cursedLabel || ''"
          :disabled="isProcessing"
          @choose="(target) => emit('curse', target)"
        />

        <!-- the reward and the buttons wait until the Curse of Karak has a victim (one step at a time, the plaque stays low) -->
        <div
          v-show="!curseChoicePending"
          class="choice-plaque__bottom"
        >
          <!-- The reward the foe guards -->
          <div
            v-if="showRewardRow"
            class="reward-row"
            :class="[rewardCategoryClass, { 'reward-rise': showResults, 'reward-row--potential': isPotentialReward && !hasBoost }]"
          >
            <span class="reward-row__icon">
              <span
                v-if="dynamicResult === 'win'"
                class="victory-sparkles"
                aria-hidden="true"
              >
                <span
                  v-for="i in 8"
                  :key="`sparkle-${i}`"
                  class="sparkle"
                  :style="sparkleStyle(i)"
                />
              </span>
              <img
                v-if="battleInfo.reward && displayItemImage"
                :src="displayItemImage"
                :alt="formattedItemName"
                class="reward-row__img"
                :class="`reward-row__img--${battleInfo.reward.type}`"
              >
              <GameIcon
                v-else
                name="treasure"
                :size="44"
              />
            </span>
            <span class="reward-row__text">
              <span class="reward-row__label">{{ $t('battle.reward') }}</span>
              <span class="reward-row__name">{{ battleInfo.reward ? formattedItemName : itemName('treasure') }}</span>
              <span
                v-if="(battleInfo.reward && getItemTypeDamage(battleInfo.reward.type) > 0) || battleInfo.reward?.treasureValue > 0 || (isGuardChestReward && !showConsumableSelection)"
                class="reward-row__stats"
              >
                <span
                  v-if="battleInfo.reward && getItemTypeDamage(battleInfo.reward.type) > 0"
                  class="stat-badge badge-damage"
                ><GameIcon
                  name="sword"
                  :size="15"
                /> +{{ getItemTypeDamage(battleInfo.reward.type) }}</span>
                <span
                  v-if="battleInfo.reward?.treasureValue > 0"
                  class="stat-badge badge-value"
                ><GameIcon
                  name="treasure"
                  :size="15"
                /> {{ battleInfo.reward.treasureValue }}</span>
                <span
                  v-if="isGuardChestReward && !showConsumableSelection"
                  class="reward-note success"
                >{{ $t('battle.chestAutoOpened') }}</span>
              </span>
            </span>
          </div>

          <!-- The buttons -->
          <div
            v-show="!isRolling"
            class="battle-footer"
            :class="{ 'footer-reveal': showResults, 'footer-locked': curseChoicePending, 'footer-working': isProcessing }"
            @click.capture="markPressed"
          >
            <!-- Karak: Regent — a duel side: lock in the strength (consumables / sacrifice / reroll as usual), then continue -->
            <div
              v-if="duel"
              class="button-group"
            >
              <button
                v-if="duelAwaitsDecision && battleInfo.canReroll"
                class="btn btn--ghost btn-reroll"
                :disabled="isProcessing"
                data-testid="reroll-battle"
                @click="rerollDice"
              >
                <GameIcon
                  name="dice"
                  :size="18"
                /> {{ $t('battle.reroll', { skill: $t('skills.double_attack.name') }) }} <span class="kbd-hint">R</span>
              </button>
              <button
                class="btn btn--ember btn-primary"
                :disabled="isProcessing"
                :data-testid="duelAwaitsDecision ? 'finalize-battle' : 'duel-continue'"
                @click="duelPrimaryAction"
              >
                {{ duelPrimaryLabel }} <span class="kbd-hint">Enter</span>
              </button>
            </div>
            <!-- Keys: player already has one -->
            <div
              v-else-if="battleInfo.result === 'win' && battleInfo.reward && isKeyReward && !hasInventorySpace && !showInventorySelection && !showConsumableSelection && !battleFinalized"
              class="button-group"
            >
              <button
                class="btn btn--ember btn-primary close-btn"
                :disabled="isProcessing"
                @click="leaveItemAndEndTurn"
              >
                {{ turnContinues ? $t('battle.continueTurn') : $t('battle.endTurn') }} {{ $t('battle.allKeysSame') }} <span class="kbd-hint">Enter</span>
              </button>
            </div>
            <!-- Guard chest: auto-collected -->
            <div
              v-else-if="battleInfo.result === 'win' && battleInfo.reward && isGuardChestReward && !showInventorySelection && !showConsumableSelection"
              class="button-group"
            >
              <button
                class="btn btn--ember btn-primary"
                :disabled="isProcessing"
                @click="pickUpAndEndTurn"
              >
                {{ turnContinues ? $t('battle.continueTurn') : $t('battle.endTurn') }} {{ $t('battle.treasureAuto') }} <span class="kbd-hint">Enter</span>
              </button>
            </div>
            <!-- Normal victory with reward -->
            <div
              v-else-if="battleInfo.result === 'win' && battleInfo.reward && !showInventorySelection && !showConsumableSelection"
              class="button-group"
            >
              <!-- Leave the reward on the tile (also the e2e's old "×" hook: .close-btn) -->
              <button
                v-if="!isGuardChestReward"
                class="btn btn--ghost btn-secondary close-btn"
                :disabled="isProcessing"
                data-testid="leave-reward"
                @click="leaveItemAndEndTurn"
              >
                {{ turnContinues ? $t('battle.leaveContinue') : $t('battle.leaveEndTurn') }} <span class="kbd-hint">Esc</span>
              </button>
              <!-- Valduk "Tireless": leave the loot on the tile and keep moving -->
              <button
                v-if="canContinueTireless && !isGuardChestReward"
                class="btn btn--ghost btn-secondary"
                :disabled="isProcessing"
                data-testid="tireless-continue"
                @click="continueTireless"
              >
                {{ $t('battle.tirelessLeave', { skill: $t('skills.tireless.name') }) }}
              </button>
              <button
                class="btn btn--ember btn-primary"
                :disabled="isProcessing"
                @click="pickUpAndEndTurn"
              >
                {{ turnContinues ? $t('battle.pickUpContinue') : $t('battle.pickUpEndTurn') }} <span class="kbd-hint">Enter</span>
              </button>
            </div>
            <!-- Consumable selection buttons -->
            <div
              v-else-if="showConsumableSelection"
              class="button-group"
            >
              <button
                v-if="battleInfo.canReroll && !battleFinalized"
                class="btn btn--ghost btn-reroll"
                :disabled="isProcessing"
                data-testid="reroll-battle"
                @click="rerollDice"
              >
                <GameIcon
                  name="dice"
                  :size="18"
                /> {{ $t('battle.reroll', { skill: $t('skills.double_attack.name') }) }} <span class="kbd-hint">R</span>
              </button>
              <button
                v-if="hasBoost && beatsMonster(totalCalculatedDamage)"
                class="btn btn--ghost btn-secondary"
                :disabled="isProcessing"
                @click="finalizeBattleAndLeaveItem"
              >
                {{ $t('battle.leaveAfterVictory') }}
              </button>
              <!-- Valduk "Tireless": win, leave the loot and keep moving -->
              <button
                v-if="hasBoost && beatsMonster(totalCalculatedDamage) && canContinueTireless"
                class="btn btn--ghost btn-secondary"
                :disabled="isProcessing"
                data-testid="tireless-continue"
                @click="finalizeBattleAndContinueTireless"
              >
                {{ $t('battle.tirelessWin', { skill: $t('skills.tireless.name') }) }}
              </button>
              <button
                v-if="hasBoost && beatsMonster(totalCalculatedDamage)"
                class="btn btn--ember btn-primary btn-strike"
                :disabled="isProcessing"
                @click="finalizeBattleAndPickUp"
              >
                {{ $t('battle.fightWinPickUp') }}
              </button>
              <button
                v-else-if="hasBoost && totalCalculatedDamage === battleInfo.monster && !battleInfo.tieIsWin"
                class="btn btn--ghost btn-draw"
                :disabled="isProcessing"
                @click="finalizeBattleWithConsumables"
              >
                {{ $t('battle.fightForDraw') }}
              </button>
              <button
                v-else
                class="btn btn--blood btn-defeat close-btn"
                :disabled="isProcessing"
                @click="finalizeBattleWithoutConsumables"
              >
                {{ retreatLabel }} <span class="kbd-hint">Enter</span>
              </button>
            </div>
            <!-- Lost or draw without consumable selection -->
            <div
              v-else-if="(battleInfo.result === 'lose' || battleInfo.result === 'draw') && !showInventorySelection"
              class="button-group"
            >
              <button
                v-if="battleInfo.canReroll && !battleFinalized"
                class="btn btn--ghost btn-reroll"
                :disabled="isProcessing"
                data-testid="reroll-battle"
                @click="rerollDice"
              >
                <GameIcon
                  name="dice"
                  :size="18"
                /> {{ $t('battle.reroll', { skill: $t('skills.double_attack.name') }) }} <span class="kbd-hint">R</span>
              </button>
              <button
                class="btn btn--blood btn-defeat close-btn"
                :disabled="isProcessing"
                @click="handleRetreat"
              >
                {{ retreatLabel }} <span class="kbd-hint">Enter</span>
              </button>
            </div>
            <!-- Fallback end turn -->
            <div
              v-else
              class="button-group"
            >
              <button
                class="btn btn--ember btn-primary close-btn"
                :disabled="isProcessing"
                @click="leaveItemAndEndTurn"
              >
                {{ turnContinues ? $t('battle.continueTurn') : $t('battle.endTurn') }} <span class="kbd-hint">Enter</span>
              </button>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { defineProps, defineEmits, computed, ref, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { I18nT, useI18n } from 'vue-i18n';
import { getMonsterImage } from '@/utils/monsterUtils';
import { formatItemName, getItemImage } from '@/utils/itemUtils';
import { itemName, monsterName, heroName } from '@/i18n/names';
import CurseChoicePanel from '@/components/game/CurseChoicePanel.vue';
import InventoryFullDialog from '@/components/game/InventoryFullDialog.vue';
import GameIcon from '@/components/ui/GameIcon.vue';
import DieFace from '@/components/game/fight/DieFace.vue';
import FighterFigure from '@/components/game/fight/FighterFigure.vue';
import ConsumableCard from '@/components/game/fight/ConsumableCard.vue';
import { getHero } from '@/theme';

const props = defineProps({
  /** HeroType of the fighting player (null = no hero) — picks the theme's hero portrait */
  heroKey: {
    type: String,
    default: null
  },
  battleInfo: {
    type: Object,
    required: true
  },
  /** Curse of Karak: { players: [{ id, label }], cursedPlayerId, cursedLabel } for the choice after beating a mummy */
  curseChoice: {
    type: Object,
    default: null
  },
  hasInventorySpace: {
    type: Boolean,
    default: true
  },
  playerHp: {
    type: Number,
    default: 5
  },
  /** Karak: Regent — the other hero of a Battle Arena duel: { label, heroKey } */
  /** the roller's name as everywhere else ("Adventurer II"); the hero's short name when not given */
  heroLabelText: { type: String, default: '' },
  duelRival: {
    type: Object,
    default: null
  },
  /** Skill flags of the fighting hero (empty while cursed): Sword Master, Tireless */
  heroFlags: {
    type: Object,
    default: () => ({})
  }
});

const { t } = useI18n();

const emit = defineEmits(['end-turn', 'pick-item-and-end-turn', 'pick-item-with-replacement', 'finalize-battle', 'finalize-battle-and-pick-up', 'reroll-battle', 'curse', 'duel-continue', 'tireless']);

// Karak: Regent — a Battle Arena duel side ({ duelId, role, opponentId, toBeat }); the challenger has nothing to beat yet
const duel = computed(() => props.battleInfo?.duel || null);
const isDuelChallenger = computed(() => duel.value?.role === 'challenger');
const rivalLabel = computed(() => props.duelRival?.label || t('battle.rival'));
// the rival's own portrait (a hero-less rival is drawn as the Adventurer, like in the party rail)
const rivalSprite = computed(() => (props.duelRival?.heroKey && getHero(props.duelRival.heroKey).image) || DEFAULT_HERO_SPRITE);
const duelRivalText = computed(() => (isDuelChallenger.value
  ? t('battle.rivalRollsNext', { name: rivalLabel.value })
  : t('battle.rivalToBeat', { name: rivalLabel.value, n: duel.value?.toBeat ?? props.battleInfo?.monster })));
// The side still waits for the roller's decision (the backend asked for consumables and nothing was sent yet)
const duelAwaitsDecision = computed(() => !!duel.value && props.battleInfo?.needsConsumableConfirmation === true && !battleFinalized.value);
const duelPrimaryLabel = computed(() => {
  if (!duelAwaitsDecision.value) return t('battle.continue');
  return t('battle.lockIn');
});
const duelPrimaryAction = () => {
  if (isProcessing.value) return;
  if (!duelAwaitsDecision.value) {
    isProcessing.value = true;
    emit('duel-continue', { battleId: props.battleInfo?.battleId });
    return;
  }
  isProcessing.value = true;
  battleFinalized.value = true;
  emit('finalize-battle', {
    battleId: props.battleInfo.battleId,
    selectedConsumableIds: selectedConsumables.value,
    sacrificeHp: sacrificeHp.value
  });
  setShowConsumableSelection(false, 'duel side locked in');
};

// Curse of Karak: the slayer of a mummy picks the victim before any other button works
const curseChoicePending = computed(() => !isRolling.value && props.battleInfo?.curseChoiceRequired === true);

// Animation state
const isRolling = ref(true);
const isAnimating = ref(true); // entrance animation phase
const showResults = ref(false);
const showDice = ref(false);
const showFlash = ref(false);
const showResultSlam = ref(false);

// Sparkle position helper for victory particles
const sparkleStyle = (i) => {
  const angle = (i / 8) * 360;
  const rad = (angle * Math.PI) / 180;
  const distance = 40 + (i % 3) * 15;
  return {
    '--sparkle-x': `${Math.cos(rad) * distance}px`,
    '--sparkle-y': `${Math.sin(rad) * distance}px`,
    animationDelay: `${i * 0.08}s`
  };
};

// Start dice rolling animation
const startRollingAnimation = () => {
  // Reset states
  isRolling.value = true;
  isAnimating.value = true;
  showResults.value = false;
  showDice.value = false;
  showFlash.value = false;
  showResultSlam.value = false;

  // Phase 1: Entrance animations (0-400ms)
  // Characters slide in via CSS (0.35s duration), let them finish

  // Phase 2: Show dice and start rolling (after entrances settle)
  setTimeout(() => {
    isAnimating.value = false;
    showDice.value = true;
  }, 400);

  // The dice are thrown at 400ms and rest about 0.92s later (DieFace: a 0.85s throw, the second die 0.07s behind);
  // the result is told only after that: 400 + 1200 + 200 = 1800ms
  setTimeout(() => {
    // A beat with the dice at rest before the result
    setTimeout(() => {
      isRolling.value = false;

      // Screen flash after a beat (150ms later)
      setTimeout(() => {
        showFlash.value = true;
        showResultSlam.value = true;
        setTimeout(() => {
          showFlash.value = false;
        }, 300);
      }, 150);

      // Show damage numbers, reward, buttons (300ms after result)
      setTimeout(() => {
        showResults.value = true;
      }, 300);
    }, 200);
  }, 1200);
};

// Start animation when component mounts or battle info changes
onMounted(() => {
  startRollingAnimation();
});

// Restart animation if battle info changes (new battle)
watch(() => props.battleInfo?.battleId, (newId, oldId) => {
  if (newId && newId !== oldId) {
    startRollingAnimation();
    isProcessing.value = false;
    battleFinalized.value = false;
    sacrificeHp.value = false;
    selectedConsumables.value = [];
  }
});

// Warrior "Double Attack": the same battle gets new dice — roll them again
watch(() => props.battleInfo?.rerolled, (rerolled, wasRerolled) => {
  if (rerolled && !wasRerolled) {
    selectedConsumables.value = [];
    sacrificeHp.value = false;
    isProcessing.value = false;
    startRollingAnimation();
  }
});

const rerollDice = () => {
  if (isProcessing.value || isRolling.value || !props.battleInfo?.canReroll || battleFinalized.value) return;
  isProcessing.value = true;
  console.log('Double Attack: rerolling dice for battle', props.battleInfo.battleId);
  emit('reroll-battle', { battleId: props.battleInfo.battleId });
};

// Reactive data for inventory selection
const showInventorySelection = ref(false);
const inventoryForSelection = ref([]);
const selectedItemForReplacement = ref(null);
const pendingPickupItem = ref(null);

// Reactive data for consumable selection
const showConsumableSelection = ref(false);
const availableConsumables = ref([]);
const selectedConsumables = ref([]);

// Warlock "Sacrifice": pay 1 HP for +1 strength, decided together with the consumables
const sacrificeHp = ref(false);
const sacrificeAvailable = computed(() => props.battleInfo?.canSacrificeHp === true && !battleFinalized.value);
const sacrificeBonus = computed(() => (sacrificeHp.value ? 1 : 0));
// Something was added to the roll (a consumable or the sacrifice), so the result must be recomputed
const hasBoost = computed(() => selectedConsumables.value.length > 0 || sacrificeHp.value);

const toggleSacrifice = () => {
  if (!sacrificeAvailable.value || isProcessing.value) return;
  sacrificeHp.value = !sacrificeHp.value;
  console.log('Sacrifice toggled:', sacrificeHp.value);
};

const setShowConsumableSelection = (value, reason = '') => {
  if (showConsumableSelection.value === value) return;
  try {
    const ts = new Date().toISOString();
    console.log(`[BattleReportModal] ${ts} showConsumableSelection ->`, value, reason ? `reason: ${reason}` : '');
  } catch { /* logging must never break the fight */ }
  showConsumableSelection.value = value;
};

// Computed to filter available consumables for only damage-dealing ones
const availableDamageConsumables = computed(() => {
  if (!availableConsumables.value) return [];
  return availableConsumables.value.filter(item => getItemTypeDamage(item.type) > 0);
});

const battleFinalized = ref(false);
const isProcessing = ref(false);

const getItemTypeDamage = (itemType) => {
  const damageMap = {
    'dagger': 1,
    'sword': 2,
    'axe': 3,
    'fireball': 1,
    'frost_fist': 2,
    'dark_thorn': 0,
    'teleport': 0,
    'key': 0,
    'chest': 0,
    'ruby_chest': 0
  };
  return damageMap[itemType] || 0;
};

// Thief "Backstab": a tie counts as a victory when the backend says so
const beatsMonster = (damage) => (props.battleInfo?.tieIsWin ? damage >= props.battleInfo.monster : damage > props.battleInfo.monster);

// Swordsman "Unstoppable": the backend says the turn continues after this battle (a 6 was rolled)
const turnContinues = computed(() => props.battleInfo?.unstoppable === true);
const retreatLabel = computed(() => {
  if (props.battleInfo?.result === 'draw') {
    return turnContinues.value ? t('battle.retreatContinue') : t('battle.retreat');
  }
  return turnContinues.value ? t('battle.takeHitContinue') : t('battle.acceptDefeat');
});

const potentialVictoryWithConsumables = computed(() => {
  if (!availableConsumables.value || duel.value) return false;

  const currentDamage = (props.battleInfo.diceRollDamage || 0) + weaponDamageTotal.value + rollBonus.value;
  const maxConsumableDamage = availableConsumables.value
    .filter(item => getItemTypeDamage(item.type) > 0)
    .reduce((total, item) => total + getItemTypeDamage(item.type), 0) + (sacrificeAvailable.value ? 1 : 0);
  console.log('Log damage:', {
    dice: props.battleInfo.diceRollDamage,
    weaponDamageTotal: weaponDamageTotal.value,
    maxConsumableDamage: maxConsumableDamage,
    monsterHp: props.battleInfo.monster,
    potentialVictory: (currentDamage + maxConsumableDamage) > props.battleInfo.monster,
  });

  return beatsMonster(currentDamage + maxConsumableDamage);
});


const dynamicResult = computed(() => {
  if (hasBoost.value && (showConsumableSelection.value || showInventorySelection.value)) {
    const totalDamage = totalCalculatedDamage.value;
    const monsterHP = props.battleInfo.monster;

    if (beatsMonster(totalDamage)) return 'win';
    if (totalDamage === monsterHP) return 'draw';
    return 'lose';
  }

  return props.battleInfo.result;
});

const dynamicResultText = computed(() => {
  if (isRolling.value) {
    return t('battle.rolling');
  }

  if (isDuelChallenger.value) {
    return t('battle.yourStrength', { n: totalCalculatedDamage.value });
  }

  // a choice is still open (spells, Sacrifice, the duel side not locked in): a forecast, not the outcome
  if ((hasBoost.value && (showConsumableSelection.value || showInventorySelection.value)) || duelAwaitsDecision.value) {
    const totalDamage = totalCalculatedDamage.value;
    const monsterHP = props.battleInfo.monster;

    if (totalDamage > monsterHP || (totalDamage === monsterHP && props.battleInfo.tieIsWin)) return t('battle.forecastWin');
    if (totalDamage === monsterHP) return t('battle.forecastDraw');
    return t('battle.forecastLose');
  }

  if (props.battleInfo.result === 'win') return t('battle.victory');
  if (props.battleInfo.result === 'draw') return t('battle.draw');
  return t('battle.defeat');
});

const usedDamageConsumables = computed(() => {
  if (!props.battleInfo.usedItems) return [];
  return props.battleInfo.usedItems.filter(item =>
    item.type && ['fireball', 'frost_fist'].includes(item.type)
  );
});


const equippedWeapons = computed(() => {
  if (!props.battleInfo.usedItems) return [];
  return props.battleInfo.usedItems.filter(item =>
    item.type && ['dagger', 'sword', 'axe'].includes(item.type)
  );
});

const weaponDamageTotal = computed(() => {
  if (!props.battleInfo.usedItems) return 0;
  return props.battleInfo.usedItems
    .filter(item => item.type && ['dagger', 'sword', 'axe'].includes(item.type))
    .reduce((total, item) => total + weaponStrength(item), 0);
});

// Baron Markul "Sword Master": a sword has strength 3 in his hands
const swordMasterBonus = computed(() => equippedWeapons.value
  .reduce((sum, w) => sum + Math.max(0, weaponStrength(w) - getItemTypeDamage(w.type)), 0));
const weaponStrength = (weapon) => (weapon?.type === 'sword' && props.heroFlags?.masterOfSword === true ? 3 : getItemTypeDamage(weapon?.type));

// Valduk "Tireless": a won fight whose loot he may leave on the tile to keep moving
const canContinueTireless = computed(() => props.heroFlags?.tireless === true && !duel.value);
const continueTireless = () => {
  if (isProcessing.value) return;
  isProcessing.value = true;
  emit('tireless', { battleId: props.battleInfo.battleId });
};


const consumableDamageTotal = computed(() => {
  if (!availableConsumables.value || selectedConsumables.value.length === 0) return sacrificeBonus.value;
  return availableConsumables.value
    .filter(item => selectedConsumables.value.includes(item.itemId))
    .reduce((total, item) => total + getItemTypeDamage(item.type), 0) + sacrificeBonus.value;
});

// Oracle "Farseeing" and Valduk "Berserk": bonuses of the roll, the backend keeps them out of itemDamage
const farseeingBonus = computed(() => (props.battleInfo.farseeing ? 1 : 0));
const berserkBonus = computed(() => props.battleInfo.berserk || 0);
const rollBonus = computed(() => farseeingBonus.value + berserkBonus.value);

const totalCalculatedDamage = computed(() => {
  return (props.battleInfo.diceRollDamage || 0) + (props.battleInfo.itemDamage || 0) + consumableDamageTotal.value + rollBonus.value;
});

// The hero's HP when this fight began: the HP lost to it (or paid for Sacrifice) is subtracted on screen
// from this, not from the live value — once "Accept defeat" reaches the server the live HP has already
// dropped, and the hearts used to lose a second one
const hpAtFightStart = ref(props.playerHp);
watch(() => props.battleInfo?.battleId, () => { hpAtFightStart.value = props.playerHp; });
const playerHpAfterBattle = computed(() => {
  const hpAfterSacrifice = hpAtFightStart.value - sacrificeBonus.value;
  if (dynamicResult.value === 'lose') {
    return Math.max(0, hpAfterSacrifice - 1);
  }
  return Math.max(0, hpAfterSacrifice);
});

const damageBarPercent = computed(() => {
  const monsterHP = props.battleInfo.monster || 1;
  return Math.min(100, (totalCalculatedDamage.value / monsterHP) * 100);
});

const isKeyReward = computed(() => {
  if (!props.battleInfo.reward) return false;
  const reward = props.battleInfo.reward;
  const isKeyType = reward.type === 'key';
  const isKeyName = reward.name === 'key' || (typeof reward.name === 'string' && reward.name.toLowerCase().includes('key'));
  console.log('Key reward check:', {
    rewardType: reward.type,
    rewardName: reward.name,
    isKeyType,
    isKeyName,
    hasInventorySpace: props.hasInventorySpace
  });
  return isKeyType || isKeyName;
});

const isGuardChestReward = computed(() => {
  if (!props.battleInfo.reward) return false;
  const reward = props.battleInfo.reward;
  if (Object.prototype.hasOwnProperty.call(reward, 'autoCollected')) {
    return reward.autoCollected === true;
  }
  const isChestType = reward.type === 'chest' || reward.type === 'ruby_chest';
  const hasDirectTreasureValue = reward.treasureValue && reward.treasureValue > 0;
  return isChestType && hasDirectTreasureValue;
});

const hasInventorySpaceForEstimatedReward = computed(() => {
  return props.hasInventorySpace;
});

// Reward category class for accent colors
const rewardCategoryClass = computed(() => {
  if (!props.battleInfo.reward) return '';
  const type = props.battleInfo.reward.type;
  if (['dagger', 'sword', 'axe'].includes(type)) return 'category-weapon';
  if (type === 'key') return 'category-key';
  if (['fireball', 'teleport', 'frost_fist', 'dark_thorn'].includes(type)) return 'category-spell';
  if (['chest', 'ruby_chest'].includes(type)) return 'category-treasure';
  return '';
});

const initializeConsumableSelection = () => {
  console.log('initializeConsumableSelection called with battleInfo:', props.battleInfo);
  console.log('needsConsumableConfirmation:', props.battleInfo.needsConsumableConfirmation);
  console.log('availableConsumables:', props.battleInfo.availableConsumables);

  if (battleFinalized.value) {
    console.log('Battle already finalized, skipping consumable selection');
    setShowConsumableSelection(false, 'battle already finalized');
    return;
  }

  if (!props.battleInfo.reward && props.battleInfo.monster && props.battleInfo.monsterType) {
    console.log('No reward in battleInfo, but monster exists. This might be a bug.');
  }

  if (props.battleInfo.needsConsumableConfirmation && (props.battleInfo.availableConsumables || props.battleInfo.canSacrificeHp)) {
    const didNotWin = props.battleInfo.result !== 'win';
    console.log('Player did not win:', didNotWin, 'result:', props.battleInfo.result);

    // Karak: Regent — the duel challenger decides on every damage spell he has (there is no total to beat yet)
    if (isDuelChallenger.value) {
      const consumablesWithDamage = (props.battleInfo.availableConsumables || []).filter(item => getItemTypeDamage(item.type) > 0);
      const canBoost = consumablesWithDamage.length > 0 || props.battleInfo.canSacrificeHp;
      setShowConsumableSelection(canBoost, 'duel challenger');
      availableConsumables.value = consumablesWithDamage;
      selectedConsumables.value = [];
      return;
    }

    if (didNotWin) {
      const currentDamage = (props.battleInfo.diceRollDamage || 0) + weaponDamageTotal.value + rollBonus.value;
      const consumablesWithDamage = (props.battleInfo.availableConsumables || []).filter(item => getItemTypeDamage(item.type) > 0);
      // Warlock "Sacrifice" counts as one more +1 the player may add
      const sacrificeMax = props.battleInfo.canSacrificeHp ? 1 : 0;
      console.log('Current damage:', currentDamage, 'consumables with damage:', consumablesWithDamage, 'sacrifice:', sacrificeMax);

      if (consumablesWithDamage.length === 0 && sacrificeMax === 0) {
        console.log('No damage-dealing consumables available, skipping consumables interface');
        setShowConsumableSelection(false, 'no damage-dealing consumables available');
        return;
      }

      const maxConsumableDamage = consumablesWithDamage.reduce((total, item) => total + getItemTypeDamage(item.type), 0) + sacrificeMax;
      const maxPossibleDamage = currentDamage + maxConsumableDamage;

      if (maxPossibleDamage > props.battleInfo.monster) {
        console.log(`Consumables could change outcome: ${maxPossibleDamage} damage vs ${props.battleInfo.monster} HP`);
        setShowConsumableSelection(true, 'maxPossibleDamage > monster');
        availableConsumables.value = consumablesWithDamage;
        selectedConsumables.value = [];
        return;
      }

      if (maxPossibleDamage >= props.battleInfo.monster) {
        console.log(`Consumables could achieve draw: ${maxPossibleDamage} damage vs ${props.battleInfo.monster} HP`);
        setShowConsumableSelection(true, 'maxPossibleDamage >= monster (draw)');
        availableConsumables.value = consumablesWithDamage;
        selectedConsumables.value = [];
        return;
      }

      console.log(`Consumables can't change outcome: ${maxPossibleDamage} damage vs ${props.battleInfo.monster} HP, skipping interface`);
      setShowConsumableSelection(false, "consumables can't change outcome");
      return;
    }

    console.log('Player won initially, no consumable selection needed');
    setShowConsumableSelection(false, 'player already won');
  } else {
    console.log('No consumable confirmation needed or no available consumables');
    setShowConsumableSelection(false, 'no confirmation needed or no consumables in battleInfo');
  }
};

watch(() => props.battleInfo, (newBattleInfo, oldBattleInfo) => {
  if (!oldBattleInfo || newBattleInfo?.battleId !== oldBattleInfo?.battleId) {
    battleFinalized.value = false;
  }
  initializeConsumableSelection();
}, { immediate: true });

const toggleConsumable = (item) => {
  const index = selectedConsumables.value.indexOf(item.itemId);
  if (index === -1) {
    selectedConsumables.value.push(item.itemId);
    const newTotalDamage = (props.battleInfo.diceRollDamage || 0) + weaponDamageTotal.value + consumableDamageTotal.value;
    console.log(`Toggled consumable ${item.name || item.type}. New total damage: ${newTotalDamage} vs monster HP: ${props.battleInfo.monster}`);

    if (newTotalDamage > props.battleInfo.monster &&
        newTotalDamage - getItemTypeDamage(item.type) <= props.battleInfo.monster) {
      console.log('This selection would lead to victory!');
      nextTick(() => {
        console.log('Re-evaluating computed properties after crossing victory threshold');
      });
    }
  } else {
    selectedConsumables.value.splice(index, 1);
    const newTotalDamage = (props.battleInfo.diceRollDamage || 0) + weaponDamageTotal.value + consumableDamageTotal.value;
    console.log(`Removed consumable ${item.name || item.type}. New total damage: ${newTotalDamage} vs monster HP: ${props.battleInfo.monster}`);

    if (newTotalDamage <= props.battleInfo.monster &&
        newTotalDamage + getItemTypeDamage(item.type) > props.battleInfo.monster) {
      console.log('This deselection would prevent victory!');
    }
  }
};

const finalizeBattleWithConsumables = () => {
  if (isProcessing.value) return;
  if (!props.battleInfo.battleId) {
    console.error('No battleId available, cannot finalize battle');
    return;
  }
  isProcessing.value = true;
  battleFinalized.value = true;
  emit('finalize-battle', {
    battleId: props.battleInfo.battleId,
    selectedConsumableIds: selectedConsumables.value,
    sacrificeHp: sacrificeHp.value
  });
  setShowConsumableSelection(false, 'finalizeBattleWithConsumables reset');
  selectedConsumables.value = [];
  availableConsumables.value = [];
};

const finalizeBattleWithoutConsumables = () => {
  if (isProcessing.value) return;
  isProcessing.value = true;
  battleFinalized.value = true;
  emit('finalize-battle', {
    battleId: props.battleInfo.battleId,
    selectedConsumableIds: []
  });
  setShowConsumableSelection(false, 'finalizeBattleWithoutConsumables reset');
  selectedConsumables.value = [];
  availableConsumables.value = [];
};

const finalizeBattleAndPickUp = () => {
  if (isProcessing.value) return;
  console.log('🟢 Green button clicked - finalizeBattleAndPickUp called');
  console.log('Battle ID:', props.battleInfo.battleId);
  console.log('Selected consumables:', selectedConsumables.value);
  console.log('Replace item ID:', selectedItemForReplacement.value?.itemId);

  isProcessing.value = true;
  battleFinalized.value = true;

  const eventData = {
    battleId: props.battleInfo.battleId,
    selectedConsumableIds: selectedConsumables.value,
    sacrificeHp: sacrificeHp.value,
    replaceItemId: selectedItemForReplacement.value?.itemId
  };

  console.log('Emitting finalize-battle-and-pick-up with data:', eventData);
  emit('finalize-battle-and-pick-up', eventData);
};

const finalizeBattleAndLeaveItem = () => {
  if (isProcessing.value) return;
  isProcessing.value = true;
  battleFinalized.value = true;
  emit('finalize-battle', {
    battleId: props.battleInfo.battleId,
    selectedConsumableIds: selectedConsumables.value,
    sacrificeHp: sacrificeHp.value,
    hideModalImmediately: true
  });
  setShowConsumableSelection(false, 'finalizeBattleAndLeaveItem reset');
  selectedConsumables.value = [];
  availableConsumables.value = [];
};

// Valduk "Tireless": the fight is won with spells, the loot stays and he keeps moving
const finalizeBattleAndContinueTireless = () => {
  if (isProcessing.value) return;
  isProcessing.value = true;
  battleFinalized.value = true;
  emit('finalize-battle', {
    battleId: props.battleInfo.battleId,
    selectedConsumableIds: selectedConsumables.value,
    sacrificeHp: sacrificeHp.value,
    hideModalImmediately: true,
    tireless: true
  });
  setShowConsumableSelection(false, 'finalizeBattleAndContinueTireless reset');
  selectedConsumables.value = [];
  availableConsumables.value = [];
};

const pickUpAndEndTurn = () => {
  if (isProcessing.value) return;
  isProcessing.value = true;
  emit('pick-item-and-end-turn');
};

const confirmReplacement = () => {
  if (isProcessing.value) return;
  if (!selectedItemForReplacement.value) return;

  isProcessing.value = true;

  if (hasBoost.value) {
    console.log('Confirming replacement with consumables:', selectedConsumables.value, 'sacrifice:', sacrificeHp.value);
    const eventData = {
      battleId: props.battleInfo.battleId,
      selectedConsumableIds: selectedConsumables.value,
    sacrificeHp: sacrificeHp.value,
      replaceItemId: selectedItemForReplacement.value.itemId,
      hideModalImmediately: true
    };
    emit('finalize-battle-and-pick-up', eventData);
    selectedConsumables.value = [];
    availableConsumables.value = [];
  } else {
    emit('pick-item-with-replacement', selectedItemForReplacement.value.itemId);
  }

  showInventorySelection.value = false;
  inventoryForSelection.value = [];
  selectedItemForReplacement.value = null;
  pendingPickupItem.value = null;
  setShowConsumableSelection(false, 'confirmReplacement reset after replacement');
  availableConsumables.value = [];
};

// one click on a card of the full-pack plaque: that item goes, the reward takes its place
const replaceAndTake = (item) => {
  selectedItemForReplacement.value = item;
  confirmReplacement();
};

const rewardCategory = computed(() => {
  const type = (pendingPickupItem.value || props.battleInfo?.reward)?.type;
  if (type === 'key') return 'key';
  if (['dagger', 'sword', 'axe'].includes(type)) return 'weapon';
  if (['fireball', 'teleport', 'frost_fist', 'dark_thorn'].includes(type)) return 'spell';
  return 'treasure';
});

const leaveItemAndEndTurn = () => {
  if (isProcessing.value) return;
  console.log('leaveItemAndEndTurn called with battleInfo:', props.battleInfo);
  isProcessing.value = true;

  if (props.battleInfo.battleId &&
      (props.battleInfo.result === 'lose' || props.battleInfo.result === 'draw' || props.battleInfo.result === 'win')) {
    console.log('Finalizing battle for result:', props.battleInfo.result);
    battleFinalized.value = true;
    emit('finalize-battle', {
      battleId: props.battleInfo.battleId,
      selectedConsumableIds: []
    });
  } else {
    console.log('Ending turn without finalization');
    emit('end-turn');
  }
};

const handleRetreat = () => {
  if (isProcessing.value) return;
  console.log('handleRetreat called');
  isProcessing.value = true;

  if (props.battleInfo.battleId) {
    battleFinalized.value = true;
    emit('finalize-battle', {
      battleId: props.battleInfo.battleId,
      selectedConsumableIds: []
    });
  } else {
    emit('end-turn');
  }
};

const showInventoryFullSelection = (inventory, item) => {
  isProcessing.value = false;
  showInventorySelection.value = true;
  inventoryForSelection.value = inventory || [];
  pendingPickupItem.value = item;
  selectedItemForReplacement.value = null;
  setShowConsumableSelection(false, 'showInventoryFullSelection hides consumable selection');
  try { console.log('[BattleReportModal] Inventory full selection opened.'); } catch { /* logging must never break the fight */ }
};

const showFinalizeBattleInventoryFullSelection = (inventory) => {
  console.log('showFinalizeBattleInventoryFullSelection called with inventory:', inventory);
  isProcessing.value = false;
  showInventorySelection.value = true;
  inventoryForSelection.value = inventory || [];
  selectedItemForReplacement.value = null;
  setShowConsumableSelection(false, 'showFinalizeBattleInventoryFullSelection hides consumable selection');
  console.log('Keeping selected consumables for later:', selectedConsumables.value);
  try { console.log('[BattleReportModal] Finalize battle inventory selection opened.'); } catch { /* logging must never break the fight */ }
};

// Keyboard handler
const onKeyDown = (e) => {
  if (isRolling.value || isProcessing.value || curseChoicePending.value) return;
  // the full-pack plaque handles its own keys (Esc leaves the find)
  if (showInventorySelection.value) return;

  // Karak: Regent — a duel side has one main action
  if (duel.value) {
    if (e.key === 'Enter') {
      duelPrimaryAction();
      e.preventDefault();
      e.stopPropagation();
    } else if ((e.key === 'r' || e.key === 'R' || e.key === 'к' || e.key === 'К') && duelAwaitsDecision.value && props.battleInfo?.canReroll) {
      rerollDice();
      e.preventDefault();
      e.stopPropagation();
    }
    return;
  }

  if (e.key === 'Enter') {
    const bi = props.battleInfo;
    if (bi.result === 'win' && bi.reward && isKeyReward.value && !props.hasInventorySpace && !showInventorySelection.value && !showConsumableSelection.value && !battleFinalized.value) {
      leaveItemAndEndTurn();
    } else if (bi.result === 'win' && bi.reward && isGuardChestReward.value && !showInventorySelection.value && !showConsumableSelection.value) {
      pickUpAndEndTurn();
    } else if (bi.result === 'win' && bi.reward && !showInventorySelection.value && !showConsumableSelection.value) {
      pickUpAndEndTurn();
    } else if (showConsumableSelection.value) {
      if (selectedConsumables.value.length > 0 && beatsMonster(totalCalculatedDamage.value)) {
        finalizeBattleAndPickUp();
      } else if (selectedConsumables.value.length > 0 && totalCalculatedDamage.value === bi.monster) {
        finalizeBattleWithConsumables();
      } else {
        finalizeBattleWithoutConsumables();
      }
    } else if ((bi.result === 'lose' || bi.result === 'draw') && !showInventorySelection.value) {
      handleRetreat();
    } else if (showInventorySelection.value) {
      if (selectedItemForReplacement.value) {
        confirmReplacement();
      }
    } else {
      leaveItemAndEndTurn();
    }
    e.preventDefault();
    e.stopPropagation();
  } else if (e.key === 'Escape') {
    leaveItemAndEndTurn();
    e.preventDefault();
    e.stopPropagation();
  } else if ((e.key === 'r' || e.key === 'R' || e.key === 'к' || e.key === 'К') && props.battleInfo?.canReroll && !battleFinalized.value) {
    rerollDice();
    e.preventDefault();
    e.stopPropagation();
  }
};

onMounted(() => window.addEventListener('keydown', onKeyDown, true));
onUnmounted(() => window.removeEventListener('keydown', onKeyDown, true));

defineExpose({
  showInventoryFullSelection,
  showFinalizeBattleInventoryFullSelection
});

watch(() => showInventorySelection.value, (now) => {
  if (now) {
    isProcessing.value = false;
  }
});

// Helper functions (names come from formatItemName in @/utils/itemUtils, localized)
const getCorrectItemName = (item) => {
  if (!item) return t('common.unknown');
  return formatItemName(item.type || item.name);
};

const formattedItemName = computed(() => {
  const reward = props.battleInfo.reward;
  if (!reward || !(reward.type || reward.name)) return '';
  // the type names the item; the name may be the monster that dropped it
  return formatItemName(reward.type || reward.name);
});



const displayItemImage = computed(() => (props.battleInfo.reward ? getItemImage({ type: props.battleInfo.reward.type }) : null));




const DEFAULT_HERO_SPRITE = '/images/player.webp';
const heroSpriteFailed = ref(false);
watch(() => props.heroKey, () => { heroSpriteFailed.value = false; });
const heroSprite = computed(() => (
  (!heroSpriteFailed.value && props.heroKey && getHero(props.heroKey).image) || DEFAULT_HERO_SPRITE
));
const onHeroSpriteError = () => {
  if (!heroSpriteFailed.value) {
    console.warn('[theme] hero portrait missing, using default sprite:', heroSprite.value);
    heroSpriteFailed.value = true;
  }
};

const displayMonsterImage = computed(() => {
  if (!props.battleInfo) return null;
  const battleData = {
    monster_name: props.battleInfo.monsterType || '',
    monster: props.battleInfo.monster || 0
  };
  return getMonsterImage(battleData);
});



const formattedMonsterName = computed(() => {
  if (!props.battleInfo.monsterType) return t('battle.monster');
  return monsterName(props.battleInfo.monsterType, 'name', props.battleInfo.monster || 0);
});

const getSpellDisplayName = (item) => {
  if (!item) return t('common.unknown');
  const monsterToSpellType = {
    'mummy': 'fireball',
    'giant_spider': 'teleport',
    'giant_rat': 'teleport',
    'skeleton_king': 'fireball',
    'dragon': 'fireball',
    'fallen': 'teleport',
    'skeleton_warrior': 'fireball',
    'skeleton_turnkey': 'teleport',
    'giant_bat': 'dark_thorn',
    'skeleton_mage': 'frost_fist'
  };

  if (['fireball', 'teleport', 'frost_fist', 'dark_thorn'].includes(item.type)) {
    return formatItemName(item.type);
  }
  if (item.name && monsterToSpellType[item.name]) {
    return formatItemName(monsterToSpellType[item.name]);
  }
  if (item.type && ['dagger', 'sword', 'axe'].includes(item.type)) {
    return formatItemName(item.type);
  }
  return formatItemName(item.type || item.name);
};

const shouldShowReward = computed(() => {
  if (!props.battleInfo.reward || duel.value) return false;
  if (props.battleInfo.result === 'win') return true;
  if (showConsumableSelection.value && totalCalculatedDamage.value > props.battleInfo.monster) return true;
  if (props.battleInfo.reward.isPotentialReward) return true;
  return false;
});

const isPotentialReward = computed(() => {
  return props.battleInfo.result !== 'win' &&
         showConsumableSelection.value &&
         potentialVictoryWithConsumables.value;
});



// While the server works on a choice, the pressed button keeps glowing and the others fade
// (instead of all of them turning grey under the loading screen)
const markPressed = (event) => {
  const button = event.target.closest('button');
  if (!button || button.disabled) return;
  event.currentTarget.querySelectorAll('.is-pressed').forEach((b) => b.classList.remove('is-pressed'));
  button.classList.add('is-pressed');
};
watch(isProcessing, (working) => {
  if (!working) document.querySelectorAll('.battle-footer .is-pressed').forEach((b) => b.classList.remove('is-pressed'));
});

// ===== The scene: names, states, the breakdown of the roll, the plaque =====

const heroLabel = computed(() => props.heroLabelText || heroName(props.heroKey, 'short'));
const sceneTitle = computed(() => t('battle.title', { hero: heroLabel.value, foe: duel.value ? rivalLabel.value : formattedMonsterName.value }));

// entrance → idle while the dice roll → the result
const heroState = computed(() => {
  if (isAnimating.value) return 'entrance';
  if (isRolling.value) return 'idle';
  return { win: 'victory', lose: 'defeat', draw: 'draw' }[dynamicResult.value] || '';
});
const foeState = computed(() => {
  if (isAnimating.value) return 'entrance';
  if (isRolling.value) return 'idle';
  return { win: 'defeat', lose: 'victory', draw: 'draw' }[dynamicResult.value] || '';
});

const shownHp = computed(() => (!isRolling.value && (dynamicResult.value === 'lose' || sacrificeHp.value) ? playerHpAfterBattle.value : hpAtFightStart.value));

// What the hero's number is made of: "4 + 2, Dagger +1, Fireball +1"
const breakdown = computed(() => {
  const bi = props.battleInfo || {};
  const parts = [];
  const dice = bi.diceResults || [];
  const diceSum = dice.reduce((a, b) => a + b, 0);
  if (dice.length) parts.push({ text: diceSum === bi.diceRollDamage ? dice.join(' + ') : String(bi.diceRollDamage ?? diceSum) });
  // the same spell twice reads as one part: "Fireball +2"
  const part = (name, n, accent = false) => {
    const same = parts.find((p) => p.name === name && p.accent === accent);
    if (same) {
      same.n += n;
      same.text = t('battle.part', { name, n: same.n });
    } else {
      parts.push({ name, n, text: t('battle.part', { name, n }), accent });
    }
  };
  // a weapon at its own strength; Baron Markul's extra on a sword is its own part ("Sword +2, Sword Master +1")
  equippedWeapons.value.forEach((w) => {
    const base = getItemTypeDamage(w.type);
    part(itemName(w.type), base);
    if (weaponStrength(w) > base) part(t('skills.sword_master.name'), weaponStrength(w) - base);
  });
  if (showConsumableSelection.value) {
    availableConsumables.value
      .filter((item) => selectedConsumables.value.includes(item.itemId))
      .forEach((item) => part(getSpellDisplayName(item), getItemTypeDamage(item.type), true));
    if (sacrificeHp.value) part(t('skills.sacrifice.name'), 1, true);
  } else {
    usedDamageConsumables.value.forEach((item) => part(getCorrectItemName(item), getItemTypeDamage(item.type), true));
    if (bi.hpSacrificed) part(t('skills.sacrifice.name'), 1, true);
  }
  if (farseeingBonus.value) part(t('skills.farseeing.name'), farseeingBonus.value);
  if (berserkBonus.value) part(t('skills.berserk.name'), berserkBonus.value);
  return parts;
});

// "guards: Key" under a monster that guards a reward
const guardNote = computed(() => {
  const reward = props.battleInfo?.reward;
  if (!reward || duel.value) return '';
  return t('battle.guards', { item: itemName(reward.type || reward.name) });
});

const showRewardRow = computed(() => !isRolling.value && !duel.value
  && (shouldShowReward.value || (showConsumableSelection.value && potentialVictoryWithConsumables.value)));

const plaqueTitle = computed(() => {
  if (showInventorySelection.value) return t('battle.chooseReplace');
  if (showConsumableSelection.value) {
    return availableDamageConsumables.value.length > 0 ? t('battle.afterRoll') : t('battle.sacrificePrompt');
  }
  return '';
});
// "7 by itself — one short": the roll without the spells against the foe
const plaqueHint = computed(() => {
  if (!showConsumableSelection.value || isDuelChallenger.value) return '';
  const alone = totalCalculatedDamage.value - consumableDamageTotal.value;
  return t('battle.alone', { n: alone, need: props.battleInfo.monster });
});

if (import.meta.env.DEV) {
  watch([isRolling, showResults, battleFinalized], ([rolling, results, finalized]) => {
    console.debug('[fight] phase', { rolling, results, finalized, result: dynamicResult.value });
  });
}
</script>

<style scoped>
/* ===== The scene: full screen, the field faintly behind ===== */
.battle-overlay {
  position: fixed;
  inset: 0;
  z-index: var(--z-modal);
  overflow-y: auto;
  font-family: var(--font-body);
  color: var(--text-primary);
  background-color: rgba(5, 3, 2, 0.95);
  background-image:
    radial-gradient(ellipse at 22% 45%, rgba(234, 220, 192, 0.12) 0%, rgba(0, 0, 0, 0) 35%),
    radial-gradient(ellipse at 78% 45%, rgba(var(--ember-rgb), 0.16) 0%, rgba(0, 0, 0, 0) 38%),
    radial-gradient(ellipse at 50% 100%, rgba(60, 35, 20, 0.6) 0%, rgba(0, 0, 0, 0) 60%);
  animation: overlayIn 0.35s ease-out;
}

.battle-overlay--lose {
  background-image:
    radial-gradient(ellipse at 22% 45%, rgba(217, 69, 42, 0.12) 0%, rgba(0, 0, 0, 0) 35%),
    radial-gradient(ellipse at 78% 45%, rgba(var(--ember-rgb), 0.2) 0%, rgba(0, 0, 0, 0) 40%),
    radial-gradient(ellipse at 50% 100%, rgba(60, 20, 12, 0.6) 0%, rgba(0, 0, 0, 0) 60%);
}

@keyframes overlayIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.battle-card {
  position: relative;
  min-height: 100%;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  padding: 28px 24px 32px;
  animation: cardIn 0.45s ease-out;
}

@keyframes cardIn {
  from { opacity: 0; transform: scale(0.97) translateY(12px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}

/* Result flash over everything */
.screen-flash {
  position: fixed;
  inset: 0;
  z-index: 2;
  pointer-events: none;
  animation: screenFlash 0.5s ease-out forwards;
}

.flash-victory { background: radial-gradient(circle, rgba(var(--ember-rgb), 0.45) 0%, rgba(var(--ember-rgb), 0) 70%); }
.flash-defeat { background: radial-gradient(circle, rgba(217, 69, 42, 0.45) 0%, rgba(217, 69, 42, 0) 70%); }
.flash-draw { background: radial-gradient(circle, rgba(234, 220, 192, 0.3) 0%, rgba(234, 220, 192, 0) 70%); }

@keyframes screenFlash {
  0% { opacity: 0; }
  15% { opacity: 1; }
  100% { opacity: 0; }
}

/* ===== Header: caption, title, result ===== */
.battle-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  text-align: center;
  text-shadow: 0 2px 6px #000;
}

.header-label {
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.32em;
  text-transform: uppercase;
  color: var(--text-accent);
}

.scene-title {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 44px;
  line-height: 1.05;
  color: var(--text-heading);
}

.result-title {
  margin: 4px 0 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 30px;
  line-height: 1;
  color: var(--text-secondary);
}

.rolling-title {
  color: var(--text-muted);
  animation: titlePulse 1.2s ease-in-out infinite;
}

.victory-title {
  color: var(--color-primary);
  text-shadow: 0 0 18px rgba(var(--ember-rgb), 0.6), 0 2px 6px #000;
}

.defeat-title {
  color: var(--color-danger);
  text-shadow: 0 0 16px rgba(217, 69, 42, 0.5), 0 2px 6px #000;
}

.draw-title {
  color: var(--text-heading);
}

.result-slam {
  animation: resultSlam 0.6s cubic-bezier(0.22, 1, 0.36, 1);
}

@keyframes titlePulse {
  0%, 100% { opacity: 0.6; }
  50% { opacity: 1; }
}

@keyframes resultSlam {
  0% { transform: scale(2.5); opacity: 0; filter: blur(8px); }
  40% { transform: scale(0.88); opacity: 1; filter: blur(0); }
  65% { transform: scale(1.12); }
  85% { transform: scale(0.97); }
  100% { transform: scale(1); }
}

/* ===== The fighters and the roll between them ===== */
.battle-body {
  flex: 1;
  display: grid;
  grid-template-columns: 1fr minmax(320px, 520px) 1fr;
  /* anchored to the top: what appears after the roll (breakdown, notes, the plaque) grows downwards
     and never shifts the dice or the fighters */
  align-items: start;
  justify-items: center;
  gap: 16px;
  padding: 8px 0 16px;
}

.combatant {
  --fighter-img-h: min(390px, 38vh);
}

.hero-hp {
  display: inline-flex;
  gap: 3px;
}

.hp-heart {
  color: var(--color-danger);
  fill: rgba(217, 69, 42, 0.1);
}

.hp-heart.filled {
  fill: var(--color-danger);
}

.hp-loss {
  animation: hpShake 0.5s ease-in-out 2;
}

@keyframes hpShake {
  0%, 100% { transform: translateX(0); }
  20% { transform: translateX(-4px); }
  40% { transform: translateX(4px); }
  60% { transform: translateX(-2px); }
  80% { transform: translateX(2px); }
}

.monster-name-label {
  font-size: 14px;
  font-style: italic;
  color: var(--text-secondary);
}

.roll-centre {
  width: 100%;
  padding-top: 48px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
}

.dice-stage {
  min-height: 108px;
  opacity: 0;
  transition: opacity var(--transition-normal);
}

.dice-stage.dice-visible {
  opacity: 1;
}

.dice-container {
  display: flex;
  gap: 28px;
  padding-top: 30px;
}

.score {
  width: 100%;
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: start;
  gap: 20px;
}

.score > .score__side:only-child {
  grid-column: 1 / -1;
}

.score__side {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  min-width: 0;
}

.score__num {
  font-family: var(--font-display);
  font-size: 144px;
  line-height: 0.85;
  color: var(--text-heading);
  transition: color var(--transition-normal);
}

.score__num--hero.dealt-hidden {
  color: var(--text-muted);
}

.score__num--hero.dealt-win {
  color: var(--text-heading);
  text-shadow: 0 0 22px rgba(var(--ember-rgb), 0.55);
}

.score__num--hero.dealt-lose {
  color: #b7a588;
}

.score__num--foe {
  color: #ff7a2a;
  text-shadow: 0 0 16px rgba(255, 90, 20, 0.6);
}

.score__vs {
  margin-top: 48px;
  font-family: var(--font-display);
  font-size: 40px;
  color: var(--text-muted);
}

.battle-overlay--rolling .score__vs {
  animation: vsPulse 1s ease-in-out infinite;
}

@keyframes vsPulse {
  0%, 100% { transform: scale(1); opacity: 0.7; }
  50% { transform: scale(1.15); opacity: 1; }
}

.score__detail {
  margin-top: 6px;
  font-size: 15px;
  color: var(--text-secondary);
  animation: bonusesSlideIn 0.4s ease-out;
}

.score__detail span {
  white-space: nowrap;
}

.score__part--accent {
  color: var(--text-accent);
}

@keyframes bonusesSlideIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

.damage-bar-center {
  width: min(320px, 90%);
  opacity: 0.5;
}

.hp-bar-reveal {
  opacity: 1;
  animation: hpBarReveal 0.4s ease-out;
}

@keyframes hpBarReveal {
  from { opacity: 0.5; transform: translateY(4px); }
  to { opacity: 1; transform: translateY(0); }
}

.hp-bar-track {
  height: 6px;
  background: rgba(0, 0, 0, 0.6);
  box-shadow: inset 0 0 0 1px rgba(234, 220, 192, 0.2);
  overflow: hidden;
}

.hp-bar-fill {
  height: 100%;
  transition: width 0.8s cubic-bezier(0.22, 1, 0.36, 1);
}

.bar-win { background: var(--color-primary); box-shadow: 0 0 10px rgba(var(--ember-rgb), 0.7); }
.bar-lose { background: var(--color-danger); }
.bar-draw { background: var(--text-primary); }

.verdicts {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

/* What a hero's skill did to the roll, told like a line from a chronicle: the skill in ember display type,
   the rest in italic bone, a thin ember rule on each side; no box */
.first-roll-note {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  max-width: 100%;
  font-style: italic;
  font-size: 16px;
  line-height: 1.35;
  color: var(--text-primary);
  text-align: center;
  text-shadow: 0 2px 6px #000;
  animation: sectionFadeIn 0.4s ease-out both;
}

.first-roll-note::before,
.first-roll-note::after {
  content: '';
  flex: 0 0 24px;
  height: 1px;
  /* level with the skill heading */
  align-self: flex-start;
  margin-top: 13px;
  background: linear-gradient(90deg, rgba(var(--ember-rgb), 0) 0%, rgba(var(--ember-rgb), 0.7) 100%);
}

.first-roll-note::after {
  background: linear-gradient(90deg, rgba(var(--ember-rgb), 0.7) 0%, rgba(var(--ember-rgb), 0) 100%);
}

/* the skill is a heading line of its own; the sentence follows below it, no punctuation glued to the display face */
.first-roll-note > span {
  display: block;
}

.first-roll-note :deep(b),
.first-roll-note b {
  display: block;
  margin-bottom: 2px;
  font-family: var(--font-display);
  font-style: normal;
  font-weight: 400;
  font-size: 20px;
  color: var(--text-accent);
  text-shadow: 0 0 10px rgba(var(--ember-rgb), 0.45), 0 2px 4px #000;
}

.first-roll-note s {
  color: var(--text-muted);
}

.first-roll-note--curse {
  color: #e6cdee;
}

.first-roll-note--curse::before {
  background: linear-gradient(90deg, rgba(224, 96, 74, 0) 0%, rgba(224, 96, 74, 0.7) 100%);
}

.first-roll-note--curse::after {
  background: linear-gradient(90deg, rgba(224, 96, 74, 0.7) 0%, rgba(224, 96, 74, 0) 100%);
}

.first-roll-note__icon {
  width: 20px;
  height: 20px;
}

/* ===== The choice plaque ===== */
.choice-plaque {
  width: min(760px, 100%);
  margin: 0 auto;
  padding: 20px 24px;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  gap: 14px;
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.94) 0%, rgba(12, 7, 5, 0.94) 100%);
  border: 1px solid rgba(var(--ember-rgb), 0.4);
  box-shadow: 0 0 40px rgba(var(--ember-rgb), 0.18), 0 12px 30px rgba(0, 0, 0, 0.85);
}

.section-reveal {
  animation: sectionFadeIn 0.4s ease-out;
}

@keyframes sectionFadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

.choice-plaque__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.choice-plaque__title {
  font-family: var(--font-display);
  font-size: 26px;
  color: var(--text-heading);
}

.choice-plaque__hint {
  font-style: italic;
  font-size: 14px;
  color: #a8957a;
}

.consumable-row {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
}

.items-section {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.items-section-title {
  font-size: 14px;
  color: var(--text-secondary);
}

.item-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-height: 44px;
  padding: 6px 12px;
  box-sizing: border-box;
  border: 1px solid rgba(234, 220, 192, 0.2);
  background: rgba(0, 0, 0, 0.35);
  color: var(--text-primary);
  font: inherit;
  font-size: 14px;
}

button.item-chip {
  cursor: pointer;
}

.consumable-kept {
  border-color: rgba(var(--ember-rgb), 0.5);
}

.chip-img {
  width: 28px;
  height: 28px;
  object-fit: contain;
}

.chip-damage,
.chip-value {
  font-family: var(--font-display);
  font-size: 17px;
  color: var(--text-heading);
}

.chip-value {
  color: #d9c08a;
}

.choice-plaque__bottom {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.reward-row {
  /* the reward in the middle of the plaque: the item, and beside it caption, name and bonus */
  align-self: center;
  display: flex;
  align-items: center;
  gap: 16px;
  min-width: 0;
}

.reward-rise {
  animation: rewardRise 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes rewardRise {
  0% { transform: translateY(25px); opacity: 0; }
  60% { transform: translateY(-3px); opacity: 1; }
  100% { transform: translateY(0); opacity: 1; }
}

.reward-row--potential .reward-row__icon {
  animation: pulseBorder 1.4s ease-in-out infinite;
}

@keyframes pulseBorder {
  0%, 100% { filter: brightness(1); }
  50% { filter: brightness(1.35); }
}

.reward-row__icon {
  position: relative;
  width: 80px;
  height: 80px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #d9c08a;
  /* torchlight behind the item, so dark iron reads on the dark plaque */
  background: radial-gradient(circle, rgba(255, 170, 70, 0.28) 0%, rgba(var(--ember-rgb), 0.08) 45%, rgba(0, 0, 0, 0) 70%);
}

.reward-row__img {
  width: 80px;
  height: 80px;
  object-fit: contain;
  filter: brightness(1.25) drop-shadow(0 0 10px rgba(var(--ember-rgb), 0.35)) drop-shadow(0 4px 4px #000);
}

/* the dagger and the axe fill only half of their art */
.reward-row__img--dagger {
  transform: scale(1.5);
}

.reward-row__img--axe {
  transform: scale(1.2);
}

/* caption over the name, like the scene's own captions */
.reward-row__text {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.reward-row__label {
  font-weight: 600;
  font-size: 12px;
  letter-spacing: 0.3em;
  text-transform: uppercase;
  color: var(--text-accent);
}

.reward-row__name {
  font-family: var(--font-display);
  font-size: 28px;
  line-height: 1;
  color: var(--text-heading);
  text-shadow: 0 2px 6px #000;
}

.reward-row__stats {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.stat-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-family: var(--font-display);
  font-size: 20px;
  line-height: 1;
  color: var(--text-heading);
}

.stat-badge :deep(svg) {
  color: var(--text-accent);
}

.badge-value {
  color: #d9c08a;
}

.reward-note {
  font-style: italic;
  font-size: 14px;
  color: var(--text-accent);
}

.victory-sparkles {
  position: absolute;
  inset: 50% auto auto 50%;
  pointer-events: none;
}

.sparkle {
  position: absolute;
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: var(--text-accent);
  box-shadow: 0 0 6px var(--color-primary);
  animation: sparkleFloat 1.1s ease-out forwards;
}

@keyframes sparkleFloat {
  0% { transform: translate(0, 0) scale(0); opacity: 1; }
  50% { opacity: 1; }
  100% { transform: translate(var(--sparkle-x), var(--sparkle-y)) scale(1.2); opacity: 0; }
}

/* ===== Buttons: an ember plate for the main action, bone-rimmed ones for the rest ===== */
/* the reward on top, the buttons under it across the whole plaque */
.choice-plaque__bottom {
  flex-direction: column;
  align-items: stretch;
  gap: 16px;
}

.battle-footer {
  width: 100%;
}

.footer-reveal {
  animation: footerSlideIn 0.4s ease-out;
}

@keyframes footerSlideIn {
  from { opacity: 0; transform: translateY(8px); }
  to { opacity: 1; transform: translateY(0); }
}

.footer-locked {
  opacity: 0.45;
  pointer-events: none;
}

.button-group {
  display: flex;
  flex-wrap: wrap;
  gap: 30px 12px;
  /* room for the keycaps under the buttons */
  padding-bottom: 26px;
}

.button-group .btn {
  flex: 1 1 0;
  min-width: 220px;
}

.battle-footer .btn {
  position: relative;
  min-height: 58px;
  /* long labels ("Сразиться, победить и взять награду") wrap inside the button instead of spilling out */
  padding: 8px 22px;
  gap: 12px;
  white-space: normal;
  text-align: center;
  line-height: 1.1;
  overflow-wrap: break-word;
  border-radius: 2px;
  font-family: var(--font-display);
  font-size: 21px;
  font-weight: 400;
  letter-spacing: 0.02em;
  transition: box-shadow var(--transition-fast), background var(--transition-fast), color var(--transition-fast);
}

.battle-footer .btn--ember {
  border: 1px solid var(--color-primary);
  background: linear-gradient(180deg, rgba(255, 130, 40, 0.38) 0%, rgba(150, 60, 10, 0.38) 100%);
  color: #ffe2b8;
  text-shadow: 0 1px 2px #000;
  box-shadow: 0 0 26px rgba(var(--ember-rgb), 0.38), inset 0 1px 0 rgba(255, 220, 180, 0.3), inset 0 -2px 6px rgba(0, 0, 0, 0.35);
}

.battle-footer .btn--ember:hover:not(:disabled),
.battle-footer .btn--ember:focus-visible {
  outline: none;
  background: linear-gradient(180deg, rgba(255, 150, 60, 0.5) 0%, rgba(170, 70, 15, 0.5) 100%);
  box-shadow: 0 0 36px rgba(var(--ember-rgb), 0.55), inset 0 1px 0 rgba(255, 220, 180, 0.4), inset 0 -2px 6px rgba(0, 0, 0, 0.35);
}

.battle-footer .btn--ghost {
  border: 1px solid rgba(234, 220, 192, 0.35);
  background: rgba(0, 0, 0, 0.3);
  color: var(--text-primary);
  font-family: var(--font-body);
  font-size: 16px;
  font-weight: 600;
}

.battle-footer .btn--ghost:hover:not(:disabled),
.battle-footer .btn--ghost:focus-visible {
  outline: none;
  border-color: rgba(234, 220, 192, 0.7);
  color: var(--text-heading);
}

.battle-footer .btn--blood {
  border: 1px solid rgba(217, 69, 42, 0.8);
  background: linear-gradient(180deg, rgba(150, 40, 20, 0.45) 0%, rgba(80, 18, 8, 0.45) 100%);
  color: #f6d2c6;
  box-shadow: 0 0 22px rgba(217, 69, 42, 0.3), inset 0 1px 0 rgba(255, 190, 170, 0.2);
}

.btn-strike {
  padding: 0 30px;
  font-size: 24px;
}

/* working on a choice: the pressed button smoulders, the rest fade away (not a grey "disabled" look) */
.footer-working .btn:disabled {
  opacity: 0.15;
  cursor: progress;
}

.footer-working .btn.is-pressed:disabled {
  opacity: 1;
  animation: plateWorking 0.8s ease-in-out infinite alternate;
}

.footer-working .kbd-hint {
  visibility: hidden;
}

@keyframes plateWorking {
  from { box-shadow: 0 0 16px rgba(var(--ember-rgb), 0.3); }
  to { box-shadow: 0 0 34px rgba(var(--ember-rgb), 0.7); }
}

/* the key that does it: a small keycap centred under the button, clear of its label */
.kbd-hint {
  position: absolute;
  top: calc(100% + 8px);
  left: 50%;
  transform: translateX(-50%);
  display: inline-flex;
  align-items: center;
  padding: 2px 7px;
  border: 1px solid currentColor;
  border-bottom-width: 2px;
  border-radius: 3px;
  font-family: var(--font-body);
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.05em;
  line-height: 1.1;
  color: var(--text-muted);
  text-shadow: none;
  pointer-events: none;
}

/* ===== Desktop screens up to ~860px high: the whole scene, the decision included, fits without scrolling ===== */
@media (min-width: 769px) and (max-height: 860px) {
  .battle-card {
    padding: 12px 24px 14px;
  }

  .scene-title {
    font-size: 34px;
  }

  .result-title {
    font-size: 24px;
    margin-top: 0;
  }

  .battle-body {
    gap: 8px;
    padding: 2px 0 8px;
  }

  .combatant {
    --fighter-img-h: min(280px, 27vh);
  }

  .roll-centre {
    padding-top: 6px;
    gap: 6px;
  }

  .dice-stage {
    min-height: 76px;
  }

  .dice-container {
    --die-size: 52px;
  }

  .score__num {
    font-size: 104px;
  }

  .score__vs {
    margin-top: 30px;
    font-size: 30px;
  }

  /* skill notes side by side instead of a column */
  .verdicts {
    flex-direction: row;
    flex-wrap: wrap;
    justify-content: center;
    gap: 2px 24px;
  }

  .first-roll-note {
    font-size: 14px;
  }

  .first-roll-note :deep(b),
  .first-roll-note b {
    font-size: 17px;
  }

  .choice-plaque {
    padding: 12px 20px;
    gap: 8px;
  }

  .choice-plaque__title {
    font-size: 22px;
  }

  .reward-row__icon,
  .reward-row__img {
    width: 56px;
    height: 56px;
  }

  .reward-row__name {
    font-size: 22px;
  }
}

/* ===== Phones: the decision buttons stay at the bottom of the screen while the scene scrolls above them;
   no keycaps on touch screens ===== */
@media (max-width: 768px) {
  .battle-footer {
    position: sticky;
    bottom: 0;
    z-index: 3;
    padding: 12px 0 10px;
    background: linear-gradient(180deg, rgba(12, 7, 5, 0) 0%, rgba(12, 7, 5, 0.94) 22%, rgba(12, 7, 5, 0.98) 100%);
  }

  .button-group {
    padding-bottom: 0;
    gap: 10px;
  }
}

@media (hover: none), (max-width: 600px) {
  .kbd-hint {
    display: none;
  }
}

/* ===== Phones and low screens: fighters smaller, side by side, the plaque full width ===== */
@media (max-width: 768px), (max-height: 500px) {
  .battle-card {
    padding: 16px 12px 16px;
  }

  .header-label {
    font-size: 11px;
  }

  .scene-title {
    font-size: 26px;
    padding: 0 48px;
  }

  .result-title {
    font-size: 22px;
  }

  .battle-body {
    grid-template-columns: 1fr 1fr;
    grid-template-areas: "hero foe" "roll roll";
    padding: 8px 0 12px;
    gap: 8px;
  }

  .hero-side { grid-area: hero; }
  .monster-side { grid-area: foe; }
  .roll-centre { grid-area: roll; gap: 10px; padding-top: 0; }

  .combatant {
    --fighter-w: 100%;
    --fighter-img-w: 120px;
    --fighter-img-h: 150px;
    --fighter-name: 20px;
  }

  .dice-container {
    --die-size: 44px;
  }

  .score__num {
    font-size: 76px;
  }

  .score {
    gap: 10px;
  }

  .score__detail span {
    white-space: normal;
  }

  .score__vs {
    margin-top: 22px;
    font-size: 26px;
  }

  .choice-plaque {
    padding: 14px;
  }

  .choice-plaque__bottom {
    flex-wrap: wrap;
  }

  .battle-footer,
  .button-group {
    width: 100%;
  }

  .button-group .btn {
    flex: 1 1 auto;
  }
}

@media (max-height: 500px) and (min-width: 600px) {
  .battle-body {
    grid-template-columns: 1fr minmax(240px, 320px) 1fr;
    grid-template-areas: "hero roll foe";
  }
}

/* Phones up to ~860px high: a smaller scene so the reward shows above the sticky buttons */
@media (max-width: 768px) and (max-height: 900px) {
  .combatant {
    --fighter-img-h: 104px;
  }

  .score__num {
    font-size: 60px;
  }

  .score__vs {
    margin-top: 16px;
    font-size: 22px;
  }

  .dice-container {
    --die-size: 38px;
  }

  .dice-stage {
    min-height: 56px;
  }

  .first-roll-note {
    font-size: 13px;
  }

  .first-roll-note :deep(b),
  .first-roll-note b {
    font-size: 15px;
  }
}
</style>
