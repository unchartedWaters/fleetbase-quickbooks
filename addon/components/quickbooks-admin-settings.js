import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

const NAMESPACE = 'quickbooks/int/v1';

// Admin > QuickBooks > Settings. The global Client ID, secret, URLs, and default sync options live here, for installation administrators only.
export default class QuickbooksAdminSettingsComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;

    @tracked settings = null;
    @tracked sync = { interval_minutes: 5 };
    @tracked canEdit = false;
    @tracked settingsLoadFailed = false;
    @tracked settingsLoaded = false;

    constructor() {
        super(...arguments);
        this.load();
    }

    async load() {
        try {
            const result = await this.fetch.get('settings', { scope: 'admin', company_uuid: this.currentUser.companyId }, { namespace: NAMESPACE });
            this.apply(result);
            this.settingsLoadFailed = false;
            this.settingsLoaded = true;
        } catch (error) {
            this.settingsLoadFailed = true;
            this.notifications.serverError(error);
        }
    }

    apply(result) {
        this.settings = result.auth;
        this.canEdit = result.can_edit === true;
        this.sync = result.sync ?? this.sync;
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
        this.apply(result);
    }
}
