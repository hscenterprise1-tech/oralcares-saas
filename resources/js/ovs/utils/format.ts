/**
 * Central formatting utility for the OralCares Enterprise Platform.
 * Ensures consistent localization and presentation across all modules.
 *
 * Default currency: INR (₹) — Indian Rupee.
 * Future-ready: Pass a `currency` option to support USD, EUR, AED, GBP, etc.
 * when multi-currency support is enabled via application settings.
 */

export type SupportedCurrency = 'INR' | 'USD' | 'EUR' | 'AED' | 'GBP';

const CURRENCY_LOCALE_MAP: Record<SupportedCurrency, string> = {
    INR: 'en-IN',
    USD: 'en-US',
    EUR: 'de-DE',
    AED: 'ar-AE',
    GBP: 'en-GB',
};

/**
 * Format a numeric value as a currency string.
 *
 * @param val     - The numeric value to format.
 * @param options - Optional Intl.NumberFormatOptions overrides.
 *                  Pass `{ currency: 'USD' }` to use a different currency.
 * @returns       Formatted currency string (e.g. "₹1,24,500" or "$124,500")
 */
export const formatCurrency = (
    val: number,
    options: Intl.NumberFormatOptions & { currency?: SupportedCurrency } = {}
): string => {
    const currency = (options.currency ?? 'INR') as SupportedCurrency;
    const locale = CURRENCY_LOCALE_MAP[currency] ?? 'en-IN';

    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
        ...options,
    }).format(val || 0);
};

/**
 * Format a number using Indian locale conventions by default.
 */
export const formatNumber = (val: number, options: Intl.NumberFormatOptions = {}): string => {
    return new Intl.NumberFormat('en-IN', options).format(val || 0);
};

/**
 * Format a compact currency value (e.g. ₹1.2L, ₹45K).
 * Useful for chart labels and summary widgets.
 */
export const formatCurrencyCompact = (
    val: number,
    options: Intl.NumberFormatOptions & { currency?: SupportedCurrency } = {}
): string => {
    const currency = (options.currency ?? 'INR') as SupportedCurrency;
    const locale = CURRENCY_LOCALE_MAP[currency] ?? 'en-IN';

    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        notation: 'compact',
        maximumFractionDigits: 1,
        ...options,
    }).format(val || 0);
};

