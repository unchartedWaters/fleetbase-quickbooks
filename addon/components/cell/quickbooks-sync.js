import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

// A QuickBooks column cell of the Customers and Invoices tables. The column's format says how to show the value.
export default class CellQuickbooksSyncComponent extends Component {
    @service intl;

    get text() {
        const { value, column } = this.args;
        if (!value) {
            return '-';
        }

        if (column?.format === 'status') {
            const key = `quickbooks.columns.state.${String(value).replace('_', '-')}`;

            return this.intl.exists(key) ? this.intl.t(key) : value;
        }

        if (column?.format === 'time') {
            const when = new Date(value);

            return Number.isNaN(when.getTime()) ? value : this.intl.formatDate(when, { dateStyle: 'medium', timeStyle: 'short' });
        }

        return value;
    }
}
