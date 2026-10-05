import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import ModalsManagerStub from '../../helpers/modals-manager-stub';

function assertConnectionButtons(assert) {
    const sync = document.querySelector('[data-test-sync-now]');
    const connect = document.querySelector('[data-test-connect]');
    const disconnect = document.querySelector('[data-test-disconnect]');
    const syncWrap = sync.closest('.btn-wrapper');
    const connectWrap = connect.closest('.btn-wrapper');
    const disconnectWrap = disconnect.closest('.btn-wrapper');

    assert.strictEqual(syncWrap.parentElement, connectWrap.parentElement, 'Sync now, Connect, and Disconnect share one row');
    assert.strictEqual(connectWrap.parentElement, disconnectWrap.parentElement, 'Sync now, Connect, and Disconnect share one row');
    assert.strictEqual(syncWrap.nextElementSibling, connectWrap, 'Connect is immediately beside Sync now');
    assert.strictEqual(connectWrap.nextElementSibling, disconnectWrap, 'Disconnect is immediately beside Connect');
    assert.dom('[data-test-test]').doesNotExist();
    assert.dom('[data-test-reconcile]').doesNotExist();
    assert.dom('[data-test-import]').doesNotExist();
    assert.dom('[data-test-actions]').doesNotExist();
}

module('Integration | Component | quickbooks-connection', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:modals-manager', ModalsManagerStub);
    });

    test('the actions section is not rendered', async function (assert) {
        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        await render(hbs`<QuickbooksConnection @variant="embedded" @connection={{this.connection}} @configured={{true}} />`);

        assert.dom('[data-test-connection-state]').doesNotExist();
        assert.dom('[data-test-actions]').doesNotExist();
        assert.dom('[data-test-sync-now]').doesNotExist();
        assert.dom('[data-test-reconcile]').doesNotExist();
        assert.dom('[data-test-import]').doesNotExist();
        assert.dom('[data-test-import-rules]').doesNotExist();
        assert.dom('[data-test-credentials-missing]').doesNotExist();
        assert.dom().doesNotIncludeText('Actions');
        assert.dom().doesNotIncludeText('Import customers');
        assert.dom().doesNotIncludeText('Reconcile');
    });

    test('an unknown variant does not describe a separate Connection or Actions page', async function (assert) {
        this.set('connection', null);
        await render(hbs`<QuickbooksConnection @connection={{this.connection}} @configured={{true}} />`);

        assert.dom('[data-test-connection-state]').doesNotExist();
        assert.dom('[data-test-actions]').doesNotExist();
        assert.dom('[data-test-connect]').doesNotExist();
        assert.dom('[data-test-disconnect]').doesNotExist();
        assert.dom('[data-test-sync-now]').doesNotExist();
        assert.dom().doesNotIncludeText('Open Connection');
        assert.dom().doesNotIncludeText('Open Actions');
    });

    test('sync now stays available for an active connection even when credentials are not marked configured', async function (assert) {
        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @configured={{false}} />`);

        assert.dom('[data-test-sync-now]').isNotDisabled();
        assert.dom('[data-test-connect]').isDisabled();
        assert.dom('[data-test-disconnect]').isNotDisabled();
        assert.dom('[data-test-credentials-missing]').doesNotExist();
        assert.dom('[data-test-reconcile]').doesNotExist();
        assert.dom('[data-test-import]').doesNotExist();
        assertConnectionButtons(assert);
    });

    test('connect does not send an import flag', async function (assert) {
        this.set('connection', null);
        this.set('payload', 'unset');
        this.set('synced', false);
        this.set('onConnect', (payload) => this.set('payload', payload));
        this.set('onSync', () => this.set('synced', true));
        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @configured={{true}} @onConnect={{this.onConnect}} @onSync={{this.onSync}} />`);

        assert.dom('[data-test-import-checkbox]').doesNotExist();
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom().doesNotIncludeText('Import customers');
        assertConnectionButtons(assert);
        await click('[data-test-connect]');
        assert.strictEqual(this.payload, undefined);
        assert.false(this.synced, 'a disconnected connection does not sync from Sync now');

        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        assert.dom('[data-test-sync-now]').isNotDisabled();
        assert.dom('[data-test-connect]').isDisabled();
        await click('[data-test-sync-now]');
        assert.true(this.synced);
        await click('[data-test-connect]');
        assert.strictEqual(this.payload, undefined, 'an active connection does not start another sign-in');
    });

    test('the connection buttons disable themselves while a run is in progress', async function (assert) {
        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @configured={{true}} @busy={{true}} />`);
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-connect]').isDisabled();
        assert.dom('[data-test-disconnect]').isDisabled();
        assert.dom('[data-test-reconcile]').doesNotExist();
        assert.dom('[data-test-import]').doesNotExist();
    });

    test('Connection shows Sync now, Connect, and Disconnect', async function (assert) {
        this.set('disconnected', false);
        this.set('onDisconnect', () => {
            this.set('disconnected', true);
            this.set('connection', null);
        });
        this.set('connection', null);

        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @configured={{true}} @onDisconnect={{this.onDisconnect}} />`);

        assert.dom('[data-test-test]').doesNotExist();
        assert.dom('[data-test-disconnected]').includesText('Choose Connect to QuickBooks');
        assert.dom('[data-test-disconnected]').includesText('syncs customers, invoices, payments, and wallets with QuickBooks Online');
        assert.dom('[data-test-disconnected]').doesNotIncludeText('sends');
        assert.dom('[data-test-disconnected]').doesNotIncludeText('Open Actions');
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-connect]').isNotDisabled();
        assert.dom('[data-test-connect]').hasClass('btn-sm');
        assert.dom('[data-test-disconnect]').isDisabled();
        assert.dom('[data-test-disconnect]').hasClass('btn-sm');
        assert.dom('[data-test-disconnect]').includesText('Disconnect');
        assert.dom().doesNotIncludeText('Import customers');
        assert.dom().doesNotIncludeText('Reconcile');
        assertConnectionButtons(assert);

        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        assert.dom('[data-test-sync-now]').isNotDisabled();
        assert.dom('[data-test-connect]').isDisabled();
        assert.dom('[data-test-disconnect]').isNotDisabled();
        assert.dom('[data-test-import-checkbox]').doesNotExist();
        assert.false(this.disconnected, 'disconnect waits for a click');
        assertConnectionButtons(assert);

        await click('[data-test-disconnect]');
        assert.true(this.disconnected);
        assert.dom('[data-test-disconnected]').includesText('Not connected');
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-connect]').isNotDisabled();
        assert.dom('[data-test-disconnect]').isDisabled();
        assertConnectionButtons(assert);

        this.set('connection', { realm_id: '123', needs_reauth: true });
        assert.dom('[data-test-reauth]').exists();
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-connect]').isNotDisabled();
        assert.dom('[data-test-connect]').hasClass('btn-sm');
        assert.dom('[data-test-disconnect]').includesText('Disconnect');
        assert.dom('[data-test-disconnect]').doesNotIncludeText('unsubscribe');
        assert.dom('[data-test-disconnect]').isDisabled();
        assert.dom('[data-test-disconnect]').hasClass('btn-sm');
        assertConnectionButtons(assert);
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

    test('an embedded variant does not show connection status while loading', async function (assert) {
        await render(hbs`<QuickbooksConnection @variant="embedded" @configured={{true}} />`);

        assert.dom('[data-test-actions]').doesNotExist();
        assert.dom('[data-test-connection-state]').doesNotExist();
        assert.dom('[data-test-connection-loading]').doesNotExist();
        assert.dom('[data-test-disconnected]').doesNotExist();
        assert.dom('[data-test-sync-now]').doesNotExist();
        assert.dom('[data-test-reconcile]').doesNotExist();
        assert.dom('[data-test-import]').doesNotExist();
        assert.dom('[data-test-disconnect]').doesNotExist();
        assert.dom().doesNotIncludeText('Not connected');
        assert.dom().doesNotIncludeText('Open Connection');
        assert.dom().doesNotIncludeText('Actions');
    });

    test('Connection stays loading until a connection result arrives', async function (assert) {
        await render(hbs`<QuickbooksConnection @variant="status" />`);

        assert.dom('[data-test-connection-state]').hasAttribute('data-test-connection-state', 'loading');
        assert.dom('[data-test-connection-loading]').hasText('Loading QuickBooks connection.');
        assert.dom('[data-test-disconnected]').doesNotExist();
        assert.dom('[data-test-sync-now]').doesNotExist();
        assert.dom('[data-test-connect]').doesNotExist();
        assert.dom('[data-test-disconnect]').doesNotExist();
        assert.dom('[data-test-import-checkbox]').doesNotExist();
        assert.dom('[data-test-test]').doesNotExist();
        assert.dom().doesNotIncludeText('Not connected');

        this.set('connection', null);
        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @configured={{true}} />`);

        assert.dom('[data-test-connection-state]').hasAttribute('data-test-connection-state', 'disconnected');
        assert.dom('[data-test-connection-loading]').doesNotExist();
        assert.dom('[data-test-disconnected]').includesText('Not connected');
        assert.dom('[data-test-connect]').isNotDisabled();
        assert.dom('[data-test-disconnect]').isDisabled();
        assert.dom('[data-test-import-checkbox]').doesNotExist();
        assertConnectionButtons(assert);

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

    test('connect stays disabled until credentials are saved, and stays available when reauth is needed', async function (assert) {
        this.set('connection', null);
        this.set('configured', false);
        this.set('connected', false);
        this.set('onConnect', () => this.set('connected', true));

        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @configured={{this.configured}} @onConnect={{this.onConnect}} />`);

        assert.dom('[data-test-connect]').isDisabled();
        assert.dom('[data-test-disconnect]').isDisabled();
        assert.dom('[data-test-disconnected]').includesText('Choose Save Changes first');
        assert.dom('[data-test-disconnected]').doesNotIncludeText('Choose Connect to QuickBooks');
        assertConnectionButtons(assert);

        this.set('configured', true);
        assert.dom('[data-test-connect]').isNotDisabled();
        assert.dom('[data-test-disconnected]').includesText('Choose Connect to QuickBooks');
        assertConnectionButtons(assert);

        this.set('connection', { realm_id: '123', needs_reauth: true });
        this.set('configured', false);
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-connect]').isNotDisabled();
        assert.dom('[data-test-disconnect]').isDisabled();
        assertConnectionButtons(assert);

        await click('[data-test-connect]');
        assert.true(this.connected, 'Connect still runs when the saved connection only needs reauth');
    });

    test('Disconnect without a parent handler posts to the disconnect endpoint', async function (assert) {
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

        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @configured={{true}} />`);

        assert.dom('[data-test-connected]').exists();
        assert.dom('[data-test-sync-now]').isNotDisabled();
        assert.dom('[data-test-disconnect]').isNotDisabled();
        assert.dom('[data-test-connect]').isDisabled();
        assert.deepEqual(this.owner.lookup('service:fetch').posts, []);

        const modals = this.owner.lookup('service:modals-manager');
        modals.decline = true;
        await click('[data-test-disconnect]');
        assert.strictEqual(modals.last.title, 'Disconnect QuickBooks?');
        assert.strictEqual(modals.last.body, 'This removes the saved QuickBooks connection. Sync now stays off until you connect again.');
        assert.false(/unsubscribe/i.test(`${modals.last.title} ${modals.last.body}`));
        assert.deepEqual(this.owner.lookup('service:fetch').posts, []);
        assert.dom('[data-test-connected]').exists();

        modals.decline = false;
        await click('[data-test-disconnect]');

        assert.deepEqual(this.owner.lookup('service:fetch').posts, [{ path: 'disconnect', body: { company_uuid: 'company-uuid' } }]);
        assert.deepEqual(this.owner.lookup('service:notifications').messages, [['success', 'QuickBooks disconnected.']]);
        assert.false(this.owner.lookup('service:notifications').messages.some((entry) => /unsubscribe|intuit/i.test(String(entry[1]))));
        assert.dom('[data-test-disconnected]').includesText('Not connected');
        assert.dom('[data-test-disconnect]').isDisabled();
        assert.dom('[data-test-connect]').isNotDisabled();
        assertConnectionButtons(assert);
    });
});
