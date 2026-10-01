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

function assertSelectableUrl(assert, key, label, value) {
    assert.dom(`[data-test-sync-field="${key}"] label`).hasText(label);
    assert.dom(`[data-test-field="${key}"]`).hasTagName('div');
    assert.dom(`[data-test-field="${key}"]`).hasClass('select-text');
    assert.dom(`[data-test-field="${key}"]`).hasText(value);
    assert.dom(`input[data-test-field="${key}"]`).doesNotExist();
    assert.dom(`textarea[data-test-field="${key}"]`).doesNotExist();
    assert.dom(`[data-test-field="${key}"]`).doesNotHaveAttribute('readonly');
    assert.dom(`[data-test-field="${key}"]`).doesNotHaveAttribute('disabled');
}

function assertPublicUrl(assert, key, label, value) {
    assert.dom(`[data-test-sync-field="${key}"] label`).hasText(label);
    assert.dom(`input[data-test-field="${key}"]`).hasValue(value);
    assert.dom(`input[data-test-field="${key}"]`).isNotDisabled();
    assert.dom(`input[data-test-field="${key}"]`).doesNotHaveAttribute('readonly');
}

function assertUrlsStayOutOfPayload(assert, payload, publicUrls = {}) {
    assert.strictEqual(payload.auth.internal_webhook_receiver_url, undefined);
    assert.strictEqual(payload.auth.internal_oauth_redirect_url, undefined);
    assert.strictEqual(payload.sync.internal_webhook_receiver_url, undefined);
    assert.strictEqual(payload.sync.internal_oauth_redirect_url, undefined);
    assert.strictEqual(payload.sync.public_webhook_receiver_url, undefined);
    assert.strictEqual(payload.sync.public_oauth_redirect_url, undefined);
    assert.strictEqual(payload.auth.public_webhook_receiver_url, publicUrls.public_webhook_receiver_url ?? '');
    assert.strictEqual(payload.auth.public_oauth_redirect_url, publicUrls.public_oauth_redirect_url ?? '');
    assert.strictEqual(payload.auth.redirect_uri, undefined);
    assert.strictEqual(payload.auth.webhook_url, undefined);
    assert.strictEqual(payload.auth.public_receiver_url, undefined);
    assert.strictEqual(payload.sync.override, undefined);
    assert.strictEqual(payload.sync.batch_size, undefined);
    assert.strictEqual(payload.sync.enabled, undefined);
    assert.true(payload.sync.customer_enabled);
    assert.true(payload.sync.invoice_enabled);
    assert.true(payload.sync.payment_enabled);
    assert.true(payload.sync.wallet_enabled);
}

