import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class NotificationsStubService extends Service {
    success() {}

    serverError() {}
}

class FetchStubService extends Service {
    connection = null;
    credentialsConfigured = false;
    posts = [];

    async get() {
        return {
            connection: this.connection,
            credentials_configured: this.credentialsConfigured,
            queue: 0,
            last_sync: null,
        };
    }

    async post(path, body) {
        this.posts.push([path, body]);

        return {};
    }
}

class OperatorAbilitiesStubService extends Service {
    can(permission) {
        return permission === 'quickbooks reconcile sync';
    }
}

class DeniedAbilitiesStubService extends Service {
    can() {
        return false;
    }
}

class CurrentUserStubService extends Service {
    companyId = 'company-uuid';
}

let finishPendingSummary = null;

class PendingSummaryFetchStubService extends FetchStubService {
    get() {
        return new Promise((resolve) => {
            finishPendingSummary = resolve;
        });
    }
}

module('Integration | Component | widget/quickbooks-sync', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:current-user', CurrentUserStubService);
        this.fetch = this.owner.lookup('service:fetch');
    });

    test('sync now stays disabled until QuickBooks is connected and credentials are configured', async function (assert) {
        await render(hbs`<Widget::QuickbooksSync />`);
        assert.dom('.ledger-widget-subtitle').hasText('Not connected');
        assert.dom('[data-test-widget-sync]').isDisabled();
        assert.dom('[data-test-widget-credentials-missing]').doesNotExist();

        this.fetch.connection = { realm_id: '123', needs_reauth: true };
        this.fetch.credentialsConfigured = true;
        await render(hbs`<Widget::QuickbooksSync />`);
        assert.dom('[data-test-widget-sync]').isDisabled();
        assert.dom('[data-test-widget-credentials-missing]').doesNotExist();

        this.fetch.connection = { realm_id: '123', environment: 'sandbox' };
        this.fetch.credentialsConfigured = false;
        await render(hbs`<Widget::QuickbooksSync />`);
        assert.dom('[data-test-widget-sync]').isDisabled();
        assert.dom('[data-test-widget-credentials-missing]').hasText('Enter Client ID and Client secret on Quickbooks Setup before Sync now.');

        this.fetch.credentialsConfigured = true;
        await render(hbs`<Widget::QuickbooksSync />`);
        assert.dom('[data-test-widget-sync]').isNotDisabled();
        assert.dom('[data-test-widget-credentials-missing]').doesNotExist();
    });

    test('an operator can sync now', async function (assert) {
        this.owner.register('service:abilities', OperatorAbilitiesStubService);
        this.fetch.connection = { realm_id: '123', environment: 'sandbox' };
        this.fetch.credentialsConfigured = true;

        await render(hbs`<Widget::QuickbooksSync />`);
        assert.dom('[data-test-widget-sync]').isNotDisabled();

        await click('[data-test-widget-sync]');

        assert.strictEqual(this.fetch.posts.length, 1);
        assert.strictEqual(this.fetch.posts[0][0], 'sync');
        assert.deepEqual(this.fetch.posts[0][1], { company_uuid: 'company-uuid' });
    });

    test('sync now stays disabled without the operator permission', async function (assert) {
        this.owner.register('service:abilities', DeniedAbilitiesStubService);
        this.fetch.connection = { realm_id: '123', environment: 'sandbox' };
        this.fetch.credentialsConfigured = true;

        await render(hbs`<Widget::QuickbooksSync />`);
        assert.dom('[data-test-widget-sync]').isDisabled();
        assert.strictEqual(this.fetch.posts.length, 0);
    });

    test('a failed summary shows unavailable and does not say not connected', async function (assert) {
        class FailedSummaryFetchStubService extends FetchStubService {
            async get() {
                throw new Error('summary failed');
            }
        }

        this.owner.register('service:fetch', FailedSummaryFetchStubService);
        await render(hbs`<Widget::QuickbooksSync />`);

        assert.dom('.ledger-widget-empty').hasText('QuickBooks status is unavailable.');
        assert.dom('[data-test-widget-loading]').doesNotExist();
        assert.dom('.ledger-widget-subtitle').doesNotExist();
        assert.dom('[data-test-last-sync]').doesNotExist();
        assert.dom('[data-test-sync-queue]').doesNotExist();
        assert.dom().doesNotIncludeText('Not connected');
        assert.dom().doesNotIncludeText('No sync yet');
    });

    test('the widget shows a loading sentence until the summary request finishes', async function (assert) {
        finishPendingSummary = null;
        this.owner.register('service:fetch', PendingSummaryFetchStubService);

        await render(hbs`<Widget::QuickbooksSync />`);

        assert.dom('[data-test-widget-loading]').hasText('Loading QuickBooks status.');
        assert.dom('.ledger-widget-subtitle').doesNotExist();
        assert.dom('[data-test-last-sync]').doesNotExist();
        assert.dom('[data-test-sync-queue]').doesNotExist();
        assert.dom('[data-test-widget-sync]').doesNotExist();
        assert.dom().doesNotIncludeText('Not connected');
        assert.dom().doesNotIncludeText('No sync yet');
        assert.dom().doesNotIncludeText('0 waiting');

        finishPendingSummary({
            connection: null,
            credentials_configured: false,
            queue: 0,
            last_sync: null,
        });
        await settled();

        assert.dom('[data-test-widget-loading]').doesNotExist();
        assert.dom('.ledger-widget-subtitle').hasText('Not connected');
        assert.dom('[data-test-last-sync]').hasText('No sync yet');
        assert.dom('[data-test-sync-queue]').hasText('0 waiting');
    });
});
