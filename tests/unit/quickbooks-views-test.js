import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { fieldState, normalizeSyncDirection, secretPresentation, validateSettings } from '@unchartedwaters/fleetbase-quickbooks/utils/settings-form';
import { connectionState } from '@unchartedwaters/fleetbase-quickbooks/utils/connection-view';
import { activityRows } from '@unchartedwaters/fleetbase-quickbooks/utils/activity-view';
import ConnectionRoute from '@unchartedwaters/fleetbase-quickbooks/routes/connection';
import ActionsRoute from '@unchartedwaters/fleetbase-quickbooks/routes/actions';
import ActivityRoute from '@unchartedwaters/fleetbase-quickbooks/routes/activity';
import Service from '@ember/service';

module('Unit | QuickBooks views', function (hooks) {
    setupTest(hooks);

    test('the connection route redirects to Connection', function (assert) {
        const replaced = [];
        this.owner.register(
            'service:host-router',
            class extends Service {
                replaceWith(name) {
                    replaced.push(name);
                }
            }
        );
        this.owner.register('route:quickbooks-connection', ConnectionRoute);

        this.owner.lookup('route:quickbooks-connection').beforeModel();

        assert.deepEqual(replaced, ['console.quickbooks.settings']);
    });

    test('the actions route redirects to Connection', function (assert) {
        const replaced = [];
        this.owner.register(
            'service:host-router',
            class extends Service {
                replaceWith(name) {
                    replaced.push(name);
                }
            }
        );
        this.owner.register('route:quickbooks-actions', ActionsRoute);

        this.owner.lookup('route:quickbooks-actions').beforeModel();

        assert.deepEqual(replaced, ['console.quickbooks.settings']);
    });

    test('the activity route redirects to Connection', function (assert) {
        const replaced = [];
        this.owner.register(
            'service:host-router',
            class extends Service {
                replaceWith(name) {
                    replaced.push(name);
                }
            }
        );
        this.owner.register('route:quickbooks-activity', ActivityRoute);

        this.owner.lookup('route:quickbooks-activity').beforeModel();

        assert.deepEqual(replaced, ['console.quickbooks.settings']);
    });

    test('settings fields carry the stored value and omit a blank secret', function (assert) {
        assert.deepEqual(fieldState({ client_id: 'company-id' }, 'client_id'), { value: 'company-id' });
        assert.deepEqual(fieldState({}, 'client_id'), { value: '' });

        assert.deepEqual(secretPresentation({ client_secret_set: true }), { value: '', set: true, hint: '' });
        assert.deepEqual(secretPresentation({ client_secret_set: true, client_secret_hint: 'a1b2' }), { value: '', set: true, hint: 'a1b2' });

        assert.deepEqual(secretPresentation({ production_client_secret_set: true, production_client_secret_hint: 'z9' }, 'production_client_secret'), { value: '', set: true, hint: 'z9' });

        const withoutAuth = validateSettings({ auth: {}, sync: {}, saved: {} });
        assert.ok(withoutAuth.production_client_id);
        assert.strictEqual(withoutAuth.redirect_uri, undefined);

        const invalid = validateSettings({
            auth: { production_client_id: '', redirect_uri: 'callback', production_client_secret: '' },
            sync: { interval_minutes: 0, retry_limit: 5, default_backoff_seconds: 4 },
            saved: {},
        });
        assert.ok(invalid.production_client_id);
        assert.strictEqual(invalid.redirect_uri, undefined);
        assert.ok(invalid.interval_minutes);
        assert.ok(invalid.default_backoff_seconds);
        assert.strictEqual(invalid.batch_size, undefined);
        assert.strictEqual(invalid.customer_direction, 'quickbooks.validation.direction-customer');

        const badRetry = validateSettings({
            auth: { production_client_id: 'id', production_client_secret: 'secret' },
            sync: { retry_limit: 0 },
            saved: { production_client_secret_set: true },
        });
        assert.strictEqual(badRetry.retry_limit, 'quickbooks.validation.retry-limit');

        const sandboxOnly = validateSettings({
            auth: { production_client_id: '', sandbox_client_id: 'id', sandbox_client_secret: 'secret' },
            sync: {
                interval_minutes: 5,
                periodic_interval_hours: 24,
                retry_limit: 5,
                default_backoff_seconds: 30,
                customer_conflict: 'fleetbase',
                customer_reference: 'fleetbase',
                customer_direction: 'both',
                invoice_conflict: 'fleetbase',
                invoice_reference: 'fleetbase',
                invoice_direction: 'both',
                payment_conflict: 'fleetbase',
                payment_reference: 'fleetbase',
                payment_direction: 'outbound',
                wallet_conflict: 'fleetbase',
                wallet_reference: 'fleetbase',
                wallet_direction: 'inbound',
            },
            saved: {},
        });
        assert.strictEqual(sandboxOnly.production_client_id, undefined);
        assert.strictEqual(sandboxOnly.sandbox_client_secret, undefined);
        assert.strictEqual(sandboxOnly.customer_direction, undefined);
        assert.strictEqual(sandboxOnly.redirect_uri, undefined);
        assert.strictEqual(sandboxOnly.batch_size, undefined);

        const offDirection = validateSettings({
            auth: { production_client_id: 'id', production_client_secret: 'secret' },
            sync: {
                interval_minutes: 5,
                periodic_interval_hours: 24,
                retry_limit: 5,
                default_backoff_seconds: 30,
                customer_conflict: 'fleetbase',
                customer_reference: 'fleetbase',
                customer_direction: 'off',
                invoice_conflict: 'fleetbase',
                invoice_reference: 'fleetbase',
                invoice_direction: 'both',
                payment_conflict: 'fleetbase',
                payment_reference: 'fleetbase',
                payment_direction: 'outbound',
                wallet_conflict: 'fleetbase',
                wallet_reference: 'fleetbase',
                wallet_direction: 'inbound',
            },
            saved: {},
        });
        assert.strictEqual(offDirection.customer_direction, 'quickbooks.validation.direction-customer');
        assert.strictEqual(normalizeSyncDirection('off'), 'both');
        assert.strictEqual(normalizeSyncDirection('outbound'), 'outbound');
        assert.strictEqual(normalizeSyncDirection(undefined), 'both');

        const disabledCustomer = validateSettings({
            auth: { production_client_id: 'id', production_client_secret: 'secret' },
            sync: {
                interval_minutes: 5,
                periodic_interval_hours: 24,
                retry_limit: 5,
                default_backoff_seconds: 30,
                customer_enabled: false,
                customer_direction: 'off',
                invoice_conflict: 'fleetbase',
                invoice_reference: 'fleetbase',
                invoice_direction: 'both',
                payment_conflict: 'fleetbase',
                payment_reference: 'fleetbase',
                payment_direction: 'outbound',
                wallet_conflict: 'fleetbase',
                wallet_reference: 'fleetbase',
                wallet_direction: 'inbound',
            },
            saved: {},
        });
        assert.strictEqual(disabledCustomer.customer_conflict, undefined);
        assert.strictEqual(disabledCustomer.customer_reference, undefined);
        assert.strictEqual(disabledCustomer.customer_direction, undefined);
        assert.deepEqual(disabledCustomer, {});
    });

    test('the tax source must be QuickBooks or Fleetbase, whichever way invoices sync', (assert) => {
        const check = (sync) =>
            validateSettings({ auth: null, sync: { interval_minutes: 5, periodic_interval_hours: 24, retry_limit: 5, default_backoff_seconds: 30, ...sync }, syncOnly: true });
        const sent = { invoice_conflict: 'quickbooks', invoice_reference: 'quickbooks', invoice_direction: 'both' };

        assert.strictEqual(check({ ...sent, tax_source: 'quickbooks' }).tax_source, undefined);
        assert.strictEqual(check({ ...sent, tax_source: 'fleetbase' }).tax_source, undefined);
        assert.strictEqual(check({ ...sent, invoice_direction: 'outbound', tax_source: 'fleetbase' }).tax_source, undefined);
        assert.strictEqual(check({ ...sent, tax_source: 'agency' }).tax_source, 'quickbooks.validation.tax-source');
        assert.strictEqual(check({ ...sent, invoice_direction: 'inbound', tax_source: 'fleetbase' }).tax_source, undefined);
        assert.strictEqual(check({ ...sent, invoice_enabled: false, tax_source: 'fleetbase' }).tax_source, undefined);
        assert.strictEqual(check({ ...sent, invoice_direction: 'inbound', tax_source: 'quickbooks' }).tax_source, undefined);
        // An older form that does not send the field is left to the server.
        assert.strictEqual(check(sent).tax_source, undefined);
    });

    test('a saved Client secret is kept only for the Client ID it was saved with', (assert) => {
        const sync = { interval_minutes: 5, periodic_interval_hours: 24, retry_limit: 5, default_backoff_seconds: 30 };
        const check = (clientId, savedClientId, secret = '') =>
            validateSettings({
                auth: { production_client_id: clientId, production_client_secret: secret },
                sync,
                saved: { production_client_id: savedClientId, production_client_secret_set: true },
            }).production_client_secret;

        assert.strictEqual(check('id', 'id'), undefined);
        assert.strictEqual(check(' id ', 'id'), undefined);
        assert.strictEqual(check('new-id', 'id'), 'quickbooks.validation.client-secret');
        assert.strictEqual(check('new-id', 'id', 'new-secret'), undefined);
        // A blank Client ID shows only its own error.
        assert.strictEqual(check('', 'id'), undefined);
        // A secret saved before any Client ID has no Client ID to belong to, so it is kept.
        assert.strictEqual(check('new-id', ''), undefined);
    });

    test('a saved webhook verifier is kept only for the Client ID it was saved with', (assert) => {
        const sync = { interval_minutes: 5, periodic_interval_hours: 24, retry_limit: 5, default_backoff_seconds: 30 };
        const check = (clientId, savedClientId, verifier = '', verifierSet = true) =>
            validateSettings({
                auth: { production_client_id: clientId, production_client_secret: 'secret', production_webhook_verifier: verifier },
                sync,
                saved: { production_client_id: savedClientId, production_client_secret_set: true, production_webhook_verifier_set: verifierSet },
            }).production_webhook_verifier;

        assert.strictEqual(check('id', 'id'), undefined);
        assert.strictEqual(check('new-id', 'id'), 'quickbooks.validation.webhook-verifier');
        assert.strictEqual(check('new-id', 'id', 'new-verifier'), undefined);
        // With no saved verifier, webhooks stay optional.
        assert.strictEqual(check('new-id', 'id', '', false), undefined);
        assert.strictEqual(check('', 'id'), undefined);
        assert.strictEqual(check('new-id', ''), undefined);
    });

    test('each environment has its own keys: a half-filled set is refused and an empty one is skipped', (assert) => {
        const sync = { interval_minutes: 5, periodic_interval_hours: 24, retry_limit: 5, default_backoff_seconds: 30 };
        const check = (auth, saved = {}) => validateSettings({ auth, sync, saved });

        assert.deepEqual(check({ production_client_id: 'live', production_client_secret: 's', sandbox_client_id: '' }), {});
        assert.deepEqual(check({ sandbox_client_id: 'dev', sandbox_client_secret: 's' }), {});
        assert.deepEqual(Object.keys(check({ production_client_id: 'live', production_client_secret: 's', sandbox_client_id: 'dev' })), ['sandbox_client_secret']);
        assert.deepEqual(Object.keys(check({ sandbox_webhook_verifier: 'token' })), ['sandbox_client_id', 'sandbox_client_secret']);
        // Nothing saved and nothing typed: one set is needed, and production is where it is asked for.
        assert.deepEqual(Object.keys(check({ production_client_id: '', sandbox_client_id: '' })), ['production_client_id']);
        // A saved secret of one environment does not satisfy the other.
        assert.deepEqual(Object.keys(check({ production_client_id: 'live', sandbox_client_id: 'dev' }, { production_client_id: 'live', production_client_secret_set: true })), [
            'sandbox_client_secret',
        ]);
    });

    test('connection state treats a realm without reauth as connected', function (assert) {
        assert.strictEqual(connectionState(null), 'disconnected');
        assert.strictEqual(connectionState({ realm_id: '1', environment: 'sandbox' }), 'connected');
        assert.strictEqual(connectionState({ realm_id: '1', needs_reauth: true }), 'needs-reauth');
    });

    test('activity rows keep outbound batches and inbound counts', function (assert) {
        const rows = activityRows([
            { uuid: '1', trigger: 'scheduled', direction: 'outbound', created: 2, error: 'timeout' },
            { uuid: '2', trigger: 'import', direction: 'inbound', created: 4, linked: 3 },
            { uuid: '3', trigger: 'catalog', direction: 'outbound' },
            { uuid: '4', trigger: 'drain', direction: 'outbound' },
        ]);
        assert.strictEqual(rows[0].direction, 'outbound');
        assert.strictEqual(rows[0].trigger, 'scheduled');
        assert.strictEqual(rows[0].error, 'timeout');
        assert.strictEqual(rows[0].headerId, 'quickbooks-activity-0');
        assert.strictEqual(rows[2].headerId, 'quickbooks-activity-2');

        const stored = activityRows([{ uuid: 'old', trigger: 'import', direction: 'inbound', error: 'QuickBooks is not connected. Connect from Connection Config.' }]);
        assert.strictEqual(stored[0].error, 'QuickBooks is not connected. Connect from Connection Config.');
        assert.strictEqual(stored[0].headerId, 'quickbooks-activity-0');
        assert.strictEqual(rows[1].trigger, 'import');
        assert.strictEqual(rows[1].created, 4);
        assert.strictEqual(rows[1].linked, 3);
        assert.strictEqual(rows[2].trigger, 'catalog');
        assert.strictEqual(rows[3].trigger, 'drain');
    });
});
