'use strict';

const path = require('path');
const { buildEngine } = require('ember-engines/lib/engine-addon');
const { name } = require('./package');

module.exports = buildEngine({
    name,

    lazyLoading: {
        enabled: true,
    },

    // True only for a local checkout or npm/pnpm link. A published install
    // lives under node_modules and must not force a rebuild.
    isDevelopingAddon() {
        return !__dirname.split(path.sep).includes('node_modules');
    },
});
