import Component from '@glimmer/component';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { connectionState, connectPayload } from '../utils/connection-view';

const NAMESPACE = 'quickbooks/int/v1';

export default class QuickbooksConnectionComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;

    // Set only after Disconnect succeeds on a screen that does not pass onDisconnect.
    @tracked removed = false;
    @tracked localBusy = false;

    get loadFailed() {
        return this.args.loadFailed === true;
    }

    // Connection and Actions leave connection undefined until the summary request finishes.
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

    get hasConnection() {
        return this.isConnected || this.needsReauth;
    }

    // Shown beside Connect on Connection. Disabled until a connection is saved.
    get disconnectDisabled() {
        return this.busy || !this.hasConnection;
    }

    get busy() {
        return Boolean(this.args.busy) || this.localBusy;
    }

    get isConfigured() {
        return this.args.configured === true;
    }

    get credentialsMissing() {
        return this.isConnected && !this.isConfigured;
    }

    get testDisabled() {
        return this.busy || !this.isConnected;
    }

    get syncDisabled() {
        return this.busy || this.isLoading || !this.isConnected || !this.isConfigured;
    }

    @action
    async connect(event) {
        event?.preventDefault?.();
        if (this.isLoading || this.loadFailed) {
            return;
        }

        await this.args.onConnect?.(connectPayload(this.args.importCustomers));
    }

    // Local delete through POST disconnect. This does not unsubscribe Intuit.
    @action
    async disconnect() {
        if (this.disconnectDisabled) {
            return;
        }

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
    reconcile() {
        if (this.syncDisabled) {
            return;
        }

        this.args.onReconcile?.();
    }

    @action
    importNow() {
        if (this.syncDisabled) {
            return;
        }

        this.args.onImport?.();
    }

    @action
    syncNow() {
        if (this.syncDisabled) {
            return;
        }

        this.args.onSync?.();
    }

    @action
    async test() {
        if (typeof this.args.onTest !== 'function') {
            return;
        }

        await this.args.onTest();
    }
}
