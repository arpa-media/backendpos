import { compactChannelLabel, formatSaleDateTimeText, getSaleNumberShort, getSaleQueueNumber, getSaleTableLabel } from './printSaleMeta'
import { channelHeaderLabel, splitKitchenDocuments, splitPizzaDocuments, splitTableDocuments } from './printChannelSplit'
import { decoratePrintedItemDetail, decoratePrintedItemName, getSalePrintRequestContext, getRequestStatusLabel, getRequestTypeLabel } from './printRequestContext'

function repeat(char = ' ', count = 0) {
  return new Array(Math.max(0, Number(count) || 0) + 1).join(String(char || ' ').slice(0, 1))
}

export function normalizeText(value) {
  return String(value ?? '')
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .replace(/\t/g, ' ')
}

export function normalizeWidth(charsPerLine = 42) {
  const width = Number(charsPerLine)
  return Number.isFinite(width) && width >= 16 ? Math.floor(width) : 42
}

export function padLine(value = '', width = 42) {
  const targetWidth = normalizeWidth(width)
  const text = normalizeText(value)
  return text.length >= targetWidth ? text.slice(0, targetWidth) : text.padEnd(targetWidth, ' ')
}

export function rightText(value = '', width = 42) {
  const targetWidth = normalizeWidth(width)
  const text = normalizeText(value).trim()
  if (text.length >= targetWidth) return text.slice(text.length - targetWidth)
  return text.padStart(targetWidth, ' ')
}

export function repeatText(char = '-', width = 42) {
  return repeat(char, normalizeWidth(width))
}

export function wrapLine(text, width = 42) {
  const safeWidth = Math.max(8, normalizeWidth(width))
  const normalized = normalizeText(text).replace(/\s+/g, ' ').trim()
  if (!normalized) return ['']

  const words = normalized.split(' ')
  const out = []
  let current = ''

  for (const word of words) {
    if (!current) {
      if (word.length <= safeWidth) {
        current = word
      } else {
        for (let i = 0; i < word.length; i += safeWidth) out.push(word.slice(i, i + safeWidth))
        current = ''
      }
      continue
    }

    const next = `${current} ${word}`
    if (next.length <= safeWidth) {
      current = next
      continue
    }

    out.push(current)
    if (word.length <= safeWidth) {
      current = word
    } else {
      for (let i = 0; i < word.length; i += safeWidth) out.push(word.slice(i, i + safeWidth))
      current = ''
    }
  }

  if (current) out.push(current)
  return out.length ? out : ['']
}

export function wrapTextBlock(text, width = 42) {
  return normalizeText(text)
    .split('\n')
    .flatMap((line) => wrapLine(line, width))
}

export function wrapText(text = '', width = 42) {
  return wrapTextBlock(text, width)
}

export function centerText(text, width = 42) {
  const targetWidth = normalizeWidth(width)
  const lines = wrapTextBlock(text, targetWidth)
  return lines.map((line) => {
    const trimmed = line.trim()
    const pad = Math.max(0, Math.floor((targetWidth - trimmed.length) / 2))
    return `${repeat(' ', pad)}${trimmed}`
  })
}

export function columns(left, right, width = 42) {
  const safeWidth = Math.max(8, normalizeWidth(width))
  const rightLabel = normalizeText(right).trim()
  const leftLabel = normalizeText(left).trim()
  if (!rightLabel) return wrapTextBlock(leftLabel, safeWidth)
  const maxLeft = Math.max(4, safeWidth - rightLabel.length - 1)
  const leftLines = wrapLine(leftLabel, maxLeft)
  const out = []
  leftLines.forEach((line, idx) => {
    if (idx === 0) {
      const padding = Math.max(1, safeWidth - line.length - rightLabel.length)
      out.push(`${line}${repeat(' ', padding)}${rightLabel}`)
    } else {
      out.push(line)
    }
  })
  return out
}

export function divider(width = 42, char = '-') {
  const targetWidth = normalizeWidth(width)
  const safeWidth = Math.max(8, targetWidth - (targetWidth >= 40 ? 2 : 1))
  return repeat(char, safeWidth)
}

