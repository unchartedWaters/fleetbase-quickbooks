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

    test('the Intuit return completes the connection once and toasts success once', async function (assert) {
        const params = { code: 'the-code', state: 'abc', realmId: '1234567' };
        await this.route.model(params);
        await this.route.model(params);

        assert.deepEqual(this.posts, [
            {
                path: 'oauth/complete',
                body: { state: 'abc', code: 'the-code', realmId: '1234567', company_uuid: 'company-uuid' },
                options: { namespace: 'quickbooks/int/v1' },
            },
        ]);
        assert.deepEqual(this.messages, [['success', 'Connected to QuickBooks.']]);
    });

    test('no code or state does not post', async function (assert) {
        assert.strictEqual(await this.route.model({}), null);
        assert.strictEqual(await this.route.model({ code: 'the-code' }), null);
        assert.strictEqual(await this.route.model({ state: 'abc' }), null);
        assert.deepEqual(this.posts, []);
    });

    test('a failed completion shows the server error and does not throw', async function (assert) {
        this.failPost = true;

        assert.strictEqual(await this.route.model({ code: 'the-code', state: 'abc', realmId: '1234567' }), null);
        assert.deepEqual(this.messages, [['serverError', 'This QuickBooks authorization link is not valid.']]);
    });

    test('an Intuit error tells the server to drop the state, once, without a success toast', async function (assert) {
        await this.route.model({ error: 'access_denied', state: 'abc' });
        await this.route.model({ error: 'access_denied', state: 'abc' });

        assert.deepEqual(this.posts, [
            {
                path: 'oauth/complete',
                body: { state: 'abc', error: 'access_denied', company_uuid: 'company-uuid' },
                options: { namespace: 'quickbooks/int/v1' },
            },
        ]);
        assert.deepEqual(this.messages, []);
    });

    test('a failed cancel post is not shown', async function (assert) {
        this.failPost = true;

        assert.strictEqual(await this.route.model({ error: 'access_denied', state: 'abc' }), null);
        assert.deepEqual(this.messages, []);
    });

    test('setupController clears code, state and realmId from the address', async function (assert) {
        this.controller.code = 'the-code';
        this.controller.state = 'abc';
        this.controller.realmId = '1234567';

        this.route.setupController(this.controller, null);
        await settled();

        assert.strictEqual(this.controller.code, null);
        assert.strictEqual(this.controller.state, null);
        assert.strictEqual(this.controller.realmId, null);
        assert.deepEqual(this.messages, []);
    });

    test('error=access_denied shows the cancelled message once and clears the param', async function (assert) {
        this.controller.error = 'access_denied';
        this.route.setupController(this.controller, null);
        await settled();

        this.controller.error = 'access_denied';
        this.route.setupController(this.controller, null);
        await settled();

        assert.strictEqual(this.controller.error, null);
        assert.deepEqual(this.messages, [['error', 'QuickBooks connection was cancelled.']]);
    });

    test('an unknown error code shows the generic message, never the raw text', async function (assert) {
        this.controller.error = '<b>Call 555-0100</b>';
        this.route.setupController(this.controller, null);
        await settled();

        assert.strictEqual(this.controller.error, null);
        assert.deepEqual(this.messages, [['error', 'QuickBooks could not connect. Try again from QuickBooks Setup.']]);
    });

    test('resetController on exit clears params and the dedupe key', async function (assert) {
        const params = { code: 'the-code', state: 'abc', realmId: '1234567' };
        await this.route.model(params);
        this.controller.error = 'access_denied';
        this.controller.code = 'the-code';
        this.controller.state = 'abc';
        this.controller.realmId = '1234567';

        this.route.resetController(this.controller, true);
        await this.route.model(params);

        assert.strictEqual(this.controller.error, null);
        assert.strictEqual(this.controller.code, null);
        assert.strictEqual(this.controller.state, null);
        assert.strictEqual(this.controller.realmId, null);
        assert.strictEqual(this.route.oauthResultKey, 'state:abc');
        assert.strictEqual(this.posts.length, 2);
    });
});
