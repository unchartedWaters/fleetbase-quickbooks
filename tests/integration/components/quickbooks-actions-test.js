import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class NotificationsStubService extends Service {
    serverError() {}
}

class FetchStubService extends Service {
    connection = null;
    credentialsConfigured = false;

    async get(path) {
        if (path === 'summary') {
            return { connection: this.connection, credentials_configured: this.credentialsConfigured, queue: 0, last_sync: null };
        }

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
    });

    test('the actions panel does not offer reconcile or import', async function (assert) {
        await render(hbs`<QuickbooksActions />`);

        assert.dom('[data-test-actions]').doesNotExist();
        assert.dom('[data-test-reconcile]').doesNotExist();
        assert.dom('[data-test-import]').doesNotExist();
        assert.dom('[data-test-sync-now]').doesNotExist();
        assert.dom().doesNotIncludeText('Import customers');
        assert.dom().doesNotIncludeText('Reconcile');
    });
});
