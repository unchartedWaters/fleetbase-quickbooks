import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ConnectionRoute extends Route {
    @service hostRouter;

    beforeModel() {
        return this.hostRouter.replaceWith('console.quickbooks.settings');
    }
}
