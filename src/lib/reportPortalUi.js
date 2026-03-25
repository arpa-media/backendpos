import { formatDateTime, todayDateInputValue } from './datetime'

export function todayInputValue(timeZone = 'Asia/Jakarta') {
  return todayDateInputValue({ timeZone })
}

export function money(value) {
  return Number(value || 0).toLocaleString('id-ID')
}

export function formatShortDateTime(value) {
  const raw = String(value || '').trim()
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(raw)) {
    return raw.slice(0, 16)
  }
  return formatDateTime(value)
}

export function getPortalReportScope(auth, portalCode) {
  const portals = auth?.reportAccess?.portals || []
  return portals.find((item) => String(item?.portal_code || '').toLowerCase() === String(portalCode || '').toLowerCase()) || null
}

export function getPortalOutletOptions(auth, portalCode) {
  const scope = getPortalReportScope(auth, portalCode)
  const allowed = Array.isArray(scope?.allowed_outlets) ? scope.allowed_outlets : []

  return [
    { id: 'ALL', code: 'ALL', name: 'ALL' },
    ...allowed.map((outlet) => ({
      id: String(outlet.id),
      code: String(outlet.code || ''),
      name: String(outlet.name || outlet.code || outlet.id),
    })),
  ]
}

export function getPortalDefaultTimeZone(auth, portalCode, outletId = 'ALL') {
  const scope = getPortalReportScope(auth, portalCode)
  const allowed = Array.isArray(scope?.allowed_outlets) ? scope.allowed_outlets : []

  if (auth?.user?.outlet?.timezone) return auth.user.outlet.timezone

  if (outletId && outletId !== 'ALL') {
    const selected = allowed.find((outlet) => String(outlet.id) === String(outletId))
    if (selected?.timezone) return selected.timezone
  }

  if (allowed.length === 1 && allowed[0]?.timezone) return allowed[0].timezone

  return 'Asia/Jakarta'
}

export function buildPortalFilters(auth, portalCode) {
  const today = todayInputValue(getPortalDefaultTimeZone(auth, portalCode))
  return {
    date_from: today,
    date_to: today,
    outlet_id: 'ALL',
    per_page: 10,
    page: 1,
    portal_code: portalCode,
    outlet_options: getPortalOutletOptions(auth, portalCode),
  }
}

export function buildPortalQuery(filters = {}) {
  return {
    date_from: filters.date_from || null,
    date_to: filters.date_to || null,
    outlet_id: filters.outlet_id && filters.outlet_id !== 'ALL' ? filters.outlet_id : 'ALL',
    page: Number(filters.page || 1),
    per_page: Number(filters.per_page || 10),
  }
}

export function normalizePortalFilters(filters = {}, options = {}) {
  const next = {
    ...filters,
    date_from: filters.date_from || todayInputValue(),
    date_to: filters.date_to || todayInputValue(),
    outlet_id: filters.outlet_id || 'ALL',
    page: Math.max(1, Number(filters.page || 1)),
    per_page: Math.max(1, Number(filters.per_page || options.perPageDefault || 10)),
  }

  const messages = []

  if (next.date_from && next.date_to && next.date_from > next.date_to) {
    const temp = next.date_from
    next.date_from = next.date_to
    next.date_to = temp
    messages.push('Rentang tanggal dibalik otomatis karena Date from lebih besar dari Date to.')
  }

  const outletOptions = Array.isArray(options.outletOptions) ? options.outletOptions : []
  const allowedOutletIds = new Set(outletOptions.map((outlet) => String(outlet.id)))
  if (next.outlet_id !== 'ALL' && allowedOutletIds.size > 0 && !allowedOutletIds.has(String(next.outlet_id))) {
    next.outlet_id = 'ALL'
    messages.push('Outlet dipulihkan ke ALL karena pilihan sebelumnya tidak ada di whitelist user.')
  }

  if (Array.isArray(options.perPageChoices) && options.perPageChoices.length > 0 && !options.perPageChoices.includes(next.per_page)) {
    next.per_page = Number(options.perPageDefault || options.perPageChoices[0] || 10)
  }

  return {
    filters: next,
    info: messages.join(' '),
  }
}

export function buildNormalizedPortalQuery(filters = {}, options = {}) {
  const normalized = normalizePortalFilters(filters, options)
  return {
    ...normalized,
    query: buildPortalQuery(normalized.filters),
  }
}

export function portalScopeText(filters = {}, outletOptions = [], options = {}) {
  const from = filters.date_from || todayInputValue()
  const to = filters.date_to || todayInputValue()
  const outletId = String(filters.outlet_id || 'ALL')
  const outlet = outletId === 'ALL'
    ? { name: 'ALL' }
    : outletOptions.find((item) => String(item.id) === outletId) || { name: 'ALL' }

  const parts = [`${from} s/d ${to}`, `Outlet ${outlet.name || 'ALL'}`]
  if (options?.markedOnly) {
    parts.push('Marking = 1')
  }

  return parts.join(' • ')
}

export function saleReceiptMeta(sale = {}) {
  return {
    paymentMethodName: String(sale?.payment_method_name || '-'),
    cashierText: String(sale?.cashier_name || sale?.cashier?.name || '-'),
    dateTimeText: formatDateTime(sale?.created_at),
  }
}
