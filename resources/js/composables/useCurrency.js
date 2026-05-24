const vndFmt = new Intl.NumberFormat('vi-VN');

/** Format VND: 1500000 → "1.500.000 đ". Rounds to integer (VND has no fractional units). */
export const formatVnd = (n) => vndFmt.format(Math.round(n ?? 0)) + ' đ';

/** Compact VND for tight spaces: 1500000 → "1,5 tr đ", 2000000000 → "2 tỷ đ" */
export const formatVndCompact = (n) => {
  const v = Math.round(n ?? 0);
  if (v >= 1_000_000_000) return vndFmt.format(+(v / 1_000_000_000).toFixed(1)) + ' tỷ đ';
  if (v >= 1_000_000)     return vndFmt.format(+(v / 1_000_000).toFixed(1))     + ' tr đ';
  return vndFmt.format(v) + ' đ';
};

/** Format percentage: 10 → "10%", 10.5 → "10,5%" */
export const formatPercent = (n, decimals = 1) =>
  vndFmt.format(+Number(n ?? 0).toFixed(decimals)) + '%';

/** Vue composable — destructure in setup() */
export function useCurrency() {
  return { formatVnd, formatVndCompact, formatPercent };
}
