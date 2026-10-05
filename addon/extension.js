import { MenuItem, ExtensionComponent, Widget } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('menu');
        const widgetService = universe.getService('universe/widget-service');

        menuService.registerHeaderMenuItem(
            new MenuItem({
                id: 'quickbooks',
                title: 'QuickBooks',
                route: 'console.settings.virtual',
                slug: 'quickbooks-setup',
                view: 'index',
                icon: 'file-invoice-dollar',
                priority: 5,
                description: 'Sync customers, invoices, payments, and wallets with QuickBooks Online.',
                onClick: () => {
                    const router = app.lookup('service:router');
                    if (router) {
                        return router.transitionTo('console.settings.virtual', 'quickbooks-setup', {
                            queryParams: { view: 'index' },
                        });
                    }
                },
            })
        );

        // Same list as Organization, Two Factor, and Notifications. Not an admin panel and not a Ledger menu.
        const openOrganizationSettings = (menuItem) => {
            const router = app.lookup('service:router');
            if (router) {
                return router.transitionTo('console.settings.virtual', menuItem.slug, {
                    queryParams: { view: menuItem.view },
                });
            }
        };

        menuService.registerSettingsMenuItem(
            new MenuItem({
                title: 'Quickbooks Setup',
                icon: 'plug',
                slug: 'quickbooks-setup',
                index: 0,
                view: 'index',
                component: new ExtensionComponent('@unchartedwaters/quickbooks-engine', 'quickbooks-company-settings'),
                onClick: openOrganizationSettings,
            })
        );

        menuService.registerSettingsMenuItem(
            new MenuItem({
                title: 'Quickbooks Activity',
                icon: 'clock-rotate-left',
                slug: 'quickbooks-activity',
                index: 1,
                view: 'index',
                component: new ExtensionComponent('@unchartedwaters/quickbooks-engine', 'quickbooks-activity'),
                onClick: openOrganizationSettings,
            })
        );

        if (widgetService) {
            const quickbooksWidget = (isDefault) =>
                new Widget({
                    id: 'quickbooks-sync',
                    name: 'QuickBooks Sync',
                    description: 'Shows the last sync, how many customers, invoices, payments, and wallets are waiting, and Sync now.',
                    icon: 'file-invoice-dollar',
                    component: '#extension-component:@unchartedwaters/quickbooks-engine:widget/quickbooks-sync',
                    grid_options: { w: 4, h: 8, minW: 3, minH: 6 },
                    category: 'QuickBooks',
                    default: isDefault,
                });

            widgetService.registerWidgets('ledger', [quickbooksWidget(true)]);
            widgetService.registerWidgets('dashboard', [quickbooksWidget(false)]);
        }
    },

    // The organization settings page renders this engine's component from the host app.
    // Nested engine components resolve only after they are registered there.
    onEngineLoaded(engine, universe, app) {
        const names = [
            'quickbooks-company-settings',
            'quickbooks-settings',
            'quickbooks-settings-fields',
            'quickbooks-connection',
            'quickbooks-activity',
            'widget/quickbooks-sync',
        ];

        names.forEach((name) => {
            const key = `component:${name}`;
            if (!app.hasRegistration(key) && engine.hasRegistration(key)) {
                app.register(key, engine.factoryFor(key).class);
            }
        });
    },
};
