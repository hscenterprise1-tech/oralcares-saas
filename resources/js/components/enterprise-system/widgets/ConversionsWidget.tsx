import React from 'react';
import { GrowthMetricWidget } from './GrowthMetricWidget';
import { WidgetProps } from '../manifests/WidgetManifest';

export function ConversionsWidget(props: WidgetProps) {
    return <GrowthMetricWidget 
        {...props} 
        value="14" 
        trend={8} 
        isGood={true}
        subtitle="This Month"
        
    />;
}
