import { describe, expect, it, vi } from 'vitest';
import { formatDate, toIsoDate, parseDisplayDate, formatDateTime } from '../../resources/js/utilities/dates.js';

describe('date-only business values', () => {
    it.each([
        ['2026-10-23', '23/10/2026'],
        ['2024-02-29', '29/02/2024'],
        ['2000-02-29', '29/02/2000'],
        ['0001-01-01', '01/01/0001'],
        ['9999-12-31', '31/12/9999'],
    ])('formats %s while retaining its exact ISO value', (value, displayed) => {
        expect(toIsoDate(value)).toBe(value);
        expect(formatDate(value)).toBe(displayed);
    });

    it.each([null, undefined, '', 'invalid', '2026-2-3', '23/10/2026', '2026-02-29', '1900-02-29', '2026-04-31', '0000-01-01', '2026-00-01', '2026-13-01', '2026-01-00', '2026-01-32', '2026-10-23\n', '2026-10-23T00:00:00Z', 12345, new Date('invalid')])('handles missing or invalid values safely: %s', (value) => {
        expect(toIsoDate(value)).toBeNull();
        expect(formatDate(value)).toBe('—');
        expect(formatDate(value, 'Not available')).toBe('Not available');
    });

    it.each(['Asia/Bangkok', 'America/Los_Angeles', 'Pacific/Kiritimati'])('does not shift a date-only value in %s', (timezone) => {
        vi.stubEnv('TZ', timezone);

        try {
            expect(formatDate('2026-01-01')).toBe('01/01/2026');
            expect(toIsoDate('2026-01-01')).toBe('2026-01-01');
        } finally {
            vi.unstubAllEnvs();
        }
    });
});

describe('explicit day/month/year entry', () => {
    it.each([
        ['03/04/2026', '2026-04-03'], ['12/11/2026', '2026-11-12'], ['29/02/2024', '2024-02-29'],
        ['29/02/2026', null], ['31/04/2026', null], ['04/13/2026', null], ['1/2/2026', null], ['2026-04-03', null],
    ])('parses %s without guessing the month: %s', (value, expected) => {
        expect(parseDisplayDate(value)).toBe(expected);
    });
    it('formats audit timestamps consistently with date-only values', () => {
        expect(formatDateTime('2026-04-03T09:15:00.000000Z')).toBe('03/04/2026 · 09:15 UTC');
        expect(formatDateTime(null)).toBe('—');
    });
});
