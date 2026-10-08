import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { connectionState } from '../utils/connection-view';
import { canConnect, canDisconnect, canRunSyncNow } from '../utils/sync-access';

export default class QuickbooksConnectionComponent extends Component {
    @service intl;
    @service modalsManager;
    @service abilities;

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

        return connectionState(this.args.connection);
    }

    get isConnected() {
        return this.state === 'connected';
    }

    get needsReauth() {
        return this.state === 'needs-reauth';
    }

    // A saved realm can be removed, including one that needs to be connected again.
    get disconnectDisabled() {
        return this.busy || (!this.isConnected && !this.needsReauth) || !canDisconnect(this.abilities);
    }

    get busy() {
        return Boolean(this.args.busy);
    }

    get isConfigured() {
        return this.args.configured === true;
    }

    get canSync() {
        return canRunSyncNow(this.abilities);
    }

    // An active realm without saved keys matches the dashboard widget: Sync now stays off.
    get credentialsMissing() {
        return this.isConnected && !this.isConfigured;
    }

    // The button is disabled with no other explanation when the realm and keys are ready.
    get syncPermissionDenied() {
        return this.isConnected && this.isConfigured && !this.canSync;
    }

    get syncDisabled() {
        return this.busy || this.isLoading || !this.isConnected || !this.isConfigured || !this.canSync;
    }

    // Connect uses the saved Client ID and Client secret, including when the realm needs to be connected again.
    // An active connection stays in the row and does not start a second sign-in.
    get connectDisabled() {
        if (this.busy || this.isLoading || this.loadFailed || this.isConnected || !canConnect(this.abilities)) {
            return true;
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

    // Setup owns the disconnect request. This does not unsubscribe Intuit.
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
