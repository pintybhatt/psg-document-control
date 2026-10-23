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
