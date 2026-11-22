import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

const NAMESPACE = 'quickbooks/int/v1';

export default class QuickbooksCompanySettingsComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;

    @tracked sync = { interval_minutes: 5 };
    // Whether this organization already owns a copy of the sync settings. Without one it shows the current global defaults.
    @tracked hasCopy = false;
    // Whether an installation administrator saved the Client ID and Client secret. Nothing else about them reaches this screen.
    @tracked credentialsConfigured = false;
    // Undefined until the connection request finishes. Null means disconnected.
    @tracked connection = undefined;
    @tracked connectionLoadFailed = false;
    @tracked connectionLoading = true;
    // False when only a QuickBooks Administrator may make this organization's first connection.
    @tracked canConnectNew = true;
    // How many records are linked to QuickBooks, by kind. Null until the connection request answers.
    @tracked linked = null;
    // Set when Intuit returned a different company than the linked records belong to: the handle, the code and the counts.
    @tracked choice = null;
    @tracked settingsLoadFailed = false;
    @tracked settingsLoaded = false;
    @tracked canEdit = false;
    @tracked busy = false;

    constructor() {
        super(...arguments);
        // The OAuth return can already ask what to do with the old company's data (the settings route passes it in).
        this.choice = this.args.choice ?? null;
        this.load();
    }

    async load() {
        const companyUuid = this.currentUser.companyId;

        try {
            const result = await this.fetch.get('settings', { scope: 'company', company_uuid: companyUuid }, { namespace: NAMESPACE });
            this.apply(result);
            this.settingsLoadFailed = false;
            this.settingsLoaded = true;
        } catch (error) {
            this.settingsLoadFailed = true;
            this.notifications.serverError(error);
        }

        await this.loadConnection();
    }

    apply(result) {
        this.canEdit = result.can_edit === true;
        this.hasCopy = result.has_copy === true;
        this.sync = result.sync ?? this.sync;
        this.credentialsConfigured = result.credentials_configured === true;
    }

    async loadConnection() {
        this.connectionLoading = true;
        try {
            const result = await this.fetch.get('connection', { company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
            this.connection = result?.connection ?? null;
            this.canConnectNew = result?.can_connect_new !== false;
            this.linked = result?.linked ?? null;
            this.connectionLoadFailed = false;
        } catch (error) {
            this.connection = null;
            this.connectionLoadFailed = true;
        } finally {
            this.connectionLoading = false;
        }
    }

    @action
    async save(payload) {
        if (this.settingsLoadFailed || !this.settingsLoaded) {
            return;
        }

        const result = await this.fetch.post(
            'settings',
            {
                scope: 'company',
                company_uuid: this.currentUser.companyId,
                sync: payload.sync ?? {},
            },
            // rawError keeps the server's field errors, so a refused value is shown under its field.
            { namespace: NAMESPACE, rawError: true }
        );
        this.apply(result);
    }

    // Replaces this organization's sync settings with a one-time copy of the current global defaults.
    @action
    async reset() {
        if (this.settingsLoadFailed || !this.settingsLoaded) {
            return;
        }

        const result = await this.fetch.post('settings', { scope: 'company', company_uuid: this.currentUser.companyId, reset: true }, { namespace: NAMESPACE, rawError: true });
        this.apply(result);
    }

    @action
    async connect() {
        if (this.connectionLoading || this.connection === undefined) {
            return;
        }

        this.busy = true;
        try {
            const result = await this.fetch.post(
                'oauth/start',
                {
                    company_uuid: this.currentUser.companyId,
                    user_uuid: this.currentUser.id,
                },
                { namespace: NAMESPACE }
            );
            if (result?.url) {
                window.location.assign(result.url);
                return;
            }
            this.notifications.error(result?.message || this.intl.t('quickbooks.connection.connect-failed'));
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.busy = false;
        }
    }

    // Returns false when the server refused, so the question stays open for another try.
    @action
    async disconnect(options = {}) {
        this.busy = true;
        try {
            await this.fetch.post('disconnect', { company_uuid: this.currentUser.companyId, ...options }, { namespace: NAMESPACE });
            this.connection = null;
            if (options.level && options.level !== 'keep') {
                this.linked = null;
            }
            this.notifications.success(this.intl.t('quickbooks.connection.disconnected-toast'));

            return true;
        } catch (error) {
            this.notifications.serverError(error);

            return false;
        } finally {
            this.busy = false;
        }
    }

    // A random code to type before data is cleared, and the counts to show next to it.
    @action
    async requestCode() {
        try {
            return await this.fetch.post('reset-code', { company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
        } catch (error) {
            this.notifications.serverError(error);

            return null;
        }
    }

    // The answer to "what happens to the old company's data": finishes the connection Intuit just returned.
    @action
    async choose(level, code) {
        this.busy = true;
        try {
            const result = await this.fetch.post('oauth/complete', { handle: this.choice.handle, level, confirm: code, company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
            if (result?.choice_required) {
                this.choice = result;

                return;
            }
            this.choice = null;
            this.notifications.success(this.intl.t('quickbooks.connection.connected-toast'));
            await this.loadConnection();
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.busy = false;
        }
    }

    @action
    cancelChoice() {
        this.choice = null;
    }

    get configured() {
        // Unknown (undefined) until the settings request succeeds, so no screen claims credentials are missing while loading or after a failure.
        return this.settingsLoaded ? this.credentialsConfigured : undefined;
    }

    @action
    async syncNow() {
        await this.runAction(async () => {
            await this.fetch.post('sync', { company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
            this.notifications.success(this.intl.t('quickbooks.actions.sync-queued'));
        });
    }

    async runAction(work) {
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
