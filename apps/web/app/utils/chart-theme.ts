const FALLBACKS: Record<string, string> = {
  'ink': '28 28 28',
  'surface': '255 255 255',
  'brand-500': '107 107 226',
  'success': '113 221 140',
  'info': '125 187 255',
  'warning': '255 181 91',
  'danger': '255 71 71',
}

/** Design token (`--pb-*` in main.css) as a CSS color, so charts follow the same palette as the UI. */
export function tokenColor(name: string, alpha = 1): string {
  const value = typeof document === 'undefined'
    ? ''
    : getComputedStyle(document.documentElement).getPropertyValue(`--pb-${name}`).trim()

  return `rgb(${value || FALLBACKS[name] || FALLBACKS.ink} / ${alpha})`
}

export const CHART_FONT = '"Inter Variable", Inter, system-ui, sans-serif'
