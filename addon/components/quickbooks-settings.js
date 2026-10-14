import Component from '@glimmer/component';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { scheduleTask } from 'ember-lifeline';
import { DEFAULT_ENVIRONMENT, fieldState, normalizeSyncDirection, secretPresentation, validateSettings, webhookVerifierPresentation } from '../utils/settings-form';

const NUMBER_FIELDS = ['interval_minutes', 'periodic_interval_hours', 'retry_limit', 'default_backoff_seconds'];
const MINIMUMS = {
    interval_minutes: 1,
    periodic_interval_hours: 1,
    retry_limit: 1,
    default_backoff_seconds: 5,
};
const AUTH_FIELDS = ['environment', 'client_id'];
const SYNC_FIELDS = ['interval_minutes', 'periodic_interval_hours', 'retry_limit', 'default_backoff_seconds'];
const POLICY_FIELDS = [
    'customer_conflict',
    'customer_reference',
    'customer_direction',
    'invoice_conflict',
    'invoice_reference',
    'invoice_direction',
    'payment_conflict',
    'payment_reference',
    'payment_direction',
    'wallet_conflict',
    'wallet_reference',
    'wallet_direction',
];
const PRIMARY_ENTITIES = ['customer', 'invoice', 'payment', 'wallet'];
const CHOICE_DEFAULTS = {
    customer_conflict: 'fleetbase',
    customer_reference: 'fleetbase',
    invoice_conflict: 'fleetbase',
    invoice_reference: 'fleetbase',
    payment_conflict: 'fleetbase',
    payment_reference: 'fleetbase',
    wallet_conflict: 'fleetbase',
    wallet_reference: 'fleetbase',
    customer_direction: 'both',
    invoice_direction: 'both',
    payment_direction: 'both',
    wallet_direction: 'both',
};
const ERROR_FIELD_ORDER = [
    'environment',
    'client_id',
    'client_secret',
    'webhook_verifier',
    'interval_minutes',
    'periodic_interval_hours',
    'retry_limit',
    'default_backoff_seconds',
    'customer_conflict',
    'customer_reference',
    'customer_direction',
    'invoice_conflict',
    'invoice_reference',
    'invoice_direction',
    'payment_conflict',
    'payment_reference',
    'payment_direction',
    'wallet_conflict',
    'wallet_reference',
    'wallet_direction',
];

const CREDENTIAL_FIELDS = ['environment', 'client_id', 'client_secret', 'webhook_verifier'];

function firstErrorKey(errors) {
    return ERROR_FIELD_ORDER.find((key) => errors?.[key]) || Object.keys(errors || {})[0] || null;
}

function errorFieldSelector(key) {
    if (typeof key === 'string' && key.endsWith('_reference')) {
        return `[data-test-sync="${key.replace(/_reference$/, '_conflict')}"]`;
    }

    if (CREDENTIAL_FIELDS.includes(key)) {
        return `[data-test-field="${key}"]`;
    }

    return `[data-test-sync="${key}"]`;
}

const DISPLAY_URLS = [
    {
        key: 'public_webhook_receiver_url',
        editable: true,
        fallback: 'internal_webhook_receiver_url',
    },
    {
        key: 'internal_webhook_receiver_url',
        editable: false,
    },
    {
        key: 'public_oauth_redirect_url',
        editable: true,
        fallback: 'internal_oauth_redirect_url',
    },
    {
        key: 'internal_oauth_redirect_url',
        editable: false,
    },
];

// The screen has one Primary. Anything other than quickbooks is Fleetbase, including a stored conflict of report.
function displayedPrimary(conflict) {
    return conflict === 'quickbooks' ? 'quickbooks' : 'fleetbase';
}

function isBlank(value) {
    return value === '' || value === null || value === undefined;
}

function primaryEntity(key) {
    return PRIMARY_ENTITIES.find((entity) => key === `${entity}_conflict` || key === `${entity}_reference`) ?? null;
}

const LABEL_KEYS = {
    client_id: 'quickbooks.settings.fields.client-id',
    environment: 'quickbooks.settings.fields.environment',
    interval_minutes: 'quickbooks.settings.fields.interval-minutes',
    periodic_interval_hours: 'quickbooks.settings.fields.periodic-interval-hours',
    retry_limit: 'quickbooks.settings.fields.retry-limit',
    default_backoff_seconds: 'quickbooks.settings.fields.retry-delay',
};

