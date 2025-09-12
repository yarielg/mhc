export function formatWeekRange(start, end) {
  if (!start || !end) return "—";
  function parseLocalDate(str) {
    const [y, m, d] = str.split('-').map(Number);
    return new Date(y, m - 1, d);
  }
  const s = parseLocalDate(start);
  const e = parseLocalDate(end);
  if (isNaN(s) || isNaN(e)) return `${start} / ${end}`;
  const sm = s.toLocaleString('en-US', { month: 'short' });
  const em = e.toLocaleString('en-US', { month: 'short' });
  const sy = s.getFullYear();
  const ey = e.getFullYear();
  const sd = s.getDate();
  const ed = e.getDate();
  if (s.getTime() === e.getTime()) return `${sm} ${sd}, ${sy}`;
  if (sm === em && sy === ey) return `${sm} ${sd}–${ed}, ${sy}`;
  if (sy === ey) return `${sm} ${sd}–${em} ${ed}, ${sy}`;
  return `${sm} ${sd}, ${sy}–${em} ${ed}, ${ey}`;
}
