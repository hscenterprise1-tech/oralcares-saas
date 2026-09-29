import React from 'react';
import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Card } from '@/ovs/primitives/card';
import { Button } from '@/ovs/primitives/button';
import { Badge } from '@/ovs/primitives/badge';

export interface PricingPlan {
  id: string;
  name: string;
  price: string;
  period: string;
  features: string[];
  highlighted?: boolean;
  ctaLabel?: string;
  onSelect: () => void;
}

export interface PricingTableProps {
  plans: PricingPlan[];
  className?: string;
}

export function PricingTable({ plans, className }: PricingTableProps) {
  return (
    <div className={cn('grid grid-cols-1 md:grid-cols-3 gap-6 items-stretch', className)}>
      {plans.map((plan) => (
        <Card
          key={plan.id}
          elevation={plan.highlighted ? 'medium' : 'surface'}
          className={cn(
            'relative p-6 bg-white rounded-2xl flex flex-col justify-between transition-all space-y-6',
            plan.highlighted
              ? 'border-2 border-ovs-accent shadow-ovs-floating ring-4 ring-ovs-accent/10'
              : 'border border-ovs-border-subtle hover:border-ovs-border-strong shadow-2xs'
          )}
        >
          {plan.highlighted && (
            <div className="absolute -top-3 left-1/2 -translate-x-1/2">
              <Badge variant="warning" className="px-3 py-1 font-bold text-[10px] uppercase tracking-wider shadow-2xs">
                Recommended
              </Badge>
            </div>
          )}

          {/* Plan Header */}
          <div className="space-y-3">
            <h3 className="text-base font-bold text-ovs-primary tracking-tight">{plan.name}</h3>
            <div className="flex items-baseline gap-1">
              <span className="text-3xl font-extrabold text-ovs-primary font-display">{plan.price}</span>
              <span className="text-xs text-ovs-secondary font-medium">/{plan.period}</span>
            </div>
          </div>

          {/* Feature List */}
          <div className="space-y-2.5 flex-1 pt-4 border-t border-ovs-border-subtle">
            <span className="text-[10px] font-bold text-ovs-secondary uppercase tracking-wider block mb-2 font-mono">
              Included Features
            </span>
            {plan.features.map((feat, idx) => (
              <div key={idx} className="flex items-start gap-2 text-xs text-ovs-primary">
                <Check className="w-4 h-4 text-ovs-semantic-success-text flex-shrink-0 mt-0.5" />
                <span className="leading-tight">{feat}</span>
              </div>
            ))}
          </div>

          {/* CTA Action */}
          <Button
            type="button"
            variant={plan.highlighted ? 'primary' : 'secondary'}
            size="md"
            onClick={plan.onSelect}
            className="w-full font-bold shadow-2xs"
          >
            {plan.ctaLabel || 'Choose Plan'}
          </Button>
        </Card>
      ))}
    </div>
  );
}
