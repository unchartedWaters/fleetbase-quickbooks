import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { scheduleOnce } from '@ember/runloop';

const ERROR_KEYS = new Map([
    ['cancelled', 'quickbooks.connection.oauth-cancelled'],
    ['state', 'quickbooks.connection.oauth-expired'],
    ['failed', 'quickbooks.connection.oauth-failed'],
]);

export default class SettingsRoute extends Route {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;

    /** Prevents ?error= / ?oauth_state= from toasting again if setup re-enters. */
    oauthResultKey = null;

    /** Finishes the connection before the page renders, so Connection loads the new status. */
    async model(params) {
        const state = params.oauth_state;
        const resultKey = `state:${state}`;

        if (!state || this.oauthResultKey === resultKey) {
            return null;
        }
        this.oauthResultKey = resultKey;

        try {
            await this.fetch.post(
                'oauth/complete',
                {
                    state,
                    company_uuid: this.currentUser.companyId,
                },
                { namespace: 'quickbooks/int/v1' }
            );
            this.notifications.success(this.intl.t('quickbooks.connection.connected-toast'));
        } catch (error) {
            this.notifications.serverError(error);
        }

        return null;
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        scheduleOnce('actions', this, this.showOauthResult, controller);
    }

    showOauthResult(controller) {
        controller.oauth_state = null;

        const error = controller.error;
        if (!error) {
            return;
        }

        const resultKey = `error:${error}`;
        const alreadyShown = this.oauthResultKey === resultKey;
        this.oauthResultKey = resultKey;
        controller.error = null;

        if (alreadyShown) {
            return;
        }

        this.notifications.error(this.intl.t(ERROR_KEYS.get(error) ?? 'quickbooks.connection.oauth-failed'));
    }

    resetController(controller, isExiting) {
        if (isExiting) {
            controller.error = null;
            controller.oauth_state = null;
            this.oauthResultKey = null;
        }
    }
}