export function columnsFixedRight(left, right, width = 42, rightReserve = 12) {
  const safeWidth = Math.max(8, normalizeWidth(width))
  const rightLabel = normalizeText(right).trim()
  const leftLabel = normalizeText(left).trim()
  if (!rightLabel) return wrapTextBlock(leftLabel, safeWidth)
  const reserve = Math.min(Math.max(rightReserve, rightLabel.length), Math.max(8, safeWidth - 8))
  const maxLeft = Math.max(4, safeWidth - reserve - 1)
  const leftLines = wrapLine(leftLabel, maxLeft)
  const out = []
  leftLines.forEach((line, idx) => {
    if (idx === leftLines.length - 1) {
      const padding = Math.max(1, safeWidth - line.length - rightLabel.length)
      out.push(`${line}${repeat(' ', padding)}${rightLabel}`)
    } else {
      out.push(line)
    }
  })
  return out
}

export function keyValueLines(label, value, width = 42) {
  return columns(label, value, width)
}

export function itemRow(name, qty, width = 42, note = '') {
  const targetWidth = normalizeWidth(width)
  const qtyText = `x${qty || 1}`
  const nameWidth = Math.max(8, targetWidth - qtyText.length - 1)
  const nameLines = wrapTextBlock(name, nameWidth)
  const rows = nameLines.map((entry, index) => {
    if (index === 0) {
      return `${entry}${repeat(' ', Math.max(1, targetWidth - entry.length - qtyText.length))}${qtyText}`.slice(0, targetWidth)
    }
    return entry
  })
  if (note) {
    for (const noteLine of wrapTextBlock(note, Math.max(8, targetWidth - 2))) {
      rows.push(`  ${noteLine}`)
    }
  }
  return rows
}

function safeText(value, fallback = '-') {
  const text = String(value ?? '').replace(/\s+/g, ' ').trim()
  return text || fallback
}

function formatMoney(value) {
  return `Rp ${Number(value || 0).toLocaleString('id-ID')}`
}

function saleNumberTail(value) {
  const raw = String(value || '')
  return raw ? raw.slice(-8) : '-'
}

function appendRequestBanner(lines, sale, width) {
  const ctx = getSalePrintRequestContext(sale)
  if (!ctx) return
  lines.push(divider(width, '='))
  lines.push(...centerText(getRequestTypeLabel(ctx.request_type), width))
  lines.push(...centerText(`Status: ${getRequestStatusLabel(ctx.status)}`, width))
  if (ctx.reason) lines.push(...wrapTextBlock(`Reason: ${ctx.reason}`, width))
  lines.push(divider(width, '='))
}

function pushLabeledValue(lines, label, value, width) {
  const labelText = `${label} = ${safeText(value, '-')}`
  lines.push(...wrapTextBlock(labelText, width))
}

function buildReceiptItemLines(item, width) {
  const lines = []
  const amount = formatMoney(item?.line_total)
  lines.push(...columnsFixedRight(decoratePrintedItemName(item), amount, width, 13))
  const variant = String(item?.variant_name || '').trim()
  if (variant) lines.push(...wrapTextBlock(variant, width))
  lines.push(...wrapTextBlock(`${Math.max(1, Number(item?.qty || 1) || 1)} x ${formatMoney(item?.unit_price)}`, width))
  const detail = decoratePrintedItemDetail(String(item?.note ?? item?.notes ?? item?.remark ?? item?.remarks ?? '').trim(), item)
  if (detail) lines.push(...wrapTextBlock(`Note: ${detail}`, width))
  return lines
}

function renderChannelSection(lines, channel, width) {
  lines.push(divider(width))
  lines.push(...centerText(channelHeaderLabel(channel || '-'), width))
  lines.push(divider(width))
}

