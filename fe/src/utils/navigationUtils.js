// Navigation and Interaction Utilities
import { ref } from 'vue';

export function createNavigationManager(fieldElement, viewState) {
  // Mouse event handlers
  const onMouseDown = (e) => {
    if (!fieldElement.value) return;

    if (e.button === 1 || (e.button === 0 && (e.ctrlKey || viewState.isCtrlPressed))) {
      viewState.isDragging = true;
      viewState.startDragX = e.pageX;
      viewState.startDragY = e.pageY;
      viewState.startScrollLeft = fieldElement.value.scrollLeft;
      viewState.startScrollTop = fieldElement.value.scrollTop;

      fieldElement.value.style.cursor = 'grabbing';
      e.preventDefault();
    }
  };

  const onMouseMove = (e) => {
    if (!viewState.isDragging || !fieldElement.value) return;

    const dx = e.pageX - viewState.startDragX;
    const dy = e.pageY - viewState.startDragY;

    fieldElement.value.scrollLeft = viewState.startScrollLeft - dx;
    fieldElement.value.scrollTop = viewState.startScrollTop - dy;

    e.preventDefault();
  };

  const onMouseUp = () => {
    if (!viewState.isDragging) return;

    viewState.isDragging = false;
    if (fieldElement.value) {
      fieldElement.value.style.cursor = 'default';
    }
  };

  const onMouseLeave = () => {
    if (viewState.isDragging) {
      viewState.isDragging = false;
      if (fieldElement.value) {
        fieldElement.value.style.cursor = 'default';
      }
    }
  };

  // Keyboard handlers for CTRL key
  const onKeyDown = (e) => {
    if (e.key === 'Control') {
      viewState.isCtrlPressed = true;
      if (fieldElement.value) {
        fieldElement.value.style.cursor = 'grab';
      }
    }
  };

  const onKeyUp = (e) => {
    if (e.key === 'Control') {
      viewState.isCtrlPressed = false;
      if (fieldElement.value && !viewState.isDragging) {
        fieldElement.value.style.cursor = 'default';
      }
    }
  };

  // Zoom controls
  const zoomIn = () => {
    viewState.zoomLevel = Math.min(viewState.zoomLevel + 0.2, 2);
  };

  const zoomOut = () => {
    viewState.zoomLevel = Math.max(viewState.zoomLevel - 0.2, 0.5);
  };

  const resetZoom = () => {
    viewState.zoomLevel = 1;
  };

  // Setup and cleanup functions
  const setupEventListeners = () => {
    window.addEventListener('mouseup', onMouseUp);
    window.addEventListener('mousemove', onMouseMove);
    window.addEventListener('keydown', onKeyDown);
    window.addEventListener('keyup', onKeyUp);
  };

  const cleanupEventListeners = () => {
    window.removeEventListener('mouseup', onMouseUp);
    window.removeEventListener('mousemove', onMouseMove);
    window.removeEventListener('keydown', onKeyDown);
    window.removeEventListener('keyup', onKeyUp);
  };

  return {
    onMouseDown,
    onMouseMove,
    onMouseUp,
    onMouseLeave,
    onKeyDown,
    onKeyUp,
    zoomIn,
    zoomOut,
    resetZoom,
    setupEventListeners,
    cleanupEventListeners
  };
}

// Resize handler utility
export function createResizeHandler(viewState) {
  const handleResize = () => {
    viewState.zoomLevel = 1;
  };

  const setupResizeListener = () => {
    window.addEventListener('resize', handleResize);
  };

  const cleanupResizeListener = () => {
    window.removeEventListener('resize', handleResize);
  };

  return {
    handleResize,
    setupResizeListener,
    cleanupResizeListener
  };
} 