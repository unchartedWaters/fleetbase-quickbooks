'use strict';

module.exports = {
    extends: 'recommended',
    ignore: ['server/**', 'server_vendor/**', 'vendor/**', 'node_modules/**'],
    rules: {
        'no-invalid-interactive': 'off',
        'no-yield-only': 'off',
        'table-groups': 'off',
        'link-href-attributes': 'off',
        'require-input-label': 'off',
        'no-invalid-role': 'off',
        'no-positive-tabindex': 'off',
        'require-presentational-children': 'off',
        // Links are valid in the body of a <details> (HTML allows flow content after its <summary>). This exempts
        // <details> from the rule entirely, so keep links and buttons out of a <summary> by review.
        'no-nested-interactive': ['error', { ignoredTags: ['details'] }],
    },
};
