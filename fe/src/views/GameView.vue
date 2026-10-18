<template>
  <div class="game-container">
    <!-- Use the extracted Loading components -->
    <LoadingScreen v-if="!gameData && loading" />
    <LoadingOverlay
      v-if="gameData && loading"
      :status="loadingStatus"
    />
    <ErrorDisplay
      v-if="error"
      :error-message="error"
    />
    <!-- An online table before the start (or closed by its host): the waiting room over everything -->
    <TableRoom
      v-if="isTableRoom"
      :game-id="id"
      :refresh-key="tableRefreshKey"
      @changed="loadGameData(false)"
    />
    <!-- An online game: my turn ran out and the AI plays for me until I take my seat back -->
    <div
      v-if="isOnline && mySeatAway && !isTableRoom"
      class="away-plaque"
      role="status"
      data-testid="away-plaque"
    >
      <strong class="away-plaque__title">{{ t('tables.awayTitle') }}</strong>
      <span class="away-plaque__text">{{ t('tables.awayText') }}</span>
      <button
        type="button"
        class="btn btn--ember btn--sm"
        data-testid="away-back"
        @click="takeSeatBack"
      >
        {{ t('tables.back') }}
      </button>
    </div>
    <!-- An online game: the push stream to the server is down and coming back -->
    <div
      v-if="isOnline && pushReconnecting"
      class="push-chip"
      role="status"
      data-testid="push-reconnecting"
    >
      {{ t('tables.reconnecting') }}
    </div>

    <div
      v-else-if="gameData"
      class="game-interface"
      :class="{ 'game-finished': gameData?.state?.status === 'finished' }"
    >
      <!-- The HUD over the full-bleed dungeon. It comes first in the DOM: its banner <h3> "Turn: N" must be the page's first h3 -->
      <GameHud :class="{ 'game-hud--under-fight': showBattleReportModal }">
        <template #banner>
          <TurnBanner
            :turn="gameData?.state?.turn || null"
            :headline="bannerHeadline"
            :sub="bannerSub"
            :regent="!!gameData?.state?.regent"
            :deadline="turnDeadlineLocal"
          />
        </template>
        <template #notice>
          <!-- Transient notice for a rejected action (the game stays on screen) -->
          <transition name="action-notice">
            <div
              v-if="actionNotice"
              class="action-notice"
              role="status"
              data-testid="action-notice"
            >{{ actionNotice }}</div>
          </transition>
        </template>
        <template
          v-if="gameStarted || ['started', 'turn_in_progress', 'finished'].includes(gameData?.state?.status)"
          #rail
        >
          <PartyRail
            :entries="turnOrder.entries.value"
            :portrait="playerAvatar"
            :hero-tooltip="heroTooltip"
            :hero-short-name="heroShortName"
            :treasure="calculatePlayerTreasure"
            :starting-roll="startingRollShown"
            :is-ai="(player) => isVirtualPlayer(player.id)"
            @portrait-error="onAvatarError"
          />
        </template>
        <template
          v-if="humanPlayer"
          #pack
        >
          <PackBar
            :inventory="humanPlayer.inventory || {}"
            :hero-flags="humanPlayer.heroFlags || {}"
            :castable="(item) => isCastableSpell(item) && isPlayerTurn && humanPlayer.id === currentPlayerId"
            :active-item-id="activeSpellItemId"
            @cast="handleSpellClick"
          />
        </template>
        <template
          v-if="gameData?.players && (gameStarted || ['started', 'turn_in_progress'].includes(gameData?.state?.status))"
          #rings
        >
          <ActionRings :waiting-text="ringsWaitingText">
            <template
              v-if="(isPlayerTurn && (gameData?.state?.sprint || isListenMode || astralHint || fountainHint)) || healLimitOpen || (devAiLabels && !isPlayerTurn && isVirtualPlayer(gameData?.state?.currentPlayerId))"
              #above
            >
              <span
                v-if="isPlayerTurn && gameData?.state?.sprint"
                class="hud-chip sprint-badge"
                data-testid="sprint-badge"
              >{{ t('hud.sprintNotice') }}</span>
              <!-- what a skill does right now, as for Sprint: Listening armed, Astral Walking available -->
              <span
                v-if="isPlayerTurn && fountainHint && !pickedTileId && !gameData?.state?.sprint"
                class="hud-chip sprint-badge"
                data-testid="fountain-badge"
              >{{ getCurrentPlayerData?.cursed ? t('hud.fountainCursedNotice') : t('hud.fountainNotice') }}</span>
              <span
                v-if="isPlayerTurn && isListenMode"
                class="hud-chip sprint-badge"
                data-testid="listen-badge"
              >{{ t('hud.listenNotice') }}</span>
              <span
                v-if="isPlayerTurn && astralHint && !isListenMode && !gameData?.state?.sprint"
                class="hud-chip sprint-badge"
                data-testid="astral-badge"
              >{{ t('hud.astralNotice') }}</span>
              <label
                v-if="healLimitOpen && getCurrentPlayerData?.heroFlags?.choosesHealing"
                class="hud-chip heal-limit-label"
              >
                {{ t('hud.healUpTo') }}
                <select
                  data-testid="heal-limit"
                  :value="getCurrentPlayerData.healLimit ?? ''"
                  :disabled="loading || isRequestInProgress"
                  @change="handleHealLimitChange($event.target.value); healLimitOpen = false"
                >
                  <option value="">{{ t('hud.healFull') }}</option>
                  <option
                    v-for="hp in [4, 3, 2, 1]"
                    :key="`heal-limit-${hp}`"
                    :value="hp"
                  >
                    {{ t('common.hpCount', hp) }}
                  </option>
                </select>
              </label>
            </template>
            <!-- Hero skills of the current turn (Karak: Regent heroes, the Warlock); they keep .end-turn-btn and come before End Turn in the DOM -->
            <IronRing
              v-if="canMagicSwapNow && !isMagicSwapMode"
              class="end-turn-btn magic-swap-btn"
              icon="swap"
              :label="t('hud.ring.magicSwap')"
              data-testid="magic-swap"
              :disabled="loading || isRequestInProgress"
              :title="t('hud.ring.magicSwapTitle')"
              @click="isMagicSwapMode = true"
            />
            <IronRing
              v-if="canLightningAttackNow && !isLightningMode"
              class="end-turn-btn magic-swap-btn"
              icon="lightning"
              :label="t('hud.ring.lightning')"
              data-testid="lightning-attack-btn"
              :disabled="loading || isRequestInProgress"
              :title="t('hud.ring.lightningTitle')"
              @click="isLightningMode = true"
            />
            <IronRing
              v-if="canSprintNow && !isLightningMode"
              class="end-turn-btn magic-swap-btn"
              icon="sprint"
              :label="t('hud.ring.sprint')"
              data-testid="sprint-btn"
              :disabled="loading || isRequestInProgress"
              :title="t('hud.ring.sprintTitle')"
              @click="handleDeclareSprint"
            />
            <IronRing
              v-if="canListenNow && !isLightningMode"
              class="end-turn-btn magic-swap-btn"
              :class="{ 'teleport-active': isListenMode }"
              icon="listen"
              :label="isListenMode ? t('hud.ring.listening') : t('hud.ring.listen')"
              :pressed="isListenMode"
              data-testid="listen-toggle"
              :disabled="loading || isRequestInProgress"
              :title="isListenMode ? t('hud.ring.listeningTitle') : t('hud.ring.listenTitle')"
              @click="isListenMode = !isListenMode"
            />
            <IronRing
              v-if="getCurrentPlayerData?.heroFlags?.choosesHealing"
              icon="heart"
              :label="t('hud.ring.healLimit')"
              :badge="getCurrentPlayerData.healLimit ?? 5"
              :pressed="healLimitOpen"
              :title="t('hud.ring.healLimitTitle')"
              @click="healLimitOpen = !healLimitOpen"
            />
            <IronRing
              data-hud-ring="journal"
              icon="journal"
              :label="t('hud.ring.journal')"
              :pressed="journalOpen"
              :title="t('hud.ring.journalTitle')"
              @click="journalOpen = !journalOpen"
            />
            <IronRing
              v-if="isPlayerTurn && !isTeleportMode && !isReincarnationMode && !isMagicSwapMode && !isDarkThornMode"
              class="end-turn-btn"
              :class="{ 'end-turn-btn--heal': fountainHint && !pickedTileId }"
              icon="hourglass"
              :lit="!(loading || isRequestInProgress || !!pickedTileId || duelBlocksEndTurn || !!retreatChoiceForMe)"
              :disabled="loading || isRequestInProgress || !!pickedTileId || duelBlocksEndTurn || !!retreatChoiceForMe"
              :title="pickedTileId ? t('hud.mustPlaceTile') : (duelBlocksEndTurn ? t('hud.finishDuel') : (retreatChoiceForMe ? t('hud.chooseRetreatFirst') : ''))"
              @click="handleManualEndTurn"
            >{{ fateweaverPrompt ? t('hud.ring.chooseToken') : pickedTileId ? t('hud.ring.placeTileFirst') : fountainHint ? t('hud.ring.endTurnHeal') : t('hud.ring.endTurn') }}</IronRing>
            <!-- Off turn: a dim hourglass, deliberately not .end-turn-btn and not "End Turn" (the e2e looks for those) -->
            <IronRing
              v-else-if="!isPlayerTurn && gameData?.state?.status !== 'finished'"
              icon="hourglass"
              :label="t('hud.ring.wait')"
              disabled
            />
            <!-- Dev-only debug plaques (dev builds only; the AI auto-forcing timer works in every build).
                 They keep .end-turn-btn after End Turn in the DOM: the e2e runs against the dev server and its .first() must not change. -->
            <div
              v-if="devAiLabels && !isPlayerTurn && isVirtualPlayer(gameData?.state?.currentPlayerId)"
              class="debug-plaque"
            >
              <button
                v-if="aiStuckDetected"
                class="end-turn-btn debug-plaque-btn"
                :disabled="loading || isRequestInProgress"
                @click="forceAITurn"
              >
                {{ devAiLabels.stuck }}
              </button>
              <small
                v-if="aiStuckDetected"
                class="debug-plaque-note"
              >{{ devAiLabels.stuckNote }}</small>
              <button
                class="end-turn-btn debug-plaque-btn"
                :disabled="loading || isRequestInProgress"
                @click="forceAITurn"
              >
                {{ devAiLabels.execute }}
              </button>
            </div>
          </ActionRings>
        </template>
        <template
          v-if="chronicleLines.length"
          #chronicle
        >
          <Chronicle :lines="chronicleLines" />
        </template>
        <JournalDrawer
          :open="journalOpen"
          :turns="journalTurns"
          @close="closeJournal"
        />
        <template
          v-if="hasDecision"
          #plaque
        >
          <DecisionPlaque>
            <!-- Missing key dialog component -->
            <MissingKeyDialog
              v-if="showMissingKeyDialog"
              :chest-type="missingKeyChestType"
              @close="showMissingKeyDialog = false"
            />

            <!-- Thief "Stealth": fight or sneak past the monster at the destination -->
            <StealthChoiceDialog
              v-if="stealthPrompt"
              :show="!!stealthPrompt"
              :monster="stealthPrompt.monster"
              @choose="handleStealthChoice"
            />

            <!-- Oracle "Fateweaver": pick which of the two drawn tokens is placed on the new room -->
            <FateweaverChoiceDialog
              v-if="fateweaverPrompt"
              :show="!!fateweaverPrompt"
              :choice="fateweaverPrompt.choice"
              @choose="handleFateweaverChoice"
            />

            <!-- Teleport Mode Controls -->
            <div
              v-if="isTeleportMode"
              class="teleport-controls"
            >
              <h4>{{ t('game.portal.title') }}</h4>
              <p>{{ t('game.portal.text') }}</p>
              <div class="portal-targets">
                <button
                  v-for="player in gameData?.players || []"
                  :key="`portal-target-${player.id}`"
                  type="button"
                  class="portal-target btn btn--ghost btn--sm"
                  :class="{ selected: teleportTargetPlayerId === player.id }"
                  :data-testid="`portal-target-${player.id}`"
                  @click="teleportTargetPlayerId = player.id"
                >
                  {{ playerLabel(player) }}
                </button>
              </div>
              <button
                class="cancel-teleport-btn btn btn--ghost btn--block"
                @click="cancelTeleportMode"
              >
                {{ t('game.portal.cancel') }}
              </button>
            </div>

            <!-- Karak: Regent — Dark Thorn: pick the hero who loses 1 HP -->
            <div
              v-if="isDarkThornMode"
              class="teleport-controls dark-thorn-controls"
              data-testid="dark-thorn-banner"
            >
              <h4>{{ t('game.darkThorn.title') }}</h4>
              <p>{{ t('game.darkThorn.text') }}</p>
              <div class="portal-targets">
                <button
                  v-for="player in darkThornTargets"
                  :key="`dark-thorn-target-${player.id}`"
                  type="button"
                  class="portal-target btn btn--ghost btn--sm"
                  :class="{ selected: darkThornTargetId === player.id }"
                  :data-testid="`dark-thorn-target-${player.id}`"
                  @click="darkThornTargetId = player.id"
                >
                  {{ playerLabel(player) }} <GameIcon name="heart" :size="13" /> {{ player.hp }}
                </button>
              </div>
              <button
                class="end-turn-btn btn btn--ember btn--block"
                data-testid="dark-thorn-confirm"
                :disabled="!darkThornTargetId || loading || isRequestInProgress"
                @click="castDarkThorn"
              >
                {{ t('game.darkThorn.cast') }}
              </button>
              <button
                class="cancel-teleport-btn btn btn--ghost btn--block"
                @click="cancelDarkThornMode"
              >
                {{ t('common.cancel') }}
              </button>
            </div>

            <!-- Warrior "Reincarnation" Controls -->
            <div
              v-if="isReincarnationMode"
              class="teleport-controls reincarnation-controls"
              data-testid="reincarnation-banner"
            >
              <h4>{{ t('game.reincarnation.title') }}</h4>
              <p>{{ t('game.reincarnation.text') }}</p>
            </div>

            <!-- Curse of Karak: the choice is still open (for example after a page reload) -->
            <div
              v-if="curseChoicePendingForMe && !showBattleReportModal"
              class="teleport-controls curse-controls"
              data-testid="curse-banner"
            >
              <CurseChoicePanel
                :players="curseChoice.players"
                :cursed-player-id="curseChoice.cursedPlayerId"
                :cursed-label="curseChoice.cursedLabel"
                @choose="handleCurseChoice"
              />
            </div>

            <!-- Warlock "Magic Swap" Controls -->
            <div
              v-if="isMagicSwapMode"
              class="teleport-controls magic-swap-controls"
              data-testid="magic-swap-banner"
            >
              <h4>{{ t('game.magicSwap.title') }}</h4>
              <p>{{ t('game.magicSwap.text') }}</p>
              <button
                class="end-turn-btn btn btn--ghost btn--block"
                data-testid="magic-swap-cancel"
                :disabled="loading || isRequestInProgress"
                @click="isMagicSwapMode = false"
              >
                {{ t('common.cancel') }}
              </button>
            </div>
            <!-- Karak: Regent — Baron Markul retreats after a Lightning Attack that was not won -->
            <div
              v-if="retreatChoiceForMe && !showBattleReportModal"
              class="teleport-controls retreat-controls"
              data-testid="retreat-banner"
            >
              <h4>{{ t('game.retreat.title') }}</h4>
              <p>{{ t('game.retreat.text') }}</p>
              <div class="portal-targets">
                <button
                  v-for="option in retreatChoiceForMe.options"
                  :key="`retreat-${option}`"
                  type="button"
                  class="portal-target btn btn--ghost btn--sm"
                  :class="{ 'is-hovered': hoveredSkillTarget === option }"
                  :data-testid="`retreat-${option}`"
                  :disabled="loading || isRequestInProgress"
                  @mouseenter="hoveredSkillTarget = option"
                  @mouseleave="hoveredSkillTarget = null"
                  @focus="hoveredSkillTarget = option"
                  @blur="hoveredSkillTarget = null"
                  @click="handleChooseRetreat(option)"
                >
                  {{ retreatOptionLabel(option) }}
                </button>
              </div>
            </div>

            <!-- Karak: Regent heroes — Lightning Attack target -->
            <div
              v-if="isLightningMode"
              class="teleport-controls lightning-controls"
              data-testid="lightning-banner"
            >
              <h4>{{ t('game.lightning.title') }}</h4>
              <p>{{ t('game.lightning.text') }}</p>
              <div class="portal-targets">
                <button
                  v-for="target in lightningTargets"
                  :key="`lightning-${target.position}`"
                  type="button"
                  class="portal-target btn btn--ghost btn--sm"
                  :class="{ 'is-hovered': hoveredSkillTarget === target.position }"
                  :data-testid="`lightning-target-${target.position}`"
                  :disabled="loading || isRequestInProgress"
                  @mouseenter="hoveredSkillTarget = target.position"
                  @mouseleave="hoveredSkillTarget = null"
                  @focus="hoveredSkillTarget = target.position"
                  @blur="hoveredSkillTarget = null"
                  @click="handleLightningAttack(target.position)"
                >
                  {{ target.label }} · <GameIcon name="sword" :size="13" /> {{ target.hp }}
                  <span class="portal-target__where">{{ directionLabel(myFieldPosition, target.position) }}</span>
                </button>
              </div>
              <button
                class="cancel-teleport-btn btn btn--ghost btn--block"
                @click="isLightningMode = false"
              >
                {{ t('common.cancel') }}
              </button>
            </div>
          </DecisionPlaque>
        </template>
        <template
          v-if="isAiPlayingBack || isReplaying"
          #playback
        >
          <!-- AI Turn Playback Controls -->
          <AiTurnPlaybackControls
            v-if="isAiPlayingBack"
            :current-step="aiPlayback.currentStepIndex.value + 1"
            :total-steps="aiPlayback.totalSteps.value"
            :playback-speed="aiPlayback.playbackSpeed.value"
            :current-action="aiPlayback.currentAction.value"
            @speed-change="aiPlayback.setSpeed"
            @skip="aiPlayback.skip"
          />

          <!-- Replay controls -->
          <ReplayControls
            v-if="isReplaying"
            :current-replay-turn="currentReplayTurn"
            :game-turns="gameTurns"
            :replay-speed="replaySpeed"
            @stop-replay="finishReplay"
            @speed-change="changeReplaySpeed"
          />
        </template>
        <template #tools>
          <ZoomControls
            @center-map="centerViewOnMiddle"
            @center-player="centerViewOnCurrentPlayer"
            @center-available="centerViewOnAvailablePlaces"
          />
          <SkillsControl
            :players="gameData?.players || []"
            :my-id="skillsOwnerId"
            :name-of="displayName"
          />
          <LanguageSwitch />
          <SoundControl />
        </template>
      </GameHud>

      <!-- Game finished banner -->
      <div
        v-if="gameData?.state?.status === 'finished'"
        class="game-finished-banner"
      >
        <span class="trophy-icon"><GameIcon name="trophy" :size="22" /></span>
        <span class="finished-lines">
          <span class="finished-text">{{ winnerIds.length > 1 ? t('game.overShared', { who: winnerNames }) : isCurrentUserWinner() ? t('game.overYouWin') : winnerId ? t('game.overWins', { who: heroNameById(winnerId) }) : t('game.overResults') }}</span>
          <!-- ended before the dragon: nobody could catch the leader any more -->
          <span v-if="gameData?.state?.endReason === 'decided'" class="finished-reason">{{ t('game.overDecided') }}</span>
        </span>
        <button
          type="button"
          class="btn btn--ghost btn--sm view-results-button"
          @click="showLeaderboardModal = true"
        >
          {{ t('game.viewLeaderboard') }}
        </button>
      </div>

      <!-- An item on the tile: a centred plaque over the field -->
      <ItemPickupDialog
        v-if="showItemPickupDialog"
        :show="showItemPickupDialog"
        :item="tileItem"
        :leave-ends-turn="pickupLeaveEndsTurn"
        @pickup="handleAutoItemPickup"
        @skip="skipItemAndEndTurn"
      />

      <!-- Inventory full: a centred plaque over the field (a bigger choice than the plaque by the rings) -->
      <InventoryFullDialog
        v-if="showInventoryFullDialog"
        :dropped-item="droppedItem"
        :item-category="itemCategory"
        :inventory-for-category="inventoryForCategory"
        @select-item="selectItemToReplace"
        @replace-item="replaceItem"
        @skip-item="skipItem"
      />

      <!-- Game layout -->
      <div class="game-layout">
        <!-- Game field on the left -->
        <div class="game-main-area">
          <div class="game-board">
            <div class="game-visualization">
              <!-- Render field and tiles if available -->
              <div 
                v-if="gameData && gameData.field" 
                ref="fieldElement" 
                class="game-field"
                :class="{ 
                  'ctrl-pressed': isCtrlPressed, 
                  'dragging': isDragging,
                  'is-replaying': isReplaying,
                  'teleport-mode': isTeleportMode || isReincarnationMode || isMagicSwapMode,
                  'reincarnation-mode': isReincarnationMode,
                  'magic-swap-mode': isMagicSwapMode
                }"
                @mousedown="onMouseDown"
                @mouseleave="onMouseLeave"
              >
                <div
                  class="tiles-container"
                  :style="fieldStyles"
                >
                  <!-- Render available move/place markers before tiles -->
                  <template v-if="gameData.state && gameData.state.availablePlaces && !isPlacingTile && !pickedTileId && !isTeleportMode && !isReincarnationMode && !isMagicSwapMode && !isProcessingAI && !arenaChoiceForMe && gameData.state.status !== 'finished'">
                    <AvailableMoveMarker
                      v-for="position in gameData.state.availablePlaces.moveTo"
                      :key="`move-${position}`"
                      :position="position"
                      :x="parseInt(position.split(',')[0])"
                      :y="parseInt(position.split(',')[1])"
                      :player-x="currentPlayerFieldPosition?.x ?? null"
                      :player-y="currentPlayerFieldPosition?.y ?? null"
                      :can-place-tile="!isProcessingAI && (gameData.state.availablePlaces.placeTile.includes(position) ||
                        (gameData.state.availablePlaces.placeTile.length === 0 &&
                          gameData.state.deck?.remainingTiles > 0 &&
                          !gameData.state.deck?.isEmpty &&
                          !isFieldPlaceAlreadyTaken(position, gameData))) &&
                        !gameData.state.deck?.isEmpty"
                      :is-player-turn="isPlayerTurn"
                      :can-click="isPlayerTurn && !isRequestInProgress"
                      :is-request-in-progress="isRequestInProgress"
                      :min-x="gameData.field.size.minX || 0"
                      :min-y="gameData.field.size.minY || 0"
                      :tile-size="tileSize"
                      @click="(position) => handlePlaceClick(position)"
                    />
                  </template>

                  <!-- Render healing fountain markers in teleport mode -->
                  <!-- Use healingFountainTiles from gameData to ensure ALL healing fountains are shown, excluding current position -->
                  <template v-if="isTeleportMode">
                    <div
                      v-for="tile in teleportableHealingFountains"
                      :key="`teleport-${tile.position}`"
                      class="healing-fountain-marker"
                      :style="{
                        position: 'absolute',
                        left: `${(tile.x - (gameData.field.size.minX || 0)) * tileSize}px`,
                        top: `${(tile.y - (gameData.field.size.minY || 0)) * tileSize}px`,
                        width: `${tileSize}px`,
                        height: `${tileSize}px`,
                        pointerEvents: 'auto',
                        opacity: 1,
                        cursor: 'pointer'
                      }"
                      @click="handleTeleportClick(tile.position)"
                    >
                      <div class="healing-fountain-indicator">
                        <span class="fountain-emoji"><GameIcon name="heart" :size="40" /></span>
                      </div>
                    </div>
                  </template>

                  <!-- Standing on a teleportation gate: dashed lines to the gates it can carry the hero to -->
                  <svg
                    v-if="gateLinks.length"
                    class="gate-links"
                    :width="(gameData.field.size.maxX - gameData.field.size.minX + 1) * tileSize"
                    :height="(gameData.field.size.maxY - gameData.field.size.minY + 1) * tileSize"
                    aria-hidden="true"
                  >
                    <line
                      v-for="link in gateLinks"
                      :key="`gate-${link.to}`"
                      :x1="link.x1"
                      :y1="link.y1"
                      :x2="link.x2"
                      :y2="link.y2"
                      class="gate-link"
                    />
                    <circle
                      v-for="link in gateLinks"
                      :key="`gate-end-${link.to}`"
                      :cx="link.x2"
                      :cy="link.y2"
                      r="9"
                      class="gate-link-end"
                    />
                  </svg>

                  <!-- Lightning Attack targets and retreat tiles, lit on the field as well as listed in the plaque -->
                  <div
                    v-for="mark in skillTargetMarks"
                    :key="`skill-mark-${mark.position}`"
                    class="healing-fountain-marker skill-target-marker"
                    :class="[`skill-target-marker--${mark.kind}`, { 'is-hovered': hoveredSkillTarget === mark.position }]"
                    :data-testid="`${mark.kind}-marker-${mark.position}`"
                    :title="mark.title"
                    :style="{
                      position: 'absolute',
                      left: `${(mark.x - (gameData.field.size.minX || 0)) * tileSize}px`,
                      top: `${(mark.y - (gameData.field.size.minY || 0)) * tileSize}px`,
                      width: `${tileSize}px`,
                      height: `${tileSize}px`,
                      pointerEvents: 'auto',
                      cursor: 'pointer'
                    }"
                    @mouseenter="hoveredSkillTarget = mark.position"
                    @mouseleave="hoveredSkillTarget = null"
                    @click="mark.kind === 'lightning' ? handleLightningAttack(mark.position) : handleChooseRetreat(mark.position)"
                  >
                    <div class="healing-fountain-indicator">
                      <GameIcon
                        :name="mark.kind === 'lightning' ? 'lightning' : 'pin'"
                        :size="40"
                      />
                    </div>
                  </div>

                  <!-- Warrior "Reincarnation": healing fountain markers to choose the respawn tile -->
                  <template v-if="isReincarnationMode">
                  <div
                    v-for="tile in reincarnationFountainTiles"
                    :key="`reincarnation-${tile.position}`"
                    class="healing-fountain-marker reincarnation-marker"
                    :data-testid="`reincarnation-target-${tile.position}`"
                    :style="{
                      position: 'absolute',
                      left: `${(tile.x - (gameData.field.size.minX || 0)) * tileSize}px`,
                      top: `${(tile.y - (gameData.field.size.minY || 0)) * tileSize}px`,
                      width: `${tileSize}px`,
                      height: `${tileSize}px`,
                      pointerEvents: 'auto',
                      opacity: 1,
                      cursor: 'pointer'
                    }"
                    @click="handleReincarnationClick(tile.position)"
                  >
                    <div class="healing-fountain-indicator">
                      <span class="fountain-emoji"><GameIcon name="heart" :size="40" /></span>
                    </div>
                  </div>
                  </template>

                  <!-- Render ghost tile if we have one and deck is not empty -->
                  <GhostTile
                    v-if="ghostTilePosition && pickedTile && !(gameData?.state?.deck?.isEmpty) && !isProcessingAI && gameData?.state?.status !== 'finished'"
                    :key="`ghost-${ghostTilePosition}-${ghostTileOrientation}-${pickedTile?.orientation}`"
                    :position="ghostTilePosition"
                    :orientation="ghostTileOrientation"
                    :orientation-symbol="ghostTileOrientationSymbol"
                    :orientation-class="ghostTileOrientationClass"
                    :is-room="pickedTile.room"
                    :is-placing-tile="isPlacingTile"
                    :can-rotate="ghostCanRotate"
                    :min-x="gameData.field.size.minX || 0"
                    :min-y="gameData.field.size.minY || 0"
                    :tile-size="tileSize"
                    :openings="{
                      top: hasOpening(ghostTileOrientation, 'T'),
                      right: hasOpening(ghostTileOrientation, 'R'),
                      bottom: hasOpening(ghostTileOrientation, 'B'),
                      left: hasOpening(ghostTileOrientation, 'L')
                    }"
                    @rotate="rotateGhostTileLocal"
                    @place="handlePlaceClick"
                  />

                  <!-- Replace the processed tiles with GameTile components -->
                  <template v-if="processedTiles && processedTiles.length">
                    <GameTile
                      v-for="tile in processedTiles"
                      :key="`${tile.x}-${tile.y}`"
                      :position="tile.position"
                      :x="tile.x"
                      :y="tile.y"
                      :orientation-char="tile.orientationChar"
                      :orientation-class="tile.orientation"
                      :orientation-symbol="getTileOrientationSymbol(tile.orientationChar, tile.isRoom)"
                      :is-room="tile.isRoom"
                      :is-starting-tile="tile.x === 0 && tile.y === 0"
                      :has-player="tile.hasPlayer"
                      :player-id="tile.playerId"
                      :all-player-ids="tile.allPlayerIds"
                      :player-heroes="playerHeroes"
                      :cursed-player-ids="cursedPlayerIds"
                      :stunned-player-ids="stunnedPlayerIds"
                      :acting-player-id="torchlightActingId"
                      :player-emoji="getPlayerEmoji(tile.playerId)"
                      :has-item="tile.hasItem"
                      :item="tile.item"
                      :item-emoji="getItemEmoji(tile.item)"
                      :item-tooltip="getItemTooltip(tile.item)"
                      :is-replaying="isReplaying"
                      :current-replay-turn="currentReplayTurn"
                      :turn-number="tile.turnNumber"
                      :is-current-action="tile.isCurrentAction"
                      :is-placing-animation="tile.isPlacingAnimation"
                      :is-current-player-position="isPlayerPosition(tile.position)"
                      :min-x="gameData.field.size.minX || 0"
                      :min-y="gameData.field.size.minY || 0"
                      :tile-size="tileSize"
                      :animation-start-x="tile.animationStartX"
                      :animation-start-y="tile.animationStartY"
                      :is-highlighted="highlightedTile === tile"
                      :openings="{
                        top: hasOpening(tile.orientationChar, 'T'),
                        right: hasOpening(tile.orientationChar, 'R'),
                        bottom: hasOpening(tile.orientationChar, 'B'),
                        left: hasOpening(tile.orientationChar, 'L')
                      }"
                      :has-healing-fountain="tile.hasHealingFountain"
                      :has-teleportation-gate="tile.hasTeleportationGate"
                      :has-battle-arena="tile.hasBattleArena"
                      :has-cursed-room="tile.hasCursedRoom"
                      :arena-used="(gameData.field.activatedArenas || []).includes(tile.position)"
                      @highlight="highlightTile"
                      @unhighlight="unhighlightTile"
                      @center-view="centerViewOnTile"
                      @item-click="handleItemClick"
                      :swap-targetable="isMagicSwapMode && !isPlayerPosition(tile.position) && !hasUndefeatedMonster(tile.position)"
                      @player-click="handlePlayerFigureClick"
                    />
                  </template>

                  <!-- Torchlight: darkness outside the heroes' torches (visual only, under the move marks) -->
                  <TorchlightLayer
                    :lights="torchLights"
                    :min-x="gameData.field.size?.minX || 0"
                    :min-y="gameData.field.size?.minY || 0"
                    :cols="fieldCols"
                    :rows="fieldRows"
                    :tile-size="tileSize"
                    :active="torchlightActive"
                  />

                  <!-- A fight on the field (the AI's, or a rival's online): FieldFight over its tile -->
                  <div
                    v-if="aiBattleOverlay"
                    class="ai-battle-overlay"
                    :style="{
                      left: `${(aiBattleOverlay.x - (gameData.field.size.minX || 0)) * tileSize}px`,
                      top: `${(aiBattleOverlay.y - (gameData.field.size.minY || 0)) * tileSize}px`,
                      width: `${tileSize}px`,
                      height: `${tileSize}px`,
                    }"
                  >
                    <FieldFight
                      :key="aiBattleOverlay.key"
                      :hero-key="aiBattleOverlay.heroKey"
                      :monster-type="aiBattleOverlay.monsterType"
                      :monster-hp="aiBattleOverlay.monsterHP"
                      :dice="aiBattleOverlay.diceResults"
                      :total="aiBattleOverlay.playerDamage"
                      :result="aiBattleOverlay.result"
                      :speed="aiBattleOverlay.speed"
                    />
                  </div>

                  <!-- Karak: Regent — a Cursed Room roll on the field (the AI's, or a rival's online): FieldCurseRoll over the room -->
                  <div
                    v-if="aiRoomOverlay"
                    class="ai-battle-overlay"
                    :style="{
                      left: `${(aiRoomOverlay.x - (gameData.field.size.minX || 0)) * tileSize}px`,
                      top: `${(aiRoomOverlay.y - (gameData.field.size.minY || 0)) * tileSize}px`,
                      width: `${tileSize}px`,
                      height: `${tileSize}px`,
                    }"
                  >
                    <FieldCurseRoll
                      :key="aiRoomOverlay.key"
                      :hero-key="aiRoomOverlay.heroKey"
                      :name="aiRoomOverlay.name"
                      :roll="aiRoomOverlay.event.roll"
                      :cursed="!!aiRoomOverlay.event.cursed"
                      :already-cursed="!!aiRoomOverlay.event.alreadyCursed"
                      :speed="aiRoomOverlay.speed"
                      @settled="onAiRoomRollSettled"
                    />
                  </div>

                  <!-- Fog of war overlay for unexplored positions -->
                  <div
                    v-for="fog in fogCells"
                    :key="`fog-${fog.x}-${fog.y}`"
                    :class="['fog-cell', { 'fog-dissolve': fog.dissolving }]"
                    :style="{
                      left: `${(fog.x - (gameData.field.size.minX || 0)) * tileSize - 4}px`,
                      top: `${(fog.y - (gameData.field.size.minY || 0)) * tileSize - 4}px`,
                      width: `${tileSize + 8}px`,
                      height: `${tileSize + 8}px`,
                    }"
                  />

                  <!-- Fog fringe: creeps over tile edges that border unexplored areas -->
                  <div
                    v-for="fringe in fogFringes"
                    :key="`fringe-${fringe.x}-${fringe.y}-${fringe.side}`"
                    :class="['fog-fringe', `fog-fringe-${fringe.side}`]"
                    :style="{
                      left: `${(fringe.x - (gameData.field.size.minX || 0)) * tileSize}px`,
                      top: `${(fringe.y - (gameData.field.size.minY || 0)) * tileSize}px`,
                      width: `${tileSize}px`,
                      height: `${tileSize}px`,
                    }"
                  />

                  <!-- Floating heal text: inside the tiles container, whose coordinates worldX/worldY use -->
                  <div
                    v-if="showHealingNotification && healingNotification"
                    :key="'heal-' + healingNotification.playerId"
                    class="floating-heal-text"
                    :style="{
                      left: `${healingNotification.worldX}px`,
                      top: `${healingNotification.worldY}px`
                    }"
                  >
                    +{{ healingNotification.healAmount }} HP
                  </div>
                </div>
              </div>
              <p v-else>
                {{ t('game.noField', { id }) }}
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Healing notification component -->
    <!-- Healing notification removed - replaced by floating text in tiles-container -->

    <!-- Battle report modal component -->
    <BattleReportModal
      v-if="showBattleReportModal"
      ref="battleReportModalRef"
      :battle-info="battleInfo"
      :hero-key="figureHeroKey(battleRoller)"
      :hero-label-text="displayName(battleRoller)"
      :has-inventory-space="hasInventorySpaceForReward"
      :player-hp="battleRoller?.hp ?? 5"
      :curse-choice="curseChoice"
      :duel-rival="duelRival"
      :hero-flags="battleRoller?.heroFlags || {}"
      @tireless="handleTireless"
      @curse="handleCurseChoice"
      @duel-continue="handleDuelContinue"
      @end-turn="closeBattleReportAndEndTurn"
      @pick-item-and-end-turn="handlePickItemAndEndTurn"
      @pick-item-with-replacement="handlePickItemWithReplacement"
      @finalize-battle="handleFinalizeBattle"
      @finalize-battle-and-pick-up="handleFinalizeBattleAndPickUp"
      @reroll-battle="handleRerollBattle"
    />

    <!-- The results: a centred Torchlight plaque (portraits, names, treasure); e2e hooks .leaderboard-overlay,
         .result-banner.result-win, .lb-entry, .lb-winner, .lb-you, .lb-btn-primary, .lb-btn-secondary -->
    <div
      v-if="showLeaderboardModal"
      class="leaderboard-overlay"
    >
      <section
        class="leaderboard-card"
        role="dialog"
        aria-modal="true"
        :aria-label="t('game.over')"
      >
        <span class="leaderboard-caption">{{ t('game.over') }}</span>
        <div
          v-if="humanPlayerId && !isVirtualPlayer(humanPlayerId)"
          class="result-banner"
          :class="isCurrentUserWinner() || isHotseat ? 'result-win' : 'result-loss'"
        >
          <!-- hot-seat: the screen is everyone's, the banner just crowns the winner -->
          <GameIcon
            :name="isCurrentUserWinner() || isHotseat ? 'trophy' : 'skull'"
            :size="30"
          />
          <span class="result-text">{{ winnerIds.length > 1 ? (isCurrentUserWinner() ? t('game.victoryShared') : t('game.overShared', { who: winnerNames })) : isCurrentUserWinner() ? t('game.victory') : t('game.opponentWinsName', { who: heroNameById(winnerId) }) }}</span>
        </div>

        <ol class="leaderboard-entries">
          <li
            v-for="(entry, idx) in leaderboard"
            :key="entry.playerId"
            class="lb-entry"
            :class="{
              'lb-winner': winnerIds.includes(entry.playerId),
              'lb-you': isCurrentUserEntry(entry),
              'lb-first': idx === 0
            }"
          >
            <span class="lb-rank">{{ idx + 1 }}</span>
            <img
              class="lb-portrait"
              :src="playerAvatar(playerById(entry.playerId))"
              alt=""
              @error="onAvatarError($event, playerById(entry.playerId))"
            >
            <span class="lb-name">
              <span class="lb-hero">{{ heroNameById(entry.playerId) }}</span>
              <span class="lb-role">
                <GameIcon
                  v-if="winnerIds.includes(entry.playerId)"
                  name="crown"
                  :size="13"
                  :title="t('game.winner')"
                />
                {{ [winnerIds.includes(entry.playerId) ? t('game.winner') : '', isCurrentUserEntry(entry) ? t('game.youBadge') : ''].filter(Boolean).join(' · ') }}
              </span>
            </span>
            <span
              class="lb-treasure"
              :title="t('dialogs.lordKarak.treasure')"
            >
              <GameIcon
                name="treasure"
                :size="18"
              /> {{ entry.treasure }}
            </span>
          </li>
        </ol>

        <div class="leaderboard-actions">
          <button
            type="button"
            class="btn btn--ghost lb-btn-secondary"
            @click="reloadPage"
          >
            <GameIcon
              name="eye"
              :size="16"
            /> {{ t('game.viewBoard') }}
            <span class="kbd-hint">Esc</span>
          </button>
          <button
            type="button"
            class="btn btn--ember lb-btn-primary"
            @click="navigateToLobby"
          >
            <GameIcon
              name="home"
              :size="16"
            /> {{ t('game.returnToLobby') }}
            <span class="kbd-hint">Enter</span>
          </button>
        </div>
      </section>
    </div>

    <!-- Stunned: on the stunned hero's turn (skip it), and right after the fight that knocked this hero out —
         that turn ends at once and the next one is skipped by the server, so the plaque waits to be read -->
    <div
      v-if="isCurrentPlayerStunned || showKnockedOut"
      class="stunned-player-overlay"
      :class="{ 'stunned-player-overlay--notice': !isCurrentPlayerStunned }"
    >
      <section
        class="stunned-message"
        role="dialog"
        aria-modal="true"
        :aria-label="t('game.stunned')"
      >
        <img
          class="stunned-player-icon"
          :src="playerAvatar(isCurrentPlayerStunned ? getCurrentPlayerData : (knockedOutPlayer || getCurrentPlayerData))"
          alt=""
          @error="onAvatarError($event, getCurrentPlayerData)"
        >
        <h3>{{ t('game.stunned') }}</h3>
        <p>{{ t('game.stunnedText') }}</p>
        <p class="stunned-heal">{{ t('game.stunnedHeal') }}</p>
        <button
          v-if="isCurrentPlayerStunned"
          type="button"
          class="btn btn--ember skip-turn-button"
          @click="skipStunnedPlayerTurn"
        >
          {{ t('game.skipTurn') }} <span class="kbd-hint">Enter</span>
        </button>
        <button
          v-else
          type="button"
          class="btn btn--ember skip-turn-button stunned-ok-button"
          @click="knockedOut = false"
        >
          {{ t('game.stunnedOk') }}
        </button>
      </section>
    </div>

    <!-- Karak: Regent — Battle Arena: the opener calls a rival out (a scene, before the duel's own) -->
    <DuelOpponentPanel
      v-if="arenaChoiceForMe && !showBattleReportModal && !isAiPlayingBack"
      :players="duelOpponentCandidates"
      :disabled="loading || isRequestInProgress"
      @choose="handleChooseDuelOpponent"
    />

    <!-- Karak: Regent — a Cursed Room: the die and the token -->
    <CursedRoomScene
      v-if="cursedRoomScene && !showBattleReportModal && !isAiPlayingBack"
      :event="cursedRoomScene"
      :name="heroNameById(cursedRoomScene.playerId)"
      :sprite="cursedRoomSceneSprite"
      :auto-close-ms="cursedRoomSceneMine ? 0 : 2200"
      :lost-skills="cursedRoomSceneLostSkills"
      @close="closeCursedRoomScene"
    />

    <!-- Karak: Regent — the current (or last) duel as a scene: the spells before the roll, the dice, the outcome -->
    <!-- after the AI's moves are played back, not over them: they show how the rival got onto the arena; nor while
         the AI's turn is still being played on the server (a state loaded meanwhile already has the duel) -->
    <DuelScene
      v-if="visibleDuel && !showBattleReportModal && !isAiPlayingBack && !aiTurnInFlight"
      :duel="visibleDuel"
      :players="gameData?.players || []"
      :labels="duelPanelPlayers"
      :my-seat-id="mySeatId"
      :online="isOnline"
      :disabled="loading || isRequestInProgress"
      @prepare="handlePrepareDuel"
      @loot="handleTakeDuelLoot"
      @dismiss="dismissDuel"
    />

    <!-- Hot-seat: the screen goes to the next player behind a curtain (over everything, the last player's pack included) -->
    <HotseatCurtain
      v-if="showHotseatCurtain"
      :name="displayName(hotseatSeatToAct.player)"
      :player-name="hotseatSeatToAct.player.username || ''"
      :portrait="playerAvatar(hotseatSeatToAct.player)"
      :reason="hotseatSeatToAct.reason"
      @reveal="revealHotseatSeat"
    />

    <!-- Karak: Regent, Lord Karak variant — shown once per browser when the weakest hero is transformed (after the room's die) -->
    <LordKarakRevealModal
      v-if="visibleLordKarak && lordKarakReady"
      :reveal="visibleLordKarak"
      :players="duelPanelPlayers"
      @close="dismissLordKarak"
    />
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { gameApi } from '@/services/api';

// Import the components
import LoadingScreen from '@/components/game/LoadingScreen.vue';
import GameIcon from '@/components/ui/GameIcon.vue';
import { getHero } from '@/theme';
import LoadingOverlay from '@/components/game/LoadingOverlay.vue';
import ErrorDisplay from '@/components/game/ErrorDisplay.vue';
import InventoryFullDialog from '@/components/game/InventoryFullDialog.vue';
import MissingKeyDialog from '@/components/game/MissingKeyDialog.vue';
import BattleReportModal from '@/components/game/BattleReportModal.vue';
import ZoomControls from '@/components/game/ZoomControls.vue';
import GhostTile from '@/components/game/GhostTile.vue';
import ReplayControls from '@/components/game/ReplayControls.vue';
import AiTurnPlaybackControls from '@/components/game/AiTurnPlaybackControls.vue';
import { useAiTurnPlayback } from '@/composables/useAiTurnPlayback';
import GameTile from '@/components/game/GameTile.vue';
import TorchlightLayer from '@/components/game/TorchlightLayer.vue';
import AvailableMoveMarker from '@/components/game/AvailableMoveMarker.vue';
import ItemPickupDialog from '@/components/game/ItemPickupDialog.vue';
import FateweaverChoiceDialog from '@/components/game/FateweaverChoiceDialog.vue';
import CurseChoicePanel from '@/components/game/CurseChoicePanel.vue';
import DuelOpponentPanel from '@/components/game/DuelOpponentPanel.vue';
import DuelScene from '@/components/game/DuelScene.vue';
import CursedRoomScene from '@/components/game/CursedRoomScene.vue';
import FieldCurseRoll from '@/components/game/FieldCurseRoll.vue';
import LordKarakRevealModal from '@/components/game/LordKarakRevealModal.vue';
import HotseatCurtain from '@/components/game/HotseatCurtain.vue';
import StealthChoiceDialog from '@/components/game/StealthChoiceDialog.vue';
import GameHud from '@/components/game/hud/GameHud.vue';
import TurnBanner from '@/components/game/hud/TurnBanner.vue';
import PartyRail from '@/components/game/hud/PartyRail.vue';
import PackBar from '@/components/game/hud/PackBar.vue';
import ActionRings from '@/components/game/hud/ActionRings.vue';
import IronRing from '@/components/game/hud/IronRing.vue';
import Chronicle from '@/components/game/hud/Chronicle.vue';
import DecisionPlaque from '@/components/game/hud/DecisionPlaque.vue';
import JournalDrawer from '@/components/game/hud/JournalDrawer.vue';
import { chronicleTail, chronicleTurns, describeAction } from '@/utils/chronicle';
import TableRoom from '@/components/game/TableRoom.vue';
import { accountApi } from '@/services/account';
import { tablesApi } from '@/services/tables';
import { subscribe as subscribePush, topics as pushTopics } from '@/services/push';
import { useTurnOrder, joinNames } from '@/composables/useTurnOrder';
import { useI18n } from 'vue-i18n';
import { heroName, monsterName, skillText, itemName } from '@/i18n/names';
import { playerDisplayName } from '@/utils/playerNames';
import SoundControl from '@/components/ui/SoundControl.vue';
import SkillsControl from '@/components/game/hud/SkillsControl.vue';
import FieldFight from '@/components/game/FieldFight.vue';
import { audio } from '@/services/audio/audioEngine';
import { useGameSounds } from '@/composables/useGameSounds';
import { useMusicSituation } from '@/composables/useMusicSituation';
import LanguageSwitch from '@/components/ui/LanguageSwitch.vue';
import { getTileOrientationChar, getTileOrientationClass, processTiles, getTileOrientationSymbol, parseOrientationString, hasOpening, hasMatchingDoors, getOppositeSide, isOpenedSide, getAllAdjacentPositions, getTileOrientationAt, isValidOrientation, getRequiredOpenSide, rotateGhostTile, handleInitialTileOrientation, highlightTile as highlightTileUtil, unhighlightTile as unhighlightTileUtil } from '@/utils/tileUtils';
import { isPlayerInGame as isPlayerInGameUtil, isSecondPlayerInGame as isSecondPlayerInGameUtil, isPlayerTurn as isPlayerTurnUtil, getPlayerEmoji, joinGame as joinGameUtil, generateUUID, getPlayerReady as getPlayerReadyUtil, formatPlayerId, switchPlayer as switchPlayerUtil, autoSwitchPlayer as autoSwitchPlayerUtil } from '@/utils/playerUtils';
import { getItemEmoji, getItemTooltip, formatItemName, getItemImage, SPELL_TYPES, hasRoomForItem, isWorthOffering } from '@/utils/itemUtils';

// Import field utility functions
import { TILE_SIZE, calculateScrollPosition, playerIsAtPosition as isPlayerAt, getPlayerIdAtPosition as getPlayerAt, getAllPlayerIdsAtPosition as getAllPlayersAt, isPlayerPosition as isCurrentPlayerAt, getProcessedAvailablePlaces, centerViewOnCurrentPlayer as centerViewOnCurrentPlayerUtil, centerViewOnAvailablePlaces as centerViewOnAvailablePlacesUtil, scrollToPosition as scrollToPositionUtil, handlePlaceClick as handlePlaceClickUtil, isRequestActive, resetRequestLock, isItemWorthPickingUp, isFieldPlaceAlreadyTaken } from '@/utils/fieldUtils';
import { updateGameDataSelectively as doUpdateGameDataSelectively, initGame as doInitGame, joinGame as doJoinGame, startGame as doStartGame } from '@/utils/gameUtils';

// Import notification utility functions
import { dismissNotification as dismissNotificationUtil, showPlayerSwitchNotification as showPlayerSwitchNotificationUtil } from '@/utils/notificationUtils';

// Import inventory utility functions
import { selectInventoryItem as selectInventoryItemUtil, selectItemToReplace as selectItemToReplaceUtil, replaceItem as replaceItemUtil, skipItem as skipItemUtil, handleInventoryFullResponse as handleInventoryFullResponseUtil } from '@/utils/inventoryUtils';

// Import monster utility functions
import { getMonsterEmoji as getMonsterEmojiUtil } from '@/utils/monsterUtils';

// Import battle utility functions
import { closeBattleReportAndEndTurn as closeBattleReportAndEndTurnUtil, predictBattleResult } from '@/utils/battleUtils';

// Import keyboard utility functions
import { handleKeyboardEvents as handleKeyboardEventsUtil, isLetterKey } from '@/utils/keyboardUtils';

// Helper function for calculating item damage values
const getItemDamage = (item) => {
  if (!item) return 0;

  // Use item type to determine damage
  const itemType = item.type || '';

  switch (itemType) {
    case 'dagger':
      return 1;
    case 'sword':
      return 2;
    case 'axe':
      return 3;
    case 'fireball':
      return 1;
    case 'frost_fist':
      return 2;
    case 'teleport':
    case 'dark_thorn':
      return 0;
    default:
      // Check if item has a direct damage property
      return item.damage || 0;
  }
};

const router = useRouter();
const { t, locale } = useI18n();
const route = useRoute();
const id = computed(() => route.params.id);
const loading = ref(true);

// Transient notice for a rejected action — unlike `error` it does not replace the game view
const actionNotice = ref('');
let actionNoticeTimer = null;
// A refusal knocks, an informational line rings (cue: 'ui_refused' | 'notice')
const showActionNotice = (text, cue = 'ui_refused') => {
  actionNotice.value = text;
  audio.cue(cue);
  if (actionNoticeTimer) clearTimeout(actionNoticeTimer);
  actionNoticeTimer = setTimeout(() => { actionNotice.value = ''; }, 5000);
};
// The server's reason stays in English (backend exception text); the frame around it is translated
const apiErrorMessage = (err) => err?.response?.data?.message || err?.message || t('errors.unknown');
const loadingStatus = ref('');  // Add this line to track specific loading operations
const error = ref(null);
const gameData = ref(null);
const currentPlayerId = ref(localStorage.getItem('currentPlayerId') || null);
const secondPlayerId = ref(localStorage.getItem('secondPlayerId') || null);
const humanPlayerId = ref(localStorage.getItem('humanPlayerId') || localStorage.getItem('currentPlayerId') || null);

// ===== Online tables: my seat comes from my account, the others' moves come pushed from the server =====
const myAccount = ref(null);
const accountReady = accountApi.me()
  .then((account) => { myAccount.value = account; })
  .catch((err) => console.warn('[online] could not load the account:', err?.message || err));
const isOnline = computed(() => gameData.value?.state?.online === true);
const mySeatId = computed(() => {
  if (!isOnline.value || !myAccount.value) return null;
  return gameData.value?.players?.find((p) => p.externalId === myAccount.value.id)?.id || null;
});
const isTableRoom = computed(() => isOnline.value
  && (gameData.value?.state?.status === 'lobby' || gameData.value?.state?.endReason === 'abandoned'));
const tableRefreshKey = ref(0);
// the server's clock minus this browser's (ms): the countdown runs on the server's deadline
const clockOffset = ref(0);
watch(() => gameData.value?.state?.serverTime, (serverTime) => {
  if (serverTime) clockOffset.value = Number(serverTime) - Date.now();
});
const turnDeadlineLocal = computed(() => {
  const deadline = gameData.value?.state?.turnDeadline;
  if (!isOnline.value || !deadline || !['started', 'turn_in_progress'].includes(gameData.value?.state?.status)) return null;
  return Date.parse(deadline) - clockOffset.value;
});
const mySeatAway = computed(() => !!gameData.value?.players?.find((p) => p.id === mySeatId.value)?.away);
let presentSent = false;
const takeSeatBack = async () => {
  try {
    await tablesApi.present(id.value);
    console.info('[online] back at the table');
    // at once: a pushed turn still playing back holds the reload
    const mine = gameData.value?.players?.find((p) => p.id === mySeatId.value);
    if (mine) mine.away = false;
  } catch (err) {
    console.warn('[online] could not take the seat back:', err?.response?.data || err?.message || err);
  }
  await loadGameData(false);
};
// opening the page of a running online game is being at the table
watch(mySeatId, (seat) => {
  if (!seat || presentSent || !['started', 'turn_in_progress'].includes(gameData.value?.state?.status)) return;
  presentSent = true;
  if (mySeatAway.value) takeSeatBack();
});
const pushReconnecting = ref(false);
// an AI turn (or a stand-in turn) pushed by the server is being played back: reloads wait for its end
let onlinePlaybackActive = false;
let onlineReloadPending = false;
const onlinePlaybackQueue = [];
let lastOnlineFightKey = null;
// a reload right after a server-played turn's playback: its fights were shown already
let suppressFightNotice = false;
const virtualPlayerIdStored = computed(() => localStorage.getItem('virtualPlayerId') || null);
// Debug UI (force the AI turn) shows only in dev builds: its texts live in this constant,
// so a production build folds it to null and drops them from the bundle
const devAiLabels = import.meta.env.DEV
  ? { stuck: 'AI Stuck - Click to Continue', stuckNote: "AI hasn't moved for 10+ seconds", execute: 'Execute AI Turn Now (Debug)' }
  : null;
const aiStuckDetected = ref(false);
let aiStuckTimer = null;
const playerIsReady = ref(false);
const gameStarted = ref(false);
const isProcessingAI = ref(false); // Flag to block interactions during AI turn
// the AI's turn is being played on the server: a state loaded meanwhile is ahead of the playback still to come
const aiTurnInFlight = ref(false);
const aiPlayback = useAiTurnPlayback();
const isAiPlayingBack = computed(() => aiPlayback.isPlayingBack.value);
const aiBattleOverlay = ref(null); // { position, x, y, playerDamage, monsterHP, result, monsterType }
const zoomLevel = ref(1);
const highlightedTile = ref(null);
const selectedTile = ref(null);
// Check for saved picked tile state early to prevent ghost tile flashing
const earlyPickedTileCheck = () => {
  const storedState = localStorage.getItem('pickedTileState');
  if (storedState) {
    try {
      const state = JSON.parse(storedState);
      // Only restore if it's for the same game
      if (state.gameId === route.params.id) {
        return {
          pickedTileId: state.pickedTileId,
          pickedTile: state.pickedTile,
          ghostTilePosition: state.ghostTilePosition,
          ghostTileOrientation: state.ghostTileOrientation,
          isPlacingTile: state.isPlacingTile
        };
      }
    } catch (e) {
      console.error('Failed to parse early picked tile state:', e);
    }
  }
  return null;
};

const earlyState = earlyPickedTileCheck();
const pickedTileId = ref(earlyState?.pickedTileId || null);
const pickedTile = ref(earlyState?.pickedTile || null);
const ghostTilePosition = ref(earlyState?.ghostTilePosition || null);
const ghostTileOrientation = ref(earlyState?.ghostTileOrientation || null);
const isPlacingTile = ref(earlyState?.isPlacingTile || false);

// Helper to save/restore picked tile state
const savePickedTileState = () => {
  const state = {
    gameId: id.value,
    pickedTileId: pickedTileId.value,
    pickedTile: pickedTile.value,
    ghostTilePosition: ghostTilePosition.value,
    ghostTileOrientation: ghostTileOrientation.value,
    isPlacingTile: isPlacingTile.value
  };
  localStorage.setItem('pickedTileState', JSON.stringify(state));
};

const clearPickedTileState = () => {
  localStorage.removeItem('pickedTileState');
};

const restorePickedTileState = () => {
  // Since we already restored state early, just verify it's still valid
  const storedState = localStorage.getItem('pickedTileState');
  if (!storedState) return false;
  
  try {
    const state = JSON.parse(storedState);
    // Only restore if it's for the same game
    if (state.gameId !== id.value) {
      clearPickedTileState();
      // Clear the refs too
      pickedTileId.value = null;
      pickedTile.value = null;
      ghostTilePosition.value = null;
      ghostTileOrientation.value = null;
      isPlacingTile.value = false;
      return false;
    }
    
    // State was already restored early, just log it
    if (state.pickedTileId && state.pickedTile) {

      return true;
    }
  } catch (e) {
    console.error('Failed to verify picked tile state:', e);
    clearPickedTileState();
    // Clear the refs too
    pickedTileId.value = null;
    pickedTile.value = null;
    ghostTilePosition.value = null;
    ghostTileOrientation.value = null;
    isPlacingTile.value = false;
  }
  return false;
};
const isMoveMode = ref(false);
const isPlaceMode = ref(false);
const tileSize = ref(TILE_SIZE);
const playerSwitched = ref(false);
const notificationTimeout = ref(null);
const healingNotification = ref(null);
const showHealingNotification = ref(false);

// Inventory management state
const showInventoryFullDialog = ref(false);
const droppedItem = ref(null);
const itemCategory = ref('');
const maxItemsInCategory = ref(0);
const selectedItemToReplace = ref(null);
const inventoryForCategory = ref([]);
const isInventoryFullAfterBattle = ref(false); // Track if inventory full dialog is after battle
const inventoryFullKeepsTurn = ref(false); // Swordsman "Unstoppable": the inventory full dialog must not end the turn

// Missing key dialog state
const showMissingKeyDialog = ref(false);
const missingKeyChestType = ref('chest');

// Item pickup dialog state
const showItemPickupDialog = ref(false);
const tileItem = ref(null);
const itemPickupTurnId = ref(null); // Store the turn ID when the dialog is shown

// Teleport spell state
const isTeleportMode = ref(false);
// Portal of Healing: who is carried to the fountain (defaults to the caster)
const teleportTargetPlayerId = ref(null);
const selectedTeleportSpell = ref(null);

// Thief "Stealth": pending "fight or sneak past" prompt ({ position, monster, resolve })
const stealthPrompt = ref(null);
const confirmStealth = ({ position, monster }) => new Promise((resolve) => {
  stealthPrompt.value = { position, monster, resolve };
});
const handleStealthChoice = (sneak) => {
  const prompt = stealthPrompt.value;
  stealthPrompt.value = null;
  prompt?.resolve(sneak === true);
};

// Oracle "Fateweaver": the player picks which of the two drawn tokens is placed on the new room
const fateweaverPrompt = ref(null);
const chooseFateweaverItem = (choice) => new Promise((resolve) => {
  fateweaverPrompt.value = { choice, resolve };
});
const handleFateweaverChoice = (itemId) => {
  const prompt = fateweaverPrompt.value;
  fateweaverPrompt.value = null;
  prompt?.resolve(itemId);
};
// A choice found in the game state (page reloaded mid-turn) is settled here, then the markers come back
const resolvePendingFateweaver = async (choice) => {
  const itemId = await chooseFateweaverItem(choice);
  try {
    await gameApi.fateweaver({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: gameData.value?.state?.currentTurnId,
      itemId
    });
  } catch (e) {
    console.error('Failed to choose the Fateweaver token:', e);
  }
  await loadGameData(false);
};

// Warrior "Reincarnation": the fallen hero must pick a healing fountain before the turn ends
const isReincarnationMode = ref(false);
const reincarnationFountainPositions = ref([]); // ["x,y", ...] from the backend, empty = all fountains

// Warlock "Magic Swap": the player picks another hero's figure on the field to trade places
const isMagicSwapMode = ref(false);

// Hero helpers for the player panel
// Swordsman "Unstoppable": the backend keeps the turn open after a battle with a 6, so the client must not end it
const lastBattleUnstoppable = () => battleInfo.value?.unstoppable === true;
// The hero's role in the current language ("the warrior", «воин», „wojownik”)
const heroShortName = (player) => (player?.hero ? heroName(player.hero, 'role') : '');
// Karak: Regent, Lord Karak variant — a transformed hero is drawn as Lord Karak (the chosen hero stays in `hero`)
const figureHeroKey = (player) => (player?.lordKarak ? 'lord_karak' : player?.hero || null);

// Hero per player id for the tile figures (theme portraits); the AI is dealt a hero like everyone
// the heroes knocked out at 0 HP, drawn grey with stars (Horan waiting to reincarnate has his skull instead)
const stunnedPlayerIds = computed(() => (gameData.value?.players || [])
  .filter((p) => (p.hp === 0 || p.defeated === true) && !p.awaitingReincarnation).map((p) => p.id));
// the heroes under the Curse of Karak (a hero waiting to reincarnate shows the skull instead)
const cursedPlayerIds = computed(() => (gameData.value?.players || [])
  .filter((p) => p.cursed && !p.awaitingReincarnation).map((p) => p.id));
const playerHeroes = computed(() => Object.fromEntries(
  (gameData.value?.players || []).map((p) => [p.id, figureHeroKey(p)])
));

const defaultAvatar = (player) =>
  (isCurrentUserEntry({ playerId: player.id, externalId: player.externalId }) ? '/images/player.webp' : '/images/ai.webp');

// Sidebar avatar: the theme's hero portrait when the player has a hero and the theme has an image
const playerAvatar = (player) => (figureHeroKey(player) && getHero(figureHeroKey(player)).image) || defaultAvatar(player);

const onAvatarError = (event, player) => {
  const fallback = defaultAvatar(player);
  if (event.target.getAttribute('src') !== fallback) {
    console.warn('[theme] hero portrait missing, using default avatar:', event.target.getAttribute('src'));
    event.target.src = fallback;
  }
};
const heroTooltip = (player) => {
  if (!player?.hero && !player?.lordKarak) return '';
  const lines = [heroName(player)];
  if (player.lordKarak) {
    lines.push(player.hero ? t('game.tooltip.onceHero', { hero: heroShortName(player) }) : t('game.tooltip.lordKarak'));
  }
  for (const skill of player.heroSkills || []) {
    const text = skillText(skill);
    lines.push(`${text.name}: ${text.description}`);
  }
  if (player.awaitingReincarnation) {
    lines.push(t('game.tooltip.fallen'));
  }
  if (player.cursed) {
    lines.push(t('game.tooltip.cursed'));
  }
  return lines.join('\n');
};

// Who a player is, the way the player panel names them
// Players in seat order (the party rail's order): the same "Adventurer I / II" everywhere
const seatOrder = computed(() => turnOrder.entries.value.map((entry) => entry.player));
const displayName = (player) => playerDisplayName(player, seatOrder.value);
// a choice or a list names the player as everywhere else, with "(you)" for this screen's player
const isYouPlayer = (player) => !!player && isCurrentUserEntry({ playerId: player.id, externalId: player.externalId });
const playerLabel = (player) => {
  if (!player) return t('common.player');
  return isYouPlayer(player) ? `${displayName(player)} (${t('common.you').toLowerCase()})` : displayName(player);
};

// Curse of Karak: the other players (candidates) and the current holder of the token
const curseChoice = computed(() => {
  const all = gameData.value?.players || [];
  const cursedPlayerId = gameData.value?.state?.curse?.cursedPlayerId ?? battleInfo.value?.cursedPlayerId ?? null;
  const holder = all.find(p => p.id === cursedPlayerId);
  return {
    players: all.filter(p => p.id !== currentPlayerId.value).map(p => ({
      id: p.id,
      label: playerChoiceLabel(p)
    })),
    cursedPlayerId,
    cursedLabel: holder ? playerLabel(holder) : ''
  };
});
const curseChoicePendingForMe = computed(() => !!currentPlayerId.value && gameData.value?.state?.curse?.pendingBy === currentPlayerId.value);

// ===== Karak: Regent =====

const playerById = (playerId) => (gameData.value?.players || []).find((p) => p.id === playerId) || null;
const playerChoiceLabel = (player) => {
  if (!player?.hero) return playerLabel(player);
  const extra = [heroShortName(player), isYouPlayer(player) ? t('common.you').toLowerCase() : ''].filter(Boolean).join(', ');
  return `${displayName(player)} (${extra})`;
};

// ===== HUD: turn order, banner =====

const isHumanPlayer = (player) => isCurrentUserEntry({ playerId: player.id, externalId: player.externalId });
const turnOrder = useTurnOrder(gameData, isHumanPlayer);
const heroNameById = (playerId) => {
  const player = playerById(playerId);
  if (!player) return t('common.unknown');
  // the third person everywhere ("Adventurer II finds…", never "You finds…"), the same name as in the rail
  return displayName(player);
};

// The human at this screen (their pack is drawn bottom left)
const humanPlayer = computed(() => (gameData.value?.players || []).find(isHumanPlayer) || null);
// The spell being cast from the pack (Portal of Healing / Dark Thorn)
const activeSpellItemId = computed(() => (isTeleportMode.value && selectedTeleportSpell.value?.itemId)
  || (isDarkThornMode.value && selectedDarkThornSpell.value?.itemId) || null);

// A choice waits for the player: the decision plaque (bottom right, over the rings) shows it
const hasDecision = computed(() => isTeleportMode.value || isDarkThornMode.value || isReincarnationMode.value
  || isMagicSwapMode.value || isLightningMode.value
  || showMissingKeyDialog.value
  || !!stealthPrompt.value || !!fateweaverPrompt.value
  || (!showBattleReportModal.value && !!(curseChoicePendingForMe.value || retreatChoiceForMe.value)));

// Rings: the heal-limit popover and the Journal drawer
const healLimitOpen = ref(false);
const journalOpen = ref(false);
watch(journalOpen, (open) => console.debug('[hud] journal', open));
// Closing the Journal gives the focus back to its ring
const closeJournal = () => {
  journalOpen.value = false;
  nextTick(() => document.querySelector('[data-hud-ring="journal"]')?.focus());
};

// The chronicle (last lines, top right) and the Journal (every turn) read the same turns
const chronicleLines = computed(() => chronicleTail(gameTurns.value, heroNameById, 4));

// "Your turn comes after Taia and Lorraine" while the others act
const ringsWaitingText = computed(() => {
  if (isPlayerTurn.value || gameData.value?.state?.status === 'finished') return '';
  const acting = turnOrder.acting.value;
  if (!acting || acting.isYou) return '';
  const names = turnOrder.waitingFor.value;
  return names.length ? t('hud.waitingAfter', { names: joinNames(names, t('hud.and')) }) : t('hud.waitingNext');
});

// The starting-player roll stays on the rail through the first round
const startingRollShown = (player) => {
  const roll = gameData.value?.state?.startingRolls?.[player.id];
  return roll && (gameData.value?.state?.turn || 0) <= (gameData.value?.players?.length || 1) ? roll : null;
};

// "third of five" under the turn number once the company is bigger than two
const bannerSub = computed(() => {
  const entries = turnOrder.entries.value;
  const index = entries.findIndex((e) => e.state === 'acting');
  if (entries.length < 3 || index < 0) return '';
  const ordinal = index < 10 ? t(`hud.ordinals.${index}`) : String(index + 1);
  const count = entries.length <= 10 ? t(`hud.counts.${entries.length - 1}`) : String(entries.length);
  return t('hud.ordinalOf', { ordinal, count });
});

// The banner's headline: who acts and what they are about
const bannerHeadline = computed(() => {
  const status = gameData.value?.state?.status;
  if (status === 'finished') return t('hud.headline.finished');
  const acting = turnOrder.acting.value;
  if (!acting || !gameData.value?.state?.turn) return t('hud.headline.setup');
  const who = acting.name;
  // ru/pl put the monster in the instrumental case ("сражается с гигантской крысой"); the form comes with the locale
  const faces = (monsterKey) => t('hud.headline.faces', { who, monster: monsterName(monsterKey, t('hud.headline.facesForm')) });
  if (isAiPlayingBack.value) {
    const action = aiPlayback.currentAction.value;
    switch (action?.type) {
      case 'battle': return action.monsterType ? faces(action.monsterType) : t('hud.headline.fights', { who });
      case 'move_player': return t('hud.headline.movesOn', { who });
      case 'place_tile': return t('hud.headline.seeksPath', { who });
      case 'end_turn': return t('hud.headline.rests', { who });
      case 'cursed_room': return t('hud.headline.cursedRoom', { who });
      case undefined: return t('hud.headline.ponders', { who });
      default: return t('hud.headline.loot', { who });
    }
  }
  if (showBattleReportModal.value && battleInfo.value?.monsterType) {
    return faces(battleInfo.value.monsterType);
  }
  if (acting.isYou && retreatChoiceForMe.value) return t('hud.headline.chooseRetreat');
  // every step of the turn spent: say so, the only thing left is to end it
  const places = gameData.value?.state?.availablePlaces;
  if (acting.isYou && places && !places.moveTo?.length && !places.placeTile?.length) return t('hud.headline.noMovesLeft');
  if (acting.isYou || !isVirtualPlayer(acting.player.id)) return t('hud.headline.seeksPath', { who });
  return t('hud.headline.ponders', { who });
});

// The hero rolling in the battle modal: the summoned rival rolls in this browser during a duel
const battleRoller = computed(() => playerById(battleInfo.value?.player) || getCurrentPlayerData.value);
const duelRival = computed(() => {
  // the challenger's rival is the summoned hero; the summoned hero's rival is the challenger
  const side = battleInfo.value?.duel;
  const rivalId = side?.role === 'opponent' ? gameData.value?.state?.duel?.challengerId : side?.opponentId;
  const rival = playerById(rivalId);
  return rival ? { label: playerChoiceLabel(rival), heroKey: figureHeroKey(rival) } : null;
});

// Battle Arena: the first entry asks this player to choose a rival
const arenaChoiceForMe = computed(() => {
  const choice = gameData.value?.state?.arenaChoice;
  return choice && choice.playerId === currentPlayerId.value ? choice : null;
});
const duelOpponentCandidates = computed(() => (arenaChoiceForMe.value?.candidates || [])
  .map((playerId) => playerById(playerId))
  .filter(Boolean)
  .map((player) => ({
    id: player.id,
    label: playerChoiceLabel(player),
    portrait: getHero(figureHeroKey(player)).image,
    weapons: (player.inventory?.weapons || []).reduce((sum, item) => sum + getItemDamage(item), 0),
    treasures: (player.inventory?.treasures || []).length
  })));
// label: for buttons and tables ("Aderyn (the thief, you)"); name: for sentences ("Aderyn wins the duel")
// whose skills the skills ring shows first: my seat online, else the player this screen plays
const skillsOwnerId = computed(() => (isOnline.value ? mySeatId.value : currentPlayerId.value));
const duelPanelPlayers = computed(() => (gameData.value?.players || []).map((p) => ({ id: p.id, label: playerChoiceLabel(p), name: displayName(p) })));

// Lord Karak reveal: one dialog per game and browser (state.lordKarak stays set for the rest of the game)
const lordKarakSeen = ref(false);
try {
  lordKarakSeen.value = localStorage.getItem(`lordKarakSeen:${route.params.id}`) === '1';
} catch (e) {
  lordKarakSeen.value = false;
}
const visibleLordKarak = computed(() => (lordKarakSeen.value ? null : gameData.value?.state?.lordKarak || null));
const dismissLordKarak = () => {
  lordKarakSeen.value = true;
  try {
    localStorage.setItem(`lordKarakSeen:${id.value}`, '1');
  } catch (e) {
    // localStorage unavailable - the dialog is hidden for this page only
  }
};

// The duel result stays in the decision plaque until dismissed (remembered per game and duel)
const dismissedDuelId = ref(null);
const dismissedDuelKey = () => `dismissedDuel:${id.value}`;
try {
  dismissedDuelId.value = localStorage.getItem(`dismissedDuel:${route.params.id}`);
} catch (e) {
  dismissedDuelId.value = null;
}
const visibleDuel = computed(() => {
  const duel = gameData.value?.state?.duel;
  return duel && duel.duelId !== dismissedDuelId.value ? duel : null;
});
const dismissDuel = () => {
  dismissedDuelId.value = gameData.value?.state?.duel?.duelId || null;
  try {
    localStorage.setItem(dismissedDuelKey(), dismissedDuelId.value || '');
  } catch (e) {
    // localStorage unavailable - the panel is hidden for this page only
  }
  // an AI that summoned me goes on with its turn; the AI's turn that waited for the outcome to be read starts
  nextTick(() => checkAndHandleVirtualPlayerTurn());
};

// The challenger cannot end the turn while the arena choice or his duel is open
const duelBlocksEndTurn = computed(() => {
  if (arenaChoiceForMe.value) return true;
  const duel = gameData.value?.state?.duel;
  return !!duel && duel.challengerId === currentPlayerId.value && ['challenger_prepare', 'opponent_prepare', 'challenger_roll', 'opponent_roll', 'loot'].includes(duel.phase);
});

const handleChooseDuelOpponent = async (opponentId) => {
  try {
    loading.value = true;
    loadingStatus.value = 'Summoning the rival...';
    const response = await gameApi.chooseDuelOpponent({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: gameData.value?.state?.currentTurnId,
      opponentId
    });
    if (response?.success === false) {
      showActionNotice(response.message || t('errors.duelStart'));
    } else if (response?.battleInfo) {
      battleInfo.value = response.battleInfo;
      showBattleReportModal.value = true;
    }
  } catch (err) {
    showActionNotice(t('errors.duelStartReason', { reason: apiErrorMessage(err) }));
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
  await loadGameData(false);
};

// A human rival summoned to a duel (by a human or by the AI, whose turn waits) rolls in this browser once the
// challenger locked in; online only in the summoned seat's own browser
const showPendingDuelRoll = (fetched) => {
  const duel = fetched?.state?.duel;
  const pending = fetched?.state?.lastBattleInfo;
  if (!duel || duel.phase !== 'opponent_roll' || showBattleReportModal.value) return;
  if (!pending?.duel || pending.duel.role !== 'opponent' || pending.player !== duel.opponentId) return;
  if (pending.battleId === lastProcessedBattleId.value) return;
  if (playerById(duel.opponentId)?.isAi) return;
  if (fetched?.state?.online && duel.opponentId !== mySeatId.value) return;
  battleInfo.value = pending;
  showBattleReportModal.value = true;
};

// A duel side is locked in for the roller (the challenger, or the summoned rival in this browser); the duel never ends the turn
const handleFinalizeDuelSide = async (finalizeBattleData) => {
  const roller = battleInfo.value?.player || currentPlayerId.value;
  lastProcessedBattleId.value = finalizeBattleData.battleId;
  try {
    loading.value = true;
    loadingStatus.value = 'Locking in strength...';
    await gameApi.finalizeBattle({
      battleId: finalizeBattleData.battleId,
      gameId: id.value,
      playerId: roller,
      turnId: gameData.value?.state?.currentTurnId,
      selectedConsumableIds: finalizeBattleData.selectedConsumableIds || [],
      pickupItem: false,
      sacrificeHp: finalizeBattleData.sacrificeHp === true
    });
  } catch (err) {
    showActionNotice(t('errors.duelRoll', { reason: apiErrorMessage(err) }));
  } finally {
    showBattleReportModal.value = false;
    battleInfo.value = null;
    loading.value = false;
    loadingStatus.value = '';
    resetRequestLock();
  }
  await loadGameData(false);
};

// The side was already decided (the rival beat the total with weapons alone): just close the modal
const handleDuelContinue = async ({ battleId } = {}) => {
  if (battleId) lastProcessedBattleId.value = battleId;
  showBattleReportModal.value = false;
  battleInfo.value = null;
  await loadGameData(false);
};

// Karak: Regent — a hero's spells for the duel, chosen before the roll
const handlePrepareDuel = async ({ playerId, consumableIds, sacrifice }) => {
  const duel = gameData.value?.state?.duel;
  if (!duel) return;
  try {
    const response = await gameApi.prepareDuel({
      gameId: id.value,
      playerId,
      turnId: gameData.value?.state?.currentTurnId,
      duelId: duel.duelId,
      consumableIds,
      sacrifice
    });
    if (response?.success === false) showActionNotice(t('errors.duelPrepare', { reason: response.message || '' }));
  } catch (err) {
    showActionNotice(t('errors.duelPrepare', { reason: apiErrorMessage(err) }));
  }
  await loadGameData(false);
};

// { itemId, replaceItemId }: a full pack leaves replaceItemId on the arena in exchange
const handleTakeDuelLoot = async ({ itemId, replaceItemId = null }) => {
  const duel = gameData.value?.state?.duel;
  if (!duel) return;
  try {
    const response = await gameApi.takeDuelLoot({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: gameData.value?.state?.currentTurnId,
      duelId: duel.duelId,
      itemId,
      replaceItemId
    });
    if (response?.success === false) {
      showActionNotice(response.message || t('errors.loot'));
    }
  } catch (err) {
    showActionNotice(t('errors.lootReason', { reason: apiErrorMessage(err) }));
  }
  await loadGameData(false);
};

// Dark Thorn: a spell cast from the pack at another hero; Frost Fist is used in fights only
const isDarkThornMode = ref(false);
const selectedDarkThornSpell = ref(null);
const darkThornTargetId = ref(null);
const darkThornTargets = computed(() => (gameData.value?.players || []).filter((p) => p.id !== currentPlayerId.value));
const isCastableSpell = (item) => item?.type === 'teleport' || item?.type === 'dark_thorn';
const cancelDarkThornMode = () => {
  isDarkThornMode.value = false;
  selectedDarkThornSpell.value = null;
  darkThornTargetId.value = null;
};
const handleSpellClick = (item) => {
  if (item.type === 'teleport') {
    cancelDarkThornMode();
    handleTeleportSpellSelection(item);
    return;
  }
  if (isDarkThornMode.value && selectedDarkThornSpell.value?.itemId === item.itemId) {
    cancelDarkThornMode();
    return;
  }
  isDarkThornMode.value = true;
  selectedDarkThornSpell.value = item;
  darkThornTargetId.value = darkThornTargets.value.length === 1 ? darkThornTargets.value[0].id : null;
};
const castDarkThorn = async () => {
  if (!selectedDarkThornSpell.value || !darkThornTargetId.value) return;
  const target = playerById(darkThornTargetId.value);
  try {
    loading.value = true;
    loadingStatus.value = 'Casting Dark Thorn...';
    await gameApi.useSpell(
      id.value,
      currentPlayerId.value,
      gameData.value?.state?.currentTurnId,
      selectedDarkThornSpell.value.itemId,
      null,
      darkThornTargetId.value
    );
    showActionNotice(t('game.notice.darkThornHit', { who: heroNameById(target?.id) }), 'notice');
  } catch (err) {
    showActionNotice(t('errors.failed', { action: t('errors.actions.darkThorn'), reason: apiErrorMessage(err) }));
  } finally {
    cancelDarkThornMode();
    loading.value = false;
    loadingStatus.value = '';
  }
  await loadGameData(false);
};

// Cursed Room: one scene (and one notice) per roll (turn + hero) — the die, then the token on the hero or left in the room
const shownRoomEventKey = ref(null);
const cursedRoomScene = ref(null);
// the state the page opens with is the field as it is: only rolls made while it is open get a scene
let roomEventsArmed = false;
watch(gameData, (data) => {
  if (data && !roomEventsArmed) nextTick(() => { roomEventsArmed = true; });
}, { flush: 'sync' });
// every roll of the turn gets its scene, one after the other (a Sprint may cross several Cursed Rooms)
const shownRoomEventIds = new Set();
const roomSceneQueue = [];
const roomEventKey = (event) => event.id || `${event.turnId}:${event.playerId}:${event.position}`;
const showNextRoomScene = () => {
  if (cursedRoomScene.value || roomSceneQueue.length === 0) return;
  const event = roomSceneQueue.shift();
  const who = heroNameById(event.playerId);
  const notice = event.alreadyCursed ? 'game.notice.cursedRoomAlready' : event.cursed ? 'game.notice.cursedRoomCursed' : 'game.notice.cursedRoomSafe';
  showActionNotice(t(notice, { who, roll: event.roll }), 'notice');
  cursedRoomScene.value = event;
};
watch(() => gameData.value?.state?.roomEvents ?? (gameData.value?.state?.roomEvent ? [gameData.value.state.roomEvent] : []), (events) => {
  const fresh = (events || []).filter((event) => event && !shownRoomEventIds.has(roomEventKey(event)));
  fresh.forEach((event) => shownRoomEventIds.add(roomEventKey(event)));
  if (fresh.length > 0) shownRoomEventKey.value = roomEventKey(fresh[fresh.length - 1]);
  if (!roomEventsArmed || fresh.length === 0) return;
  roomSceneQueue.push(...fresh);
  showNextRoomScene();
}, { flush: 'sync' }); // at once, not on the next tick: the AI's turn is checked right after the state loads
// The Curse of Karak passed on by someone else (a mummy's slayer) gets the same scene: who gave it to whom.
// There is one token: a Cursed Room hands it over too, and the room's own scene already tells that.
watch(() => gameData.value?.state?.curse?.cursedPlayerId, (holder, was) => {
  if (!roomEventsArmed || !holder || holder === was) return;
  const room = gameData.value?.state?.roomEvent;
  // (the latest room roll cursed the new holder — also when the state comes after the turn has passed)
  if (room?.playerId === holder && room.cursed && !room.alreadyCursed) return;
  const by = gameData.value?.state?.currentPlayerId;
  const byMe = isOnline.value ? by === mySeatId.value : !gameData.value?.players?.find((p) => p.id === by)?.isAi;
  // my own choice was made on the fight scene: nothing to tell
  if (byMe) return;
  cursedRoomScene.value = { transfer: true, cursed: true, playerId: holder, fromName: heroNameById(by), position: null };
}, { flush: 'sync' });
const cursedRoomSceneMine = computed(() => {
  const playerId = cursedRoomScene.value?.playerId;
  if (!playerId) return false;
  return isOnline.value ? playerId === mySeatId.value : !gameData.value?.players?.find((p) => p.id === playerId)?.isAi;
});
// my hero's skills, told as lost when the curse falls on it
const cursedRoomSceneLostSkills = computed(() => {
  if (!cursedRoomSceneMine.value) return [];
  const player = gameData.value?.players?.find((p) => p.id === cursedRoomScene.value?.playerId);
  return (player?.heroSkills || []).map((skill) => skillText(skill).name);
});
const cursedRoomSceneSprite = computed(() => {
  const player = gameData.value?.players?.find((p) => p.id === cursedRoomScene.value?.playerId);
  return getHero(figureHeroKey(player)).image;
});
// The token flies from the scene to where it now lies: the hero's card in the rail, or back into the room on the field
const flyCurseToken = (fromRect, target) => {
  if (!fromRect || !target || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const to = target.getBoundingClientRect();
  const token = document.createElement('img');
  token.src = '/images/curse.webp';
  token.alt = '';
  token.className = 'curse-token-flight';
  Object.assign(token.style, { position: 'fixed', left: `${fromRect.left}px`, top: `${fromRect.top}px`, width: `${fromRect.width}px`, height: `${fromRect.height}px`, zIndex: 3000, pointerEvents: 'none' });
  document.body.appendChild(token);
  const dx = to.left + to.width / 2 - (fromRect.left + fromRect.width / 2);
  const dy = to.top + to.height / 2 - (fromRect.top + fromRect.height / 2);
  const scale = Math.max(0.2, Math.min(to.width, 40) / fromRect.width);
  const flight = token.animate([
    { transform: 'translate(0, 0) scale(1)', opacity: 1, filter: 'drop-shadow(0 0 18px rgba(170, 80, 240, 0.9))' },
    { transform: `translate(${dx * 0.5}px, ${dy * 0.5 - 60}px) scale(${(1 + scale) / 2})`, opacity: 1, offset: 0.5 },
    { transform: `translate(${dx}px, ${dy}px) scale(${scale})`, opacity: 0.2 },
  ], { duration: 900, easing: 'cubic-bezier(0.4, 0, 0.2, 1)' });
  flight.onfinish = () => {
    token.remove();
    target.classList.add('curse-token-landed');
    setTimeout(() => target.classList.remove('curse-token-landed'), 1200);
  };
};
// The AI waits while a Cursed Room's die is on screen: its turn (or its playback online) starts once it is closed
let sceneWaiters = [];
const whenRoomSceneClosed = () => (cursedRoomScene.value || roomSceneQueue.length > 0
  ? new Promise((resolve) => { sceneWaiters.push(resolve); })
  : Promise.resolve());
const closeCursedRoomScene = (tokenRect) => {
  const event = cursedRoomScene.value;
  cursedRoomScene.value = null;
  // the token flies to the hero's card, or back into the room (a hero already cursed keeps it where it was)
  if (event && !event.alreadyCursed) {
    nextTick(() => {
      const target = event.cursed
        ? document.querySelector(`.party-member[data-player-id="${event.playerId}"] .party-member__medallion`)
        : document.querySelector(`.tile[data-position="${event.position}"]`);
      flyCurseToken(tokenRect, target);
    });
  }
  // the next roll of the same turn first; the AI and the playback wait for the last one
  if (roomSceneQueue.length > 0) {
    setTimeout(showNextRoomScene, 400);
    return;
  }
  const waiting = sceneWaiters;
  sceneWaiters = [];
  waiting.forEach((resolve) => resolve());
  // a turn of the AI held back by the scene
  if (event) nextTick(() => checkAndHandleVirtualPlayerTurn());
};
const handleCurseChoice = async (targetPlayerId) => {
  try {
    const result = await gameApi.curse({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: gameData.value?.state?.currentTurnId,
      targetPlayerId
    });
    const holder = (gameData.value?.players || []).find(p => p.id === result?.cursedPlayerId);
    const label = holder ? heroNameById(holder.id) : t('game.notice.nobody');
    if (battleInfo.value) {
      battleInfo.value = {
        ...battleInfo.value,
        curseChoiceRequired: false,
        curseResolvedLabel: t(result?.kept ? 'game.notice.curseStays' : 'game.notice.curseOn', { who: label })
      };
    }
    if (gameData.value?.state?.curse) {
      gameData.value.state.curse.pendingBy = null;
      gameData.value.state.curse.cursedPlayerId = result?.cursedPlayerId ?? null;
    }
    if (!showBattleReportModal.value) {
      await loadGameData(false);
    }
  } catch (e) {
    console.error('Failed to place the curse:', e);
    const reason = e?.response?.data?.message || e.message || '';
    // The server has no curse waiting (it was placed already, the fight on screen is an old one):
    // drop the choice so the reward and the buttons come back; a short notice instead of the full-screen error
    if (/no curse to place/i.test(reason) && battleInfo.value) {
      battleInfo.value = { ...battleInfo.value, curseChoiceRequired: false };
      await loadGameData(false);
      return;
    }
    showActionNotice(t('errors.curse', { reason }));
  }
};

// Game turns for action log
const gameTurns = ref([]);

// The game state carries the last two turns only: the Journal loads the whole history when it opens
// (and again when a turn ends while it is open), the live turns on top
const journalHistory = ref([]);
const mapTurn = (turn) => ({
  turnId: turn.turnId || turn.turn_id,
  turnNumber: turn.turnNumber || turn.turn_number,
  playerId: turn.playerId || turn.player_id,
  actions: turn.actions || [],
  startTime: turn.startTime || turn.start_time,
  endTime: turn.endTime || turn.end_time,
  pendingItemPickup: turn.pendingItemPickup || turn.pending_item_pickup || false,
});
const loadJournalHistory = async () => {
  try {
    const data = await gameApi.getGameTurns(id.value);
    journalHistory.value = (data?.turns || []).map(mapTurn);
  } catch (err) {
    console.warn('[journal] history not loaded:', err?.message || err);
  }
};
watch(journalOpen, (open) => { if (open) loadJournalHistory(); });
watch(() => gameTurns.value.length && gameTurns.value[gameTurns.value.length - 1]?.turnId, () => {
  if (journalOpen.value) loadJournalHistory();
});
const journalTurnList = computed(() => {
  const byId = new Map(journalHistory.value.map((turn) => [turn.turnId, turn]));
  gameTurns.value.forEach((turn) => byId.set(turn.turnId, turn));
  return [...byId.values()].sort((x, y) => (x.turnNumber || 0) - (y.turnNumber || 0));
});
const journalTurns = computed(() => (journalOpen.value ? chronicleTurns(journalTurnList.value, heroNameById) : []));

// WebSocket for real-time updates
// const socket = ref(null);

// Drag navigation state
const isDragging = ref(false);
const startDragX = ref(0);
const startDragY = ref(0);
const startScrollLeft = ref(0);
const startScrollTop = ref(0);
const fieldElement = ref(null); // Reference to the game field element

// Handle CTRL key press to show grab cursor
const isCtrlPressed = ref(false);

// Need to add isReplaying ref and replay state variables
const isReplaying = ref(false);
const currentReplayTurn = ref(0);
const originalGameData = ref(null);
const replaySpeed = ref(1); // 1 = normal speed, 0.5 = slow, 2 = fast
const gamePollingInterval = ref(null); // Interval for polling game updates

// Battle modal state
const showBattleReportModal = ref(false);

// Lord Karak rises on the tile that was just entered: the tile's own scene first (the Cursed Room's die and its
// token, the arena's rival choice and duel, a fight, the AI's playback), the reveal and its music a moment after
const tileSceneBusy = computed(() => !!cursedRoomScene.value || !!arenaChoiceForMe.value || !!visibleDuel.value
  || showBattleReportModal.value || isAiPlayingBack.value || aiTurnInFlight.value);
const lordKarakReady = ref(false);
let lordKarakTimer = null;
watch(tileSceneBusy, (busy) => {
  clearTimeout(lordKarakTimer);
  lordKarakReady.value = false;
  // the curse token's flight to the hero's card takes under a second
  if (!busy) lordKarakTimer = setTimeout(() => { lordKarakReady.value = true; }, 1000);
}, { immediate: true });
onUnmounted(() => clearTimeout(lordKarakTimer));
const battleInfo = ref(null);
const battleReportModalRef = ref(null);
const skipBattleModalReshow = ref(false);
const lastProcessedBattleId = ref(null);
// When the fight scene closes on the field, say why the turn goes on (the scene's note is gone with it)
// (battleInfo is often cleared in the same tick as the scene closes: remember the fight that was on screen)
let fightOnScreen = null;
watch(battleInfo, (fight) => { if (fight) fightOnScreen = fight; });

// ===== Sound: the music follows the situation, the state's changes sound (useGameSounds) =====
useMusicSituation(() => {
  if (!gameData.value) return null;
  if (isTableRoom.value) return 'lobby';
  if (showBattleReportModal.value && battleInfo.value) {
    return battleInfo.value.monsterType === 'dragon' ? 'dragon' : 'fight';
  }
  if (gameData.value.state?.status === 'finished') return 'lobby';
  return 'explore';
});
useGameSounds(gameData, {
  isReplaying,
  isMine: (playerId) => (isOnline.value
    ? playerId === mySeatId.value
    : !gameData.value?.players?.find((p) => p.id === playerId)?.isAi),
});
watch(pickedTileId, (tileId, was) => { if (tileId && tileId !== was) audio.cue('tile_draw'); });
watch(ghostTileOrientation, (now, was) => {
  if (now && was && now !== was && pickedTileId.value) audio.cue('tile_rotate');
});
watch(showMissingKeyDialog, (shown) => { if (shown) audio.cue('chest_locked'); });
watch(journalOpen, (open) => audio.cue(open ? 'ui_open' : 'ui_close'));
// the last ten seconds of my turn on an online table tick
let tickTimer = null;
watch(turnDeadlineLocal, (deadline) => {
  clearInterval(tickTimer);
  if (!deadline) return;
  tickTimer = setInterval(() => {
    const left = deadline - Date.now();
    const mine = gameData.value?.state?.currentPlayerId === mySeatId.value;
    if (mine && left > 0 && left <= 10500) audio.cue('turn_tick');
    if (left <= 0) clearInterval(tickTimer);
  }, 1000);
}, { immediate: true });
onBeforeUnmount(() => clearInterval(tickTimer));
watch(showBattleReportModal, (shown, wasShown) => {
  const fight = fightOnScreen;
  if (shown || !wasShown || fight?.unstoppable !== true || fight.duel) return;
  if (fight.player !== currentPlayerId.value || gameData.value?.state?.status === 'finished') return;
  showActionNotice(t('game.notice.unstoppableGoesOn', { who: heroNameById(fight.player) }), 'notice');
});
// localStorage.currentPlayerId is shared by all tabs: a game started in another tab overwrites it.
// When it names nobody in this game, take the player the lobby remembered for this game.
const adoptThisGamesPlayer = (data) => {
  const players = data?.players || [];
  if (!players.length) return;
  // An online game: the seat that belongs to my account, never the keys another game left in this browser
  if (data?.state?.online) {
    const mine = myAccount.value ? players.find((p) => p.externalId === myAccount.value.id) : null;
    if (!mine) return;
    if (currentPlayerId.value !== mine.id || humanPlayerId.value !== mine.id) {
      console.debug('[online] playing seat', mine.id);
    }
    currentPlayerId.value = mine.id;
    humanPlayerId.value = mine.id;
    try {
      localStorage.setItem('currentPlayerId', mine.id);
      localStorage.setItem('humanPlayerId', mine.id);
      localStorage.setItem(`humanPlayer:${id.value}`, mine.id);
      const ai = players.find((p) => p.isAi);
      if (ai) localStorage.setItem('virtualPlayerId', ai.id);
    } catch { /* the refs above still hold it for this page */ }
    return;
  }
  // the AI of this game, the same way: a stale virtualPlayerId from another game would hide whose turn it is
  try {
    const storedAi = localStorage.getItem('virtualPlayerId');
    const ai = players.find((p) => p.isAi);
    if (ai && storedAi !== ai.id && !players.some((p) => p.id === storedAi)) {
      localStorage.setItem('virtualPlayerId', ai.id);
    }
  } catch { /* no storage: nothing to fix */ }
  if (players.some((p) => p.id === currentPlayerId.value)) return;
  let remembered = null;
  try {
    remembered = localStorage.getItem(`humanPlayer:${id.value}`);
  } catch {
    return;
  }
  if (!remembered || !players.some((p) => p.id === remembered)) return;
  console.debug('[game] the shared player id belongs to another game; using this game\'s player', remembered);
  currentPlayerId.value = remembered;
  humanPlayerId.value = remembered;
  try {
    localStorage.setItem('currentPlayerId', remembered);
    localStorage.setItem('humanPlayerId', remembered);
  } catch { /* the refs above still hold it for this page */ }
};

// The pending post-fight decision is restored at most once per page load (see loadGameData)
const pendingBattleRestored = ref(false);
// The fight still waits for the player: the current turn's last action is the fight itself (not a duel, the turn is open)
const battleAwaitsDecision = (data, battle) => {
  // the first turn of a game runs in status 'started', later ones in 'turn_in_progress'
  if (!battle || battle.duel || !['started', 'turn_in_progress'].includes(data?.state?.status)) return false;
  if (battle.player !== data.state.currentPlayerId) return false;
  const turn = (data.turns || []).find((t) => t.turnId === data.state.currentTurnId);
  const actions = turn?.actions || [];
  const last = actions[actions.length - 1];
  if ((last?.action || last?.type) === 'fight_monster') return true;
  // a roll that did not win and waits for spells / Sacrifice is not recorded as a fight yet: it still
  // waits while the hero stands on the monster's tile and the monster is undefeated
  if (battle.needsConsumableConfirmation && battle.position) {
    const monster = data.field?.items?.[battle.position];
    return data.field?.playerPositions?.[battle.player] === battle.position && !!monster && !monster.guardDefeated;
  }
  return false;
};

// Computed property to determine if the game can be started
// Computed properties for ghost tile to ensure reactivity
const ghostTileOrientationSymbol = computed(() => {
  if (!ghostTileOrientation.value || !pickedTile.value) return '';
  const symbol = getTileOrientationSymbol(ghostTileOrientation.value, pickedTile.value.room);

  return symbol;
});

const ghostTileOrientationClass = computed(() => {
  if (!ghostTileOrientation.value || !pickedTile.value) return '';
  return getTileOrientationClass(ghostTileOrientation.value, pickedTile.value.room);
});

const canStartGame = computed(() => {
  // Logic to determine if game can be started
  if (!gameData.value || !gameData.value.players) return false;

  // Check if we have at least two players and game is not already started
  return gameData.value.players.length >= 2 && !gameStarted.value;
});

// Computed styles for the field container
const fieldStyles = computed(() => {
  if (!gameData.value || !gameData.value.field) return {};

  // Get field dimensions based on tile positions
  let fieldWidth, fieldHeight;

  // Check if size has min/max properties (preferred format)
  if (gameData.value.field.size && 
      'minX' in gameData.value.field.size && 
      'maxX' in gameData.value.field.size && 
      'minY' in gameData.value.field.size && 
      'maxY' in gameData.value.field.size) {

    const widthTiles = gameData.value.field.size.maxX - gameData.value.field.size.minX + 1;
    const heightTiles = gameData.value.field.size.maxY - gameData.value.field.size.minY + 1;

    // Calculate field dimensions with fixed tile size
    fieldWidth = widthTiles * tileSize.value;
    fieldHeight = heightTiles * tileSize.value;

  } else if (gameData.value.field.size && 'width' in gameData.value.field.size && 'height' in gameData.value.field.size) {
    // Alternative format with direct width/height
    const widthTiles = gameData.value.field.size.width;
    const heightTiles = gameData.value.field.size.height;

    fieldWidth = widthTiles * tileSize.value;
    fieldHeight = heightTiles * tileSize.value;
  } else {
    // Default fallback
    fieldWidth = 600;
    fieldHeight = 600;
  }

  return {
    width: `${fieldWidth}px`,
    height: `${fieldHeight}px`,
    position: 'relative',
    transform: `scale(${zoomLevel.value})`,
    margin: 'auto', // centred in the view while smaller than it (.game-field is a grid), from the padding once larger
    transformOrigin: 'top left' // Change transform origin to avoid centering issues when zooming
  };
});

// Computed property to check if current player is in the game
const isPlayerInGame = computed(() => {
  return isPlayerInGameUtil(gameData.value, currentPlayerId.value);
});

// Check if the second player is in the game
const isSecondPlayerInGame = computed(() => {
  return isSecondPlayerInGameUtil(gameData.value, secondPlayerId.value);
});

// Hot-seat: several humans at this screen; the screen follows the human seat whose turn it is
const isHotseat = computed(() => gameData.value?.state?.hotseat === true && !isOnline.value);
const followHotseatSeat = (data) => {
  if (data?.state?.hotseat !== true || data.state.online) return;
  const current = data.state.currentPlayerId;
  const seat = data.players?.find((p) => p.id === current);
  if (!seat || seat.isAi) return;
  if (currentPlayerId.value === current && humanPlayerId.value === current) return;
  console.info('[hotseat] screen follows', { from: currentPlayerId.value, to: current });
  currentPlayerId.value = current;
  humanPlayerId.value = current;
  try {
    localStorage.setItem('currentPlayerId', current);
    localStorage.setItem('humanPlayerId', current);
    localStorage.setItem(`humanPlayer:${data.gameId || id.value}`, current);
  } catch (e) {
    // localStorage unavailable: the refs carry the seat for this page
  }
};

// whichever path brought the new state (a reload, a selective update after a fight or a pickup), the screen follows
watch(() => gameData.value?.state?.currentPlayerId, () => followHotseatSeat(gameData.value));

// Hot-seat: the screen is handed over between humans behind a curtain; the seat that must act now is the current
// human, or a human summoned to a duel (spells, roll) or winning one (loot) during someone else's turn
const hotseatRevealedKey = () => `hotseat.revealed:${id.value}`;
const readHotseatRevealed = () => {
  try { return sessionStorage.getItem(hotseatRevealedKey()); } catch (e) { return null; }
};
const hotseatRevealed = ref(readHotseatRevealed());
const hotseatSeatToAct = computed(() => {
  if (!isHotseat.value || gameData.value?.state?.status === 'finished') return null;
  const players = gameData.value?.players || [];
  const human = (playerId) => players.find((p) => p.id === playerId && !p.isAi) || null;
  const currentId = gameData.value?.state?.currentPlayerId;
  const duel = gameData.value?.state?.duel;
  if (duel && ['opponent_prepare', 'opponent_roll'].includes(duel.phase) && human(duel.opponentId)) {
    return { player: human(duel.opponentId), reason: duel.opponentId === currentId ? 'turn' : 'duel' };
  }
  if (duel && duel.phase === 'loot') {
    const winnerId = duel.result === 'challenger' ? duel.challengerId : duel.result === 'opponent' ? duel.opponentId : null;
    if (human(winnerId)) return { player: human(winnerId), reason: winnerId === currentId ? 'turn' : 'duel' };
  }
  const current = human(currentId);
  return current ? { player: current, reason: 'turn' } : null;
});
const showHotseatCurtain = computed(() => !!hotseatSeatToAct.value
  && hotseatSeatToAct.value.player.id !== hotseatRevealed.value
  && !isAiPlayingBack.value && !aiTurnInFlight.value && !isProcessingAI.value && !cursedRoomScene.value
  // the last player reads their duel's outcome first (a duel ends the challenger's turn)
  && visibleDuel.value?.phase !== 'done');
watch(showHotseatCurtain, (shown) => {
  if (shown) console.info('[hotseat] curtain', { seat: hotseatSeatToAct.value?.player?.id, reason: hotseatSeatToAct.value?.reason });
});
const revealHotseatSeat = () => {
  const seatId = hotseatSeatToAct.value?.player?.id || null;
  hotseatRevealed.value = seatId;
  try { sessionStorage.setItem(hotseatRevealedKey(), seatId || ''); } catch (e) { /* the ref carries it for this page */ }
  audio.cue('notice');
  console.info('[hotseat] revealed', { seat: seatId });
};

// Check if it's the current player's turn
const isPlayerTurn = computed(() => {
  // Block interactions if game is finished
  if (gameData.value?.state?.status === 'finished') {
    return false;
  }
  // An online game: my seat's turn, and no pushed turn still playing back
  if (isOnline.value) {
    return !!mySeatId.value && gameData.value?.state?.currentPlayerId === mySeatId.value && !isProcessingAI.value;
  }
  // Hot-seat: the human seat whose turn it is plays at this screen (the screen follows it, followHotseatSeat)
  if (isHotseat.value) {
    const seat = gameData.value?.players?.find((p) => p.id === gameData.value?.state?.currentPlayerId);
    return !!seat && !seat.isAi && seat.id === currentPlayerId.value && !isProcessingAI.value;
  }
  
  // Get the actual current player from the API - this is the source of truth
  const serverCurrentPlayerId = gameData.value?.state?.currentPlayerId;
  
  // Check if this is a PvP game (no AI players)
  const hasAIPlayer = gameData.value?.players?.some(p => p.isAi === true);
  
  if (!hasAIPlayer) {
    // This is a PvP/hotseat game - check if current player matches the session player
    const currentPlayerId = localStorage.getItem('currentPlayerId');
    const isCurrentPlayerTurn = currentPlayerId && serverCurrentPlayerId === currentPlayerId;
    

    
    return isCurrentPlayerTurn;
  }
  
  // This is a game with AI - use the original logic
  const humanPlayerId = localStorage.getItem('humanPlayerId');

  // An AI seat's turn (any of them: a hot-seat game may have several) is not the player's
  if (isVirtualPlayer(serverCurrentPlayerId)) {
    return false;
  }
  
  // Check if it's actually the human player's turn
  // Use the stable humanPlayerId, not currentPlayerId which changes
  const isHumanTurn = humanPlayerId && serverCurrentPlayerId === humanPlayerId;
  
  // Block interactions if AI is processing
  if (isProcessingAI.value) {

    return false;
  }
  
  return isHumanTurn;
});

// Fog of war: cells for all positions in the field bounds that don't have a tile
const dissolvingFogPositions = ref(new Set());

// Torchlight: one light per hero at the position its figure is drawn at
// (AI playback moves gameData.field.playerPositions step by step, so the light follows)
const fieldCols = computed(() => {
  const size = gameData.value?.field?.size;
  return size && 'maxX' in size ? size.maxX - size.minX + 1 : 1;
});
const fieldRows = computed(() => {
  const size = gameData.value?.field?.size;
  return size && 'maxY' in size ? size.maxY - size.minY + 1 : 1;
});
const torchlightActingId = computed(() => (isAiPlayingBack.value
  ? (aiPlayback.currentAction.value?.playerId || gameData.value?.state?.currentPlayerId || virtualPlayerIdStored.value)
  : gameData.value?.state?.currentPlayerId) || null);
const torchLights = computed(() => {
  const positions = gameData.value?.field?.playerPositions || {};
  return Object.entries(positions)
    .filter(([, position]) => typeof position === 'string' && position.includes(','))
    .map(([playerId, position]) => {
      const [x, y] = position.split(',').map(Number);
      return { x, y, acting: playerId === torchlightActingId.value };
    })
    .filter((light) => Number.isFinite(light.x) && Number.isFinite(light.y));
});
const torchlightActive = computed(() => gameData.value?.state?.status !== 'finished' && torchLights.value.length > 0);
watch(() => torchLights.value.map((light) => `${light.x},${light.y}${light.acting ? '*' : ''}`).join(' '), (lit) => {
  console.debug('[torchlight] lights', lit || 'none', 'acting', torchlightActingId.value);
});

const fogCells = computed(() => {
  if (!gameData.value?.field?.size) return [];

  const { minX, maxX, minY, maxY } = gameData.value.field.size;
  const tilePositions = new Set(
    (gameData.value.field.tiles || []).map(t => `${t.x ?? parseInt(t.position?.split(',')[0])},${t.y ?? parseInt(t.position?.split(',')[1])}`)
  );

  // Only render fog cells within the tile container bounds (+ 1 tile border)
  // The game-field background itself acts as the main fog
  const cells = [];
  for (let x = minX - 1; x <= maxX + 1; x++) {
    for (let y = minY - 1; y <= maxY + 1; y++) {
      const key = `${x},${y}`;
      if (!tilePositions.has(key)) {
        cells.push({ x, y, dissolving: dissolvingFogPositions.value.has(key) });
      }
    }
  }
  return cells;
});

// Fog fringes: gradient overlays on tile edges that border unexplored fog
const fogFringes = computed(() => {
  if (!gameData.value?.field?.size) return [];

  const tilePositions = new Set(
    (gameData.value.field.tiles || []).map(t => `${t.x ?? parseInt(t.position?.split(',')[0])},${t.y ?? parseInt(t.position?.split(',')[1])}`)
  );

  const fringes = [];
  for (const pos of tilePositions) {
    const [x, y] = pos.split(',').map(Number);
    // Check each neighbor — if missing, add a fringe on that side
    if (!tilePositions.has(`${x},${y - 1}`)) fringes.push({ x, y, side: 'top' });
    if (!tilePositions.has(`${x + 1},${y}`)) fringes.push({ x, y, side: 'right' });
    if (!tilePositions.has(`${x},${y + 1}`)) fringes.push({ x, y, side: 'bottom' });
    if (!tilePositions.has(`${x - 1},${y}`)) fringes.push({ x, y, side: 'left' });
  }
  return fringes;
});

// Check if the player can place a tile
const canPlaceTile = computed(() => {
  return isPlayerTurn.value && pickedTileId.value !== null;
});

// Check if the player can rotate a tile
const canRotateTile = computed(() => {
  return isPlayerTurn.value && pickedTileId.value !== null;
});

// Process tiles to add additional information for rendering
const processedTiles = computed(() => {
  return processTiles(
    gameData.value,
    playerIsAtPosition,
    getPlayerIdAtPosition,
    getAllPlayersAtPosition,
    true // Enable limited logging
  );
});

// Get healing fountain tiles that can be teleported to (excluding current position)
const teleportableHealingFountains = computed(() => {
  const allFountains = healingFountainTiles.value;
  
  // Get current player position from gameData
  let currentPosStr = null;
  
  // Try to get from currentPlayerFieldPosition first
  const currentPos = currentPlayerFieldPosition.value;
  if (currentPos) {
    currentPosStr = `${currentPos.x},${currentPos.y}`;
  } else if (gameData.value?.field?.playerPositions && currentPlayerId.value) {
    // Fallback to getting directly from gameData
    currentPosStr = gameData.value.field.playerPositions[currentPlayerId.value];
  }
  
  if (!currentPosStr) {
    // If we still don't know current position, show all fountains

    return allFountains;
  }
  
  // Filter out the current position
  return allFountains.filter(tile => tile.position !== currentPosStr);
});

// Get all healing fountain tiles for teleportation
const healingFountainTiles = computed(() => {
  const tiles = [];
  
  // First, try to get from gameData.field.tiles
  if (gameData.value?.field?.tiles) {
    const tilesWithFountains = gameData.value.field.tiles
      .filter(tile => tile && tile.features && tile.features.includes('healing_fountain'))
      .map(tile => ({
        x: tile.x,
        y: tile.y,
        position: tile.position
      }))
      .filter(tile => tile.position); // Ensure position exists
    tiles.push(...tilesWithFountains);
  }
  
  // Also check processedTiles as a fallback
  if (processedTiles.value) {
    const processedFountains = processedTiles.value
      .filter(tile => tile && tile.hasHealingFountain)
      .map(tile => ({
        x: tile.x,
        y: tile.y,
        position: tile.position
      }))
      .filter(tile => tile.position); // Ensure position exists
    
    // Add any that aren't already in the list
    processedFountains.forEach(pf => {
      if (!tiles.some(t => t.position === pf.position)) {
        tiles.push(pf);
      }
    });
  }
  
  // Filter out any invalid entries
  const validTiles = tiles.filter(t => t && t.position && typeof t.x === 'number' && typeof t.y === 'number');
  
  return validTiles;
});

// Get available places for rendering
const availablePlaces = computed(() => {
  // Use the enhanced utility function to get all available places
  return getProcessedAvailablePlaces(gameData.value);
});

// Wrapper for playerIsAtPosition function from utils
const playerIsAtPosition = (position) => {
  return isPlayerAt(gameData.value?.field, position);
};

// Wrapper for getPlayerIdAtPosition function from utils
const getPlayerIdAtPosition = (position) => {
  return getPlayerAt(gameData.value?.field, position);
};

// Wrapper for getAllPlayerIdsAtPosition function from utils
const getAllPlayersAtPosition = (position) => {
  return getAllPlayersAt(gameData.value?.field, position);
};

// Wrapper for isPlayerPosition function from utils
const isPlayerPosition = (position) => {
  return isCurrentPlayerAt(gameData.value?.field, position, currentPlayerId.value);
};

// Computed property to track if a request is currently in progress
const isRequestInProgress = computed(() => {
  return isRequestActive();
});

// Update game turns from game data (no separate API call needed)
const updateGameTurns = (gameDataResponse) => {
  if (gameDataResponse && gameDataResponse.turns) {
    gameTurns.value = gameDataResponse.turns.map(turn => ({
      turnId: turn.turnId || turn.turn_id,
      turnNumber: turn.turnNumber || turn.turn_number,
      playerId: turn.playerId || turn.player_id,
      actions: turn.actions || [],
      startTime: turn.startTime || turn.start_time,
      endTime: turn.endTime || turn.end_time,
      pendingItemPickup: turn.pendingItemPickup || turn.pending_item_pickup || false,
    }));
  }
};

// AI turn playback callbacks - apply visual changes to the board step by step
const applyAiTilePlacement = (action) => {
  const result = action.details?.result;
  if (!result?.position) return;

  const position = result.position;
  const [x, y] = position.split(',').map(Number);
  const responseData = result.response || {};

  // Add tile to field data so the watcher triggers fog dissolve and tile rendering
  // (a pushed online turn can arrive after the state that already holds the tile)
  if (gameData.value?.field?.tiles && !gameData.value.field.tiles.some((tile) => tile.position === position)) {
    const tileData = responseData.tile || {};
    const newTile = {
      position,
      x,
      y,
      tileId: result.tileId || tileData.tileId || '',
      room: tileData.room || tileData.tileType || '',
      isPlacingAnimation: true,
      ...tileData,
    };
    gameData.value.field.tiles.push(newTile);

    // Update field size bounds if needed
    if (gameData.value.field.size) {
      const size = gameData.value.field.size;
      if (x < (size.minX ?? x)) size.minX = x;
      if (x > (size.maxX ?? x)) size.maxX = x;
      if (y < (size.minY ?? y)) size.minY = y;
      if (y > (size.maxY ?? y)) size.maxY = y;
    }

    // Update tileOrientations so processTiles renders the correct tile image
    if (gameData.value.field?.tileOrientations && tileData.orientation) {
      const orientationTBLR = parseOrientationString(tileData.orientation);
      const isRoom = !!tileData.room;
      gameData.value.field.tileOrientations[position] = getTileOrientationSymbol(orientationTBLR, isRoom);

      // Update roomFieldPlaces if it's a room tile
      if (isRoom && gameData.value.field.roomFieldPlaces) {
        if (!gameData.value.field.roomFieldPlaces.includes(position)) {
          gameData.value.field.roomFieldPlaces.push(position);
        }
      }
    }

    // The token drawn for the new room (monster, chest…): show it with the tile, before the fight is replayed
    if (result.item) {
      // an empty PHP map arrives as []
      if (!gameData.value.field.items || Array.isArray(gameData.value.field.items)) {
        gameData.value.field.items = { ...(gameData.value.field.items || {}) };
      }
      if (!gameData.value.field.items[position]) {
        gameData.value.field.items[position] = result.item;
      }
    }

    // Clear animation flag after animation completes
    setTimeout(() => {
      const tile = gameData.value?.field?.tiles?.find(t => t.position === position);
      if (tile) tile.isPlacingAnimation = false;
    }, 1000);
  }

  centerViewOnTile(position);
};

let fieldFightKey = 0;
const showAiBattleOverlay = (action, playerId = null) => {
  const battleResult = String(action.battleResult || 'draw').toLowerCase();
  const totalDamage = action.totalDamage || 0;
  const monsterHP = action.monsterHP || 0;
  const diceResults = action.diceResults || [];
  const monsterType = action.monsterType || '';

  // Position comes from the enriched battleInfo, or fall back to AI player's current position
  let battlePosition = action.position;
  if (!battlePosition) {
    const aiPlayerId = playerId || gameData.value?.state?.currentPlayerId;
    if (aiPlayerId && gameData.value?.field?.playerPositions) {
      battlePosition = gameData.value.field.playerPositions[aiPlayerId];
    }
  }

  if (!battlePosition) return;

  const [x, y] = battlePosition.split(',').map(Number);

  const fighter = gameData.value?.players?.find((p) => p.id === (playerId || localStorage.getItem('virtualPlayerId')));
  fieldFightKey += 1;
  aiBattleOverlay.value = {
    key: fieldFightKey,
    position: battlePosition,
    x,
    y,
    playerDamage: totalDamage,
    monsterHP,
    diceResults,
    result: ['win', 'lose', 'draw'].includes(battleResult) ? battleResult : 'draw',
    monsterType,
    heroKey: figureHeroKey(fighter),
    speed: aiPlayback.playbackSpeed.value || 1,
  };

  centerViewOnTile(battlePosition);
};

const clearAiBattleOverlay = () => {
  aiBattleOverlay.value = null;
  aiRoomOverlay.value = null;
};

// Karak: Regent — a Cursed Room roll played back on its step, over the room, instead of the full-screen scene at the end
const aiRoomOverlay = ref(null);
// the rolls of a played-back turn are its steps: the state that brings them later shows no scene for them
const claimPlaybackRoomRolls = (rawActions) => {
  const events = (rawActions || []).find((a) => a.type === 'room_events')?.details?.events || [];
  events.forEach((event) => shownRoomEventIds.add(roomEventKey(event)));
};
const showAiRoomRoll = (action) => {
  const event = action.event;
  if (!event?.position) return;
  aiBattleOverlay.value = null;
  const [x, y] = event.position.split(',').map(Number);
  const player = gameData.value?.players?.find((p) => p.id === event.playerId);
  const who = heroNameById(event.playerId);
  const notice = event.alreadyCursed ? 'game.notice.cursedRoomAlready' : event.cursed ? 'game.notice.cursedRoomCursed' : 'game.notice.cursedRoomSafe';
  showActionNotice(t(notice, { who, roll: event.roll }), 'notice');
  fieldFightKey += 1;
  aiRoomOverlay.value = { key: fieldFightKey, x, y, event, name: who, heroKey: figureHeroKey(player), speed: aiPlayback.playbackSpeed.value || 1 };
  centerViewOnTile(event.position);
};
// the outcome is shown: a cursed hero's token flies onto his card in the rail
const onAiRoomRollSettled = (tokenRect) => {
  const event = aiRoomOverlay.value?.event;
  if (!event || event.alreadyCursed) return;
  if (!event.cursed) {
    audio.cue('curse_lifted');
    return;
  }
  flyCurseToken(tokenRect, document.querySelector(`.party-member[data-player-id="${event.playerId}"] .party-member__medallion`));
};

const applyAiPlayerMovement = (action, playerId) => {
  const result = action.details?.result;
  if (!result) return;

  const to = result.to;
  if (!to) return;

  // Update player position in game data (Magic Swap: the rival takes the hero's old tile)
  if (gameData.value?.field?.playerPositions) {
    gameData.value.field.playerPositions[playerId] = to;
    if (result.swapWith?.playerId && result.swapWith.to) {
      gameData.value.field.playerPositions[result.swapWith.playerId] = result.swapWith.to;
    }
  }

  // Also update in players array if present
  if (gameData.value?.players) {
    const player = gameData.value.players.find(p => p.id === playerId || p.playerId === playerId);
    if (player) {
      player.position = to;
    }
  }

  centerViewOnTile(to);
};

// Track which turn number we last executed AI for - this is our single source of truth
let lastExecutedAITurn = null;
// Karak: Regent — the AI's turn waits for the hero it summoned to a duel (resumed once the duel is over and closed)
let aiPausedForDuel = false;
// Track if we have an AI execution in progress for a specific turn
let aiExecutionInProgressForTurn = null;
// Promise to track ongoing AI execution
let aiExecutionPromise = null;
// Track if AI turn is in progress
let aiTurnInProgress = false;

// A game with AI: the AI flags still set while the server says it is certainly the human's turn are cleared
watch(
  () => {
    const state = gameData.value?.state;
    if (!state || isOnline.value || !gameData.value?.players?.some((p) => p.isAi === true)) return false;
    const humanPlayerId = localStorage.getItem('humanPlayerId');
    return isProcessingAI.value && !!humanPlayerId && state.currentPlayerId === humanPlayerId
      && !isVirtualPlayer(state.currentPlayerId);
  },
  (stale) => {
    if (!stale) return;
    isProcessingAI.value = false;
    aiTurnInProgress = false;
    aiExecutionPromise = null;
  },
  { flush: 'sync' },
);

// Check if current player is virtual and handle their turn
const checkAndHandleVirtualPlayerTurn = async () => {
  try {
    if (!gameData.value?.state) {
      return;
    }
    // the server plays the AI seats of an online game and pushes the turn (playOnlineAiTurn)
    if (gameData.value.state.online) {
      return;
    }
    // a Cursed Room's die is still on screen: the AI starts when it is closed (closeCursedRoomScene)
    if (cursedRoomScene.value || roomSceneQueue.length > 0) {
      return;
    }
    
    // First check if there's actually an AI player in the game
    const hasAIPlayer = gameData.value.players?.some(p => p.isAi === true);
    if (!hasAIPlayer) {
      // No AI players in this game - it's a PvP game
      if (isProcessingAI.value) {

        isProcessingAI.value = false;
        aiExecutionInProgressForTurn = null;
        aiExecutionPromise = null;
      }
      return;
    }
    
    const currentTurnNumber = gameData.value?.state?.turn;
    const currentPlayer = gameData.value.state.currentPlayer || gameData.value.state.currentPlayerId;
    // any AI seat plays when it is current (a hot-seat game may have several; virtualPlayerId names one)
    const isAITurn = isVirtualPlayer(currentPlayer);
    if (isAITurn && currentPlayer !== localStorage.getItem('virtualPlayerId')) {
      console.info('[hotseat] AI seat plays', currentPlayer);
    }
    

    
    if (!isAITurn) {
      // Human turn - clear all AI flags (a paused AI turn ended with its duel)
      if (aiPausedForDuel && !visibleDuel.value) aiPausedForDuel = false;
      if (isProcessingAI.value) {

        isProcessingAI.value = false;
        aiExecutionInProgressForTurn = null;
        aiExecutionPromise = null;
      }
      return;
    }
    
    // Don't execute AI turn if game is finished
    if (gameData.value.state.status === 'finished') {

      isProcessingAI.value = false;
      return;
    }
    
    // The AI waits for my duel: my spells, my roll, my loot, then the outcome closed. The duel ends the AI's turn,
    // so once it is over this is usually the next player's turn already (or the AI's own when it was settled early)
    if (aiPausedForDuel) {
      const phase = gameData.value.state.duel?.phase;
      if (['opponent_prepare', 'opponent_roll', 'loot'].includes(phase) || visibleDuel.value) return;
      console.info('[duel] the duel is over: the turn goes on from the server state');
      aiPausedForDuel = false;
    }

    // A duel's outcome is still on screen (a duel ends the challenger's turn): the AI starts once it is closed,
    // else its turn hid the outcome and could open the next duel right over it
    if (visibleDuel.value?.phase === 'done') {
      console.info('[duel] the AI waits until the duel outcome is closed');
      return;
    }

    // Check if we already executed this turn
    if (lastExecutedAITurn === currentTurnNumber) {

      return;
    }
    
    // Check if execution is in progress for this turn
    if (aiExecutionInProgressForTurn === currentTurnNumber) {

      if (aiExecutionPromise) {
        await aiExecutionPromise;
      }
      return;
    }
    
    // This is a new AI turn that needs execution

    // Clear any loading overlay from the previous turn (e.g. "Finalizing battle...")
    // so the AI playback is visible on the field
    loading.value = false;
    loadingStatus.value = '';

    // Block user interactions
    isProcessingAI.value = true;
    
    // Mark this specific turn as being executed
    aiExecutionInProgressForTurn = currentTurnNumber;
    
    // Start stuck detection timer (10 seconds)
    if (aiStuckTimer) {
      clearTimeout(aiStuckTimer);
    }
    aiStuckDetected.value = false;
    
    aiStuckTimer = setTimeout(() => {
      if (aiExecutionInProgressForTurn === currentTurnNumber) {

        aiStuckDetected.value = true;
        
        // Auto-recover after 5 more seconds
        setTimeout(() => {
          if (aiStuckDetected.value && aiExecutionInProgressForTurn === currentTurnNumber) {

            forceAITurn();
          }
        }, 5000);
      }
    }, 10000);
    
    // Create a promise for the AI execution
    aiExecutionPromise = (async () => {
      try {
        // Add a small delay
        await new Promise(timeout => setTimeout(timeout, 1000));
        

        aiTurnInFlight.value = true;
        const response = await gameApi.executeVirtualPlayerTurn(id.value, currentPlayer);
        
        if (response.success) {


          // Mark this turn as successfully executed
          lastExecutedAITurn = currentTurnNumber;

          // Play back AI actions step by step before loading final state
          claimPlaybackRoomRolls(response.actions);
          const filteredActions = aiPlayback.filterActions(response.actions || []);
          if (filteredActions.length > 0) {
            await aiPlayback.playActions(filteredActions, {
              onPlaceTile: (action) => { clearAiBattleOverlay(); applyAiTilePlacement(action); },
              onMovePlayer: (action) => { clearAiBattleOverlay(); applyAiPlayerMovement(action, currentPlayer); },
              onBattle: (action) => showAiBattleOverlay(action, currentPlayer),
              onCursedRoom: (action) => showAiRoomRoll(action),
              onPickup: () => clearAiBattleOverlay(),
              onEndTurn: () => clearAiBattleOverlay(),
            });
            clearAiBattleOverlay();
          }
          aiTurnInFlight.value = false;

          // Sync to authoritative final state
          await loadGameData(false);

          // Karak: Regent — the AI's turn waits for me: the hero it summoned rolls here, the turn goes on after the duel
          const paused = (response.actions || []).some((a) => a.type === 'ai_paused');
          if (paused) {
            console.info('[duel] the AI waits for the summoned hero');
            aiPausedForDuel = true;
            lastExecutedAITurn = null;
            isProcessingAI.value = false;
            showPendingDuelRoll(gameData.value);
          }

          // After loading, check if there's another AI turn to execute
          // This handles consecutive AI turns when human is stunned
          const newGameState = gameData.value;
          if (!paused && newGameState?.state) {
            const newTurn = newGameState.state.turn;
            const newCurrentPlayer = newGameState.state.currentPlayer || newGameState.state.currentPlayerId;
            const isStillAI = isVirtualPlayer(newCurrentPlayer);
            
            if (isStillAI && newTurn !== currentTurnNumber && newTurn !== lastExecutedAITurn) {

              // Don't clear isProcessingAI - keep it blocked
              // The next call to checkAndHandleVirtualPlayerTurn will handle it
              setTimeout(() => checkAndHandleVirtualPlayerTurn(), 100);
            } else {
              // Clear isProcessingAI if it's now human's turn
              if (!isStillAI) {

                isProcessingAI.value = false;
              }
            }
          }
        } else {
          console.error('Virtual player turn failed:', response);
          isProcessingAI.value = false;
        }
      } catch (error) {
        console.error('Error executing AI turn:', error);
        isProcessingAI.value = false;
      } finally {
        aiTurnInFlight.value = false;
        // Clear the execution flag for this turn
        if (aiExecutionInProgressForTurn === currentTurnNumber) {
          aiExecutionInProgressForTurn = null;
        }
        aiExecutionPromise = null;
        
        // Clear stuck detection
        if (aiStuckTimer) {
          clearTimeout(aiStuckTimer);
          aiStuckTimer = null;
        }
        aiStuckDetected.value = false;
      }
    })();
    
    // Wait for completion
    await aiExecutionPromise;
  } catch (error) {
    console.error('Error in checkAndHandleVirtualPlayerTurn:', error);
    isProcessingAI.value = false;
    aiExecutionInProgressForTurn = null;
    aiExecutionPromise = null;
  }
};

// ===== Online play: what the server pushes for this game =====
// Another seat fought: a short notice with the chronicle's sentence (the field and the log update by themselves)
const noticeOpponentFight = (data) => {
  // the latest fight of the game: a fight usually ends its turn, so by the time the state comes the current turn
  // is already the next player's (looking only there missed every rival's fight)
  const turns = [...(data?.turns || [])].sort((a, b) => (b.turnNumber || 0) - (a.turnNumber || 0));
  let turn = null;
  let index = -1;
  for (const candidate of turns) {
    const found = (Array.isArray(candidate.actions) ? candidate.actions : []).map((a) => a?.action || a?.type).lastIndexOf('fight_monster');
    if (found >= 0) {
      turn = candidate;
      index = found;
      break;
    }
  }
  if (!turn) return;
  const actions = turn.actions;
  const key = `${turn.turnId || turn.turnNumber}:${index}`;
  const first = lastOnlineFightKey === null;
  if (key === lastOnlineFightKey) return;
  lastOnlineFightKey = key;
  // the first state of the page shows the field as it is; my own fights have their own scene; an AI seat's
  // fights are played back with its turn (ai.turn)
  const fighter = data.players?.find((p) => p.id === turn.playerId);
  if (first || suppressFightNotice || turn.playerId === mySeatId.value || fighter?.isAi) return;
  const nameOf = (playerId) => {
    const player = data.players?.find((p) => p.id === playerId);
    return player ? playerDisplayName(player, data.players) : t('common.unknown');
  };
  const line = describeAction(actions[index], nameOf(turn.playerId), nameOf, actions);
  if (line) showActionNotice(line, 'notice');
  const fight = actions[index]?.additionalData || actions[index]?.details || {};
  if (fight.toPosition) {
    const action = {
      battleResult: fight.result,
      totalDamage: fight.totalDamage ?? fight.diceRollDamage ?? 0,
      monsterHP: fight.monster?.hp ?? 0,
      diceResults: fight.diceResults || [],
      monsterType: fight.monster?.name || '',
      position: fight.toPosition,
    };
    showAiBattleOverlay(action, turn.playerId);
    audio.cue('dice_roll');
    const blow = { win: 'hit_win', lose: 'hit_lose', draw: 'draw' }[String(fight.result || '').toLowerCase()];
    if (blow) setTimeout(() => audio.cue(blow), 1150);
    const shown = aiBattleOverlay.value?.key;
    setTimeout(() => { if (aiBattleOverlay.value?.key === shown) clearAiBattleOverlay(); }, 2800);
  }
};

// A turn the server played (an AI seat, or the AI standing in for an away player): played back like the AI's
const playOnlineAiTurn = async (message) => {
  // its Cursed Room rolls are played on their steps, not as scenes of a state loaded meanwhile
  claimPlaybackRoomRolls(message.actions);
  onlinePlaybackQueue.push(message);
  if (onlinePlaybackActive) return;
  onlinePlaybackActive = true;
  isProcessingAI.value = true;
  try {
    while (onlinePlaybackQueue.length) {
      // a Cursed Room's die on screen is read first
      await whenRoomSceneClosed();
      const next = onlinePlaybackQueue.shift();
      const actions = aiPlayback.filterActions(next.actions || []);
      console.info('[online] playing back the server turn of', next.playerId, `(${actions.length} actions${next.standIn ? ', stand-in' : ''})`);
      if (next.standIn) showActionNotice(t('tables.standIn', { who: heroNameById(next.playerId) }), 'notice');
      if (actions.length) {
        await aiPlayback.playActions(actions, {
          onPlaceTile: (action) => { clearAiBattleOverlay(); applyAiTilePlacement(action); },
          onMovePlayer: (action) => { clearAiBattleOverlay(); applyAiPlayerMovement(action, next.playerId); },
          onBattle: (action) => showAiBattleOverlay(action, next.playerId),
          onCursedRoom: (action) => showAiRoomRoll(action),
          onPickup: () => clearAiBattleOverlay(),
          onEndTurn: () => clearAiBattleOverlay(),
        });
        clearAiBattleOverlay();
      }
      // the state after this turn before the next one (stand-in turns can come one after another)
      if (onlinePlaybackQueue.length) {
        suppressFightNotice = true;
        try {
          await loadGameData(false, { force: true });
        } finally {
          suppressFightNotice = false;
        }
      }
    }
  } catch (err) {
    console.error('[online] the playback failed:', err);
  } finally {
    onlinePlaybackActive = false;
    onlineReloadPending = false;
    isProcessingAI.value = false;
    // its fights were just played back (a stand-in plays a human seat): the state after it shows them no more
    suppressFightNotice = true;
    try {
      await loadGameData(false, { force: true });
    } finally {
      suppressFightNotice = false;
    }
  }
};

let onlineReloadTimer = null;
const onPushMessage = (message) => {
  if (!message || message.gameId !== id.value) return;
  if (message.type === 'ai.turn') {
    playOnlineAiTurn(message);
    return;
  }
  if (message.type !== 'game.changed') return;
  tableRefreshKey.value += 1;
  // my own commands already reload the page; a burst of changes is one reload
  if (message.playerId && message.playerId === mySeatId.value) return;
  clearTimeout(onlineReloadTimer);
  onlineReloadTimer = setTimeout(() => loadGameData(false), 150);
};

let unsubscribePush = null;
const startPush = () => {
  if (unsubscribePush || !id.value) return;
  unsubscribePush = subscribePush(pushTopics.game(id.value), onPushMessage, {
    onState: (state) => {
      pushReconnecting.value = state === 'reconnecting';
      // what happened while the stream was down comes with the state
      if (state === 'reopened') {
        tableRefreshKey.value += 1;
        loadGameData(false);
      }
    },
  });
};
// the stream opens once the game is known to be online (games in one browser have nothing to listen to)
watch(isOnline, (online) => { if (online) startPush(); }, { immediate: true });
onBeforeUnmount(() => {
  unsubscribePush?.();
  unsubscribePush = null;
  clearTimeout(onlineReloadTimer);
});

// Removed executeVirtualPlayerTurn - logic is now integrated into checkAndHandleVirtualPlayerTurn

const loadGameData = async (showLoading = true, { force = false } = {}) => {
  // an online playback is on screen: the final state comes when it ends
  if (onlinePlaybackActive && !force) {
    onlineReloadPending = true;
    return;
  }
  await accountReady;
  if (!id.value) {
    console.error('No game ID found in route params');
    error.value = t('errors.invalidGame');
    loading.value = false;
    // Reset request lock on error
    resetRequestLock();
    return;
  }



  try {
    // Attempt to load the game data
    if (showLoading) {
      loading.value = true;
    }
    const fetchedGameData = await gameApi.getGame(id.value);
    adoptThisGamesPlayer(fetchedGameData);
    followHotseatSeat(fetchedGameData);
    if (fetchedGameData?.state?.online) noticeOpponentFight(fetchedGameData);


    // Initialize gameData if it doesn't exist yet
    if (!gameData.value) {
      gameData.value = fetchedGameData;
    } else {
      // Use selective update for existing gameData
      updateGameDataSelectively(fetchedGameData);
    }

    // Update game turns from the fetched game data
    updateGameTurns(fetchedGameData);

    // Oracle "Fateweaver": a pending two-token choice blocks the turn until it is settled
    if (fetchedGameData.state?.fateweaverChoice && isPlayerTurn.value && !fateweaverPrompt.value) {
      resolvePendingFateweaver(fetchedGameData.state.fateweaverChoice);
    }

    // Check for unplaced tile from backend and sync with frontend state
    if (fetchedGameData.state?.unplacedTile) {
      const unplacedTileData = fetchedGameData.state.unplacedTile;
      if (unplacedTileData.tileId) {
        // Backend has an unplaced tile, sync it with frontend

        
        // Check if we have tile data
        if (unplacedTileData.tile) {
          // Full tile data available
          pickedTileId.value = unplacedTileData.tileId;
          pickedTile.value = unplacedTileData.tile;
          ghostTilePosition.value = unplacedTileData.fieldPlace;
          // Parse the orientation string to get the proper format
          ghostTileOrientation.value = parseOrientationString(unplacedTileData.tile.orientation) || null;
          isPlacingTile.value = unplacedTileData.fieldPlace ? true : false;
          
          // Store in localStorage for consistency
          const tileState = {
            gameId: id.value,
            tileId: unplacedTileData.tileId,
            tile: unplacedTileData.tile,
            position: unplacedTileData.fieldPlace,
            orientation: unplacedTileData.tile.orientation
          };
          localStorage.setItem('pickedTileState', JSON.stringify(tileState));
        } else {
          // Only tileId available, tile was picked but we don't have full data
          // Clear the picked tile state to avoid inconsistency

          clearPickedTileState();
          pickedTileId.value = null;
          pickedTile.value = null;
          ghostTilePosition.value = null;
          ghostTileOrientation.value = null;
          isPlacingTile.value = false;
        }
      }
    } else {
      // No unplaced tile on backend, clear frontend state if it exists
      if (pickedTileId.value) {

        clearPickedTileState();
        pickedTileId.value = null;
        pickedTile.value = null;
        ghostTilePosition.value = null;
        ghostTileOrientation.value = null;
        isPlacingTile.value = false;
      }
    }

    // Check if it's a virtual player's turn and handle it
    const currentPlayer = fetchedGameData.state?.currentPlayer || fetchedGameData.state?.currentPlayerId;
    let virtualPlayerId = localStorage.getItem('virtualPlayerId');
    const humanPlayerId = localStorage.getItem('currentPlayerId');
    
    // If virtualPlayerId is missing but we have an AI player, detect and store it
    if (!virtualPlayerId && fetchedGameData.players) {
      const aiPlayer = fetchedGameData.players.find(p => p.isAi === true);
      if (aiPlayer) {

        localStorage.setItem('virtualPlayerId', aiPlayer.id);
        virtualPlayerId = aiPlayer.id;
      }
    }
    
    const isCurrentlyAITurn = !!currentPlayer && (fetchedGameData.players?.find((p) => p.id === currentPlayer)?.isAi === true
      || currentPlayer === virtualPlayerId);
    
    // If it's an AI turn, always ensure isProcessingAI is true to prevent user interaction
    if (isCurrentlyAITurn) {
      if (!isProcessingAI.value) {



        isProcessingAI.value = true;
      }
      
      // Simply call checkAndHandleVirtualPlayerTurn - it will handle all the logic
      await checkAndHandleVirtualPlayerTurn();
    } else {
      // It's a human turn, clear all AI flags
      if (isProcessingAI.value || aiExecutionInProgressForTurn) {



        isProcessingAI.value = false;
        aiExecutionInProgressForTurn = null;
        aiExecutionPromise = null;
      }
    }
    






    // A reload in the middle of a post-fight decision (pick up / leave / retreat): bring the fight back once,
    // otherwise the field offers new rooms and the backend refuses PICK_TILE after FIGHT_MONSTER
    if (!pendingBattleRestored.value) {
      pendingBattleRestored.value = true;
      const pending = fetchedGameData.state?.lastBattleInfo;
      if (battleAwaitsDecision(fetchedGameData, pending) && pending.player === currentPlayerId.value
        && !showBattleReportModal.value && lastProcessedBattleId.value !== pending.battleId) {
        console.debug('[fight] restoring the pending fight after a reload', pending.battleId);
        // the stored fight keeps curseChoiceRequired after the curse was placed: trust the live curse state
        battleInfo.value = pending.curseChoiceRequired
          ? { ...pending, curseChoiceRequired: fetchedGameData.state?.curse?.pendingBy === currentPlayerId.value }
          : pending;
        showBattleReportModal.value = true;
      }
    }

    // Check for pending battle info and show battle modal if needed
    if (fetchedGameData.field && fetchedGameData.field.lastBattleInfo) {
      const battleData = fetchedGameData.field.lastBattleInfo;

      // Check if this battle has already been processed
      const isAlreadyProcessed = lastProcessedBattleId.value === battleData.battleId;
      
      // Only show battle modal if:
      // 1. It's for the current player
      // 2. Modal isn't already shown
      // 3. We haven't explicitly skipped re-showing it
      // 4. This battle hasn't been processed yet
      if (battleData.player === currentPlayerId.value && !showBattleReportModal.value && !skipBattleModalReshow.value && !isAlreadyProcessed) {

        battleInfo.value = battleData;
        showBattleReportModal.value = true;
      } else if (skipBattleModalReshow.value || isAlreadyProcessed) {

        skipBattleModalReshow.value = false; // Reset the flag for future battles
      }
    }

    // Karak: Regent — the summoned rival rolls in this browser
    showPendingDuelRoll(fetchedGameData);

    // Check if we need to update our current player
    if (fetchedGameData.state && fetchedGameData.state.currentPlayerId) {
      checkStunnedPlayersAndSwitch();
    }
    
    // Set game as started if appropriate
    if (gameData.value.state && (gameData.value.state.status === 'started' || gameData.value.state.status === 'turn_in_progress')) {
      // Set gameStarted to true when loading an already started game
      gameStarted.value = true;

      // Also update playerIsReady status
      if (isPlayerInGame.value) {
        playerIsReady.value = true;
      }
    }

    // Initialize game here if using Phaser
    initGame();
    error.value = null;

    // Reset request lock after successful load
    resetRequestLock();
  } catch (err) {
    console.error('Error loading game:', err);
    error.value = t('errors.loadGame');
    // Reset request lock on error
    resetRequestLock();
  } finally {
    if (showLoading) {
      loading.value = false;
      loadingStatus.value = '';
    }
  }
};

// Watch for route changes to reload data if necessary
watch(() => route.params.id, (newId, oldId) => {
  if (newId !== oldId) {
    // Clear picked tile state if changing games
    clearPickedTileState();
    pickedTileId.value = null;
    pickedTile.value = null;
    ghostTilePosition.value = null;
    ghostTileOrientation.value = null;
    isPlacingTile.value = false;
    loadGameData();
  }
});

onMounted(async () => {

  
  // Expose handleFinalizeBattleAndPickUp globally for debugging
  window.gameViewHandleFinalizeBattleAndPickUp = handleFinalizeBattleAndPickUp;
  // Expose resetRequestLock for E2E tests
  window.__resetRequestLock = resetRequestLock;


  try {
    // Initial game data load
    loading.value = true;
    loadingStatus.value = 'Loading game data...';
    await loadGameData();
    
    // After loading game data, restore picked tile state if any
    if (restorePickedTileState()) {

      
      // Validate that the deck hasn't changed since we picked the tile
      // If the deck is now empty or tiles have been placed, clear the state
      if (gameData.value?.state?.deck?.isEmpty) {

        clearPickedTileState();
        pickedTileId.value = null;
        pickedTile.value = null;
        ghostTilePosition.value = null;
        ghostTileOrientation.value = null;
        isPlacingTile.value = false;
      }
      
      // Check if the tile has already been placed on the field
      // This can happen if the placement was successful but UI didn't update properly
      if (pickedTileId.value && gameData.value?.field?.tiles) {
        const tileAlreadyPlaced = gameData.value.field.tiles.some(tile => 
          tile.tileId === pickedTileId.value
        );
        
        if (tileAlreadyPlaced) {

          clearPickedTileState();
          pickedTileId.value = null;
          pickedTile.value = null;
          ghostTilePosition.value = null;
          ghostTileOrientation.value = null;
          isPlacingTile.value = false;
        }
      }
    }

    // Initialize WebSocket for real-time updates
    // initWebSocket();
  } catch (err) {
    console.error('Error loading game data:', err);
    error.value = t('errors.loadGameData', { reason: err.message });
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
});

onBeforeUnmount(() => {
  // Clean up WebSocket connection when component is destroyed
  // if (socket.value) {
  //   socket.value.close();
  //   socket.value = null;
  // }
});

// Helper function to selectively update game data without replacing the entire object
// This function has been moved to @/utils/gameUtils.js
// Import is already at the top of this file
const updateGameDataSelectively = (updatedData) => {
  // Check for HP changes before updating
  if (updatedData.players && gameData.value?.players) {
    updatedData.players.forEach(updatedPlayer => {
      const currentPlayer = gameData.value.players.find(p => p.id === updatedPlayer.id);
      if (currentPlayer && currentPlayer.hp < updatedPlayer.hp) {
        // Player was healed!
        const healAmount = updatedPlayer.hp - currentPlayer.hp;
        
        // Check if player is at a healing fountain tile
        const playerPosition = updatedData.field?.playerPositions?.[updatedPlayer.id];
        if (playerPosition) {
          const tile = processedTiles.value?.find(t => t.position === playerPosition);
          if (tile?.hasHealingFountain) {
            // Show healing notification with the position from updated data
            showHealingNotificationForPlayer(updatedPlayer.id, healAmount, playerPosition);
          }
        }
      }
    });
  }
  
  // Call the imported function with gameData.value as the first parameter
  return doUpdateGameDataSelectively(gameData.value, updatedData);
};

const initGame = () => {
  // Call the imported initGame function with the necessary parameters
  doInitGame(id.value, gameData.value, centerViewOnCurrentPlayer);
};

// Function to join the game
const joinGame = async () => {
  try {
    // Call the extracted joinGame utility function from gameUtils
    const result = await doJoinGame(
      id.value, 
      currentPlayerId.value, 
      gameApi, 
      updateGameDataSelectively,
      (value) => loading.value = value
    );

    // Update the player ID if a new one was generated
    if (result && result.playerId) {
      currentPlayerId.value = result.playerId;
      localStorage.setItem('currentPlayerId', currentPlayerId.value);
    }
  } catch (err) {
    console.error('Failed to join game:', err);
    error.value = err.message || t('errors.joinGame');
  }
};

// Function to mark player as ready
const getPlayerReady = async () => {
  try {
    // Call the extracted getPlayerReady utility function
    const result = await getPlayerReadyUtil(
      id.value,
      currentPlayerId.value,
      gameApi,
      updateGameDataSelectively,
      (value) => loading.value = value,
      (value) => loadingStatus.value = value,
      (value) => error.value = value
    );

    // Set player as ready if successful
    if (result && result.success) {
      playerIsReady.value = true;
    }
  } catch (err) {
    console.error('Failed to mark player as ready:', err);
    // Error is already set in the utility function
  }
};

// Function to add a second player and set them ready
const addSecondPlayer = async () => {
  try {
    // Start loading without destroying UI
    loading.value = true;
    loadingStatus.value = 'Adding second player...';

    // Generate a new player ID for the second player
    secondPlayerId.value = generateUUID();
    localStorage.setItem('secondPlayerId', secondPlayerId.value);



    // Join the game with the second player (as Player 2)
    await gameApi.joinGame(id.value, secondPlayerId.value, 'Player 2');

    // Mark the second player as ready
    loadingStatus.value = 'Getting second player ready...';
    await gameApi.playerReady(id.value, secondPlayerId.value);

    // Refresh game data
    loadingStatus.value = 'Updating game status...';
    const updatedGameData = await gameApi.getGame(id.value);
    updateGameDataSelectively(updatedGameData);


  } catch (err) {
    console.error('Failed to add second player:', err);
    error.value = t('errors.secondPlayer', { reason: err.message });
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Zoom control functions
const zoomIn = () => {
  zoomLevel.value = Math.min(zoomLevel.value + 0.2, 2);
};

const zoomOut = () => {
  zoomLevel.value = Math.max(zoomLevel.value - 0.2, 0.5);
};

const resetZoom = () => {
  zoomLevel.value = 1;
  // Scroll to middle of the field
  if (gameData.value && gameData.value.field && gameData.value.field.size) {
    centerViewOnMiddle();
  }
};

// Function to center view on a specific tile
const centerViewOnTile = (tile) => {
  if (!tile) return;

  // If tile is already a proper object with x and y properties
  if (tile.x !== undefined && tile.y !== undefined) {
    scrollToPosition(tile.x, tile.y);
  } 
  // If we got a position string (common in some API responses)
  else if (typeof tile === 'string') {
    const [x, y] = tile.split(',').map(Number);
    scrollToPosition(x, y);
  }
  // If we got a position object in a different format
  else if (tile.position) {
    const [x, y] = tile.position.split(',').map(Number);
    scrollToPosition(x, y);
  }
};

// Center view on the middle of the field
const centerViewOnMiddle = () => {
  const fieldElement = document.querySelector('.game-field');
  if (!fieldElement || !gameData.value || !gameData.value.field || !gameData.value.field.size) return;

  const minX = gameData.value.field.size.minX || 0;
  const maxX = gameData.value.field.size.maxX || 0;
  const minY = gameData.value.field.size.minY || 0;
  const maxY = gameData.value.field.size.maxY || 0;

  // Center of the field in pixels; the padding and the scroll range come from the element (fieldUtils)
  const centerX = ((maxX + minX) / 2 - minX) * tileSize.value;
  const centerY = ((maxY + minY) / 2 - minY) * tileSize.value;

  fieldElement.scrollTo({
    ...calculateScrollPosition(centerX, centerY, fieldElement),
    behavior: 'smooth'
  });
};

// Tile highlighting functions
const highlightTile = (tileData, centerView = false) => {
  const tile = highlightTileUtil(tileData, processedTiles.value, centerViewOnTile, centerView);
  if (tile) {
    highlightedTile.value = tile;
  }
};

const unhighlightTile = () => {
  highlightedTile.value = unhighlightTileUtil();
};

// Function to start the game
const startGame = async () => {
  try {
    // Call the extracted startGame utility function
    await doStartGame(
      id.value,
      gameApi,
      updateGameDataSelectively,
      (value) => loading.value = value,
      (value) => loadingStatus.value = value,
      (value) => error.value = value,
      (value) => gameStarted.value = value
    );

  } catch (err) {
    console.error('Failed to start game:', err);
    // Error is already set in the utility function
  }
};

// Watch for changes in tile size and update the CSS variable
watch(tileSize, (newSize) => {
  document.documentElement.style.setProperty('--tile-size', `${newSize}px`);

  // Also update the tile size CSS variable
  const tiles = document.querySelectorAll('.tile');
  if (tiles.length) {
    tiles.forEach(tile => {
      tile.style.setProperty('--tile-size', `${newSize}px`);
    });
  }
});

// Watch for picked tile changes to save state
watch([pickedTileId, pickedTile, ghostTilePosition, ghostTileOrientation, isPlacingTile], () => {
  if (pickedTileId.value && pickedTile.value) {
    savePickedTileState();
  } else {
    clearPickedTileState();
  }
});

// Watch for orientation changes during tile placement
watch(ghostTileOrientation, (newVal, oldVal) => {
  if (newVal !== oldVal && newVal) {
    const newSymbol = getTileOrientationSymbol(newVal, pickedTile.value?.room);
    const newClass = getTileOrientationClass(newVal, pickedTile.value?.room);

    
    // Force update of the ghost tile by triggering a re-render
    nextTick(() => {

    });
  }
});

// Watch for changes in field dimensions
// Track tile positions to trigger fog dissolve when new tiles appear
const knownTilePositions = ref(new Set());

watch(() => gameData.value?.field?.tiles, (tiles) => {
  if (!tiles) return;
  const currentPositions = new Set(tiles.map(t => t.position));
  // Find newly appeared tile positions
  for (const pos of currentPositions) {
    if (!knownTilePositions.value.has(pos)) {
      dissolvingFogPositions.value.add(pos);
      // Remove from dissolving set after animation completes
      setTimeout(() => {
        dissolvingFogPositions.value.delete(pos);
      }, 800);
    }
  }
  knownTilePositions.value = currentPositions;
}, { deep: true, immediate: true });

watch(() => gameData.value?.field?.size, (newSize) => {
  if (newSize) {
    document.documentElement.style.setProperty('--field-min-x', newSize.minX || 0);
    document.documentElement.style.setProperty('--field-min-y', newSize.minY || 0);

    // Update all tiles with the new field dimensions
    const tiles = document.querySelectorAll('.tile');
    if (tiles.length) {
      tiles.forEach(tile => {
        tile.style.setProperty('--field-min-x', newSize.minX || 0);
        tile.style.setProperty('--field-min-y', newSize.minY || 0);
      });
    }
  }
}, { deep: true });

// Handle window resize by resetting zoom and letting the computed properties recalculate
const handleResize = () => {
  zoomLevel.value = 1;
  // Simply accessing the fieldStyles computed property will trigger recalculation
  const _ = fieldStyles.value;
};

// Clean up event listener on component unmount
onUnmounted(() => {
  window.removeEventListener('resize', handleResize);

  // Remove global event listeners for drag navigation
  window.removeEventListener('mouseup', onMouseUp);
  window.removeEventListener('mousemove', onMouseMove);

  // Remove keyboard event listeners for CTRL key detection
  window.removeEventListener('keydown', onKeyDown);
  window.removeEventListener('keyup', onKeyUp);

  // Remove keyboard listener for combined keyboard events
  window.removeEventListener('keydown', handleKeyboardEvents);

  // Remove keyboard listeners for modal overlays
  window.removeEventListener('keydown', handleLeaderboardKeyDown, true);
  window.removeEventListener('keydown', handleStunnedKeyDown, true);

  // Clear the polling interval
  if (gamePollingInterval.value) {
    clearInterval(gamePollingInterval.value);
    gamePollingInterval.value = null;
  }
});

// Methods for drag navigation
const onMouseDown = (e) => {
  if (!fieldElement.value) return;

  // Also start dragging on middle-click (button 1) or when holding space with any mouse button
  if (e.button === 1 || (e.button === 0 && (e.ctrlKey || isCtrlPressed.value))) {
    isDragging.value = true;
    startDragX.value = e.pageX;
    startDragY.value = e.pageY;
    startScrollLeft.value = fieldElement.value.scrollLeft;
    startScrollTop.value = fieldElement.value.scrollTop;

    // Change cursor style
    fieldElement.value.style.cursor = 'grabbing';

    // Prevent default behavior (like text selection)
    e.preventDefault();
  }
};

const onMouseMove = (e) => {
  if (!isDragging.value || !fieldElement.value) return;

  // Calculate distance moved
  const dx = e.pageX - startDragX.value;
  const dy = e.pageY - startDragY.value;

  // Scroll in the opposite direction of drag
  fieldElement.value.scrollLeft = startScrollLeft.value - dx;
  fieldElement.value.scrollTop = startScrollTop.value - dy;

  // Prevent default behavior
  e.preventDefault();
};

const onMouseUp = () => {
  if (!isDragging.value) return;

  isDragging.value = false;

  // Reset cursor style
  if (fieldElement.value) {
    fieldElement.value.style.cursor = 'default';
  }
};

// Handle mouse leaving the field
const onMouseLeave = () => {
  if (isDragging.value) {
    isDragging.value = false;

    // Reset cursor style
    if (fieldElement.value) {
      fieldElement.value.style.cursor = 'default';
    }
  }
};

// Handle CTRL key press to show grab cursor
const onKeyDown = (e) => {
  if (e.key === 'Control') {
    isCtrlPressed.value = true;
    if (fieldElement.value) {
      fieldElement.value.style.cursor = 'grab';
    }
  }
};

const onKeyUp = (e) => {
  if (e.key === 'Control') {
    isCtrlPressed.value = false;
    if (fieldElement.value && !isDragging.value) {
      fieldElement.value.style.cursor = 'default';
    }
  }
};

// Add startReplay method and related replay functionality
const startReplay = async () => {
  // Replay functionality disabled (uses /turns API)
  alert('Replay functionality is currently disabled');
  return;
  /*
  if (isReplaying.value) return;

  try {
    // Fetch all turns for the game
    const response = await gameApi.getGameTurns(id.value);

    if (!response || !response.turns || !response.turns.length) {
      alert('No turns available for replay');
      return;
    }

    // Process the turns directly from the API response
    gameTurns.value = response.turns.map(turn => {
      // The API already provides the actions in parsed format
      return {
        turnId: turn.turn_id,
        turnNumber: turn.turn_number,
        playerId: turn.player_id,
        actions: turn.actions,
        startTime: turn.start_time,
        // Don't rely on end_time as it's always null currently
        endTime: null 
      };
    });

    if (!gameTurns.value.length) {
      alert('No turns available for replay');
      return;
    }
    // Save original game data to restore after replay
    originalGameData.value = JSON.parse(JSON.stringify(gameData.value));

    // Setup replay UI and state
    isReplaying.value = true;
    currentReplayTurn.value = 0;
    replaySpeed.value = 1;

    // Add class to game field to enable replay-specific styling
    const gameField = document.querySelector('.game-field');
    if (gameField) {
      gameField.classList.add('is-replaying');
    }

    // Show replay overlay
    nextTick(() => { // Use imported nextTick instead of this.$nextTick
      const overlay = document.createElement('div');
      overlay.className = 'replay-overlay';
      overlay.id = 'replay-overlay';

      const status = document.createElement('div');
      status.className = 'replay-status';
      status.textContent = 'REPLAY MODE';
      overlay.appendChild(status);

      // Create speed indicator
      const speedIndicator = document.createElement('div');
      speedIndicator.className = 'replay-speed-indicator';
      speedIndicator.id = 'replay-speed-indicator';
      speedIndicator.textContent = `Speed: ${replaySpeed.value}x`;

      document.querySelector('.game-container').appendChild(overlay);
      document.querySelector('.game-container').appendChild(speedIndicator);
    });

    // Clean the field before starting replay
    cleanFieldForReplay();

    // Center view on the middle of the field
    centerViewOnMiddle();

    // Start the replay sequence after a short delay to allow for UI updates
    setTimeout(() => {
      replayNextTurn();
    }, 500);

  } catch (error) {
    console.error('Failed to start replay:', error);
    alert(t('errors.replay', { reason: error.message || t('errors.unknown') }));
    isReplaying.value = false;
  }
  */
};

const finishReplay = () => {
  // Ask for confirmation before ending replay
  if (isReplaying.value && !confirm(t('hud.confirmStopReplay'))) {
    return;
  }

  isReplaying.value = false;
  clearTimeout(replayTimeout.value);

  // Clean up animations: remove classes from all DOM elements
  const tiles = document.querySelectorAll('.tile');
  tiles.forEach(tile => {
    tile.classList.remove('is-current-action');
    tile.classList.remove('tile-placing-animation');
  });

  // Also clear any animation flags from the data model
  if (gameData.value && gameData.value.field && gameData.value.field.tiles) {
    gameData.value.field.tiles.forEach(tile => {
      tile.isCurrentAction = false;
      tile.isPlacingAnimation = false;
    });
  }

  // Remove class from game field
  const gameField = document.querySelector('.game-field');
  if (gameField) {
    gameField.classList.remove('is-replaying');
  }

  // Remove overlay and speed indicator
  const overlay = document.getElementById('replay-overlay');
  const speedIndicator = document.getElementById('replay-speed-indicator');

  if (overlay) {
    overlay.remove();
  }

  if (speedIndicator) {
    speedIndicator.remove();
  }

  // Restore original game data
  if (originalGameData.value) {
    gameData.value = originalGameData.value;
  }

  // Reset replay state
  currentReplayTurn.value = 0;

  // Center view on the middle of the field
  setTimeout(() => {
    centerViewOnMiddle();
  }, 100);
};

const changeReplaySpeed = (newSpeed) => {
  // Update the replay speed with the value received from the ReplayControls component
  replaySpeed.value = newSpeed;

  // Update speed indicator
  const speedIndicator = document.getElementById('replay-speed-indicator');
  if (speedIndicator) {
    speedIndicator.textContent = `Speed: ${replaySpeed.value}x`;
  }
};

// Add a function to clean the field before replay
const cleanFieldForReplay = () => {


  if (gameData.value && gameData.value.field) {
    // Keep only the starting tile (at 0,0) and remove all other tiles
    if (gameData.value.field.tiles && gameData.value.field.tiles.length > 0) {
      // Find the starting tile (usually at 0,0)
      const startingTileIndex = gameData.value.field.tiles.findIndex(
        tile => {
          const [x, y] = tile.position.split(',').map(Number);
          return x === 0 && y === 0;
        }
      );

      // If we found a starting tile, keep only that one
      if (startingTileIndex >= 0) {
        const startingTile = gameData.value.field.tiles[startingTileIndex];
        gameData.value.field.tiles = [startingTile];
      } else {
        // If no starting tile is found, just clear all tiles
        gameData.value.field.tiles = [];
      }
    }

    // Clear player positions
    if (gameData.value.field.playerPositions) {
      // Reset all players to starting position (0,0)
      for (const playerId in gameData.value.field.playerPositions) {
        gameData.value.field.playerPositions[playerId] = '0,0';
      }
    }

    // Reset field size to minimal (just around the starting tile)
    if (gameData.value.field.size) {
      gameData.value.field.size.minX = -1;
      gameData.value.field.size.maxX = 1;
      gameData.value.field.size.minY = -1;
      gameData.value.field.size.maxY = 1;
    }


  }
};

// Track replay timeout so we can clear it
const replayTimeout = ref(null);

const replayNextTurn = () => {
  if (!isReplaying.value || currentReplayTurn.value >= gameTurns.value.length) {
    // End of replay
    finishReplay();
    return;
  }

  const turn = gameTurns.value[currentReplayTurn.value];

  // Clean up previous animations properly
  // 1. Remove the is-current-action class from all DOM elements
  const tiles = document.querySelectorAll('.tile');
  tiles.forEach(tile => {
    tile.classList.remove('is-current-action');
  });

  // 2. Clear the isCurrentAction flag from all tiles in the data model
  if (gameData.value && gameData.value.field && gameData.value.field.tiles) {
    gameData.value.field.tiles.forEach(tile => {
      if (tile.isCurrentAction) {
        tile.isCurrentAction = false;
      }
    });
  }

  // Variables to track the primary action for this turn
  let activeTilePos = null;
  let activeTileType = 'default'; // Default type if no specific action was found

  // Use the original game state and simulate actions to display this turn
  if (gameData.value && gameData.value.field) {
    // Group actions by type for sequential processing
    const placeTileActions = turn.actions.filter(action => action.action === 'place_tile');
    const rotateTileActions = turn.actions.filter(action => action.action === 'rotate_tile');
    const moveActions = turn.actions.filter(action => action.action === 'move');

    // First process tile placements
    if (placeTileActions.length > 0) {
      // For each placement, update the game state
      placeTileActions.forEach((action) => {
        const fieldPlace = action.additionalData?.fieldPlace;
        if (fieldPlace && action.tileId) {
          // Update the tiles collection with this placement
          const [x, y] = fieldPlace.split(',').map(Number);

          // Find if the tile already exists or add a new one
          const existingTileIndex = gameData.value.field.tiles.findIndex(
            t => t.position === fieldPlace
          );

          // Calculate animation start position from player's position
          let animationStartX, animationStartY;
          const playerPosition = gameData.value.field.playerPositions?.[turn.playerId];

          if (playerPosition) {
            // Get player coordinates
            const [playerX, playerY] = playerPosition.split(',').map(Number);
            const minX = gameData.value.field.size.minX || 0;
            const minY = gameData.value.field.size.minY || 0;

            // Calculate start pixel positions (relative to the tiles container)
            animationStartX = (playerX - minX) * tileSize.value;
            animationStartY = (playerY - minY) * tileSize.value;
          }

          if (existingTileIndex >= 0) {
            // Update existing tile
            gameData.value.field.tiles[existingTileIndex].tileId = action.tileId;
            // Flag for current action
            gameData.value.field.tiles[existingTileIndex].isCurrentAction = true;
          } else {
            // Add new tile with animation data
            const newTile = {
              position: fieldPlace,
              tileId: action.tileId,
              isCurrentAction: true,
              isPlacingAnimation: true,
              animationStartX,
              animationStartY,
              turnNumber: currentReplayTurn.value + 1
            };
            gameData.value.field.tiles.push(newTile);
          }

          // Update field size if needed
          if (gameData.value.field.size) {
            gameData.value.field.size.minX = Math.min(gameData.value.field.size.minX || 0, x);
            gameData.value.field.size.maxX = Math.max(gameData.value.field.size.maxX || 0, x);
            gameData.value.field.size.minY = Math.min(gameData.value.field.size.minY || 0, y);
            gameData.value.field.size.maxY = Math.max(gameData.value.field.size.maxY || 0, y);
          }

          // Track this as the active position for centering view
          activeTilePos = { x, y };
          activeTileType = 'placement';
        }
      });

      // Apply current action class after a short delay to ensure DOM is updated
      setTimeout(() => {
        placeTileActions.forEach((action) => {
          const fieldPlace = action.additionalData?.fieldPlace;
          if (fieldPlace) {
            const [x, y] = fieldPlace.split(',').map(Number);
            const tileElement = document.querySelector(`.tile[data-x="${x}"][data-y="${y}"]`);
            if (tileElement) {
              tileElement.classList.add('is-current-action');

              // Remove animation class after animation completes
              setTimeout(() => {
                // Find the tile in our data model and remove the animation flag
                const tileIndex = gameData.value.field.tiles.findIndex(
                  t => t.position === fieldPlace
                );
                if (tileIndex >= 0) {
                  gameData.value.field.tiles[tileIndex].isPlacingAnimation = false;
                }
              }, 1000); // slightly longer than animation duration
            }
          }
        });
      }, 10);
    }

    // Process rotate tile actions (lower priority than placements)
    if (rotateTileActions.length > 0 && !activeTilePos) {
      rotateTileActions.forEach(action => {
        const fieldPlace = action.additionalData?.fieldPlace;
        if (fieldPlace) {
          const [x, y] = fieldPlace.split(',').map(Number);

          // Find the tile to rotate
          const tileIndex = gameData.value.field.tiles.findIndex(
            t => t.position === fieldPlace
          );

          if (tileIndex >= 0) {
            // Only mark as current action if no tile placement
            if (placeTileActions.length === 0) {
              gameData.value.field.tiles[tileIndex].isCurrentAction = true;
            }

            // Apply current action class after a short delay to ensure DOM is updated
            setTimeout(() => {
              const tileElement = document.querySelector(`.tile[data-x="${x}"][data-y="${y}"]`);
              if (tileElement && placeTileActions.length === 0) {
                tileElement.classList.add('is-current-action');
              }
            }, 10);

            // Track this as the active position for centering view if no placement
            if (!activeTilePos) {
              activeTilePos = { x, y };
              activeTileType = 'rotation';
            }
          }
        }
      });
    }

    // Process move actions
    if (moveActions.length > 0) {
      moveActions.forEach(action => {
        const toPosition = action.additionalData?.toPosition;
        if (toPosition && gameData.value.field.playerPositions) {
          // Update the player position
          gameData.value.field.playerPositions[turn.playerId] = toPosition;

          // Parse coordinates from position
          const [x, y] = toPosition.split(',').map(Number);

          // Flag the destination tile for highlighting
          const tileIndex = gameData.value.field.tiles.findIndex(
            t => t.position === toPosition
          );

          if (tileIndex >= 0) {
            // Mark as current action if no tile placement or rotation
            if (placeTileActions.length === 0 && rotateTileActions.length === 0) {
              gameData.value.field.tiles[tileIndex].isCurrentAction = true;
            }

            // Apply current action class after a short delay to ensure DOM is updated
            setTimeout(() => {
              const tileElement = document.querySelector(`.tile[data-x="${x}"][data-y="${y}"]`);
              if (tileElement && placeTileActions.length === 0 && rotateTileActions.length === 0) {
                tileElement.classList.add('is-current-action');
              }
            }, 10);

            // Track this as the active position for centering view if no placement or rotation
            if (!activeTilePos) {
              activeTilePos = { x, y };
              activeTileType = 'movement';
            }
          }
        }
      });
    }

    // Center the view on the most important action
    if (activeTilePos) {
      centerViewOnTile(activeTilePos);
    } else {
      centerViewOnMiddle();
    }
  }

  // Add a visual feedback that the turn is being processed
  const gameBoard = document.querySelector('.game-board');
  if (gameBoard) {
    gameBoard.classList.add('turn-transition');
    setTimeout(() => {
      gameBoard.classList.remove('turn-transition');
    }, 300);
  }

  // Increment the turn counter
  currentReplayTurn.value++;

  // Adjust timing based on action type - placement and rotation need more time than movement
  let baseDuration = 2000; // 2 seconds base duration
  if (activeTileType === 'placement') {
    baseDuration = 2500;
  } else if (activeTileType === 'rotation') {
    baseDuration = 2000;
  } else if (activeTileType === 'movement') {
    baseDuration = 1500;
  }

  // Apply speed factor
  const duration = baseDuration / replaySpeed.value;

  // Continue to the next turn after a delay (adjusted by replay speed)
  replayTimeout.value = setTimeout(() => {
    replayNextTurn();
  }, duration);
};

// Function to center view on the current player's position
const centerViewOnCurrentPlayer = () => {
  centerViewOnCurrentPlayerUtil(gameData.value, centerViewOnMiddle, processedTiles.value, centerViewOnTile);
};


// Watch for changes in the game state to center on player when needed
watch(() => gameData.value?.state?.currentPlayerId, async (newPlayerId, oldPlayerId) => {
  if (newPlayerId && newPlayerId !== oldPlayerId) {
    // When current player changes, center on them
    nextTick(() => {
      centerViewOnCurrentPlayer();
    });
    
    // Check if the new current player is an AI player
    // This handles cases where turn auto-ends (e.g., after 4 moves)
    await checkAndHandleVirtualPlayerTurn();
  }
}, { immediate: false });

// Watch for changes in game status - center on player when game starts
watch(() => gameData.value?.state?.status, (newStatus, oldStatus) => {
  if (newStatus === 'started') {
    // When game starts, center on current player
    gameStarted.value = true;
    nextTick(() => {
      centerViewOnCurrentPlayer();
    });
  } else if (newStatus === 'finished') {
    // Game is finished, show the leaderboard


    // Extract scores from players' treasures
    const entries = gameData.value.players.map(player => {
      const treasures = player.inventory?.treasures || [];
      const treasureValue = treasures.reduce((sum, treasure) => sum + (treasure.treasureValue || 0), 0);
      return {
        playerId: player.id,
        externalId: player.externalId || null,
        treasure: treasureValue
      };
    });

    // Sort entries by treasure value (highest first)
    entries.sort((a, b) => b.treasure - a.treasure);

    // Set the leaderboard data
    leaderboard.value = entries;

    // Get the winner (player with most treasure)
    winnerId.value = entries.length > 0 ? entries[0].playerId : null;
    
    // Also store the winner's externalId for authenticated user comparison
    const winnerExternalId = entries.length > 0 ? entries[0].externalId : null;


    // Close battle modal if it's still open before showing leaderboard
    if (showBattleReportModal.value) {

      showBattleReportModal.value = false;
      
      // Add a small delay to ensure DOM updates
      setTimeout(() => {
        showLeaderboardModal.value = true;
      }, 100);
    } else {
      // Show the leaderboard modal immediately if battle modal is not open
      showLeaderboardModal.value = true;
    }
  }
}, { immediate: true });

// Function to center view on all available places for player moves or tile placements
const centerViewOnAvailablePlaces = () => {
  centerViewOnAvailablePlacesUtil(gameData.value, centerViewOnMiddle, tileSize.value);
};

// Function to handle clicking on an available place
const handlePlaceClick = async (position) => {
  // Debug logging






  
  // Double-check it's actually the player's turn (in case of stale UI state)
  if (!isPlayerTurn.value) {

    // Force refresh game state if there's a mismatch
    await loadGameData();
    return;
  }
  
  // Also check isProcessingAI directly
  if (isProcessingAI.value) {

    return;
  }
  
  // Prevent actions if player is stunned
  if (isCurrentPlayerStunned.value) {

    return;
  }
  
  const response = await handlePlaceClickUtil({
    position,
    isPlayerTurn: isPlayerTurn.value,
    gameData: gameData.value,
    currentPlayerId,
    autoSwitchPlayer,
    gameApi,
    gameId: id.value,
    loadingState: { loading, loadingStatus, error },
    battleState: { battleInfo, showBattleReportModal },
    confirmStealth,
    chooseFateweaverItem,
    // Lady Lorraine "Listening": the switch is used up by one tile placement
    shouldListen: () => {
      const listening = isListenMode.value;
      isListenMode.value = false;
      return listening;
    },
    updateGameDataSelectively,
    centerViewOnTile,
    tileState: { 
      pickedTileId, 
      pickedTile, 
      ghostTilePosition, 
      isPlacingTile, 
      ghostTileOrientation 
    },
    tileUtils: {
      isValidOrientation: isValidOrientationLocal,
      getTileOrientationChar,
      getTileOrientationSymbol,
      generateUUID,
      getRequiredOpenSide: getRequiredOpenSideLocal,
      handleInitialTileOrientation: handleInitialTileOrientationLocal,
      cancelTilePlacement
    }
  });
  
  // Check if the move response contains a battle with a reward (win)
  if (response?.battleInfo && response.battleInfo.result === 'win' && response.battleInfo.reward) {

    // Battle rewards are handled through the battle modal, not the item pickup dialog
    // The battle modal will show automatically when loadGameData() is called
  }
  // Check if the move response contains itemInfo (non-battle item)
  else if (response?.itemInfo) {

    
    // Store the current turn ID and item info
    itemPickupTurnId.value = gameData.value?.state?.currentTurnId;
    tileItem.value = response.itemInfo.item;
    
    // Check if the item requires a key and player doesn't have one
    if (response.itemInfo.requiresKey && !response.itemInfo.hasKey) {

      missingKeyChestType.value = response.itemInfo.item.type;
      showMissingKeyDialog.value = true;
      
      // End turn since player can't pick up the item
      try {
        const currentTurnId = gameData.value?.state?.currentTurnId;
        if (currentTurnId) {
          await gameApi.endTurn({
            gameId: id.value,
            playerId: currentPlayerId.value,
            turnId: currentTurnId
          });
          await loadGameData();
        }
      } catch (err) {
        console.error('Failed to end turn after missing key:', err);
      }
    } else {
      // Only show the dialog if it's still the player's turn — and not for a find a full pack
      // has nothing worse to give up for: the hero walks over it (a click on the item still offers it)
      if (isPlayerTurn.value && !isWorthOffering(getCurrentPlayerData.value, tileItem.value)) {
        showActionNotice(t('game.notice.notWorthTaking', { item: itemName(tileItem.value?.type) }));
        tileItem.value = null;
        itemPickupTurnId.value = null;
        await loadGameData(false);
      } else if (isPlayerTurn.value) {
        showItemPickupDialog.value = true;
      }
    }
  } else if (!response?.battleInfo && !response?.tilePicked && !response?.tilePlaced) {
    // No battle, no item, no tile picked/placed - just a regular move
    // Players can move up to 4 times per turn, so don't end turn automatically

    // Just reload game data to update the UI with new positions
    await loadGameData();
  }
};

// Add a function to cancel tile placement
const cancelTilePlacement = () => {
  ghostTilePosition.value = null;
  pickedTileId.value = null;
  pickedTile.value = null;
  isPlacingTile.value = false;
  ghostTileOrientation.value = null;
  clearPickedTileState();
};


// Use the imported isValidOrientation function with gameData.value and currentPlayerId.value
const isValidOrientationLocal = (position, orientation) => {
  return isValidOrientation(position, orientation, gameData.value, currentPlayerId.value);
};

// Use the imported getRequiredOpenSide function with gameData.value and currentPlayerId.value
const getRequiredOpenSideLocal = (position) => {
  return getRequiredOpenSide(position, gameData.value, currentPlayerId.value);
};

// Where the player acts is kept clear of the HUD: when it lands under the rail, the chronicle, the banner or
// the rings, the field scrolls just enough (not a re-centre). Phones have their own HUD strip and bar: skipped.
const HUD_CLEARANCE = { left: 330, right: 360, top: 170, bottom: 180 };
// The area the player acts in: the tile being placed, or the acting hero with the marks of where to go next.
// It is scrolled clear of the HUD only when it fits in the free middle of the screen at all.
const actionArea = () => {
  const ghost = document.querySelector('.ghost-tile');
  const parts = ghost
    ? [ghost]
    : [...document.querySelectorAll('.hero-standee.is-acting, .available-place')];
  if (!parts.length) return null;
  return parts.map((el) => el.getBoundingClientRect()).reduce((a, r) => ({
    left: Math.min(a.left, r.left),
    top: Math.min(a.top, r.top),
    right: Math.max(a.right, r.right),
    bottom: Math.max(a.bottom, r.bottom)
  }));
};
const keepGhostClearOfHud = () => {
  if (window.matchMedia('(max-width: 768px), (max-height: 500px)').matches) return;
  const field = document.querySelector('.game-field');
  const tile = actionArea();
  if (!field || !tile) return;
  const view = field.getBoundingClientRect();
  const freeWidth = view.width - HUD_CLEARANCE.left - HUD_CLEARANCE.right;
  const freeHeight = view.height - HUD_CLEARANCE.top - HUD_CLEARANCE.bottom;
  if (tile.right - tile.left > freeWidth || tile.bottom - tile.top > freeHeight) return;
  const safeLeft = view.left + HUD_CLEARANCE.left;
  const safeRight = view.right - HUD_CLEARANCE.right;
  const safeTop = view.top + HUD_CLEARANCE.top;
  const safeBottom = view.bottom - HUD_CLEARANCE.bottom;
  let dx = 0;
  let dy = 0;
  if (tile.left < safeLeft) dx = tile.left - safeLeft;
  else if (tile.right > safeRight) dx = tile.right - safeRight;
  if (tile.top < safeTop) dy = tile.top - safeTop;
  else if (tile.bottom > safeBottom) dy = tile.bottom - safeBottom;
  if (dx || dy) {
    console.debug('[field] scrolling the action clear of the HUD', { dx, dy });
    field.scrollBy({ left: dx, top: dy, behavior: 'smooth' });
  }
};
watch([ghostTilePosition, isPlacingTile], ([position, placing]) => {
  if (position && placing) nextTick(keepGhostClearOfHud);
});
// ...and the marks of the next step on your turn
watch(() => (isPlayerTurn.value ? JSON.stringify(gameData.value?.state?.availablePlaces || null) : null), (places) => {
  if (places) setTimeout(keepGhostClearOfHud, 450);
});

// Turning the tile only makes sense when it has two different ways to lie with a door towards the hero:
// a straight corridor (one way) and a four-way crossing (all the same) offer nothing to turn
const GHOST_SIDES = ['T', 'R', 'B', 'L'];
const ghostCanRotate = computed(() => {
  const orientation = ghostTileOrientation.value;
  if (!orientation) return true;
  const open = GHOST_SIDES.map((side) => hasOpening(orientation, side));
  const required = getRequiredOpenSide(ghostTilePosition.value, gameData.value, currentPlayerId.value);
  const ways = new Set();
  for (let turn = 0; turn < 4; turn++) {
    const turned = GHOST_SIDES.map((_, i) => open[(i - turn + 4) % 4]);
    if (required === null || turned[required]) ways.add(turned.join());
  }
  return ways.size > 1;
});

// A tile that lies one way only (a crossing, a corridor along the way) has nothing to confirm: it goes down by
// itself, after a beat to see it drawn
const AUTO_PLACE_MS = 450;
let autoPlacedTileId = null;
watch(() => [pickedTileId.value, isPlacingTile.value, ghostTilePosition.value, ghostTileOrientation.value, ghostCanRotate.value],
  ([tileId, placing, position, orientation, canRotate]) => {
    if (!tileId || !placing || !position || !orientation || canRotate || !isPlayerTurn.value) return;
    if (autoPlacedTileId === tileId) return;
    autoPlacedTileId = tileId;
    console.info('[tile] one way to lie: placed without asking', { position });
    setTimeout(() => {
      if (pickedTileId.value === tileId && isPlacingTile.value && ghostTilePosition.value === position) {
        handlePlaceClick(position);
      }
    }, AUTO_PLACE_MS);
  });

// Use the imported rotateGhostTile function with necessary parameters
const rotateGhostTileLocal = async () => {
  if (!ghostCanRotate.value) return;
  if (!pickedTileId.value || !pickedTile.value || !isPlacingTile.value || !ghostTilePosition.value) return;

  // Ensure we're using the correct player ID from the game state
  if (gameData.value?.state?.currentPlayerId !== currentPlayerId.value) {
    console.error('Player ID mismatch in rotateGhostTile: UI player does not match game state player');



    // Force a player switch to ensure we're using the correct player
    autoSwitchPlayer();

    // If after switching the player IDs still don't match, don't proceed
    if (gameData.value?.state?.currentPlayerId !== currentPlayerId.value) {
      console.error('Failed to align player IDs, aborting rotation');
      return;
    }
  }

  // Get current turn ID from game state
  const currentTurnId = gameData.value?.state?.currentTurnId;
  if (!currentTurnId) {
    console.error('No current turn ID found in game state');
    return;
  }

  await rotateGhostTile({
    ghostTilePosition: ghostTilePosition.value,
    pickedTileId: pickedTileId.value,
    pickedTile: pickedTile.value,
    gameData: gameData.value,
    currentPlayerId: currentPlayerId.value,
    gameId: id.value,
    currentTurnId,
    rotateTileApi: gameApi.rotateTile,
    onSuccess: (tile) => {
      // Update the tile orientation
      pickedTile.value = tile;
      const newOrientation = parseOrientationString(tile.orientation);
      const newSymbol = getTileOrientationSymbol(newOrientation, tile.room);

      ghostTileOrientation.value = newOrientation;
    },
    onError: (err) => {
      console.error('Failed to rotate tile:', err);
      error.value = t('errors.rotate', { reason: err.message });
    }
  });
};

const handleInitialTileOrientationLocal = async (position) => {
  if (!pickedTile.value || !pickedTileId.value) return false;

  // Ensure we're using the correct player ID from the game state
  if (gameData.value?.state?.currentPlayerId !== currentPlayerId.value) {
    console.error('Player ID mismatch in handleInitialTileOrientation: UI player does not match game state player');



    // Force a player switch to ensure we're using the correct player
    autoSwitchPlayer();

    // If after switching the player IDs still don't match, don't proceed
    if (gameData.value?.state?.currentPlayerId !== currentPlayerId.value) {
      console.error('Failed to align player IDs, aborting initial tile orientation');
      return false;
    }
  }

  // Get current turn ID from game state
  const currentTurnId = gameData.value?.state?.currentTurnId;
  if (!currentTurnId) {
    console.error('No current turn ID found in game state');
    return false;
  }

  // Set initial orientation
  ghostTileOrientation.value = parseOrientationString(pickedTile.value.orientation);

  return await handleInitialTileOrientation({
    position,
    pickedTile: pickedTile.value,
    pickedTileId: pickedTileId.value,
    gameData: gameData.value,
    currentPlayerId: currentPlayerId.value,
    gameId: id.value,
    currentTurnId,
    rotateTileApi: gameApi.rotateTile,
    onSuccess: (tile) => {
      pickedTile.value = tile;
      const newOrientation = parseOrientationString(tile.orientation);

      ghostTileOrientation.value = newOrientation;
    },
    onError: (err) => {
      console.error('Failed to rotate tile:', err);
    }
  });
};

// Function to handle keyboard controls for ghost tile
const handleKeyboardControls = (e) => {
  // Don't handle movement when any modal/dialog is open
  if (showBattleReportModal.value || showInventoryFullDialog.value || showItemPickupDialog.value || showLeaderboardModal.value || showMissingKeyDialog.value) return;

  // Only handle keyboard if it's player's turn
  if (!isPlayerTurn.value) return;

  // If we have a picked tile, handle its movement
  if (pickedTileId.value && pickedTile.value && isPlacingTile.value) {
    if (isLetterKey(e, 'r')) {
      rotateGhostTileLocal();
      e.preventDefault();
    } else if (e.key === 'Enter') {
      // Place the tile at current ghost position
      if (ghostTilePosition.value) {
        handlePlaceClick(ghostTilePosition.value);
        e.preventDefault();
      }
      return;
    }
    return;
  }

  // Handle arrow keys for ghost tile placement
  const availablePlaces = getProcessedAvailablePlaces(gameData.value, 'placeTile');
  const availableMoves = getProcessedAvailablePlaces(gameData.value, 'moveTo');

  // Combine all valid positions (both empty places and already placed tiles)
  const allValidPositions = [...new Set([...availablePlaces.map(p => `${p.x},${p.y}`), ...availableMoves.map(p => `${p.x},${p.y}`)])];

  if (!allValidPositions.length) return;

  // Get current ghost position or player position as reference
  let currentPos;
  if (ghostTilePosition.value) {
    currentPos = ghostTilePosition.value;
  } else if (gameData.value?.field?.playerPositions?.[currentPlayerId.value]) {
    currentPos = gameData.value.field.playerPositions[currentPlayerId.value];
  } else {
    return;
  }

  const [currentX, currentY] = currentPos.split(',').map(Number);
  let targetPos;

  switch (e.key) {
    case 'ArrowUp':
      targetPos = `${currentX},${currentY - 1}`;
      e.preventDefault();
      break;
    case 'ArrowRight':
      targetPos = `${currentX + 1},${currentY}`;
      e.preventDefault();
      break;
    case 'ArrowDown':
      targetPos = `${currentX},${currentY + 1}`;
      e.preventDefault();
      break;
    case 'ArrowLeft':
      targetPos = `${currentX - 1},${currentY}`;
      e.preventDefault();
      break;
    case 'Enter':
      // If no ghost tile but we're on a valid place, pick a new tile or move
      if (!pickedTileId.value && allValidPositions.includes(currentPos)) {
        handlePlaceClick(currentPos);
        e.preventDefault();
      }
      break;
    default:
      return;
  }

  // Check if the target position is a valid move or place for a new tile
  if (targetPos && allValidPositions.includes(targetPos)) {
    // If we don't have a picked tile yet, simulate click on the position
    if (!pickedTileId.value) {
      handlePlaceClick(targetPos);
    } else if (availablePlaces.some(place => `${place.x},${place.y}` === targetPos)) {
      // If we already have a picked tile, just move the ghost (only to empty places)
      ghostTilePosition.value = targetPos;
    }
  }
};

// Add keyboard event listener setup in onMounted
onMounted(() => {
  loadGameData();
  window.addEventListener('resize', handleResize);

  // Add global event listeners for drag navigation
  window.addEventListener('mouseup', onMouseUp);
  window.addEventListener('mousemove', onMouseMove);

  // Add keyboard event listeners for CTRL key detection
  window.addEventListener('keydown', onKeyDown);
  window.addEventListener('keyup', onKeyUp);

  // Add keyboard listener for combined keyboard events
  window.addEventListener('keydown', handleKeyboardEvents);
});

// Update cleanup in onUnmounted
onUnmounted(() => {
  window.removeEventListener('resize', handleResize);

  // Remove global event listeners for drag navigation
  window.removeEventListener('mouseup', onMouseUp);
  window.removeEventListener('mousemove', onMouseMove);

  // Remove keyboard event listeners for CTRL key detection
  window.removeEventListener('keydown', onKeyDown);
  window.removeEventListener('keyup', onKeyUp);

  // Remove keyboard listener for combined keyboard events
  window.removeEventListener('keydown', handleKeyboardEvents);

  // Remove keyboard listeners for modal overlays
  window.removeEventListener('keydown', handleLeaderboardKeyDown, true);
  window.removeEventListener('keydown', handleStunnedKeyDown, true);

  // Clear the polling interval
  if (gamePollingInterval.value) {
    clearInterval(gamePollingInterval.value);
    gamePollingInterval.value = null;
  }

  // Reset AI tracking variables
  lastExecutedAITurn = null;
  aiTurnInProgress = false;
  aiExecutionPromise = null;
  
  // Clear any stuck AI detection timers
  if (aiStuckTimer) {
    clearTimeout(aiStuckTimer);
    aiStuckTimer = null;
  }
});

// Use the imported getTileOrientationAt function with gameData.value
const getTileOrientationAtLocal = (position) => {
  return getTileOrientationAt(position, gameData.value);
};

// Function to switch between the current player and second player
const switchPlayer = () => {
  switchPlayerUtil({ 
    currentPlayerId, 
    secondPlayerId, 
    loadGameData 
  });
};

// Function to automatically switch to the current player based on game state
const autoSwitchPlayer = () => {
  // Use our enhanced checkStunnedPlayersAndSwitch function
  checkStunnedPlayersAndSwitch();
  
  // Show notification if player switching happened
  showPlayerSwitchNotification();
};

// Watch for changes in game data to ensure player IDs are always in sync
watch(() => gameData.value?.state?.currentPlayerId, (newCurrentPlayerId) => {
  if (newCurrentPlayerId && newCurrentPlayerId !== currentPlayerId.value) {

    
    // Dismiss any open dialogs when player changes
    if (showItemPickupDialog.value) {

      dismissItemPickupDialog();
    }
    
    autoSwitchPlayer();
  }
}, { immediate: true });

// Watch for turn changes to dismiss dialogs
watch(() => gameData.value?.state?.currentTurnId, (newTurnId, oldTurnId) => {
  if (newTurnId !== oldTurnId && oldTurnId !== undefined) {
    // Turn has changed, dismiss any open dialogs
    if (showItemPickupDialog.value) {

      dismissItemPickupDialog();
    }
    
    // Reset AI turn flag when turn changes - this ensures AI can play again
    // even if multiple AI turns happen in quick succession
    if (aiTurnInProgress) {

      aiTurnInProgress = false;
    }
  }
});

// Watch for changes in game status - center on player when game starts
watch(() => gameData.value?.state?.status, (newStatus, oldStatus) => {
  if (newStatus === 'started') {
    // When game starts, center on current player
    gameStarted.value = true;
    nextTick(() => {
      centerViewOnCurrentPlayer();
    });
  } else if (newStatus === 'finished') {
    // Game is finished, show the leaderboard


    // Extract scores from players' treasures
    const entries = gameData.value.players.map(player => {
      const treasures = player.inventory?.treasures || [];
      const treasureValue = treasures.reduce((sum, treasure) => sum + (treasure.treasureValue || 0), 0);
      return {
        playerId: player.id,
        externalId: player.externalId || null,
        treasure: treasureValue
      };
    });

    // Sort entries by treasure value (highest first)
    entries.sort((a, b) => b.treasure - a.treasure);

    // Set the leaderboard data
    leaderboard.value = entries;

    // Get the winner (player with most treasure)
    winnerId.value = entries.length > 0 ? entries[0].playerId : null;
    
    // Also store the winner's externalId for authenticated user comparison
    const winnerExternalId = entries.length > 0 ? entries[0].externalId : null;


    // Close battle modal if it's still open before showing leaderboard
    if (showBattleReportModal.value) {

      showBattleReportModal.value = false;
      
      // Add a small delay to ensure DOM updates
      setTimeout(() => {
        showLeaderboardModal.value = true;
      }, 100);
    } else {
      // Show the leaderboard modal immediately if battle modal is not open
      showLeaderboardModal.value = true;
    }
  }
}, { immediate: true });

// Function to dismiss notification
const dismissNotification = () => {
  dismissNotificationUtil({ 
    playerSwitched, 
    notificationTimeout 
  });
};

// Function to show player switch notification
const showPlayerSwitchNotification = () => {
  showPlayerSwitchNotificationUtil({ 
    playerSwitched, 
    notificationTimeout 
  });
};

// Where a hero's figure stands in field coordinates: offsets ignore the zoom transform of the field.
// Returns the top centre of the figure, or null when it is not rendered.
const playerFigureAnchor = (playerId) => {
  const figure = document.querySelector(`[data-testid="player-figure-${playerId}"]`);
  const field = figure?.closest('.tile')?.offsetParent;
  if (!figure || !field) return null;
  let x = 0;
  let y = 0;
  for (let el = figure; el && el !== field; el = el.offsetParent) {
    x += el.offsetLeft;
    y += el.offsetTop;
  }
  return { x: x + figure.offsetWidth / 2, y };
};

// Function to show healing notification
const showHealingNotificationForPlayer = (playerId, healAmount, positionStr = null) => {
  // Try to get player's username from game data
  const player = gameData.value?.players?.find(p => p.id === playerId);
  const playerName = player?.username || formatPlayerId(playerId);

  // Use provided position or fall back to current game data
  const pos = positionStr || gameData.value?.field?.playerPositions?.[playerId];
  let hx = 0, hy = 0;
  if (pos && typeof pos === 'string') {
    const [px, py] = pos.split(',').map(Number);
    const minX = gameData.value?.field?.size?.minX || 0;
    const minY = gameData.value?.field?.size?.minY || 0;
    hx = (px - minX) * tileSize.value + tileSize.value / 2;
    hy = (py - minY) * tileSize.value - 10;
  }

  healingNotification.value = {
    playerId: playerId,
    playerName: playerName,
    healAmount: healAmount,
    worldX: hx,
    worldY: hy
  };
  showHealingNotification.value = true;

  // Above the hero's figure once the board shows the new position (the tile centre above is only a fallback)
  nextTick(() => {
    const anchor = playerFigureAnchor(playerId);
    if (anchor && healingNotification.value?.playerId === playerId) {
      healingNotification.value = { ...healingNotification.value, worldX: anchor.x, worldY: anchor.y - 24 };
    }
  });
  
  // Auto-dismiss after animation completes
  setTimeout(() => {
    dismissHealingNotification();
  }, 2000);
};

// Function to dismiss healing notification
const dismissHealingNotification = () => {
  showHealingNotification.value = false;
  healingNotification.value = null;
};

// We're using the imported formatPlayerId function directly

// Get current player data
const getCurrentPlayerData = computed(() => {
  if (!gameData.value || !gameData.value.players || !currentPlayerId.value) return null;
  return gameData.value.players.find(player => player.id === currentPlayerId.value);
});

// Get second player data
const getSecondPlayerData = computed(() => {
  if (!gameData.value || !gameData.value.players || !secondPlayerId.value) return null;
  return gameData.value.players.find(player => player.id === secondPlayerId.value);
});

// Get current player's field position
const currentPlayerFieldPosition = computed(() => {
  if (!gameData.value || !gameData.value.field || !currentPlayerId.value) return null;

  const playerPositions = gameData.value.field.playerPositions || {};
  const posStr = playerPositions[currentPlayerId.value];
  if (posStr && typeof posStr === 'string') {
    const [x, y] = posStr.split(',').map(n => parseInt(n));
    return { x, y };
  }

  return null;
});

// Check if player has inventory space for the battle reward
// The loot of the fight on screen. A fight lost or drawn on the dice carries no reward, though spells or a
// Sacrifice may still win it: the monster's own token on the field tells what it guards
const battleRewardType = computed(() => battleRewardType.value
  || gameData.value?.field?.items?.[battleInfo.value?.position]?.type
  || null);

const hasInventorySpaceForReward = computed(() => {
  if (!battleInfo.value || !battleRewardType.value || !getCurrentPlayerData.value) return true;

  // One place for the slot rules (hero skills included: Hanna's daggers also fit a spell slot)
  return hasRoomForItem(getCurrentPlayerData.value, battleRewardType.value);
});

// Function to select inventory item
const selectInventoryItem = (item) => {
  selectInventoryItemUtil(item, {
    onSpellSelected: async (spell) => {
      if (spell.type === 'teleport' && isPlayerTurn.value) {
        await handleTeleportSpellSelection(spell);
      }
    }
  });
};

// Function to select an item to replace when inventory is full
const selectItemToReplace = (item) => {
  selectItemToReplaceUtil({ 
    selectedItemToReplace, 
    item 
  });
};

// Function to replace an item in the inventory
const replaceItem = async () => {
  // Get current turn ID to pass to replaceItem
  const currentTurnId = gameData.value?.state?.currentTurnId;
  
  await replaceItemUtil({
    selectedItemToReplace,
    droppedItem,
    loading,
    loadingStatus,
    showInventoryFullDialog,
    error,
    gameApi,
    gameId: id.value,
    playerId: currentPlayerId.value,
    turnId: currentTurnId,
    loadGameData,
    keepTurn: inventoryFullKeepsTurn.value,
    heroPosition: pickupPosition() || null,
    // the dialog stays open: the player can try again or leave the find
    onError: (message) => showActionNotice(message)
  });
  inventoryFullKeepsTurn.value = false;
};

// Function to skip picking up an item
const skipItem = async () => {
  // Get current turn ID to pass to skipItem
  const currentTurnId = gameData.value?.state?.currentTurnId;
  
  await skipItemUtil({
    droppedItem,
    loading,
    loadingStatus,
    showInventoryFullDialog,
    error,
    gameApi,
    gameId: id.value,
    playerId: currentPlayerId.value,
    turnId: currentTurnId,
    loadGameData,
    isAfterBattle: isInventoryFullAfterBattle.value
  });
  
  // Reset the flags after use
  isInventoryFullAfterBattle.value = false;
  inventoryFullKeepsTurn.value = false;
};

// Function to handle inventory full response
const handleInventoryFullResponse = (response) => {
  handleInventoryFullResponseUtil({
    response,
    droppedItem,
    itemCategory,
    maxItemsInCategory,
    inventoryForCategory,
    showInventoryFullDialog,
    selectedItemToReplace,
    getCurrentPlayerData
  });
};

// Function to close battle report and end turn
const closeBattleReportAndEndTurn = async () => {
  try {
    // Reset request lock when battle ends
    resetRequestLock();

    // Check if player lost the battle - if so, we should update UI immediately
    const isPlayerDefeated = battleInfo.value && battleInfo.value.result === 'loose';
    if (isPlayerDefeated) {
      // Immediately update the UI to show reduced HP
      if (getCurrentPlayerData.value && getCurrentPlayerData.value.hp > 0) {
        getCurrentPlayerData.value.hp -= 1;
      }

    }

    // Close the battle report modal first
    showBattleReportModal.value = false;
    
    // Get current turn ID
    const currentTurnId = gameData.value?.state?.currentTurnId;
    if (!currentTurnId) {
      console.error('No current turn ID found in game state');
      return;
    }
    
    // The backend automatically ends the turn only for LOSE/DRAW battle results
    // For WIN results, the turn ending is handled by the finalize-battle call
    loading.value = true;
    loadingStatus.value = 'Updating game state...';
    

    
    // Refresh game data to get the updated turn state
    const updatedGameState = await gameApi.getGame(id.value);
    doUpdateGameDataSelectively(updatedGameState);
    
    // If the turn advanced to a different player, update player IDs (hot-seat: the screen follows the human seats)
    if (updatedGameState.state?.hotseat) {
      followHotseatSeat(updatedGameState);
    } else if (updatedGameState.state.currentPlayerId !== currentPlayerId.value) {

      // If it's our second player, switch to them
      if (secondPlayerId.value === updatedGameState.state.currentPlayerId) {
        switchPlayer({
          currentPlayerId,
          secondPlayerId,
          loadGameData
        });
      } else {
        // Otherwise just update our current player ID
        currentPlayerId.value = updatedGameState.state.currentPlayerId;
        localStorage.setItem('currentPlayerId', currentPlayerId.value);
      }
    }
    
    // Clear battle info and loading before AI turn starts
    battleInfo.value = null;
    loading.value = false;
    loadingStatus.value = '';

    // Check if the next player is an AI player and trigger their turn
    // This is important for stunned players whose turn auto-ends
    await checkAndHandleVirtualPlayerTurn();

  } catch (err) {
    console.error('Failed to close battle report:', err);
    error.value = err.message || t('errors.closeBattle');
    // Still reset the lock even if there's an error
    resetRequestLock();
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Function to handle keyboard events, including Escape to close battle report
const handleKeyboardEvents = (e) => {
  handleKeyboardEventsUtil({
    e,
    isPlayerTurn,
    showBattleReportModal,
    closeBattleReportAndEndTurn,
    pickedTileId,
    pickedTile,
    isPlacingTile,
    rotateGhostTileLocal,
    ghostTilePosition,
    handlePlaceClick,
    getProcessedAvailablePlaces,
    gameData,
    currentPlayerId,
    isTeleportMode,
    cancelTeleportMode
  });
};


// Helper function to scroll to a specific position
const scrollToPosition = (x, y) => {
  scrollToPositionUtil(x, y, gameData.value, tileSize.value);
};

// Add a new function to handle item clicks from GameTile
const handleItemClick = async (itemData) => {
  try {
    // Check if player is on the same position as the item
    const playerPosition = gameData.value?.field?.playerPositions?.[currentPlayerId.value];
    if (playerPosition !== itemData.position) {

      return;
    }

    // Check if the item has an undefeated guard
    if (itemData.item && !itemData.item.guardDefeated && itemData.item.guardHP > 0) {

      return;
    }

    // When player clicks directly on an item, show the pickup dialog
    // This handles the case where player is already on the tile
    // Only show the dialog if it's still the player's turn
    if (isPlayerTurn.value) {
      tileItem.value = itemData.item;
      itemPickupTurnId.value = gameData.value?.state?.currentTurnId;
      showItemPickupDialog.value = true;
    }
  } catch (err) {
    // Handle missing key error specifically
    if (err.message && err.message.startsWith('MISSING_KEY:')) {
      const chestType = err.message.split(':')[1];
      missingKeyChestType.value = chestType;
      showMissingKeyDialog.value = true;
    } else {
      // Re-throw other errors to be handled normally
      throw err;
    }
  }
};

// Function to handle picking up item and ending turn
const handlePickItemAndEndTurn = async () => {
  try {
    const currentTurnId = gameData.value?.state?.currentTurnId;
    
    const itemPosition = pickupPosition();

    if (!currentTurnId || !itemPosition) {
      console.error('Missing turn ID or item position');
      closeBattleReportAndEndTurn();
      return;
    }

    loading.value = true;
    loadingStatus.value = 'Picking up item...';

    // Try to pick up the item
    const response = await gameApi.pickItem({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: currentTurnId,
      position: itemPosition
    });

    // Check if inventory is full
    if (response.inventoryFull && battleReportModalRef.value) {
      // Special case for keys: all keys are the same, so auto-replace without asking
      if (response.itemCategory === 'key' || response.itemCategory === 'keys') {


        // Get the first key from current inventory to replace
        const currentKeys = response.currentInventory || [];
        if (currentKeys.length > 0) {
          const keyToReplace = currentKeys[0].itemId;

          // Automatically replace the existing key
          await gameApi.pickItem({
            gameId: id.value,
            playerId: currentPlayerId.value,
            turnId: currentTurnId,
            position: itemPosition,
            itemIdToReplace: keyToReplace
          });

          // Item replaced successfully, now end the turn

          // End the turn (an Unstoppable Swordsman keeps playing)
          if (!lastBattleUnstoppable()) {
            await gameApi.endTurn({
              gameId: id.value,
              playerId: currentPlayerId.value,
              turnId: currentTurnId
            });
          }

          // Close modal before refreshing (loadGameData auto-triggers AI turn)
          showBattleReportModal.value = false;
          battleInfo.value = null;
          loading.value = false;
          loadingStatus.value = '';
          resetRequestLock();

          // Refresh game data (will auto-trigger AI turn if needed)
          await loadGameData();
        } else {
          // Fallback: show normal inventory full dialog if no keys found
          inventoryFullKeepsTurn.value = lastBattleUnstoppable();
          isInventoryFullAfterBattle.value = !inventoryFullKeepsTurn.value;
          showInventoryFullDialog.value = true;
          // the reward lies on the fight's tile: the loaded positions may still show the tile the hero came from
          droppedItem.value = { ...response.item, position: itemPosition };
          itemCategory.value = response.itemCategory;
          maxItemsInCategory.value = response.maxItemsInCategory;
          inventoryForCategory.value = response.currentInventory || [];
          
          // Close the battle modal since we're showing the inventory dialog
          showBattleReportModal.value = false;
          battleInfo.value = null;
        }
      } else {
        // Set up inventory full dialog state for non-key items after battle
        inventoryFullKeepsTurn.value = lastBattleUnstoppable();
        isInventoryFullAfterBattle.value = !inventoryFullKeepsTurn.value;
        showInventoryFullDialog.value = true;
        // the reward lies on the fight's tile: the loaded positions may still show the tile the hero came from
        droppedItem.value = { ...response.item, position: itemPosition };
        itemCategory.value = response.itemCategory;
        maxItemsInCategory.value = response.maxItemsInCategory;
        inventoryForCategory.value = response.currentInventory || [];
        
        // Close the battle modal since we're showing the inventory dialog
        showBattleReportModal.value = false;
        battleInfo.value = null;
      }
    } else {
      // Item picked up successfully, now end the turn

      // End the turn (an Unstoppable Swordsman keeps playing)
      if (!lastBattleUnstoppable()) {
        await gameApi.endTurn({
          gameId: id.value,
          playerId: currentPlayerId.value,
          turnId: currentTurnId
        });
      }

      // Close modal and clear loading BEFORE refreshing data
      // (loadGameData auto-triggers AI turn, so modal must be gone first)
      showBattleReportModal.value = false;
      battleInfo.value = null;
      loading.value = false;
      loadingStatus.value = '';
      resetRequestLock();

      // Refresh game data (will auto-trigger AI turn if needed)
      await loadGameData();
    }
  } catch (err) {
    console.error('Failed to pick up item:', err);
    error.value = err.message || t('errors.pickUp');
    closeBattleReportAndEndTurn();
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Where the item to pick up lies: a won fight's reward on the fight's tile (the loaded positions may still show the
// tile the hero came from until the state is reloaded), else the hero's own tile
const pickupPosition = () => {
  if (battleInfo.value && battleInfo.value.result === 'win' && battleInfo.value.position) {
    return battleInfo.value.position;
  }
  return gameData.value?.field?.playerPositions?.[currentPlayerId.value];
};

// Function to handle picking up item with replacement
const handlePickItemWithReplacement = async (itemIdToReplace) => {
  try {
    const currentTurnId = gameData.value?.state?.currentTurnId;
    const playerPosition = pickupPosition();

    if (!currentTurnId || !playerPosition) {
      console.error('Missing turn ID or player position');
      closeBattleReportAndEndTurn();
      return;
    }

    loading.value = true;
    loadingStatus.value = 'Replacing item...';

    // Pick up the item with replacement
    await gameApi.pickItem({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: currentTurnId,
      position: playerPosition,
      itemIdToReplace: itemIdToReplace
    });

    // Item replaced successfully, now end the turn

    
    // End the turn (an Unstoppable Swordsman keeps playing)
    if (!lastBattleUnstoppable()) {
      await gameApi.endTurn({
        gameId: id.value,
        playerId: currentPlayerId.value,
        turnId: currentTurnId
      });
    }
    
    // Refresh game data
    await loadGameData();

    showBattleReportModal.value = false;
    battleInfo.value = null;
    loading.value = false;
    loadingStatus.value = '';
    resetRequestLock();

    // Check if the next player is an AI player and trigger their turn
    await checkAndHandleVirtualPlayerTurn();
  } catch (err) {
    console.error('Failed to replace item:', err);
    error.value = err.message || t('errors.replace');
    closeBattleReportAndEndTurn();
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Function to handle finalize battle and pick up
const handleFinalizeBattleAndPickUp = async (finalizeBattleData) => {


  
  // Reset any stale request locks since we're in a battle finalization flow
  resetRequestLock();
  
  // Check if a request is already in progress after reset
  if (isRequestActive()) {
    console.warn('⚠️ Request already in progress even after reset, forcing through');
    // Force reset and continue
    resetRequestLock();
  }
  
  try {
    const currentTurnId = gameData.value?.state?.currentTurnId;
    const playerPosition = gameData.value?.field?.playerPositions?.[currentPlayerId.value];
    






    if (!currentTurnId || !playerPosition) {
      console.error('❌ Early return: Missing turn ID or player position');
      console.error('  - currentTurnId:', currentTurnId);
      console.error('  - playerPosition:', playerPosition);
      closeBattleReportAndEndTurn();
      return;
    }


    
    // Calculate if inventory will have space after consuming the selected items
    let willHaveSpace = hasInventorySpaceForReward.value;
    
    // Wizard "Magical Affinity": used fireballs stay in the inventory, so they never free a slot
    const keepsSpellsInCombat = getCurrentPlayerData.value?.heroFlags?.keepsSpellsInCombat === true;

    // If consumables were used, check if removing them creates space
    if (!hasInventorySpaceForReward.value && !keepsSpellsInCombat && finalizeBattleData.selectedConsumableIds && finalizeBattleData.selectedConsumableIds.length > 0) {
      const rewardType = battleRewardType.value;
      
      // If the reward is a spell (Karak: Regent spells included), check if we're freeing up spell slots
      if (SPELL_TYPES.includes(rewardType)) {
        const currentSpells = getCurrentPlayerData.value?.inventory?.spells || [];
        const consumedSpellCount = finalizeBattleData.selectedConsumableIds.filter(id => 
          currentSpells.some(spell => spell.itemId === id)
        ).length;
        
        const spellsAfterConsumption = currentSpells.length - consumedSpellCount;
        const spellLimit = 3;
        
        willHaveSpace = spellsAfterConsumption < spellLimit;
        console.log('[FIX] spell reward slot check after consumables', { rewardType, spellsAfterConsumption, spellLimit, willHaveSpace });
        

      }
    }
    
    // Check if we need to select a replacement item first
    if (!finalizeBattleData.replaceItemId && !willHaveSpace) {

      
      // Special case for keys: auto-replace
      if (battleRewardType.value === 'key') {

        const currentKeys = getCurrentPlayerData.value?.inventory?.keys || [];
        if (currentKeys.length > 0) {
          finalizeBattleData.replaceItemId = currentKeys[0].itemId;
          // Set the skip flag BEFORE any async operations to prevent race conditions
          skipBattleModalReshow.value = true;
        }
      } else {

        loading.value = false;
        
        // Get inventory category
        const rewardType = battleRewardType.value;
        let inventoryCategory = 'treasures';
        if (['dagger', 'sword', 'axe'].includes(rewardType)) {
          inventoryCategory = 'weapons';
        } else if (SPELL_TYPES.includes(rewardType)) {
          inventoryCategory = 'spells';
        } else if (rewardType === 'key') {
          inventoryCategory = 'keys';
        }
        
        let inventoryForCategory = getCurrentPlayerData.value?.inventory?.[inventoryCategory] || [];
        // Hanna "Dagger Throwing": a dagger may also replace anything in the spell slots
        if (rewardType === 'dagger' && getCurrentPlayerData.value?.heroFlags?.throwsDaggers === true) {
          inventoryForCategory = [...inventoryForCategory, ...(getCurrentPlayerData.value?.inventory?.spells || [])];
        }
        
        // Filter out consumables that were just used in the battle
        // They haven't been removed from inventory yet but shouldn't be shown as replacement options
        // (the Wizard keeps his fireballs, so for him they remain valid replacement candidates)
        if (!keepsSpellsInCombat && finalizeBattleData.selectedConsumableIds && finalizeBattleData.selectedConsumableIds.length > 0) {
          inventoryForCategory = inventoryForCategory.filter(item => 
            !finalizeBattleData.selectedConsumableIds.includes(item.itemId)
          );

        }
        

        
        if (battleReportModalRef.value) {
          // a fight won with spells carries no reward: the monster's token on the field is the loot
          const fieldLoot = gameData.value?.field?.items?.[battleInfo.value?.position] || null;
          battleReportModalRef.value.showFinalizeBattleInventoryFullSelection(inventoryForCategory, fieldLoot);
          return; // Wait for user to select replacement
        }
      }
    }
    
    // Now finalize battle with pickup and replacement if needed

    loading.value = true;
    loadingStatus.value = 'Finalizing battle and picking up item...';

    const response = await gameApi.finalizeBattle({
      battleId: finalizeBattleData.battleId,
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: currentTurnId,
      selectedConsumableIds: finalizeBattleData.selectedConsumableIds,
      pickupItem: true,  // Always try to pick up
      replaceItemId: finalizeBattleData.replaceItemId,  // Include replacement if selected
      sacrificeHp: finalizeBattleData.sacrificeHp === true // Warlock "Sacrifice"
    });


    
    // Mark this battle as processed to prevent re-showing
    if (finalizeBattleData.battleId) {
      lastProcessedBattleId.value = finalizeBattleData.battleId;
    }
    
    // Ensure skip flag is set if we auto-replaced a key (check before clearing battleInfo)
    const wasKeyReplacement = battleRewardType.value === 'key' && finalizeBattleData.replaceItemId;
    if (wasKeyReplacement) {
      skipBattleModalReshow.value = true;
    }
    
    // Close modal and clear loading before refreshing (loadGameData auto-triggers AI turn)
    showBattleReportModal.value = false;
    battleInfo.value = null;
    loading.value = false;
    loadingStatus.value = '';
    resetRequestLock();

    // Refresh game data (will auto-trigger AI turn if needed)
    await loadGameData();

    // If game hasn't ended yet but we picked up a game-ending item, retry loadGameData
    if (gameData.value?.state?.status !== 'finished') {
      await new Promise(r => setTimeout(r, 500));
      await loadGameData();
    }
  } catch (err) {
    console.error('Failed to finalize battle and pick up:', err);
    error.value = err.message || t('errors.finalizePickup');
    closeBattleReportAndEndTurn();
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Function to handle finalize battle
const handleFinalizeBattle = async (finalizeBattleData) => {
  // Karak: Regent — a duel side has its own flow (no monster, no turn end)
  if (battleInfo.value?.duel) {
    await handleFinalizeDuelSide(finalizeBattleData);
    return;
  }

  // Mark this battle as processed to prevent re-showing
  if (finalizeBattleData.battleId) {
    lastProcessedBattleId.value = finalizeBattleData.battleId;
  }
  
  // Hide modal immediately if requested (to prevent showing intermediate battle state)
  if (finalizeBattleData.hideModalImmediately) {
    showBattleReportModal.value = false;
  }
  
  try {
    const currentTurnId = gameData.value?.state?.currentTurnId;

    if (!currentTurnId) {
      console.error('Missing turn ID for battle finalization');
      closeBattleReportAndEndTurn();
      return;
    }

    loading.value = true;
    loadingStatus.value = 'Finalizing battle...';

    // Check predicted battle result based on damage vs monster HP
    const monster = battleInfo.value?.monster;
    const sacrificeHp = finalizeBattleData.sacrificeHp === true; // Warlock "Sacrifice": +1 strength for 1 HP
    const totalDamage = (battleInfo.value?.totalDamage || 0) + (sacrificeHp ? 1 : 0);
    const tieIsWin = battleInfo.value?.tieIsWin === true; // Thief "Backstab"
    // battleInfo.monster is the monster's HP (a number); spells chosen in the modal are not in totalDamage yet
    const predictedResult = predictBattleResult(totalDamage, monster, tieIsWin);

    // The sacrifice is paid whatever the result
    if (sacrificeHp && getCurrentPlayerData.value && getCurrentPlayerData.value.hp > 0) {
      getCurrentPlayerData.value.hp -= 1;
    }

    // If we predict a loss, immediately update the UI to show reduced HP
    if (predictedResult === 'loose') {
      if (getCurrentPlayerData.value && getCurrentPlayerData.value.hp > 0) {
        getCurrentPlayerData.value.hp -= 1;
      }

    }

    // Call the finalize battle API
    const finalizeResult = await gameApi.finalizeBattle({
      battleId: finalizeBattleData.battleId,
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: currentTurnId,
      selectedConsumableIds: finalizeBattleData.selectedConsumableIds,
      pickupItem: false, // Explicitly set to false to ensure item is not picked up
      sacrificeHp: finalizeBattleData.sacrificeHp === true // Warlock "Sacrifice"
    });

    // Warrior "Reincarnation": the hero lost the last HP, the turn stays open until a fountain is chosen
    if (finalizeResult?.awaitingReincarnation) {
      enterReincarnationMode(finalizeResult.healingFountainPositions || []);
    }

    // The backend only automatically ends the turn for LOSE/DRAW results
    // For WIN results where the player doesn't pick up the item, we need to manually end the turn
    // (unless the Swordsman is Unstoppable: a 6 keeps his turn going)
    // The real result comes from the final strength the backend computed (spells and Sacrifice included);
    // a fight that is not won was already ended by the backend (or waits for Markul's retreat choice)
    const finalResult = predictBattleResult(finalizeResult?.finalTotalDamage ?? totalDamage, monster, tieIsWin) ?? predictedResult;
    console.log('[FIX] finalize result', { predictedResult, finalResult, finalTotalDamage: finalizeResult?.finalTotalDamage, monster, tireless: finalizeBattleData.tireless === true });
    if (finalResult === 'win' && finalizeBattleData.tireless === true) {
      // Valduk "Tireless": the loot stays on the tile and the turn goes on
      await gameApi.continueAfterVictory({ gameId: id.value, playerId: currentPlayerId.value, turnId: currentTurnId });
    } else if (finalResult === 'win' && !finalizeResult?.awaitingReincarnation && !lastBattleUnstoppable()) {

      await gameApi.endTurn({
        gameId: id.value,
        playerId: currentPlayerId.value,
        turnId: currentTurnId
      });
    }

    // Close modal and clear loading before refreshing (loadGameData auto-triggers AI turn)
    showBattleReportModal.value = false;
    battleInfo.value = null;
    loading.value = false;
    loadingStatus.value = '';
    resetRequestLock();

    // Refresh game data (will auto-trigger AI turn if needed)
    await loadGameData();
  } catch (err) {
    console.error('Failed to finalize battle:', err);
    error.value = err.message || t('errors.finalize');
    closeBattleReportAndEndTurn();
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Check if all players are ready (used to hide player setup section)
const allPlayersReady = computed(() => {
  // If game is started or in progress, consider all players ready
  if (gameStarted.value || gameData.value?.state?.status === 'started' || gameData.value?.state?.status === 'turn_in_progress') {
    return true;
  }

  // For setup phase, check if we have the minimum required players and they're ready
  const hasMinimumPlayers = gameData.value?.players?.length >= 2;
  const currentPlayerReady = playerIsReady.value;
  const secondPlayerExists = isSecondPlayerInGame.value;

  // All players are ready if:
  // 1. We have minimum players (2)
  // 2. Current player is ready
  // 3. Second player exists (which implies they're ready since adding them automatically sets them ready)
  return hasMinimumPlayers && currentPlayerReady && secondPlayerExists;
});

// Note: checkForPickableItem function has been removed
// Item pickup is now handled through the move-player API response

// Function to handle automatic item pickup from dialog
const handleAutoItemPickup = async () => {
  if (!tileItem.value) return;

  try {
    loading.value = true;
    loadingStatus.value = 'Picking up item...';

    const itemPosition = pickupPosition();
    if (!itemPosition) {
      throw new Error('Player has no position');
    }

    // Use the turn ID that was stored when the dialog was shown
    const turnId = itemPickupTurnId.value;
    if (!turnId) {
      throw new Error('No turn ID found - dialog was shown without proper turn context');
    }
    







    // Call API to pick up the item
    const response = await gameApi.pickItem({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: turnId,
      position: itemPosition
    });

    // Check if player is missing a key for a chest
    if (response.missingKey) {

      dismissItemPickupDialog();
      missingKeyChestType.value = response.chestType;
      showMissingKeyDialog.value = true;
      return;
    }

    // Check if inventory is full
    if (response.inventoryFull) {

      dismissItemPickupDialog();

      // Set up the inventory full dialog
      showInventoryFullDialog.value = true;
      droppedItem.value = response.item || tileItem.value;
      itemCategory.value = response.itemCategory;
      maxItemsInCategory.value = response.maxItemsInCategory;
      inventoryForCategory.value = response.currentInventory || [];
      selectedItemToReplace.value = null;
      
      // Store the position for later when we need to pick up the item
      if (!droppedItem.value.position) {
        droppedItem.value.position = itemPosition;
      }
      
      return;
    }

    // Dismiss the dialog immediately after successful pickup
    dismissItemPickupDialog();

    // Refresh game data
    await loadGameData();

    // Ensure dialog is dismissed after data refresh (prevents stale dialog on turn change)
    dismissItemPickupDialog();

    // Check if the turn has already changed (backend auto-ended, e.g. 4th move pickup)
    const currentTurnNow = gameData.value?.state?.currentTurnId;
    if (currentTurnNow !== turnId) {
      // Turn already advanced — nothing more to do
    } else {
      // End turn after successfully picking up the item

      try {
        await gameApi.endTurn({
          gameId: id.value,
          playerId: currentPlayerId.value,
          turnId: turnId
        });
        
        // Refresh game data again to get the updated turn state
        await loadGameData();
      } catch (endTurnErr) {
        console.error('Failed to end turn after item pickup:', endTurnErr);
      }
    }
  } catch (err) {
    console.error('Failed to pick up item:', err);
    error.value = t('errors.pickUpReason', { reason: err.message });
    // Only dismiss dialog on error if it wasn't already dismissed
    if (showItemPickupDialog.value) {
      dismissItemPickupDialog();
    }
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Function to dismiss the item pickup dialog
const dismissItemPickupDialog = () => {
  showItemPickupDialog.value = false;
  tileItem.value = null;
  itemPickupTurnId.value = null;
};

// Function to dismiss item pickup dialog when player skips item.
// If the player has used all their moves (pendingItemPickup), auto-ends the turn.
// Otherwise, the player can continue moving.
// Leaving the item ends the turn only when the hero has no move left (the backend marks the turn pendingItemPickup)
const pickupLeaveEndsTurn = computed(() => {
  const turnId = itemPickupTurnId.value || gameData.value?.state?.currentTurnId;
  return gameTurns.value?.find((turn) => turn.turnId === turnId)?.pendingItemPickup === true;
});

const skipItemAndEndTurn = async () => {


  // Save turn ID before dismissing (dismiss clears it)
  const turnId = itemPickupTurnId.value;

  // Dismiss the dialog
  dismissItemPickupDialog();

  // Refresh game data so move markers update for the player's new position
  try {

    await loadGameData(false);
  } catch (err) {
    console.error('Failed to refresh game data after skipping item:', err);
  }

  // Check if the current turn has pendingItemPickup (4th move exhausted all actions)
  // If so, auto-end the turn since no more moves are available
  const currentTurn = gameTurns.value?.find(
    t => t.turnId === (turnId || gameData.value?.state?.currentTurnId)
  );
  if (currentTurn?.pendingItemPickup) {

    try {
      await gameApi.endTurn({
        gameId: id.value,
        playerId: currentPlayerId.value,
        turnId: turnId || gameData.value?.state?.currentTurnId
      });
      await loadGameData(false);
      await checkAndHandleVirtualPlayerTurn();
    } catch (err) {
      console.error('Failed to auto-end turn:', err);
    }
  }
};

// Teleport spell handling functions
const handleTeleportSpellSelection = async (spell) => {
  // If already in teleport mode with the same spell, cancel it (toggle behavior)
  if (isTeleportMode.value && selectedTeleportSpell.value?.itemId === spell.itemId) {
    cancelTeleportMode();
    return;
  }
  
  // Check if there are any healing fountain tiles available (excluding current position)
  const currentPos = currentPlayerFieldPosition.value;
  const currentPosStr = currentPos ? `${currentPos.x},${currentPos.y}` : null;
  const availableFountains = healingFountainTiles.value.filter(t => t.position !== currentPosStr);
  
  if (availableFountains.length > 0) {
    // Enter teleport mode
    isTeleportMode.value = true;
    selectedTeleportSpell.value = spell;
    teleportTargetPlayerId.value = currentPlayerId.value;
  } else {
    error.value = t('errors.noFountain');
  }
};

const handleTeleportClick = async (position) => {
  if (!isTeleportMode.value || !selectedTeleportSpell.value) return;
  
  // Double-check we're not teleporting to the same position
  if (isCurrentPlayerPosition(position)) {
    return;
  }
  
  try {
    loading.value = true;
    loadingStatus.value = 'Teleporting...';
    
    // Convert position string to coordinates object with correct keys for backend
    const [x, y] = position.split(',').map(n => parseInt(n));
    const targetPosition = { positionX: x, positionY: y };
    
    
    // Use the teleport spell
    await gameApi.useSpell(
      id.value,
      currentPlayerId.value,
      gameData.value.state.currentTurnId,
      selectedTeleportSpell.value.itemId,
      targetPosition,
      teleportTargetPlayerId.value || currentPlayerId.value
    );
    
    // Exit teleport mode
    isTeleportMode.value = false;
    selectedTeleportSpell.value = null;
    
    // Reload game data to see the updated position
    await loadGameData();
    

  } catch (err) {
    console.error('Failed to use teleport spell:', err);
    error.value = t('errors.teleport', { reason: err.message });
    // Exit teleport mode on error
    isTeleportMode.value = false;
    selectedTeleportSpell.value = null;
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

const cancelTeleportMode = () => {
  isTeleportMode.value = false;
  selectedTeleportSpell.value = null;
  teleportTargetPlayerId.value = null;

};

// ===== Warrior hero skills =====

// "Double Attack": reroll the dice of the open battle once; the modal is updated in place (same battleId)
const handleRerollBattle = async ({ battleId }) => {
  const currentTurnId = gameData.value?.state?.currentTurnId;
  if (!battleId || !currentTurnId) {
    console.error('Missing battle or turn ID for Double Attack reroll');
    return;
  }

  try {
    loadingStatus.value = 'Double Attack! Rerolling...';
    const response = await gameApi.rerollBattle({
      battleId,
      gameId: id.value,
      playerId: battleInfo.value?.player || currentPlayerId.value, // a summoned duel rival rolls during the challenger's turn
      turnId: currentTurnId
    });

    if (response?.battleInfo) {
      // Keep the frontend-only fields (reward etc. are recomputed by the backend as well)
      battleInfo.value = { ...battleInfo.value, ...response.battleInfo };
    } else {
      console.warn('Reroll response has no battleInfo, reloading game data', response);
      await loadGameData(false);
    }
  } catch (err) {
    console.error('Failed to reroll battle dice:', err);
    error.value = t('errors.failed', { action: t('errors.actions.doubleAttack'), reason: err.message });
  } finally {
    loadingStatus.value = '';
  }
};

// Fountain tiles offered for reincarnation (backend list when provided, otherwise all discovered fountains)
const reincarnationFountainTiles = computed(() => {
  const allowed = reincarnationFountainPositions.value;
  if (!allowed || allowed.length === 0) return healingFountainTiles.value;
  return healingFountainTiles.value.filter(tile => allowed.includes(tile.position));
});

// Set once the fountain was chosen, so a stale game-data response (fetched before the reincarnation
// completed) cannot re-open the fountain choice
const reincarnationHandled = ref(false);

const enterReincarnationMode = (fountainPositions = []) => {
  reincarnationFountainPositions.value = fountainPositions;
  reincarnationHandled.value = false;
  isReincarnationMode.value = true;
  isTeleportMode.value = false;
  selectedTeleportSpell.value = null;
};

// Re-enter the mode after a page reload while the hero is still fallen
watch(() => getCurrentPlayerData.value?.awaitingReincarnation, (awaiting) => {
  if (awaiting && isPlayerTurn.value && !isReincarnationMode.value && !reincarnationHandled.value) {
    enterReincarnationMode(gameData.value?.field?.lastBattleInfo?.healingFountainPositions || []);
  }
  if (!awaiting && isReincarnationMode.value) {
    isReincarnationMode.value = false;
    reincarnationFountainPositions.value = [];
  }
  // a reincarnation done: the next fall (a later fight or a duel) asks again
  if (!awaiting) reincarnationHandled.value = false;
});

// Actions already recorded in the current turn (the Magic Swap must be the first one)
const currentTurnActions = computed(() => {
  const currentTurnId = gameData.value?.state?.currentTurnId;
  if (!currentTurnId) return [];
  const turn = gameTurns.value.find(t => t.turnId === currentTurnId);
  return turn?.actions || [];
});

// Other heroes standing somewhere on the field
const otherPlayersWithPosition = computed(() => {
  const positions = gameData.value?.field?.playerPositions || {};
  return Object.keys(positions).filter(playerId => playerId !== currentPlayerId.value);
});

// Warlock "Magic Swap" is offered only as the first action of the turn
// a hero sneaking on a monster's tile cannot be swapped with (the server refuses it too)
const hasUndefeatedMonster = (position) => {
  const item = gameData.value?.field?.items?.[position];
  return !!item && Number(item.guardHP) > 0 && !item.guardDefeated;
};

const canMagicSwapNow = computed(() => {
  if (!isPlayerTurn.value || isReplaying.value || isProcessingAI.value) return false;
  if (gameData.value?.state?.status === 'finished') return false;
  if (getCurrentPlayerData.value?.heroFlags?.canMagicSwap !== true) return false;
  if (pickedTileId.value || isTeleportMode.value || isReincarnationMode.value) return false;
  if (currentTurnActions.value.length > 0) return false;
  return otherPlayersWithPosition.value.length > 0;
});

// Leave the swap mode as soon as it is no longer possible (turn changed, actions recorded)
watch(canMagicSwapNow, (possible) => {
  if (!possible && isMagicSwapMode.value) {
    isMagicSwapMode.value = false;
  }
});

// Karak: Regent heroes ------------------------------------------------------------

// Skills that can only open the turn (Lightning Attack, Sprint): my turn, nothing done yet
const canOpenTurnWithSkill = computed(() => {
  if (!isPlayerTurn.value || isReplaying.value || isProcessingAI.value) return false;
  if (gameData.value?.state?.status === 'finished') return false;
  if (pickedTileId.value || isTeleportMode.value || isReincarnationMode.value || isMagicSwapMode.value) return false;
  return currentTurnActions.value.length === 0;
});

// Baron Markul "Lightning Attack": every tile holding an undefeated monster, except his own
const isLightningMode = ref(false);
const lightningTargets = computed(() => {
  const items = gameData.value?.field?.items || {};
  const myPosition = gameData.value?.field?.playerPositions?.[currentPlayerId.value];
  return Object.entries(items)
    .filter(([position, item]) => item && item.guardHP > 0 && !item.guardDefeated && position !== myPosition)
    .map(([position, item]) => ({ position, hp: item.guardHP, label: formatItemName(item.name) }));
});
const canLightningAttackNow = computed(() => canOpenTurnWithSkill.value
  && getCurrentPlayerData.value?.heroFlags?.lightningAttack === true
  && lightningTargets.value.length > 0);
watch(canLightningAttackNow, (possible) => {
  if (!possible && isLightningMode.value) {
    isLightningMode.value = false;
  }
});

const handleLightningAttack = async (targetPosition) => {
  if (isRequestInProgress.value) return;
  try {
    loading.value = true;
    loadingStatus.value = 'Lightning Attack...';
    const response = await gameApi.lightningAttack({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: gameData.value.state.currentTurnId,
      targetPosition
    });
    isLightningMode.value = false;
    await loadGameData(false);
    if (response?.battleInfo) {
      battleInfo.value = response.battleInfo;
      showBattleReportModal.value = true;
    }
  } catch (err) {
    console.error('Lightning Attack failed:', err);
    isLightningMode.value = false;
    showActionNotice(t('errors.failed', { action: t('errors.actions.lightningAttack'), reason: apiErrorMessage(err) }));
    await loadGameData(false).catch(() => {});
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Baron Markul: the retreat after a Lightning Attack that was not won
const retreatChoiceForMe = computed(() => {
  const choice = gameData.value?.state?.retreatChoice;
  return choice && choice.playerId === currentPlayerId.value ? choice : null;
});
// Where a tile lies from the hero, in words ("north-east"): the plaques list targets this way, not as "0,-1"
const myFieldPosition = computed(() => gameData.value?.field?.playerPositions?.[currentPlayerId.value] || null);
const directionLabel = (from, to) => {
  if (!from || !to) return '';
  const [fx, fy] = from.split(',').map(Number);
  const [tx, ty] = to.split(',').map(Number);
  const ns = ty < fy ? 'n' : ty > fy ? 's' : '';
  const ew = tx > fx ? 'e' : tx < fx ? 'w' : '';
  return t(`game.dir.${ns + ew || 'here'}`);
};
const retreatOptionLabel = (option) => {
  const from = retreatChoiceForMe.value?.position || myFieldPosition.value;
  const fountain = (gameData.value?.field?.healingFountainPositions || []).includes(option);
  const where = directionLabel(from, option);
  const label = fountain ? `${where} · ${t('game.retreat.fountain')}` : where;
  return label.charAt(0).toUpperCase() + label.slice(1);
};
const skillTargetMarks = computed(() => {
  const at = (position, kind, title) => {
    const [x, y] = position.split(',').map(Number);
    return { position, x, y, kind, title };
  };
  if (isLightningMode.value) return lightningTargets.value.map((target) => at(target.position, 'lightning', target.label));
  if (retreatChoiceForMe.value) return (retreatChoiceForMe.value.options || []).map((option) => at(option, 'retreat', retreatOptionLabel(option)));
  return [];
});
// The target under the pointer, in the plaque's list or on the field: both light up together
const hoveredSkillTarget = ref(null);
watch(skillTargetMarks, (marks) => {
  if (!marks.some((mark) => mark.position === hoveredSkillTarget.value)) hoveredSkillTarget.value = null;
});
const handleChooseRetreat = async (position) => {
  if (isRequestInProgress.value) return;
  try {
    loading.value = true;
    loadingStatus.value = 'Retreating...';
    await gameApi.chooseRetreat({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: gameData.value.state.currentTurnId,
      position
    });
    // The retreat ends the turn (this also triggers the AI turn if needed)
    await loadGameData();
  } catch (err) {
    console.error('Retreat failed:', err);
    showActionNotice(t('errors.failed', { action: t('errors.actions.retreat'), reason: apiErrorMessage(err) }));
    await loadGameData(false).catch(() => {});
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Hanna "Sprint": declared as the first action of the turn
const canSprintNow = computed(() => canOpenTurnWithSkill.value && getCurrentPlayerData.value?.heroFlags?.sprints === true);
const handleDeclareSprint = async () => {
  if (isRequestInProgress.value) return;
  try {
    loading.value = true;
    await gameApi.declareSprint({ gameId: id.value, playerId: currentPlayerId.value, turnId: gameData.value.state.currentTurnId });
    await loadGameData(false);
  } catch (err) {
    console.error('Sprint failed:', err);
    showActionNotice(t('errors.failed', { action: t('errors.actions.sprint'), reason: apiErrorMessage(err) }));
  } finally {
    loading.value = false;
  }
};

// On a healing fountain with something to heal (or a curse to lift): End Turn says so and glows
const fountainHint = computed(() => {
  const me = getCurrentPlayerData.value;
  const here = myFieldPosition.value;
  if (!isPlayerTurn.value || !me || !here || gameData.value?.state?.status === 'finished') return false;
  if (!(gameData.value?.field?.healingFountainPositions || []).includes(here)) return false;
  return me.cursed || me.hp < (me.maxHp || 5);
});
// On a teleportation gate: the other gates it can step to, as dashed lines on the field
const gateLinks = computed(() => {
  const here = myFieldPosition.value;
  const field = gameData.value?.field;
  if (!isPlayerTurn.value || !here || !field?.tiles || isTeleportMode.value || pickedTileId.value) return [];
  const gates = field.tiles.filter((tile) => (tile.features || []).includes('teleportation_gate')).map((tile) => tile.position);
  if (!gates.includes(here)) return [];
  const moves = gameData.value?.state?.availablePlaces?.moveTo || [];
  const centre = (position) => {
    const [x, y] = position.split(',').map(Number);
    return [(x - (field.size.minX || 0) + 0.5) * tileSize.value, (y - (field.size.minY || 0) + 0.5) * tileSize.value];
  };
  const [x1, y1] = centre(here);
  return gates.filter((gate) => gate !== here && moves.includes(gate)).map((gate) => {
    const [x2, y2] = centre(gate);
    return { to: gate, x1, y1, x2, y2 };
  });
});

// Argentus "Astral Walking": told on his turn while he can still move (the moves through walls are plain marks)
const astralHint = computed(() => isPlayerTurn.value
  && getCurrentPlayerData.value?.heroFlags?.walksThroughWalls === true
  && (gameData.value?.state?.availablePlaces?.moveTo || []).length > 0);

// Lady Lorraine "Listening": the next placed tile is explored without entering it
const isListenMode = ref(false);
const canListenNow = computed(() => isPlayerTurn.value
  && !isReplaying.value
  && !isProcessingAI.value
  && gameData.value?.state?.status !== 'finished'
  && getCurrentPlayerData.value?.heroFlags?.listens === true
  && (gameData.value?.state?.availablePlaces?.placeTile || []).length > 0);
watch(canListenNow, (possible) => {
  if (!possible) {
    isListenMode.value = false;
  }
});

// Valduk "Tireless": leave the loot of the won fight on its tile and keep moving
const handleTireless = async () => {
  try {
    loading.value = true;
    await gameApi.continueAfterVictory({ gameId: id.value, playerId: currentPlayerId.value, turnId: gameData.value.state.currentTurnId });
    showBattleReportModal.value = false;
    battleInfo.value = null;
    resetRequestLock();
    await loadGameData(false);
  } catch (err) {
    console.error('Tireless failed:', err);
    showActionNotice(t('errors.failed', { action: t('errors.actions.tireless'), reason: apiErrorMessage(err) }));
  } finally {
    loading.value = false;
  }
};

// Valduk "Berserk": heal only up to N HP ('' = heal fully)
const handleHealLimitChange = async (value) => {
  try {
    await gameApi.setHealLimit({ gameId: id.value, playerId: currentPlayerId.value, hp: value === '' ? null : Number(value) });
    await loadGameData(false);
  } catch (err) {
    console.error('Heal limit failed:', err);
    showActionNotice(t('errors.failed', { action: t('errors.actions.healLimit'), reason: apiErrorMessage(err) }));
  }
};

// "Magic Swap": trade places with the clicked hero; the backend spends the 4 moves (and ends the turn unless a reward waits)
const handlePlayerFigureClick = async ({ playerId }) => {
  if (!isMagicSwapMode.value || isRequestInProgress.value) return;
  if (!playerId || playerId === currentPlayerId.value) return;

  try {
    loading.value = true;
    loadingStatus.value = 'Swapping places...';

    // Fresh turn id and actions: the swap is legal only while the turn has no actions yet
    await loadGameData();
    if (!canMagicSwapNow.value) {
      isMagicSwapMode.value = false;
      showActionNotice(t('game.notice.magicSwapTooLate'));
      console.warn('[magic-swap] refused after refresh: turn already has actions');
      return;
    }

    await gameApi.magicSwap({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: gameData.value.state.currentTurnId,
      targetPlayerId: playerId
    });

    isMagicSwapMode.value = false;

    // Reload game data (the turn usually ended; this also triggers the AI turn if needed)
    await loadGameData();
  } catch (err) {
    console.error('Failed to swap places:', err);
    isMagicSwapMode.value = false;
    showActionNotice(t('errors.failed', { action: t('errors.actions.magicSwap'), reason: apiErrorMessage(err) }));
    await loadGameData().catch(() => {});
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// "Reincarnation": teleport to the chosen healing fountain with full HP; the backend ends the turn
const handleReincarnationClick = async (position) => {
  if (!isReincarnationMode.value || isRequestInProgress.value) return;

  try {
    loading.value = true;
    loadingStatus.value = 'Reincarnating...';

    const [x, y] = position.split(',').map(n => parseInt(n));
    await gameApi.reincarnate({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: gameData.value.state.currentTurnId,
      targetPosition: { positionX: x, positionY: y }
    });

    reincarnationHandled.value = true;
    isReincarnationMode.value = false;
    reincarnationFountainPositions.value = [];

    // Reload game data (turn has ended; this also triggers the AI turn if needed)
    await loadGameData();
  } catch (err) {
    console.error('Failed to reincarnate:', err);
    error.value = t('errors.failed', { action: t('errors.actions.reincarnation'), reason: err.message });
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Helper to check if a position is the current player's position
const isCurrentPlayerPosition = (position) => {
  if (!currentPlayerFieldPosition.value) return false;
  const [x, y] = position.split(',').map(n => parseInt(n));
  return currentPlayerFieldPosition.value.x === x && currentPlayerFieldPosition.value.y === y;
};

// Helper to check if a player is an AI/virtual player
const isVirtualPlayer = (playerId) => {
  // First check the isAi flag from the game data - this is the authoritative source
  if (gameData.value?.players) {
    const player = gameData.value.players.find(p => p.id === playerId);
    if (player && typeof player.isAi === 'boolean') {
      // If we have an explicit isAi flag, use it
      return player.isAi;
    }
  }
  
  // Fallback: check if we have a stored virtual player ID
  const virtualPlayerId = localStorage.getItem('virtualPlayerId');
  if (virtualPlayerId && playerId === virtualPlayerId) {
    return true;
  }
  
  // Note: We removed the fallback that assumed non-current players are AI
  // because in hotseat mode, both players are human
  
  return false;
};

// Every player with the top score wins: equal top scores share the victory
const winnerIds = computed(() => {
  const entries = leaderboard.value || [];
  if (entries.length === 0) return [];
  const top = Math.max(...entries.map((entry) => Number(entry.treasure) || 0));
  return entries.filter((entry) => (Number(entry.treasure) || 0) === top).map((entry) => entry.playerId);
});
const winnerNames = computed(() => winnerIds.value.map((playerId) => heroNameById(playerId)).join(', '));

// Helper to check if current user is (one of) the winner(s)
const isCurrentUserWinner = () => {
  // hot-seat: everyone at the screen sees the game end, the banner names the winner ("Victory to X")
  if (isHotseat.value) return false;
  if (humanPlayerId.value && !isVirtualPlayer(humanPlayerId.value)) {
    return winnerIds.value.includes(humanPlayerId.value);
  }

  return false;
};

// Helper to calculate total treasure value for a player
const calculatePlayerTreasure = (player) => {
  if (!player?.inventory?.treasures) return 0;
  return player.inventory.treasures.reduce((sum, treasure) => sum + (treasure.treasureValue || 0), 0);
};

// Helper to check if a leaderboard entry is the current user
const isCurrentUserEntry = (entry) => {
  // hot-seat: "you" is the seat on screen during the game (humanPlayerId follows it); at the end there is no one "you"
  if (isHotseat.value && gameData.value?.state?.status === 'finished') return false;
  if (humanPlayerId.value && !isVirtualPlayer(humanPlayerId.value)) {
    return entry.playerId === humanPlayerId.value;
  }
  
  return false;
};

// Navigation functions for end game
const navigateToLobby = () => {
  router.push('/');
};

const reloadPage = () => {
  // Close the modal to view the final game board
  showLeaderboardModal.value = false;
};

// Function to handle manual end turn by player
const handleManualEndTurn = async () => {
  try {
    // Check if there's a picked tile that hasn't been placed
    if (pickedTileId.value) {

      error.value = t('hud.mustPlaceTile');
      setTimeout(() => { error.value = null; }, 5000);
      return;
    }
    
    const currentTurnId = gameData.value?.state?.currentTurnId;
    if (!currentTurnId) {
      console.error('No current turn ID found');
      return;
    }
    
    // Double-check it's the player's turn
    if (!isPlayerTurn.value) {
      console.error('Not the player\'s turn');
      return;
    }
    
    loading.value = true;
    loadingStatus.value = 'Ending turn...';
    
    const result = await gameApi.endTurn({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: currentTurnId
    });
    
    // Check if there was an error (check for success flag or error status)
    if (result && result.message && !result.success) {
      error.value = result.message;
      setTimeout(() => { error.value = null; }, 5000);
      loading.value = false;
      loadingStatus.value = '';
      return;
    }
    
    // Clear loading state before refreshing
    loading.value = false;
    loadingStatus.value = '';
    
    // Refresh game data to get the updated turn state (without showing loading)
    await loadGameData(false);
    

    
    // Check if the next player is an AI player and trigger their turn
    await checkAndHandleVirtualPlayerTurn();
  } catch (err) {
    console.error('Failed to end turn:', err);
    error.value = t('errors.endTurn', { reason: err.message });
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Force AI turn execution (for stuck recovery)
const forceAITurn = async () => {
  try {
    const currentTurnNumber = gameData.value?.state?.turn;
    const aiPlayerId = gameData.value?.state?.currentPlayerId;
    if (!aiPlayerId || !currentTurnNumber) {
      console.error('No current player ID or turn number found');
      return;
    }
    

    
    // Clear stuck detection
    aiStuckDetected.value = false;
    if (aiStuckTimer) {
      clearTimeout(aiStuckTimer);
      aiStuckTimer = null;
    }
    
    // Clear any existing AI flags that might be blocking
    isProcessingAI.value = false;
    aiExecutionInProgressForTurn = null;
    aiExecutionPromise = null;
    
    // Reset last executed turn to force re-execution of current turn
    if (lastExecutedAITurn === currentTurnNumber) {
      lastExecutedAITurn = null;

    }
    
    // Call checkAndHandleVirtualPlayerTurn which will handle the execution
    await checkAndHandleVirtualPlayerTurn();
    

  } catch (err) {
    console.error('Failed to force AI turn:', err);
    error.value = t('errors.recoverAi', { reason: err.message });
    
    // Show the button again if recovery failed
    aiStuckDetected.value = true;
  }
};

// Note: We no longer need to watch for position changes to check for items
// This is now handled directly in the move response from the API

// Add this after the script setup line
onMounted(() => {
  // Existing mounted code...

  // Enhanced watch function for debugging
  watch(() => gameData.value?.state?.availablePlaces, (newValue) => {
    if (newValue) {


      // Check if there are moveTo places but no placeTile places despite having remaining tiles
      if (newValue.moveTo?.length > 0 && 
          newValue.placeTile?.length === 0 && 
          gameData.value?.state?.deck?.remainingTiles > 0) {
        console.warn('Possible issue: There are moveTo places but no placeTile places despite having remaining tiles');
      }
    }
  }, { deep: true });
});

const showLeaderboardModal = ref(false);
const leaderboard = ref([]);
const winnerId = ref(null);

// Listen for GameEnded event from backend (assuming websocket or polling updates gameData)
function handleGameEnded(event) {
  if (!event || !event.scores) return;
  // Convert scores object to sorted array
  // Map scores and add externalId from player data
  const entries = Object.entries(event.scores).map(([playerId, treasure]) => {
    const player = gameData.value?.players?.find(p => p.id === playerId);
    return { 
      playerId, 
      treasure,
      externalId: player?.externalId || null
    };
  });
  entries.sort((a, b) => b.treasure - a.treasure);
  leaderboard.value = entries;
  winnerId.value = event.winnerId;
  
  // Close battle modal if it's still open before showing leaderboard
  if (showBattleReportModal.value) {

    showBattleReportModal.value = false;
    
    // Add a small delay to ensure DOM updates
    setTimeout(() => {
      showLeaderboardModal.value = true;
    }, 100);
  } else {
    showLeaderboardModal.value = true;
  }
}

// Also watch for gameEnded flag
watch(() => gameData.value?.state?.gameEnded, (isEnded) => {

  if (isEnded) {

    
    // Extract scores from players' treasures
    const entries = gameData.value.players.map(player => {
      const treasures = player.inventory?.treasures || [];
      const treasureValue = treasures.reduce((sum, treasure) => sum + (treasure.treasureValue || 0), 0);
      return {
        playerId: player.id,
        externalId: player.externalId || null,
        treasure: treasureValue
      };
    });

    // Sort entries by treasure value (highest first)
    entries.sort((a, b) => b.treasure - a.treasure);

    // Set the leaderboard data
    leaderboard.value = entries;

    // Get the winner (player with most treasure)
    winnerId.value = entries.length > 0 ? entries[0].playerId : null;
    
    // Also store the winner's externalId for authenticated user comparison
    const winnerExternalId = entries.length > 0 ? entries[0].externalId : null;


    // Close battle modal if it's still open before showing leaderboard
    if (showBattleReportModal.value) {

      showBattleReportModal.value = false;
      
      // Add a small delay to ensure DOM updates
      setTimeout(() => {
        showLeaderboardModal.value = true;
      }, 100);
    } else {
      // Show the leaderboard modal immediately if battle modal is not open
      showLeaderboardModal.value = true;
    }
  }
});

// Watch for game status changes to detect when game is finished
watch(() => gameData.value?.state?.status, (newStatus, oldStatus) => {

  if (newStatus === 'finished') {



    // Extract scores from players' treasures
    const entries = gameData.value.players.map(player => {
      const treasures = player.inventory?.treasures || [];
      const treasureValue = treasures.reduce((sum, treasure) => sum + (treasure.treasureValue || 0), 0);
      return {
        playerId: player.id,
        externalId: player.externalId || null,
        treasure: treasureValue
      };
    });

    // Sort entries by treasure value (highest first)
    entries.sort((a, b) => b.treasure - a.treasure);

    // Set the leaderboard data
    leaderboard.value = entries;

    // Get the winner (player with most treasure)
    winnerId.value = entries.length > 0 ? entries[0].playerId : null;
    
    // Also store the winner's externalId for authenticated user comparison
    const winnerExternalId = entries.length > 0 ? entries[0].externalId : null;


    // Close battle modal if it's still open before showing leaderboard
    if (showBattleReportModal.value) {

      showBattleReportModal.value = false;
      
      // Add a small delay to ensure DOM updates
      setTimeout(() => {
        showLeaderboardModal.value = true;
      }, 100);
    } else {
      // Show the leaderboard modal immediately if battle modal is not open
      showLeaderboardModal.value = true;
    }
  }
}, { immediate: true });

// Example: If using a websocket or event bus, register handler here
onMounted(() => {
  if (window && window.addEventListener) {
    window.addEventListener('GameEnded', (e) => handleGameEnded(e.detail));
  }
  // If using a global event bus or websocket, register there instead
});

// Add computed property to check if current player is stunned
const isCurrentPlayerStunned = computed(() => {
  if (!gameData.value || !currentPlayerId.value) return false;
  
  // If battle report is showing or we're in replay mode, don't show stun dialog
  if (showBattleReportModal.value || isReplaying.value) return false;
  
  // Check if the current player in our UI matches the server's current player
  const isCurrentPlayersTurn = gameData.value?.state?.currentPlayerId === currentPlayerId.value;
  if (!isCurrentPlayersTurn) return false;
  
  // Check if player data exists and has HP = 0
  const playerData = gameData.value.players?.find(p => p.id === currentPlayerId.value);
  if (!playerData) return false;
  
  // Warrior "Reincarnation": the hero is at 0 HP but chooses a healing fountain instead of being stunned
  if (playerData.awaitingReincarnation || isReincarnationMode.value) return false;

  // Show stunned dialog if it's player's turn AND they're stunned
  const isStunned = playerData.hp === 0 || playerData.defeated === true;

  return isStunned;
});

// This hero's HP dropped to 0 (not a Warrior reincarnating): tell it once the fight scene is closed
// (any human at this screen: in a hot-seat game the screen hands over to the next player right after)
const knockedOut = ref(false);
const knockedOutPlayer = ref(null);
watch(() => (gameData.value?.players || []).map((p) => `${p.id}:${p.hp}`).join('|'), (now, before) => {
  if (!before || gameData.value?.state?.status === 'finished') return;
  const was = Object.fromEntries(before.split('|').map((entry) => entry.split(':')));
  for (const player of gameData.value?.players || []) {
    if (player.hp === 0 && Number(was[player.id]) > 0 && !player.isAi && !isVirtualPlayer(player.id) && !player.awaitingReincarnation) {
      // online the screen is one player's: a rival knocked out is told in a line, not with "you lose your next turn"
      if (isOnline.value && player.id !== mySeatId.value) {
        showActionNotice(t('game.notice.rivalStunned', { who: heroNameById(player.id) }), 'notice');
        continue;
      }
      knockedOutPlayer.value = player;
      knockedOut.value = true;
    }
  }
});
const showKnockedOut = computed(() => knockedOut.value && !showBattleReportModal.value && !isReincarnationMode.value);

// Add method to skip turn for stunned players
const skipStunnedPlayerTurn = async () => {
  knockedOut.value = false;
  if (!isCurrentPlayerStunned.value) return;

  try {
    loading.value = true;
    loadingStatus.value = 'Skipping turn...';

    const currentTurnId = gameData.value?.state?.currentTurnId;
    if (!currentTurnId) throw new Error('No current turn ID found in game state');

    await gameApi.endTurn({
      gameId: id.value,
      playerId: currentPlayerId.value,
      turnId: currentTurnId
    });

    // Poll for turn advancement
    let attempts = 0;
    let updatedGameState;
    do {
      await new Promise(res => setTimeout(res, 300));
      updatedGameState = await gameApi.getGame(id.value);
      attempts++;
    } while (
      updatedGameState.state.currentPlayerId === currentPlayerId.value &&
      attempts < 10
    );

    if (updatedGameState.state.currentPlayerId === currentPlayerId.value) {
      error.value = t('errors.turnStuck');
    } else {
      doUpdateGameDataSelectively(updatedGameState);
      // Optionally switch player here if needed
    }
  } catch (err) {
    console.error('Failed to skip turn:', err);
    error.value = t('errors.skipTurn', { reason: err.message });
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};

// Keyboard handler for leaderboard modal
const handleLeaderboardKeyDown = (e) => {
  if (e.key === 'Enter') {
    navigateToLobby();
    e.preventDefault();
    e.stopPropagation();
  } else if (e.key === 'Escape') {
    reloadPage();
    e.preventDefault();
    e.stopPropagation();
  }
};

// Keyboard handler for stunned overlay
const handleStunnedKeyDown = (e) => {
  if (e.key === 'Enter' || e.key === 'Escape') {
    skipStunnedPlayerTurn();
    e.preventDefault();
    e.stopPropagation();
  }
};

// Watch leaderboard modal visibility to add/remove keyboard listener
watch(showLeaderboardModal, (visible) => {
  if (visible) {
    window.addEventListener('keydown', handleLeaderboardKeyDown, true);
  } else {
    window.removeEventListener('keydown', handleLeaderboardKeyDown, true);
  }
});

// Watch stunned state to add/remove keyboard listener
watch(isCurrentPlayerStunned, (stunned) => {
  if (stunned) {
    window.addEventListener('keydown', handleStunnedKeyDown, true);
  } else {
    window.removeEventListener('keydown', handleStunnedKeyDown, true);
  }
});

// Add function to check for stunned players and handle player switching
const checkStunnedPlayersAndSwitch = () => {
  if (!gameData.value || !gameData.value.players) return;
  // an online game: this browser plays its own seat only
  if (gameData.value.state?.online) return;
  // hot-seat: the screen follows the human seats itself (followHotseatSeat), no legacy two-player swap
  if (gameData.value.state?.hotseat) return;
  
  const serverCurrentPlayerId = gameData.value.state?.currentPlayerId;
  if (!serverCurrentPlayerId) return;
  
  // Check if we're in a game with an AI player
  const virtualPlayerId = localStorage.getItem('virtualPlayerId');
  const humanPlayerId = localStorage.getItem('humanPlayerId');
  
  // If we have a virtual player in the game, don't switch players based on whose turn it is
  // The human should always control their own player
  if (virtualPlayerId && humanPlayerId) {

    // Ensure the human player remains the controlled player for UI purposes
    // BUT DO NOT change gameData.state.currentPlayerId - that's the server's truth
    if (currentPlayerId.value !== humanPlayerId) {
      currentPlayerId.value = humanPlayerId;
      localStorage.setItem('currentPlayerId', humanPlayerId);
    }
    


    
    // Check if human player is stunned
    const currentPlayerData = gameData.value.players.find(p => p.id === humanPlayerId);
    const isCurrentPlayerStunned = currentPlayerData && (currentPlayerData.hp === 0 || currentPlayerData.defeated === true);

    return;
  }
  
  // First check if our current player is stunned and it's their turn
  const currentPlayerData = gameData.value.players.find(p => p.id === currentPlayerId.value);
  const isCurrentPlayerStunned = currentPlayerData && (currentPlayerData.hp === 0 || currentPlayerData.defeated === true);
  
  // This logic is for local multiplayer only (not AI games)
  // If we have both players stored locally AND no AI player
  if (currentPlayerId.value && secondPlayerId.value && !virtualPlayerId) {
    // If our UI player doesn't match the server player, switch to match server
    if (serverCurrentPlayerId !== currentPlayerId.value) {

      
      // Check if the server's current player matches our second player
      if (serverCurrentPlayerId === secondPlayerId.value) {

        switchPlayer({
          currentPlayerId,
          secondPlayerId,
          loadGameData
        });
      } else {
        // Just update to match server

        currentPlayerId.value = serverCurrentPlayerId;
        localStorage.setItem('currentPlayerId', currentPlayerId.value);
      }
    }
  } else if (!virtualPlayerId) {
    // Only one player stored and NOT an AI game
    // Only update for non-AI players
    currentPlayerId.value = serverCurrentPlayerId;
    localStorage.setItem('currentPlayerId', currentPlayerId.value);
  }
  



};

// Call this function when game data changes
watch(() => gameData.value?.state?.currentPlayerId, (newPlayerId, oldPlayerId) => {
  if (newPlayerId !== oldPlayerId) {

    checkStunnedPlayersAndSwitch();
  }
}, { immediate: true });
</script>

<style>
.away-plaque {
  position: fixed;
  left: 50%;
  bottom: calc(var(--hud-bar-height) + 24px);
  z-index: calc(var(--z-hud) + 5);
  transform: translateX(-50%);
  width: min(420px, calc(100vw - 32px));
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 14px 18px;
  border: 1px solid rgba(var(--ember-rgb), 0.55);
  background: rgba(16, 9, 6, 0.95);
  box-shadow: 0 0 26px rgba(var(--ember-rgb), 0.22);
  text-align: center;
}

.away-plaque__title {
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 24px;
  color: var(--text-heading);
}

.away-plaque__text {
  font-size: 14px;
  font-style: italic;
  color: var(--text-secondary);
}

.push-chip {
  position: fixed;
  top: 12px;
  left: 50%;
  z-index: calc(var(--z-modal) + 1);
  transform: translateX(-50%);
  padding: 6px 14px;
  border: 1px solid rgba(217, 69, 42, 0.6);
  background: rgba(20, 8, 6, 0.94);
  color: #f0b5a8;
  font-size: 14px;
  white-space: nowrap;
}

.action-notice {
  max-width: min(520px, calc(100vw - 32px));
  padding: 8px 18px;
  background: var(--bg-panel);
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  box-shadow: 0 0 18px rgba(255, 100, 20, 0.2), 0 8px 20px rgba(0, 0, 0, 0.7);
  color: var(--text-primary);
  font-style: italic;
  font-size: 0.95rem;
}

.action-notice-enter-active,
.action-notice-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}

.action-notice-enter-from,
.action-notice-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}

/* ============================================
   RESULTS — a centred Torchlight plaque, like the full-pack choice
   ============================================ */
.leaderboard-overlay {
  position: fixed;
  inset: 0;
  z-index: var(--z-modal);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: rgba(5, 3, 2, 0.72);
  animation: resultsFade 0.3s ease-out;
}

@keyframes resultsFade {
  from { opacity: 0; }
  to { opacity: 1; }
}

.leaderboard-card {
  width: min(480px, 100%);
  box-sizing: border-box;
  max-height: calc(100vh - 32px);
  overflow-y: auto;
  padding: 22px 26px 18px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.97) 0%, rgba(12, 7, 5, 0.97) 100%);
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  box-shadow: 0 0 40px rgba(var(--ember-rgb), 0.2), 0 14px 34px rgba(0, 0, 0, 0.85);
  color: var(--text-primary);
  font-family: var(--font-body);
}

.leaderboard-caption {
  align-self: center;
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.32em;
  text-transform: uppercase;
  color: var(--text-accent);
}

.result-banner {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  font-family: var(--font-display);
  font-size: 44px;
  line-height: 1;
}

.result-banner.result-win {
  color: var(--text-heading);
  text-shadow: 0 0 22px rgba(var(--ember-rgb), 0.55), 0 2px 6px #000;
}

.result-banner.result-win :deep(svg) {
  color: var(--color-primary);
}

.result-banner.result-loss {
  color: var(--text-secondary);
  font-size: 32px;
}

.leaderboard-entries {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
}

.lb-entry {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 4px;
  border-top: 1px solid rgba(234, 220, 192, 0.12);
}

.lb-entry:last-child {
  border-bottom: 1px solid rgba(234, 220, 192, 0.12);
}

.lb-rank {
  width: 22px;
  flex: none;
  text-align: center;
  font-family: var(--font-display);
  font-size: 22px;
  color: var(--text-muted);
}

.lb-portrait {
  width: 44px;
  height: 44px;
  flex: none;
  border-radius: 50%;
  object-fit: cover;
  object-position: top;
  border: 2px solid var(--iron-dark, #3a332c);
  background: #0c0806;
}

.lb-winner .lb-portrait {
  border-color: var(--color-primary);
  box-shadow: 0 0 14px rgba(var(--ember-rgb), 0.5);
}

.lb-winner .lb-rank {
  color: var(--color-primary);
}

.lb-name {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.lb-hero {
  font-family: var(--font-display);
  font-size: 22px;
  line-height: 1.05;
  color: var(--text-heading);
}

.lb-role {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-height: 14px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: var(--text-accent);
}

.lb-treasure {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex: none;
  font-family: var(--font-display);
  font-size: 24px;
  color: #d9c08a;
}

/* the buttons as in the other plaques: ghost "view the board", the ember way back, keycaps under them */
.leaderboard-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 30px 12px;
  padding: 4px 0 22px;
}

.leaderboard-actions .btn {
  position: relative;
  flex: 1 1 0;
  min-width: 160px;
  min-height: 50px;
}

.leaderboard-actions .kbd-hint {
  position: absolute;
  top: calc(100% + 8px);
  left: 50%;
  transform: translateX(-50%);
  padding: 2px 7px;
  border: 1px solid currentColor;
  border-bottom-width: 2px;
  border-radius: 3px;
  font-size: 10px;
  font-weight: 600;
  line-height: 1.1;
  color: var(--text-muted);
  pointer-events: none;
}

@media (hover: none), (max-width: 600px) {
  .leaderboard-actions .kbd-hint {
    display: none;
  }

  .leaderboard-actions {
    padding-bottom: 4px;
  }
}

/* Reincarnation target markers pulse so the choice is obvious */
.reincarnation-marker .healing-fountain-indicator {
  animation: reincarnation-pulse 1.2s ease-in-out infinite;
}

@keyframes reincarnation-pulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.12); }
}

/* Game finished: a small plaque under the turn banner (the HUD stays readable around it) */
.game-finished-banner {
  position: fixed;
  top: 108px;
  left: 50%;
  transform: translateX(-50%);
  max-width: calc(100vw - 32px);
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-wrap: wrap;
  gap: 10px 14px;
  padding: 10px 18px;
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.95) 0%, rgba(12, 7, 5, 0.95) 100%);
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  box-shadow: 0 0 26px rgba(var(--ember-rgb), 0.2), 0 10px 24px rgba(0, 0, 0, 0.8);
  color: var(--text-heading);
  z-index: 850; /* over the field and the HUD, under the overlays */
  animation: finishedIn 0.45s ease-out;
}

@keyframes finishedIn {
  from { opacity: 0; transform: translate(-50%, -8px); }
  to { opacity: 1; transform: translate(-50%, 0); }
}

.game-finished-banner .trophy-icon {
  display: inline-flex;
  color: var(--color-primary);
}

.game-finished-banner .finished-lines {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.game-finished-banner .finished-reason {
  font-family: var(--font-body);
  font-style: italic;
  font-size: 14px;
  color: var(--text-muted);
}

.game-finished-banner .finished-text {
  font-family: var(--font-display);
  font-size: 24px;
  line-height: 1.1;
  text-shadow: 0 2px 6px #000;
}

@media (max-width: 600px) {
  /* above the pack row at the bottom */
  .game-finished-banner {
    top: auto;
    bottom: 104px;
  }
}

.game-interface.game-finished .game-field {
  opacity: 0.8;
  /* Allow scrolling but prevent game interactions */
  pointer-events: auto;
}

/* Prevent interactions with game elements when finished */
.game-interface.game-finished .game-field .tiles-container > * {
  pointer-events: none;
}

.game-interface.game-finished .game-controls {
  opacity: 0.5;
  pointer-events: none;
}

/* Add styles for stunned player overlay */
.stunned-player-overlay {
  position: fixed;
  inset: 0;
  z-index: var(--z-overlay);
  display: flex;
  justify-content: center;
  align-items: center;
  padding: 16px;
  background: rgba(5, 3, 2, 0.7);
}

/* right after the knock-out: a plaque by the rings, the field stays usable around it */
.stunned-player-overlay--notice {
  inset: auto 16px 120px auto;
  padding: 0;
  background: none;
  pointer-events: none;
}

.stunned-player-overlay--notice .stunned-message {
  pointer-events: auto;
  width: min(360px, calc(100vw - 32px));
}

/* a plaque like the others, with a blood-red rim */
.stunned-message {
  width: min(420px, 100%);
  box-sizing: border-box;
  padding: 22px 26px 24px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  text-align: center;
  background: linear-gradient(180deg, rgba(20, 12, 8, 0.97) 0%, rgba(12, 7, 5, 0.97) 100%);
  border: 1px solid rgba(217, 69, 42, 0.55);
  box-shadow: 0 0 36px rgba(217, 69, 42, 0.25), 0 14px 34px rgba(0, 0, 0, 0.85);
  color: var(--text-primary);
  font-family: var(--font-body);
}

.stunned-player-icon {
  width: 84px;
  height: 84px;
  border-radius: 50%;
  object-fit: cover;
  object-position: top;
  border: 2px solid var(--color-danger);
  box-shadow: 0 0 18px rgba(217, 69, 42, 0.55);
  filter: grayscale(0.5) brightness(0.8);
}

.stunned-message h3 {
  margin: 4px 0 0;
  font-family: var(--font-display);
  font-weight: 400;
  font-size: 32px;
  color: var(--color-danger);
  text-shadow: 0 0 14px rgba(217, 69, 42, 0.45), 0 2px 6px #000;
}

.stunned-message p {
  margin: 0;
  font-size: 16px;
  line-height: 1.4;
}

.stunned-message .stunned-heal {
  font-style: italic;
  color: var(--text-secondary);
}

.skip-turn-button {
  position: relative;
  margin-top: 10px;
  min-width: 200px;
  min-height: 48px;
}

.skip-turn-button .kbd-hint {
  position: absolute;
  top: calc(100% + 6px);
  left: 50%;
  transform: translateX(-50%);
  font-size: 10px;
  color: var(--text-muted);
}

/* Floating heal text */
.floating-heal-text {
  position: absolute;
  z-index: 100;
  font-size: 1.1rem;
  font-weight: 800;
  color: #ffd28a;
  text-shadow: 0 0 8px rgba(var(--accent-light-rgb), 0.8), 0 2px 4px rgba(0, 0, 0, 0.8);
  pointer-events: none;
  transform: translateX(-50%);
  animation: healFloat 2s ease-out forwards;
}

@keyframes healFloat {
  0% { opacity: 1; transform: translateX(-50%) translateY(0) scale(1.2); }
  20% { opacity: 1; transform: translateX(-50%) translateY(-10px) scale(1); }
  70% { opacity: 1; }
  100% { opacity: 0; transform: translateX(-50%) translateY(-40px); }
}

/* Healing fountain markers */
.gate-links {
  position: absolute;
  left: 0;
  top: 0;
  z-index: 21;
  pointer-events: none;
  overflow: visible;
}

.gate-link {
  stroke: rgba(var(--ember-rgb), 0.75);
  stroke-width: 3;
  stroke-dasharray: 10 9;
  stroke-linecap: round;
  animation: gateDash 1.2s linear infinite;
}

.gate-link-end {
  fill: rgba(var(--ember-rgb), 0.18);
  stroke: rgba(var(--ember-rgb), 0.85);
  stroke-width: 2;
}

@keyframes gateDash {
  to { stroke-dashoffset: -19; }
}

@media (prefers-reduced-motion: reduce) {
  .gate-link {
    animation: none;
  }
}

/* End Turn on a fountain: a slow warm glow — ending the turn here heals */
.end-turn-btn--heal :deep(.iron-ring__disk) {
  animation: healRingGlow 2.2s ease-in-out infinite;
}

@keyframes healRingGlow {
  0%, 100% { box-shadow: 0 0 0 3px var(--iron), 0 0 0 5px var(--iron-edge), 0 0 14px rgba(var(--ember-rgb), 0.45); }
  50% { box-shadow: 0 0 0 3px var(--color-secondary), 0 0 0 5px var(--iron-edge), 0 0 30px rgba(var(--ember-rgb), 0.9); }
}

@media (prefers-reduced-motion: reduce) {
  .end-turn-btn--heal :deep(.iron-ring__disk) {
    animation: none;
  }
}

.skill-target-marker .healing-fountain-indicator {
  color: var(--color-primary);
  transition: transform var(--transition-fast, 0.15s);
}

/* the target pointed at in the plaque's list (or on the field itself) */
.healing-fountain-marker.skill-target-marker.is-hovered {
  z-index: 101 !important;
  border: 3px solid var(--color-primary-hover);
  background: radial-gradient(circle, rgba(var(--ember-rgb), 0.38) 0%, rgba(var(--ember-rgb), 0.06) 75%);
  box-shadow: inset 0 0 40px rgba(255, 140, 40, 0.7), 0 0 28px rgba(var(--ember-rgb), 0.85);
  animation: none;
}

.skill-target-marker.is-hovered .healing-fountain-indicator {
  transform: scale(1.3);
}

.portal-target.is-hovered:not(:disabled) {
  border-color: var(--color-primary-hover);
  color: var(--text-primary);
  background: rgba(var(--ember-rgb), 0.12);
}

.portal-target__where {
  margin-left: 6px;
  font-style: italic;
  font-weight: 400;
  color: var(--text-secondary);
}

.healing-fountain-marker {
  z-index: 100 !important;
  display: flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  /* a fountain the hero can be carried to: an ember frame like the move marks, breathing */
  border: 2px solid rgba(var(--ember-rgb), 0.75);
  background: radial-gradient(circle, rgba(var(--ember-rgb), 0.18) 0%, rgba(var(--ember-rgb), 0) 70%);
  box-shadow: inset 0 0 24px rgba(255, 120, 30, 0.35);
  animation: fountainMarkerBreath 2s ease-in-out infinite;
}

.healing-fountain-marker:hover {
  border-color: var(--color-primary-hover);
  box-shadow: inset 0 0 30px rgba(255, 120, 30, 0.5), 0 0 16px rgba(var(--ember-rgb), 0.4);
}

@keyframes fountainMarkerBreath {
  0%, 100% { box-shadow: inset 0 0 18px rgba(255, 120, 30, 0.3); }
  50% { box-shadow: inset 0 0 32px rgba(255, 120, 30, 0.55), 0 0 12px rgba(var(--ember-rgb), 0.3); }
}

@media (prefers-reduced-motion: reduce) {
  .healing-fountain-marker {
    animation: none;
  }
}

.healing-fountain-indicator {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
}

.fountain-emoji {
  display: inline-flex;
  color: var(--color-danger);
  fill: rgba(217, 69, 42, 0.35);
  filter: drop-shadow(0 0 6px rgba(var(--accent-rgb), 0.8));
}

.current-position-label {
  background: rgba(0, 0, 0, 0.7);
  color: white;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: bold;
}

@keyframes pulse {
  0% {
    box-shadow: 0 0 0 0 rgba(var(--accent-rgb), 0.4);
  }
  70% {
    box-shadow: 0 0 0 15px rgba(var(--accent-rgb), 0);
  }
  100% {
    box-shadow: 0 0 0 0 rgba(var(--accent-rgb), 0);
  }
}

/* Hide regular UI elements during teleport mode */
.game-field.teleport-mode .available-move-marker {
  display: none;
}

/* A fight on the field: FieldFight centred over its tile */
.ai-battle-overlay {
  position: absolute;
  z-index: 100;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}

.kbd-hint {
  font-size: 0.75em;
  opacity: 0.6;
  margin-left: 4px;
}

@media (hover: none) {
  .kbd-hint { display: none; }
}
/* HUD chips over the action rings: the Sprint notice and the Berserk heal-limit popover */
.hud-chip {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  background: var(--bg-panel);
  border: 1px solid rgba(var(--ember-rgb), 0.45);
  box-shadow: 0 0 14px rgba(255, 100, 20, 0.2), 0 6px 16px rgba(0, 0, 0, 0.7);
  color: var(--text-primary);
  font-style: italic;
  font-size: 14px;
}

.heal-limit-label select {
  padding: 2px 6px;
  border-radius: var(--radius-sm);
  background: var(--bg-tertiary);
  color: inherit;
  border: 1px solid var(--iron);
  font-family: var(--font-body);
}

/* Dev-only debug plaque: small and dim, after the rings, out of the way of the real controls */
.debug-plaque {
  align-self: center;
  display: flex;
  flex-direction: column;
  gap: 4px;
  max-width: 170px;
  padding: 6px;
  border: 1px dashed var(--iron-dark);
  border-radius: var(--radius-sm);
  background: transparent;
  opacity: 0.55;
}

.debug-plaque:hover {
  opacity: 0.9;
}

.debug-plaque .debug-plaque-btn {
  padding: 4px 8px;
  border: 1px solid var(--iron-dark);
  border-radius: var(--radius-sm);
  cursor: pointer;
  background: rgba(0, 0, 0, 0.4);
  box-shadow: none;
  color: var(--text-muted);
  font-family: var(--font-body);
  font-size: 12px;
  text-shadow: none;
}

.debug-plaque-note {
  display: block;
  margin-top: 4px;
  text-align: center;
  font-size: 11px;
  color: var(--text-muted);
}
</style>
