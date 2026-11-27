import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | cell/quickbooks-sync', function (hooks) {
    setupRenderingTest(hooks);

    test('a status shows in words and an unknown one shows as sent', async function (assert) {
        this.column = { format: 'status' };

        this.value = 'not_synced';
        await render(hbs`<Cell::QuickbooksSync @column={{this.column}} @value={{this.value}} />`);
        assert.dom('[data-test-quickbooks-cell="status"]').hasText('Not synced');

        this.value = 'failed';
        await render(hbs`<Cell::QuickbooksSync @column={{this.column}} @value={{this.value}} />`);
        assert.dom('[data-test-quickbooks-cell="status"]').hasText('Failed');

        this.value = 'something-new';
        await render(hbs`<Cell::QuickbooksSync @column={{this.column}} @value={{this.value}} />`);
        assert.dom('[data-test-quickbooks-cell="status"]').hasText('something-new');
    });

    test('a time shows as a date and time, and an empty value as a dash', async function (assert) {
        this.column = { format: 'time' };

        this.value = '2026-11-20T10:00:00+00:00';
        await render(hbs`<Cell::QuickbooksSync @column={{this.column}} @value={{this.value}} />`);
        assert.dom('[data-test-quickbooks-cell="time"]').includesText('2026');

        this.value = 'not a date';
        await render(hbs`<Cell::QuickbooksSync @column={{this.column}} @value={{this.value}} />`);
        assert.dom('[data-test-quickbooks-cell="time"]').hasText('not a date');

        this.value = null;
        await render(hbs`<Cell::QuickbooksSync @column={{this.column}} @value={{this.value}} />`);
        assert.dom('[data-test-quickbooks-cell="time"]').hasText('-');
    });
});
