import { module, test } from 'qunit';
import { activityRows } from '@unchartedwaters/quickbooks-engine/utils/activity-view';

// Characterization tests: they pin the rows activityRows() returns today so a
// refactor of activity-view.js can be shown to keep the same output.
module('Unit | Utility | activity-view characterization', () => {
    test('a row has the same keys in the same order', (assert) => {
        const [row] = activityRows([{ uuid: 'b1', trigger: 'now' }]);

        assert.deepEqual(Object.keys(row), [
            'id',
            'headerId',
            'trigger',
            'direction',
            'status',
            'created',
            'aligned',
            'linked',
            'skipped',
            'updated',
            'unmatched',
            'voided',
            'failed',
            'results',
            'error',
            'when',
        ]);
    });

    test('no batches and an empty list give no rows', (assert) => {
        assert.deepEqual(activityRows(), []);
        assert.deepEqual(activityRows([]), []);
    });

    test('the id is the uuid, then the trigger with the started or finished stamp', (assert) => {
        const rows = activityRows([
            { uuid: 'batch-1', trigger: 'now', started_at: '2026-01-01T00:00:00Z' },
            { uuid: '', trigger: 'now', started_at: '2026-01-02T00:00:00Z', finished_at: '2026-01-03T00:00:00Z' },
            { trigger: 'import', started_at: '', finished_at: '2026-01-03T00:00:00Z' },
            { trigger: 'catalog', created_at: '2026-01-04T00:00:00Z' },
            { started_at: '2026-01-05T00:00:00Z' },
            { uuid: null, trigger: 'drain', started_at: null, finished_at: null },
        ]);

        assert.deepEqual(
            rows.map((row) => row.id),
            ['batch-1', 'now-2026-01-02T00:00:00Z', 'import-2026-01-03T00:00:00Z', 'catalog-', 'undefined-2026-01-05T00:00:00Z', 'drain-']
        );
        assert.deepEqual(
            rows.map((row) => row.headerId),
            ['quickbooks-activity-0', 'quickbooks-activity-1', 'quickbooks-activity-2', 'quickbooks-activity-3', 'quickbooks-activity-4', 'quickbooks-activity-5']
        );
    });

    test('each count prefers the plain field, then the _count field, then zero', (assert) => {
        const names = ['created', 'aligned', 'linked', 'skipped', 'updated', 'unmatched', 'voided', 'failed'];
        const plain = {};
        const counted = {};
        const zeroWins = {};
        const nullFalls = {};
        const missing = {};
        const kept = {};
        names.forEach((name, index) => {
            plain[name] = index + 1;
            plain[`${name}_count`] = 100;
            counted[`${name}_count`] = index + 10;
            zeroWins[name] = 0;
            zeroWins[`${name}_count`] = 7;
            nullFalls[name] = null;
            nullFalls[`${name}_count`] = index + 20;
            missing[name] = undefined;
            missing[`${name}_count`] = null;
            kept[name] = '';
            kept[`${name}_count`] = 9;
        });
        const rows = activityRows([plain, counted, zeroWins, nullFalls, missing, kept, { created_count: '3', failed: false }]);
        const counts = (row) => names.map((name) => row[name]);

        assert.deepEqual(counts(rows[0]), [1, 2, 3, 4, 5, 6, 7, 8]);
        assert.deepEqual(counts(rows[1]), [10, 11, 12, 13, 14, 15, 16, 17]);
        assert.deepEqual(counts(rows[2]), [0, 0, 0, 0, 0, 0, 0, 0]);
        assert.deepEqual(counts(rows[3]), [20, 21, 22, 23, 24, 25, 26, 27]);
        assert.deepEqual(counts(rows[4]), [0, 0, 0, 0, 0, 0, 0, 0]);
        assert.deepEqual(counts(rows[5]), ['', '', '', '', '', '', '', '']);
        assert.strictEqual(rows[6].created, '3');
        assert.false(rows[6].failed);
        assert.strictEqual(rows[6].linked, 0);
    });

    test('an error is a non-empty string, any other truthy value, or null', (assert) => {
        const detail = { message: 'Boom' };
        const rows = activityRows([
            { uuid: '1', error: 'timeout' },
            { uuid: '2', error: '' },
            { uuid: '3' },
            { uuid: '4', error: null },
            { uuid: '5', error: 0 },
            { uuid: '6', error: false },
            { uuid: '7', error: detail },
            { uuid: '8', error: '   ' },
            { uuid: '9', error: 3 },
        ]);

        assert.strictEqual(rows[0].error, 'timeout');
        assert.strictEqual(rows[1].error, null);
        assert.strictEqual(rows[2].error, null);
        assert.strictEqual(rows[3].error, null);
        assert.strictEqual(rows[4].error, null);
        assert.strictEqual(rows[5].error, null);
        assert.strictEqual(rows[6].error, detail);
        assert.strictEqual(rows[7].error, '   ');
        assert.strictEqual(rows[8].error, 3);
    });

    test('direction defaults to outbound, status to empty, and trigger is passed through', (assert) => {
        const rows = activityRows([
            { uuid: '1', trigger: 'import', direction: 'inbound', status: 'failed' },
            { uuid: '2', direction: '', status: null },
            { uuid: '3', direction: null, status: 0 },
            { uuid: '4', trigger: '' },
        ]);

        assert.deepEqual(
            rows.map((row) => [row.trigger, row.direction, row.status]),
            [
                ['import', 'inbound', 'failed'],
                [undefined, 'outbound', ''],
                [undefined, 'outbound', ''],
                ['', 'outbound', ''],
            ]
        );
    });

    test('when uses the finished time, then the created time, and keeps an unreadable value as text', (assert) => {
        const finished = '2026-03-01T10:05:00Z';
        const created = '2026-03-01T09:00:00Z';
        const rows = activityRows([
            { uuid: '1', finished_at: finished, created_at: created, started_at: '2026-01-01T00:00:00Z' },
            { uuid: '2', finished_at: '', created_at: created },
            { uuid: '3', started_at: '2026-01-01T00:00:00Z' },
            { uuid: '4', finished_at: 'not a date' },
            { uuid: '5', created_at: 0 },
        ]);

        assert.strictEqual(rows[0].when, new Date(finished).toLocaleString());
        assert.strictEqual(rows[1].when, new Date(created).toLocaleString());
        assert.strictEqual(rows[2].when, '');
        assert.strictEqual(rows[3].when, 'not a date');
        assert.strictEqual(rows[4].when, '');
    });
});
