import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { keysSavedForSync } from '../utils/sync-access';

const NAMESPACE = 'quickbooks/int/v1';

export default class QuickbooksCompanySettingsComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;

    @tracked settings = null;
    @tracked sync = { interval_minutes: 5 };
    @tracked companySettings = null;
    @tracked companySync = null;
    // Undefined until the connection request finishes. Null means disconnected.
    @tracked connection = undefined;
    @tracked connectionLoadFailed = false;
    @tracked connectionLoading = true;
    @tracked settingsLoadFailed = false;
    @tracked settingsLoaded = false;
    // The settings are install-wide. Only an installation administrator can change them.
    @tracked canEdit = false;
    @tracked busy = false;

    constructor() {
        super(...arguments);
        this.load();
    }

    async load() {
        const companyUuid = this.currentUser.companyId;

        try {
            const result = await this.fetch.get('settings', { scope: 'admin', company_uuid: companyUuid }, { namespace: NAMESPACE });
            this.settings = result.auth;
            this.canEdit = result.can_edit === true;
            this.sync = result.sync ?? { interval_minutes: 5 };
            this.companySettings = result.company_auth ?? null;
            this.companySync = result.company_sync ?? null;
            this.settingsLoadFailed = false;
            this.settingsLoaded = true;
        } catch (error) {
            this.settingsLoadFailed = true;
            this.notifications.serverError(error);
        }

        await this.loadConnection();
    }

    async loadConnection() {
        this.connectionLoading = true;
        try {
            const result = await this.fetch.get('connection', { company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
            this.connection = result?.connection ?? null;
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
                scope: 'admin',
                company_uuid: this.currentUser.companyId,
                auth: payload.auth,
                sync: payload.sync,
            },
            { namespace: NAMESPACE }
        );
        this.settings = result.auth;
        this.sync = result.sync ?? this.sync;
        this.companySettings = result.company_auth ?? this.companySettings;
        this.companySync = result.company_sync ?? this.companySync;
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

    @action
    async disconnect() {
        this.busy = true;
        try {
            await this.fetch.post('disconnect', { company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
            this.connection = null;
            this.notifications.success(this.intl.t('quickbooks.connection.disconnected-toast'));
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.busy = false;
        }
    }

    get configured() {
        return keysSavedForSync(this.settings);
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
