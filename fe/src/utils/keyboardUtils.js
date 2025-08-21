/**
 * Utility functions for keyboard-related operations in the game
 */

/**
 * Handles keyboard events for game controls
 * @param {Object} params - Parameters for handling keyboard events
 * @param {Event} params.e - The keyboard event
 * @param {Ref<boolean>} params.isPlayerTurn - Reference to is player turn flag
 * @param {Ref<boolean>} params.showBattleReportModal - Reference to show battle report modal flag
 * @param {Function} params.closeBattleReportAndEndTurn - Function to close battle report and end turn
 * @param {Ref<string|null>} params.pickedTileId - Reference to picked tile ID
 * @param {Ref<Object|null>} params.pickedTile - Reference to picked tile
 * @param {Ref<boolean>} params.isPlacingTile - Reference to is placing tile flag
 * @param {Function} params.rotateGhostTileLocal - Function to rotate ghost tile locally
 * @param {Ref<string|null>} params.ghostTilePosition - Reference to ghost tile position
 * @param {Function} params.handlePlaceClick - Function to handle place click
 * @param {Function} params.getProcessedAvailablePlaces - Function to get processed available places
 * @param {Ref<Object|null>} params.gameData - Reference to game data
 * @param {Ref<string>} params.currentPlayerId - Reference to current player ID
 */
export const handleKeyboardEvents = ({
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
  currentPlayerId
}) => {
  // Helper function to get value from a possible ref
  const getValue = (ref) => ref && typeof ref.value !== 'undefined' ? ref.value : ref;

  // Get values from possible refs
  const isPlayerTurnValue = getValue(isPlayerTurn);
  const showBattleReportModalValue = getValue(showBattleReportModal);
  const pickedTileIdValue = getValue(pickedTileId);
  const pickedTileValue = getValue(pickedTile);
  const isPlacingTileValue = getValue(isPlacingTile);
  const ghostTilePositionValue = getValue(ghostTilePosition);

  // If battle report is open, allow closing with Escape key
  if (showBattleReportModalValue && e.key === 'Escape') {
    closeBattleReportAndEndTurn();
    e.preventDefault();
    return;
  }

  // Always handle basic navigation keys regardless of turn
  const isNavigationKey = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key);

  // Only handle non-navigation keyboard events if it's player's turn
  if (!isPlayerTurnValue && !isNavigationKey) return;

  // If we have a picked tile, handle its movement
  if (pickedTileIdValue && pickedTileValue && isPlacingTileValue) {
    if (e.key === 'r') {
      rotateGhostTileLocal();
      e.preventDefault();
    } else if (e.key === 'Enter') {
      // Place the tile at current ghost position
      if (ghostTilePositionValue) {
        handlePlaceClick(ghostTilePositionValue);
        e.preventDefault();
      }
      return;
    }
    return;
  }

  // Handle arrow keys for ghost tile placement
  // Check if gameData is a ref or direct value
  const gameDataObj = gameData && typeof gameData.value !== 'undefined' ? gameData.value : gameData;

  // If we don't have game data yet, we can't process movement
  if (!gameDataObj) return;

  const availablePlaces = getProcessedAvailablePlaces(gameDataObj, 'placeTile');
  const availableMoves = getProcessedAvailablePlaces(gameDataObj, 'moveTo');

  // Combine all valid positions (both empty places and already placed tiles)
  const allValidPositions = [...new Set([...availablePlaces.map(p => `${p.x},${p.y}`), ...availableMoves.map(p => `${p.x},${p.y}`)])];

  // For navigation keys, we'll still process them even if there are no valid positions
  // This allows basic movement when the game is starting or when it's not the player's turn
  if (!allValidPositions.length && !isNavigationKey) return;

  // Get current ghost position or player position as reference
  let currentPos;
  if (ghostTilePositionValue) {
    currentPos = ghostTilePositionValue;
  } else {
    // Use the gameDataObj we already defined and the currentPlayerId value we extracted
    const currentPlayerIdValue = getValue(currentPlayerId);

    if (gameDataObj?.field?.playerPositions?.[currentPlayerIdValue]) {
      currentPos = gameDataObj.field.playerPositions[currentPlayerIdValue];
    } else if (isNavigationKey) {
      // For navigation keys, if we don't have a current position, use a default position
      // This allows arrow keys to work even when the player position is not set yet
      currentPos = "0,0";
    } else {
      return;
    }
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
      if (!pickedTileIdValue && allValidPositions.includes(currentPos)) {
        handlePlaceClick(currentPos);
        e.preventDefault();
      }
      break;
    default:
      return;
  }

  // Check if the target position is a valid move or place for a new tile
  if (targetPos) {
    // For navigation keys, we'll still process them even if the target position is not valid
    // This allows basic movement when the game is starting or when it's not the player's turn
    if (isNavigationKey || allValidPositions.includes(targetPos)) {
      // If we don't have a picked tile yet, simulate click on the position
      if (!pickedTileIdValue) {
        handlePlaceClick(targetPos);
      } else if (availablePlaces.some(place => `${place.x},${place.y}` === targetPos)) {
        // If we already have a picked tile, just move the ghost (only to empty places)
        // Update the ref if it has a setter, otherwise assume it's a direct value that can't be modified
        if (typeof ghostTilePosition === 'object' && ghostTilePosition !== null) {
          ghostTilePosition.value = targetPos;
        }
      }
    }
  }
};
