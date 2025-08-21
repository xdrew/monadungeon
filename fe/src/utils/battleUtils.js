/**
 * Utility functions for battle-related operations in the game
 */

/**
 * Closes battle report and ends the turn
 * @param {Object} params - Parameters for closing battle report and ending turn
 * @param {Ref<boolean>} params.loading - Reference to loading state
 * @param {Ref<string>} params.loadingStatus - Reference to loading status message
 * @param {Ref<boolean>} params.showBattleReportModal - Reference to show battle report modal flag
 * @param {Ref<Object|null>} params.battleInfo - Reference to battle information
 * @param {Ref<Object|null>} params.gameData - Reference to game data
 * @param {Ref<string|null>} params.error - Reference to error message
 * @param {Object} params.gameApi - The game API object
 * @param {string} params.gameId - The game ID
 * @param {string} params.playerId - The player ID
 * @param {Function} params.updateGameDataSelectively - Function to update game data selectively
 */
export const closeBattleReportAndEndTurn = async ({
  loading,
  loadingStatus,
  showBattleReportModal,
  battleInfo,
  gameData,
  error,
  gameApi,
  gameId,
  playerId,
  updateGameDataSelectively
}) => {
  try {
    // Show loading indicator
    loading.value = true;
    loadingStatus.value = 'Ending turn...';

    // Close the battle report modal
    showBattleReportModal.value = false;

    if (!battleInfo.value) {
      console.error('No battle info available');
      return;
    }

    const currentTurnId = gameData.value?.state?.currentTurnId;
    if (!currentTurnId) {
      console.error('No current turn ID found in game state');
      return;
    }

    // Call the end-turn endpoint
    await gameApi.endTurn({
      gameId,
      playerId,
      turnId: currentTurnId
    });

    console.log('Turn ended successfully');

    // Refresh game data after ending turn
    const updatedGameState = await gameApi.getGame(gameId);
    updateGameDataSelectively(updatedGameState);

    // Clear battle info
    battleInfo.value = null;

  } catch (err) {
    console.error('Failed to end turn:', err);
    error.value = `Failed to end turn: ${err.message}`;
  } finally {
    loading.value = false;
    loadingStatus.value = '';
  }
};