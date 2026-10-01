import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { settled } from '@ember/test-helpers';
import SettingsRoute from '@unchartedwaters/quickbooks-engine/routes/settings';
import SettingsController from '@unchartedwaters/quickbooks-engine/controllers/settings';
import Service from '@ember/service';

module('Unit | Route | settings', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        const posts = [];
        const messages = [];
        this.posts = posts;
        this.messages = messages;
        this.failPost = false;
        const context = this;

        this.owner.register(
            'service:fetch',
            class extends Service {
                async post(path, body, options) {
                    posts.push({ path, body, options });
                    if (context.failPost) {
                        throw new Error('This QuickBooks authorization link is not valid.');
                    }
                    return { connected: true };
                }
            }
        );
        this.owner.register(
            'service:notifications',
            class extends Service {
                success(message) {
                    messages.push(['success', message]);
                }
                error(message) {
                    messages.push(['error', message]);
                }
                serverError(error) {
                    messages.push(['serverError', error.message]);
                }
            }
        );
        this.owner.register(
            'service:current-user',
            class extends Service {
                companyId = 'company-uuid';
            }
        );
        this.owner.register('route:quickbooks-settings', SettingsRoute);
        this.owner.register('controller:quickbooks-settings', SettingsController);

        this.route = this.owner.lookup('route:quickbooks-settings');
        this.controller = this.owner.lookup('controller:quickbooks-settings');
    });

    test('oauth_state completes the connection once and toasts success once', async function (assert) {
        await this.route.model({ oauth_state: 'abc' });
        await this.route.model({ oauth_state: 'abc' });

        assert.deepEqual(this.posts, [{ path: 'oauth/complete', body: { state: 'abc', company_uuid: 'company-uuid' }, options: { namespace: 'quickbooks/int/v1' } }]);
        assert.deepEqual(this.messages, [['success', 'Connected to QuickBooks.']]);
    });

    test('no oauth_state does not post', async function (assert) {
        assert.strictEqual(await this.route.model({}), null);
        assert.deepEqual(this.posts, []);
    });

    test('a failed completion shows the server error and does not throw', async function (assert) {
        this.failPost = true;

        assert.strictEqual(await this.route.model({ oauth_state: 'abc' }), null);
        assert.deepEqual(this.messages, [['serverError', 'This QuickBooks authorization link is not valid.']]);
    });

    test('setupController clears oauth_state', async function (assert) {
        this.controller.oauth_state = 'abc';

        this.route.setupController(this.controller, null);
        await settled();

        assert.strictEqual(this.controller.oauth_state, null);
        assert.deepEqual(this.messages, []);
    });

    test('error=cancelled shows the fixed message once and clears the param', async function (assert) {
        this.controller.error = 'cancelled';
        this.route.setupController(this.controller, null);
        await settled();

        this.controller.error = 'cancelled';
        this.route.setupController(this.controller, null);
        await settled();

        assert.strictEqual(this.controller.error, null);
        assert.deepEqual(this.messages, [['error', 'QuickBooks connection was cancelled.']]);
    });

    test('error=state shows the expired message', async function (assert) {
        this.controller.error = 'state';
        this.route.setupController(this.controller, null);
        await settled();

        assert.deepEqual(this.messages, [['error', 'That QuickBooks authorization expired. Connect again from Connection.']]);
    });

    test('an unknown error code shows the generic message, never the raw text', async function (assert) {
        this.controller.error = '<b>Call 555-0100</b>';
        this.route.setupController(this.controller, null);
        await settled();

        assert.strictEqual(this.controller.error, null);
        assert.deepEqual(this.messages, [['error', 'QuickBooks could not connect. Try again from Connection.']]);
    });

    test('resetController on exit clears params and the dedupe key', async function (assert) {
        await this.route.model({ oauth_state: 'abc' });
        this.controller.error = 'failed';
        this.controller.oauth_state = 'abc';

        this.route.resetController(this.controller, true);
        await this.route.model({ oauth_state: 'abc' });

        assert.strictEqual(this.controller.error, null);
        assert.strictEqual(this.controller.oauth_state, null);
        assert.strictEqual(this.route.oauthResultKey, 'state:abc');
        assert.strictEqual(this.posts.length, 2);
    });
});
