import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import ModalsManagerStub from '../../helpers/modals-manager-stub';

// Only the actions that apply are shown, always in the order Sync now, Connect, Disconnect, on one row.
function assertConnectionButtons(assert, expected) {
    const wrappers = ['[data-test-sync-now]', '[data-test-connect]', '[data-test-disconnect]'].map((selector) => document.querySelector(selector)?.closest('.btn-wrapper')).filter(Boolean);
    const names = wrappers.map((wrap) => (wrap.querySelector('[data-test-sync-now]') ? 'sync' : wrap.querySelector('[data-test-connect]') ? 'connect' : 'disconnect'));

    assert.deepEqual(names, expected, `shows ${expected.join(', ')}`);
    wrappers.forEach((wrap, index) => {
        assert.strictEqual(wrap.parentElement, wrappers[0].parentElement, 'the actions share one row');
        if (index > 0) {
            assert.strictEqual(wrappers[index - 1].nextElementSibling, wrap, 'the actions sit next to each other');
        }
    });
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

    test('sync now runs for an operator', async function (assert) {
        class OperatorAbilitiesStubService extends Service {
            can(permission) {
                return permission === 'quickbooks reconcile sync' || permission === 'quickbooks connect connection' || permission === 'quickbooks disconnect connection';
            }
        }

        this.owner.register('service:abilities', OperatorAbilitiesStubService);
        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        this.set('synced', false);
        this.set('onSync', () => this.set('synced', true));

        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @configured={{true}} @onSync={{this.onSync}} />`);
        assert.dom('[data-test-sync-now]').isNotDisabled();
        await click('[data-test-sync-now]');
        assert.true(this.synced);
        assert.dom('[data-test-disconnect]').isNotDisabled();
        assert.dom('[data-test-disconnect-denied]').doesNotExist();

        this.set('connection', { realm_id: '123', needs_reauth: true });
        assert.dom('[data-test-disconnect]').isNotDisabled();
        assert.dom('[data-test-disconnect-denied]').doesNotExist();
        assert.dom('[data-test-reauth]').doesNotIncludeText('You do not have permission');
    });

    test('sync now stays off without the operator permission', async function (assert) {
        class DeniedAbilitiesStubService extends Service {
            can() {
                return false;
            }
        }

        this.owner.register('service:abilities', DeniedAbilitiesStubService);
        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        this.set('synced', false);
        this.set('onSync', () => this.set('synced', true));

        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @configured={{true}} @onSync={{this.onSync}} />`);
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-sync-denied]').hasText('You do not have permission to run Sync now.');
        assert.dom('[data-test-connect]').doesNotExist('an active connection does not offer Connect');
        assert.dom('[data-test-disconnect]').isDisabled();
        assert.dom('[data-test-disconnect-denied]').hasText('You do not have permission to disconnect QuickBooks.');
        assert.false(this.synced);
    });

    test('connect and disconnect stay off without those permissions', async function (assert) {
        class DeniedAbilitiesStubService extends Service {
            can(permission) {
                return permission === 'quickbooks reconcile sync';
            }
        }

        this.owner.register('service:abilities', DeniedAbilitiesStubService);
        this.set('connection', null);
        this.set('connected', false);
        this.set('disconnected', false);
        this.set('onConnect', () => this.set('connected', true));
        this.set('onDisconnect', () => this.set('disconnected', true));

        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @configured={{true}} @onConnect={{this.onConnect}} @onDisconnect={{this.onDisconnect}} />`);

        assert.dom('[data-test-connect]').isDisabled();
        assert.dom('[data-test-disconnect]').doesNotExist('nothing is saved to disconnect');
        assert.dom('[data-test-sync-denied]').doesNotExist();
        assert.dom('[data-test-disconnect-denied]').doesNotExist();
        assert.dom('[data-test-disconnected]').includesText('You do not have permission to connect QuickBooks.');
        assert.dom('[data-test-disconnected]').doesNotIncludeText('Choose Connect to QuickBooks');
        assert.false(this.connected);

        this.set('connection', { realm_id: '123', needs_reauth: true });
        assert.dom('[data-test-connect]').isDisabled();
        assert.dom('[data-test-disconnect]').isDisabled();
        assert.dom('[data-test-sync-now]').doesNotExist('Sync now waits for a working connection');
        assert.dom('[data-test-reauth]').includesText('You do not have permission to connect QuickBooks.');
        assert.dom('[data-test-reauth]').doesNotIncludeText('Choose Connect to QuickBooks');
        assert.dom('[data-test-disconnect-denied]').hasText('You do not have permission to disconnect QuickBooks.');
        assert.false(this.disconnected);
    });

    test('missing credentials explain the admin step before a missing connect permission', async function (assert) {
        class DeniedAbilitiesStubService extends Service {
            can() {
                return false;
            }
        }

        this.owner.register('service:abilities', DeniedAbilitiesStubService);
        this.set('connection', null);

        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @configured={{false}} />`);
        assert
            .dom('[data-test-disconnected]')
            .hasText('Not connected. An installation administrator must enter the QuickBooks credentials in Admin → QuickBooks → Settings before you can connect.');

        this.set('connection', { realm_id: '123', needs_reauth: true });
        assert
            .dom('[data-test-reauth]')
            .hasText(
                'QuickBooks needs to be connected again before sync can continue. First, an installation administrator must enter the QuickBooks credentials in Admin → QuickBooks → Settings.'
            );
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

    test('sync now stays off for an active connection when credentials are not marked configured', async function (assert) {
        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        this.set('synced', false);
        this.set('onSync', () => this.set('synced', true));
        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @configured={{false}} @onSync={{this.onSync}} />`);

        assert.dom('[data-test-sync-now]').isDisabled();
        assert
            .dom('[data-test-credentials-missing]')
            .hasText('Sync now is unavailable until an installation administrator enters the QuickBooks Client ID and Client secret in Admin → QuickBooks → Settings.');
        assert.dom('[data-test-sync-denied]').doesNotExist();
        assert.dom('[data-test-disconnect]').isNotDisabled();
        assert.false(this.synced);
        assert.dom('[data-test-reconcile]').doesNotExist();
        assert.dom('[data-test-import]').doesNotExist();
        assertConnectionButtons(assert, ['sync', 'disconnect']);
    });

    test('connect does not send an import flag', async function (assert) {
        this.set('connection', null);
        this.set('payload', 'unset');
        this.set('synced', false);
        this.set('onConnect', (payload) => this.set('payload', payload));
        this.set('onSync', () => this.set('synced', true));
        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @configured={{true}} @onConnect={{this.onConnect}} @onSync={{this.onSync}} />`);

        assert.dom('[data-test-import-checkbox]').doesNotExist();
        assert.dom('[data-test-sync-now]').doesNotExist();
        assert.dom().doesNotIncludeText('Import customers');
        assertConnectionButtons(assert, ['connect']);
        await click('[data-test-connect]');
        assert.strictEqual(this.payload, undefined);
        assert.false(this.synced, 'a disconnected connection does not sync from Sync now');

        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        assert.dom('[data-test-sync-now]').isNotDisabled();
        assert.dom('[data-test-connect]').doesNotExist();
        await click('[data-test-sync-now]');
        assert.true(this.synced);
        assert.dom('[data-test-connect]').doesNotExist();
        assert.strictEqual(this.payload, undefined, 'an active connection does not start another sign-in');
    });

    test('the connection buttons disable themselves while a run is in progress', async function (assert) {
        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} @configured={{true}} @busy={{true}} />`);
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-connect]').doesNotExist();
        assert.dom('[data-test-disconnect]').isDisabled();
        assert.dom('[data-test-reconcile]').doesNotExist();
        assert.dom('[data-test-import]').doesNotExist();
    });

    test('Connection shows only the actions that apply', async function (assert) {
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
        assert.dom('[data-test-connect]').isNotDisabled();
        assert.dom('[data-test-connect]').hasClass('btn-sm');
        assert.dom().doesNotIncludeText('Import customers');
        assert.dom().doesNotIncludeText('Reconcile');
        assertConnectionButtons(assert, ['connect']);

        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        assert.dom('[data-test-sync-now]').isNotDisabled();
        assert.dom('[data-test-disconnect]').isNotDisabled();
        assert.dom('[data-test-disconnect]').hasClass('btn-sm');
        assert.dom('[data-test-disconnect]').includesText('Disconnect');
        assert.dom('[data-test-import-checkbox]').doesNotExist();
        assert.false(this.disconnected, 'disconnect waits for a click');
        assertConnectionButtons(assert, ['sync', 'disconnect']);

        await click('[data-test-disconnect]');
        assert.true(this.disconnected);
        assert.dom('[data-test-disconnected]').includesText('Not connected');
        assert.dom('[data-test-connect]').isNotDisabled();
        assertConnectionButtons(assert, ['connect']);

        this.set('disconnected', false);
        this.set('connection', { realm_id: '123', needs_reauth: true });
        assert.dom('[data-test-reauth]').exists();
        assert.dom('[data-test-connect]').isNotDisabled();
        assert.dom('[data-test-connect]').hasClass('btn-sm');
        assert.dom('[data-test-disconnect]').includesText('Disconnect');
        assert.dom('[data-test-disconnect]').doesNotIncludeText('unsubscribe');
        assert.dom('[data-test-disconnect]').isNotDisabled();
        assert.dom('[data-test-disconnect]').hasClass('btn-sm');
        assertConnectionButtons(assert, ['connect', 'disconnect']);

        await click('[data-test-disconnect]');
        assert.true(this.disconnected, 'a connection that needs to be authorized again can be removed');
        assert.dom('[data-test-disconnected]').includesText('Not connected');
        assertConnectionButtons(assert, ['connect']);
    });

    test('connected status names the income account invoice lines post to', async function (assert) {
        this.set('connection', { realm_id: '123', environment: 'sandbox', default_item_account: 'Services' });

        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} />`);

        assert
            .dom('[data-test-income-account]')
            .hasText(
                'Invoice lines use the QuickBooks product "Fleetbase service", which posts to the income account Services. QuickBooks taxes these lines only when that product and the customer are taxable. To change the account or whether the lines are taxed, edit that product in QuickBooks.'
            );

        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        assert.dom('[data-test-income-account]').doesNotExist('nothing is shown before the account is known');
    });

    test('connected status explains invoice and wallet currency rules', async function (assert) {
        this.set('connection', { realm_id: '123', environment: 'sandbox', home_currency: 'USD' });

        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} />`);

        assert.dom('[data-test-connected]').hasText('Connected to QuickBooks company 123 (Sandbox).');
        assert.dom('.panel-title').includesText('Connection', 'the card does not repeat the page title');
        assert.dom('.panel-title').doesNotIncludeText('Setup');
        assert.dom('[data-test-connected]').doesNotIncludeText('home currency');
        assert.dom('[data-test-home-currency]').includesText('The QuickBooks home currency is USD.');
        assert.dom('[data-test-home-currency]').includesText('To check it in QuickBooks, open the gear menu → Account and settings → Advanced → Currency.');
        assert
            .dom('[data-test-home-currency]')
            .includesText('Invoices in another currency are sent only when Multicurrency is turned on in QuickBooks. Otherwise they fail and show in Activity.');
        assert
            .dom('[data-test-home-currency]')
            .includesText('A wallet in another currency fails when it has no QuickBooks account yet and Fleetbase is Primary. A wallet already linked to an account is still sent.');
        assert
            .dom('[data-test-home-currency]')
            .includesText(
                "When QuickBooks is Primary for Accounts / Wallets and changes come from QuickBooks (Both or From QuickBooks), the wallet takes the QuickBooks account's currency."
            );
        assert.dom('[data-test-home-currency] li').exists({ count: 4 });
        assert.dom('[data-test-disconnect]').exists();

        this.set('connection', { realm_id: '123', environment: 'production' });
        assert.dom('[data-test-connected]').hasText('Connected to QuickBooks company 123 (Production).');
        this.set('connection', { realm_id: '123', environment: 'other' });
        assert.dom('[data-test-connected]').hasText('Connected to QuickBooks company 123 (other).', 'an unknown environment is shown as stored');
        this.set('connection', { realm_id: '123' });
        assert.dom('[data-test-connected]').hasText('Connected to QuickBooks company 123.');
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
        assert.dom('[data-test-import-checkbox]').doesNotExist();
        assertConnectionButtons(assert, ['connect']);

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
        assert
            .dom('[data-test-disconnected]')
            .hasText('Not connected. An installation administrator must enter the QuickBooks credentials in Admin → QuickBooks → Settings before you can connect.');
        assertConnectionButtons(assert, ['connect']);

        this.set('configured', true);
        assert.dom('[data-test-connect]').isNotDisabled();
        assert.dom('[data-test-disconnected]').includesText('Choose Connect to QuickBooks');
        assertConnectionButtons(assert, ['connect']);

        this.set('connection', { realm_id: '123', needs_reauth: true });
        this.set('configured', false);
        assert.dom('[data-test-sync-now]').doesNotExist();
        assert.dom('[data-test-credentials-missing]').doesNotExist();
        assert.dom('[data-test-connect]').isDisabled();
        assert.dom('[data-test-disconnect]').isNotDisabled();
        assert
            .dom('[data-test-reauth]')
            .hasText(
                'QuickBooks needs to be connected again before sync can continue. First, an installation administrator must enter the QuickBooks credentials in Admin → QuickBooks → Settings.'
            );
        assertConnectionButtons(assert, ['connect', 'disconnect']);

        this.set('configured', true);
        assert.dom('[data-test-connect]').isNotDisabled();
        assert.dom('[data-test-reauth]').includesText('Choose Connect to QuickBooks');
        await click('[data-test-connect]');
        assert.true(this.connected, 'Connect still runs when the saved connection only needs reauth');
    });

    test('an unknown credential state does not claim the credentials are missing', async function (assert) {
        this.set('connection', null);
        await render(hbs`<QuickbooksConnection @variant="status" @connection={{this.connection}} />`);

        assert.dom('[data-test-disconnected]').hasText('Not connected.');
        assert.dom('[data-test-connect]').isDisabled();

        this.set('connection', { realm_id: '123', needs_reauth: true });
        assert.dom('[data-test-reauth]').hasText('QuickBooks needs to be connected again before sync can continue.');

        this.set('connection', { realm_id: '123', environment: 'sandbox' });
        assert.dom('[data-test-credentials-missing]').doesNotExist();
        assert.dom('[data-test-sync-now]').isDisabled();
        assertConnectionButtons(assert, ['sync', 'disconnect']);
    });

    test('Disconnect without a parent handler confirms and does not post', async function (assert) {
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
        assert.dom('[data-test-connect]').doesNotExist();
        assert.deepEqual(this.owner.lookup('service:fetch').posts, []);

        const modals = this.owner.lookup('service:modals-manager');
        modals.decline = true;
        await click('[data-test-disconnect]');
        assert.strictEqual(modals.last.title, 'Disconnect QuickBooks?');
        assert.strictEqual(modals.last.body, 'This removes the saved QuickBooks connection. Syncing stops until you connect again.');
        assert.false(/unsubscribe/i.test(`${modals.last.title} ${modals.last.body}`));
        assert.deepEqual(this.owner.lookup('service:fetch').posts, []);
        assert.dom('[data-test-connected]').exists();

        modals.decline = false;
        await click('[data-test-disconnect]');

        assert.deepEqual(this.owner.lookup('service:fetch').posts, [], 'QuickBooks Setup owns the disconnect request');
        assert.deepEqual(this.owner.lookup('service:notifications').messages, []);
        assert.dom('[data-test-connected]').exists();
        assert.dom('[data-test-disconnect]').isNotDisabled();
        assertConnectionButtons(assert, ['sync', 'disconnect']);
    });
});
