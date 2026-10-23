import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, render, settled, waitFor } from '@ember/test-helpers';
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

const GLOBAL_SYNC = {
    interval_minutes: 5,
    periodic_interval_hours: 24,
    retry_limit: 5,
    default_backoff_seconds: 30,
    customer_conflict: 'fleetbase',
    customer_reference: 'fleetbase',
    customer_direction: 'both',
    customer_enabled: true,
    invoice_conflict: 'fleetbase',
    invoice_reference: 'fleetbase',
    invoice_direction: 'both',
    invoice_enabled: true,
    payment_conflict: 'fleetbase',
    payment_reference: 'fleetbase',
    payment_direction: 'both',
    payment_enabled: true,
    wallet_conflict: 'fleetbase',
    wallet_reference: 'fleetbase',
    wallet_direction: 'both',
    wallet_enabled: true,
};
const SYNC_KEYS = Object.keys(GLOBAL_SYNC).sort();

class FetchStubService extends Service {
    postCalls = [];
    posts = [];
    credentialsConfigured = true;
    canEdit = true;
    hasCopy = false;

    async get(path) {
        if (path === 'settings') {
            return {
                can_edit: this.canEdit,
                has_copy: this.hasCopy,
                credentials_configured: this.credentialsConfigured,
                sync: this.hasCopy ? { ...GLOBAL_SYNC, interval_minutes: 15 } : GLOBAL_SYNC,
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
                has_copy: true,
                credentials_configured: true,
                sync: body?.reset === true ? GLOBAL_SYNC : { ...GLOBAL_SYNC, ...body?.sync },
            };
        }

        return {};
    }
}

// The server's 422 body as ember-core rejects it with rawError: a plain object, not an Error.
class FieldlessErrorFetchStubService extends FetchStubService {
    postOptions = [];

    async post(path, body, options) {
        this.postCalls.push(path);
        this.posts.push({ path, body });
        this.postOptions.push(options);
        if (path === 'settings') {
            throw {
                message: 'Choose the organization sync settings only.',
                errors: { settings: 'Only the sync settings can be saved for an organization.' },
            };
        }

        return {};
    }
}