export function renderKitchenTicketText(sale, profile = {}, options = {}) {
  const width = normalizeWidth(profile?.charsPerLine)
  const providedDocs = Array.isArray(options?.splitDocuments) ? options.splitDocuments : null
  const documents = (providedDocs || splitKitchenDocuments(sale, options?.printerSettings)).filter((doc) => Array.isArray(doc?.items) && doc.items.length)
  const out = [
    ...centerText('NEW ORDER', width),
    ...centerText(`Queue # ${getSaleQueueNumber(sale)}`, width),
    ...columns(`Sale # ${saleNumberTail(sale?.sale_number || sale?.invoice_no || '')}`, formatSaleDateTimeText(sale?.created_at || sale?.paid_at || sale?.timestamp, sale?.outlet?.timezone || 'Asia/Jakarta'), width),
  ]

  if (sale?.cashier?.name || sale?.cashier_name || sale?.cashierName) pushLabeledValue(out, 'Cashier', sale?.cashier?.name || sale?.cashier_name || sale?.cashierName, width)
  if (sale?.bill_name || sale?.customer_name) pushLabeledValue(out, 'Customer', sale?.bill_name || sale?.customer_name, width)
  pushLabeledValue(out, 'Table Number', getSaleTableLabel(sale), width)
  appendRequestBanner(out, sale, width)

  if (!documents.length) {
    renderChannelSection(out, sale?.channel || '-', width)
  } else {
    documents.forEach((doc, index) => {
      renderChannelSection(out, doc?.split_channel || doc?.channel || sale?.channel, width)
      for (const item of doc.items || []) {
        const detail = [String(item?.variant_name || '').trim(), decoratePrintedItemDetail(String(item?.note ?? item?.notes ?? item?.remark ?? item?.remarks ?? '').trim(), item)].filter(Boolean).join(' • ')
        out.push(...itemRow(decoratePrintedItemName(item), Number(item?.qty || 1), width, detail))
        if (index < documents.length || item !== (doc.items || []).at(-1)) out.push('')
      }
    })
  }

  out.push(...centerText('Thank you', width))
  return out.join('\n').replace(/\n{3,}/g, '\n\n')
}

export function renderBarSlipText(slip = {}, profile = {}) {
  const width = normalizeWidth(profile?.charsPerLine)
  const queueText = String(slip?.queue_text || '').trim()
  const customerText = String(slip?.customer_text || '').trim()
  const channelText = compactChannelLabel(slip?.channel_text || slip?.channel || '-', slip?.online_order_source).toUpperCase()
  const firstLine = [queueText, customerText, channelText].filter(Boolean).join(' - ') || '-'
  const productName = String(decoratePrintedItemName(slip) || '-').trim() || '-'
  const variantName = String(slip?.variant_name || '').trim()
  const noteText = decoratePrintedItemDetail(String(slip?.note ?? slip?.notes ?? slip?.remark ?? slip?.remarks ?? '').trim(), slip)
  const secondBase = variantName ? `${productName} (${variantName})` : productName
  const secondLine = noteText ? `${secondBase} - ${noteText}` : secondBase
  const lines = [
    ...wrapTextBlock(firstLine, width),
    ...wrapTextBlock(secondLine, width),
    ...wrapTextBlock(String(slip?.date_text || '-').trim() || '-', width),
  ]
  return lines.join('\n')
}

export function renderBarTicketText(slips = [], profile = {}) {
  const blocks = []
  for (const slip of slips || []) blocks.push(renderBarSlipText(slip, profile))
  return blocks.join('\n\n\n')
}

function trimIsoDateTime(value) {
  const raw = String(value || '').trim()
  if (!raw) return '-'
  return raw.replace('T', ' ').slice(0, 19)
}