module('Integration | Component | quickbooks-settings', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:notifications', NotificationsStubService);
        this.notifications = this.owner.lookup('service:notifications');
    });

    test('company settings save the selected direction and show webhook and oauth urls as text', async function (assert) {
        this.set('settings', {
            client_id: 'org-id',
            redirect_uri: 'https://admin.example/callback',
            environment: 'sandbox',
            client_secret_set: true,
            webhook_verifier_set: true,
            internal_webhook_receiver_url: 'http://internal.example/quickbooks/int/v1/webhooks',
            public_webhook_receiver_url: 'http://internal.example/quickbooks/int/v1/webhooks',
            internal_oauth_redirect_url: 'http://internal.example/quickbooks/int/v1/oauth/callback',
            public_oauth_redirect_url: 'http://internal.example/quickbooks/int/v1/oauth/callback',
            sources: {
                client_id: 'admin',
                environment: 'admin',
            },
        });
        this.set('sync', {
            enabled: true,
            override: false,
            interval_minutes: 5,
            batch_size: 100,
            retry_limit: 5,
            default_backoff_seconds: 30,
            invoice_reference: 'quickbooks',
        });
        this.set('companySettings', { client_id: 'company-id', client_secret_set: true });
        this.set('companySync', { interval_minutes: 2, override: false });
        this.set('saved', null);
        this.set('onSave', (payload) => this.set('saved', payload));

        await render(hbs`
            <QuickbooksSettings
                @scope="company"
                @settings={{this.settings}}
                @sync={{this.sync}}
                @companySettings={{this.companySettings}}
                @companySync={{this.companySync}}
                @onSave={{this.onSave}}
            />
        `);

        assert.dom('.next-view-section-subheader-title').hasText('Connection');
        assert.dom('[data-test-override]').doesNotExist();
        assert.dom().doesNotIncludeText('Override');
        assert.dom().doesNotIncludeText("Enable to use this organization's QuickBooks keys.");
        assert.dom('.next-view-section-body').includesText('Authentication');
        assert.dom('[data-test-redirect-step]').hasText('Under Redirect URIs, paste the Public OAuth Redirect URL.');
        assert.dom('[data-test-redirect-step]').doesNotIncludeText('{{fleetbase.url}}');
        assert.dom('[data-test-redirect-step]').doesNotIncludeText('{{');
        assert.dom("[data-test-field-help='environment']").hasClass('text-xs');
        assert.dom("[data-test-field-help='environment']").hasClass('text-gray-400');
        assert.dom("[data-test-field-help='environment']").hasClass('mt-1');
        assert.dom('[data-test-credential-save-step]').hasText('Choose Save Changes, then Connect to QuickBooks. Connect uses the saved keys.');
        assert.dom("[data-test-field='redirect_uri']").doesNotExist();
        assert.dom("[data-test-field='webhook_verifier']").isNotDisabled();
        assert.dom('[data-test-verifier-set]').hasText('A verifier token is already saved. Leave this blank to keep it.');
        assert.dom('[data-test-admin-verifier]').doesNotExist();
        assert.dom().doesNotIncludeText('QUICKBOOKS_WEBHOOK_VERIFIER');
        assert.dom().doesNotIncludeText('If this address is localhost');
        assert.dom('[data-test-localhost-warning]').doesNotExist();
        assert.dom('.next-view-section-body').includesText('Schedule');
        assert.dom("[data-test-sync='enabled']").doesNotExist();
        assert.dom('input[aria-label="Sync"]').doesNotExist();
        assert.dom().doesNotIncludeText('Enable schedule');
        assert.dom().doesNotIncludeText('The schedule syncs records. Sync now and Reconcile still run when it is off.');
        assert.dom('.next-view-section-body').includesText('Data Resolution');
        assert
            .dom('[data-test-data-resolution-description]')
            .hasText('Controls the data to be synchronized, the data that should be accepted in the event of a conflict, and the directionality of the synchronization.');
        assert.dom().doesNotIncludeText('The Primary choice for each category decides which system wins when the records differ and which system supplies identifiers.');
        assert.dom("[data-test-field='environment']").hasValue('sandbox');
        assert.dom("[data-test-field='environment'] option[value='sandbox']").hasText('Sandbox');
        assert.dom("[data-test-field='environment'] option[value='production']").hasText('Production');
        assert.dom("[data-test-field='client_id']").hasValue('org-id');
        assert.dom("[data-test-field='client_id']").isNotDisabled();
        assert.dom(this.element.querySelector("[data-test-field='client_id']").closest('.input-group').querySelector('label')).hasClass('required');
        assert.dom(this.element.querySelector("[data-test-field='client_secret']").closest('.input-group').querySelector('label')).doesNotHaveClass('required');
        assert.dom("[data-test-sync-field='interval_minutes'] label").hasText('Sync Frequency (minutes)');
        const syncFrequencyHelp = 'Defines the time between possible sync operations. Sync operations will only occur when there is data to sync.';
        assert.dom("[data-test-sync-field='interval_minutes'] [data-test-field-help='interval_minutes']").hasText(syncFrequencyHelp);
        assert.strictEqual(document.body.textContent.split(syncFrequencyHelp).length - 1, 1);
        assert.dom("[data-test-sync-field='interval_minutes'] .fa-circle-info").doesNotExist();
        assert.dom("[data-test-sync-field='periodic_interval_hours']").doesNotIncludeText(syncFrequencyHelp);
        assert.dom("[data-test-sync-field='retry_limit']").doesNotIncludeText(syncFrequencyHelp);
        assert.dom("[data-test-sync-field='default_backoff_seconds']").doesNotIncludeText(syncFrequencyHelp);
        assert.dom("[data-test-sync='interval_minutes']").hasValue('5');
        assert.dom("[data-test-sync='interval_minutes']").isNotDisabled();
        assert.dom("[data-test-sync-field='periodic_interval_hours'] label").hasText('Full Sync Frequency (hours)');
        assert.dom("[data-test-sync='periodic_interval_hours']").hasValue('24');
        assert.dom("[data-test-sync-field='retry_limit'] label").hasText('Retry attempts');
        assert.dom("[data-test-sync-field='default_backoff_seconds'] label").hasText('Retry Delay (seconds)');
        assert.dom("[data-test-sync='batch_size']").doesNotExist();
        assert.dom().doesNotIncludeText('Records in each sync');
        assert.dom().doesNotIncludeText('Clear Enable');
        assert.dom().doesNotIncludeText('when Enable is on');
        assertSelectableUrl(assert, 'internal_webhook_receiver_url', 'Internal Webhook Receiver URL', 'http://internal.example/quickbooks/int/v1/webhooks');
        assertPublicUrl(assert, 'public_webhook_receiver_url', 'Public Webhook Receiver URL', 'http://internal.example/quickbooks/int/v1/webhooks');
        assertSelectableUrl(assert, 'internal_oauth_redirect_url', 'Internal Oauth Redirect URL', 'http://internal.example/quickbooks/int/v1/oauth/callback');
        assertPublicUrl(assert, 'public_oauth_redirect_url', 'Public OAuth Redirect URL', 'http://internal.example/quickbooks/int/v1/oauth/callback');
        assert.dom("[data-test-policy='customer'] [data-test-sync='customer_conflict']").exists();
        assert.dom("[data-test-sync='customer_enabled']").hasAttribute('role', 'checkbox');
        assert.dom("input[data-test-sync='customer_enabled']").doesNotExist();
        assert.dom("[data-test-sync-field='customer_enabled'] span.ml-2").hasText('Enable');
        assert.dom("[data-test-sync='customer_enabled']").isChecked();
        assert.dom("[data-test-policy='customer']").includesText('Enable');
        assert.dom('[data-test-connection-state] [data-test-import-checkbox]').doesNotExist();
        assert.dom("[data-test-policy='customer'] [data-test-import-checkbox]").hasAttribute('role', 'checkbox');
        assert.dom("input[data-test-import-checkbox]").doesNotExist();
        assert.dom("[data-test-sync-field='import_customers']").includesText('Import customers');
        assert.dom("[data-test-sync-field='import_customers'] [data-test-field-help='import_customers']").hasText(
            'On connect, copy QuickBooks customers into Fleetbase once, only when Customers is enabled in Data Resolution. If Customers is off, the import is skipped. Inactive customers and sub-customers are skipped. Existing Fleetbase names, emails, and phones are left as they are.'
        );
        assert.dom("[data-test-import-checkbox]").isNotChecked();
        assert.dom("[data-test-policy='invoice'] [data-test-import-checkbox]").doesNotExist();
        assert.dom("[data-test-policy='payment'] [data-test-import-checkbox]").doesNotExist();
        assert.dom("[data-test-policy='wallet'] [data-test-import-checkbox]").doesNotExist();
        assert.dom("[data-test-policy='customer'] [data-test-sync='customer_direction']").exists();
        assert.dom("[data-test-sync-field='customer_direction'] label").hasText('Sync direction');
        assert.dom("[data-test-sync='customer_direction']").hasValue('both');
        assert.dom("[data-test-sync='customer_direction']").isNotDisabled();
        assert.dom("[data-test-policy='customer'] [data-test-sync='customer_direction'] option[value='both']").hasText('Both');
        assert.dom("[data-test-policy='customer'] [data-test-sync='customer_direction'] option[value='outbound']").hasText('To QuickBooks');
        assert.dom("[data-test-policy='customer'] [data-test-sync='customer_direction'] option[value='inbound']").hasText('From QuickBooks');
        assert.dom("[data-test-policy='customer'] [data-test-sync='customer_direction'] option[value='off']").doesNotExist();

        await fillIn("[data-test-field='client_id']", '');
        await click('[data-test-save]');
        assert.dom("[data-test-error='client_id']").exists();
        assert.dom("[data-test-error='redirect_uri']").doesNotExist();
        assert.strictEqual(this.saved, null);

        await fillIn("[data-test-field='client_id']", 'changed-id');
        await fillIn("[data-test-field='public_webhook_receiver_url']", 'https://edited.example/hooks');
        await fillIn("[data-test-field='public_oauth_redirect_url']", 'https://edited.example/oauth');
        await fillIn("[data-test-sync='customer_conflict']", 'quickbooks');
        await click('[data-test-save]');
        assert.strictEqual(this.saved.auth.client_id, 'changed-id');
        assert.strictEqual(this.saved.auth.environment, 'sandbox');
        assert.strictEqual(this.saved.sync.enabled, undefined);
        assert.strictEqual(this.saved.sync.interval_minutes, 5);
        // Stored invoice_reference is quickbooks, but conflict is unset, so Primary is Fleetbase for both keys.
        assert.strictEqual(this.saved.sync.invoice_conflict, 'fleetbase');
        assert.strictEqual(this.saved.sync.invoice_reference, 'fleetbase');
        assert.strictEqual(this.saved.sync.customer_conflict, 'quickbooks');
        assert.strictEqual(this.saved.sync.customer_reference, 'quickbooks');
        assert.strictEqual(this.saved.sync.customer_direction, 'both');
        assert.strictEqual(this.saved.sync.invoice_direction, 'both');
        assert.strictEqual(this.saved.sync.payment_direction, 'both');
        assert.strictEqual(this.saved.sync.wallet_direction, 'both');
        assert.strictEqual(this.saved.sync.periodic_interval_hours, 24);
        assert.strictEqual(this.saved.auth.webhook_verifier, undefined);
        assert.strictEqual(this.saved.auth.client_secret, undefined);
        assertUrlsStayOutOfPayload(assert, this.saved, {
            public_webhook_receiver_url: 'https://edited.example/hooks',
            public_oauth_redirect_url: 'https://edited.example/oauth',
        });
        assert.dom('[data-test-policy="wallet"]').includesText('Primary');
        assert.dom('[data-test-policy="wallet"]').includesText('Sync direction');
        assert.dom('[data-test-policy="wallet"]').includesText('Enable');
        assert.dom("[data-test-sync='wallet_enabled']").isChecked();
        assert.dom("[data-test-policy='wallet'] option[value='fleetbase']").hasText('Fleetbase');
        assert.dom("[data-test-policy='wallet'] option[value='quickbooks']").hasText('QuickBooks');
        assert.dom("[data-test-policy='wallet'] option[value='report']").doesNotExist();
        assert.dom("[data-test-policy='wallet'] option[value='off']").doesNotExist();
        assert.dom("[data-test-sync='wallet_reference']").doesNotExist();
    });

    test('organization settings require the Intuit app fields before saving', async function (assert) {
        this.set('settings', { client_id: '', redirect_uri: '', environment: 'sandbox', client_secret_set: false });
        this.set('sync', {
            enabled: true,
            override: true,
            interval_minutes: 5,
            batch_size: 100,
            retry_limit: 5,
            default_backoff_seconds: 30,
        });
        this.set('saved', null);
        this.set('onSave', (payload) => this.set('saved', payload));

        await render(hbs`
            <QuickbooksSettings @settings={{this.settings}} @sync={{this.sync}} @onSave={{this.onSave}} />
        `);

        assert.dom('[data-test-settings-scope]').hasAttribute('data-test-settings-scope', 'company');
        assert.dom().doesNotIncludeText('Admin → QuickBooks');
        assert.dom('[data-test-credential-save-step]').hasText('Choose Save Changes, then Connect to QuickBooks. Connect uses the saved keys.');
        assert.dom('[data-test-redirect-step]').hasText('Under Redirect URIs, paste the Public OAuth Redirect URL.');
        assert.dom('[data-test-redirect-step]').doesNotIncludeText('{{fleetbase.url}}');
        assert.dom("[data-test-field-help='environment']").hasClass('text-xs');
        assert.dom("[data-test-field-help='environment']").hasClass('text-gray-400');
        assert.dom("[data-test-field-help='environment']").hasClass('mt-1');
        assert.dom('[data-test-connection-state]').hasAttribute('data-test-connection-state', 'loading');
        assert.dom('[data-test-connect]').doesNotExist();
        assert.dom('[data-test-admin-disconnect]').doesNotExist();
        assert.dom(this.element.querySelector("[data-test-field='client_secret']").closest('.input-group').querySelector('label')).hasClass('required');

        await click('[data-test-save]');
        assert.dom("[data-test-error='client_id']").exists();
        assert.dom("[data-test-error='redirect_uri']").doesNotExist();
        assert.dom("[data-test-error='client_secret']").exists();
        assert.strictEqual(this.saved, null);

        await fillIn("[data-test-field='client_id']", 'admin-id');
        await fillIn("[data-test-field='client_secret']", 'secret');
        await click('[data-test-save]');
        assert.strictEqual(this.saved.auth.client_id, 'admin-id');
        assert.strictEqual(this.saved.auth.client_secret, 'secret');
        assert.strictEqual(this.saved.auth.environment, 'sandbox');
        assert.strictEqual(this.saved.sync.interval_minutes, 5);
        assert.strictEqual(this.saved.sync.enabled, undefined);
        assert.strictEqual(this.saved.scope, 'company');
        assertUrlsStayOutOfPayload(assert, this.saved);
        assert.deepEqual(this.notifications.messages.at(-1), ['success', 'QuickBooks settings saved.']);
    });

    test('save shows a success notification when settings are stored', async function (assert) {
        this.set('settings', {
            client_id: 'id',
            redirect_uri: 'https://example.test/callback',
            environment: 'sandbox',
            client_secret_set: true,
        });
        this.set('sync', { enabled: true, override: true, interval_minutes: 5, batch_size: 100, retry_limit: 5, default_backoff_seconds: 30 });
        this.set('onSave', () => Promise.resolve());

        await render(hbs`
            <QuickbooksSettings @settings={{this.settings}} @sync={{this.sync}} @onSave={{this.onSave}} />
        `);

        assert.dom(this.element.querySelector("[data-test-field='client_id']").closest('.input-group').querySelector('label')).hasClass('required');
        assert.dom(this.element.querySelector("[data-test-field='client_secret']").closest('.input-group').querySelector('label')).doesNotHaveClass('required');

        await click('[data-test-save]');
        assert.deepEqual(this.notifications.messages.at(-1), ['success', 'QuickBooks settings saved.']);
    });

    test('saving without changing Primary stores the choice shown on screen', async function (assert) {
        this.set('settings', {
            client_id: 'id',
            redirect_uri: 'https://example.test/callback',
            environment: 'sandbox',
            client_secret_set: true,
        });
        this.set('sync', {
            enabled: true,
            override: true,
            interval_minutes: 5,
            batch_size: 100,
            retry_limit: 5,
            default_backoff_seconds: 30,
            customer_conflict: 'report',
            customer_reference: 'quickbooks',
            invoice_conflict: 'quickbooks',
            invoice_reference: 'fleetbase',
            payment_conflict: 'fleetbase',
            payment_reference: 'quickbooks',
            wallet_conflict: 'report',
            wallet_reference: 'fleetbase',
        });
        this.set('saved', null);
        this.set('onSave', (payload) => this.set('saved', payload));

        await render(hbs`
            <QuickbooksSettings @settings={{this.settings}} @sync={{this.sync}} @onSave={{this.onSave}} />
        `);

        assert.dom("[data-test-sync='customer_conflict']").hasValue('fleetbase');
        assert.dom("[data-test-sync='invoice_conflict']").hasValue('quickbooks');
        assert.dom("[data-test-sync='payment_conflict']").hasValue('fleetbase');
        assert.dom("[data-test-sync='wallet_conflict']").hasValue('fleetbase');

        await click('[data-test-save]');
        assert.strictEqual(this.saved.sync.customer_conflict, 'fleetbase');
        assert.strictEqual(this.saved.sync.customer_reference, 'fleetbase');
        assert.strictEqual(this.saved.sync.invoice_conflict, 'quickbooks');
        assert.strictEqual(this.saved.sync.invoice_reference, 'quickbooks');
        assert.strictEqual(this.saved.sync.payment_conflict, 'fleetbase');
        assert.strictEqual(this.saved.sync.payment_reference, 'fleetbase');
        assert.strictEqual(this.saved.sync.wallet_conflict, 'fleetbase');
        assert.strictEqual(this.saved.sync.wallet_reference, 'fleetbase');
        assert.strictEqual(this.saved.sync.customer_direction, 'both');
        assert.dom("[data-test-policy='customer'] [data-test-sync='customer_direction']").exists();
        assert.dom("[data-test-sync='customer_direction']").hasValue('both');
        assertUrlsStayOutOfPayload(assert, this.saved);
    });

    test('save waits until organization settings have loaded', async function (assert) {
        this.set('settingsLoadFailed', true);
        this.set('settingsLoaded', false);
        this.set('settings', null);
        this.set('sync', { interval_minutes: 5 });
        this.set('saved', null);
        this.set('onSave', (payload) => this.set('saved', payload));

        await render(hbs`
            <QuickbooksSettings
                @scope="company"
                @settings={{this.settings}}
                @sync={{this.sync}}
                @settingsLoadFailed={{this.settingsLoadFailed}}
                @settingsLoaded={{this.settingsLoaded}}
                @onSave={{this.onSave}}
            />
        `);

        assert.dom('[data-test-settings-unavailable]').hasText('QuickBooks settings could not be loaded.');
        assert.dom('[data-test-override]').doesNotExist();
        assert.dom('[data-test-save]').isDisabled();
        assert.strictEqual(this.saved, null);
        assert.deepEqual(this.notifications.messages, []);

        this.set('settingsLoadFailed', false);
        assert.dom('[data-test-settings-unavailable]').doesNotExist();
        assert.dom('[data-test-save]').isNotDisabled();
        await click('[data-test-save]');
        assert.strictEqual(this.saved, null);
        assert.deepEqual(this.notifications.messages, []);

        this.set('settings', {
            client_id: 'id',
            redirect_uri: 'https://example.test/callback',
            environment: 'sandbox',
            client_secret_set: true,
        });
        this.set('sync', {
            enabled: true,
            override: false,
            interval_minutes: 5,
            batch_size: 100,
            retry_limit: 5,
            default_backoff_seconds: 30,
        });
        this.set('settingsLoadFailed', false);
        this.set('settingsLoaded', true);

        assert.dom('[data-test-settings-unavailable]').doesNotExist();
        await click('[data-test-save]');
        assert.strictEqual(this.saved.scope, 'company');
        assert.strictEqual(this.saved.auth.client_id, 'id');
        assert.strictEqual(this.saved.auth.environment, 'sandbox');
        assert.strictEqual(this.saved.sync.enabled, undefined);
        assert.strictEqual(this.saved.sync.interval_minutes, 5);
        assert.strictEqual(this.saved.sync.customer_direction, 'both');
        assertUrlsStayOutOfPayload(assert, this.saved);
        assert.deepEqual(this.notifications.messages.at(-1), ['success', 'QuickBooks settings saved.']);
    });

    test('a blank webhook verifier stays out of the save and direction is independent of Primary', async function (assert) {
        this.set('settings', {
            client_id: 'id',
            redirect_uri: 'https://example.test/callback',
            environment: 'sandbox',
            client_secret_set: true,
            webhook_verifier_set: true,
            internal_webhook_receiver_url: 'http://internal.example/hooks',
            public_webhook_receiver_url: 'http://internal.example/hooks',
            internal_oauth_redirect_url: 'http://internal.example/oauth',
            public_oauth_redirect_url: 'http://internal.example/oauth',
        });
        this.set('sync', {
            enabled: true,
            override: true,
            interval_minutes: 5,
            batch_size: 100,
            retry_limit: 5,
            default_backoff_seconds: 30,
            customer_conflict: 'quickbooks',
            customer_reference: 'fleetbase',
        });
        this.set('saved', null);
        this.set('onSave', (payload) => this.set('saved', payload));

        await render(hbs`
            <QuickbooksSettings @settings={{this.settings}} @sync={{this.sync}} @onSave={{this.onSave}} />
        `);

        assert.dom("[data-test-field='webhook_verifier']").hasValue('');
        assert.dom("[data-test-field='webhook_verifier']").hasAttribute('placeholder', 'A verifier token is saved');
        assert.dom('[data-test-verifier-set]').hasText('A verifier token is already saved. Leave this blank to keep it.');
        assertSelectableUrl(assert, 'internal_webhook_receiver_url', 'Internal Webhook Receiver URL', 'http://internal.example/hooks');
        assertPublicUrl(assert, 'public_webhook_receiver_url', 'Public Webhook Receiver URL', 'http://internal.example/hooks');
        assertSelectableUrl(assert, 'internal_oauth_redirect_url', 'Internal Oauth Redirect URL', 'http://internal.example/oauth');
        assertPublicUrl(assert, 'public_oauth_redirect_url', 'Public OAuth Redirect URL', 'http://internal.example/oauth');
        assert.dom("[data-test-field-help='environment']").hasClass('text-xs');
        assert.dom("[data-test-field-help='environment']").hasClass('text-gray-400');
        assert.dom("[data-test-field-help='environment']").hasClass('mt-1');
        assert.dom('[data-test-localhost-warning]').doesNotExist();
        assert.dom().doesNotIncludeText('{{fleetbase.url}}');
        assert.dom('[data-test-admin-verifier]').doesNotExist();
        assert.dom("[data-test-sync='customer_conflict']").hasValue('quickbooks');
        assert.dom("[data-test-sync='customer_direction']").hasValue('both');

        await click('[data-test-save]');
        assert.strictEqual(this.saved.auth.webhook_verifier, undefined);
        assert.strictEqual(this.saved.sync.customer_conflict, 'quickbooks');
        assert.strictEqual(this.saved.sync.customer_reference, 'quickbooks');
        assert.strictEqual(this.saved.sync.customer_direction, 'both');
        assertUrlsStayOutOfPayload(assert, this.saved, {
            public_webhook_receiver_url: 'http://internal.example/hooks',
            public_oauth_redirect_url: 'http://internal.example/oauth',
        });

        this.set('settings', {
            client_id: 'id',
            redirect_uri: 'https://example.test/callback',
            environment: 'sandbox',
            client_secret_set: true,
            webhook_verifier_set: true,
            internal_webhook_receiver_url: 'http://localhost/quickbooks/int/v1/webhooks',
            public_webhook_receiver_url: 'http://localhost/quickbooks/int/v1/webhooks',
            internal_oauth_redirect_url: 'http://localhost/quickbooks/int/v1/oauth/callback',
            public_oauth_redirect_url: 'http://localhost/quickbooks/int/v1/oauth/callback',
        });
        assert.dom("[data-test-field='internal_webhook_receiver_url']").hasText('http://localhost/quickbooks/int/v1/webhooks');
        assert.dom("[data-test-field='public_webhook_receiver_url']").hasValue('http://localhost/quickbooks/int/v1/webhooks');
        assert.dom("[data-test-field='internal_oauth_redirect_url']").hasText('http://localhost/quickbooks/int/v1/oauth/callback');
        assert.dom("[data-test-field='public_oauth_redirect_url']").hasValue('http://localhost/quickbooks/int/v1/oauth/callback');
        assert.dom('[data-test-localhost-warning]').doesNotExist();
        assert.dom().doesNotIncludeText('If this address is localhost');
        assert.dom().doesNotIncludeText('{{fleetbase.url}}');

        this.set('settings', {
            client_id: 'id',
            redirect_uri: 'https://example.test/callback',
            environment: 'sandbox',
            client_secret_set: true,
            webhook_verifier_set: true,
            internal_webhook_receiver_url: 'http://internal.example/hooks',
            public_webhook_receiver_url: 'https://public.example/hooks',
            internal_oauth_redirect_url: 'http://internal.example/oauth',
            public_oauth_redirect_url: 'https://public.example/oauth',
        });
        assert.dom("[data-test-field='internal_webhook_receiver_url']").hasText('http://internal.example/hooks');
        assert.dom("[data-test-field='public_webhook_receiver_url']").hasValue('https://public.example/hooks');
        assert.dom("[data-test-field='internal_oauth_redirect_url']").hasText('http://internal.example/oauth');
        assert.dom("[data-test-field='public_oauth_redirect_url']").hasValue('https://public.example/oauth');

        await fillIn("[data-test-sync='customer_direction']", 'outbound');
        await fillIn("[data-test-field='webhook_verifier']", 'verifier-token');
        await click('[data-test-save]');
        assert.strictEqual(this.saved.sync.customer_direction, 'outbound');
        assert.strictEqual(this.saved.sync.customer_conflict, 'quickbooks');
        assert.strictEqual(this.saved.sync.customer_reference, 'quickbooks');
        assert.strictEqual(this.saved.auth.webhook_verifier, 'verifier-token');
        assert.strictEqual(this.saved.auth.client_secret, undefined);
        assertUrlsStayOutOfPayload(assert, this.saved, {
            public_webhook_receiver_url: 'https://public.example/hooks',
            public_oauth_redirect_url: 'https://public.example/oauth',
        });
    });

    test('a missing environment defaults to production and a stored sandbox value stays sandbox', async function (assert) {
        this.set('settings', {
            client_id: 'id',
            redirect_uri: 'https://example.test/callback',
            client_secret_set: true,
        });
        this.set('sync', { enabled: true, override: true, interval_minutes: 5, retry_limit: 5, default_backoff_seconds: 30 });
        this.set('saved', null);
        this.set('onSave', (payload) => this.set('saved', payload));

        await render(hbs`
            <QuickbooksSettings @settings={{this.settings}} @sync={{this.sync}} @onSave={{this.onSave}} />
        `);

        assert.dom("[data-test-field='environment']").hasValue('production');
        assert.dom("[data-test-sync='batch_size']").doesNotExist();
        assertSelectableUrl(assert, 'internal_webhook_receiver_url', 'Internal Webhook Receiver URL', '');
        assertPublicUrl(assert, 'public_webhook_receiver_url', 'Public Webhook Receiver URL', '');
        assertSelectableUrl(assert, 'internal_oauth_redirect_url', 'Internal Oauth Redirect URL', '');
        assertPublicUrl(assert, 'public_oauth_redirect_url', 'Public OAuth Redirect URL', '');
        await click('[data-test-save]');
        assert.strictEqual(this.saved.auth.environment, 'production');
        assertUrlsStayOutOfPayload(assert, this.saved);

        this.set('settings', {
            client_id: 'id',
            redirect_uri: 'https://example.test/callback',
            environment: 'sandbox',
            client_secret_set: true,
        });
        assert.dom("[data-test-field='environment']").hasValue('sandbox');
    });

    test('a stored off direction saves as both and the selected direction is what is stored', async function (assert) {
        this.set('settings', {
            client_id: 'id',
            redirect_uri: 'https://example.test/callback',
            environment: 'production',
            client_secret_set: true,
        });
        this.set('sync', {
            enabled: true,
            override: true,
            interval_minutes: 5,
            retry_limit: 5,
            default_backoff_seconds: 30,
            customer_direction: 'outbound',
            invoice_direction: 'inbound',
            payment_direction: 'off',
            wallet_direction: 'both',
        });
        this.set('saved', null);
        this.set('onSave', (payload) => this.set('saved', payload));

        await render(hbs`
            <QuickbooksSettings @settings={{this.settings}} @sync={{this.sync}} @onSave={{this.onSave}} />
        `);

        assert.dom("[data-test-sync='customer_enabled']").isChecked();
        assert.dom("[data-test-sync='invoice_enabled']").isChecked();
        assert.dom("[data-test-sync='payment_enabled']").isChecked();
        assert.dom("[data-test-sync='wallet_enabled']").isChecked();
        assert.dom("[data-test-sync='customer_direction']").hasValue('outbound');
        assert.dom("[data-test-sync='invoice_direction']").hasValue('inbound');
        assert.dom("[data-test-sync='payment_direction']").hasValue('both');
        assert.dom("[data-test-sync='wallet_direction']").hasValue('both');
        assert.dom("option[value='off']").doesNotExist();

        await fillIn("[data-test-sync='customer_direction']", 'inbound');
        await click('[data-test-save]');
        assert.strictEqual(this.saved.sync.customer_direction, 'inbound');
        assert.strictEqual(this.saved.sync.invoice_direction, 'inbound');
        assert.strictEqual(this.saved.sync.payment_direction, 'both');
        assert.strictEqual(this.saved.sync.wallet_direction, 'both');
        assert.true(this.saved.sync.customer_enabled);
        assertUrlsStayOutOfPayload(assert, this.saved);
    });

    test('an unchecked entity disables Primary and Sync direction and is saved off', async function (assert) {
        this.set('settings', {
            client_id: 'id',
            environment: 'production',
            client_secret_set: true,
        });
        this.set('sync', {
            enabled: true,
            interval_minutes: 5,
            retry_limit: 5,
            default_backoff_seconds: 30,
            customer_conflict: 'quickbooks',
            customer_reference: 'fleetbase',
            customer_direction: 'outbound',
            payment_enabled: false,
            payment_conflict: 'quickbooks',
            payment_reference: 'fleetbase',
            payment_direction: 'inbound',
        });
        this.set('saved', null);
        this.set('onSave', (payload) => this.set('saved', payload));

        await render(hbs`<QuickbooksSettings @settings={{this.settings}} @sync={{this.sync}} @onSave={{this.onSave}} />`);

        assert.dom("[data-test-sync='customer_enabled']").hasAttribute('role', 'checkbox');
        assert.dom("input[data-test-sync='customer_enabled']").doesNotExist();
        assert.dom("[data-test-sync='customer_enabled']").isChecked();
        assert.dom("[data-test-sync='payment_enabled']").hasAttribute('role', 'checkbox');
        assert.dom("[data-test-sync='payment_enabled']").isNotChecked();
        assert.dom("[data-test-sync='customer_conflict']").hasValue('quickbooks');
        assert.dom("[data-test-sync='customer_direction']").hasValue('outbound');
        assert.dom("[data-test-sync='payment_conflict']").hasValue('quickbooks');
        assert.dom("[data-test-sync='payment_direction']").hasValue('inbound');
        assert.dom("[data-test-sync='payment_direction']").isDisabled();
        assert.dom("[data-test-sync='payment_conflict']").isDisabled();
        assert.dom("[data-test-sync='customer_direction']").isNotDisabled();
        assert.dom(this.element.querySelector("[data-test-sync='customer_conflict']").closest('.input-group').querySelector('label')).hasClass('required');
        assert.dom(this.element.querySelector("[data-test-sync='customer_direction']").closest('.input-group').querySelector('label')).hasClass('required');
        assert.dom(this.element.querySelector("[data-test-sync='payment_conflict']").closest('.input-group').querySelector('label')).doesNotHaveClass('required');
        assert.dom(this.element.querySelector("[data-test-sync='payment_direction']").closest('.input-group').querySelector('label')).doesNotHaveClass('required');

        await click("[data-test-sync='customer_enabled']");
        assert.dom("[data-test-sync='customer_enabled']").isNotChecked();
        assert.dom("[data-test-sync='customer_direction']").isDisabled();
        assert.dom("[data-test-sync='customer_conflict']").isDisabled();
        assert.dom("[data-test-sync='customer_conflict']").hasValue('quickbooks');
        assert.dom("[data-test-sync='customer_direction']").hasValue('outbound');
        assert.dom(this.element.querySelector("[data-test-sync='customer_conflict']").closest('.input-group').querySelector('label')).doesNotHaveClass('required');
        assert.dom(this.element.querySelector("[data-test-sync='customer_direction']").closest('.input-group').querySelector('label')).doesNotHaveClass('required');

        await click("[data-test-sync='customer_enabled']");
        assert.dom("[data-test-sync='customer_enabled']").isChecked();
        assert.dom("[data-test-sync='customer_direction']").isNotDisabled();
        assert.dom(this.element.querySelector("[data-test-sync='customer_conflict']").closest('.input-group').querySelector('label')).hasClass('required');

        await click("[data-test-sync='customer_enabled']");
        await click('[data-test-save]');
        assert.false(this.saved.sync.customer_enabled);
        assert.false(this.saved.sync.payment_enabled);
        assert.true(this.saved.sync.invoice_enabled);
        assert.true(this.saved.sync.wallet_enabled);
        assert.strictEqual(this.saved.sync.customer_conflict, 'quickbooks');
        assert.strictEqual(this.saved.sync.customer_reference, 'quickbooks');
        assert.strictEqual(this.saved.sync.customer_direction, 'outbound');
        assert.strictEqual(this.saved.sync.payment_conflict, 'quickbooks');
        assert.strictEqual(this.saved.sync.payment_reference, 'quickbooks');
        assert.strictEqual(this.saved.sync.payment_direction, 'inbound');
        assert.dom("[data-test-error='customer_conflict']").doesNotExist();
        assert.dom("[data-test-error='customer_direction']").doesNotExist();
        assert.dom("[data-test-error='payment_conflict']").doesNotExist();
        assert.dom("[data-test-error='payment_direction']").doesNotExist();
    });

    test('the customers import control is sent on connect and is not saved with settings', async function (assert) {
        this.set('settings', {
            client_id: 'id',
            environment: 'sandbox',
            client_secret_set: true,
        });
        this.set('sync', {
            interval_minutes: 5,
            periodic_interval_hours: 24,
            retry_limit: 5,
            default_backoff_seconds: 30,
        });
        this.set('connection', null);
        this.set('payload', null);
        this.set('saved', null);
        this.set('onConnect', (payload) => this.set('payload', payload));
        this.set('onSave', (payload) => this.set('saved', payload));

        await render(hbs`
            <QuickbooksSettings
                @settings={{this.settings}}
                @sync={{this.sync}}
                @connection={{this.connection}}
                @onConnect={{this.onConnect}}
                @onSave={{this.onSave}}
            />
        `);

        assert.dom('[data-test-connection-state] [data-test-import-checkbox]').doesNotExist();
        assert.dom("[data-test-policy='customer'] [data-test-import-checkbox]").isNotChecked();
        assert.dom("[data-test-policy='invoice'] [data-test-import-checkbox]").doesNotExist();
        await click('[data-test-connect]');
        assert.deepEqual(this.payload, { import_customers: false });

        await click("[data-test-policy='customer'] [data-test-import-checkbox]");
        assert.dom("[data-test-policy='customer'] [data-test-import-checkbox]").isChecked();
        await click('[data-test-connect]');
        assert.deepEqual(this.payload, { import_customers: true });

        await click('[data-test-save]');
        assert.strictEqual(this.saved.sync.import_customers, undefined);
        assert.strictEqual(this.saved.auth.import_customers, undefined);
        assert.true(this.saved.sync.customer_enabled);
    });
});
