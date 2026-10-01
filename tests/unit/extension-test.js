import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import extension from '@unchartedwaters/quickbooks-engine/extension';

function captureRegistration() {
    const headerItems = [];
    const adminItems = [];
    const adminPanels = [];
    const settingsPanels = [];
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
                    registerSettingsMenuPanel(title, items, options) {
                        settingsPanels.push({ title, items, options });
                    },
                };
            }

            if (name === 'universe/widget-service') {
                return {
                    registerWidgets() {},
                };
            }

            return null;
        },
    };

    extension.setupExtension(null, universe);

    return { headerItems, adminItems, adminPanels, settingsPanels };
}

module('Unit | extension', function (hooks) {
    setupTest(hooks);

    test('the header menu describes sync in either direction', function (assert) {
        const { headerItems } = captureRegistration();

        assert.strictEqual(headerItems[0].title, 'QuickBooks');
        assert.strictEqual(headerItems[0].description, 'Sync customers, invoices, payments, and wallets with QuickBooks Online.');
    });

    test('QuickBooks settings are not registered under Admin', function (assert) {
        const { adminItems, adminPanels } = captureRegistration();

        assert.strictEqual(adminItems.length, 0);
        assert.strictEqual(adminPanels.length, 0);
    });

    test('QuickBooks settings are one organization settings group', function (assert) {
        const { settingsPanels } = captureRegistration();

        assert.strictEqual(settingsPanels.length, 1);
        assert.strictEqual(settingsPanels[0].title, 'Quickbooks Settings');
        assert.deepEqual(
            settingsPanels[0].items.map((item) => [item.title, item.route]),
            [
                ['Connection', 'console.ledger.settings.quickbooks'],
                ['Activity', 'console.ledger.settings.quickbooks-activity'],
            ]
        );
    });
});
