import React, { useEffect, useState, useRef, useCallback } from 'react';
import {
    Search, Building, Briefcase, FileText,
    Stethoscope, GitBranch, UserCog, Receipt,
    Megaphone, LayoutDashboard, Wallet,
    BarChart2, HeadphonesIcon, Plus, Clock, ArrowRight, Loader2
} from 'lucide-react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Badge, type BadgeVariant } from '@/ovs/primitives/badge';
import { IconChip, type IconChipTone } from '@/ovs/primitives/icon-chip';

// ─── Types ────────────────────────────────────────────────────────────────────

type ResultItem = {
    label: string;
    description?: string;
    icon: React.ElementType;
    href: string;
    badge?: string;
    tone?: IconChipTone;
};

type ResultGroup = {
    key: string;
    label: string;
    icon?: React.ElementType;
    items: ResultItem[];
};

// ─── Data ─────────────────────────────────────────────────────────────────────

/*
 * There are no recent searches.
 *
 * This list was four invented records — a named clinic, an invoice number, a
 * lead and an employee — presented to every user as their own history. Nothing
 * tracked recency, so they never changed and never belonged to the person
 * reading them. An empty list is the truth until something records one.
 */
const recentSearches: ResultItem[] = [];

const allGroups: ResultGroup[] = [
    {
        key: 'navigation',
        label: 'Navigation',
        items: [
            { label: 'Dashboard',    icon: LayoutDashboard, href: '/company/dashboard',        description: 'Executive overview', tone: 'indigo' },
            { label: 'CRM',          icon: Briefcase,       href: '/company/crm/deals',        description: 'Leads & pipeline',    tone: 'indigo' },
            { label: 'Finance',      icon: Wallet,          href: '/company/finance/invoices', description: 'Revenue & invoices',  tone: 'amber' },
            { label: 'Employees',    icon: UserCog,         href: '/company/people/employees', description: 'People & workforce',  tone: 'slate' },
        ],
    },
    {
        key: 'records',
        label: 'Records',
        items: [
            { label: 'Leads',         icon: Briefcase,   href: '/company/crm/deals',         description: 'CRM pipeline',         tone: 'indigo', badge: 'CRM' },
            { label: 'Accounts',      icon: Building,    href: '/company/customer-success/accounts', description: 'Customer accounts', tone: 'indigo', badge: 'CRM' },
            { label: 'Clinics',       icon: Stethoscope, href: '/company/customer-success/accounts', description: 'Managed on their account', tone: 'teal',   badge: 'Clinic' },
            { label: 'Employees',     icon: UserCog,     href: '/company/people/employees',  description: 'People & roles',       tone: 'slate',  badge: 'Ops' },
            { label: 'Invoices',      icon: Receipt,     href: '/company/finance/invoices',  description: 'Billing & payments',   tone: 'amber',  badge: 'Finance' },
        ],
    },
    {
        key: 'actions',
        label: 'Quick Actions',
        items: [
            { label: 'Create Lead',         icon: Plus, href: '/company/crm/leads/create',         badge: 'CRM',     tone: 'indigo' },
        ],
    },
];

const BADGE_MAP: Record<string, BadgeVariant> = {
    CRM:      'info',
    Clinic:   'success',
    Network:  'success',
    Finance:  'warning',
    Ops:      'neutral',
    Marketing:'brand',
};

function getModuleTone(moduleKey: string): IconChipTone {
    switch (moduleKey.toLowerCase()) {
        case 'leads':
        case 'crm':
        case 'organizations':
        case 'company':
            return 'indigo';
        case 'clinics':
        case 'branches':
            return 'teal';
        case 'invoices':
        case 'payments':
        case 'finance':
            return 'amber';
        case 'employees':
        case 'users':
            return 'slate';
        case 'documents':
            return 'blue';
        case 'campaigns':
        case 'marketing':
            return 'purple';
        default:
            return 'indigo';
    }
}

