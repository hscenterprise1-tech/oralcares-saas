import React from 'react';
import {
    Plus, Users, Stethoscope, UserCog,
    CheckSquare, FileText, Megaphone
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Link } from '@inertiajs/react';

type CreateGroup = {
    label: string;
    dot: string;
    items: { label: string; icon: React.ElementType; href: string }[];
};

const createGroups: CreateGroup[] = [
    {
        label: 'CRM',
        dot: '#3B82F6',
        items: [
            { label: 'Lead', icon: Users, href: '/company/crm/leads/create' },
        ],
    },


    {
        label: 'Marketing',
        dot: '#EC4899',
        items: [
            { label: 'Campaign', icon: Megaphone, href: '/company/marketing/campaigns/create' },
        ],
    },
];

export function QuickCreate() {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    id="quick-create-trigger"
                    className="bg-[#0F172A] hover:bg-[#1E293B] active:bg-[#0F172A] text-white rounded-xl h-[42px] px-4 shadow-[0_2px_8px_rgba(15,23,42,0.2)] hover:shadow-[0_4px_12px_rgba(15,23,42,0.25)] flex items-center justify-center gap-2 transition-all duration-200 border-0 select-none cursor-pointer"
                >
                    <Plus className="h-4 w-4 text-white" strokeWidth={2.5} />
                    <span className="hidden sm:inline font-semibold text-[13px] tracking-[0.01em]">Create</span>
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align="end"
                sideOffset={10}
                className="w-[210px] rounded-2xl shadow-[0_20px_60px_rgba(0,0,0,0.12),0_4px_16px_rgba(0,0,0,0.06)] border border-[#E8EDF3] p-2 bg-white"
            >
                {createGroups.map((group, gIdx) => (
                    <React.Fragment key={group.label}>
                        {gIdx > 0 && <DropdownMenuSeparator className="my-1.5 bg-[#F4F6F9]" />}

                        <DropdownMenuLabel className="px-2.5 py-1.5">
                            <div className="flex items-center gap-2">
                                <div className="w-1.5 h-1.5 rounded-full opacity-70" style={{ backgroundColor: group.dot }} />
                                <span className="text-[10.5px] font-[750] uppercase tracking-[0.14em] text-[#94A3B8]">
                                    {group.label}
                                </span>
                            </div>
                        </DropdownMenuLabel>

                        {group.items.map((item) => {
                            const Icon = item.icon;
                            return (
                                <DropdownMenuItem key={item.href} asChild className="p-0 !outline-none !ring-0 !border-none">
                                    <Link
                                        href={item.href}
                                        className="flex items-center gap-3 px-2.5 py-2 rounded-xl cursor-pointer w-full group/item text-[#0F172A] bg-transparent hover:bg-[#F4F6F9] hover:text-[#0F172A] focus:bg-[#F4F6F9] focus:text-[#0F172A] data-[highlighted]:bg-[#F4F6F9] data-[highlighted]:text-[#0F172A] transition-colors duration-150 outline-none select-none"
                                    >
                                        <div className="w-7 h-7 rounded-lg border border-[#E8EDF3] bg-[#F8FAFC] flex items-center justify-center shrink-0 group-data-[highlighted]/item:bg-white group-data-[highlighted]/item:border-[#DDE3EC] group-hover/item:bg-white group-hover/item:border-[#DDE3EC] transition-all duration-150">
                                            <Icon className="h-3.5 w-3.5 text-[#475569] group-data-[highlighted]/item:text-[#0F172A] group-hover/item:text-[#0F172A] transition-colors" strokeWidth={1.75} />
                                        </div>
                                        <span className="text-[13px] font-medium tracking-[-0.01em]">{item.label}</span>
                                    </Link>
                                </DropdownMenuItem>
                            );
                        })}
                    </React.Fragment>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
