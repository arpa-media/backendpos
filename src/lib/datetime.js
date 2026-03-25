const DEFAULT_TZ = "Asia/Jakarta";

function toDate(value) {
  if (!value) return null;
  if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? null : d;
}

/**
 * Format datetime to "dd MMM yyyy HH:mm" (e.g. 17 Jan 2026 18:04)
 * - Default timezone: Asia/Jakarta
 * - Accepts ISO string or Date
 */
export function formatDateTime(value, opts = {}) {
  const tz = opts.timeZone || DEFAULT_TZ;
  const d = toDate(value);
  if (!d) return "-";

  const fmt = new Intl.DateTimeFormat("id-ID", {
    timeZone: tz,
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });

  // id-ID biasanya pakai "18.04", jadi kita ambil parts dan pakai ":" sesuai requirement
  const parts = fmt.formatToParts(d);
  const get = (type) => parts.find((p) => p.type === type)?.value || "";

  const day = get("day");
  const month = get("month");
  const year = get("year");
  const hour = get("hour");
  const minute = get("minute");

  return `${day} ${month} ${year} ${hour}:${minute}`.trim();
}

/**
 * Format datetime to "yyyy-MM-dd HH:mm:ss" in given timezone (default Asia/Jakarta).
 */
export function formatYmdHms(value, opts = {}) {
  const tz = opts.timeZone || DEFAULT_TZ;
  const d = toDate(value);
  if (!d) return "-";

  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
  }).formatToParts(d);

  const get = (type) => parts.find((p) => p.type === type)?.value || "";
  const yyyy = get("year");
  const mm = get("month");
  const dd = get("day");
  const hh = get("hour");
  const mi = get("minute");
  const ss = get("second");

  return `${yyyy}-${mm}-${dd} ${hh}:${mi}:${ss}`;
}


/**
 * Format date to HTML input value (yyyy-MM-dd) in given timezone.
 */
export function dateInputValue(value = new Date(), opts = {}) {
  const tz = opts.timeZone || DEFAULT_TZ;
  const d = toDate(value);
  if (!d) return "";

  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: tz,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(d);

  const get = (type) => parts.find((p) => p.type === type)?.value || "";
  const yyyy = get("year");
  const mm = get("month");
  const dd = get("day");

  return `${yyyy}-${mm}-${dd}`;
}

export function todayDateInputValue(opts = {}) {
  return dateInputValue(new Date(), opts);
}
