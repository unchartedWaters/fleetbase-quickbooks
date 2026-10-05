import Application from 'dummy/app';
import config from 'dummy/config/environment';
import * as QUnit from 'qunit';
import Service from '@ember/service';
import { setApplication } from '@ember/test-helpers';
import { setup } from 'qunit-dom';
import { start } from 'ember-qunit';

// ContentPanel stores icon nodes with EmberArray#pushObject. The console turns
// that on for every array. This dummy app does not, so mirror that one method.
if (typeof Array.prototype.pushObject !== 'function') {
    Array.prototype.pushObject = function pushObject(item) {
        this.push(item);
        return item;
    };
}

// The universe extension manager imports a module the console host generates at build
// time. The engine's dummy app has no host, so provide an inert stand-in before boot.
if (typeof window.define === 'function' && !window.requirejs?.entries?.['@fleetbase/console/extensions']) {
    window.define('@fleetbase/console/extensions', ['exports'], function (exports) {
        exports.getExtensionLoader = function () {
            return null;
        };
        exports.default = {};
    });
}

class StubUniverseService extends Service {
    registerWidgets() {}

    registerDefaultWidgets() {}
}

Application.instanceInitializer({
    name: 'quickbooks-test-universe-services',
    before: 'register-report-widget',
    initialize(appInstance) {
        appInstance.register('service:universe/registry-service', StubUniverseService, { override: true });
        appInstance.register('service:universe/widget-service', StubUniverseService, { override: true });
    },
});

setApplication(Application.create(config.APP));

setup(QUnit.assert);

start();