const HINT_KEYS = {
    client_id: 'quickbooks.settings.hints.client-id',
    environment: 'quickbooks.settings.hints.environment',
    interval_minutes: 'quickbooks.settings.hints.interval-minutes',
    periodic_interval_hours: 'quickbooks.settings.hints.periodic-interval-hours',
    retry_limit: 'quickbooks.settings.hints.retry-limit',
    default_backoff_seconds: 'quickbooks.settings.hints.retry-delay',
};

const URL_COPY = {
    internal_webhook_receiver_url: ['quickbooks.settings.urls.internal-webhook', 'quickbooks.settings.urls.internal-webhook-hint'],
    public_webhook_receiver_url: ['quickbooks.settings.urls.public-webhook', 'quickbooks.settings.urls.public-webhook-hint'],
    internal_oauth_redirect_url: ['quickbooks.settings.urls.internal-oauth', 'quickbooks.settings.urls.internal-oauth-hint'],
    public_oauth_redirect_url: ['quickbooks.settings.urls.public-oauth', 'quickbooks.settings.urls.public-oauth-hint'],
};

export default class QuickbooksSettingsComponent extends Component {
    @service notifications;
    @service intl;
    @service modalsManager;

    @tracked draft = {};
    @tracked errors = {};
    @tracked saving = false;

    // The organization screen edits sync options only: no credentials, no URLs.
    get syncOnly() {
        return this.args.syncOnly === true;
    }

    get syncSource() {
        return this.args.sync;
    }

    // The note only describes a finished, successful load.
    get showCopyNote() {
        return this.syncOnly && this.args.settingsLoaded === true && this.args.settingsLoadFailed !== true;
    }

    get hasCopy() {
        return this.args.hasCopy === true;
    }

    // Reset is for an organization that can edit its own copy of the sync settings.
    get showReset() {
        return this.syncOnly && !this.readOnly;
    }

    get resetDisabled() {
        return this.args.settingsLoadFailed === true || this.args.settingsLoaded !== true || this.saving || this.args.busy === true || this.readOnly;
    }

    @action
    reset() {
        if (this.resetDisabled) {
            return;
        }

        this.modalsManager.confirm({
            title: this.intl.t('quickbooks.settings.reset-confirm-title'),
            body: this.intl.t('quickbooks.settings.reset-confirm'),
            acceptButtonText: this.intl.t('quickbooks.settings.reset'),
            confirm: () => this.performReset(),
        });
    }

    async performReset() {
        this.saving = true;
        try {
            await this.args.onReset?.();
            this.notifications.success(this.intl.t('quickbooks.settings.reset-done'));
            this.primaryTouched.clear();
            this.draft = {};
            this.errors = {};
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.saving = false;
        }
    }

    // Saving is for installation administrators, so the server's can_edit is the only answer.
    // A missing value (a host that does not pass it) leaves the form editable and the server decides.
    get readOnly() {
        return this.args.canEdit === false;
    }

    // The note only describes a finished, successful load. Loading or a failed load must not claim the caller lacks permission.
    get showReadOnlyNote() {
        return this.args.settingsLoaded === true && this.readOnly;
    }

    get saveDisabled() {
        return this.args.settingsLoadFailed === true || this.saving || this.readOnly;
    }

    primaryTouched = new Set();

    get authFields() {
        return AUTH_FIELDS.map((key) => this.present(key));
    }

    get syncFields() {
        return SYNC_FIELDS.map((key) => this.present(key));
    }

    get policies() {
        return [
            { entity: 'customer', label: this.intl.t('quickbooks.settings.entities.customer') },
            { entity: 'invoice', label: this.intl.t('quickbooks.settings.entities.invoice') },
            { entity: 'payment', label: this.intl.t('quickbooks.settings.entities.payment') },
            { entity: 'wallet', label: this.intl.t('quickbooks.settings.entities.wallet') },
        ].map((policy) => {
            const conflict = this.present(`${policy.entity}_conflict`);
            const reference = this.present(`${policy.entity}_reference`);
            const direction = this.entityDirection(policy.entity);

            return {
                ...policy,
                enabled: this.entityEnabled(policy.entity),
                enabledKey: `${policy.entity}_enabled`,
                primary: displayedPrimary(conflict.value),
                conflictKey: conflict.key,
                referenceKey: reference.key,
                direction: direction.direction,
                directionKey: direction.directionKey,
            };
        });
    }

