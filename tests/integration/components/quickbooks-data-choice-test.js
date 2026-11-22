import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

const LINKED = { customers: 3, invoices: 5, wallets: 1, products: 2, total: 11, imported: 2 };

module('Integration | Component | quickbooks-data-choice', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.set('linked', LINKED);
        this.set('answer', null);
        this.set('onConfirm', (level, code) => this.set('answer', { level, code }));
    });

    test('a switch offers only the clearing levels and needs the code typed', async function (assert) {
        await render(hbs`<QuickbooksDataChoice @mode="switch" @linked={{this.linked}} @code="K7M2QX" @onConfirm={{this.onConfirm}} />`);

        assert.dom('[data-test-choice-option="keep"]').doesNotExist('keeping the links is not an answer to a different company');
        assert.dom('[data-test-choice-option]').exists({ count: 3 });
        assert.dom('[data-test-choice-counts]').hasText('Linked to QuickBooks: 3 customers, 5 invoices, 1 wallet and 2 products.');
        assert.dom('[data-test-choice-code]').hasText('K7M2QX');
        assert.dom('[data-test-choice-confirm]').isDisabled();

        await fillIn('[data-test-choice-input]', 'wrong1');
        assert.dom('[data-test-choice-confirm]').isDisabled();

        await fillIn('[data-test-choice-input]', 'k7m2qx');
        assert.dom('[data-test-choice-confirm]').isNotDisabled('the code matches in any letter case');
        await click('[data-test-choice-confirm]');
        assert.deepEqual(this.answer, { level: 'sync', code: 'k7m2qx' });
    });

    test('the deepest level names the imported customers it deletes', async function (assert) {
        await render(hbs`<QuickbooksDataChoice @mode="switch" @linked={{this.linked}} @code="K7M2QX" @onConfirm={{this.onConfirm}} />`);

        assert.dom('[data-test-choice-option="everything"]').exists();
        await click('[data-test-choice-option="everything"]');
        await fillIn('[data-test-choice-input]', 'K7M2QX');
        await click('[data-test-choice-confirm]');
        assert.strictEqual(this.answer.level, 'everything');
        assert.dom().includesText('2 Fleetbase customers that were imported from QuickBooks');
    });

    test('disconnect keeps the data by default without a code, and shows a refused code', async function (assert) {
        this.set('rejected', false);
        await render(hbs`<QuickbooksDataChoice @mode="disconnect" @linked={{this.linked}} @code="K7M2QX" @codeRejected={{this.rejected}} @onConfirm={{this.onConfirm}} />`);

        assert.dom('[data-test-choice-option]').exists({ count: 4 });
        assert.dom('[data-test-choice-input]').doesNotExist('keeping the data needs no code');
        assert.dom('[data-test-choice-confirm]').isNotDisabled();
        await click('[data-test-choice-confirm]');
        assert.deepEqual(this.answer, { level: 'keep', code: '' });

        await click('[data-test-choice-option="settings"]');
        assert.dom('[data-test-choice-input]').exists();
        assert.dom('[data-test-choice-confirm]').isDisabled();
        this.set('rejected', true);
        assert.dom('[data-test-choice-code-rejected]').exists();
    });
});
