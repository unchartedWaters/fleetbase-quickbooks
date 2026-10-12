import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { fieldState, normalizeSyncDirection, secretPresentation, validateSettings } from '@unchartedwaters/quickbooks-engine/utils/settings-form';
import { connectionState } from '@unchartedwaters/quickbooks-engine/utils/connection-view';
import { activityRows } from '@unchartedwaters/quickbooks-engine/utils/activity-view';
import ConnectionRoute from '@unchartedwaters/quickbooks-engine/routes/connection';
import ActionsRoute from '@unchartedwaters/quickbooks-engine/routes/actions';
import ActivityRoute from '@unchartedwaters/quickbooks-engine/routes/activity';
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

    test('settings fields mark inherited company values and omit a blank secret', function (assert) {
        const inherited = fieldState({ scope: 'company', client_id: 'admin-id', sources: { client_id: 'admin' } }, 'client_id');
        assert.true(inherited.inherited);

        const overridden = fieldState({ scope: 'company', client_id: 'company-id', sources: { client_id: 'company' } }, 'client_id');
        assert.false(overridden.inherited);

        assert.deepEqual(secretPresentation({ client_secret_set: true }), { value: '', set: true });

        const withoutOverride = validateSettings({ auth: {}, sync: {}, secretSet: false });
        assert.ok(withoutOverride.client_id);
        assert.strictEqual(withoutOverride.redirect_uri, undefined);
        assert.strictEqual(withoutOverride.override, undefined);

        const invalid = validateSettings({
            auth: { client_id: '', redirect_uri: 'callback', environment: 'sandbox', client_secret: '' },
            sync: { interval_minutes: 0, retry_limit: 5, default_backoff_seconds: 4 },
            secretSet: false,
        });
        assert.ok(invalid.client_id);
        assert.strictEqual(invalid.redirect_uri, undefined);
        assert.ok(invalid.interval_minutes);
        assert.ok(invalid.default_backoff_seconds);
        assert.strictEqual(invalid.batch_size, undefined);
        assert.strictEqual(invalid.customer_direction, 'quickbooks.validation.direction-customer');

        const badRetry = validateSettings({
            auth: { client_id: 'id', environment: 'sandbox', client_secret: 'secret' },
            sync: { retry_limit: 0 },
            secretSet: true,
        });
        assert.strictEqual(badRetry.retry_limit, 'quickbooks.validation.retry-limit');

        const blankEnvironment = validateSettings({
            auth: { client_id: 'id', environment: '', client_secret: 'secret' },
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
            secretSet: true,
        });
        assert.strictEqual(blankEnvironment.environment, undefined);
        assert.strictEqual(blankEnvironment.customer_direction, undefined);
        assert.strictEqual(blankEnvironment.redirect_uri, undefined);
        assert.strictEqual(blankEnvironment.batch_size, undefined);

        const offDirection = validateSettings({
            auth: { client_id: 'id', environment: 'production', client_secret: 'secret' },
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
            secretSet: true,
        });
        assert.strictEqual(offDirection.customer_direction, 'quickbooks.validation.direction-customer');
        assert.strictEqual(normalizeSyncDirection('off'), 'both');
        assert.strictEqual(normalizeSyncDirection('outbound'), 'outbound');
        assert.strictEqual(normalizeSyncDirection(undefined), 'both');

        const disabledCustomer = validateSettings({
            auth: { client_id: 'id', environment: 'production', client_secret: 'secret' },
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
            secretSet: true,
        });
        assert.strictEqual(disabledCustomer.customer_conflict, undefined);
        assert.strictEqual(disabledCustomer.customer_reference, undefined);
        assert.strictEqual(disabledCustomer.customer_direction, undefined);
        assert.deepEqual(disabledCustomer, {});
    });

    test('connection state treats a realm without reauth as connected', function (assert) {
        assert.strictEqual(connectionState(null), 'disconnected');
        assert.strictEqual(connectionState({ realm_id: '1', environment: 'sandbox' }), 'connected');
        assert.strictEqual(connectionState({ realm_id: '1', needs_reauth: true }), 'needs-reauth');
    });

    test('activity rows keep outbound batches and inbound counts', function (assert) {
        const rows = activityRows([
            { uuid: '1', trigger: 'scheduled', direction: 'outbound', created: 2, error: 'timeout' },
            { uuid: '2', trigger: 'import', direction: 'inbound', created_count: 4, linked_count: 3 },
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
