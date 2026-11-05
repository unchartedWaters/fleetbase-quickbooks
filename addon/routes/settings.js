import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { dropTask, waitForQueue } from 'ember-concurrency';

// Intuit's error codes. Anything else gets the generic message, never the raw text.
const ERROR_KEYS = new Map([['access_denied', 'quickbooks.connection.oauth-cancelled']]);

export default class SettingsRoute extends Route {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;

    /** Prevents ?error= / ?code= from posting or toasting again if setup re-enters. */
    oauthResultKey = null;

    /**
     * This page is the OAuth redirect: Intuit sends the browser back here with code, state and realmId
     * (or error and state). The connection is finished before the page renders, so Connection loads the new status.
     */
    async model(params) {
        const { code, state, realmId, error } = params;
        if (!state || (!code && !error)) {
            return null;
        }

        const resultKey = `state:${state}`;
        if (this.oauthResultKey === resultKey) {
            return null;
        }
        this.oauthResultKey = resultKey;

        if (error) {
            // Lets the server drop the state so Connect can be tried again at once; setupController shows the message.
            try {
                await this.complete({ state, error });
            } catch {
                // The cancelled or failed message is shown either way.
            }

            return null;
        }

        try {
            await this.complete({ state, code, realmId });
            this.notifications.success(this.intl.t('quickbooks.connection.connected-toast'));
        } catch (failure) {
            this.notifications.serverError(failure);
        }

        return null;
    }

    complete(body) {
        return this.fetch.post('oauth/complete', { ...body, company_uuid: this.currentUser.companyId }, { namespace: 'quickbooks/int/v1' });
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        this.showOauthResultTask.perform(controller);
    }

    /**
     * One run in the actions queue, after this transition finalizes and before outlets render.
     * A second setup in that same turn is dropped. Destroying the route cancels the pending run.
     */
    @dropTask
    *showOauthResultTask(controller) {
        yield waitForQueue('actions');
        this.showOauthResult(controller);
    }

    showOauthResult(controller) {
        // The code is single use and done with; keep it out of the address bar and history.
        controller.code = null;
        controller.state = null;
        controller.realmId = null;

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
            controller.code = null;
            controller.state = null;
            controller.realmId = null;
            this.oauthResultKey = null;
        }
    }
}