export function renderCashierReportText(payload = {}, profile = {}) {
  const width = normalizeWidth(profile?.charsPerLine)
  const summary = payload?.summary || {}
  const cashiers = Array.isArray(payload?.cashiers) ? payload.cashiers : []
  const sales = Array.isArray(payload?.sales) ? payload.sales : []
  const date = String(payload?.date || '').trim() || '-'

  const out = [
    ...centerText('CASHIER REPORT', width),
    ...centerText('Semua transaksi outlet', width),
    ...centerText(`Date: ${date}`, width),
    divider(width),
    ...columns('Total transactions', String(summary?.transaction_count || 0), width),
    ...columns('Items sold', String(summary?.items_sold || 0), width),
    ...columns('Grand total', formatMoney(summary?.grand_total), width),
  ]

  if (cashiers.length) {
    out.push(divider(width))
    out.push(...centerText('CASHIER BREAKDOWN', width))
    cashiers.forEach((cashier) => {
      const name = String(cashier?.cashier_name || cashier?.cashier_id || '-').trim() || '-'
      out.push(...wrapTextBlock(name, width))
      out.push(...columns('Transactions', String(cashier?.transaction_count || 0), width))
      out.push(...columns('Grand total', formatMoney(cashier?.grand_total), width))
      out.push(divider(width, '.'))
    })
  }

  out.push(divider(width))

  if (!sales.length) {
    out.push('No sales.')
  } else {
    sales.forEach((sale, idx) => {
      out.push(...wrapTextBlock(`Sale # ${sale?.sale_number || '-'}${sale?.channel ? ` [${String(sale.channel).toUpperCase()}]` : ''}`, width))
      out.push(...columns('Cashier', String(sale?.cashier_name || '-'), width))
      out.push(...columns('Time', trimIsoDateTime(sale?.paid_at || sale?.created_at), width))
      out.push(divider(width, '.'))

      const items = Array.isArray(sale?.items) ? sale.items : []
      if (!items.length) out.push('No items.')
      items.forEach((item) => {
        const name = String(item?.product_name || '-').trim() || '-'
        const qty = Number(item?.qty || 0) || 1
        const noteParts = [
          String(item?.variant_name || '').trim(),
          String(item?.note ?? item?.notes ?? '').trim(),
        ].filter(Boolean)
        out.push(...itemRow(name, qty, width, noteParts.join(' • ')))
        out.push(...columns('Subtotal', formatMoney(item?.line_total), width))
      })

      out.push(divider(width, '.'))
      const displayChangeTotal = Math.max(0, Number(sale?.paid_total || 0) - Number(sale?.grand_total || 0)) || Number(sale?.change_total || 0)
      out.push(...columns('Total', formatMoney(sale?.grand_total), width))
      out.push(...columns('Paid', formatMoney(sale?.paid_total), width))
      out.push(...columns('Change', formatMoney(displayChangeTotal), width))
      if (Number(sale?.discount_total || 0) > 0) out.push(...columns('Discount', formatMoney(sale?.discount_total), width))
      if (Number(sale?.tax_total || 0) > 0) out.push(...columns('Tax', formatMoney(sale?.tax_total), width))
      if (Number(sale?.rounding_total || 0) !== 0) {
        const rounding = Number(sale?.rounding_total || 0)
        const label = rounding > 0 ? `Rounding (+${Math.abs(rounding)})` : `Rounding (-${Math.abs(rounding)})`
        out.push(...columns(label, formatMoney(rounding), width))
      }
      const payment = Array.isArray(sale?.payments) && sale.payments[0]
        ? (sale.payments[0]?.payment_method_name || sale?.payment_method_name || '-')
        : (sale?.payment_method_name || '-')
      out.push(...columns('Payment', String(payment), width))
      if (idx < sales.length - 1) out.push(divider(width))
    })
  }

  out.push(divider(width))
  out.push(...centerText('End Report', width))
  return out.join('\n').replace(/\n{3,}/g, '\n\n')
}

export function renderItemSoldReportText(payload = {}, profile = {}) {
  const width = normalizeWidth(profile?.charsPerLine)
  const summary = payload?.summary || {}
  const items = Array.isArray(payload?.items) ? payload.items : []
  const date = String(payload?.date || '').trim() || '-'

  const out = [
    ...centerText('ITEM SOLD REPORT', width),
    ...centerText('Per tanggal load cashier report', width),
    ...centerText(`Date: ${date}`, width),
    divider(width),
    ...columns('Item variants', String(summary?.item_count || items.length || 0), width),
    ...columns('Sold total', String(summary?.qty_total || 0), width),
    ...columns('Grand total', formatMoney(summary?.grand_total), width),
    divider(width),
  ]

  if (!items.length) {
    out.push('No item sold.')
  } else {
    items.forEach((item, idx) => {
      const itemName = String(item?.item || item?.product_name || '-').trim() || '-'
      const variantName = String(item?.variant || item?.variant_name || '').trim()
      const compactName = variantName ? `${itemName} - ${variantName}` : itemName
      const qty = Number(item?.qty || 0)
      const total = Number(item?.total || item?.grand_total || 0)

      out.push(...wrapTextBlock(compactName, width))
      out.push(...columns('Qty', String(qty), width))
      out.push(...columns('Total', formatMoney(total), width))
      if (idx < items.length - 1) out.push(divider(width, '.'))
    })
  }

  out.push(divider(width))
  out.push(...centerText('End Report', width))
  return out.join('\n').replace(/\n{3,}/g, '\n\n')
}

