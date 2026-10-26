import { toIsoDate } from './dates.js';

export function calendarDate(year, month, day = 1) {
    const value = `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    return toIsoDate(value);
}

export function utcCalendarDate(value) {
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(0);
    date.setUTCFullYear(year, month - 1, day);
    return date;
}

export function moveCalendarDay(value, days) {
    const date = utcCalendarDate(value);
    date.setUTCDate(date.getUTCDate() + days);
    return calendarDate(date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate()) ?? value;
}

export function moveCalendarMonth(value, months) {
    const [year, month, day] = value.split('-').map(Number);
    const index = Math.max(12, Math.min(9999 * 12 + 11, year * 12 + month - 1 + months));
    const nextYear = Math.floor(index / 12), nextMonth = index % 12 + 1;
    let nextDay = day;
    while (!calendarDate(nextYear, nextMonth, nextDay)) { nextDay--; }
    return calendarDate(nextYear, nextMonth, nextDay);
}

export function laosToday() {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Vientiane', calendar: 'gregory', numberingSystem: 'latn', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date()).map(part => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day}`;
}
