/**
 * Side-by-side columns for overlapping appointments in one time-grid column:
 * events that overlap share the width; nothing is drawn on top of another.
 */
export interface Placed {
  id: number;
  column: number;
  columns: number;
}

export function layoutOverlaps(events: { id: number; start: number; end: number }[]): Map<number, Placed> {
  const sorted = [...events].sort((a, b) => a.start - b.start || b.end - a.end);
  const placed = new Map<number, Placed>();
  let cluster: { id: number; column: number }[] = [];
  let ends: number[] = [];
  let clusterEnd = -Infinity;

  const flush = () => {
    cluster.forEach((event) => placed.set(event.id, { id: event.id, column: event.column, columns: ends.length }));
    cluster = [];
    ends = [];
    clusterEnd = -Infinity;
  };

  for (const event of sorted) {
    if (cluster.length > 0 && event.start >= clusterEnd) {
      flush();
    }
    const free = ends.findIndex((end) => end <= event.start);
    const column = free === -1 ? ends.length : free;
    ends[column] = event.end;
    cluster.push({ id: event.id, column });
    clusterEnd = Math.max(clusterEnd, event.end);
  }
  flush();

  return placed;
}
