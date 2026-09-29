import * as React from 'react';
import { ArrowUpDown, ArrowUp, ArrowDown } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The CRM's compositional table primitives.
 *
 * Deliberately a CRM-owned copy of `@/ovs/tables/table`, with the same
 * component names and the same props, so a page moves onto the new language by
 * changing one import line rather than rewriting its markup.
 *
 * The copy exists because the shared version hardcodes the raw slate palette
 * (`text-slate-500`, `border-slate-200`, `bg-slate-50`) and is used by Finance,
 * People, Deployments and Visual Studio as well. Restyling it in place would
 * have redecorated four modules that nobody asked to change; forking it keeps
 * the blast radius exactly at the CRM.
 *
 * What actually changed from the original: colours come from `ovs-crm-*`
 * tokens, headers lose the shouty uppercase micro-caps for the same 11.5px
 * regular the rest of the CRM uses, rules are hairlines in white rather than
 * grey borders, and rows wash to the inset surface on hover instead of picking
 * up a border.
 */

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
  ({ className, containerClassName, density = 'default', seamless = false, stickyHeader = false, children, ...props }, ref) => (
    <TableContext.Provider value={{ density, seamless, stickyHeader }}>
      <div
        className={cn(
          'relative w-full overflow-x-auto',
          seamless ? 'border-0 bg-transparent shadow-none' : 'rounded-[18px] bg-white shadow-ovs-crmPanel',
          containerClassName
        )}
        style={{
          /*
           * Scroll-shadow hint. The table is wider than its container on narrow
           * viewports, so a shadow fades in at whichever edge still has
           * unscrolled content — telling the reader there is more to swipe to
           * without a visible scrollbar. Inline because this multi-layer
           * background is beyond what the arbitrary-value scanner will emit.
           */
          backgroundImage:
            'linear-gradient(to right, white, rgba(255,255,255,0)),' +
            'linear-gradient(to left, white, rgba(255,255,255,0)),' +
            'linear-gradient(to right, rgba(11,11,15,0.10), rgba(11,11,15,0)),' +
            'linear-gradient(to left, rgba(11,11,15,0.10), rgba(11,11,15,0))',
          backgroundPosition: '0 0, 100% 0, 0 0, 100% 0',
          backgroundRepeat: 'no-repeat',
          backgroundSize: '24px 100%, 24px 100%, 10px 100%, 10px 100%',
          backgroundAttachment: 'local, local, scroll, scroll',
        }}
      >
        <table
          ref={ref}
          className={cn('w-full min-w-[700px] caption-bottom border-collapse text-left text-[13px]', className)}
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
          'select-none border-b border-ovs-crm-border bg-ovs-crm-surface-inset',
          isSticky && 'sticky top-0 z-10 backdrop-blur-sm',
          className
        )}
        {...props}
      />
    );
  }
);
TableHeader.displayName = 'TableHeader';

const TableBody = React.forwardRef<HTMLTableSectionElement, React.HTMLAttributes<HTMLTableSectionElement>>(
  ({ className, ...props }, ref) => (
    <tbody ref={ref} className={cn('divide-y divide-ovs-crm-border/60 [&_tr:last-child]:border-0', className)} {...props} />
  )
);
TableBody.displayName = 'TableBody';

const TableFooter = React.forwardRef<HTMLTableSectionElement, React.HTMLAttributes<HTMLTableSectionElement>>(
  ({ className, ...props }, ref) => (
    <tfoot
      ref={ref}
      className={cn(
        'border-t border-ovs-crm-border bg-ovs-crm-surface-inset font-medium text-ovs-crm-text-secondary [&>tr]:last:border-b-0',
        className
      )}
      {...props}
    />
  )
);
TableFooter.displayName = 'TableFooter';

export interface TableRowProps extends React.HTMLAttributes<HTMLTableRowElement> {
  isSelected?: boolean;
  isClickable?: boolean;
}

