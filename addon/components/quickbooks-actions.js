import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

const NAMESPACE = 'quickbooks/int/v1';

export default class QuickbooksActionsComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;

    @tracked summary = null;
    @tracked summaryLoadFailed = false;
    @tracked summaryLoaded = false;
    @tracked busy = false;

    constructor() {
        super(...arguments);
        this.load();
    }

    // Undefined until the summary request finishes. Null is a finished request with no connection.
    get connection() {
        if (!this.summaryLoaded) {
            return undefined;
        }

        return this.summary?.connection ?? null;
    }

    get configured() {
        return this.summary?.credentials_configured === true;
    }

    async load() {
        try {
            this.summary = await this.fetch.get('summary', { company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
            this.summaryLoadFailed = false;
        } catch (error) {
            this.summary = null;
            this.summaryLoadFailed = true;
            this.notifications.serverError(error);
        } finally {
            this.summaryLoaded = true;
        }
    }

    @action
    async reconcile() {
        await this.run(async () => {
            await this.fetch.post('reconcile', { company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
            this.notifications.success(this.intl.t('quickbooks.actions.reconcile-queued'));
        });
    }

    @action
    async importCustomers() {
        await this.run(async () => {
            await this.fetch.post('import', { company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
            this.notifications.success(this.intl.t('quickbooks.actions.import-queued'));
        });
    }

    @action
    async sync() {
        await this.run(async () => {
            await this.fetch.post('sync', { company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
            this.notifications.success(this.intl.t('quickbooks.actions.sync-queued'));
        });
    }

    async run(work) {
        this.busy = true;
        try {
            await work();
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.busy = false;
        }
    }
}