function getModuleIcon(moduleKey: string): React.ElementType {
    switch (moduleKey.toLowerCase()) {
        case 'leads':
        case 'crm':
            return Briefcase;
        case 'organizations':
        case 'company':
            return Building;
        case 'clinics':
            return Stethoscope;
        case 'branches':
            return GitBranch;
        case 'employees':
        case 'users':
            return UserCog;
        case 'invoices':
        case 'payments':
        case 'finance':
            return Receipt;
        case 'documents':
            return FileText;
        case 'campaigns':
        case 'marketing':
            return Megaphone;
        default:
            return Search;
    }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function filterGroups(query: string): ResultGroup[] {
    const q = query.toLowerCase().trim();
    if (!q) return allGroups;
    return allGroups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    item.label.toLowerCase().includes(q) ||
                    item.description?.toLowerCase().includes(q) ||
                    item.badge?.toLowerCase().includes(q)
            ),
        }))
        .filter((g) => g.items.length > 0);
}

function flattenVisible(groups: ResultGroup[], hasQuery: boolean, navCount: number, actionCount: number): ResultItem[] {
    if (!hasQuery) {
        return [
            ...recentSearches,
            ...allGroups[0].items.slice(0, navCount),
            ...allGroups[2].items.slice(0, actionCount),
        ];
    }
    return groups.flatMap((g) => g.items);
}

function highlightMatch(text: string, query: string): React.ReactNode {
    if (!query.trim()) return text;
    const idx = text.toLowerCase().indexOf(query.toLowerCase());
    if (idx === -1) return text;
    return (
        <>
            {text.slice(0, idx)}
            <mark className="bg-ovs-semantic-warning-bg text-ovs-semantic-warning-text rounded px-0.5 font-semibold not-italic">
                {text.slice(idx, idx + query.length)}
            </mark>
            {text.slice(idx + query.length)}
        </>
    );
}

const NAV_COUNT    = 4;
const ACTION_COUNT = 4;

export interface CommandPaletteProps {
    isOpen?: boolean;
    onClose?: () => void;
    /** Custom placeholder for the search input. */
    placeholder?: string;
    /**
     * 'default' — standalone widget with its own pill background.
     * 'inline'  — transparent, borderless; blends into a parent surface (e.g. the floating header bar).
     */
    variant?: 'default' | 'inline';
    className?: string;
}

