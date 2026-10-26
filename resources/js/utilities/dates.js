/** Validate ISO date-only business values without parsing them as timestamps. */
export function toIsoDate(value) {
    if (typeof value !== 'string' || value.length !== 10 || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return null;
    }

    const [year, month, day] = value.split('-').map(Number);
    const isLeapYear = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
    const daysPerMonth = [31, isLeapYear ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    return year > 0 && month >= 1 && month <= 12 && day >= 1 && day <= daysPerMonth[month - 1] ? value : null;
}

export function formatDate(value, fallback = '—') {
    const isoDate = toIsoDate(value);

    return isoDate ? isoDate.split('-').reverse().join('/') : fallback;
}

/** Parse only the explicit day/month/year format used in controls. */
export function parseDisplayDate(value) {
    if (typeof value !== 'string' || !/^\d{2}\/\d{2}\/\d{4}$/.test(value)) { return null; }
    return toIsoDate(value.split('/').reverse().join('-'));
}

/** Audit timestamps retain their unambiguous server calendar date. */
export function formatDateTime(value, fallback = '—') {
    if (typeof value !== 'string') { return fallback; }
    const date = formatDate(value.slice(0, 10), fallback);
    const time = value.match(/[T ](\d{2}:\d{2})/);
    return date === fallback ? fallback : `${date}${time ? ` · ${time[1]} UTC` : ''}`;
}
