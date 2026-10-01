import { MenuItem, Widget } from '@fleetbase/ember-core/contracts';

export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('menu');
        const widgetService = universe.getService('universe/widget-service');

        menuService.registerHeaderMenuItem(
            new MenuItem({
                id: 'quickbooks',
                title: 'QuickBooks',
                route: 'console.quickbooks',
                icon: 'file-invoice-dollar',
                priority: 5,
                description: 'Sync customers, invoices, payments, and wallets with QuickBooks Online.',
            })
        );

        menuService.registerSettingsMenuPanel(
            'Quickbooks Settings',
            [
                new MenuItem({
                    title: 'Connection',
                    route: 'console.ledger.settings.quickbooks',
                    icon: 'file-invoice-dollar',
                }),
                new MenuItem({
                    title: 'Activity',
                    route: 'console.ledger.settings.quickbooks-activity',
                    icon: 'list',
                }),
            ],
            {
                slug: 'quickbooks-settings',
                icon: 'file-invoice-dollar',
            }
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

    onEngineLoaded(engine, universe, app) {
        const names = [
            'quickbooks-company-settings',
            'quickbooks-settings',
            'quickbooks-settings-fields',
            'quickbooks-connection',
            'quickbooks-actions',
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
