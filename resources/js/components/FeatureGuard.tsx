import { ReactNode } from 'react';
import { useFeature } from '@/hooks/useFeature';
import { FeatureFlag } from '@/constants/features';

interface FeatureGuardProps {
    requires: FeatureFlag;
    children: ReactNode;
    fallback?: ReactNode;
}

export function FeatureGuard({ requires, children, fallback = null }: FeatureGuardProps) {
    const { isFeatureEnabled } = useFeature();

    if (isFeatureEnabled(requires)) {
        return <>{children}</>;
    }

    return <>{fallback}</>;
}
