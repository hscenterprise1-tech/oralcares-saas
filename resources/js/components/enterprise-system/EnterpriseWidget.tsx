import React from 'react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { RefreshCw, Download, Maximize2, Sparkles, AlertCircle, Lock, Ban, WifiOff, Wrench } from "lucide-react";
import { cn } from "@/lib/utils";

interface EnterpriseWidgetProps {
  title: string;
  description?: string;
  icon?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
  isLoading?: boolean;
  isError?: boolean;
  isEmpty?: boolean;
  isRefreshing?: boolean;
  isPermissionDenied?: boolean;
  isFeatureDisabled?: boolean;
  isOffline?: boolean;
  isMaintenance?: boolean;
  errorMessage?: string;
  emptyMessage?: string;
  onRefresh?: () => void;
  onExport?: () => void;
  onExpand?: () => void;
  onAiInsight?: () => void;
  headerActions?: React.ReactNode;
}

export function EnterpriseWidget({
  title,
  description,
  icon,
  children,
  className,
  isLoading,
  isError,
  isEmpty,
  isRefreshing,
  isPermissionDenied,
  isFeatureDisabled,
  isOffline,
  isMaintenance,
  errorMessage = "Failed to load data",
  emptyMessage = "No data available",
  onRefresh,
  onExport,
  onExpand,
  onAiInsight,
  headerActions,
}: EnterpriseWidgetProps) {
  return (
    <Card className={cn("flex flex-col h-full bg-white border border-slate-200/80 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.04)] rounded-[24px] overflow-hidden transition-all duration-300 hover:shadow-[0_8px_24px_-8px_rgba(0,0,0,0.08)]", className)}>
      <CardHeader className="flex flex-row items-center justify-between pb-4 space-y-0 px-7 pt-7 border-b border-slate-100">
        <div className="flex items-center gap-3.5">
          {icon && (
            <div className="h-10 w-10 rounded-xl bg-slate-50 flex items-center justify-center text-slate-700 border border-slate-100 shadow-sm">
              {icon}
            </div>
          )}
          <div className="flex flex-col">
            <CardTitle className="text-[16px] font-semibold text-slate-900 leading-none tracking-tight">
              {title}
            </CardTitle>
            {description && (
              <CardDescription className="text-[13px] font-normal text-slate-500 mt-1.5">
                {description}
              </CardDescription>
            )}
          </div>
        </div>
        
        <div className="flex items-center gap-1.5">
          {headerActions}
          {onAiInsight && (
            <Button variant="ghost" size="icon" onClick={onAiInsight} className="h-8 w-8 text-indigo-600 hover:bg-indigo-50 hover:text-indigo-700 transition-colors">
              <Sparkles className="h-4 w-4" />
            </Button>
          )}
          {onRefresh && (
            <Button variant="ghost" size="icon" onClick={onRefresh} className="h-8 w-8 text-slate-400 hover:bg-slate-50 hover:text-slate-700 transition-colors">
              <RefreshCw className={cn("h-4 w-4", (isLoading || isRefreshing) && "animate-spin")} />
            </Button>
          )}
          {onExport && (
            <Button variant="ghost" size="icon" onClick={onExport} className="h-8 w-8 text-slate-400 hover:bg-slate-50 hover:text-slate-700 transition-colors">
              <Download className="h-4 w-4" />
            </Button>
          )}
          {onExpand && (
            <Button variant="ghost" size="icon" onClick={onExpand} className="h-8 w-8 text-slate-400 hover:bg-slate-50 hover:text-slate-700 transition-colors">
              <Maximize2 className="h-4 w-4" />
            </Button>
          )}
        </div>
      </CardHeader>
      
      <CardContent className="flex-1 p-7 relative">
        {isMaintenance ? (
          <div className="flex flex-col items-center justify-center h-full text-center py-6">
            <div className="h-12 w-12 rounded-full bg-slate-50 flex items-center justify-center mb-3">
              <Wrench className="h-6 w-6 text-slate-400" />
            </div>
            <p className="text-[14px] font-medium text-slate-900">Maintenance Mode</p>
            <p className="text-[13px] text-slate-500 mt-1">This feature is temporarily unavailable.</p>
          </div>
        ) : isFeatureDisabled ? (
          <div className="flex flex-col items-center justify-center h-full text-center py-6">
            <div className="h-12 w-12 rounded-full bg-slate-50 flex items-center justify-center mb-3">
              <Ban className="h-6 w-6 text-slate-400" />
            </div>
            <p className="text-[14px] font-medium text-slate-900">Feature Disabled</p>
            <p className="text-[13px] text-slate-500 mt-1">Contact your administrator to enable.</p>
          </div>
        ) : isPermissionDenied ? (
          <div className="flex flex-col items-center justify-center h-full text-center py-6">
            <div className="h-12 w-12 rounded-full bg-slate-50 flex items-center justify-center mb-3">
              <Lock className="h-6 w-6 text-slate-400" />
            </div>
            <p className="text-[14px] font-medium text-slate-900">Access Restricted</p>
            <p className="text-[13px] text-slate-500 mt-1">You don't have permission to view this data.</p>
          </div>
        ) : isOffline ? (
          <div className="flex flex-col items-center justify-center h-full text-center py-6">
            <div className="h-12 w-12 rounded-full bg-slate-50 flex items-center justify-center mb-3">
              <WifiOff className="h-6 w-6 text-slate-400" />
            </div>
            <p className="text-[14px] font-medium text-slate-900">You are offline</p>
            <p className="text-[13px] text-slate-500 mt-1">Check your connection and try again.</p>
          </div>
        ) : isLoading ? (
          <div className="flex flex-col gap-4">
            <div className="h-6 w-1/3 bg-slate-100 animate-pulse rounded-md"></div>
            <div className="h-4 w-full bg-slate-50 animate-pulse rounded-md"></div>
            <div className="h-4 w-5/6 bg-slate-50 animate-pulse rounded-md"></div>
            <div className="h-4 w-4/6 bg-slate-50 animate-pulse rounded-md"></div>
          </div>
        ) : isError ? (
          <div className="flex flex-col items-center justify-center h-full text-center py-6">
            <AlertCircle className="h-8 w-8 text-rose-500 mb-3 opacity-80" />
            <p className="text-[14px] font-medium text-slate-900">{errorMessage}</p>
            {onRefresh && (
              <Button variant="outline" size="sm" onClick={onRefresh} className="mt-4 h-9 text-[13px] border-slate-200 hover:bg-slate-50 transition-colors">
                Try Again
              </Button>
            )}
          </div>
        ) : isEmpty ? (
          <div className="flex flex-col items-center justify-center h-full text-center py-8">
            <div className="h-14 w-14 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center mb-4">
              <span className="text-xl text-slate-400 opacity-50">--</span>
            </div>
            <p className="text-[14px] text-slate-500">{emptyMessage}</p>
          </div>
        ) : (
          <>
            {isRefreshing && (
              <div className="absolute inset-0 flex items-center justify-center bg-surface/50 backdrop-blur-[1px] z-10 rounded-b-2xl">
                <RefreshCw className="h-5 w-5 animate-spin text-interactive" />
              </div>
            )}
            {children}
          </>
        )}
      </CardContent>
    </Card>
  );
}
