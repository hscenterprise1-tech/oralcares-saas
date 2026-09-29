import React, { useState } from 'react';
import { CalendarIcon } from 'lucide-react';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { cn } from '@/lib/utils';

interface GlobalDateRangeSelectorProps {
  className?: string;
}

export function GlobalDateRangeSelector({ className }: GlobalDateRangeSelectorProps) {
  const [value, setValue] = useState("last_30_days");

  return (
    <Select value={value} onValueChange={setValue}>
      <SelectTrigger className={cn("h-8 bg-white border-[#E2E8F0] shadow-sm text-xs font-medium text-[#0A2540] w-[160px]", className)}>
        <div className="flex items-center gap-2">
          <CalendarIcon className="w-3.5 h-3.5 text-[#64748B]" />
          <SelectValue placeholder="Select Date Range" />
        </div>
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="today">Today</SelectItem>
        <SelectItem value="yesterday">Yesterday</SelectItem>
        <SelectItem value="last_7_days">Last 7 Days</SelectItem>
        <SelectItem value="last_30_days">Last 30 Days</SelectItem>
        <SelectItem value="this_month">This Month</SelectItem>
        <SelectItem value="last_quarter">Last Quarter</SelectItem>
        <SelectItem value="custom">Custom Range...</SelectItem>
      </SelectContent>
    </Select>
  );
}
