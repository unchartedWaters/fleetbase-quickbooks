import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import ModalsManagerStub from '../../helpers/modals-manager-stub';

// Characterization tests: they pin what the settings form shows and saves for blank,
// loaded and drafted values (QuickbooksSettings#present) so a refactor keeps them.

class NotificationsStubService extends Service {
    messages = [];

    success(message) {
        this.messages.push(['success', message]);
    }

    error(message) {
        this.messages.push(['error', message]);
    }

    serverError(error) {
        this.messages.push(['error', error?.message ?? String(error)]);
    }
}

class ActivityFetchStubService extends Service {
    get() {
        return Promise.resolve({ batches: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } });
    }
}

class CurrentUserStubService extends Service {
    companyId = 'company-uuid';
}

function saveButton() {
    return document.querySelector('#next-view-section-subheader-actions [data-test-save]');
}

module('Integration | Component | quickbooks-settings present characterization', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:fetch', ActivityFetchStubService);
        this.owner.register('service:current-user', CurrentUserStubService);
        this.owner.register('service:modals-manager', ModalsManagerStub);
        this.header = document.createElement('div');
        this.header.id = 'next-view-section-subheader';
        const actions = document.createElement('div');
        actions.id = 'next-view-section-subheader-actions';
        this.header.appendChild(actions);
        document.body.appendChild(this.header);
    });

    hooks.afterEach(function () {
        this.header?.remove();
    });

    test('blank values show and save the defaults', async function (assert) {
        this.set('settings', { client_id: 'id', environment: '', client_secret_set: true });
        this.set('sync', { interval_minutes: '', periodic_interval_hours: null, retry_limit: 5, default_backoff_seconds: 30 });
        this.set('saved', null);
        this.set('onSave', (payload) => this.set('saved', payload));

        await render(hbs`
            <QuickbooksSettings @settings={{this.settings}} @sync={{this.sync}} @onSave={{this.onSave}} />
        `);

        assert.dom("[data-test-field='environment']").hasValue('production');
        assert.dom("[data-test-sync='interval_minutes']").hasValue('5');
        assert.dom("[data-test-sync='periodic_interval_hours']").hasValue('24');
        assert.dom("[data-test-sync='customer_conflict']").hasValue('fleetbase');
        assert.dom("[data-test-sync='customer_direction']").hasValue('both');

        await click(saveButton());
        assert.strictEqual(this.saved.auth.environment, 'production');
        assert.strictEqual(this.saved.auth.client_id, 'id');
        assert.strictEqual(this.saved.sync.interval_minutes, 5);
        assert.strictEqual(this.saved.sync.periodic_interval_hours, 24);
        assert.strictEqual(this.saved.sync.retry_limit, 5);
        assert.strictEqual(this.saved.sync.default_backoff_seconds, 30);
        ['customer', 'invoice', 'payment', 'wallet'].forEach((entity) => {
            assert.strictEqual(this.saved.sync[`${entity}_conflict`], 'fleetbase');
            assert.strictEqual(this.saved.sync[`${entity}_reference`], 'fleetbase');
            assert.strictEqual(this.saved.sync[`${entity}_direction`], 'both');
            assert.true(this.saved.sync[`${entity}_enabled`]);
        });
    });

    test('a loaded value is shown and a string number saves as a number', async function (assert) {
        this.set('settings', { client_id: 'loaded-id', environment: 'sandbox', client_secret_set: true });
        this.set('sync', {
            interval_minutes: '7',
            periodic_interval_hours: 12,
            retry_limit: '3',
            default_backoff_seconds: 45,
            customer_conflict: 'quickbooks',
            customer_reference: 'fleetbase',
        });
        this.set('saved', null);
        this.set('onSave', (payload) => this.set('saved', payload));

        await render(hbs`
            <QuickbooksSettings @settings={{this.settings}} @sync={{this.sync}} @onSave={{this.onSave}} />
        `);

        assert.dom("[data-test-field='client_id']").hasValue('loaded-id');
        assert.dom("[data-test-field='environment']").hasValue('sandbox');
        assert.dom("[data-test-sync='interval_minutes']").hasValue('7');
        assert.dom("[data-test-sync='periodic_interval_hours']").hasValue('12');
        assert.dom("[data-test-sync='customer_conflict']").hasValue('quickbooks');

        await click(saveButton());
        assert.strictEqual(this.saved.auth.client_id, 'loaded-id');
        assert.strictEqual(this.saved.auth.environment, 'sandbox');
        assert.strictEqual(this.saved.sync.interval_minutes, 7);
        assert.strictEqual(this.saved.sync.periodic_interval_hours, 12);
        assert.strictEqual(this.saved.sync.retry_limit, 3);
        assert.strictEqual(this.saved.sync.default_backoff_seconds, 45);
        // Primary for a loaded entity comes from its conflict value, for both keys.
        assert.strictEqual(this.saved.sync.customer_conflict, 'quickbooks');
        assert.strictEqual(this.saved.sync.customer_reference, 'quickbooks');
    });

    test('a draft overrides the loaded value and the default, and a saved draft shows the loaded Primary again', async function (assert) {
        this.set('settings', { client_id: 'loaded-id', environment: 'sandbox', client_secret_set: true });
        this.set('sync', {
            interval_minutes: 7,
            retry_limit: 5,
            default_backoff_seconds: 30,
            customer_conflict: 'quickbooks',
            customer_reference: 'quickbooks',
        });
        this.set('saved', null);
        this.set('onSave', (payload) => this.set('saved', payload));

        await render(hbs`
            <QuickbooksSettings @settings={{this.settings}} @sync={{this.sync}} @onSave={{this.onSave}} />
        `);

        await fillIn("[data-test-field='client_id']", ' drafted-id ');
        await fillIn("[data-test-sync='interval_minutes']", '9');
        await fillIn("[data-test-sync='periodic_interval_hours']", '6');
        await fillIn("[data-test-sync='customer_conflict']", 'fleetbase');
        assert.dom("[data-test-sync='customer_conflict']").hasValue('fleetbase');

        await click(saveButton());
        assert.strictEqual(this.saved.auth.client_id, 'drafted-id');
        assert.strictEqual(this.saved.sync.interval_minutes, 9);
        assert.strictEqual(this.saved.sync.periodic_interval_hours, 6);
        assert.strictEqual(this.saved.sync.customer_conflict, 'fleetbase');
        assert.strictEqual(this.saved.sync.customer_reference, 'fleetbase');

        // A successful save clears the draft, so the loaded values show again.
        assert.dom("[data-test-sync='customer_conflict']").hasValue('quickbooks');
        assert.dom("[data-test-sync='interval_minutes']").hasValue('7');
        assert.dom("[data-test-sync='periodic_interval_hours']").hasValue('24');
    });

    test('a blank draft is not replaced by the default and fails validation', async function (assert) {
        this.set('settings', { client_id: 'id', environment: 'sandbox', client_secret_set: true });
        this.set('sync', { retry_limit: 5, default_backoff_seconds: 30 });
        this.set('saved', null);
        this.set('onSave', (payload) => this.set('saved', payload));

        await render(hbs`
            <QuickbooksSettings @settings={{this.settings}} @sync={{this.sync}} @onSave={{this.onSave}} />
        `);

        assert.dom("[data-test-sync='interval_minutes']").hasValue('5');
        await fillIn("[data-test-sync='interval_minutes']", '');
        assert.dom("[data-test-sync='interval_minutes']").hasValue('');

        await click(saveButton());
        assert.strictEqual(this.saved, null);
        assert.dom("[data-test-error='interval_minutes']").exists();
        assert.dom("[data-test-error='periodic_interval_hours']").doesNotExist();
    });
});
