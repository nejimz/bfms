export function toMinor(value: string): number | null {
  const trimmed = value.trim()
  if (trimmed === '') {
    return 0
  }

  const normalized = trimmed.startsWith('.') ? `0${trimmed}` : trimmed
  if (!/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(normalized)) {
    return null
  }

  const [whole, fraction = ''] = normalized.split('.')
  return Number(whole) * 100 + Number((fraction + '00').slice(0, 2))
}

export function formatMinor(minor: number): string {
  const negative = minor < 0
  const absolute = Math.abs(minor)
  const whole = Math.floor(absolute / 100)
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  const fraction = String(absolute % 100).padStart(2, '0')

  return `${negative ? '-' : ''}₱${whole}.${fraction}`
}

export function formatDecimal(decimal: string): string {
  const negative = decimal.startsWith('-')
  const minor = toMinor(negative ? decimal.slice(1) : decimal)
  if (minor === null) {
    return decimal
  }

  return formatMinor(negative ? -minor : minor)
}

export function normalizeDecimal(value: string): string | null {
  const minor = toMinor(value)
  if (minor === null) {
    return null
  }

  const absolute = Math.abs(minor)
  return `${Math.floor(absolute / 100)}.${String(absolute % 100).padStart(2, '0')}`
}
