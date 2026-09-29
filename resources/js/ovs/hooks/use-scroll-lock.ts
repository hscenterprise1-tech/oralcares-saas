import { useEffect } from 'react';
import { router } from '@inertiajs/react';

/**
 * Global Scroll Lock Manager
 *
 * Simultaneously locks scrolling on:
 * 1. document.body (standard viewport)
 * 2. [data-ovs-scroll-viewport] (ExecutiveShell inner scrollable canvas)
 *
 * Maintains a reference counter to support nested modals and drawers gracefully.
 */
class ScrollLockManager {
  private static lockCount = 0;
  private static savedScrollTop = 0;
  private static isInitialized = false;

  private static initRouterListener() {
    if (this.isInitialized || typeof window === 'undefined') return;
    this.isInitialized = true;
    router.on('navigate', () => {
      ScrollLockManager.reset();
    });
  }

  static lock() {
    this.initRouterListener();
    this.lockCount++;
    if (this.lockCount === 1) {
      const scrollViewport = document.querySelector<HTMLElement>('[data-ovs-scroll-viewport]');
      if (scrollViewport) {
        this.savedScrollTop = scrollViewport.scrollTop;
        scrollViewport.style.overflowY = 'hidden';
      }
      document.body.style.overflow = 'hidden';
    }
  }

  static unlock() {
    this.lockCount = Math.max(0, this.lockCount - 1);
    if (this.lockCount === 0) {
      const scrollViewport = document.querySelector<HTMLElement>('[data-ovs-scroll-viewport]');
      if (scrollViewport) {
        scrollViewport.style.overflowY = '';
        if (this.savedScrollTop > 0) {
          scrollViewport.scrollTop = this.savedScrollTop;
        }
      }
      document.body.style.overflow = '';
    }
  }

  static reset() {
    this.lockCount = 0;
    const scrollViewport = document.querySelector<HTMLElement>('[data-ovs-scroll-viewport]');
    if (scrollViewport) {
      scrollViewport.style.overflowY = '';
    }
    document.body.style.overflow = '';
  }
}

/**
 * Hook to lock both document.body and the ExecutiveShell scroll viewport when active.
 *
 * @param isLocked - whether scroll locking is currently active
 */
export function useSynchronizedScrollLock(isLocked = true) {
  useEffect(() => {
    if (!isLocked) return;

    ScrollLockManager.lock();
    return () => {
      ScrollLockManager.unlock();
    };
  }, [isLocked]);
}

export { ScrollLockManager };
