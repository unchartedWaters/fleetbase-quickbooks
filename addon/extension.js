import { MenuItem, ExtensionComponent, TableColumn, Widget } from '@fleetbase/ember-core/contracts';

const ENGINE = '@unchartedwaters/fleetbase-quickbooks';

// The organization settings page renders these engine components from the host app.
const HOST_COMPONENTS = [
    'quickbooks-company-settings',
    'quickbooks-admin-settings',
    'quickbooks-settings',
    'quickbooks-settings-fields',
    'quickbooks-connection',
    'quickbooks-data-choice',
    'quickbooks-activity',
    'widget/quickbooks-sync',
];

// The header item id "quickbooks" is checked as this permission.
// A settings item stays available to anyone who can open Settings unless it passes the same permission.
const SEE_EXTENSION = 'quickbooks see extension';

function openOrganizationSetting(app, slug, view) {
    const router = app.lookup('service:router');
    if (router) {
        return router.transitionTo('console.settings.virtual', slug, {
            queryParams: { view },
        });
    }
}

// Titles are read once at boot, in the locale active then; the host has no per-render translation for menu titles.
// Without an intl service (or before the key is known), the English text is used.
function translator(app) {
    const intl = app.lookup('service:intl');

    return (key, fallback) => (intl?.exists?.(key) ? intl.t(key) : fallback);
}

function registerHeaderItem(menuService, app, t) {
    menuService.registerHeaderMenuItem(
        new MenuItem({
            id: 'quickbooks',
            title: 'QuickBooks',
            route: 'console.settings.virtual',
            slug: 'quickbooks-setup',
            view: 'index',
            icon: 'file-invoice-dollar',
            priority: 5,
            description: t('quickbooks.menu.header-description', 'Sync Fleetbase customers, invoices, payments, wallets, and products with QuickBooks Online.'),
            onClick: () => openOrganizationSetting(app, 'quickbooks-setup', 'index'),
        })
    );
}

// Global settings (Client ID, secret, URLs, the sync template) are edited here by installation administrators only.
// A single item, not a panel: the console's admin sidebar opens a panel group without opening any of its items,
// so a click on "QuickBooks" would land on a blank page. The item opens Settings directly and is highlighted there.
function registerAdminSettings(menuService, t) {
    menuService.registerAdminMenuItem(
        new MenuItem({
            id: 'quickbooks-admin-settings',
            label: 'QuickBooks',
            title: t('quickbooks.menu.admin-settings', 'Settings'),
            icon: 'file-invoice-dollar',
            slug: 'quickbooks',
            view: 'settings',
            component: new ExtensionComponent(ENGINE, 'quickbooks-admin-settings'),
        })
    );
}

// Same list as Organization, Two Factor, and Notifications. Not an admin panel and not a Ledger menu.
// The ids keep the values the earlier "Quickbooks" titles produced.
function registerOrganizationSettings(menuService, app, t) {
    const onClick = (menuItem) => openOrganizationSetting(app, menuItem.slug, menuItem.view);

    menuService.registerSettingsMenuItem(
        new MenuItem({
            id: 'quickbooks-setup',
            title: t('quickbooks.menu.setup', 'QuickBooks Setup'),
            icon: 'plug',
            slug: 'quickbooks-setup',
            index: 0,
            view: 'index',
            permission: SEE_EXTENSION,
            component: new ExtensionComponent(ENGINE, 'quickbooks-company-settings'),
            onClick,
        })
    );

    menuService.registerSettingsMenuItem(
        new MenuItem({
            id: 'quickbooks-activity',
            title: t('quickbooks.menu.activity', 'QuickBooks Activity'),
            icon: 'clock-rotate-left',
            slug: 'quickbooks-activity',
            index: 1,
            view: 'index',
            permission: SEE_EXTENSION,
            component: new ExtensionComponent(ENGINE, 'quickbooks-activity'),
            onClick,
        })
    );
}

function registerWidgets(widgetService, t) {
    if (!widgetService) {
        return;
    }

    const quickbooksWidget = (isDefault) =>
        new Widget({
            id: 'quickbooks-sync',
            name: t('quickbooks.widget.title', 'QuickBooks Sync'),
            description: t('quickbooks.widget.description', 'Shows the last sync, how many customers, invoices, payments, wallets, and products are waiting, and Sync now.'),
            icon: 'file-invoice-dollar',
            component: `#extension-component:${ENGINE}:widget/quickbooks-sync`,
            grid_options: { w: 4, h: 8, minW: 3, minH: 6 },
            category: 'QuickBooks',
            default: isDefault,
        });

    widgetService.registerWidgets('ledger', [quickbooksWidget(true)]);
    widgetService.registerWidgets('dashboard', [quickbooksWidget(false)]);
}

// The QuickBooks columns of the Fleet-Ops customers and Ledger invoices tables, read from meta.quickbooks.
// The server sorts and searches them through the QuickBooks tables. Only people who may see the extension get them.
const TABLES = [
    ['fleet-ops', 'customer'],
    ['ledger', 'invoice'],
];

const TABLE_COLUMNS = [
    { id: 'status', label: 'quickbooks.columns.status', fallback: 'QuickBooks status', key: 'status', format: 'status' },
    { id: 'id', label: 'quickbooks.columns.id', fallback: 'QuickBooks ID', key: 'id', sort: 'quickbooks_id', filter: 'quickbooks_id' },
    { id: 'synced-at', label: 'quickbooks.columns.synced-at', fallback: 'QuickBooks last synced', key: 'synced_at', format: 'time', sort: 'quickbooks_synced_at' },
];

function tableColumn(spec, t) {
    const column = new TableColumn({ id: `quickbooks-${spec.id}`, label: t(spec.label, spec.fallback), valuePath: `meta.quickbooks.${spec.key}`, permission: SEE_EXTENSION });

    if (spec.format) {
        column.setOption('format', spec.format).withCellComponent(new ExtensionComponent(ENGINE, 'cell/quickbooks-sync'));
    }

    if (spec.sort) {
        column.withSort(spec.sort);
    }

    // A filter param must be registered here, in setupExtension, before the table's controller reads its query params.
    if (spec.filter) {
        column.withFilter(spec.filter);
    }

    return column.inGroup(t('quickbooks.columns.group', 'QuickBooks')).hiddenByDefault();
}

function registerTableColumns(views, t) {
    if (!views) {
        return;
    }

    TABLES.forEach(([extension, resource]) => {
        TABLE_COLUMNS.forEach((spec) => {
            views.registerTableColumn(extension, resource, tableColumn(spec, t));
        });
    });
}

// Nested engine components resolve only after they are registered on the host.
function registerHostComponents(engine, app) {
    HOST_COMPONENTS.forEach((name) => {
        const key = `component:${name}`;
        if (!app.hasRegistration(key) && engine.hasRegistration(key)) {
            app.register(key, engine.factoryFor(key).class);
        }
    });
}

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('menu');
        const t = translator(app);

        registerHeaderItem(menuService, app, t);
        registerAdminSettings(menuService, t);
        registerOrganizationSettings(menuService, app, t);
        registerWidgets(universe.getService('universe/widget-service'), t);
        registerTableColumns(universe.getService('universe/resource-view-service'), t);
    },

    onEngineLoaded(engine, universe, app) {
        registerHostComponents(engine, app);
    },
};
