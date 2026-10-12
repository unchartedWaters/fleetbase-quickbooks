import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, render, waitFor } from '@ember/test-helpers';
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

const GLOBAL_SYNC = { interval_minutes: 5, periodic_interval_hours: 24, retry_limit: 5, default_backoff_seconds: 30, customer_conflict: 'fleetbase' };

class FetchStubService extends Service {
    postCalls = [];
    posts = [];
    credentialsConfigured = true;
    canEdit = true;
    override = false;

    async get(path) {
        if (path === 'settings') {
            return {
                can_edit: this.canEdit,
                override: this.override,
                credentials_configured: this.credentialsConfigured,
                sync: this.override ? { ...GLOBAL_SYNC, interval_minutes: 15 } : GLOBAL_SYNC,
                global_sync: GLOBAL_SYNC,
                company_sync: this.override ? { interval_minutes: 15 } : [],
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
            return {
                can_edit: true,
                override: body?.override === true,
                credentials_configured: true,
                sync: { ...GLOBAL_SYNC, ...(body?.sync ?? {}) },
                global_sync: GLOBAL_SYNC,
                company_sync: body?.sync ?? {},
            };
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
        assert.dom('[data-test-sync-now]').isNotDisabled('a localhost API callback does not block Sync now');
        assert.dom('[data-test-reconcile]').doesNotExist();
        assert.dom('[data-test-import]').doesNotExist();
        assert.dom('[data-test-actions]').doesNotExist();
        assert.dom('[data-test-test]').doesNotExist();
        assert.dom().doesNotIncludeText('Import customers');
        assert.dom().doesNotIncludeText('Reconcile');
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

        fetch.credentialsConfigured = false;
        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);
        assert.dom('[data-test-sync-now]').isDisabled();
        assert
            .dom('[data-test-credentials-missing]')
            .hasText('An installation administrator must enter the QuickBooks Client ID and Client secret on the Admin QuickBooks screen before Sync now.');
        assert.dom('[data-test-disconnect]').isNotDisabled();
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

    test('the organization screen never renders a global setting', async function (assert) {
        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        [
            'client_id',
            'client_secret',
            'webhook_verifier',
            'environment',
            'redirect_uri',
            'public_webhook_receiver_url',
            'internal_webhook_receiver_url',
            'public_oauth_redirect_url',
            'internal_oauth_redirect_url',
        ].forEach((key) => assert.dom(`[data-test-field="${key}"]`).doesNotExist(key));
        assert.dom('[data-test-credentials-guidance]').doesNotExist();
        assert.dom().doesNotIncludeText('Authentication');
        assert.dom('[data-test-settings-scope]').includesText('Schedule');
        assert.dom('[data-test-settings-scope]').includesText('Data Resolution');
        assert.dom('[data-test-credentials-not-configured]').doesNotExist();
    });

    test('without an override the global sync values show read only', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-override]').exists();
        assert.dom('[data-test-override]').doesNotHaveAttribute('data-disabled');
        assert.dom('[data-test-inherited-note]').exists();
        assert.dom('[data-test-sync="interval_minutes"]').hasValue('5');
        assert.dom('[data-test-sync="interval_minutes"]').isDisabled();
        assert.dom('[data-test-sync="customer_conflict"]').isDisabled();
        assert.dom('[data-test-sync="customer_direction"]').isDisabled();
        assert.dom('[data-test-settings-read-only]').doesNotExist();
        assert.dom('[data-test-save]', document).isNotDisabled();
        assert.deepEqual(fetch.postCalls, []);
    });

    test('turning the override on makes the sync fields editable', async function (assert) {
        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        await click('[data-test-override]');

        assert.dom('[data-test-inherited-note]').doesNotExist();
        assert.dom('[data-test-sync="interval_minutes"]').isNotDisabled();
        assert.dom('[data-test-sync="interval_minutes"]').hasValue('5');
        assert.dom('[data-test-sync="customer_direction"]').isNotDisabled();
    });

    test('an existing override shows its values editable', async function (assert) {
        this.owner.lookup('service:fetch').override = true;

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-inherited-note]').doesNotExist();
        assert.dom('[data-test-sync="interval_minutes"]').hasValue('15');
        assert.dom('[data-test-sync="interval_minutes"]').isNotDisabled();
    });

    test('the form is read only with a note when the caller cannot edit', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');
        fetch.canEdit = false;
        fetch.override = true;

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-settings-read-only]').exists();
        assert.dom('[data-test-save]', document).isDisabled();
        assert.dom('[data-test-override]').hasAttribute('data-disabled');
        assert.dom('[data-test-sync="interval_minutes"]').isDisabled();
        assert.dom('[data-test-sync="customer_conflict"]').isDisabled();
        assert.dom('[data-test-sync="customer_direction"]').isDisabled();
        assert.dom('[data-test-sync="customer_enabled"]').hasAttribute('data-disabled');
        assert.deepEqual(fetch.postCalls, []);
        // Connecting and syncing are per organization and stay on their own permissions.
        assert.dom('[data-test-sync-now]').isNotDisabled();
    });

    test('missing global credentials show a note and disable connecting', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');
        fetch.credentialsConfigured = false;

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-credentials-not-configured]').includesText('installation administrator must enter the QuickBooks Client ID and Client secret on the Admin QuickBooks screen');
        assert.dom('[data-test-sync-now]').isDisabled();
    });

    test('saving without an override posts override false and no auth', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);
        await click(document.querySelector('#next-view-section-subheader-actions [data-test-save]'));

        assert.deepEqual(fetch.posts.at(-1), {
            path: 'settings',
            body: { scope: 'company', company_uuid: 'company-uuid', override: false, sync: {} },
        });
        assert.deepEqual(this.notifications.messages.at(-1), ['success', 'QuickBooks settings saved.']);
    });

    test('saving with an override posts the sync fields and no auth', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);
        await click('[data-test-override]');
        await fillIn('[data-test-sync="interval_minutes"]', '15');
        await click(document.querySelector('#next-view-section-subheader-actions [data-test-save]'));

        const post = fetch.posts.at(-1);
        assert.strictEqual(post.path, 'settings');
        assert.deepEqual(Object.keys(post.body).sort(), ['company_uuid', 'override', 'scope', 'sync']);
        assert.strictEqual(post.body.scope, 'company');
        assert.strictEqual(post.body.company_uuid, 'company-uuid');
        assert.true(post.body.override);
        assert.strictEqual(post.body.sync.interval_minutes, 15);
        assert.strictEqual(post.body.sync.customer_direction, 'both');
        assert.strictEqual(post.body.sync.customer_enabled, true);
        assert.false(Object.hasOwn(post.body, 'auth'));
        assert.false(Object.hasOwn(post.body.sync, 'client_id'));
        assert.deepEqual(this.notifications.messages.at(-1), ['success', 'QuickBooks settings saved.']);
    });
});
