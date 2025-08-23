/**
 * Tile Image Configuration
 * Maps tile orientations to their corresponding image files
 * Images should be placed in /public/images/tiles/
 */

/**
 * Get the base image and rotation for a specific tile configuration
 * @param {string} orientationChar - The orientation character (╋, ║, ═, etc.)
 * @param {boolean} isRoom - Whether this is a room tile or corridor tile
 * @returns {{image: string, rotation: number}} The image filename and rotation angle
 */
export const getTileImageWithRotation = (orientationChar, isRoom = false) => {
  // Map orientation characters to base image + rotation
  // We have 4 base images: 1111, 1110, 1100, 1010
  const orientationMap = {
    // Crossroads (4 openings) - 1111 - no rotation needed
    '╬': { base: '1111', rotation: 0 },
    '╋': { base: '1111', rotation: 0 },
    
    // Straight passages (2 openings - opposite sides)
    '║': { base: '1010', rotation: 0 },   // Vertical (top and bottom open)
    '┃': { base: '1010', rotation: 0 },
    '═': { base: '1010', rotation: 90 },  // Horizontal - rotate vertical 90°
    '━': { base: '1010', rotation: 90 },
    
    // Corners (2 openings - adjacent sides) - all use 1100 with different rotations
    '╚': { base: '1100', rotation: 0 },   // Top and right open (base orientation)
    '┗': { base: '1100', rotation: 0 },
    '╔': { base: '1100', rotation: 90 },  // Bottom and right open - rotate 90°
    '┏': { base: '1100', rotation: 90 },
    '╗': { base: '1100', rotation: 180 }, // Bottom and left open - rotate 180°
    '┓': { base: '1100', rotation: 180 },
    '╝': { base: '1100', rotation: 270 }, // Top and left open - rotate 270°
    '┛': { base: '1100', rotation: 270 },
    
    // T-junctions (3 openings) - all use 1110 with different rotations
    '╠': { base: '1110', rotation: 0 },   // Top, right, bottom open (left closed) - base
    '┣': { base: '1110', rotation: 0 },
    '╦': { base: '1110', rotation: 90 },  // Right, bottom, left open (top closed) - rotate 90°
    '┳': { base: '1110', rotation: 90 },
    '╣': { base: '1110', rotation: 180 }, // Top, bottom, left open (right closed) - rotate 180°
    '┫': { base: '1110', rotation: 180 },
    '╩': { base: '1110', rotation: 270 }, // Top, right, left open (bottom closed) - rotate 270°
    '┻': { base: '1110', rotation: 270 },
  };
  
  const config = orientationMap[orientationChar];
  
  if (config) {
    const prefix = isRoom ? 'r' : 'c';
    return {
      image: `/assets/tiles/${prefix}-${config.base}.png`,
      rotation: config.rotation
    };
  }
  
  // No fallback
  return null;
};

/**
 * Get the image filename for a specific tile configuration (backward compatibility)
 * @param {string} orientationChar - The orientation character (╋, ║, ═, etc.)
 * @param {boolean} isRoom - Whether this is a room tile or corridor tile
 * @returns {string} The image filename to use
 */
export const getTileImage = (orientationChar, isRoom = false) => {
  const result = getTileImageWithRotation(orientationChar, isRoom);
  return result ? result.image : null;
};

/**
 * Get the image for a tile with special features
 * @param {string} orientationChar - The orientation character
 * @param {boolean} isRoom - Whether this is a room tile
 * @param {Array} features - Array of feature strings (not used for images)
 * @returns {string} The image filename to use
 */
export const getTileImageWithFeatures = (orientationChar, isRoom, features = []) => {
  // Features like fountains and teleports are handled with CSS/emojis
  // Just return the base tile image
  return getTileImage(orientationChar, isRoom);
};

/**
 * Check if an image exists (useful for fallback)
 * @param {string} imagePath - The image path to check
 * @returns {Promise<boolean>} Whether the image exists
 */
export const imageExists = async (imagePath) => {
  try {
    // For development, check if the file exists
    console.log('Checking image:', imagePath);
    const response = await fetch(imagePath, { method: 'HEAD' });
    console.log('Image check result:', imagePath, response.ok, response.status);
    return response.ok;
  } catch (error) {
    console.error('Error checking image:', imagePath, error);
    return false;
  }
};

/**
 * Get tile image with fallback
 * @param {string} orientationChar - The orientation character
 * @param {boolean} isRoom - Whether this is a room tile
 * @param {Array} features - Array of feature strings (not used)
 * @returns {Promise<string>} The image path to use
 */
export const getTileImageWithFallback = async (orientationChar, isRoom, features = []) => {
  // Get the base tile image (features are handled separately with CSS/emojis)
  const baseImage = getTileImage(orientationChar, isRoom);
  
  // If no image path, return null
  if (!baseImage) {
    return null;
  }
  
  // Check if the image exists
  if (await imageExists(baseImage)) {
    return baseImage;
  }
  
  // Fallback - return null to use CSS styling
  return null;
};

/**
 * Preload all tile images for better performance
 * @returns {Promise<void>}
 */
export const preloadTileImages = async () => {
  // Only preload the tiles we actually have
  const tilePaths = [
    '/assets/tiles/c-1111.png', // Corridor crossroads
    '/assets/tiles/c-1110.png', // Corridor T-junction
    '/assets/tiles/c-1100.png', // Corridor corner
    '/assets/tiles/c-1010.png', // Corridor straight vertical
    '/assets/tiles/r-1111.png', // Room crossroads
    '/assets/tiles/r-1110.png', // Room T-junction
    '/assets/tiles/r-1100.png', // Room corner
    '/assets/tiles/r-1010.png', // Room straight vertical
  ];
  
  // Preload images
  const promises = tilePaths.map(path => {
    return new Promise((resolve) => {
      const img = new Image();
      img.onload = resolve;
      img.onerror = resolve; // Resolve even on error to not block
      img.src = path;
    });
  });
  
  await Promise.all(promises);
};

export default {
  getTileImage,
  getTileImageWithRotation,
  getTileImageWithFeatures,
  getTileImageWithFallback,
  imageExists,
  preloadTileImages
};