const TableRow = React.forwardRef<HTMLTableRowElement, TableRowProps>(
  ({ className, isSelected = false, isClickable = true, ...props }, ref) => {
    const { density } = React.useContext(TableContext);

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
          'transition-colors duration-150',
          rowHeights[density],
          isClickable && 'group cursor-pointer select-none hover:bg-ovs-crm-surface-inset',
          isSelected && 'bg-ovs-crm-surface-inset font-medium',
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
  ({ className, align = 'left', isSortable = false, sortDirection = null, onSort, children, ...props }, ref) => {
    const { density } = React.useContext(TableContext);

    const headPaddings: Record<TableDensity, string> = {
      compact: 'px-3 py-2',
      default: 'px-3.5 py-2.5',
      comfortable: 'px-4 py-3',
    };

    const alignmentClasses = { left: 'text-left', center: 'text-center', right: 'text-right' };

    return (
      <th
        ref={ref}
        aria-sort={sortDirection ? (sortDirection === 'asc' ? 'ascending' : 'descending') : props['aria-sort'] || 'none'}
        onClick={isSortable ? onSort : undefined}
        className={cn(
          'group h-9 select-none align-middle text-[11.5px] font-normal text-ovs-crm-text-muted',
          headPaddings[density],
          alignmentClasses[align],
          isSortable && 'cursor-pointer transition-colors hover:text-ovs-crm-text-primary',
          sortDirection && 'font-medium text-ovs-crm-text-primary',
          className
        )}
        {...props}
      >
        <div
          className={cn(
            'inline-flex items-center gap-1.5',
            align === 'center' && 'justify-center',
            align === 'right' && 'w-full justify-end'
          )}
        >
          <span>{children}</span>
          {isSortable && (
            <span className="inline-flex shrink-0">
              {sortDirection === 'asc' ? (
                <ArrowUp className="h-3 w-3 stroke-[2.5] text-ovs-crm-text-primary" />
              ) : sortDirection === 'desc' ? (
                <ArrowDown className="h-3 w-3 stroke-[2.5] text-ovs-crm-text-primary" />
              ) : (
                // Visible at rest so a sortable column looks sortable; hover only deepens it.
                <ArrowUpDown className="h-3 w-3 text-ovs-crm-text-muted/50 transition-colors group-hover:text-ovs-crm-text-muted" />
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
      compact: 'px-3 py-1.5',
      default: 'px-3.5 py-2.5',
      comfortable: 'px-4 py-3',
    };

    const alignmentClasses = { left: 'text-left', center: 'text-center', right: 'text-right' };

    return (
      <td
        ref={ref}
        className={cn(
          'align-middle text-[13px] text-ovs-crm-text-primary',
          cellPaddings[density],
          alignmentClasses[align],
          isNumeric && 'text-right font-medium tabular-nums',
          isTruncated && 'max-w-[220px] truncate',
          className
        )}
        {...props}
      />
    );
  }
);
TableCell.displayName = 'TableCell';

const TableCaption = React.forwardRef<HTMLTableCaptionElement, React.HTMLAttributes<HTMLTableCaptionElement>>(
  ({ className, ...props }, ref) => (
    <caption ref={ref} className={cn('mt-3 text-center text-[11.5px] text-ovs-crm-text-muted', className)} {...props} />
  )
);
TableCaption.displayName = 'TableCaption';

/** The two-line primary cell: what the row is, and how to tell it apart. */
export const TablePrimaryEntity: React.FC<{
  title: string;
  subtitle?: string | null;
  metadata?: string | null;
  className?: string;
}> = ({ title, subtitle, metadata, className }) => (
  <div className={cn('flex min-w-0 flex-col leading-tight', className)}>
    <span
      className="truncate text-[13px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary transition-colors group-hover:text-ovs-crm-brand-text"
      title={title}
    >
      {title}
    </span>
    {(subtitle || metadata) && (
      <div className="mt-0.5 flex items-center gap-1.5 truncate text-[11.5px] text-ovs-crm-text-muted">
        {subtitle && <span className="truncate">{subtitle}</span>}
        {subtitle && metadata && <span className="text-ovs-crm-border-strong">·</span>}
        {metadata && <span className="shrink-0 font-normal text-ovs-crm-text-muted">{metadata}</span>}
      </div>
    )}
  </div>
);

export { Table, TableHeader, TableBody, TableFooter, TableHead, TableRow, TableCell, TableCaption };
