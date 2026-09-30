import React, { useState, useRef, useEffect } from 'react';
import { Search, Plus, X, Filter, ChevronDown } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Input } from '@/ovs/primitives/input';
import { Button } from '@/ovs/primitives/button';
import { Badge } from '@/ovs/primitives/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/ovs/primitives/select';
import { Popover, PopoverTrigger, PopoverContent } from '@/ovs/overlays/popover';

export interface FilterField {
  key: string;
  label: string;
  type: 'text' | 'select' | 'date';
  options?: { label: string; value: string }[];
}

export interface FilterRule {
  id: string;
  fieldKey: string;
  fieldLabel: string;
  operator: string;
  value: string;
  displayValue: string;
}

export interface FilterBarProps {
  fields: FilterField[];
  filters?: FilterRule[];
  onFiltersChange?: (filters: FilterRule[]) => void;
  searchValue?: string;
  onSearchChange?: (value: string) => void;
  placeholder?: string;
  className?: string;
}

export function FilterBar({
  fields,
  filters: initialFilters = [],
  onFiltersChange,
  searchValue = '',
  onSearchChange,
  placeholder = 'Search records...',
  className,
}: FilterBarProps) {
  const [internalSearch, setInternalSearch] = useState(searchValue);
  const [activeFilters, setActiveFilters] = useState<FilterRule[]>(initialFilters);
  const [isOpen, setIsOpen] = useState(false);

  // Add filter form state
  const [selectedFieldKey, setSelectedFieldKey] = useState<string>(fields[0]?.key || '');
  const [operator, setOperator] = useState<string>('contains');
  const [inputValue, setInputValue] = useState<string>('');

  const selectedField = fields.find((f) => f.key === selectedFieldKey) || fields[0];

  const handleSearchChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value;
    setInternalSearch(val);
    if (onSearchChange) onSearchChange(val);
  };

  const handleAddFilter = () => {
    if (!selectedField) return;
    const valStr = inputValue.trim();
    if (!valStr && selectedField.type !== 'select') return;

    let displayVal = valStr;
    if (selectedField.type === 'select' && selectedField.options) {
      const match = selectedField.options.find((o) => o.value === valStr);
      if (match) displayVal = match.label;
    }

    const newRule: FilterRule = {
      id: `${selectedField.key}-${Date.now()}`,
      fieldKey: selectedField.key,
      fieldLabel: selectedField.label,
      operator: operator === 'equals' ? '=' : operator === 'contains' ? 'contains' : ':',
      value: valStr,
      displayValue: displayVal || valStr,
    };

    const updated = [...activeFilters, newRule];
    setActiveFilters(updated);
    if (onFiltersChange) onFiltersChange(updated);

    // Reset modal form
    setInputValue('');
    setIsOpen(false);
  };

  const handleRemoveFilter = (id: string) => {
    const updated = activeFilters.filter((f) => f.id !== id);
    setActiveFilters(updated);
    if (onFiltersChange) onFiltersChange(updated);
  };

  const handleClearAll = () => {
    setActiveFilters([]);
    if (onFiltersChange) onFiltersChange([]);
  };

  return (
    <div className={cn('space-y-3', className)}>
      <div className="flex flex-wrap items-center gap-3">
        {/* Search input with icon */}
        <div className="relative flex-1 min-w-[240px]">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-ovs-secondary pointer-events-none" />
          <Input
            value={onSearchChange ? searchValue : internalSearch}
            onChange={handleSearchChange}
            placeholder={placeholder}
            className="pl-9"
          />
        </div>

        {/* Add Filter Popover */}
        <Popover open={isOpen} onOpenChange={setIsOpen}>
          <PopoverTrigger asChild>
            <Button variant="secondary" size="md" className="gap-2">
              <Filter className="w-4 h-4" />
              <span>Add Filter</span>
              <ChevronDown className="w-3.5 h-3.5 opacity-60" />
            </Button>
          </PopoverTrigger>
          <PopoverContent className="w-80 p-4 space-y-4 shadow-ovs-floating">
            <h4 className="text-xs font-bold text-ovs-primary border-b border-ovs-border-subtle pb-2">Filter Conditions</h4>
            
            {/* Field Select */}
            <div className="space-y-1.5">
              <label className="text-[11px] font-semibold text-ovs-secondary block">Field</label>
              <Select value={selectedFieldKey} onValueChange={(val) => { setSelectedFieldKey(val); setInputValue(''); }}>
                <SelectTrigger>
                  <SelectValue placeholder="Select field" />
                </SelectTrigger>
                <SelectContent>
                  {fields.map((f) => (
                    <SelectItem key={f.key} value={f.key}>{f.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Operator Select */}
            <div className="space-y-1.5">
              <label className="text-[11px] font-semibold text-ovs-secondary block">Condition</label>
              <Select value={operator} onValueChange={setOperator}>
                <SelectTrigger>
                  <SelectValue placeholder="Select operator" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="contains">Contains</SelectItem>
                  <SelectItem value="equals">Equals (=)</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* Value Input / Select */}
            <div className="space-y-1.5">
              <label className="text-[11px] font-semibold text-ovs-secondary block">Value</label>
              {selectedField?.type === 'select' && selectedField.options ? (
                <Select value={inputValue} onValueChange={setInputValue}>
                  <SelectTrigger>
                    <SelectValue placeholder="Choose value" />
                  </SelectTrigger>
                  <SelectContent>
                    {selectedField.options.map((opt) => (
                      <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              ) : selectedField?.type === 'date' ? (
                <Input type="date" value={inputValue} onChange={(e) => setInputValue(e.target.value)} />
              ) : (
                <Input placeholder="Enter value..." value={inputValue} onChange={(e) => setInputValue(e.target.value)} />
              )}
            </div>

            <div className="flex justify-end gap-2 pt-2 border-t border-ovs-border-subtle">
              <Button variant="ghost" size="sm" onClick={() => setIsOpen(false)}>Cancel</Button>
              <Button variant="primary" size="sm" onClick={handleAddFilter}>Apply Filter</Button>
            </div>
          </PopoverContent>
        </Popover>

        {activeFilters.length > 0 && (
          <Button variant="ghost" size="sm" onClick={handleClearAll} className="text-xs text-ovs-secondary hover:text-ovs-semantic-danger-text">
            Clear all
          </Button>
        )}
      </div>

      {/* Active Filter Chips */}
      {activeFilters.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 pt-1">
          {activeFilters.map((rule) => (
            <Badge key={rule.id} variant="neutral" className="gap-1.5 py-1 px-2.5 bg-ovs-background border border-ovs-border-subtle font-medium">
              <span className="text-ovs-secondary">{rule.fieldLabel}</span>
              <span className="text-ovs-accent font-mono text-[10px]">{rule.operator}</span>
              <span className="font-semibold text-ovs-primary">{rule.displayValue}</span>
              <button
                type="button"
                onClick={() => handleRemoveFilter(rule.id)}
                className="hover:text-ovs-semantic-danger text-ovs-secondary ml-1"
                aria-label={`Remove filter ${rule.fieldLabel}`}
              >
                <X className="w-3 h-3" />
              </button>
            </Badge>
          ))}
        </div>
      )}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// AUTOCOMPLETE SEARCH COMPONENT
// ─────────────────────────────────────────────────────────────────────────────

export interface AutocompleteOption {
  label: string;
  value: string;
  category?: string;
}

export interface AutocompleteSearchProps {
  options: AutocompleteOption[];
  onSelect: (value: string) => void;
  value?: string;
  onChange?: (value: string) => void;
  placeholder?: string;
  className?: string;
}

export function AutocompleteSearch({
  options,
  onSelect,
  value: externalValue,
  onChange,
  placeholder = 'Type to search...',
  className,
}: AutocompleteSearchProps) {
  const [query, setQuery] = useState(externalValue || '');
  const [isOpen, setIsOpen] = useState(false);
  const [selectedIndex, setSelectedIndex] = useState<number>(-1);
  const containerRef = useRef<HTMLDivElement>(null);

  const filtered = options.filter((o) =>
    o.label.toLowerCase().includes(query.toLowerCase()) ||
    (o.category && o.category.toLowerCase().includes(query.toLowerCase()))
  );

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value;
    setQuery(val);
    if (onChange) onChange(val);
    setIsOpen(true);
    setSelectedIndex(-1);
  };

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (!isOpen || filtered.length === 0) return;

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setSelectedIndex((prev) => (prev < filtered.length - 1 ? prev + 1 : 0));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setSelectedIndex((prev) => (prev > 0 ? prev - 1 : filtered.length - 1));
    } else if (e.key === 'Enter' && selectedIndex >= 0) {
      e.preventDefault();
      const chosen = filtered[selectedIndex];
      if (chosen) {
        setQuery(chosen.label);
        onSelect(chosen.value);
        setIsOpen(false);
      }
    } else if (e.key === 'Escape') {
      setIsOpen(false);
    }
  };

  // Click outside listener
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  return (
    <div ref={containerRef} className={cn('relative w-full', className)}>
      <div className="relative">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-ovs-secondary pointer-events-none" />
        <Input
          value={externalValue !== undefined ? externalValue : query}
          onChange={handleInputChange}
          onFocus={() => setIsOpen(true)}
          onKeyDown={handleKeyDown}
          placeholder={placeholder}
          className="pl-9"
        />
      </div>

      {isOpen && filtered.length > 0 && (
        <div className="absolute z-50 mt-1 w-full bg-white rounded-xl border border-ovs-border-subtle shadow-ovs-floating max-h-60 overflow-y-auto py-1">
          {filtered.map((opt, idx) => (
            <button
              key={`${opt.value}-${idx}`}
              type="button"
              onClick={() => {
                setQuery(opt.label);
                onSelect(opt.value);
                setIsOpen(false);
              }}
              className={cn(
                'w-full px-3 py-2 text-left text-xs flex items-center justify-between transition-colors',
                idx === selectedIndex ? 'bg-ovs-accent/10 text-ovs-accent font-bold' : 'text-ovs-primary hover:bg-ovs-background'
              )}
            >
              <span>{opt.label}</span>
              {opt.category && (
                <span className="text-[10px] text-ovs-secondary uppercase bg-ovs-background px-1.5 py-0.5 rounded font-mono">
                  {opt.category}
                </span>
              )}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
