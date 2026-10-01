import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, settled } from '@ember/test-helpers';
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
        assert.dom('[data-test-activity-error]').hasAttribute('headers', 'quickbooks-activity-2');
        assert.dom('#quickbooks-activity-2').hasText('Reconcile');
        assert.dom('#quickbooks-activity-2').hasAttribute('scope', 'row');
        assert.dom('thead th[scope="col"]').exists({ count: 12 });
        assert.dom('[data-test-activity-loading]').doesNotExist();
    });

    test('a stored Connection Config sentence points at Connection', async function (assert) {
        this.set('batches', [
            {
                uuid: 'import-sep-27',
                trigger: 'import',
                direction: 'inbound',
                error: 'QuickBooks is not connected. Connect from Connection Config.',
            },
        ]);

        await render(hbs`<QuickbooksActivity @batches={{this.batches}} />`);

        assert.dom('[data-test-activity-trigger]').hasText('Import customers');
        assert.dom('[data-test-activity-error]').hasText('QuickBooks is not connected. Connect from Connection.');
        assert.dom('[data-test-activity-error]').hasAttribute('headers', 'quickbooks-activity-0');
        assert.dom('#quickbooks-activity-0').hasAttribute('scope', 'row');
    });

    test('it shows the batch status', async function (assert) {
        this.set('batches', [{ uuid: 'skip', trigger: 'now', direction: 'outbound', status: 'skipped' }]);

        await render(hbs`<QuickbooksActivity @batches={{this.batches}} />`);

        assert.dom('[data-test-activity-status]').hasText('Skipped');
    });

    test('the empty state points to Connection and Actions', async function (assert) {
        this.set('batches', []);

        await render(hbs`<QuickbooksActivity @batches={{this.batches}} />`);

        assert.dom('[data-test-activity-empty]').hasText('No syncs yet. Connect on Connection, then choose Sync now on Actions or wait for the schedule.');
        assert.dom('[data-test-activity-load-failed]').doesNotExist();
        assert.dom('[data-test-activity-loading]').doesNotExist();
    });

    test('a successful empty load keeps the empty state', async function (assert) {
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:fetch', EmptyBatchesFetchStubService);
        this.owner.register('service:current-user', CurrentUserStubService);

        await render(hbs`<QuickbooksActivity />`);

        assert.dom('[data-test-activity-empty]').hasText('No syncs yet. Connect on Connection, then choose Sync now on Actions or wait for the schedule.');
        assert.dom('[data-test-activity-load-failed]').doesNotExist();
        assert.dom('[data-test-activity-loading]').doesNotExist();
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
        assert.dom('[data-test-activity-empty]').hasText('No syncs yet. Connect on Connection, then choose Sync now on Actions or wait for the schedule.');
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
