import React, { useState, useEffect } from 'react';

import {
    Bell, CheckCircle2, AlertCircle, Info, MoreHorizontal,
    CheckSquare, ShieldCheck, AtSign, Zap, AlertTriangle
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';

type NotificationItem = {
    id: string;
    title: string;
    description: string;
    time: string;
    type: 'success' | 'warning' | 'info' | 'task' | 'approval' | 'mention' | 'system' | 'alert';
    isRead: boolean;
};

const notificationsData = {
    today: [
        { id: '1', title: 'New lead assigned', description: 'Rahul Sharma has been assigned to you.', time: '10:30 AM', type: 'info', isRead: false },
        { id: '2', title: 'Payment received', description: 'Invoice #INV-2026 for City Dental is paid.', time: '09:15 AM', type: 'success', isRead: false },
        { id: '5', title: 'Pending Approval', description: 'Discount request for Smile Care clinic.', time: '08:00 AM', type: 'approval', isRead: false },
    ] as NotificationItem[],
    yesterday: [
        { id: '3', title: 'Server maintenance', description: 'Scheduled maintenance completed successfully.', time: '11:00 PM', type: 'system', isRead: true },
        { id: '6', title: 'You were mentioned', description: 'Arjun mentioned you in a comment.', time: '04:00 PM', type: 'mention', isRead: true },
    ] as NotificationItem[],
    earlier: [
        { id: '4', title: 'Weekly report ready', description: 'Your performance report for last week is ready.', time: 'Mon, 9:00 AM', type: 'info', isRead: true },
        { id: '7', title: 'Task due soon', description: 'Follow up call with Dr. Rajesh.', time: 'Mon, 8:00 AM', type: 'task', isRead: true },
        { id: '8', title: 'High CPU Usage', description: 'Database cluster CPU exceeded 90%.', time: 'Sun, 2:00 PM', type: 'alert', isRead: true },
    ] as NotificationItem[],
};

const typeIcon: Record<NotificationItem['type'], React.ReactNode> = {
    success: <CheckCircle2 className="h-4 w-4 text-[#16A34A]" />,
    warning: <AlertCircle className="h-4 w-4 text-[#D97706]" />,
    info: <Info className="h-4 w-4 text-[#2563EB]" />,
    task: <CheckSquare className="h-4 w-4 text-[#7C3AED]" />,
    approval: <ShieldCheck className="h-4 w-4 text-[#D97706]" />,
    mention: <AtSign className="h-4 w-4 text-[#DB2777]" />,
    system: <Zap className="h-4 w-4 text-[#64748B]" />,
    alert: <AlertTriangle className="h-4 w-4 text-[#DC2626]" />,
};

const typeIconBg: Record<NotificationItem['type'], string> = {
    success: 'bg-green-50',
    warning: 'bg-amber-50',
    info: 'bg-blue-50',
    task: 'bg-violet-50',
    approval: 'bg-amber-50',
    mention: 'bg-pink-50',
    system: 'bg-slate-50',
    alert: 'bg-red-50',
};

export function NotificationPanel() {
    const [activeTab, setActiveTab] = useState('all');
    const [notifications, setNotifications] = useState<{
        today: NotificationItem[];
        yesterday: NotificationItem[];
        earlier: NotificationItem[];
    }>({
        today: notificationsData.today,
        yesterday: notificationsData.yesterday,
        earlier: notificationsData.earlier
    });
    const [unreadCount, setUnreadCount] = useState<number>(3);

    // Fetch live notifications and unread count from backend APIs
    const fetchNotifications = async () => {
        try {
            const countRes = await fetch('/api/notifications/unread-count', {
                headers: { 'Accept': 'application/json' }
            });
            if (countRes.ok) {
                const countJson = await countRes.json();
                if (typeof countJson.count === 'number') {
                    setUnreadCount(countJson.count);
                }
            }

            const listRes = await fetch('/api/notifications?per_page=20', {
                headers: { 'Accept': 'application/json' }
            });
            if (listRes.ok) {
                const listJson = await listRes.json();
                const rawItems: any[] = Array.isArray(listJson) ? listJson : (listJson.data || []);
                if (rawItems.length > 0) {
                    const formatted: NotificationItem[] = rawItems.map((n: any) => ({
                        id: String(n.id),
                        title: n.title || n.data?.title || 'Notification',
                        description: n.description || n.data?.message || n.data?.description || '',
                        time: n.created_at ? new Date(n.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'Recently',
                        type: (n.category || n.type || 'info').toLowerCase() as NotificationItem['type'],
                        isRead: Boolean(n.read_at || n.is_read)
                    }));

                    setNotifications({
                        today: formatted.slice(0, 5),
                        yesterday: formatted.slice(5, 10),
                        earlier: formatted.slice(10)
                    });
                }
            }
        } catch (err) {
            console.error('Error fetching notifications:', err);
        }
    };

    useEffect(() => {
        fetchNotifications();
    }, []);

    const handleMarkAllRead = async () => {
        try {
            await fetch('/api/notifications/mark-all-read', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                }
            });
            setUnreadCount(0);
            setNotifications(prev => ({
                today: prev.today.map(i => ({ ...i, isRead: true })),
                yesterday: prev.yesterday.map(i => ({ ...i, isRead: true })),
                earlier: prev.earlier.map(i => ({ ...i, isRead: true }))
            }));
        } catch (err) {
            console.error('Error marking all as read:', err);
        }
    };

    const handleMarkAsRead = async (id: string) => {
        try {
            await fetch(`/api/notifications/${id}/read`, {
                method: 'PATCH',
                headers: { 'Accept': 'application/json' }
            });
            setUnreadCount(prev => Math.max(0, prev - 1));
            setNotifications(prev => ({
                today: prev.today.map(i => i.id === id ? { ...i, isRead: true } : i),
                yesterday: prev.yesterday.map(i => i.id === id ? { ...i, isRead: true } : i),
                earlier: prev.earlier.map(i => i.id === id ? { ...i, isRead: true } : i)
            }));
        } catch (err) {
            console.error('Error marking notification as read:', err);
        }
    };

    const filterItems = (items: NotificationItem[]) => {
        if (activeTab === 'all') return items;
        if (activeTab === 'tasks') return items.filter(i => i.type === 'task');
        if (activeTab === 'approvals') return items.filter(i => i.type === 'approval');
        if (activeTab === 'mentions') return items.filter(i => i.type === 'mention');
        if (activeTab === 'system') return items.filter(i => i.type === 'system');
        if (activeTab === 'alerts') return items.filter(i => i.type === 'alert' || i.type === 'warning');
        return items;
    };

    const todayFiltered = filterItems(notifications.today);
    const yesterdayFiltered = filterItems(notifications.yesterday);
    const earlierFiltered = filterItems(notifications.earlier);
    const hasNotifications = todayFiltered.length > 0 || yesterdayFiltered.length > 0 || earlierFiltered.length > 0;

    const NotificationGroup = ({ label, items }: { label: string; items: NotificationItem[] }) => (
        <div className="mb-1">
            <div className="text-[10.5px] font-[750] text-[#94A3B8] uppercase tracking-[0.12em] mb-1.5 px-4 pt-3">{label}</div>
            {items.map((item) => (
                <div
                    key={item.id}
                    onClick={() => !item.isRead && handleMarkAsRead(item.id)}
                    className={cn(
                        'flex gap-3.5 px-4 py-3 hover:bg-[#F8FAFC] transition-colors duration-150 relative cursor-pointer group',
                        !item.isRead && 'bg-[#F8FBFF]'
                    )}
                >
                    {/* Unread pill */}
                    {!item.isRead && (
                        <div className="absolute left-0 top-3 bottom-3 w-[3px] bg-[#3B82F6] rounded-r-full" />
                    )}

                    {/* Icon */}
                    <div className={cn('w-8 h-8 rounded-xl flex items-center justify-center shrink-0 mt-0.5', typeIconBg[item.type] || 'bg-slate-50')}>
                        {typeIcon[item.type] || <Info className="h-4 w-4 text-[#2563EB]" />}
                    </div>

                    {/* Content */}
                    <div className="flex-1 min-w-0">
                        <div className="flex justify-between items-start gap-2">
                            <p className={cn('text-[13px] leading-snug truncate', item.isRead ? 'font-medium text-[#374151]' : 'font-semibold text-[#0F172A]')}>
                                {item.title}
                            </p>
                            <span className="text-[11px] text-[#94A3B8] whitespace-nowrap shrink-0">{item.time}</span>
                        </div>
                        <p className="text-[12px] text-[#64748B] mt-0.5 line-clamp-2 leading-relaxed">{item.description}</p>
                    </div>

                    {/* Hover action */}
                    <div className="shrink-0 opacity-0 group-hover:opacity-100 transition-opacity duration-150 self-start mt-0.5">
                        <Button variant="ghost" size="icon" className="h-6 w-6 text-[#94A3B8] hover:text-[#0F172A] rounded-lg">
                            <MoreHorizontal className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                </div>
            ))}
        </div>
    );

    return (
        <DropdownMenu onOpenChange={(open) => open && fetchNotifications()}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    id="notifications-trigger"
                    className="relative text-[#6B7A8F] hover:text-[#0F172A] hover:bg-[#F4F6F9] rounded-xl h-[42px] w-[42px] transition-all duration-200"
                >
                    <Bell className="h-[18px] w-[18px]" strokeWidth={1.75} />
                    {unreadCount > 0 && (
                        <span className="absolute top-2.5 right-2.5 h-[7px] w-[7px] rounded-full bg-[#EF4444] border-[1.5px] border-white shadow-sm" />
                    )}
                </Button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align="end"
                sideOffset={10}
                className="w-[380px] p-0 rounded-2xl shadow-[0_20px_60px_rgba(0,0,0,0.12),0_4px_16px_rgba(0,0,0,0.06)] border border-[#E8EDF3] overflow-hidden bg-white"
            >
                {/* Header */}
                <div className="flex items-center justify-between px-5 pt-4 pb-3">
                    <div className="flex items-center gap-2.5">
                        <h3 className="font-semibold text-[15px] text-[#0F172A] tracking-tight">Notifications</h3>
                        {unreadCount > 0 && (
                            <span className="inline-flex items-center justify-center h-5 min-w-[20px] px-1.5 rounded-full bg-[#EF4444] text-white text-[10px] font-bold">
                                {unreadCount}
                            </span>
                        )}
                    </div>
                    <button onClick={handleMarkAllRead} className="text-[12px] text-[#64748B] hover:text-[#0F172A] font-medium transition-colors duration-150 cursor-pointer">
                        Mark all read
                    </button>
                </div>


                {/* Filter Tabs */}
                <div className="px-4 pb-2 border-b border-[#F4F6F9]">
                    <Tabs value={activeTab} onValueChange={setActiveTab}>
                        <TabsList className="h-auto w-full justify-start bg-transparent p-0 gap-1 flex flex-wrap">
                            {['all', 'tasks', 'approvals', 'mentions', 'system', 'alerts'].map((tab) => (
                                <TabsTrigger
                                    key={tab}
                                    value={tab}
                                    className="rounded-lg px-3 h-7 capitalize text-[11.5px] font-medium data-[state=active]:bg-[#0F172A] data-[state=active]:text-white data-[state=inactive]:bg-transparent data-[state=inactive]:text-[#64748B] hover:text-[#0F172A] hover:bg-[#F4F6F9] transition-all duration-150 shadow-none"
                                >
                                    {tab}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </Tabs>
                </div>

                {/* Notification List */}
                <div className="max-h-[380px] overflow-y-auto sidebar-scroll">
                    {hasNotifications ? (
                        <>
                            {todayFiltered.length > 0 && <NotificationGroup label="Today" items={todayFiltered} />}
                            {yesterdayFiltered.length > 0 && <NotificationGroup label="Yesterday" items={yesterdayFiltered} />}
                            {earlierFiltered.length > 0 && <NotificationGroup label="Earlier" items={earlierFiltered} />}
                        </>
                    ) : (
                        <div className="flex flex-col items-center justify-center py-14 px-4 text-center">
                            <div className="w-12 h-12 rounded-2xl bg-[#F4F6F9] flex items-center justify-center mb-3">
                                <Bell className="h-5 w-5 text-[#94A3B8]" strokeWidth={1.5} />
                            </div>
                            <p className="text-[13px] font-semibold text-[#374151]">You're all caught up</p>
                            <p className="text-[12px] text-[#94A3B8] mt-1">No new notifications right now</p>
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="border-t border-[#F4F6F9] bg-[#FAFBFC] px-4 py-3">
                    <button className="w-full text-center text-[13px] font-semibold text-[#0F172A] hover:text-[#3B82F6] transition-colors duration-150">
                        View all notifications →
                    </button>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
