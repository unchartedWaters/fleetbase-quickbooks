import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { activityRows } from '../utils/activity-view';

const NAMESPACE = 'quickbooks/int/v1';

export default class QuickbooksActivityComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;

    @tracked loadedBatches = null;
    @tracked activityLoadFailed = false;

    constructor() {
        super(...arguments);

        if (this.args.batches === undefined) {
            this.load();
        }
    }

    get rows() {
        const batches = this.args.batches !== undefined ? this.args.batches : this.loadedBatches || [];

        return activityRows(batches).map((row) => ({
            ...row,
            trigger: this.translated('quickbooks.activity.trigger', row.trigger),
            directionLabel: this.translated('quickbooks.activity.direction', row.direction),
            status: this.statusLabel(row.status),
            error: this.displayError(row.error),
        }));
    }

    translated(prefix, value) {
        if (!value) {
            return '';
        }

        const key = `${prefix}.${value}`;

        return this.intl.exists(key) ? this.intl.t(key) : value;
    }

    statusLabel(status) {
        if (!status) {
            return '';
        }

        const key = `quickbooks.activity.status.${status}`;
        if (this.intl.exists(key)) {
            return this.intl.t(key);
        }

        return status.charAt(0).toUpperCase() + status.slice(1);
    }

    displayError(error) {
        if (typeof error !== 'string' || error === '') {
            return error || null;
        }

        return error.split('Connection Config').join(this.intl.t('quickbooks.connection.title'));
    }

    get loadFailed() {
        return this.args.batches === undefined && this.activityLoadFailed;
    }

    get showEmptyState() {
        if (this.rows.length || this.loadFailed) {
            return false;
        }

        if (this.args.batches !== undefined) {
            return true;
        }

        return this.loadedBatches !== null;
    }

    get isLoading() {
        return this.args.batches === undefined && this.loadedBatches === null && !this.activityLoadFailed;
    }

    async load() {
        try {
            const result = await this.fetch.get('batches', { company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
            this.loadedBatches = result?.batches ?? [];
            this.activityLoadFailed = false;
        } catch (error) {
            this.loadedBatches = null;
            this.activityLoadFailed = true;
            this.notifications.serverError(error);
        }
    }
}
