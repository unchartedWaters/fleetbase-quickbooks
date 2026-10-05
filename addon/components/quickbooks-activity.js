import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { activityRows } from '../utils/activity-view';

const NAMESPACE = 'quickbooks/int/v1';
const PAGE_SIZE = 25;

function withPageBounds(meta, fallbackPage) {
    if (!meta) {
        return null;
    }

    const perPage = Number(meta.per_page) || PAGE_SIZE;
    const current = Number(meta.current_page) || fallbackPage || 1;
    const total = Number(meta.total) || 0;
    let from = total === 0 ? 0 : (current - 1) * perPage + 1;
    let to = total === 0 ? 0 : Math.min(current * perPage, total);

    if (meta.from != null) {
        from = Number(meta.from);
    }

    if (meta.to != null) {
        to = Number(meta.to);
    }

    return {
        ...meta,
        from,
        to,
    };
}

export default class QuickbooksActivityComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;

    @tracked loadedBatches = null;
    @tracked loadedMeta = null;
    @tracked page = 1;
    @tracked activityLoadFailed = false;

    requestId = 0;

    constructor() {
        super(...arguments);

        if (this.args.batches === undefined) {
            this.load(1);
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

        const page = 'Quickbooks Setup';

        return error.split('Connection Config').join(this.intl.t('quickbooks.connection.title')).split('from Connection').join(`from ${page}`).split('on Connection').join(`on ${page}`);
    }

    get meta() {
        return this.args.batches !== undefined ? (this.args.meta ?? null) : this.loadedMeta;
    }

    get currentPage() {
        if (this.args.batches !== undefined) {
            return Number(this.args.meta?.current_page) || 1;
        }

        return this.page;
    }

    get paginationMeta() {
        return withPageBounds(this.meta, this.currentPage);
    }

    get showPagination() {
        return Number(this.meta?.last_page) > 1;
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

    @action
    changePage(page) {
        if (this.args.batches !== undefined) {
            this.args.onPageChange?.(page);
            return;
        }

        this.load(page);
    }

    async load(page = 1) {
        const requested = Math.max(1, Number(page) || 1);
        const requestId = ++this.requestId;

        try {
            const result = await this.fetch.get(
                'batches',
                {
                    company_uuid: this.currentUser.companyId,
                    page: requested,
                    per_page: PAGE_SIZE,
                },
                { namespace: NAMESPACE }
            );

            if (requestId !== this.requestId) {
                return;
            }

            this.loadedBatches = result?.batches ?? [];
            this.loadedMeta = result?.meta ?? null;
            this.page = Number(result?.meta?.current_page) || requested;
            this.activityLoadFailed = false;
        } catch (error) {
            if (requestId !== this.requestId) {
                return;
            }

            this.loadedBatches = null;
            this.loadedMeta = null;
            this.activityLoadFailed = true;
            this.notifications.serverError(error);
        }
    }
}
