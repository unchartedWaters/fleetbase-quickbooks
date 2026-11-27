import { module, test } from 'qunit';
import { setupTest } from 'ember-qunit';
import extension from '@unchartedwaters/fleetbase-quickbooks/extension';

module('Unit | admin menu with the real menu service', function (hooks) {
    setupTest(hooks);

    test('the console finds the QuickBooks item as a top-level admin item', function (assert) {
        const menuService = this.owner.lookup('service:universe/menu-service');
        const universe = { getService: (name) => (name === 'menu' ? menuService : null) };

        extension.setupExtension({ lookup: () => null }, universe);

        const items = menuService.adminMenuItems.filter((item) => item.slug === 'quickbooks');
        assert.strictEqual(items.length, 1);
        assert.strictEqual(menuService.adminMenuPanels.filter((panel) => panel.slug === 'quickbooks').length, 0);
        assert.strictEqual(menuService.lookupMenuItem('console:admin', 'quickbooks', 'settings'), items[0], 'the virtual admin route resolves it');
        assert.strictEqual(items[0].label, 'QuickBooks');
        assert.strictEqual(items[0].title, 'Settings');
    });
});
