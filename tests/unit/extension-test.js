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
                    registerAdminMenuPanel(panel) {
                        adminPanels.push(panel);
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

    test('the header menu opens the QuickBooks engine', function (assert) {
        const { headerItems } = captureRegistration();

        assert.strictEqual(headerItems.length, 1);
        assert.strictEqual(headerItems[0].title, 'QuickBooks');
        assert.strictEqual(headerItems[0].route, 'console.quickbooks');
        assert.strictEqual(headerItems[0].description, 'Sync customers, invoices, payments, and wallets with QuickBooks Online.');
    });

    test('QuickBooks settings are not registered under Admin', function (assert) {
        const { adminItems, adminPanels } = captureRegistration();

        assert.strictEqual(adminItems.length, 0);
        assert.strictEqual(adminPanels.length, 0);
    });

    test('QuickBooks settings are one organization settings item', function (assert) {
        const { settingsItems, settingsPanels, transitions } = captureRegistration();
        const item = settingsItems[0];

        assert.strictEqual(settingsItems.length, 1);
        assert.strictEqual(settingsPanels.length, 0);
        assert.strictEqual(item.title, 'QuickBooks');
        assert.strictEqual(item.slug, 'quickbooks');
        assert.strictEqual(item.icon, 'file-invoice-dollar');
        assert.strictEqual(item.index, 0);
        assert.strictEqual(item.component.engine, '@unchartedwaters/quickbooks-engine');
        assert.strictEqual(item.component.path, 'quickbooks-company-settings');
        assert.strictEqual(item.route, null);

        item.onClick(item);

        assert.deepEqual(transitions, [['console.settings.virtual', 'quickbooks']]);
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

    test('the extension does not copy components onto the host when the engine loads', function (assert) {
        const registrations = [];
        const app = {
            hasRegistration() {
                return false;
            },
            register(key) {
                registrations.push(key);
            },
        };
        const engine = {
            hasRegistration() {
                return true;
            },
            factoryFor() {
                return { class: function QuickbooksComponent() {} };
            },
        };

        if (typeof extension.onEngineLoaded === 'function') {
            extension.onEngineLoaded(engine, {}, app);
        }

        assert.strictEqual(registrations.length, 0);
        assert.strictEqual(extension.onEngineLoaded, undefined);
    });
});
