import React from 'react';
import { GrowthMetricWidget } from './GrowthMetricWidget';
import { WidgetProps } from '../manifests/WidgetManifest';

export function TrialClinicsWidget(props: WidgetProps) {
    return <GrowthMetricWidget 
        {...props} 
        value="28" 
        trend={4} 
        isGood={true}
        
        
    />;
}
