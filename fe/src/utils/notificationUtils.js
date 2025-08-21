/**
 * Utility functions for handling notifications in the game
 */

/**
 * Dismisses a notification
 * @param {Object} params - Parameters for dismissing notification
 * @param {Ref<boolean>} params.playerSwitched - Reference to player switched flag
 * @param {Ref<number|null>} params.notificationTimeout - Reference to notification timeout
 */
export const dismissNotification = ({ playerSwitched, notificationTimeout }) => {
  playerSwitched.value = false;
  if (notificationTimeout.value) {
    clearTimeout(notificationTimeout.value);
    notificationTimeout.value = null;
  }
};

/**
 * Shows a player switch notification
 * @param {Object} params - Parameters for showing notification
 * @param {Ref<boolean>} params.playerSwitched - Reference to player switched flag
 * @param {Ref<number|null>} params.notificationTimeout - Reference to notification timeout
 * @param {number} [params.duration=5000] - Duration to show notification in milliseconds
 */
export const showPlayerSwitchNotification = ({ playerSwitched, notificationTimeout, duration = 5000 }) => {
  // Clear any existing timeout
  if (notificationTimeout.value) {
    clearTimeout(notificationTimeout.value);
  }

  // Show the notification
  playerSwitched.value = true;

  // Auto-dismiss after specified duration
  notificationTimeout.value = setTimeout(() => {
    playerSwitched.value = false;
  }, duration);
};