import React from 'react';
import { ExecutiveHeader, ExecutiveHeaderProps } from '@/components/executive-shell/ExecutiveHeader';

export interface HeaderProps {
    onMenuClick?: () => void;
    onToggleMobileDrawer?: () => void;
    className?: string;
}

export function Header({ onMenuClick, onToggleMobileDrawer, className }: HeaderProps) {
    const handleToggle = onToggleMobileDrawer || onMenuClick;
    return <ExecutiveHeader onToggleMobileDrawer={handleToggle} className={className} />;
}

export { ExecutiveHeader as OvsHeader, ExecutiveHeader };
