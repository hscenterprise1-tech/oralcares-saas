import { usePage } from '@inertiajs/react';
import { PageProps } from '../types';
import { usePreview } from '@/contexts/PreviewContext';
import { FeatureFlag } from '@/constants/features';

export function useFeature() {
    const { auth, features: serverFeatures } = usePage<PageProps & { features?: Record<string, boolean> }>().props;
    const { previewState } = usePreview();

    // In production, features should be injected into Inertia shared props
    // We fall back to false if the property doesn't exist
    const isPreview = previewState.isEnabled;

    const isFeatureEnabled = (feature: FeatureFlag): boolean => {
        if (isPreview) {
            return previewState.featureFlags[feature] === true;
        }
        
        if (!serverFeatures) return false;
        return serverFeatures[feature] === true;
    };

    const hasAnyFeature = (featuresToCheck: FeatureFlag[]): boolean => {
        return featuresToCheck.some(feature => isFeatureEnabled(feature));
    };

    const hasAllFeatures = (featuresToCheck: FeatureFlag[]): boolean => {
        return featuresToCheck.every(feature => isFeatureEnabled(feature));
    };

    return {
        isFeatureEnabled,
        hasAnyFeature,
        hasAllFeatures,
        // Optional: raw map access
        features: isPreview ? previewState.featureFlags : (serverFeatures ?? {})
    };
}
