import * as React from 'react';
import { ArrowUpDown, ArrowUp, ArrowDown } from 'lucide-react';
import { cn } from '@/lib/utils';

export type TableDensity = 'compact' | 'default' | 'comfortable';

interface TableContextValue {
  density: TableDensity;
  seamless: boolean;
  stickyHeader: boolean;
}

const TableContext = React.createContext<TableContextValue>({
  density: 'default',
  seamless: false,
  stickyHeader: false,
});

export interface TableProps extends React.HTMLAttributes<HTMLTableElement> {
  density?: TableDensity;
  seamless?: boolean;
  stickyHeader?: boolean;
  containerClassName?: string;
}

const Table = React.forwardRef<HTMLTableElement, TableProps>(
  (
    {
      className,
      containerClassName,
      density = 'default',
      seamless = false,
      stickyHeader = false,
      children,
      ...props
    },
    ref
  ) => (
    <TableContext.Provider value={{ density, seamless, stickyHeader }}>
      <div
        className={cn(
          'relative w-full overflow-x-auto font-sans',
          seamless
            ? 'border-0 shadow-none bg-transparent'
            : 'rounded-xl border border-slate-200/80 shadow-2xs bg-white',
          containerClassName
        )}
        style={{
          // Scroll-shadow hint: on narrow viewports the table is wider than its
          // container (min-w-[700px] below), so this fades in a shadow at
          // whichever edge still has unscrolled content — telling the user
          // there's more to swipe to without needing a visible scrollbar.
          // Plain inline style (not a Tailwind class) because this multi-layer
          // background is too complex for the arbitrary-value JIT scanner.
          backgroundImage:
            'linear-gradient(to right, white, rgba(255,255,255,0)),' +
            'linear-gradient(to left, white, rgba(255,255,255,0)),' +
            'linear-gradient(to right, rgba(15,23,42,0.12), rgba(15,23,42,0)),' +
            'linear-gradient(to left, rgba(15,23,42,0.12), rgba(15,23,42,0))',
          backgroundPosition: '0 0, 100% 0, 0 0, 100% 0',
          backgroundRepeat: 'no-repeat',
          backgroundSize: '24px 100%, 24px 100%, 10px 100%, 10px 100%',
          backgroundAttachment: 'local, local, scroll, scroll',
        }}
      >
        <table
          ref={ref}
          className={cn(
            'w-full caption-bottom text-xs border-collapse font-sans text-left min-w-[700px]',
            className
          )}
          {...props}
        >
          {children}
        </table>
      </div>
    </TableContext.Provider>
  )
);
Table.displayName = 'Table';

export interface TableHeaderProps extends React.HTMLAttributes<HTMLTableSectionElement> {
  sticky?: boolean;
}

const TableHeader = React.forwardRef<HTMLTableSectionElement, TableHeaderProps>(
  ({ className, sticky, ...props }, ref) => {
    const { stickyHeader } = React.useContext(TableContext);
    const isSticky = sticky ?? stickyHeader;

    return (
      <thead
        ref={ref}
        className={cn(
          'border-b border-slate-200/80 bg-slate-50/75 select-none font-sans',
          isSticky && 'sticky top-0 z-10 backdrop-blur-xs bg-slate-50/95 shadow-2xs',
          className
        )}
        {...props}
      />
    );
  }
);
TableHeader.displayName = 'TableHeader';

const TableBody = React.forwardRef<
  HTMLTableSectionElement,
  React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
  <tbody
    ref={ref}
    className={cn('[&_tr:last-child]:border-0 divide-y divide-slate-100/90 font-sans', className)}
    {...props}
  />
));
TableBody.displayName = 'TableBody';

const TableFooter = React.forwardRef<
  HTMLTableSectionElement,
  React.HTMLAttributes<HTMLTableSectionElement>
>(({ className, ...props }, ref) => (
  <tfoot
    ref={ref}
    className={cn(
      'border-t border-slate-200/80 bg-slate-50/50 font-sans font-medium text-slate-700 [&>tr]:last:border-b-0',
      className
    )}
    {...props}
  />
));
TableFooter.displayName = 'TableFooter';

export interface TableRowProps extends React.HTMLAttributes<HTMLTableRowElement> {
  isSelected?: boolean;
  isClickable?: boolean;
}

const TableRow = React.forwardRef<HTMLTableRowElement, TableRowProps>(
  ({ className, isSelected = false, isClickable = true, ...props }, ref) => {
    const { density } = React.useContext(TableContext);

    // Candidate D Canonical Row Heights
    const rowHeights: Record<TableDensity, string> = {
      compact: 'h-[48px]',
      default: 'h-[56px]',
      comfortable: 'h-[64px]',
    };

    return (
      <tr
        ref={ref}
        data-state={isSelected ? 'selected' : undefined}
        className={cn(
          'border-b border-slate-100 transition-colors duration-150 font-sans',
          rowHeights[density],
          isClickable && 'hover:bg-slate-50/80 cursor-pointer group select-none',
          isSelected && 'bg-slate-100/80 font-medium',
          className
        )}
        {...props}
      />
    );
  }
);
TableRow.displayName = 'TableRow';

