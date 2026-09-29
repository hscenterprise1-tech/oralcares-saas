import React from 'react';
import { User, Settings, HelpCircle, LogOut, ChevronDown, Shield } from 'lucide-react';
import { usePage, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

function getInitials(name: string): string {
    return name
        .split(' ')
        .slice(0, 2)
        .map((w) => w.charAt(0))
        .join('')
        .toUpperCase();
}

/**
 * Override ALL shadcn hover/focus colour tokens.
 * Text must always remain #0F172A — only the background changes.
 * `data-[highlighted]` targets Radix's internal focus state.
 */
const menuItemClass =
    'cursor-pointer rounded-xl px-3 py-2.5 flex items-center gap-3 ' +
    'text-[#0F172A] bg-transparent ' +
    'hover:bg-[#F4F6F9] hover:text-[#0F172A] ' +
    'focus:bg-[#F4F6F9] focus:text-[#0F172A] ' +
    'data-[highlighted]:bg-[#F4F6F9] data-[highlighted]:text-[#0F172A] ' +
    'transition-colors duration-150 outline-none select-none';

const menuItems = [
    { label: 'My Profile',       icon: User },
    { label: 'Account Settings', icon: Settings },
    { label: 'Preferences',      icon: Shield },
    { label: 'Help & Support',   icon: HelpCircle },
];

export function ProfileMenu() {
    const { auth } = usePage().props as any;
    const user = auth?.user;
    const initials = user?.name ? getInitials(user.name) : 'A';
    const roleName = user?.roles?.[0] || user?.user_type || 'Administrator';

    const handleLogout = () => {
        router.post('/company/logout');
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    id="profile-menu-trigger"
                    className={cn(
                        'flex items-center gap-2.5 h-[42px] px-2.5 rounded-xl',
                        'border border-transparent bg-transparent',
                        'text-[#0F172A] hover:text-[#0F172A]',
                        'hover:bg-[#F4F6F9] hover:border-[#E8EDF3]',
                        'focus-visible:ring-0 focus-visible:ring-offset-0',
                        'transition-all duration-200 select-none'
                    )}
                >
                    {/* Gradient Avatar */}
                    <div className="h-[30px] w-[30px] rounded-full bg-gradient-to-br from-[#0A2E63] to-[#17B6C8] flex items-center justify-center text-white font-bold text-[12px] shrink-0 shadow-none ring-1 ring-black/5">
                        {initials}
                    </div>
                    {/* Name + Role */}
                    <div className="hidden lg:flex flex-col items-start min-w-0">
                        <span className="text-[13px] font-semibold text-[#0F172A] leading-tight truncate max-w-[120px]">
                            {user?.name || 'Administrator'}
                        </span>
                        <span className="text-[10.5px] text-[#94A3B8] leading-tight font-medium capitalize">
                            {roleName}
                        </span>
                    </div>
                    <ChevronDown className="h-3.5 w-3.5 text-[#94A3B8] hidden lg:block shrink-0" strokeWidth={2} />
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align="end"
                sideOffset={10}
                className="w-[248px] rounded-2xl shadow-[0_20px_60px_rgba(0,0,0,0.12),0_4px_16px_rgba(0,0,0,0.06)] border border-[#E8EDF3] p-2 bg-white"
            >
                {/* ── Identity Card ── */}
                <div className="flex items-center gap-3 px-3 pt-2.5 pb-3">
                    <div className="h-10 w-10 rounded-full bg-gradient-to-br from-[#0A2E63] to-[#17B6C8] flex items-center justify-center text-white font-bold text-[14px] shrink-0 shadow-none ring-1 ring-black/5">
                        {initials}
                    </div>
                    <div className="flex flex-col min-w-0">
                        <p className="text-[13.5px] font-semibold text-[#0F172A] leading-snug truncate">
                            {user?.name || 'Administrator'}
                        </p>
                        <p className="text-[11.5px] text-[#94A3B8] leading-snug truncate mt-0.5">
                            {user?.email || 'admin@oralcares.in'}
                        </p>
                    </div>
                </div>


                <DropdownMenuSeparator className="bg-[#F1F5F9] mx-1 mb-1" />

                {/* ── Main Menu Items ── */}
                <DropdownMenuGroup className="space-y-0.5">
                    {menuItems.map(({ label, icon: Icon }) => (
                        <DropdownMenuItem key={label} className={menuItemClass}>
                            <div className="w-7 h-7 rounded-lg bg-[#F8FAFC] border border-[#E8EDF3] flex items-center justify-center shrink-0">
                                <Icon className="h-3.5 w-3.5 text-[#64748B]" strokeWidth={1.75} />
                            </div>
                            <span className="text-[13px] font-medium text-[#0F172A]">{label}</span>
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuGroup>

                <DropdownMenuSeparator className="bg-[#F1F5F9] mx-1 mt-1 mb-1" />

                {/*
                 * Logout — plain <button>, NOT wrapped in DropdownMenuItem.
                 * This bypasses all Radix/shadcn hover·focus colour overrides entirely.
                 * Every visual state is controlled exclusively by Tailwind classes below.
                 */}
                <button
                    onClick={handleLogout}
                    className={cn(
                        'w-full flex items-center gap-3 px-3 py-2.5 rounded-xl',
                        'text-[#374151]',
                        'hover:bg-red-50 hover:text-[#DC2626]',
                        'focus:bg-red-50 focus:text-[#DC2626] focus:outline-none',
                        'active:bg-red-100 active:text-[#B91C1C]',
                        'transition-colors duration-150',
                        'text-[13px] font-medium cursor-pointer select-none'
                    )}
                >
                    <div className="w-7 h-7 rounded-lg bg-[#FEF2F2] border border-[#FECACA] flex items-center justify-center shrink-0">
                        <LogOut className="h-3.5 w-3.5 text-[#DC2626]" strokeWidth={1.75} />
                    </div>
                    Log out
                </button>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
