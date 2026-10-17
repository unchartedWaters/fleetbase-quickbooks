import { module, test } from 'qunit';

const ENGINE = '@unchartedwaters/quickbooks-engine';

// ember-asset-loader's app modules read a meta tag the Fleetbase console never renders.
// Bundled into this engine, they stop the lazy engine from booting and every QuickBooks screen stays blank.
module('Unit | engine build', () => {
    test('the engine bundle does not carry ember-asset-loader modules', (assert) => {
        const entries = window.requirejs.entries;

        assert.ok(entries[`${ENGINE}/extension`], 'engine modules are part of this build');
        assert.notOk(entries[`${ENGINE}/config/asset-manifest`], 'no config/asset-manifest module');
        assert.notOk(entries[`${ENGINE}/instance-initializers/load-asset-manifest`], 'no load-asset-manifest instance initializer');
    });
});
