import { Button } from '@/components/ui/button';
import { Loader2, Mail } from 'lucide-react';

export function ButtonSection() {
    return (
        <div className="space-y-10">
            <div>
                <h2 className="text-2xl font-bold tracking-tight mb-2">Buttons</h2>
                <p className="text-slate-500">Standardized button components and states.</p>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-12">
                <div className="space-y-6">
                    <h3 className="text-lg font-semibold border-b pb-2">Variants</h3>
                    <div className="flex flex-wrap gap-4 items-center">
                        <Button variant="default">Primary</Button>
                        <Button variant="secondary">Secondary</Button>
                        <Button variant="outline">Outline</Button>
                        <Button variant="ghost">Ghost</Button>
                        <Button variant="link">Link</Button>
                        <Button variant="destructive">Destructive</Button>
                    </div>
                </div>

                <div className="space-y-6">
                    <h3 className="text-lg font-semibold border-b pb-2">Sizes</h3>
                    <div className="flex flex-wrap gap-4 items-center">
                        <Button size="sm">Small</Button>
                        <Button size="default">Default</Button>
                        <Button size="lg">Large</Button>
                        <Button size="icon"><Mail className="h-4 w-4" /></Button>
                    </div>
                </div>

                <div className="space-y-6">
                    <h3 className="text-lg font-semibold border-b pb-2">States</h3>
                    <div className="flex flex-wrap gap-4 items-center">
                        <Button disabled>Disabled</Button>
                        <Button disabled>
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            Loading...
                        </Button>
                        <Button>
                            <Mail className="mr-2 h-4 w-4" />
                            With Icon
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
