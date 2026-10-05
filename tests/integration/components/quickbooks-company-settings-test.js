import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render, waitFor } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import ModalsManagerStub from '../../helpers/modals-manager-stub';

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

class FetchStubService extends Service {
    postCalls = [];
    posts = [];
    secretSet = true;

    async get(path) {
        if (path === 'settings') {
            return {
                auth: { client_id: 'id', redirect_uri: 'https://example.test/callback', environment: 'sandbox', client_secret_set: this.secretSet },
                sync: { enabled: true, interval_minutes: 5, batch_size: 100, retry_limit: 5, default_backoff_seconds: 30, override: false },
            };
        }
        if (path === 'connection') {
            return { connection: { realm_id: '123', environment: 'sandbox' } };
        }

        return {};
    }

    async post(path, body) {
        this.postCalls.push(path);
        this.posts.push({ path, body });
        if (path === 'settings') {
            return { auth: body?.auth ?? {}, sync: body?.sync ?? {} };
        }

        return {};
    }
}

class CurrentUserStubService extends Service {
    companyId = 'company-uuid';
    id = 'user-uuid';
}

class DisconnectedFetchStubService extends FetchStubService {
    async get(path) {
        if (path === 'connection') {
            return { connection: null };
        }

        return super.get(path);
    }

    async post(path) {
        this.postCalls.push(path);
        if (path === 'oauth/start') {
            throw new Error('Configure QuickBooks Client ID, Client secret, and Redirect URI before connecting.');
        }

        return {};
    }
}

class SettingsLoadFailedFetchStubService extends FetchStubService {
    async get(path) {
        if (path === 'settings') {
            throw new Error('QuickBooks settings are unavailable.');
        }

        return super.get(path);
    }
}

class ConnectionLoadFailedFetchStubService extends FetchStubService {
    async get(path) {
        if (path === 'connection') {
            throw new Error('QuickBooks status is unavailable.');
        }

        return super.get(path);
    }
}

let connectionGate = Promise.resolve();

class HoldingConnectionFetchStubService extends FetchStubService {
    async get(path) {
        if (path === 'connection') {
            await connectionGate;

            return { connection: null };
        }

        return super.get(path);
    }
}

