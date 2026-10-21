/*
 * How patient facts are written, the same on every patient page.
 */

const SEX: Record<string, string> = { male: 'Male', female: 'Female', other: 'Other' };

/** "34 y · Female", from whatever of the two the server has. */
export function ageSex(age: number | null | undefined, gender: string | null | undefined): string | null {
  const parts = [age !== null && age !== undefined ? `${age} y` : null, gender ? (SEX[gender] ?? gender) : null].filter(Boolean);

  return parts.length ? parts.join(' · ') : null;
}

/** A calendar date the way the clinic reads it: "3 Oct 2026". */
export function shortDate(value: string | null | undefined): string | null {
  if (!value) {
    return null;
  }
  const date = new Date(value.length === 10 ? `${value}T00:00:00` : value.replace(' ', 'T'));

  return Number.isNaN(date.getTime()) ? null : date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
}

/** "Today", "Yesterday", "5 days ago", else the date. */
export function lastSeen(value: string | null | undefined): string | null {
  if (!value) {
    return null;
  }
  const date = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(date.getTime())) {
    return null;
  }
  const startOf = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
  const days = Math.round((startOf(new Date()) - startOf(date)) / 86_400_000);
  if (days <= 0) {
    return 'Today';
  }
  if (days === 1) {
    return 'Yesterday';
  }
  if (days < 7) {
    return `${days} days ago`;
  }

  return shortDate(value);
}
