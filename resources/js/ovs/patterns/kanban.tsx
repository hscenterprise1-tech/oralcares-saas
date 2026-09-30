import React, { useState } from 'react';
import {
  DndContext,
  DragOverlay,
  closestCorners,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  useDroppable,
  DragStartEvent,
  DragOverEvent,
  DragEndEvent,
  defaultDropAnimationSideEffects,
  DropAnimation,
} from '@dnd-kit/core';
import {
  SortableContext,
  verticalListSortingStrategy,
  useSortable,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
  Clock,
  Sparkles,
  AlertTriangle,
  CheckCircle2,
  GripVertical,
  Inbox,
  UserCheck,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { PriorityBadge, StageBadge } from '@/ovs/primitives/badge';
import { Skeleton } from '@/ovs/feedback/skeleton';

export interface OVSKanbanCard {
  id: string | number;
  title: string;
  subtitle?: string;
  metadata?: string;
  priority?: 'hot' | 'warm' | 'cold' | 'blocked' | string;
  stage?: string;
  stageLabel?: string;
  alertType?: 'stalled' | 'overdue' | 'healthy' | 'unassigned';
  alertLabel?: string;
  nextAction?: string;
  nextFollowup?: string;
  ownerName?: string;
  ownerInitials?: string;
  monetaryValue?: string; // e.g. ₹4.20L ARR
  secondaryMonetaryValue?: string; // e.g. ₹35,000 / mo
  data?: Record<string, any>;
}

export type KanbanBadgeVariant =
  | 'slate'
  | 'blue'
  | 'indigo'
  | 'teal'
  | 'amber'
  | 'emerald'
  | 'rose';

export interface OVSKanbanColumn {
  id: string;
  title: string;
  count?: number;
  aggregateValue?: string;
  badgeVariant?: KanbanBadgeVariant;
  cards: OVSKanbanCard[];
}

export interface OVSKanbanBoardProps {
  columns: OVSKanbanColumn[];
  onCardMove?: (
    cardId: string | number,
    sourceColId: string,
    targetColId: string,
    newIndex: number
  ) => void;
  onCardClick?: (card: OVSKanbanCard) => void;
  columnWidthClass?: string;
  heightClass?: string;
  renderCustomCard?: (
    card: OVSKanbanCard,
    isDragging: boolean,
    isOverlay: boolean
  ) => React.ReactNode;
  emptyText?: string;
  isLoading?: boolean;
  className?: string;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. DEFAULT KANBAN CARD VIEW
// ─────────────────────────────────────────────────────────────────────────────

interface KanbanCardViewProps {
  card: OVSKanbanCard;
  isDragging?: boolean;
  isOverlay?: boolean;
  onClick?: () => void;
  dragHandleProps?: Record<string, any>;
}

export const OVSKanbanCardView: React.FC<KanbanCardViewProps> = ({
  card,
  isDragging = false,
  isOverlay = false,
  onClick,
  dragHandleProps,
}) => {
  return (
    <div
      onClick={onClick}
      className={cn(
        'group relative bg-white border border-slate-200/90 rounded-xl p-3.5 shadow-2xs font-sans transition-all select-none space-y-2.5',
        'hover:border-slate-300 hover:shadow-xs cursor-pointer',
        isDragging && 'opacity-35 border-teal-500 ring-2 ring-teal-500/20 shadow-md',
        isOverlay &&
          'border-teal-600 ring-2 ring-teal-600/30 shadow-2xl scale-[1.02] rotate-1 cursor-grabbing bg-white z-50'
      )}
    >
      {/* Top Header: Status Cluster (Max 1 Priority, 1 Lifecycle, 1 Operational) + Drag Handle */}
      <div className="flex items-center justify-between gap-1.5">
        <div className="flex items-center gap-1.5 flex-wrap">
          {card.priority && (
            <PriorityBadge
              priority={card.priority as any}
              size="compact"
              className="text-[10px] py-0 px-1.5 leading-none font-bold"
            />
          )}
          {card.stage && (
            <StageBadge
              status={card.stage}
              label={card.stageLabel || card.stage}
              className="text-[10px] py-0 px-1.5 leading-none font-semibold whitespace-nowrap"
            />
          )}
          {card.alertLabel && (
            <span
              className={cn(
                'inline-flex items-center gap-0.5 text-[9px] font-bold px-1.5 py-0.5 rounded leading-none border',
                card.alertType === 'stalled' && 'bg-amber-50 text-amber-800 border-amber-200/80',
                card.alertType === 'overdue' && 'bg-rose-50 text-rose-800 border-rose-200/80',
                card.alertType === 'healthy' && 'bg-emerald-50 text-emerald-800 border-emerald-200/80',
                (!card.alertType || card.alertType === 'unassigned') &&
                  'bg-slate-100 text-slate-700 border-slate-200'
              )}
            >
              {card.alertType === 'stalled' && <Clock className="w-2.5 h-2.5" />}
              {card.alertType === 'overdue' && <AlertTriangle className="w-2.5 h-2.5" />}
              {card.alertType === 'healthy' && <CheckCircle2 className="w-2.5 h-2.5" />}
              <span>{card.alertLabel}</span>
            </span>
          )}
        </div>

        {/* Drag Handle icon */}
        <div
          {...dragHandleProps}
          className="text-slate-300 group-hover:text-slate-500 hover:bg-slate-100 p-0.5 rounded cursor-grab active:cursor-grabbing transition-colors"
          onClick={(e) => e.stopPropagation()}
          title="Drag card"
        >
          <GripVertical className="w-3.5 h-3.5" />
        </div>
      </div>

      {/* Primary & Secondary Identity */}
      <div>
        <h4 className="text-xs font-bold text-slate-900 tracking-tight leading-snug line-clamp-1 group-hover:text-teal-700 transition-colors">
          {card.title}
        </h4>
        {(card.subtitle || card.metadata) && (
          <p className="text-[11px] text-slate-500 font-normal truncate mt-0.5">
            {card.subtitle}
            {card.subtitle && card.metadata && ' · '}
            {card.metadata}
          </p>
        )}
      </div>

      {/* Next Action Dispatch (If available) */}
      {(card.nextAction || card.nextFollowup) && (
        <div className="pt-2 border-t border-slate-100/90 flex flex-col gap-0.5 text-[11px]">
          {card.nextAction && (
            <div className="flex items-center gap-1 text-slate-800 font-medium truncate">
              <Sparkles className="w-3 h-3 text-teal-600 shrink-0" />
              <span className="truncate">{card.nextAction}</span>
            </div>
          )}
          {card.nextFollowup && (
            <div className="flex items-center gap-1 text-rose-600 font-semibold text-[10px] pl-4">
              <Clock className="w-2.5 h-2.5 shrink-0" />
              <span>{card.nextFollowup}</span>
            </div>
          )}
        </div>
      )}

      {/* Tertiary Footer: Assigned Owner & Authoritative Financial Runrate */}
      {(card.ownerName || card.monetaryValue || card.secondaryMonetaryValue) && (
        <div className="pt-2 border-t border-slate-100 flex items-center justify-between gap-2 text-xs">
          {card.ownerName ? (
            <div className="flex items-center gap-1.5 min-w-0">
              <div className="w-5 h-5 rounded-full bg-slate-900 text-white text-[9px] font-bold flex items-center justify-center shrink-0">
                {card.ownerInitials || card.ownerName.slice(0, 2).toUpperCase()}
              </div>
              <span className="text-[11px] text-slate-600 font-medium truncate">
                {card.ownerName}
              </span>
            </div>
          ) : (
            <span className="text-[10px] text-slate-400 italic">Unassigned</span>
          )}

          {(card.monetaryValue || card.secondaryMonetaryValue) && (
            <div className="text-right shrink-0">
              {card.monetaryValue && (
                <div className="text-xs font-bold text-slate-900 tabular-nums">
                  {card.monetaryValue}
                </div>
              )}
              {card.secondaryMonetaryValue && (
                <div className="text-[10px] text-slate-500 tabular-nums font-normal">
                  {card.secondaryMonetaryValue}
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </div>
  );
};

// ─────────────────────────────────────────────────────────────────────────────
// 2. SORTABLE CARD WRAPPER
// ─────────────────────────────────────────────────────────────────────────────

function SortableCard({
  card,
  onClick,
  renderCustomCard,
}: {
  card: OVSKanbanCard;
  onClick?: (card: OVSKanbanCard) => void;
  renderCustomCard?: (
    card: OVSKanbanCard,
    isDragging: boolean,
    isOverlay: boolean
  ) => React.ReactNode;
}) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({
    id: card.id,
    data: {
      type: 'KanbanCard',
      card,
    },
  });

  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
  };

  if (renderCustomCard) {
    return (
      <div ref={setNodeRef} style={style}>
        {renderCustomCard(card, isDragging, false)}
      </div>
    );
  }

  return (
    <div ref={setNodeRef} style={style}>
      <OVSKanbanCardView
        card={card}
        isDragging={isDragging}
        onClick={() => onClick && onClick(card)}
        dragHandleProps={{ ...attributes, ...listeners }}
      />
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. KANBAN COLUMN COMPONENT
// ─────────────────────────────────────────────────────────────────────────────

function KanbanColumnComponent({
  column,
  onCardClick,
  renderCustomCard,
  columnWidthClass,
  emptyText,
}: {
  column: OVSKanbanColumn;
  onCardClick?: (card: OVSKanbanCard) => void;
  renderCustomCard?: (
    card: OVSKanbanCard,
    isDragging: boolean,
    isOverlay: boolean
  ) => React.ReactNode;
  columnWidthClass: string;
  emptyText: string;
}) {
  const { setNodeRef, isOver } = useDroppable({
    id: column.id,
    data: {
      type: 'Column',
      column,
    },
  });

  const cardIds = column.cards.map((c) => c.id);

  const getStageHeaderBadgeStyle = (variant?: KanbanBadgeVariant) => {
    switch (variant) {
      case 'blue':
        return 'bg-blue-50 text-blue-800 border-blue-200';
      case 'indigo':
        return 'bg-indigo-50 text-indigo-800 border-indigo-200';
      case 'teal':
        return 'bg-teal-50 text-teal-800 border-teal-200';
      case 'amber':
        return 'bg-amber-50 text-amber-800 border-amber-200';
      case 'emerald':
        return 'bg-emerald-50 text-emerald-800 border-emerald-200';
      case 'rose':
        return 'bg-rose-50 text-rose-800 border-rose-200';
      case 'slate':
      default:
        return 'bg-slate-100 text-slate-800 border-slate-200';
    }
  };

  return (
    <div
      ref={setNodeRef}
      className={cn(
        'h-full flex-shrink-0 flex flex-col bg-slate-50/80 border border-slate-200/80 rounded-2xl p-2.5 transition-colors select-none font-sans',
        columnWidthClass,
        isOver && 'bg-teal-50/40 border-teal-300 ring-2 ring-teal-500/20'
      )}
    >
      {/* Fixed Stage Column Header */}
      <div className="flex-shrink-0 px-1.5 py-1 flex items-center justify-between border-b border-slate-200/60 pb-2 mb-2.5">
        <div className="flex items-center gap-1.5 min-w-0">
          <span
            className={cn(
              'text-[10px] font-extrabold uppercase tracking-wider px-2 py-0.5 rounded-md border truncate',
              getStageHeaderBadgeStyle(column.badgeVariant)
            )}
          >
            {column.title}
          </span>
          <span className="text-xs font-mono font-bold text-slate-500 shrink-0">
            ({column.count ?? column.cards.length})
          </span>
        </div>

        {/* Column Subtotal Aggregate Value */}
        {column.aggregateValue && (
          <div className="text-[11px] font-bold text-slate-800 tabular-nums shrink-0 ml-1">
            {column.aggregateValue}
          </div>
        )}
      </div>

      {/* Vertical Scrolling Card Area */}
      <div className="flex-1 overflow-y-auto space-y-2.5 pr-0.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        <SortableContext items={cardIds} strategy={verticalListSortingStrategy}>
          {column.cards.map((card) => (
            <SortableCard
              key={card.id}
              card={card}
              onClick={onCardClick}
              renderCustomCard={renderCustomCard}
            />
          ))}
        </SortableContext>

        {/* Empty Column State */}
        {column.cards.length === 0 && (
          <div className="h-32 border-2 border-dashed border-slate-200 rounded-xl flex flex-col items-center justify-center p-3 text-center text-slate-400 space-y-1">
            <Inbox className="w-5 h-5 text-slate-300" />
            <span className="text-xs font-medium text-slate-500">{emptyText}</span>
            <span className="text-[10px] text-slate-400">Drag records here</span>
          </div>
        )}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. CANONICAL OVS KANBAN BOARD
// ─────────────────────────────────────────────────────────────────────────────

const dropAnimationConfig: DropAnimation = {
  sideEffects: defaultDropAnimationSideEffects({
    styles: {
      active: {
        opacity: '0.4',
      },
    },
  }),
};

export const OVSKanbanBoard: React.FC<OVSKanbanBoardProps> = ({
  columns,
  onCardMove,
  onCardClick,
  columnWidthClass = 'w-[285px] min-w-[285px] max-w-[285px]',
  heightClass = 'h-[calc(100vh-280px)] min-h-[500px]',
  renderCustomCard,
  emptyText = 'No records in this stage',
  isLoading = false,
  className,
}) => {
  const [activeCard, setActiveCard] = useState<OVSKanbanCard | null>(null);

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: {
        distance: 5, // 5px sensor constraint prevents accidental drags on click/scroll
      },
    }),
    useSensor(KeyboardSensor)
  );

  const handleDragStart = (event: DragStartEvent) => {
    const { active } = event;
    const cardData = active.data.current?.card as OVSKanbanCard | undefined;
    if (cardData) {
      setActiveCard(cardData);
    }
  };

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    setActiveCard(null);

    if (!over || !onCardMove) return;

    const activeId = active.id;
    const overId = over.id;

    // Find source column
    let sourceColId = '';
    let targetColId = '';
    let newIndex = 0;

    for (const col of columns) {
      if (col.cards.some((c) => c.id === activeId)) {
        sourceColId = col.id;
      }
      if (col.id === overId) {
        targetColId = col.id;
        newIndex = col.cards.length;
      }
      const targetCardIndex = col.cards.findIndex((c) => c.id === overId);
      if (targetCardIndex !== -1) {
        targetColId = col.id;
        newIndex = targetCardIndex;
      }
    }

    if (sourceColId && targetColId) {
      onCardMove(activeId, sourceColId, targetColId, newIndex);
    }
  };

  // Loading Skeleton State
  if (isLoading) {
    return (
      <div
        className={cn(
          'flex gap-3 overflow-x-auto select-none [scrollbar-width:none] [&::-webkit-scrollbar]:hidden p-1 pb-4',
          heightClass,
          className
        )}
      >
        {Array.from({ length: 4 }).map((_, i) => (
          <div
            key={i}
            className={cn(
              'h-full flex-shrink-0 bg-slate-50 border border-slate-200 rounded-2xl p-3 space-y-3',
              columnWidthClass
            )}
          >
            <div className="flex items-center justify-between pb-2 border-b border-slate-200">
              <Skeleton className="h-4 w-28 rounded-md" />
              <Skeleton className="h-4 w-12 rounded" />
            </div>
            {Array.from({ length: 3 }).map((_, cIdx) => (
              <div
                key={cIdx}
                className="p-3 bg-white border border-slate-200 rounded-xl space-y-2"
              >
                <div className="flex justify-between">
                  <Skeleton className="h-4 w-16 rounded" />
                  <Skeleton className="h-3 w-3 rounded" />
                </div>
                <Skeleton className="h-4 w-4/5 rounded" />
                <Skeleton className="h-3 w-2/5 rounded" />
              </div>
            ))}
          </div>
        ))}
      </div>
    );
  }

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCorners}
      onDragStart={handleDragStart}
      onDragEnd={handleDragEnd}
    >
      <div
        className={cn(
          'flex gap-3 overflow-x-auto select-none [scrollbar-width:none] [&::-webkit-scrollbar]:hidden p-1 pb-4',
          heightClass,
          className
        )}
      >
        {columns.map((column) => (
          <KanbanColumnComponent
            key={column.id}
            column={column}
            onCardClick={onCardClick}
            renderCustomCard={renderCustomCard}
            columnWidthClass={columnWidthClass}
            emptyText={emptyText}
          />
        ))}
      </div>

      <DragOverlay dropAnimation={dropAnimationConfig}>
        {activeCard ? (
          renderCustomCard ? (
            renderCustomCard(activeCard, true, true)
          ) : (
            <OVSKanbanCardView card={activeCard} isOverlay />
          )
        ) : null}
      </DragOverlay>
    </DndContext>
  );
};

// Backwards compatibility alias
export const KanbanBoard = OVSKanbanBoard;