export interface TableHeadProps extends React.ThHTMLAttributes<HTMLTableCellElement> {
  align?: 'left' | 'center' | 'right';
  isSortable?: boolean;
  sortDirection?: 'asc' | 'desc' | null;
  onSort?: () => void;
}

const TableHead = React.forwardRef<HTMLTableCellElement, TableHeadProps>(
  (
    {
      className,
      align = 'left',
      isSortable = false,
      sortDirection = null,
      onSort,
      children,
      ...props
    },
    ref
  ) => {
    const { density } = React.useContext(TableContext);

    const headPaddings: Record<TableDensity, string> = {
      compact: 'py-2 px-3',
      default: 'py-2.5 px-3.5',
      comfortable: 'py-3 px-4',
    };

    const alignmentClasses = {
      left: 'text-left',
      center: 'text-center',
      right: 'text-right',
    };

    return (
      <th
        ref={ref}
        aria-sort={sortDirection ? (sortDirection === 'asc' ? 'ascending' : 'descending') : props['aria-sort'] || 'none'}
        onClick={isSortable ? onSort : undefined}
        className={cn(
          'h-9 align-middle text-[11px] font-semibold uppercase tracking-wider text-slate-500 font-sans select-none group',
          headPaddings[density],
          alignmentClasses[align],
          isSortable && 'cursor-pointer hover:text-slate-900 transition-colors',
          sortDirection && 'text-slate-900 font-bold',
          className
        )}
        {...props}
      >
        <div
          className={cn(
            'inline-flex items-center gap-1.5',
            align === 'center' && 'justify-center',
            align === 'right' && 'justify-end w-full'
          )}
        >
          <span>{children}</span>
          {isSortable && (
            <span className="inline-flex shrink-0">
              {sortDirection === 'asc' ? (
                <ArrowUp className="w-3 h-3 text-slate-900 stroke-[2.5]" />
              ) : sortDirection === 'desc' ? (
                <ArrowDown className="w-3 h-3 text-slate-900 stroke-[2.5]" />
              ) : (
                <ArrowUpDown className="w-3 h-3 text-slate-400 opacity-0 group-hover:opacity-100 transition-opacity" />
              )}
            </span>
          )}
        </div>
      </th>
    );
  }
);
TableHead.displayName = 'TableHead';

export interface TableCellProps extends React.TdHTMLAttributes<HTMLTableCellElement> {
  align?: 'left' | 'center' | 'right';
  isNumeric?: boolean;
  isTruncated?: boolean;
}

const TableCell = React.forwardRef<HTMLTableCellElement, TableCellProps>(
  ({ className, align = 'left', isNumeric = false, isTruncated = false, ...props }, ref) => {
    const { density } = React.useContext(TableContext);

    const cellPaddings: Record<TableDensity, string> = {
      compact: 'py-1.5 px-3',
      default: 'py-2.5 px-3.5',
      comfortable: 'py-3 px-4',
    };

    const alignmentClasses = {
      left: 'text-left',
      center: 'text-center',
      right: 'text-right',
    };

    return (
      <td
        ref={ref}
        className={cn(
          'align-middle text-xs text-slate-900 font-sans',
          cellPaddings[density],
          alignmentClasses[align],
          isNumeric && 'text-right tabular-nums font-semibold',
          isTruncated && 'truncate max-w-[220px]',
          className
        )}
        {...props}
      />
    );
  }
);
TableCell.displayName = 'TableCell';

const TableCaption = React.forwardRef<
  HTMLTableCaptionElement,
  React.HTMLAttributes<HTMLTableCaptionElement>
>(({ className, ...props }, ref) => (
  <caption
    ref={ref}
    className={cn('mt-3 text-xs text-slate-500 font-sans text-center', className)}
    {...props}
  />
));
TableCaption.displayName = 'TableCaption';

/**
 * Standardized 2-Line Primary Entity Formatter
 */
export const TablePrimaryEntity: React.FC<{
  title: string;
  subtitle?: string | null;
  metadata?: string | null;
  className?: string;
}> = ({ title, subtitle, metadata, className }) => (
  <div className={cn('flex flex-col min-w-0 font-sans leading-tight', className)}>
    <span
      className="text-xs font-bold text-slate-900 group-hover:text-teal-700 transition-colors truncate tracking-tight"
      title={title}
    >
      {title}
    </span>
    {(subtitle || metadata) && (
      <div className="flex items-center gap-1.5 mt-0.5 text-[11px] text-slate-500 truncate">
        {subtitle && <span className="truncate">{subtitle}</span>}
        {subtitle && metadata && <span className="text-slate-300">·</span>}
        {metadata && <span className="text-slate-400 font-normal shrink-0">{metadata}</span>}
      </div>
    )}
  </div>
);

export {
  Table,
  TableHeader,
  TableBody,
  TableFooter,
  TableHead,
  TableRow,
  TableCell,
  TableCaption,
};
