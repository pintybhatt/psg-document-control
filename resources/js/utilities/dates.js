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

const timestampFormatter = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Vientiane', calendar: 'gregory', numberingSystem: 'latn',
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
});

/** Display system timestamps in Laos; timezone-less system values are stored UTC. */
export function formatDateTime(value, fallback = '—') {
    if (typeof value !== 'string') { return fallback; }
    if (toIsoDate(value)) { return formatDate(value, fallback); }
    const match = value.match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})?$/);
    if (!match || !toIsoDate(match[1]) || Number(match[2]) > 23 || Number(match[3]) > 59 || Number(match[4] ?? 0) > 59) { return fallback; }
    const timestamp = new Date(value.replace(' ', 'T').replace(/(\.\d{3})\d+/, '$1') + (match[5] ? '' : 'Z'));
    if (Number.isNaN(timestamp.getTime())) { return fallback; }
    const parts = Object.fromEntries(timestampFormatter.formatToParts(timestamp).map(part => [part.type, part.value]));
    return `${parts.day}/${parts.month}/${parts.year.padStart(4, '0')}, ${parts.hour}:${parts.minute}`;
}
