import { Component, type ErrorInfo, type ReactNode } from 'react';
import { StatePanel } from '../ui/StatePanel';
import { buttonClasses } from '../ui/Button';

interface Props {
  /** Changing this (the page URL) clears a caught error, so navigating away recovers. */
  resetKey: string;
  children: ReactNode;
}

interface State {
  failed: boolean;
}

/**
 * Keeps one broken page from taking the whole portal down: the navigation
 * stays usable and the page area says what happened. The error goes to the
 * console for developers, never onto the screen.
 */
export class PortalErrorBoundary extends Component<Props, State> {
  state: State = { failed: false };

  static getDerivedStateFromError(): State {
    return { failed: true };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('Clinic Portal page failed to render', error, info.componentStack);
  }

  componentDidUpdate(previous: Props): void {
    if (this.state.failed && previous.resetKey !== this.props.resetKey) {
      this.setState({ failed: false });
    }
  }

  render(): ReactNode {
    if (!this.state.failed) {
      return this.props.children;
    }

    return (
      <div className="rounded-3xl border border-clinic-border bg-clinic-surface">
        <StatePanel
          kind="error"
          title="This page could not be shown"
          description="Something went wrong while displaying it. Reloading usually fixes this; your saved work is safe."
          action={
            <button type="button" onClick={() => window.location.reload()} className={buttonClasses('primary', 'md')}>
              Reload page
            </button>
          }
        />
      </div>
    );
  }
}
