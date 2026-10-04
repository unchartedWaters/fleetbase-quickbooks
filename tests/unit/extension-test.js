import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import extension from '@unchartedwaters/quickbooks-engine/extension';

function captureRegistration() {
    const headerItems = [];
    const adminItems = [];
    const adminPanels = [];
    const settingsItems = [];
    const settingsPanels = [];
    const widgetCalls = [];
    const transitions = [];
    const app = {
        lookup(name) {
            if (name === 'service:router') {
                return {
                    transitionTo(...args) {
                        transitions.push(args);
                    },
                };
            }

            return null;
        },
    };
    const universe = {
        getService(name) {
            if (name === 'menu') {
                return {
                    registerHeaderMenuItem(item) {
                        headerItems.push(item);
                    },
                    registerAdminMenuItem(item) {
                        adminItems.push(item);
                    },
                    registerAdminMenuPanel(title, items, options) {
                        adminPanels.push({ title, items, options });
                    },
                    registerSettingsMenuItem(item) {
                        settingsItems.push(item);
                    },
                    registerSettingsMenuPanel(title, items, options) {
                        settingsPanels.push({ title, items, options });
                    },
                };
            }

            if (name === 'universe/widget-service') {
                return {
                    registerWidgets(dashboard, widgets) {
                        widgetCalls.push({ dashboard, widgets });
                    },
                };
            }

            return null;
        },
    };

    extension.setupExtension(app, universe);

    return { headerItems, adminItems, adminPanels, settingsItems, settingsPanels, widgetCalls, transitions };
}

module('Unit | extension', function (hooks) {
    setupTest(hooks);

    test('the header menu opens Quickbooks Setup', function (assert) {
        const { headerItems, transitions } = captureRegistration();

        assert.strictEqual(headerItems.length, 1);
        assert.strictEqual(headerItems[0].title, 'QuickBooks');
        assert.strictEqual(headerItems[0].route, 'console.settings.virtual');
        assert.strictEqual(headerItems[0].slug, 'quickbooks-setup');
        assert.strictEqual(headerItems[0].view, 'index');
        assert.strictEqual(headerItems[0].description, 'Sync customers, invoices, payments, and wallets with QuickBooks Online.');

        headerItems[0].onClick(headerItems[0]);

        assert.deepEqual(transitions, [['console.settings.virtual', 'quickbooks-setup', { queryParams: { view: 'index' } }]]);
    });

    test('Quickbooks Setup and Quickbooks Activity are organization settings items', function (assert) {
        const { adminItems, adminPanels, settingsItems, settingsPanels, transitions } = captureRegistration();

        assert.strictEqual(adminItems.length, 0);
        assert.strictEqual(adminPanels.length, 0);
        assert.strictEqual(settingsPanels.length, 0);
        assert.strictEqual(settingsItems.length, 2);

        assert.strictEqual(settingsItems[0].title, 'Quickbooks Setup');
        assert.strictEqual(settingsItems[0].slug, 'quickbooks-setup');
        assert.strictEqual(settingsItems[0].view, 'index');
        assert.strictEqual(settingsItems[0].index, 0);
        assert.strictEqual(settingsItems[0].icon, 'plug');
        assert.strictEqual(settingsItems[0].component.engine, '@unchartedwaters/quickbooks-engine');
        assert.strictEqual(settingsItems[0].component.path, 'quickbooks-company-settings');
        assert.false(settingsItems[0].overwriteWrapperClass);
        assert.strictEqual(settingsItems[0].wrapperClass, null);

        assert.strictEqual(settingsItems[1].title, 'Quickbooks Activity');
        assert.strictEqual(settingsItems[1].slug, 'quickbooks-activity');
        assert.strictEqual(settingsItems[1].view, 'index');
        assert.strictEqual(settingsItems[1].index, 1);
        assert.strictEqual(settingsItems[1].icon, 'clock-rotate-left');
        assert.strictEqual(settingsItems[1].component.engine, '@unchartedwaters/quickbooks-engine');
        assert.strictEqual(settingsItems[1].component.path, 'quickbooks-activity');
        assert.false(settingsItems[1].overwriteWrapperClass);
        assert.strictEqual(settingsItems[1].wrapperClass, null);

        settingsItems[0].onClick(settingsItems[0]);
        settingsItems[1].onClick(settingsItems[1]);

        assert.deepEqual(transitions, [
            ['console.settings.virtual', 'quickbooks-setup', { queryParams: { view: 'index' } }],
            ['console.settings.virtual', 'quickbooks-activity', { queryParams: { view: 'index' } }],
        ]);
    });

    test('QuickBooks widgets register on the ledger and dashboard', function (assert) {
        const { widgetCalls } = captureRegistration();
        const component = '#extension-component:@unchartedwaters/quickbooks-engine:widget/quickbooks-sync';

        assert.deepEqual(
            widgetCalls.map((call) => call.dashboard),
            ['ledger', 'dashboard']
        );
        assert.strictEqual(widgetCalls[0].widgets.length, 1);
        assert.strictEqual(widgetCalls[1].widgets.length, 1);
        assert.strictEqual(widgetCalls[0].widgets[0].id, 'quickbooks-sync');
        assert.strictEqual(widgetCalls[0].widgets[0].component, component);
        assert.strictEqual(widgetCalls[1].widgets[0].component, component);
        assert.true(widgetCalls[0].widgets[0].isDefault());
        assert.false(widgetCalls[1].widgets[0].isDefault());
    });

    test('onEngineLoaded registers nested settings components on the host', function (assert) {
        const registrations = [];
        const presentOnEngine = new Set([
            'component:quickbooks-company-settings',
            'component:quickbooks-settings',
            'component:quickbooks-settings-fields',
            'component:quickbooks-connection',
            'component:quickbooks-actions',
            'component:quickbooks-activity',
            'component:widget/quickbooks-sync',
        ]);
        const app = {
            hasRegistration(key) {
                return key === 'component:quickbooks-settings';
            },
            register(key) {
                registrations.push(key);
            },
        };
        const engine = {
            hasRegistration(key) {
                return presentOnEngine.has(key);
            },
            factoryFor(key) {
                assert.true(presentOnEngine.has(key));

                return { class: function QuickbooksComponent() {} };
            },
        };

        assert.strictEqual(typeof extension.onEngineLoaded, 'function');
        extension.onEngineLoaded(engine, {}, app);

        assert.deepEqual(registrations, [
            'component:quickbooks-company-settings',
            'component:quickbooks-settings-fields',
            'component:quickbooks-connection',
            'component:quickbooks-actions',
            'component:quickbooks-activity',
            'component:widget/quickbooks-sync',
        ]);
    });
});
