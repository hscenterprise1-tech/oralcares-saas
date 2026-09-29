/**
 * Browser mirror of `App\Services\Patient\CaseNumberFormatter` for the
 * Settings live preview. Both are checked against the shared cases in
 * `caseNumberFormatCases.json` (see `CaseNumberFormatParityTest.php`), so the
 * preview can never show a number the server would not issue.
 *
 * Kept free of imports and non-erasable TypeScript so Node can run it
 * directly in that parity test.
 */

export type YearStyle = 'YYYY' | 'YY' | 'FY' | 'FY_SHORT';

export interface CaseNumberFormat {
    prefix: string;
    show_year: boolean;
    year_style: YearStyle;
    separator: string;
    digits: number;
    reset_yearly: boolean;
}

export const YEAR_STYLES: YearStyle[] = ['YYYY', 'YY', 'FY', 'FY_SHORT'];

export const SEPARATORS = ['-', '/', '.', ''];

const isFinancialYear = (yearStyle: YearStyle): boolean => yearStyle === 'FY' || yearStyle === 'FY_SHORT';

/** `date` is a plain `YYYY-MM-DD` string — no timezone conversion. */
export function periodStartYear(yearStyle: YearStyle, date: string): number {
    const [year, month] = date.split('-').map(Number);

    return isFinancialYear(yearStyle) && month < 4 ? year - 1 : year;
}

export function periodKey(yearStyle: YearStyle, date: string): string {
    return (isFinancialYear(yearStyle) ? 'FY' : 'CY') + periodStartYear(yearStyle, date);
}

const twoDigits = (value: number): string => String(value % 100).padStart(2, '0');

export function yearLabel(yearStyle: YearStyle, startYear: number): string {
    switch (yearStyle) {
        case 'YY':
            return twoDigits(startYear);
        case 'FY':
            return `${startYear}-${twoDigits(startYear + 1)}`;
        case 'FY_SHORT':
            return `${twoDigits(startYear)}-${twoDigits(startYear + 1)}`;
        default:
            return String(startYear);
    }
}

export function formatCaseNumber(format: CaseNumberFormat, date: string, sequence: number): string {
    const parts = [format.prefix];

    if (format.show_year) {
        parts.push(yearLabel(format.year_style, periodStartYear(format.year_style, date)));
    }

    parts.push(String(sequence).padStart(format.digits, '0'));

    return parts.join(format.separator);
}

export function searchKey(caseNumber: string): string {
    return caseNumber.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
}

export function shortLabel(sequence: number): string {
    return String(sequence % 10000).padStart(4, '0');
}
