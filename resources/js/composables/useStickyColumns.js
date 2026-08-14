/**
 * Computes cumulative left offsets for frozen (sticky) leading columns in a wide table.
 * Pair with the .erp-sticky-col / .erp-sticky-col-header / .erp-sticky-col-boundary
 * utility classes in resources/css/app.css.
 *
 * @param {Array<{key: string, width: number}>} columns - sticky columns in left-to-right order
 */
export function useStickyColumns(columns) {
  const offsets = {};
  let acc = 0;
  for (const col of columns) {
    offsets[col.key] = {
      left: `${acc}px`,
      width: `${col.width}px`,
      minWidth: `${col.width}px`,
      maxWidth: `${col.width}px`,
    };
    acc += col.width;
  }

  const boundaryKey = columns.length ? columns[columns.length - 1].key : null;
  const boundaryLeft = `${acc}px`;

  /** Style object for a sticky <th>/<td>. Pass widthless: true for colspan cells (left offset only). */
  function getStickyStyle(key, { widthless = false } = {}) {
    const style = offsets[key];
    if (!style) return {};
    return widthless ? { left: style.left } : style;
  }

  return { offsets, boundaryKey, boundaryLeft, getStickyStyle };
}
