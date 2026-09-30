import React from 'react';
import { GrowthMetricWidget } from './GrowthMetricWidget';
import { WidgetProps } from '../manifests/WidgetManifest';

export function DemosScheduledWidget(props: WidgetProps) {
    return <GrowthMetricWidget 
        {...props} 
        value="12" 
        trend={-2} 
        isGood={false}
        subtitle="Next 7 Days"
        
    />;
}
