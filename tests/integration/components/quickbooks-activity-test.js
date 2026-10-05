import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class NotificationsStubService extends Service {
    serverError() {}
}

class EmptyBatchesFetchStubService extends Service {
    async get() {
        return { batches: [] };
    }
}

class FailedBatchesFetchStubService extends Service {
    async get() {
        throw new Error('batches failed');
    }
}

let finishPendingBatches = null;

class PendingBatchesFetchStubService extends Service {
    get() {
        return new Promise((resolve) => {
            finishPendingBatches = resolve;
        });
    }
}

class CurrentUserStubService extends Service {
    companyId = 'company-uuid';
}

module('Integration | Component | quickbooks-activity', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders outbound batches, an inbound import, and an error row', async function (assert) {
        this.set('batches', [
            { uuid: 'out', trigger: 'scheduled', direction: 'outbound', created: 2, aligned: 2, linked: 0 },
            { uuid: 'in', trigger: 'import', direction: 'inbound', created: 4, linked: 3 },
            { uuid: 'err', trigger: 'manual', direction: 'outbound', created: 0, error: 'QuickBooks timed out' },
        ]);

        await render(hbs`<QuickbooksActivity @batches={{this.batches}} />`);

        assert.dom("[data-direction='outbound']").exists({ count: 2 });
        assert.dom("[data-direction='outbound'] [data-test-activity-aligned]").hasText('2');
        assert.dom("[data-direction='outbound'] [data-test-activity-linked]").hasText('0');
        assert.dom("[data-direction='inbound'] [data-test-activity-created]").hasText('4');
        assert.dom("[data-direction='inbound'] [data-test-activity-linked]").hasText('3');
        assert.dom('[data-test-activity-error]').hasText('QuickBooks timed out');
        assert.dom('[data-test-activity-error]').hasClass('dark:text-red-400');
        assert.dom('[data-test-activity]').hasClass('overflow-x-auto');
        assert.dom('[data-test-activity] table').hasClass('whitespace-nowrap');
        assert.dom('[data-test-activity]').doesNotHaveClass('overflow-y-hidden');
        assert.dom('[data-test-activity]').doesNotHaveClass('h-screen');
        assert.dom('[data-test-activity-error]').hasAttribute('headers', 'quickbooks-activity-2');
        assert.dom('#quickbooks-activity-2').hasText('Reconcile');
        assert.dom('#quickbooks-activity-2').hasAttribute('scope', 'row');
        assert.dom('thead th[scope="col"]').exists({ count: 12 });
        assert.dom('[data-test-activity-loading]').doesNotExist();
    });

    test('stored Connection sentences point at Quickbooks Setup', async function (assert) {
        this.set('batches', [
            {
                uuid: 'import-sep-27',
                trigger: 'import',
                direction: 'inbound',
                error: 'QuickBooks is not connected. Connect from Connection Config.',
            },
            {
                uuid: 'reauth',
                trigger: 'now',
                direction: 'outbound',
                error: 'QuickBooks needs to be reconnected. Connect from Connection.',
            },
            {
                uuid: 'credentials',
                trigger: 'manual',
                direction: 'outbound',
                error: 'QuickBooks refused the app credentials. Check Client ID and Client secret on Connection.',
            },
        ]);

        await render(hbs`<QuickbooksActivity @batches={{this.batches}} />`);

        const errors = [...this.element.querySelectorAll('[data-test-activity-error]')].map((node) => node.textContent.trim());
        assert.deepEqual(errors, [
            'QuickBooks is not connected. Connect from Quickbooks Setup.',
            'QuickBooks needs to be reconnected. Connect from Quickbooks Setup.',
            'QuickBooks refused the app credentials. Check Client ID and Client secret on Quickbooks Setup.',
        ]);
        assert.dom(this.element.querySelector('[data-test-activity-trigger]')).hasText('Import customers');
        assert.dom(this.element.querySelector('[data-test-activity-error]')).hasAttribute('headers', 'quickbooks-activity-0');
        assert.dom('#quickbooks-activity-0').hasAttribute('scope', 'row');
    });

    test('it shows the batch status', async function (assert) {
        this.set('batches', [{ uuid: 'skip', trigger: 'now', direction: 'outbound', status: 'skipped' }]);

        await render(hbs`<QuickbooksActivity @batches={{this.batches}} />`);

        assert.dom('[data-test-activity-status]').hasText('Skipped');
    });

    test('the empty state points to Connection', async function (assert) {
        this.set('batches', []);

        await render(hbs`<QuickbooksActivity @batches={{this.batches}} />`);

        assert.dom('[data-test-activity-empty]').hasText('No syncs yet. Connect on Quickbooks Setup, then choose Sync now or wait for the schedule.');
        assert.dom('[data-test-activity-load-failed]').doesNotExist();
        assert.dom('[data-test-activity-loading]').doesNotExist();
    });

    test('a successful empty load keeps the empty state', async function (assert) {
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:fetch', EmptyBatchesFetchStubService);
        this.owner.register('service:current-user', CurrentUserStubService);

        await render(hbs`<QuickbooksActivity />`);

        assert.dom('[data-test-activity-empty]').hasText('No syncs yet. Connect on Quickbooks Setup, then choose Sync now or wait for the schedule.');
        assert.dom('[data-test-activity-load-failed]').doesNotExist();
        assert.dom('[data-test-activity-loading]').doesNotExist();
        assert.dom('#fleetbase-pagination').doesNotExist();
    });

    test('one page of batches does not show the pager', async function (assert) {
        this.set('batches', [{ uuid: 'only', trigger: 'now', direction: 'outbound', created: 1 }]);
        this.set('meta', { current_page: 1, last_page: 1, per_page: 25, total: 1 });

        await render(hbs`<QuickbooksActivity @batches={{this.batches}} @meta={{this.meta}} />`);

        assert.dom('[data-test-activity-row]').exists({ count: 1 });
        assert.dom('#fleetbase-pagination').doesNotExist();
    });

    test('changing page requests that page of 25 and replaces the rows', async function (assert) {
        const calls = [];
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:current-user', CurrentUserStubService);
        this.owner.register(
            'service:fetch',
            class extends Service {
                get(path, query, options) {
                    calls.push({ path, query, options });
                    const page = Number(query?.page) || 1;
                    if (page === 2) {
                        return Promise.resolve({
                            batches: [{ uuid: 'page-2', trigger: 'now', direction: 'outbound', created: 9 }],
                            meta: { current_page: 2, last_page: 2, per_page: 25, total: 26 },
                        });
                    }

                    return Promise.resolve({
                        batches: [{ uuid: 'page-1', trigger: 'scheduled', direction: 'outbound', created: 1 }],
                        meta: { current_page: 1, last_page: 2, per_page: 25, total: 26 },
                    });
                }
            }
        );

        await render(hbs`<QuickbooksActivity />`);

        assert.deepEqual(calls, [
            {
                path: 'batches',
                query: { company_uuid: 'company-uuid', page: 1, per_page: 25 },
                options: { namespace: 'quickbooks/int/v1' },
            },
        ]);
        assert.dom('[data-test-activity-row]').exists({ count: 1 });
        assert.dom('[data-test-activity-created]').hasText('1');
        assert.dom('#fleetbase-pagination').exists({ count: 1 });

        await click('#fleetbase-pagination-forward-button');

        assert.strictEqual(calls.length, 2);
        assert.deepEqual(calls[1], {
            path: 'batches',
            query: { company_uuid: 'company-uuid', page: 2, per_page: 25 },
            options: { namespace: 'quickbooks/int/v1' },
        });
        assert.true(calls.every((call) => call.query.per_page === 25));
        assert.dom('[data-test-activity-row]').exists({ count: 1 });
        assert.dom('[data-test-activity-created]').hasText('9');
        assert.dom('[data-test-activity-trigger]').hasText('Sync now');
    });

    test('it shows a loading sentence while batches are still loading', async function (assert) {
        finishPendingBatches = null;
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:fetch', PendingBatchesFetchStubService);
        this.owner.register('service:current-user', CurrentUserStubService);

        await render(hbs`<QuickbooksActivity />`);

        assert.dom('[data-test-activity-loading]').hasText('Loading sync activity.');
        assert.dom('[data-test-activity-empty]').doesNotExist();
        assert.dom('[data-test-activity-load-failed]').doesNotExist();
        assert.dom('[data-test-activity-row]').doesNotExist();

        finishPendingBatches({ batches: [] });
        await settled();

        assert.dom('[data-test-activity-loading]').doesNotExist();
        assert.dom('[data-test-activity-empty]').hasText('No syncs yet. Connect on Quickbooks Setup, then choose Sync now or wait for the schedule.');
    });

    test('a failed load does not show the empty state', async function (assert) {
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:fetch', FailedBatchesFetchStubService);
        this.owner.register('service:current-user', CurrentUserStubService);

        await render(hbs`<QuickbooksActivity />`);

        assert.dom('[data-test-activity-load-failed]').hasText('Sync activity could not be loaded.');
        assert.dom('[data-test-activity-empty]').doesNotExist();
        assert.dom('[data-test-activity-loading]').doesNotExist();
        assert.dom().doesNotIncludeText('No syncs yet');
    });
});