export function CommandPalette({ isOpen, onClose, placeholder, variant = 'default', className }: CommandPaletteProps = {}) {
    const resolvedPlaceholder = placeholder ?? 'Search anything...';
    const [internalOpen, setInternalOpen] = useState(false);
    const open = isOpen !== undefined ? isOpen : internalOpen;
    const setOpen = (val: boolean) => {
        setInternalOpen(val);
        if (!val && onClose) onClose();
    };
    const [query, setQuery]         = useState('');
    const [activeIdx, setActiveIdx] = useState(0);

    const containerRef = useRef<HTMLDivElement>(null);
    const inputRef     = useRef<HTMLInputElement>(null);

    const [remoteGroups, setRemoteGroups] = useState<ResultGroup[]>([]);
    const [searching, setSearching]       = useState(false);

    const hasQuery      = query.trim().length > 0;
    const localFiltered = filterGroups(query);

    // Merge local navigation & actions with remote API search results
    const filtered: ResultGroup[] = hasQuery
        ? [
            ...localFiltered,
            ...remoteGroups.filter(rg => !localFiltered.some(lg => lg.key === rg.key))
          ]
        : localFiltered;

    const flatItems = flattenVisible(filtered, hasQuery, NAV_COUNT, ACTION_COUNT);
    const isEmpty   = hasQuery && !searching && filtered.length === 0;

    // Fetch live backend search results from /api/search
    useEffect(() => {
        if (!hasQuery) {
            setRemoteGroups([]);
            setSearching(false);
            return;
        }

        const timer = setTimeout(async () => {
            setSearching(true);
            try {
                const res = await fetch(`/api/search?q=${encodeURIComponent(query.trim())}`, {
                    headers: { 'Accept': 'application/json' }
                });
                if (res.ok) {
                    const json = await res.json();
                    if (json.data && typeof json.data === 'object') {
                        const fetchedGroups: ResultGroup[] = Object.entries(json.data).map(([moduleKey, items]: [string, any]) => ({
                            key: moduleKey,
                            label: moduleKey.charAt(0).toUpperCase() + moduleKey.slice(1),
                            items: Array.isArray(items) ? items.map((it: any) => ({
                                label: it.title || it.name || it.label || 'Search Record',
                                description: it.subtitle || it.description || moduleKey,
                                icon: getModuleIcon(moduleKey),
                                tone: getModuleTone(moduleKey),
                                href: it.url || it.href || `/company/${moduleKey}`,
                                badge: it.badge || moduleKey.toUpperCase()
                            })) : []
                        })).filter(g => g.items.length > 0);
                        setRemoteGroups(fetchedGroups);
                    }
                }
            } catch (err) {
                console.error('Error fetching global search results:', err);
            } finally {
                setSearching(false);
            }
        }, 200);

        return () => clearTimeout(timer);
    }, [query, hasQuery]);

    const navigate = useCallback((href: string) => {
        router.visit(href, {
            onFinish: () => {
                setQuery('');
                setActiveIdx(0);
            },
        });
        setOpen(false);
    }, []);

    const openDropdown = () => { setOpen(true); setActiveIdx(0); };

    // Click outside → close
    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    // Global ⌘K shortcut
    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            if (e.key === 'k' && (e.metaKey || e.ctrlKey)) {
                e.preventDefault();
                inputRef.current?.focus();
                openDropdown();
            }
        };
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, []);

    // Keyboard navigation inside dropdown
    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (!open) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActiveIdx((i) => Math.min(i + 1, flatItems.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiveIdx((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (flatItems[activeIdx]) navigate(flatItems[activeIdx].href);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            setOpen(false);
            inputRef.current?.blur();
        }
    };

    useEffect(() => setActiveIdx(0), [query, open]);

    return (
        <div ref={containerRef} className="relative w-full">

            {/* ── Search Input ─────────────────────────────────────────────── */}
            <div
                className={cn(
                    'flex items-center gap-2.5 transition-all duration-150 group',
                    variant === 'inline'
                        // inline: refined modern SaaS search control (48px height, visible surface, glowing active state)
                        ? 'h-[48px] px-3.5 rounded-xl bg-ovs-background hover:bg-ovs-border-subtle focus-within:bg-white border border-ovs-border focus-within:border-ovs-accent/50 focus-within:ring-2 focus-within:ring-ovs-accent/20 shadow-ovs-flat focus-within:shadow-ovs-surface'
                        // default: pill with own background
                        : 'h-[44px] rounded-xl pl-4 pr-3 bg-ovs-background border border-ovs-border hover:bg-ovs-border-subtle focus-within:bg-white focus-within:border-ovs-accent/50 focus-within:ring-2 focus-within:ring-ovs-accent/20 transition-all duration-150',
                    className
                )}
            >
                {/* Single standard Lucide Search icon */}
                <Search
                    className={cn(
                        'shrink-0 transition-colors duration-150',
                        'h-[17px] w-[17px] text-ovs-text-tertiary group-focus-within:text-ovs-accent'
                    )}
                    strokeWidth={1.75}
                />

                {/* Input */}
                <input
                    ref={inputRef}
                    type="text"
                    value={query}
                    onChange={(e) => { setQuery(e.target.value); openDropdown(); }}
                    onFocus={openDropdown}
                    onKeyDown={handleKeyDown}
                    placeholder={resolvedPlaceholder}
                    className={cn(
                        'flex-1 bg-transparent border-0 border-transparent outline-none ring-0 focus:ring-0 focus:border-transparent focus:outline-none shadow-none focus:shadow-none appearance-none min-w-0 leading-none p-0',
                        'text-[13px] text-ovs-text-primary placeholder:text-ovs-text-tertiary font-medium'
                    )}
                    autoComplete="off"
                    spellCheck={false}
                    aria-label="Global search"
                    aria-expanded={open}
                />

                {/* Loading indicator or Clear button */}
                {searching ? (
                    <Loader2 className="h-4 w-4 text-ovs-accent animate-spin shrink-0" />
                ) : query ? (
                    <button
                        onClick={() => { setQuery(''); inputRef.current?.focus(); }}
                        className="shrink-0 h-[18px] w-[18px] rounded-full bg-ovs-border hover:bg-ovs-border-strong flex items-center justify-center transition-colors"
                        tabIndex={-1}
                        aria-label="Clear"
                    >
                        <span className="text-ovs-text-secondary text-[11px] leading-none font-bold select-none">×</span>
                    </button>
                ) : null}
            </div>

            {/* ── Dropdown ───────────────────────────────────────────────────── */}
            {open && (
                <div
                    className={cn(
                        'absolute top-[calc(100%+8px)] left-0 z-50',
                        'w-full min-w-[460px]',
                        // Fully opaque — this panel floats over busy, colorful page content,
                        // so it must never rely on translucency/blur for legibility.
                        'bg-white rounded-2xl',
                        'border border-ovs-border',
                        'ring-1 ring-ovs-primary/[0.05]',
                        'shadow-ovs-floating',
                        'overflow-hidden',
                        'animate-in fade-in-0 slide-in-from-top-1 duration-150 ease-out'
                    )}
                >
                    {/* Scrollable results — invisible scrollbar */}
                    <div className="search-scroll max-h-[480px] p-2">

                        {/* ── Empty State ──────────────────────────────────────── */}
                        {isEmpty && (
                            <div className="flex flex-col items-center justify-center py-14 px-6 text-center">
                                <div className="w-11 h-11 rounded-2xl bg-ovs-accent-light border border-ovs-accent/20 flex items-center justify-center mb-3.5 shadow-ovs-keyline">
                                    <Search className="h-5 w-5 text-ovs-accent" strokeWidth={1.75} />
                                </div>
                                <p className="text-[13.5px] font-bold text-ovs-text-primary font-display">
                                    No results for "{query}"
                                </p>
                                <p className="text-[12px] text-ovs-text-secondary mt-1.5 max-w-[280px] leading-relaxed">
                                    Try searching for a clinic, lead, invoice, employee, or document.
                                </p>
                            </div>
                        )}

                        {/* ── No Query: Recent + Navigation + Quick Actions ───── */}
                        {!hasQuery && (
                            <>
                                {/* A "Recent" heading with nothing under it is not a section. */}
                                {recentSearches.length > 0 && (
                                    <>
                                        <Section label="Recent" icon={Clock}>
                                            {recentSearches.map((item, idx) => (
                                                <Row
                                                    key={idx}
                                                    item={item}
                                                    isActive={activeIdx === idx}
                                                    onHover={() => setActiveIdx(idx)}
                                                    onClick={() => navigate(item.href)}
                                                />
                                            ))}
                                        </Section>

                                        <Divider />
                                    </>
                                )}

                                <Section label="Navigation">
                                    {allGroups[0].items.slice(0, NAV_COUNT).map((item, i) => {
                                        const idx = recentSearches.length + i;
                                        return (
                                            <Row
                                                key={idx}
                                                item={item}
                                                isActive={activeIdx === idx}
                                                onHover={() => setActiveIdx(idx)}
                                                onClick={() => navigate(item.href)}
                                            />
                                        );
                                    })}
                                </Section>

                                <Divider />

                                <Section label="Quick Actions">
                                    {allGroups[2].items.slice(0, ACTION_COUNT).map((item, i) => {
                                        const idx = recentSearches.length + NAV_COUNT + i;
                                        return (
                                            <Row
                                                key={idx}
                                                item={item}
                                                isActive={activeIdx === idx}
                                                onHover={() => setActiveIdx(idx)}
                                                onClick={() => navigate(item.href)}
                                            />
                                        );
                                    })}
                                </Section>
                            </>
                        )}

                        {/* ── With Query: Filtered Groups ───────────────────────── */}
                        {hasQuery && !isEmpty && (() => {
                            let globalIdx = 0;
                            return filtered.map((group, gIdx) => (
                                <React.Fragment key={group.key}>
                                    {gIdx > 0 && <Divider />}
                                    <Section label={group.label}>
                                        {group.items.map((item) => {
                                            const idx = globalIdx++;
                                            return (
                                                <Row
                                                    key={item.href + idx}
                                                    item={item}
                                                    isActive={activeIdx === idx}
                                                    onHover={() => setActiveIdx(idx)}
                                                    onClick={() => navigate(item.href)}
                                                    highlightQuery={query}
                                                />
                                            );
                                        })}
                                    </Section>
                                </React.Fragment>
                            ));
                        })()}
                    </div>

                    {/* ── Footer ───────────────────────────────────────────────── */}
                    <div className="border-t border-ovs-border-subtle bg-ovs-background px-5 py-2.5 flex items-center gap-5">
                        <FooterHint kbd="↵">Select</FooterHint>
                        <FooterHint kbd="↑↓">Navigate</FooterHint>
                        <FooterHint kbd="Esc">Close</FooterHint>
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Sub-components ────────────────────────────────────────────────────────────

function Divider() {
    return <div className="h-px bg-ovs-border-subtle mx-2 my-1" />;
}

function Section({
    label,
    icon: Icon,
    children,
}: {
    label: string;
    icon?: React.ElementType;
    children: React.ReactNode;
}) {
    return (
        <div className="py-1">
            <div className="flex items-center gap-1.5 px-3 pt-1.5 pb-1.5">
                {Icon && <Icon className="h-3 w-3 text-ovs-text-tertiary" strokeWidth={2} />}
                <span className="text-[10px] font-extrabold uppercase tracking-[0.15em] text-ovs-text-tertiary font-display">
                    {label}
                </span>
            </div>
            <div className="space-y-0.5">{children}</div>
        </div>
    );
}

type RowProps = {
    item: ResultItem;
    isActive: boolean;
    onHover: () => void;
    onClick: () => void;
    highlightQuery?: string;
};

function Row({ item, isActive, onHover, onClick, highlightQuery }: RowProps) {
    const Icon = item.icon;
    const badgeVariant = item.badge ? (BADGE_MAP[item.badge] ?? 'neutral') : null;

    return (
        <button
            onMouseEnter={onHover}
            onClick={onClick}
            className={cn(
                'w-full flex items-center gap-3 px-3 py-2 text-left rounded-xl group select-none',
                'transition-all duration-100 ease-in-out',
                isActive ? 'bg-ovs-accent-light/60 text-ovs-text-primary' : 'hover:bg-ovs-background text-ovs-text-secondary'
            )}
        >
            {/* Gradient Icon Chip */}
            <IconChip
                tone={item.tone || 'indigo'}
                size="sm"
                icon={<Icon className="w-3.5 h-3.5" strokeWidth={2} />}
            />

            {/* Text */}
            <div className="flex-1 min-w-0">
                <p className="text-[13px] font-semibold text-ovs-text-primary truncate leading-snug font-sans">
                    {highlightQuery ? highlightMatch(item.label, highlightQuery) : item.label}
                </p>
                {item.description && (
                    <p className="text-[11.5px] text-ovs-text-secondary truncate leading-tight mt-0.5 font-normal font-sans">
                        {item.description}
                    </p>
                )}
            </div>

            {/* OVS Badge */}
            {item.badge && badgeVariant && (
                <Badge variant={badgeVariant} size="compact" className="text-[10px] py-0 px-1.5">
                    {item.badge}
                </Badge>
            )}

            {/* Active arrow */}
            <ArrowRight
                className={cn(
                    'h-3.5 w-3.5 text-ovs-text-tertiary shrink-0 transition-opacity duration-100',
                    isActive ? 'opacity-100 text-ovs-accent' : 'opacity-0 group-hover:opacity-100'
                )}
                strokeWidth={2}
            />
        </button>
    );
}

function FooterHint({ kbd, children }: { kbd: string; children: React.ReactNode }) {
    return (
        <span className="flex items-center gap-1.5 text-[11px] text-ovs-text-secondary font-sans">
            <kbd className="inline-flex items-center justify-center min-w-[20px] h-[18px] px-1.5 rounded bg-white border border-ovs-border shadow-2xs font-mono text-[9.5px] font-bold text-ovs-text-secondary">
                {kbd}
            </kbd>
            {children}
        </span>
    );
}