// A 403 body with rawError is a plain object with only a message.
class ForbiddenFetchStubService extends FetchStubService {
    async post(path, body) {
        this.postCalls.push(path);
        this.posts.push({ path, body });

        throw { message: 'You do not have permission to change QuickBooks settings.' };
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
        assert.dom('[data-test-connect]').doesNotExist('an active connection does not offer Connect');
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
        assert.dom('[data-test-disconnect]').doesNotExist();
        assert.dom('[data-test-sync-now]').doesNotExist();
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
            .hasText('Sync now is unavailable until an installation administrator enters the QuickBooks Client ID and Client secret in Admin → QuickBooks → Settings.');
        assert.dom('[data-test-disconnect]').isNotDisabled();
    });

    test('connect shows an error notification when oauth start fails', async function (assert) {
        this.owner.register('service:fetch', DisconnectedFetchStubService);

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-status-unavailable]').doesNotExist();
        assert.dom('[data-test-connect]').exists();
        assert.dom('[data-test-disconnect]').doesNotExist();
        assert.dom('[data-test-test]').doesNotExist();
        assert.dom('[data-test-sync-now]').doesNotExist();
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
            assert.dom('[data-test-disconnect]').doesNotExist();
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
        ].forEach((key) => {
            assert.dom(`[data-test-field="${key}"]`).doesNotExist(key);
        });
        assert.dom('[data-test-credentials-guidance]').doesNotExist();
        assert.dom().doesNotIncludeText('Authentication');
        assert.dom('[data-test-settings-scope]').includesText('Schedule');
        assert.dom('[data-test-settings-scope]').includesText('Data Resolution');
    });

    test('there is no override toggle and the sync fields are editable by default', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-override]').doesNotExist();
        assert.dom('[data-test-inherited-note]').doesNotExist();
        assert.dom().doesNotIncludeText('Override');
        assert.dom('[data-test-sync="interval_minutes"]').hasValue('5');
        assert.dom('[data-test-sync="interval_minutes"]').isNotDisabled();
        assert.dom('[data-test-sync="customer_conflict"]').isNotDisabled();
        assert.dom('[data-test-sync="customer_direction"]').isNotDisabled();
        assert.dom('[data-test-settings-read-only]').doesNotExist();
        assert.dom('[data-test-save]', document).isNotDisabled();
        assert.deepEqual(fetch.postCalls, []);
    });

    test('an organization without its own copy sees the template note', async function (assert) {
        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-template-note]').hasText("These are the current global defaults. They become this organization's own settings when you connect or save.");
        assert.dom('[data-test-copy-note]').doesNotExist();
        assert.dom('[data-test-admin-template-note]').doesNotExist();
    });

    test('an organization with its own copy shows its values and the copy note', async function (assert) {
        this.owner.lookup('service:fetch').hasCopy = true;

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-copy-note]').hasText('Changes to the global defaults do not affect this organization.');
        assert.dom('[data-test-template-note]').doesNotExist();
        assert.dom('[data-test-sync="interval_minutes"]').hasValue('15');
        assert.dom('[data-test-sync="interval_minutes"]').isNotDisabled();
    });

    test('the notes stay hidden while settings load and after a failed load', async function (assert) {
        this.owner.register('service:fetch', SettingsLoadFailedFetchStubService);

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-template-note]').doesNotExist();
        assert.dom('[data-test-copy-note]').doesNotExist();
        assert.dom('[data-test-reset]', document).doesNotExist();
    });

    test('the form is read only with a note when the caller cannot edit', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');
        fetch.canEdit = false;
        fetch.hasCopy = true;

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-settings-read-only]').exists();
        assert.dom('[data-test-save]', document).isDisabled();
        assert.dom('[data-test-reset]', document).doesNotExist();
        assert.dom('[data-test-sync="interval_minutes"]').isDisabled();
        assert.dom('[data-test-sync="customer_conflict"]').isDisabled();
        assert.dom('[data-test-sync="customer_direction"]').isDisabled();
        assert.dom('[data-test-sync="customer_enabled"]').hasAttribute('data-disabled');
        assert.deepEqual(fetch.postCalls, []);
        // Connecting and syncing are per organization and stay on their own permissions.
        assert.dom('[data-test-sync-now]').isNotDisabled();
    });

    test('missing global credentials are explained once, on the connection card', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');
        fetch.credentialsConfigured = false;

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-credentials-not-configured]').doesNotExist();
        assert.dom('[data-test-credentials-missing]').includesText('installation administrator enters the QuickBooks Client ID and Client secret in Admin → QuickBooks → Settings');
        assert.dom().doesNotIncludeText('Admin QuickBooks screen');
        assert.dom('[data-test-sync-now]').isDisabled();
    });

    test('the connection card comes first, then the settings note, then the schedule', async (assert) => {
        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        const card = document.querySelector('[data-test-connection-state]');
        const note = document.querySelector('[data-test-template-note]');
        const schedule = document.querySelector('[data-test-sync-field="interval_minutes"]');
        assert.ok(card.compareDocumentPosition(note) & Node.DOCUMENT_POSITION_FOLLOWING, 'the note follows the connection card');
        assert.ok(note.compareDocumentPosition(schedule) & Node.DOCUMENT_POSITION_FOLLOWING, 'the schedule follows the note');
        assert.dom('[data-test-connection-state]').exists({ count: 1 });
    });

    test('reset sits in the header actions just before Save Changes', async (assert) => {
        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        const actions = document.querySelector('#next-view-section-subheader-actions');
        const reset = actions.querySelector('[data-test-reset]');
        const save = actions.querySelector('[data-test-save]');
        assert.ok(reset, 'Reset is in the header actions');
        assert.ok(reset.compareDocumentPosition(save) & Node.DOCUMENT_POSITION_FOLLOWING, 'Save Changes comes last');
        assert.dom('[data-test-reset]').doesNotExist('Reset is not repeated in the page body');
    });

    test('the credentials and read-only notes stay hidden while settings load', async function (assert) {
        let release;
        const gate = new Promise((resolve) => {
            release = resolve;
        });
        class HoldingSettingsFetchStubService extends FetchStubService {
            async get(path) {
                if (path === 'settings') {
                    await gate;
                }

                return super.get(path);
            }
        }
        this.owner.register('service:fetch', HoldingSettingsFetchStubService);

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);
        assert.dom('[data-test-credentials-missing]').doesNotExist();
        assert.dom('[data-test-settings-read-only]').doesNotExist();

        release();
        await settled();
    });

    test('a failed settings load shows neither the credentials note nor the read-only note', async function (assert) {
        this.owner.register('service:fetch', SettingsLoadFailedFetchStubService);

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);

        assert.dom('[data-test-settings-unavailable]').exists();
        assert.dom('[data-test-credentials-missing]').doesNotExist('an unknown credential state is not reported as missing');
        assert.dom('[data-test-settings-read-only]').doesNotExist();
        assert.dom('[data-test-sync-now]').isDisabled();
    });

    test('saving posts the full sync field set without an override or auth', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);
        await fillIn('[data-test-sync="interval_minutes"]', '15');
        await click(document.querySelector('#next-view-section-subheader-actions [data-test-save]'));

        const post = fetch.posts.at(-1);
        assert.strictEqual(post.path, 'settings');
        assert.deepEqual(Object.keys(post.body).sort(), ['company_uuid', 'scope', 'sync']);
        assert.strictEqual(post.body.scope, 'company');
        assert.strictEqual(post.body.company_uuid, 'company-uuid');
        assert.deepEqual(Object.keys(post.body.sync).sort(), SYNC_KEYS);
        assert.strictEqual(post.body.sync.interval_minutes, 15);
        assert.strictEqual(post.body.sync.retry_limit, 5);
        assert.false(Object.hasOwn(post.body, 'override'));
        assert.false(Object.hasOwn(post.body, 'auth'));
        assert.deepEqual(this.notifications.messages.at(-1), ['success', 'QuickBooks settings saved.']);
        assert.dom('[data-test-copy-note]').exists('the response marks the organization as having its own copy');
    });

    test('saving without changes still posts the full set', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);
        await click(document.querySelector('#next-view-section-subheader-actions [data-test-save]'));

        assert.deepEqual(Object.keys(fetch.posts.at(-1).body.sync).sort(), SYNC_KEYS);
    });

    test('reset asks for confirmation, posts reset and reloads from the response', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');
        fetch.hasCopy = true;
        const modals = this.owner.lookup('service:modals-manager');

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);
        assert.dom('[data-test-sync="interval_minutes"]').hasValue('15');
        await fillIn('[data-test-sync="retry_limit"]', '9');

        await click(document.querySelector('#next-view-section-subheader-actions [data-test-reset]'));

        assert.strictEqual(modals.last.title, 'Reset to global defaults?');
        assert.strictEqual(
            modals.last.body,
            "This replaces this organization's sync settings with a one-time copy of the current global defaults. Later changes to the global defaults will not affect them."
        );
        assert.deepEqual(fetch.posts.at(-1), {
            path: 'settings',
            body: { scope: 'company', company_uuid: 'company-uuid', reset: true },
        });
        assert.dom('[data-test-sync="interval_minutes"]').hasValue('5');
        assert.dom('[data-test-sync="retry_limit"]').hasValue('5', 'unsaved edits are dropped');
        assert.deepEqual(this.notifications.messages.at(-1), ['success', 'Sync settings reset to the global defaults.']);
    });

    test('a field-less server error on save shows a toast', async function (assert) {
        this.owner.register('service:fetch', FieldlessErrorFetchStubService);
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);
        await click(document.querySelector('#next-view-section-subheader-actions [data-test-save]'));

        assert.true(fetch.postOptions.at(-1).rawError);
        assert.deepEqual(this.notifications.messages.at(-1), ['error', 'Choose the organization sync settings only.']);
        assert.dom('[data-test-error]').doesNotExist();
    });

    test('a refused save or reset shows the server message', async function (assert) {
        this.owner.register('service:fetch', ForbiddenFetchStubService);
        const fetch = this.owner.lookup('service:fetch');
        fetch.hasCopy = true;

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);
        await click(document.querySelector('#next-view-section-subheader-actions [data-test-save]'));
        assert.deepEqual(this.notifications.messages.at(-1), ['error', 'You do not have permission to change QuickBooks settings.']);

        await click(document.querySelector('#next-view-section-subheader-actions [data-test-reset]'));
        assert.deepEqual(this.notifications.messages.at(-1), ['error', 'You do not have permission to change QuickBooks settings.']);
        assert.deepEqual(fetch.postCalls, ['settings', 'settings']);
    });

    test('declining the reset confirmation posts nothing', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');
        this.owner.lookup('service:modals-manager').decline = true;

        await render(hbs`<QuickbooksCompanySettings @title="Connection" />`);
        await click(document.querySelector('#next-view-section-subheader-actions [data-test-reset]'));

        assert.deepEqual(fetch.postCalls, []);
    });
});
