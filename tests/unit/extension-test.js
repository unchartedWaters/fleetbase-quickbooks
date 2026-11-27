import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import extension from '@unchartedwaters/fleetbase-quickbooks/extension';

function captureRegistration(intl = null) {
    const headerItems = [];
    const adminItems = [];
    const adminPanels = [];
    const settingsItems = [];
    const settingsPanels = [];
    const widgetCalls = [];
    const tableColumns = [];
    const transitions = [];
    const order = [];
    const app = {
        lookup(name) {
            if (name === 'service:router') {
                return {
                    transitionTo(...args) {
                        transitions.push(args);
                    },
                };
            }
            if (name === 'service:intl') {
                return intl;
            }

            return null;
        },
    };
    const universe = {
        getService(name) {
            if (name === 'menu') {
                return {
                    registerHeaderMenuItem(item) {
                        order.push(`header:${item.title}`);
                        headerItems.push(item);
                    },
                    registerAdminMenuItem(item) {
                        order.push(`admin-item:${item.title}`);
                        adminItems.push(item);
                    },
                    registerAdminMenuPanel(title, items, options) {
                        order.push(`admin-panel:${title}`);
                        adminPanels.push({ title, items, options });
                    },
                    registerSettingsMenuItem(item) {
                        order.push(`settings-item:${item.title}`);
                        settingsItems.push(item);
                    },
                    registerSettingsMenuPanel(title, items, options) {
                        order.push(`settings-panel:${title}`);
                        settingsPanels.push({ title, items, options });
                    },
                };
            }

            if (name === 'universe/widget-service') {
                return {
                    registerWidgets(dashboard, widgets) {
                        order.push(`widgets:${dashboard}`);
                        widgetCalls.push({ dashboard, widgets });
                    },
                };
            }

            if (name === 'universe/resource-view-service') {
                return {
                    registerTableColumn(extension, resource, column) {
                        tableColumns.push({ extension, resource, column: column.toObject() });
                    },
                };
            }

            return null;
        },
    };

    extension.setupExtension(app, universe);

    return { headerItems, adminItems, adminPanels, settingsItems, settingsPanels, widgetCalls, tableColumns, transitions, order };
}

