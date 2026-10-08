import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { connectionState } from '../../utils/connection-view';
import { canRunSyncNow, keysSavedForSync } from '../../utils/sync-access';

const NAMESPACE = 'quickbooks/int/v1';

export default class WidgetQuickbooksSyncComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;
    @service abilities;

    @tracked summary = null;
    @tracked error = null;
    @tracked busy = false;

    constructor() {
        super(...arguments);
        this.refresh();
    }

    get lastSyncLabel() {
        const finished = this.summary?.last_sync?.finished_at;
        if (!finished) {
            return this.intl.t('quickbooks.widget.no-sync');
        }

        const when = new Date(finished);
        if (Number.isNaN(when.getTime())) {
            return finished;
        }

        return when.toLocaleString();
    }

    get queueCount() {
        return this.summary?.queue ?? 0;
    }

    get isConnected() {
        return connectionState(this.summary?.connection) === 'connected';
    }

    get credentialsConfigured() {
        return keysSavedForSync(this.summary);
    }

    get credentialsMissing() {
        return this.isConnected && !this.credentialsConfigured;
    }

    get canSync() {
        return canRunSyncNow(this.abilities);
    }

    // Shown when Sync now would run except the signed-in user cannot reconcile.
    get syncPermissionDenied() {
        return !this.isLoading && !this.error && this.isConnected && this.credentialsConfigured && !this.canSync;
    }

    get syncDisabled() {
        return this.busy || !this.canSync || !this.isConnected || !this.credentialsConfigured;
    }

    // Null summary with no error means the request has not settled. A failure sets error and must not read as Not connected.
    get isLoading() {
        return this.summary === null && !this.error;
    }

    get statusLabel() {
        if (this.isLoading || this.error) {
            return '';
        }
        if (this.summary?.connection?.needs_reauth) {
            return this.intl.t('quickbooks.widget.reauth');
        }
        if (this.summary?.connection?.realm_id) {
            return this.intl.t('quickbooks.widget.connected');
        }

        return this.intl.t('quickbooks.widget.disconnected');
    }

    @action
    async refresh() {
        this.busy = true;
        this.error = null;
        try {
            this.summary = await this.fetch.get('summary', { company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
            this.error = null;
        } catch (error) {
            this.error = this.intl.t('quickbooks.widget.unavailable');
            this.summary = null;
        } finally {
            this.busy = false;
        }
    }

    @action
    async sync() {
        if (this.syncDisabled) {
            return;
        }

        this.busy = true;
        try {
            await this.fetch.post('sync', { company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
            this.notifications.success(this.intl.t('quickbooks.actions.sync-queued'));
            await this.refresh();
        } catch (error) {
            this.notifications.serverError(error);
            this.busy = false;
        }
    }
}
