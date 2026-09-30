import React from 'react';
import { GrowthMetricWidget } from './GrowthMetricWidget';
import { WidgetProps } from '../manifests/WidgetManifest';

export function RenewalsDueMetricWidget(props: WidgetProps) {
    return <GrowthMetricWidget 
        {...props} 
        value="6" 
        trend={0} 
        isGood={false}
        subtitle="Next 30 Days"
        attention={true}
    />;
}
