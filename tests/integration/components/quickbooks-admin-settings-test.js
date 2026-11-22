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
                production_client_id: 'id',
                production_client_secret_set: true,
                sandbox_client_id: '',
                internal_webhook_receiver_url: 'http://internal.example/quickbooks/int/v1/webhooks',
                public_webhook_receiver_url: 'http://internal.example/quickbooks/int/v1/webhooks',
                public_oauth_redirect_url: 'https://console.example/quickbooks',
            },
            sync: { interval_minutes: 5, periodic_interval_hours: 24, retry_limit: 5, default_backoff_seconds: 30 },
        };
    }

    async post(path, body, options) {
        this.posts.push({ path, body, options });

        return { auth: { production_client_id: body.auth.production_client_id, production_client_secret_set: true }, sync: body.sync };
    }
}

const REFUSED_CLIENT_ID = 'Enter the Client ID from Keys & credentials.';

// Rejects the way ember-core's fetch does: with an Error that keeps only the message, unless rawError is set.
class RefusingFetchStubService extends FetchStubService {
    async post(path, body, options) {
        this.posts.push({ path, body, options });
        if (options?.rawError !== true) {
            throw new Error('Some QuickBooks settings are missing or invalid.');
        }

        throw {
            message: 'Some QuickBooks settings are missing or invalid.',
            errors: { production_client_id: REFUSED_CLIENT_ID },
        };
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
        assert.dom('[data-test-field="production_client_id"]').hasValue('id');
        assert.dom('[data-test-field="production_client_secret"]').exists();
        assert.dom('[data-test-field="production_webhook_verifier"]').exists();
        assert.dom('[data-test-field="sandbox_client_id"]').hasValue('');
        assert.dom('[data-test-field="sandbox_client_secret"]').exists();
        assert.dom('[data-test-field="sandbox_webhook_verifier"]').exists();
        // The environment is chosen where the organization connects, not here.
        assert.dom('[data-test-field="environment"]').doesNotExist();
        // The URLs are text to copy into Intuit, not inputs.
        assert.dom('[data-test-field="public_webhook_receiver_url"]').hasText('http://internal.example/quickbooks/int/v1/webhooks');
        assert.dom('[data-test-field="public_oauth_redirect_url"]').hasText('https://console.example/quickbooks');
        assert.dom('input[data-test-field="public_webhook_receiver_url"]').doesNotExist();
        assert.dom('input[data-test-field="public_oauth_redirect_url"]').doesNotExist();
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
        await fillIn('[data-test-field="production_client_id"]', 'new-id');
        await fillIn('[data-test-field="production_client_secret"]', 'new-secret');
        await click(document.querySelector('#next-view-section-subheader-actions [data-test-save]'));

        const post = fetch.posts.at(-1);
        assert.strictEqual(post.path, 'settings');
        assert.strictEqual(post.body.scope, 'admin');
        assert.strictEqual(post.body.company_uuid, 'company-uuid');
        assert.strictEqual(post.body.auth.production_client_id, 'new-id');
        assert.strictEqual(post.body.auth.production_client_secret, 'new-secret');
        assert.strictEqual(post.body.sync.interval_minutes, 5);
        assert.false(Object.hasOwn(post.body, 'override'));
        assert.deepEqual(this.notifications.messages.at(-1), ['success', 'QuickBooks settings saved.']);
    });

    test('a field error from the server is shown under that field', async function (assert) {
        this.owner.register('service:fetch', RefusingFetchStubService);
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<QuickbooksAdminSettings />`);
        await click(document.querySelector('#next-view-section-subheader-actions [data-test-save]'));

        assert.true(fetch.posts.at(-1).options.rawError);
        assert.false(Object.hasOwn(fetch.posts.at(-1).body.auth, 'public_oauth_redirect_url'));
        assert.dom('[data-test-error="production_client_id"]').hasText(REFUSED_CLIENT_ID);
        assert.dom('[data-test-field="production_client_id"]').hasValue('id');
        assert.false(this.notifications.messages.some((entry) => entry[0] === 'success'));
    });
});
