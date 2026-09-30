import { usePreview } from '@/contexts/PreviewContext';
import { ALL_FEATURES, FeatureFlag } from '@/constants/features';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';

export function FeatureFlagsSection() {
    const { previewState, updateFeatureFlag } = usePreview();

    // Group features by prefix (e.g. "core", "patient")
    const groupedFeatures = ALL_FEATURES.reduce((acc, feature) => {
        const [group, name] = feature.split('.');
        if (!acc[group]) acc[group] = [];
        acc[group].push({ feature, name });
        return acc;
    }, {} as Record<string, { feature: FeatureFlag, name: string }[]>);

    return (
        <div className="space-y-10">
            <div>
                <h2 className="text-2xl font-bold tracking-tight mb-2">Feature Flags</h2>
                <p className="text-slate-500">
                    Instantly enable or disable platform features. Changing the <strong>Subscription</strong> in the top panel will automatically reset these flags to match the plan presets.
                </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">
                {Object.entries(groupedFeatures).map(([group, features]) => (
                    <div key={group} className="space-y-4">
                        <h3 className="text-lg font-semibold capitalize border-b pb-2 text-slate-800">{group}</h3>
                        <div className="space-y-3">
                            {features.map(({ feature, name }) => (
                                <div key={feature} className="flex items-center justify-between space-x-2">
                                    <Label 
                                        htmlFor={feature} 
                                        className="text-sm font-medium leading-none cursor-pointer capitalize"
                                    >
                                        {name.replace(/_/g, ' ')}
                                    </Label>
                                    <Switch
                                        id={feature}
                                        checked={previewState.featureFlags[feature] || false}
                                        onCheckedChange={(checked) => updateFeatureFlag(feature, checked)}
                                        disabled={!previewState.isEnabled}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