export function renderPizzaTicketText(lines = [], sale = {}, profile = {}, options = {}) {
  return renderTableTicketText(lines, sale, profile, {
    title: 'PIZZA',
    emptyText: 'Tidak ada item kategori Other untuk pizza print.',
    printerSettings: options?.printerSettings,
    splitDocuments: Array.isArray(options?.splitDocuments) ? options.splitDocuments : splitPizzaDocuments(sale, options?.printerSettings),
  })
}

export function renderTableTicketText(lines = [], sale = {}, profile = {}, options = {}) {
  const width = normalizeWidth(profile?.charsPerLine)
  const title = String(options?.title || 'SERVER')
  const emptyText = String(options?.emptyText || 'Tidak ada item Food & Drink untuk server print.')
  const providedDocs = Array.isArray(options?.splitDocuments) ? options.splitDocuments : null
  const documents = (providedDocs || splitTableDocuments(sale, options?.printerSettings)).filter((doc) => Array.isArray(doc?.items) && doc.items.length)
  const sourceSale = sale || {}
  const out = [
    ...centerText(title, width),
    ...columns(`Sale # ${getSaleNumberShort(sourceSale)}`, formatSaleDateTimeText(sourceSale?.created_at || sourceSale?.paid_at || sourceSale?.timestamp, sourceSale?.outlet?.timezone || 'Asia/Jakarta'), width),
  ]

  pushLabeledValue(out, 'Cashier', sourceSale?.cashier?.name || sourceSale?.cashier_name || sourceSale?.cashierName || '-', width)
  pushLabeledValue(out, 'Customer', sourceSale?.bill_name || sourceSale?.customer_name || '-', width)
  pushLabeledValue(out, 'Table Number', getSaleTableLabel(sourceSale), width)
  appendRequestBanner(out, sourceSale, width)

  if (!documents.length) {
    out.push(emptyText)
  } else {
    documents.forEach((doc) => {
      renderChannelSection(out, doc?.split_channel || doc?.channel || sourceSale?.channel, width)
      for (const item of doc.items || []) {
        const detail = [String(item?.variant_name || '').trim(), decoratePrintedItemDetail(String(item?.note ?? item?.notes ?? item?.remark ?? item?.remarks ?? '').trim(), item)].filter(Boolean).join(' • ')
        out.push(...itemRow(decoratePrintedItemName(item), Number(item?.qty || 1), width, detail))
        out.push('')
      }
    })
  }

  out.push(...centerText('Thank you', width))
  return out.join('\n').replace(/\n{3,}/g, '\n\n')
}

export const line = padLine
export const repeatLine = repeatText
export const repeatChars = repeatText
export const center = centerText
export const wrap = wrapTextBlock
export const kv = keyValueLines

export const thermalTextUtils = {
  normalizeText,
  normalizeWidth,
  padLine,
  rightText,
  repeatText,
  wrapLine,
  wrapText,
  wrapTextBlock,
  centerText,
  columns,
  divider,
  keyValueLines,
  itemRow,
  renderKitchenTicketText,
  renderBarSlipText,
  renderBarTicketText,
  renderTableTicketText,
  renderPizzaTicketText,
  renderCashierReportText,
  renderItemSoldReportText,
  line,
  repeatLine,
  repeatChars,
  center,
  wrap,
  kv,
}

export default thermalTextUtils
