import React, { useState } from 'react';
import { cn } from '@/lib/utils';
import { WidgetContainer, WidgetContainerProps } from '../framework';
import { ArrowUpDown } from 'lucide-react';

export interface TableColumn<T = any> {
  key: string;
  header: string;
  render?: (row: T) => React.ReactNode;
  sortable?: boolean;
  align?: 'left' | 'center' | 'right';
}

export interface TableWidgetProps<T = any> extends Omit<WidgetContainerProps, 'children'> {
  columns?: TableColumn<T>[];
  data?: T[];
  selectable?: boolean;
  onSelectionChange?: (selectedIds: string[]) => void;
  onSort?: (key: string, direction: 'asc' | 'desc') => void;
  getRowId?: (row: T) => string;
}

export function TableWidget<T = any>({
  columns = [],
  data = [],
  selectable = false,
  onSelectionChange,
  onSort,
  getRowId = (row: any) => row.id || JSON.stringify(row),
  ...containerProps
}: TableWidgetProps<T>) {
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
  const [sortKey, setSortKey] = useState<string | null>(null);
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');

  const handleSelectAll = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.checked) {
      const allIds = new Set(data.map(getRowId));
      setSelectedIds(allIds);
      onSelectionChange?.(Array.from(allIds));
    } else {
      setSelectedIds(new Set());
      onSelectionChange?.([]);
    }
  };

  const handleSelectRow = (id: string) => {
    const next = new Set(selectedIds);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setSelectedIds(next);
    onSelectionChange?.(Array.from(next));
  };

  const handleHeaderSort = (key: string) => {
    const nextDir = sortKey === key && sortDir === 'asc' ? 'desc' : 'asc';
    setSortKey(key);
    setSortDir(nextDir);
    onSort?.(key, nextDir);
  };

  return (
    <WidgetContainer {...containerProps}>
      {data.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse select-none">
            <thead>
              <tr className="border-b border-[#E4E7EE] bg-[#F8FAFC] text-[11px] font-bold text-[#64748B]">
                {selectable && (
                  <th className="px-4 py-2.5 w-10">
                    <input
                      type="checkbox"
                      onChange={handleSelectAll}
                      checked={data.length > 0 && selectedIds.size === data.length}
                      className="rounded border-[#CBD5E1] text-[#4F46E5] focus:ring-[#4F46E5]/30"
                    />
                  </th>
                )}
                {columns.map((col) => (
                  <th
                    key={col.key}
                    className={cn(
                      'px-4 py-2.5 font-bold uppercase tracking-wider',
                      col.align === 'center' && 'text-center',
                      col.align === 'right' && 'text-right',
                    )}
                  >
                    {col.sortable ? (
                      <button
                        type="button"
                        onClick={() => handleHeaderSort(col.key)}
                        className="flex items-center gap-1.5 hover:text-[#0F172A] transition-colors"
                      >
                        <span>{col.header}</span>
                        <ArrowUpDown className="w-3 h-3 text-[#94A3B8]" />
                      </button>
                    ) : (
                      col.header
                    )}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-[#F4F6F9] text-[12.5px]">
              {data.map((row) => {
                const id = getRowId(row);
                const isSelected = selectedIds.has(id);
                return (
                  <tr
                    key={id}
                    className={cn('hover:bg-[#F8FAFC] transition-colors', isSelected && 'bg-[#EEF2FF]/40')}
                  >
                    {selectable && (
                      <td className="px-4 py-3">
                        <input
                          type="checkbox"
                          checked={isSelected}
                          onChange={() => handleSelectRow(id)}
                          className="rounded border-[#CBD5E1] text-[#4F46E5] focus:ring-[#4F46E5]/30"
                        />
                      </td>
                    )}
                    {columns.map((col) => (
                      <td
                        key={col.key}
                        className={cn(
                          'px-4 py-3 text-[#0F172A]',
                          col.align === 'center' && 'text-center',
                          col.align === 'right' && 'text-right',
                        )}
                      >
                        {col.render ? col.render(row) : (row as any)[col.key]}
                      </td>
                    ))}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </WidgetContainer>
  );
}