module('Unit | extension', function (hooks) {
    setupTest(hooks);

    test('setupExtension registers the menus and widgets in a fixed order', function (assert) {
        const { order } = captureRegistration();

        assert.deepEqual(order, ['header:QuickBooks', 'admin-item:Settings', 'settings-item:QuickBooks Setup', 'settings-item:QuickBooks Activity', 'widgets:ledger', 'widgets:dashboard']);
    });

    test('menu titles come from translations', (assert) => {
        const intl = { exists: (key) => key !== 'quickbooks.menu.activity', t: (key) => `T:${key}` };
        const { headerItems, adminItems, settingsItems, widgetCalls } = captureRegistration(intl);

        assert.strictEqual(headerItems[0].title, 'QuickBooks', 'the product name needs no key');
        assert.strictEqual(headerItems[0].description, 'T:quickbooks.menu.header-description');
        assert.strictEqual(adminItems[0].label, 'QuickBooks');
        assert.strictEqual(adminItems[0].title, 'T:quickbooks.menu.admin-settings');
        assert.strictEqual(settingsItems[0].title, 'T:quickbooks.menu.setup');
        assert.strictEqual(settingsItems[1].title, 'QuickBooks Activity', 'a key the service does not know falls back to English');
        assert.strictEqual(settingsItems[0].id, 'quickbooks-setup', 'ids do not follow the translated title');
        assert.strictEqual(settingsItems[1].id, 'quickbooks-activity');
        assert.strictEqual(widgetCalls[0].widgets[0].name, 'T:quickbooks.widget.title');
        assert.strictEqual(widgetCalls[0].widgets[0].description, 'T:quickbooks.widget.description');
        assert.strictEqual(widgetCalls[0].widgets[0].category, 'QuickBooks');
    });

    test('the header menu opens QuickBooks Setup', (assert) => {
        const { headerItems, transitions } = captureRegistration();

        assert.strictEqual(headerItems.length, 1);
        assert.strictEqual(headerItems[0].title, 'QuickBooks');
        assert.strictEqual(headerItems[0].route, 'console.settings.virtual');
        assert.strictEqual(headerItems[0].slug, 'quickbooks-setup');
        assert.strictEqual(headerItems[0].view, 'index');
        assert.strictEqual(headerItems[0].description, 'Sync Fleetbase customers, invoices, payments, wallets, and products with QuickBooks Online.');

        headerItems[0].onClick(headerItems[0]);

        assert.deepEqual(transitions, [['console.settings.virtual', 'quickbooks-setup', { queryParams: { view: 'index' } }]]);
    });

    test('QuickBooks Setup and QuickBooks Activity are organization settings items', (assert) => {
        const { settingsItems, settingsPanels, transitions } = captureRegistration();

        assert.strictEqual(settingsPanels.length, 0);
        assert.strictEqual(settingsItems.length, 2);

        assert.strictEqual(settingsItems[0].title, 'QuickBooks Setup');
        assert.strictEqual(settingsItems[0].id, 'quickbooks-setup', 'the id stays the same after the title was recapitalized');
        assert.strictEqual(settingsItems[0].slug, 'quickbooks-setup');
        assert.strictEqual(settingsItems[0].view, 'index');
        assert.strictEqual(settingsItems[0].index, 0);
        assert.strictEqual(settingsItems[0].icon, 'plug');
        assert.strictEqual(settingsItems[0].component.engine, '@unchartedwaters/fleetbase-quickbooks');
        assert.strictEqual(settingsItems[0].component.path, 'quickbooks-company-settings');
        assert.strictEqual(settingsItems[0].permission, 'quickbooks see extension');
        assert.false(settingsItems[0].overwriteWrapperClass);
        assert.strictEqual(settingsItems[0].wrapperClass, null);

        assert.strictEqual(settingsItems[1].title, 'QuickBooks Activity');
        assert.strictEqual(settingsItems[1].id, 'quickbooks-activity');
        assert.strictEqual(settingsItems[1].slug, 'quickbooks-activity');
        assert.strictEqual(settingsItems[1].view, 'index');
        assert.strictEqual(settingsItems[1].index, 1);
        assert.strictEqual(settingsItems[1].icon, 'clock-rotate-left');
        assert.strictEqual(settingsItems[1].component.engine, '@unchartedwaters/fleetbase-quickbooks');
        assert.strictEqual(settingsItems[1].component.path, 'quickbooks-activity');
        assert.strictEqual(settingsItems[1].permission, 'quickbooks see extension');
        assert.false(settingsItems[1].overwriteWrapperClass);
        assert.strictEqual(settingsItems[1].wrapperClass, null);

        settingsItems[0].onClick(settingsItems[0]);
        settingsItems[1].onClick(settingsItems[1]);

        assert.deepEqual(transitions, [
            ['console.settings.virtual', 'quickbooks-setup', { queryParams: { view: 'index' } }],
            ['console.settings.virtual', 'quickbooks-activity', { queryParams: { view: 'index' } }],
        ]);
    });

    test('the QuickBooks admin item opens the global Settings page directly', function (assert) {
        const { adminPanels, adminItems } = captureRegistration();

        assert.strictEqual(adminPanels.length, 0, 'a panel group would open without selecting Settings');
        assert.strictEqual(adminItems.length, 1);
        assert.strictEqual(adminItems[0].label, 'QuickBooks');
        assert.strictEqual(adminItems[0].title, 'Settings');
        assert.strictEqual(adminItems[0].slug, 'quickbooks');
        assert.strictEqual(adminItems[0].view, 'settings', 'keeps the /admin/quickbooks?view=settings address');
        assert.strictEqual(adminItems[0].component.engine, '@unchartedwaters/fleetbase-quickbooks');
        assert.strictEqual(adminItems[0].component.path, 'quickbooks-admin-settings');
    });

    test('QuickBooks widgets register on the ledger and dashboard', function (assert) {
        const { widgetCalls } = captureRegistration();
        const component = '#extension-component:@unchartedwaters/fleetbase-quickbooks:widget/quickbooks-sync';

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

    test('customers and invoices get hidden QuickBooks columns under their own heading', function (assert) {
        const { tableColumns } = captureRegistration();

        assert.deepEqual(
            tableColumns.map(({ extension, resource, column }) => `${extension}:${resource}:${column.id}`),
            [
                'fleet-ops:customer:quickbooks-status',
                'fleet-ops:customer:quickbooks-id',
                'fleet-ops:customer:quickbooks-synced-at',
                'ledger:invoice:quickbooks-status',
                'ledger:invoice:quickbooks-id',
                'ledger:invoice:quickbooks-synced-at',
            ]
        );

        const [status, id, syncedAt] = tableColumns.map(({ column }) => column);
        assert.strictEqual(status.label, 'QuickBooks status');
        assert.strictEqual(id.label, 'QuickBooks ID');
        assert.strictEqual(syncedAt.label, 'QuickBooks last synced');
        assert.deepEqual([status.valuePath, id.valuePath, syncedAt.valuePath], ['meta.quickbooks.status', 'meta.quickbooks.id', 'meta.quickbooks.synced_at']);
        tableColumns.forEach(({ column }) => {
            assert.true(column.hidden, `${column.id} stays hidden until it is picked`);
            assert.strictEqual(column.group, 'QuickBooks');
            assert.strictEqual(column.permission, 'quickbooks see extension');
        });
        assert.false(status.sortable, 'the status has no sort key on the server');
        assert.deepEqual([id.sortable, id.sortParam, syncedAt.sortable, syncedAt.sortParam], [true, 'quickbooks_id', true, 'quickbooks_synced_at']);
        assert.deepEqual(
            [id.filterable, id.filterParam, id.filterComponent, status.filterable, syncedAt.filterable],
            [true, 'quickbooks_id', 'filter/string', undefined, undefined],
            'only the ID filters, and the param is the one the server filters on'
        );
        assert.strictEqual(status.cellComponent.path, 'cell/quickbooks-sync');
        assert.strictEqual(status.cellComponent.engine, '@unchartedwaters/fleetbase-quickbooks');
        assert.strictEqual(id.cellComponent, undefined, 'the ID shows as plain text');
    });

    test('onEngineLoaded registers nested settings components on the host', function (assert) {
        const registrations = [];
        const presentOnEngine = new Set([
            'component:quickbooks-company-settings',
            'component:quickbooks-admin-settings',
            'component:quickbooks-settings',
            'component:quickbooks-settings-fields',
            'component:quickbooks-connection',
            'component:quickbooks-data-choice',
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
            'component:quickbooks-admin-settings',
            'component:quickbooks-settings-fields',
            'component:quickbooks-connection',
            'component:quickbooks-data-choice',
            'component:quickbooks-activity',
            'component:widget/quickbooks-sync',
        ]);
    });

    test('onEngineLoaded skips a component the engine does not provide', function (assert) {
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
            hasRegistration(key) {
                return key !== 'component:quickbooks-activity';
            },
            factoryFor(key) {
                assert.notStrictEqual(key, 'component:quickbooks-activity');

                return { class: function QuickbooksComponent() {} };
            },
        };

        extension.onEngineLoaded(engine, {}, app);

        assert.false(registrations.includes('component:quickbooks-activity'));
        assert.strictEqual(registrations.length, 7);
    });

    test('setupExtension still registers the menus when no widget service is available', function (assert) {
        const order = [];
        const menu = {
            registerHeaderMenuItem: (item) => order.push(`header:${item.title}`),
            registerAdminMenuItem: (item) => order.push(`admin-item:${item.title}`),
            registerSettingsMenuItem: (item) => order.push(`settings-item:${item.title}`),
        };
        const universe = { getService: (name) => (name === 'menu' ? menu : null) };

        extension.setupExtension({ lookup: () => null }, universe);

        assert.deepEqual(order, ['header:QuickBooks', 'admin-item:Settings', 'settings-item:QuickBooks Setup', 'settings-item:QuickBooks Activity']);
    });
});
