import Component from '@glimmer/component';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { connectionState } from '../utils/connection-view';

const NAMESPACE = 'quickbooks/int/v1';

export default class QuickbooksConnectionComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;
    @service modalsManager;

    // Set only after Disconnect succeeds on a screen that does not pass onDisconnect.
    @tracked removed = false;
    @tracked localBusy = false;

    get loadFailed() {
        return this.args.loadFailed === true;
    }

    // Connection leaves connection undefined until the summary request finishes.
    // Null is a finished request with no QuickBooks organization.
    get isLoading() {
        return !this.loadFailed && this.args.connection === undefined;
    }

    get state() {
        if (this.loadFailed) {
            return 'unavailable';
        }

        if (this.isLoading) {
            return 'loading';
        }

        return connectionState(this.effectiveConnection);
    }

    // A needs-reauth organization is still a saved connection.
    get effectiveConnection() {
        if (this.removed) {
            return null;
        }

        return this.args.connection;
    }

    get isConnected() {
        return this.state === 'connected';
    }

    get needsReauth() {
        return this.state === 'needs-reauth';
    }

    // Active means a saved realm that does not need to be connected again.
    get disconnectDisabled() {
        return this.busy || !this.isConnected;
    }

    get busy() {
        return Boolean(this.args.busy) || this.localBusy;
    }

    get isConfigured() {
        return this.args.configured === true;
    }

    get syncDisabled() {
        return this.busy || this.isLoading || !this.isConnected;
    }

    // Connect uses the saved Client ID and Client secret. A connection that only needs reauth can connect again.
    // An active connection stays in the row and does not start a second sign-in.
    get connectDisabled() {
        if (this.busy || this.isLoading || this.loadFailed || this.isConnected) {
            return true;
        }

        if (this.needsReauth) {
            return false;
        }

        return !this.isConfigured;
    }

    @action
    async connect(event) {
        event?.preventDefault?.();
        if (this.connectDisabled || this.isLoading || this.loadFailed) {
            return;
        }

        await this.args.onConnect?.();
    }

    // Local delete through POST disconnect. This does not unsubscribe Intuit.
    @action
    disconnect() {
        if (this.disconnectDisabled) {
            return;
        }

        this.modalsManager.confirm({
            title: this.intl.t('quickbooks.connection.disconnect-confirm-title'),
            body: this.intl.t('quickbooks.connection.disconnect-confirm'),
            acceptButtonText: this.intl.t('quickbooks.connection.disconnect'),
            confirm: () => this.performDisconnect(),
        });
    }

    async performDisconnect() {
        if (typeof this.args.onDisconnect === 'function') {
            await this.args.onDisconnect();
            return;
        }

        this.localBusy = true;
        try {
            await this.fetch.post('disconnect', { company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
            this.removed = true;
            this.notifications.success(this.intl.t('quickbooks.connection.disconnected-toast'));
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.localBusy = false;
        }
    }

    @action
    syncNow() {
        if (this.syncDisabled) {
            return;
        }

        this.args.onSync?.();
    }
}
