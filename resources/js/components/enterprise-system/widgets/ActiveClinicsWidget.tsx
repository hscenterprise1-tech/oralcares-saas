import React from 'react';
import { GrowthMetricWidget } from './GrowthMetricWidget';
import { WidgetProps } from '../manifests/WidgetManifest';

export function ActiveClinicsWidget(props: WidgetProps) {
    return <GrowthMetricWidget 
        {...props} 
        value="142" 
        trend={12} 
        isGood={true}
        
        
    />;
}
