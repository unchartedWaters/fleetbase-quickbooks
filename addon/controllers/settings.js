import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';

// This page is the OAuth redirect URL, so Intuit sends these back here after sign-in.
export default class SettingsController extends Controller {
    queryParams = [{ code: { replace: true } }, { state: { replace: true } }, { realmId: { replace: true } }, { error: { replace: true } }];

    /** @type {string|null} Intuit's single-use authorization code; posted by the route, then cleared. */
    @tracked code = null;

    /** @type {string|null} The state Connect started with. */
    @tracked state = null;

    /** @type {string|null} The QuickBooks company Intuit returned. */
    @tracked realmId = null;

    /** @type {string|null} Intuit's error code, such as access_denied when the user cancels. */
    @tracked error = null;
}
