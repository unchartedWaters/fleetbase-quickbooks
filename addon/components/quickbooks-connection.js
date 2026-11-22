import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { connectionState } from '../utils/connection-view';
import { canChangeCompany, canConnect, canDisconnect, canRunSyncNow } from '../utils/sync-access';

export default class QuickbooksConnectionComponent extends Component {
    @service intl;
    @service modalsManager;
    @service abilities;

    // Set while the disconnect question (keep the data, or clear it at a level) is open: its code and the counts.
    @tracked disconnecting = null;

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

    // Only a finished settings load can say the credentials are missing. Undefined means not known yet.
    get isUnconfigured() {
        return this.args.configured === false;
    }

    // Shows the stored environment as its option label. An unknown value is shown as stored.
    get environmentLabel() {
        const environment = this.args.connection?.environment;
        const key = `quickbooks.settings.options.${environment}`;

        return ['sandbox', 'production'].includes(environment) ? this.intl.t(key) : environment;
    }

    get showSyncNow() {
        return this.isConnected;
    }

    get showConnect() {
        return !this.isConnected;
    }

    // Only an administrator may point the organization at another QuickBooks company, so the others never see it.
    get showChangeCompany() {
        return this.isConnected && canChangeCompany(this.abilities) && canConnect(this.abilities);
    }

    get changeCompanyDisabled() {
        return this.busy || !this.isConfigured;
    }

    // Intuit's sign-in page is where the company is chosen, so say so wherever it is about to open.
    get showCompanyHint() {
        return this.showChangeCompany || (this.showConnect && !this.connectDisabled);
    }

    // A saved realm can be removed, including one that needs to be connected again.
    get showDisconnect() {
        return this.isConnected || this.needsReauth;
    }

    get canSync() {
        return canRunSyncNow(this.abilities);
    }

    // An active realm without saved keys matches the dashboard widget: Sync now stays off.
    get credentialsMissing() {
        return this.isConnected && this.isUnconfigured;
    }

    // The realm and keys are ready, and Sync now is off only because this user cannot run it.
    get syncPermissionDenied() {
        return this.isConnected && this.isConfigured && !this.canSync;
    }

    // Keys are saved and QuickBooks is not already connected. Connect is off only because this user cannot connect.
    get connectPermissionDenied() {
        return !this.isLoading && !this.loadFailed && !this.isConnected && this.isConfigured && !canConnect(this.abilities);
    }

    // Only a QuickBooks Administrator may make an organization's first connection; the server says so with can_connect_new.
    get firstConnectDenied() {
        return !this.isLoading && !this.loadFailed && !this.isConnected && !this.needsReauth && this.isConfigured && canConnect(this.abilities) && this.args.canConnectNew === false;
    }

    // A saved realm can be removed, including one that needs to be connected again. The button is off only for permission.
    get disconnectPermissionDenied() {
        return (this.isConnected || this.needsReauth) && !canDisconnect(this.abilities);
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
        if (this.args.canConnectNew === false && !this.needsReauth) {
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

    // The current connection is kept until the sign-in at Intuit finishes, so cancelling there changes nothing.
    @action
    changeCompany() {
        if (this.changeCompanyDisabled) {
            return;
        }

        this.modalsManager.confirm({
            title: this.intl.t('quickbooks.connection.change-company-confirm-title'),
            body: this.intl.t('quickbooks.connection.change-company-confirm'),
            acceptButtonText: this.intl.t('quickbooks.connection.change-company'),
            confirm: () => this.args.onConnect?.(),
        });
    }

    // Setup owns the disconnect request. This does not unsubscribe Intuit.
    // With linked records, an administrator is first asked what happens to them.
    @action
    async disconnect() {
        if (this.disconnectDisabled) {
            return;
        }

        const linked = this.args.linked?.total > 0;
        if (linked && canChangeCompany(this.abilities) && typeof this.args.onRequestCode === 'function') {
            const issued = await this.args.onRequestCode();
            if (issued?.code) {
                this.disconnecting = { code: issued.code, linked: issued.linked ?? this.args.linked };

                return;
            }
        }

        this.modalsManager.confirm({
            title: this.intl.t('quickbooks.connection.disconnect-confirm-title'),
            body: this.intl.t('quickbooks.connection.disconnect-confirm'),
            acceptButtonText: this.intl.t('quickbooks.connection.disconnect'),
            confirm: () => this.performDisconnect(),
        });
    }

    // The panel stays open when the server refuses the code, which it keeps valid for another try.
    @action
    async chooseDisconnect(level, code) {
        const done = await this.args.onDisconnect?.({ level, confirm: code });
        if (done !== false) {
            this.disconnecting = null;
        }
    }

    @action
    cancelDisconnect() {
        this.disconnecting = null;
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