    get displayedUrls() {
        return DISPLAY_URLS.map((field) => {
            const [labelKey, hintKey] = URL_COPY[field.key];

            return {
                ...field,
                label: this.intl.t(labelKey),
                hint: this.intl.t(hintKey),
                value: this.displayedUrl(field.key),
            };
        });
    }

    get secret() {
        const presentation = secretPresentation(this.args.settings);
        // A saved secret belongs to the saved Client ID, so it is not offered for a changed one.
        const savedClientId = String(this.args.settings?.client_id || '').trim();
        const clientId = String(this.present('client_id').value || '').trim();
        if (savedClientId && clientId && clientId !== savedClientId) {
            presentation.set = false;
        }
        if (this.draft.client_secret !== null && this.draft.client_secret !== undefined) {
            presentation.value = this.draft.client_secret;
        }
        // The fields template already receives this object, so the verifier rides along with the same blank-keeps-saved shape.
        presentation.verifier = webhookVerifierPresentation(this.args.settings);
        if (this.draft.webhook_verifier !== null && this.draft.webhook_verifier !== undefined) {
            presentation.verifier.value = this.draft.webhook_verifier;
        }

        return presentation;
    }

    displayedUrl(key) {
        if (Object.hasOwn(this.draft, key)) {
            const drafted = this.draft[key];

            return typeof drafted === 'string' ? drafted : '';
        }

        const value = this.args.settings?.[key];
        if (typeof value === 'string' && value.trim() !== '') {
            return value;
        }

        const field = DISPLAY_URLS.find((item) => item.key === key);
        if (field?.fallback) {
            const fallback = this.args.settings?.[field.fallback];

            return typeof fallback === 'string' ? fallback : '';
        }

        return typeof value === 'string' ? value : '';
    }

    present(key) {
        const source = AUTH_FIELDS.includes(key) ? this.args.settings : this.syncSource;
        const state = fieldState(source, key);
        this.applyPresentDefault(state, key);
        this.applyPresentDraft(state, key);

        return this.presentField(key, state);
    }

    applyPresentDefault(state, key) {
        if (isBlank(state.value) === false) {
            return;
        }
        if (key === 'interval_minutes') {
            state.value = 5;
        } else if (key === 'periodic_interval_hours') {
            state.value = 24;
        } else if (key === 'environment') {
            state.value = DEFAULT_ENVIRONMENT;
        } else if (CHOICE_DEFAULTS[key]) {
            state.value = CHOICE_DEFAULTS[key];
        }
    }

    applyPresentDraft(state, key) {
        const entity = primaryEntity(key);
        if (entity) {
            // Dropdown edits live in the draft. Otherwise show the loaded Primary.
            const drafted = this.primaryTouched.has(entity) && Object.hasOwn(this.draft, key);
            state.value = drafted ? this.draft[key] : displayedPrimary(this.syncSource?.[`${entity}_conflict`]);

            return;
        }
        if (Object.hasOwn(this.draft, key)) {
            state.value = this.draft[key];
        }
    }

    presentField(key, state) {
        return {
            key,
            label: LABEL_KEYS[key] ? this.intl.t(LABEL_KEYS[key]) : key,
            hint: HINT_KEYS[key] ? this.intl.t(HINT_KEYS[key]) : null,
            type: NUMBER_FIELDS.includes(key) ? 'number' : 'text',
            min: MINIMUMS[key] ?? null,
            ...state,
        };
    }

    @action
    update(key, event) {
        const value = event.target.value;
        if (PRIMARY_ENTITIES.includes(key)) {
            const conflictKey = `${key}_conflict`;
            const referenceKey = `${key}_reference`;
            this.primaryTouched.add(key);
            this.draft = {
                ...this.draft,
                [conflictKey]: value,
                [referenceKey]: value,
            };
            if (this.errors[conflictKey] || this.errors[referenceKey]) {
                const errors = { ...this.errors };
                delete errors[conflictKey];
                delete errors[referenceKey];
                this.errors = errors;
            }
            return;
        }

        this.draft = { ...this.draft, [key]: value };
        if (this.errors[key]) {
            const errors = { ...this.errors };
            delete errors[key];
            this.errors = errors;
        }
    }

    @action
    setEntityEnabled(entity, enabled) {
        enabled = enabled === true;
        this.draft = { ...this.draft, [`${entity}_enabled`]: enabled };
        if (!enabled) {
            const errors = { ...this.errors };
            delete errors[`${entity}_conflict`];
            delete errors[`${entity}_reference`];
            delete errors[`${entity}_direction`];
            this.errors = errors;
        }
    }

