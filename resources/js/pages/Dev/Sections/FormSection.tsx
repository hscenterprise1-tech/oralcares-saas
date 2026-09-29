import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Slider } from '@/components/ui/slider';

export function FormSection() {
    return (
        <div className="space-y-10">
            <div>
                <h2 className="text-2xl font-bold tracking-tight mb-2">Forms & Inputs</h2>
                <p className="text-slate-500">Standard form controls and input states.</p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-12 max-w-4xl">
                {/* Basic Inputs */}
                <div className="space-y-6">
                    <div className="space-y-2">
                        <Label htmlFor="email">Email address</Label>
                        <Input id="email" type="email" placeholder="name@example.com" />
                    </div>
                    
                    <div className="space-y-2">
                        <Label htmlFor="password">Password</Label>
                        <Input id="password" type="password" placeholder="••••••••" />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="error" className="text-ovs-semantic-danger-text">Invalid Input</Label>
                        <Input id="error" className="border-ovs-semantic-danger/30 focus-visible:ring-ovs-semantic-danger/40" placeholder="Error state" defaultValue="wrong value" />
                        <p className="text-xs text-ovs-semantic-danger-text">This field is required.</p>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="disabled" className="text-ovs-secondary">Disabled Input</Label>
                        <Input id="disabled" disabled placeholder="Not allowed" />
                    </div>
                </div>

                {/* Toggles & Controls */}
                <div className="space-y-8">
                    <div className="flex items-center space-x-2">
                        <Switch id="airplane-mode" />
                        <Label htmlFor="airplane-mode">Airplane Mode</Label>
                    </div>

                    <div className="flex items-center space-x-2">
                        <Checkbox id="terms" />
                        <Label htmlFor="terms" className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
                            Accept terms and conditions
                        </Label>
                    </div>

                    <div className="space-y-3">
                        <Label>Notification Preference</Label>
                        <RadioGroup defaultValue="all">
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem value="all" id="r1" />
                                <Label htmlFor="r1">All new messages</Label>
                            </div>
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem value="mentions" id="r2" />
                                <Label htmlFor="r2">Direct mentions and replies</Label>
                            </div>
                        </RadioGroup>
                    </div>

                    <div className="space-y-3">
                        <Label>Volume Level</Label>
                        <Slider defaultValue={[50]} max={100} step={1} />
                    </div>
                </div>
            </div>
        </div>
    );
}
