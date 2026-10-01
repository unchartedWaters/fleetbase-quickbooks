import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';

export default class SettingsController extends Controller {
    queryParams = [
        {
            error: {
                replace: true,
            },
        },
        {
            oauth_state: {
                replace: true,
            },
        },
    ];

    /** @type {string|null} Set from ?error= (a code such as cancelled, state or failed) after OAuth redirect. */
    @tracked error = null;

    /** @type {string|null} Set from ?oauth_state= after OAuth redirect; finished by the route. */
    @tracked oauth_state = null;
}
