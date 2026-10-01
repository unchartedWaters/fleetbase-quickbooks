import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

function assertDisconnectFollowsConnect(assert) {
    const connect = document.querySelector('[data-test-connect]');
    const disconnect = document.querySelector('[data-test-disconnect]');
    const connectWrap = connect.closest('.btn-wrapper');
    const disconnectWrap = disconnect.closest('.btn-wrapper');

    assert.strictEqual(connectWrap.parentElement, disconnectWrap.parentElement, 'Connect and Disconnect share one row');
    assert.strictEqual(connectWrap.nextElementSibling, disconnectWrap, 'Disconnect is immediately beside Connect');
}

module('Integration | Component | quickbooks-connection', function (hooks) {
    setupRenderingTest(hooks);

    test('embedded actions keep sync, reconcile, and import without a second disconnect', async function (assert) {
        this.set('connection', null);
        await render(hbs`<QuickbooksConnection @variant="embedded" @connection={{this.connection}} @configured={{true}} />`);

        assert.dom('[data-test-connection-state]').doesNotExist();
        assert.dom('[data-test-actions]').includesText('Sync now sends customers, invoices, payments, and wallets that are waiting.');
        assert.dom('[data-test-actions]').includesText('Reconcile syncs one page of invoices already in Fleetbase.');
        assert.dom('[data-test-import-rules]').hasText('Import customers skips inactive customers and sub-customers. Existing Fleetbase names, emails, and phones are left as they are.');
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-reconcile]').isDisabled();
        assert.dom('[data-test-import]').isDisabled();
        assert.dom('[data-test-disconnect]').doesNotExist();
        assert.dom('[data-test-connect]').doesNotExist();
        assert.dom('[data-test-credentials-missing]').doesNotExist();

        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        assert.dom('[data-test-sync-now]').isNotDisabled();
        assert.dom('[data-test-reconcile]').isNotDisabled();
        assert.dom('[data-test-import]').isNotDisabled();
        assert.dom('[data-test-disconnect]').doesNotExist();

        this.set('connection', { realm_id: '123', needs_reauth: true });
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-disconnect]').doesNotExist();
    });

    test('actions stays on sync, reconcile, and import, and points to Connection', async function (assert) {
        this.set('connection', null);
        await render(hbs`<QuickbooksConnection @connection={{this.connection}} @configured={{true}} />`);
        assert.dom('[data-test-connection-state]').hasAttribute('data-test-connection-state', 'disconnected');
        assert.dom('[data-test-disconnected]').includesText('Connection');
        assert.dom('[data-test-disconnected]').includesText('syncs customers, invoices, payments, and wallets with QuickBooks Online');
        assert.dom('[data-test-disconnected]').doesNotIncludeText('sends');
        assert.dom().includesText('Sync now sends customers, invoices, payments, and wallets that are waiting.');
        assert.dom().includesText('Reconcile syncs one page of invoices already in Fleetbase.');
        assert.dom().includesText('It does not go through every customer or wallet.');
        assert.dom().includesText('It does not import QuickBooks invoices that are not already in Fleetbase, and it does not delete QuickBooks records.');
        assert.dom().doesNotIncludeText('Schedule → Sync is off');
        assert.dom().doesNotIncludeText('Enable schedule');
        assert.dom('[data-test-import-rules]').hasText('Import customers skips inactive customers and sub-customers. Existing Fleetbase names, emails, and phones are left as they are.');
        assert.dom().doesNotIncludeText('compares every');
        assert.dom('[data-test-disconnected]').doesNotIncludeText('Open Actions');
        assert.dom('[data-test-connect]').doesNotExist();
        assert.dom('[data-test-disconnect]').doesNotExist();
        assert.dom('[data-test-import-checkbox]').doesNotExist();
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-reconcile]').isDisabled();
        assert.dom('[data-test-import]').isDisabled();

        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        assert.dom('[data-test-connected]').includesText('123');
        assert.dom('[data-test-connected]').doesNotIncludeText('Home currency');
        assert.dom('[data-test-sync-now]').isNotDisabled();
        assert.dom('[data-test-reconcile]').isNotDisabled();
        assert.dom('[data-test-import]').isNotDisabled();
        assert.dom('[data-test-connect]').doesNotExist();
        assert.dom('[data-test-disconnect]').exists();

        this.set('connection', { realm_id: '123', needs_reauth: true });
        assert.dom('[data-test-reauth]').includesText('Connection');
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-connect]').doesNotExist();
        assert.dom('[data-test-disconnect]').exists();
    });

    test('sync, reconcile, and import stay disabled when QuickBooks is not configured', async function (assert) {
        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        await render(hbs`<QuickbooksConnection @connection={{this.connection}} @configured={{false}} />`);
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-reconcile]').isDisabled();
        assert.dom('[data-test-import]').isDisabled();
        assert.dom('[data-test-credentials-missing]').hasText('Enter Client ID and Client secret on Connection before these actions.');
    });

    test('connect sends import_customers from Data Resolution and defaults off', async function (assert) {
        this.set('connection', null);
        this.set('payload', null);
        this.set('importCustomers', false);
        this.set('onConnect', (payload) => this.set('payload', payload));
        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @importCustomers={{this.importCustomers}} @onConnect={{this.onConnect}} />`);

        assert.dom('[data-test-import-checkbox]').doesNotExist();
        assert.dom('[data-test-connect]').exists();
        assert.dom('[data-test-sync-now]').doesNotExist();
        await click('[data-test-connect]');
        assert.deepEqual(this.payload, { import_customers: false });

        this.set('importCustomers', true);
        await click('[data-test-connect]');
        assert.deepEqual(this.payload, { import_customers: true });
    });

    test('reconcile and import disable themselves while a run is in progress', async function (assert) {
        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        await render(hbs`<QuickbooksConnection @connection={{this.connection}} @configured={{true}} @busy={{true}} />`);
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-reconcile]').isDisabled();
        assert.dom('[data-test-import]').isDisabled();
    });

    test('Connection shows connect when disconnected and disconnect when connected', async function (assert) {
        this.set('tested', false);
        this.set('disconnected', false);
        this.set('onTest', async () => this.set('tested', true));
        this.set('onDisconnect', () => {
            this.set('disconnected', true);
            this.set('connection', null);
        });
        this.set('connection', null);

        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @onTest={{this.onTest}} @onDisconnect={{this.onDisconnect}} />`);

        assert.dom('[data-test-test]').isDisabled();
        assert.dom('[data-test-disconnected]').includesText('Choose Connect to QuickBooks');
        assert.dom('[data-test-disconnected]').includesText('syncs customers, invoices, payments, and wallets with QuickBooks Online');
        assert.dom('[data-test-disconnected]').doesNotIncludeText('sends');
        assert.dom('[data-test-disconnected]').doesNotIncludeText('Open Actions');
        assert.dom('[data-test-connect]').exists();
        assert.dom('[data-test-connect]').isNotDisabled();
        assert.dom('[data-test-connect]').hasClass('btn-sm');
        assert.dom('[data-test-disconnect]').exists();
        assert.dom('[data-test-disconnect]').isDisabled();
        assert.dom('[data-test-disconnect]').hasClass('btn-sm');
        assert.dom('[data-test-disconnect]').includesText('Disconnect');
        assertDisconnectFollowsConnect(assert);

        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        assert.dom('[data-test-disconnect]').isNotDisabled();
        assert.dom('[data-test-connect]').doesNotExist();
        assert.dom('[data-test-import-checkbox]').doesNotExist();
        assert.false(this.disconnected, 'disconnect waits for a click');

        await click('[data-test-test]');
        assert.true(this.tested, 'onTest runs when Test connection is clicked');
        assert.false(this.disconnected, 'testing the connection does not disconnect it');

        this.set('connection', { realm_id: '123', needs_reauth: true });
        assert.dom('[data-test-reauth]').exists();
        assert.dom('[data-test-disconnect]').includesText('Disconnect');
        assert.dom('[data-test-disconnect]').doesNotIncludeText('unsubscribe');
        assert.dom('[data-test-disconnect]').isNotDisabled();
        assert.dom('[data-test-connect]').exists();
        assert.dom('[data-test-connect]').hasClass('btn-sm');
        assert.dom('[data-test-disconnect]').hasClass('btn-sm');
        assert.dom('[data-test-test]').exists();
        assertDisconnectFollowsConnect(assert);

        await click('[data-test-disconnect]');
        assert.true(this.disconnected);
        assert.dom('[data-test-disconnected]').includesText('Not connected');
        assert.dom('[data-test-connect]').exists();
        assert.dom('[data-test-disconnect]').isDisabled();
        assertDisconnectFollowsConnect(assert);
    });

    test('connected status explains invoice and wallet currency rules', async function (assert) {
        this.set('connection', { realm_id: '123', environment: 'sandbox', home_currency: 'USD' });

        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} />`);

        assert.dom('[data-test-connected]').includesText('Connected to QuickBooks organization 123 (sandbox).');
        assert.dom('[data-test-connected]').doesNotIncludeText('Home currency');
        assert.dom('[data-test-home-currency]').includesText('Home currency is USD.');
        assert.dom('[data-test-home-currency]').includesText('In QuickBooks: gear → Account and settings → Advanced → Currency.');
        assert.dom('[data-test-home-currency]').includesText('Invoices in another currency are not sent and show as failed in Activity.');
        assert.dom('[data-test-home-currency]').includesText('A wallet in another currency fails only when Fleetbase is creating a new QuickBooks account and QuickBooks is not Primary.');
        assert.dom('[data-test-home-currency]').includesText('An existing wallet is still sent.');
        assert.dom('[data-test-home-currency]').includesText('When QuickBooks is Primary, the account currency is copied onto the wallet.');
        assert.dom('[data-test-disconnect]').exists();
    });

    test('Actions stays loading until a connection result arrives', async function (assert) {
        await render(hbs`<QuickbooksConnection @configured={{true}} />`);

        assert.dom('[data-test-connection-state]').hasAttribute('data-test-connection-state', 'loading');
        assert.dom('[data-test-connection-loading]').hasText('Loading QuickBooks connection.');
        assert.dom('[data-test-disconnected]').doesNotExist();
        assert.dom('[data-test-status-unavailable]').doesNotExist();
        assert.dom('[data-test-sync-now]').doesNotExist();
        assert.dom('[data-test-reconcile]').doesNotExist();
        assert.dom('[data-test-import]').doesNotExist();
        assert.dom('[data-test-disconnect]').doesNotExist();
        assert.dom().doesNotIncludeText('Not connected');

        this.set('connection', null);
        await render(hbs`<QuickbooksConnection @connection={{this.connection}} @configured={{true}} />`);

        assert.dom('[data-test-connection-state]').hasAttribute('data-test-connection-state', 'disconnected');
        assert.dom('[data-test-connection-loading]').doesNotExist();
        assert.dom('[data-test-disconnected]').includesText('Not connected');
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-reconcile]').isDisabled();
        assert.dom('[data-test-import]').isDisabled();

        await render(hbs`<QuickbooksConnection @loadFailed={{true}} @configured={{true}} />`);

        assert.dom('[data-test-connection-state]').hasAttribute('data-test-connection-state', 'unavailable');
        assert.dom('[data-test-connection-loading]').doesNotExist();
        assert.dom('[data-test-status-unavailable]').hasText('QuickBooks status could not be loaded.');
        assert.dom('[data-test-disconnected]').doesNotExist();
        assert.dom().doesNotIncludeText('Not connected');
        assert.dom('[data-test-sync-now]').isDisabled();
    });

    test('Connection stays loading until a connection result arrives', async function (assert) {
        await render(hbs`<QuickbooksConnection @variant="status" />`);

        assert.dom('[data-test-connection-state]').hasAttribute('data-test-connection-state', 'loading');
        assert.dom('[data-test-connection-loading]').hasText('Loading QuickBooks connection.');
        assert.dom('[data-test-disconnected]').doesNotExist();
        assert.dom('[data-test-connect]').doesNotExist();
        assert.dom('[data-test-disconnect]').doesNotExist();
        assert.dom('[data-test-import-checkbox]').doesNotExist();
        assert.dom('[data-test-test]').isDisabled();
        assert.dom().doesNotIncludeText('Not connected');

        this.set('connection', null);
        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} />`);

        assert.dom('[data-test-connection-state]').hasAttribute('data-test-connection-state', 'disconnected');
        assert.dom('[data-test-connection-loading]').doesNotExist();
        assert.dom('[data-test-disconnected]').includesText('Not connected');
        assert.dom('[data-test-connect]').isNotDisabled();
        assert.dom('[data-test-disconnect]').isDisabled();
        assert.dom('[data-test-import-checkbox]').doesNotExist();
        assertDisconnectFollowsConnect(assert);

        await render(hbs`<QuickbooksConnection @variant="status" @loadFailed={{true}} />`);

        assert.dom('[data-test-connection-state]').hasAttribute('data-test-connection-state', 'unavailable');
        assert.dom('[data-test-connection-loading]').doesNotExist();
        assert.dom('[data-test-status-unavailable]').hasText('QuickBooks status could not be loaded.');
        assert.dom('[data-test-connect]').doesNotExist();
        assert.dom('[data-test-disconnect]').doesNotExist();
        assert.dom().doesNotIncludeText('Not connected');
    });

    test('a failed status load does not offer connect', async function (assert) {
        await render(hbs`<QuickbooksConnection @variant="status" @loadFailed={{true}} />`);

        assert.dom('[data-test-connection-state]').hasAttribute('data-test-connection-state', 'unavailable');
        assert.dom('[data-test-status-unavailable]').hasText('QuickBooks status could not be loaded.');
        assert.dom('[data-test-disconnected]').doesNotExist();
        assert.dom('[data-test-connect]').doesNotExist();
        assert.dom('[data-test-disconnect]').doesNotExist();
        assert.dom('[data-test-import-checkbox]').doesNotExist();
        assert.dom().doesNotIncludeText('Not connected');
    });

    test('a failed actions load does not say not connected', async function (assert) {
        await render(hbs`<QuickbooksConnection @loadFailed={{true}} @configured={{true}} />`);

        assert.dom('[data-test-status-unavailable]').hasText('QuickBooks status could not be loaded.');
        assert.dom('[data-test-disconnected]').doesNotExist();
        assert.dom('[data-test-disconnect]').doesNotExist();
        assert.dom().doesNotIncludeText('Not connected');
        assert.dom('[data-test-sync-now]').isDisabled();
    });

    test('Actions disconnect calls the disconnect endpoint and shows not connected', async function (assert) {
        class NotificationsStubService extends Service {
            messages = [];

            success(message) {
                this.messages.push(['success', message]);
            }

            serverError(error) {
                this.messages.push(['error', error?.message ?? String(error)]);
            }
        }

        class FetchStubService extends Service {
            posts = [];

            async post(path, body) {
                this.posts.push({ path, body });

                return { disconnected: true };
            }
        }

        class CurrentUserStubService extends Service {
            companyId = 'company-uuid';
        }

        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:current-user', CurrentUserStubService);
        this.set('connection', { realm_id: '123', environment: 'sandbox' });

        await render(hbs`<QuickbooksConnection @connection={{this.connection}} @configured={{true}} />`);

        assert.dom('[data-test-connected]').exists();
        assert.dom('[data-test-disconnect]').exists();
        assert.dom('[data-test-connect]').doesNotExist();
        assert.deepEqual(this.owner.lookup('service:fetch').posts, []);

        await click('[data-test-disconnect]');

        assert.deepEqual(this.owner.lookup('service:fetch').posts, [{ path: 'disconnect', body: { company_uuid: 'company-uuid' } }]);
        assert.deepEqual(this.owner.lookup('service:notifications').messages, [['success', 'QuickBooks disconnected.']]);
        assert.false(this.owner.lookup('service:notifications').messages.some((entry) => /unsubscribe|intuit/i.test(String(entry[1]))));
        assert.dom('[data-test-disconnected]').includesText('Not connected');
        assert.dom('[data-test-disconnect]').doesNotExist();
        assert.dom('[data-test-sync-now]').isDisabled();
    });
});
