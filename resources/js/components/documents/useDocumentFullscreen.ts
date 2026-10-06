import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * Full-screen editing for a document editor.
 *
 * The Fullscreen API is used when the webview allows it; otherwise (or if
 * the request is refused) the element is simply laid over the whole window
 * with CSS. Escape always leaves full screen and is swallowed so it does not
 * also close a surrounding dialog.
 */
export const useDocumentFullscreen = (target: Ref<HTMLElement | null>) => {
    const isFullscreen = ref(false);
    let native = false;
    let exitedAt = 0;
    let previousOverflow = '';

    const lockScroll = (locked: boolean) => {
        if (locked) {
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = previousOverflow;
        }
    };

    const markExited = () => {
        if (!isFullscreen.value) {
            return;
        }

        isFullscreen.value = false;
        exitedAt = Date.now();

        if (!native) {
            lockScroll(false);
        }

        native = false;
    };

    const enter = async () => {
        const element = target.value;

        if (!element || isFullscreen.value) {
            return;
        }

        isFullscreen.value = true;

        if (typeof element.requestFullscreen === 'function') {
            try {
                await element.requestFullscreen({ navigationUI: 'hide' });
                native = true;

                return;
            } catch {
                native = false;
            }
        }

        lockScroll(true);
    };

    const exit = async () => {
        if (!isFullscreen.value) {
            return;
        }

        const wasNative = native;
        markExited();

        if (wasNative && document.fullscreenElement) {
            try {
                await document.exitFullscreen();
            } catch {
                // Already left (e.g. the user pressed Escape).
            }
        }
    };

    const toggle = () => (isFullscreen.value ? exit() : enter());

    const onFullscreenChange = () => {
        if (native && document.fullscreenElement !== target.value) {
            markExited();
        }
    };

    const onKeydown = (event: KeyboardEvent) => {
        if (event.key !== 'Escape') {
            return;
        }

        if (isFullscreen.value || Date.now() - exitedAt < 500) {
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
            void exit();
        }
    };

    onMounted(() => {
        document.addEventListener('fullscreenchange', onFullscreenChange);
        window.addEventListener('keydown', onKeydown, true);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('fullscreenchange', onFullscreenChange);
        window.removeEventListener('keydown', onKeydown, true);
        void exit();
    });

    return { isFullscreen, enter, exit, toggle };
};
