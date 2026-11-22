import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

const KEEP = { level: 'keep', title: 'quickbooks.choice.keep', detail: 'quickbooks.choice.keep-detail' };
const LEVELS = [
    { level: 'sync', title: 'quickbooks.choice.sync', detail: 'quickbooks.choice.sync-detail' },
    { level: 'settings', title: 'quickbooks.choice.settings', detail: 'quickbooks.choice.settings-detail' },
    { level: 'everything', title: 'quickbooks.choice.everything', detail: 'quickbooks.choice.everything-detail' },
];

/**
 * What to do with an organization's QuickBooks data when it disconnects or Intuit returns a different company.
 * Anything that clears data needs the random code the server issued typed back, so it is never one click.
 * Arguments: @mode ("disconnect" keeps Keep as an option, "switch" does not), @linked, @code, @codeRejected,
 * @busy, @onConfirm(level, code), @onCancel.
 */
export default class QuickbooksDataChoiceComponent extends Component {
    @tracked level = this.isSwitch ? 'sync' : 'keep';
    @tracked typed = '';

    get isSwitch() {
        return this.args.mode === 'switch';
    }

    get options() {
        return this.isSwitch ? LEVELS : [KEEP, ...LEVELS];
    }

    get counts() {
        const linked = this.args.linked ?? {};

        return {
            customers: linked.customers ?? 0,
            invoices: linked.invoices ?? 0,
            wallets: linked.wallets ?? 0,
            products: linked.products ?? 0,
            imported: linked.imported ?? 0,
        };
    }

    get needsCode() {
        return this.level !== 'keep';
    }

    get confirmDisabled() {
        if (this.args.busy) {
            return true;
        }

        return this.needsCode && this.typed.trim().toUpperCase() !== String(this.args.code ?? '').toUpperCase();
    }

    @action
    choose(level) {
        this.level = level;
    }

    @action
    type(event) {
        this.typed = event.target.value;
    }

    @action
    confirm() {
        if (this.confirmDisabled) {
            return;
        }

        this.args.onConfirm?.(this.level, this.typed.trim());
    }
}
