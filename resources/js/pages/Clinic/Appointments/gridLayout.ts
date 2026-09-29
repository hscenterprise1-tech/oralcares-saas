/**
 * Overlap-column layout for a time grid — sorts events by start time,
 * clusters the ones that mutually overlap, and assigns each a column
 * within its cluster so nothing visually overlaps. This is the one real
 * job FullCalendar's `timeGrid` plugin was still doing after every event's
 * own visual rendering became a fully custom `EventCard` — a clinic's day
 * has at most a handful of doctors' appointments overlapping at once, so
 * the simpler equal-width-columns version is enough; it doesn't do the
 * fancier "expand into free space to the right" packing a general calendar
 * product needs.
 */

export interface OverlapInput {
  id: string;
  startMinutes: number;
  endMinutes: number;
}

export interface OverlapResult {
  id: string;
  column: number;
  columnCount: number;
}

export function layoutOverlaps(events: OverlapInput[]): OverlapResult[] {
  const sorted = [...events].sort((a, b) => a.startMinutes - b.startMinutes || a.endMinutes - b.endMinutes);
  const results: OverlapResult[] = [];

  let cluster: OverlapInput[] = [];
  let clusterEnd = -Infinity;

  const flush = () => {
    if (cluster.length === 0) return;

    const columnEnds: number[] = [];
    const clusterResults: OverlapResult[] = [];

    for (const ev of cluster) {
      const freeColumn = columnEnds.findIndex((end) => end <= ev.startMinutes);
      const column = freeColumn === -1 ? columnEnds.length : freeColumn;
      columnEnds[column] = ev.endMinutes;
      clusterResults.push({ id: ev.id, column, columnCount: -1 });
    }

    const columnCount = columnEnds.length;
    for (const r of clusterResults) {
      r.columnCount = columnCount;
      results.push(r);
    }

    cluster = [];
    clusterEnd = -Infinity;
  };

  for (const ev of sorted) {
    if (cluster.length > 0 && ev.startMinutes >= clusterEnd) {
      flush();
    }
    cluster.push(ev);
    clusterEnd = Math.max(clusterEnd, ev.endMinutes);
  }
  flush();

  return results;
}