    revealFirstError(errors) {
        const key = firstErrorKey(errors);
        // ember-lifeline rejects the afterRender queue. The render queue is
        // already holding Glimmer's revalidate job, so this runs after the DOM updates.
        scheduleTask(this, 'render', () => {
            const root = document.querySelector('[data-test-settings-scope]');
            if (!root || !key) {
                return;
            }

            const field = root.querySelector(errorFieldSelector(key));
            const target = field || root.querySelector(`[data-test-error="${CSS.escape(key)}"]`);
            if (!target) {
                return;
            }

            target.scrollIntoView?.({ block: 'center' });
            target.focus?.();
        });
    }

    @action
    async save(event) {
        event?.preventDefault?.();
        if (this.saveDisabled || this.args.settingsLoaded === false) {
            return;
        }

        const auth = this.syncOnly ? null : this.buildAuth();

        const sync = {};
        SYNC_FIELDS.forEach((key) => {
            sync[key] = this.numeric(key);
        });
        POLICY_FIELDS.forEach((key) => {
            if (key.endsWith('_direction')) {
                const entity = key.slice(0, -'_direction'.length);
                sync[key] = this.entityDirection(entity).direction;
                return;
            }
            sync[key] = this.present(key).value;
        });
        PRIMARY_ENTITIES.forEach((entity) => {
            sync[`${entity}_enabled`] = this.entityEnabled(entity);
        });

        const errors = validateSettings({
            auth: auth && { ...auth, client_secret: this.draft.client_secret || '' },
            sync,
            secretSet: Boolean(this.args.settings?.client_secret_set),
            savedClientId: this.args.settings?.client_id ?? '',
            syncOnly: this.syncOnly,
        });
        this.errors = this.localizeErrors(errors);
        if (Object.keys(errors).length) {
            this.revealFirstError(errors);
            return;
        }

        this.saving = true;
        try {
            if (this.syncOnly) {
                await this.args.onSave?.({ scope: 'company', sync });
            } else {
                await this.args.onSave?.({ scope: 'company', auth, sync });
            }
            this.notifications.success(this.intl.t('quickbooks.settings.saved'));
            this.primaryTouched.clear();
            this.draft = {};
            this.errors = {};
        } catch (error) {
            const serverErrors = error?.errors;
            if (serverErrors && typeof serverErrors === 'object' && !Array.isArray(serverErrors)) {
                this.errors = this.localizeErrors(serverErrors);
                this.revealFirstError(serverErrors);
            } else {
                this.notifications.serverError(error);
            }
        } finally {
            this.saving = false;
        }
    }

    buildAuth() {
        const auth = {
            client_id: String(this.present('client_id').value || '').trim(),
            environment: this.present('environment').value || DEFAULT_ENVIRONMENT,
        };
        if (this.draft.client_secret) {
            auth.client_secret = this.draft.client_secret;
        }
        if (this.draft.webhook_verifier) {
            auth.webhook_verifier = this.draft.webhook_verifier;
        }
        auth.public_webhook_receiver_url = String(this.displayedUrl('public_webhook_receiver_url') || '').trim();
        auth.public_oauth_redirect_url = String(this.displayedUrl('public_oauth_redirect_url') || '').trim();

        return auth;
    }

    localizeErrors(errors) {
        const localized = {};
        Object.keys(errors || {}).forEach((key) => {
            const value = errors[key];
            localized[key] = typeof value === 'string' && value.startsWith('quickbooks.') ? this.intl.t(value) : value;
        });

        return localized;
    }

    entityEnabled(entity) {
        const key = `${entity}_enabled`;
        const stored = Object.hasOwn(this.draft, key) ? this.draft[key] : this.syncSource?.[key];
        if (stored === false || stored === 'false' || stored === 0 || stored === '0') {
            return false;
        }

        return true;
    }

    entityDirection(entity) {
        const directionKey = `${entity}_direction`;
        const stored = Object.hasOwn(this.draft, directionKey) ? this.draft[directionKey] : this.syncSource?.[directionKey];

        return {
            direction: normalizeSyncDirection(stored),
            directionKey,
        };
    }

    numeric(key) {
        const value = this.present(key).value;
        if (typeof value === 'string' && /^-?\d+$/.test(value.trim())) {
            return Number(value);
        }

        return value;
    }
}
