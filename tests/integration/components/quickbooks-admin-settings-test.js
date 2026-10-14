import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, render } from '@ember/test-helpers';
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
    gets = [];
    posts = [];

    async get(path, params) {
        this.gets.push({ path, params });

        return {
            can_edit: true,
            auth: {
                client_id: 'id',
                environment: 'sandbox',
                client_secret_set: true,
                internal_webhook_receiver_url: 'http://internal.example/quickbooks/int/v1/webhooks',
                public_webhook_receiver_url: '',
                internal_oauth_redirect_url: 'http://internal.example/quickbooks/int/v1/oauth/callback',
                public_oauth_redirect_url: '',
            },
            sync: { interval_minutes: 5, periodic_interval_hours: 24, retry_limit: 5, default_backoff_seconds: 30 },
        };
    }

    async post(path, body) {
        this.posts.push({ path, body });

        return { auth: { client_id: body.auth.client_id, client_secret_set: true }, sync: body.sync };
    }
}

class CurrentUserStubService extends Service {
    companyId = 'company-uuid';
}

module('Integration | Component | quickbooks-admin-settings', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:notifications', NotificationsStubService);
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:current-user', CurrentUserStubService);
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

    test('it loads the global settings with scope admin and renders the full form', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<QuickbooksAdminSettings />`);

        assert.deepEqual(fetch.gets, [{ path: 'settings', params: { scope: 'admin', company_uuid: 'company-uuid' } }]);
        assert.dom('[data-test-field="client_id"]').hasValue('id');
        assert.dom('[data-test-field="client_secret"]').exists();
        assert.dom('[data-test-field="webhook_verifier"]').exists();
        assert.dom('[data-test-field="environment"]').exists();
        assert.dom('[data-test-field="public_webhook_receiver_url"]').exists();
        assert.dom('[data-test-sync="interval_minutes"]').isNotDisabled();
        assert.dom('[data-test-override]').doesNotExist();
        assert
            .dom('[data-test-admin-template-note]')
            .hasText(
                "These sync settings are only a template. An organization's sync settings are copied from them when it connects, saves or resets its settings. Changing them here has no effect on any organization's sync settings."
            );
        assert.dom('[data-test-reset]').doesNotExist();
        assert.dom('[data-test-template-note]').doesNotExist();
        assert.dom('[data-test-connection-actions]').doesNotExist();
        assert.dom('[data-test-connect]').doesNotExist();
    });

    test('it saves with scope admin', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<QuickbooksAdminSettings />`);
        await fillIn('[data-test-field="client_id"]', 'new-id');
        await fillIn('[data-test-field="client_secret"]', 'new-secret');
        await click(document.querySelector('#next-view-section-subheader-actions [data-test-save]'));

        const post = fetch.posts.at(-1);
        assert.strictEqual(post.path, 'settings');
        assert.strictEqual(post.body.scope, 'admin');
        assert.strictEqual(post.body.company_uuid, 'company-uuid');
        assert.strictEqual(post.body.auth.client_id, 'new-id');
        assert.strictEqual(post.body.auth.client_secret, 'new-secret');
        assert.strictEqual(post.body.sync.interval_minutes, 5);
        assert.false(Object.hasOwn(post.body, 'override'));
        assert.deepEqual(this.notifications.messages.at(-1), ['success', 'QuickBooks settings saved.']);
    });
});
