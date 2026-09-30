import React from 'react';
import { GrowthMetricWidget } from './GrowthMetricWidget';
import { WidgetProps } from '../manifests/WidgetManifest';

export function NewLeadsWidget(props: WidgetProps) {
    return <GrowthMetricWidget 
        {...props} 
        value="64" 
        trend={18} 
        isGood={true}
        subtitle="This Week"
        
    />;
}
