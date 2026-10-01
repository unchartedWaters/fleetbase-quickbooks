import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, waitFor } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

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
    connection = null;
    credentialsConfigured = false;
    failSummary = false;
    summaryGate = null;
    getCalls = [];

    async get(path) {
        if (this.failSummary) {
            throw new Error('summary failed');
        }

        if (path === 'summary' && this.summaryGate) {
            await this.summaryGate;
        }

        this.getCalls.push(path);
        if (path === 'summary') {
            return { connection: this.connection, credentials_configured: this.credentialsConfigured, queue: 0, last_sync: null };
        }

        return {};
    }

    async post() {
        return {};
    }
}

class CurrentUserStubService extends Service {
    companyId = 'company-uuid';
    id = 'user-uuid';
}

module('Integration | Component | quickbooks-actions', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:current-user', CurrentUserStubService);
        this.fetch = this.owner.lookup('service:fetch');
    });

    test('Actions stays loading until the summary request finishes', async function (assert) {
        let release = () => {};
        this.fetch.summaryGate = new Promise((resolve) => {
            release = resolve;
        });
        this.fetch.credentialsConfigured = true;

        try {
            const rendering = render(hbs`<QuickbooksActions />`);
            await waitFor('[data-test-connection-loading]');

            assert.dom('[data-test-connection-state]').hasAttribute('data-test-connection-state', 'loading');
            assert.dom('[data-test-connection-loading]').hasText('Loading QuickBooks connection.');
            assert.dom('[data-test-disconnected]').doesNotExist();
            assert.dom('[data-test-status-unavailable]').doesNotExist();
            assert.dom('[data-test-sync-now]').doesNotExist();
            assert.dom('[data-test-reconcile]').doesNotExist();
            assert.dom('[data-test-import]').doesNotExist();
            assert.dom().doesNotIncludeText('Not connected');

            release();
            await rendering;

            assert.dom('[data-test-connection-loading]').doesNotExist();
            assert.dom('[data-test-disconnected]').includesText('Not connected');
            assert.dom('[data-test-disconnected]').includesText('syncs customers, invoices, payments, and wallets with QuickBooks Online');
            assert.dom('[data-test-sync-now]').isDisabled();
            assert.dom('[data-test-reconcile]').isDisabled();
            assert.dom('[data-test-import]').isDisabled();
        } finally {
            release();
        }
    });

    test('a connected summary enables actions only after the summary request finishes', async function (assert) {
        let release = () => {};
        this.fetch.summaryGate = new Promise((resolve) => {
            release = resolve;
        });
        this.fetch.connection = { realm_id: '123', environment: 'sandbox' };
        this.fetch.credentialsConfigured = true;

        try {
            const rendering = render(hbs`<QuickbooksActions />`);
            await waitFor('[data-test-connection-loading]');

            assert.dom('[data-test-connection-loading]').hasText('Loading QuickBooks connection.');
            assert.dom().doesNotIncludeText('Not connected');
            assert.dom('[data-test-sync-now]').doesNotExist();
            assert.dom('[data-test-reconcile]').doesNotExist();
            assert.dom('[data-test-import]').doesNotExist();

            release();
            await rendering;

            assert.dom('[data-test-connection-loading]').doesNotExist();
            assert.dom('[data-test-connected]').includesText('123');
            assert.dom().doesNotIncludeText('Not connected');
            assert.dom('[data-test-sync-now]').isNotDisabled();
            assert.dom('[data-test-reconcile]').isNotDisabled();
            assert.dom('[data-test-import]').isNotDisabled();
        } finally {
            release();
        }
    });

    test('sync reconcile and import stay disabled when QuickBooks is not connected', async function (assert) {
        this.fetch.credentialsConfigured = true;

        await render(hbs`<QuickbooksActions />`);

        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-reconcile]').isDisabled();
        assert.dom('[data-test-import]').isDisabled();
        assert.dom('[data-test-credentials-missing]').doesNotExist();
    });

    test('sync reconcile and import stay disabled and explain why when credentials are missing', async function (assert) {
        this.fetch.connection = { realm_id: '123', environment: 'sandbox' };

        await render(hbs`<QuickbooksActions />`);

        assert.dom('[data-test-sync-now]').isDisabled();
        assert.dom('[data-test-reconcile]').isDisabled();
        assert.dom('[data-test-import]').isDisabled();
        assert.dom('[data-test-credentials-missing]').exists();
    });

    test('sync reconcile and import enable when connected and credentials are configured', async function (assert) {
        this.fetch.connection = { realm_id: '123', environment: 'sandbox' };
        this.fetch.credentialsConfigured = true;

        await render(hbs`<QuickbooksActions />`);

        assert.dom('[data-test-sync-now]').isNotDisabled();
        assert.dom('[data-test-reconcile]').isNotDisabled();
        assert.dom('[data-test-import]').isNotDisabled();
        assert.deepEqual(this.fetch.getCalls, ['summary']);
    });

    test('a failed summary load does not say not connected', async function (assert) {
        this.fetch.failSummary = true;

        await render(hbs`<QuickbooksActions />`);

        assert.dom('[data-test-status-unavailable]').hasText('QuickBooks status could not be loaded.');
        assert.dom('[data-test-disconnected]').doesNotExist();
        assert.dom().doesNotIncludeText('Not connected');
        assert.dom('[data-test-sync-now]').isDisabled();
    });
});
