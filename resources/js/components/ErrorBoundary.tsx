import { Component, ErrorInfo, ReactNode } from 'react';
import { AlertCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';

interface Props {
    children?: ReactNode;
}

interface State {
    hasError: boolean;
    error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
    public state: State = {
        hasError: false,
        error: null
    };

    public static getDerivedStateFromError(error: Error): State {
        return { hasError: true, error };
    }

    public componentDidCatch(error: Error, errorInfo: ErrorInfo) {
        console.error('Uncaught error:', error, errorInfo);
    }

    public render() {
        if (this.state.hasError) {
            return (
                <div className="min-h-[400px] flex items-center justify-center p-4">
                    <Card className="max-w-md w-full border-ovs-semantic-danger/30/20 shadow-sm">
                        <CardHeader className="text-center pb-2">
                            <div className="mx-auto w-12 h-12 rounded-full bg-ovs-semantic-danger-bg flex items-center justify-center mb-4">
                                <AlertCircle className="w-6 h-6 text-ovs-semantic-danger-text" />
                            </div>
                            <CardTitle className="text-ovs-semantic-danger-text">Something went wrong</CardTitle>
                            <CardDescription>
                                An unexpected error occurred while rendering this component.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="text-sm text-ovs-secondary text-center">
                            {this.state.error?.message && (
                                <div className="bg-slate-50 p-3 rounded-md border border-slate-100 overflow-auto text-left">
                                    <code className="text-xs text-slate-800 break-words font-mono">
                                        {this.state.error.message}
                                    </code>
                                </div>
                            )}
                        </CardContent>
                        <CardFooter className="justify-center">
                            <Button variant="outline" onClick={() => window.location.reload()}>
                                Refresh Page
                            </Button>
                        </CardFooter>
                    </Card>
                </div>
            );
        }

        return this.props.children;
    }
}
