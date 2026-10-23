import { MenuItem, ExtensionComponent, Widget } from '@fleetbase/ember-core/contracts';

const ENGINE = '@unchartedwaters/quickbooks-engine';

// The organization settings page renders these engine components from the host app.
const HOST_COMPONENTS = [
    'quickbooks-company-settings',
    'quickbooks-admin-settings',
    'quickbooks-settings',
    'quickbooks-settings-fields',
    'quickbooks-connection',
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
            description: t('quickbooks.menu.header-description', 'Sync customers, invoices, payments, and wallets with QuickBooks Online.'),
            onClick: () => openOrganizationSetting(app, 'quickbooks-setup', 'index'),
        })
    );
}

// Global settings (Client ID, secret, URLs, the sync template) are edited here by installation administrators only.
function registerAdminSettings(menuService, t) {
    menuService.registerAdminMenuPanel(
        'QuickBooks',
        [
            new MenuItem({
                title: t('quickbooks.menu.admin-settings', 'Settings'),
                icon: 'gear',
                component: new ExtensionComponent(ENGINE, 'quickbooks-admin-settings'),
            }),
        ],
        { slug: 'quickbooks' }
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
            description: t('quickbooks.widget.description', 'Shows the last sync, how many customers, invoices, payments, and wallets are waiting, and Sync now.'),
            icon: 'file-invoice-dollar',
            component: `#extension-component:${ENGINE}:widget/quickbooks-sync`,
            grid_options: { w: 4, h: 8, minW: 3, minH: 6 },
            category: 'QuickBooks',
            default: isDefault,
        });

    widgetService.registerWidgets('ledger', [quickbooksWidget(true)]);
    widgetService.registerWidgets('dashboard', [quickbooksWidget(false)]);
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
    },

    onEngineLoaded(engine, universe, app) {
        registerHostComponents(engine, app);
    },
};