module('Integration | Component | quickbooks-company-settings', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:current-user', CurrentUserStubService);
        this.owner.register('service:modals-manager', ModalsManagerStub);
        this.notifications = this.owner.lookup('service:notifications');
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

    test('disconnect deletes the local connection and shows not connected', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-connected]').exists();
        assert.dom('[data-test-disconnect]').exists({ count: 1 });
        assert.dom('[data-test-connect]').isDisabled();
        assert.dom('[data-test-sync-now]').isNotDisabled();
        assert.dom('[data-test-reconcile]').doesNotExist();
        assert.dom('[data-test-import]').doesNotExist();
        assert.dom('[data-test-actions]').doesNotExist();
        assert.dom('[data-test-test]').doesNotExist();
        assert.dom().doesNotIncludeText('Import customers');
        assert.dom().doesNotIncludeText('Reconcile');
        assert.strictEqual(
            document.querySelector('[data-test-connection-actions]').compareDocumentPosition(document.querySelector("[data-test-field='client_id']")) & Node.DOCUMENT_POSITION_FOLLOWING,
            Node.DOCUMENT_POSITION_FOLLOWING
        );
        assert.deepEqual(fetch.posts, []);

        await click('[data-test-disconnect]');

        assert.deepEqual(fetch.posts.at(-1), {
            path: 'disconnect',
            body: { company_uuid: 'company-uuid' },
        });
        assert.strictEqual(fetch.posts.filter((call) => call.path === 'disconnect').length, 1);
        assert.deepEqual(this.notifications.messages.at(-1), ['success', 'QuickBooks disconnected.']);
        assert.false(this.notifications.messages.some((entry) => /unsubscribe|intuit/i.test(String(entry[1]))));
        assert.dom('[data-test-disconnected]').includesText('Not connected');
        assert.dom('[data-test-connect]').isNotDisabled();
        assert.dom('[data-test-disconnect]').exists({ count: 1 });
        assert.dom('[data-test-disconnect]').isDisabled();
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-reconcile]').doesNotExist();
        assert.dom('[data-test-import]').doesNotExist();
    });

    test('sync now posts while QuickBooks is connected', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);
        await click('[data-test-sync-now]');

        assert.deepEqual(
            fetch.posts.map((call) => call.path),
            ['sync']
        );
        assert.deepEqual(this.notifications.messages, [['success', 'Sync queued.']]);
        assert.dom('[data-test-test]').doesNotExist();
        assert.dom('[data-test-reconcile]').doesNotExist();
        assert.dom('[data-test-import]').doesNotExist();

        fetch.secretSet = false;
        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);
        assert.dom('[data-test-sync-now]').isNotDisabled();
        assert.dom('[data-test-credentials-missing]').doesNotExist();
    });

    test('connect shows an error notification when oauth start fails', async function (assert) {
        this.owner.register('service:fetch', DisconnectedFetchStubService);

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-status-unavailable]').doesNotExist();
        assert.dom('[data-test-connect]').exists();
        assert.dom('[data-test-disconnect]').exists({ count: 1 });
        assert.dom('[data-test-disconnect]').isDisabled();
        assert.dom('[data-test-test]').doesNotExist();
        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-reconcile]').doesNotExist();
        assert.dom('[data-test-import]').doesNotExist();
        await click('[data-test-connect]');
        assert.deepEqual(this.notifications.messages.at(-1), ['error', 'Configure QuickBooks Client ID, Client secret, and Redirect URI before connecting.']);
    });

    test('the connection screen stays loading until the connection request finishes', async function (assert) {
        let release = () => {};
        connectionGate = new Promise((resolve) => {
            release = resolve;
        });
        this.owner.register('service:fetch', HoldingConnectionFetchStubService);

        try {
            const rendering = render(hbs`<QuickbooksCompanySettings @title="Connection" />`);
            await waitFor('[data-test-connection-loading]');

            assert.dom('[data-test-connection-state]').hasAttribute('data-test-connection-state', 'loading');
            assert.dom('[data-test-connection-loading]').hasText('Loading QuickBooks connection.');
            assert.dom('[data-test-disconnected]').doesNotExist();
            assert.dom('[data-test-status-unavailable]').doesNotExist();
            assert.dom('[data-test-connect]').doesNotExist();
            assert.dom('[data-test-import-checkbox]').doesNotExist();
            assert.dom('[data-test-sync-now]').doesNotExist();
            assert.dom('[data-test-reconcile]').doesNotExist();
            assert.dom('[data-test-import]').doesNotExist();
            assert.dom().doesNotIncludeText('Not connected');

            release();
            await rendering;

            assert.dom('[data-test-connection-loading]').doesNotExist();
            assert.dom('[data-test-disconnected]').includesText('Not connected');
            assert.dom('[data-test-connect]').isNotDisabled();
            assert.dom('[data-test-disconnect]').isDisabled();
            assert.dom('[data-test-status-unavailable]').doesNotExist();
        } finally {
            release();
        }
    });

    test('a failed connection load does not offer connect', async function (assert) {
        this.owner.register('service:fetch', ConnectionLoadFailedFetchStubService);

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-status-unavailable]').hasText('QuickBooks status could not be loaded.');
        assert.dom('[data-test-disconnected]').doesNotExist();
        assert.dom('[data-test-connect]').doesNotExist();
        assert.dom('[data-test-disconnect]').doesNotExist();
        assert.dom('[data-test-import-checkbox]').doesNotExist();
        assert.dom().doesNotIncludeText('Not connected');
    });

    test('a failed settings load does not save', async function (assert) {
        this.owner.register('service:fetch', SettingsLoadFailedFetchStubService);
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-settings-unavailable]').hasText('QuickBooks settings could not be loaded.');
        assert.deepEqual(this.notifications.messages.at(-1), ['error', 'QuickBooks settings are unavailable.']);
        assert.dom('[data-test-save]', document).isDisabled();
        assert.deepEqual(fetch.postCalls, []);
        assert.false(this.notifications.messages.some((entry) => entry[0] === 'success'));
        assert.dom('[data-test-settings-unavailable]').hasText('QuickBooks settings could not be loaded.');
    });

    test('a successful settings load posts directions without override', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-settings-unavailable]').doesNotExist();
        assert.dom('[data-test-override]').doesNotExist();
        assert.dom("[data-test-sync='customer_enabled']").isChecked();
        await click('[data-test-save]');
        assert.deepEqual(fetch.posts.at(-1), {
            path: 'settings',
            body: {
                scope: 'admin',
                company_uuid: 'company-uuid',
                auth: {
                    client_id: 'id',
                    environment: 'sandbox',
                    public_webhook_receiver_url: '',
                    public_oauth_redirect_url: '',
                },
                sync: {
                    interval_minutes: 5,
                    periodic_interval_hours: 24,
                    retry_limit: 5,
                    default_backoff_seconds: 30,
                    customer_conflict: 'fleetbase',
                    customer_reference: 'fleetbase',
                    customer_direction: 'both',
                    invoice_conflict: 'fleetbase',
                    invoice_reference: 'fleetbase',
                    invoice_direction: 'both',
                    payment_conflict: 'fleetbase',
                    payment_reference: 'fleetbase',
                    payment_direction: 'both',
                    wallet_conflict: 'fleetbase',
                    wallet_reference: 'fleetbase',
                    wallet_direction: 'both',
                    customer_enabled: true,
                    invoice_enabled: true,
                    payment_enabled: true,
                    wallet_enabled: true,
                },
            },
        });
        assert.deepEqual(this.notifications.messages.at(-1), ['success', 'QuickBooks settings saved.']);
    });
});
