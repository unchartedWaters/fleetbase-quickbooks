export function fieldState(settings, key) {
    return { value: settings?.[key] ?? '' };
}

function savedSecretPresentation(set, hint) {
    return {
        value: '',
        set: Boolean(set),
        hint: typeof hint === 'string' ? hint : '',
    };
}

export function secretPresentation(settings, key = 'client_secret') {
    return savedSecretPresentation(settings?.[`${key}_set`], settings?.[`${key}_hint`]);
}

export function webhookVerifierPresentation(settings, key = 'webhook_verifier') {
    return savedSecretPresentation(settings?.[`${key}_set`], settings?.[`${key}_hint`]);
}

// Intuit issues separate keys for its Production and Development (sandbox) apps, so the settings hold one set of
// each. Every field is named after its environment: production_client_id, sandbox_webhook_verifier, and so on.
export const ENVIRONMENTS = ['production', 'sandbox'];

export function credentialKeys(environment) {
    return {
        clientId: `${environment}_client_id`,
        secret: `${environment}_client_secret`,
        verifier: `${environment}_webhook_verifier`,
    };
}

const SYNC_DIRECTIONS = ['both', 'outbound', 'inbound'];

export function normalizeSyncDirection(stored) {
    return SYNC_DIRECTIONS.includes(stored) ? stored : 'both';
}

const CHOICES = {
    customer_conflict: ['fleetbase', 'quickbooks'],
    customer_reference: ['fleetbase', 'quickbooks'],
    invoice_conflict: ['fleetbase', 'quickbooks'],
    invoice_reference: ['fleetbase', 'quickbooks'],
    payment_conflict: ['fleetbase', 'quickbooks'],
    payment_reference: ['fleetbase', 'quickbooks'],
    wallet_conflict: ['fleetbase', 'quickbooks'],
    wallet_reference: ['fleetbase', 'quickbooks'],
};

const DIRECTION_KEYS = {
    customer_direction: 'quickbooks.validation.direction-customer',
    invoice_direction: 'quickbooks.validation.direction-invoice',
    payment_direction: 'quickbooks.validation.direction-payment',
    wallet_direction: 'quickbooks.validation.direction-wallet',
};

function entityEnabled(sync, entity) {
    const value = sync?.[`${entity}_enabled`];
    if (value === false || value === 'false' || value === 0 || value === '0') {
        return false;
    }

    return true;
}

function wholeNumber(value, minimum) {
    if (typeof value === 'number' && Number.isInteger(value)) {
        return value >= minimum;
    }
    if (typeof value === 'string' && /^-?\d+$/.test(value.trim())) {
        return Number(value) >= minimum;
    }

    return false;
}

const text = (value) => String(value || '').trim();

// A set nobody filled in is skipped. A set with any key in it needs its Client ID and Client secret, and at least one
// set must be complete. A saved secret or verifier belongs to the saved Client ID, so a changed Client ID needs its own.
function credentialErrors(auth, saved) {
    const errors = {};
    let complete = 0;
    ENVIRONMENTS.forEach((environment) => {
        const keys = credentialKeys(environment);
        const clientId = text(auth?.[keys.clientId]);
        const savedClient = text(saved?.[keys.clientId]);
        // A blank Client ID has its own error, so the saved secret is not flagged as well.
        const sameClient = !savedClient || !clientId || clientId === savedClient;
        const secret = text(auth?.[keys.secret]) || (saved?.[`${keys.secret}_set`] && sameClient ? 'saved' : '');
        const verifier = text(auth?.[keys.verifier]);
        const verifierSaved = Boolean(saved?.[`${keys.verifier}_set`]);
        if (!clientId && !secret && !verifier && !verifierSaved) {
            return;
        }
        if (!clientId) {
            errors[keys.clientId] = 'quickbooks.validation.client-id';
        }
        if (!secret) {
            errors[keys.secret] = 'quickbooks.validation.client-secret';
        }
        // With no saved verifier, webhooks stay optional.
        if (verifierSaved && !sameClient && !verifier) {
            errors[keys.verifier] = 'quickbooks.validation.webhook-verifier';
        }
        complete += clientId && secret ? 1 : 0;
    });
    if (!complete && !Object.keys(errors).length) {
        errors[credentialKeys(ENVIRONMENTS[0]).clientId] = 'quickbooks.validation.client-id';
        errors[credentialKeys(ENVIRONMENTS[0]).secret] = 'quickbooks.validation.client-secret';
    }

    return errors;
}

// syncOnly is the organization screen: credentials are global and are not checked there.
// saved is the settings the server last sent: each environment's Client ID, and whether its secret and verifier are saved.
export function validateSettings({ auth, sync, saved, syncOnly = false }) {
    const errors = syncOnly ? {} : credentialErrors(auth, saved);

    if (!wholeNumber(sync?.interval_minutes, 1)) {
        errors.interval_minutes = 'quickbooks.validation.interval-minutes';
    }
    if (!wholeNumber(sync?.periodic_interval_hours, 1)) {
        errors.periodic_interval_hours = 'quickbooks.validation.periodic-interval-hours';
    }
    if (!wholeNumber(sync?.retry_limit, 1)) {
        errors.retry_limit = 'quickbooks.validation.retry-limit';
    }
    if (!wholeNumber(sync?.default_backoff_seconds, 5)) {
        errors.default_backoff_seconds = 'quickbooks.validation.retry-delay';
    }

    Object.keys(CHOICES).forEach((key) => {
        const entity = key.split('_')[0];
        if (!entityEnabled(sync, entity)) {
            return;
        }
        if (!CHOICES[key].includes(sync?.[key])) {
            errors[key] = 'quickbooks.validation.choice';
        }
    });
    Object.keys(DIRECTION_KEYS).forEach((key) => {
        const entity = key.slice(0, -'_direction'.length);
        if (!entityEnabled(sync, entity)) {
            return;
        }
        if (!SYNC_DIRECTIONS.includes(sync?.[key])) {
            errors[key] = DIRECTION_KEYS[key];
        }
    });

    checkTaxSource(errors, sync);

    return errors;
}

// A form that does not send the field is left to the server.
function checkTaxSource(errors, sync) {
    if (sync?.tax_source !== undefined && !['quickbooks', 'fleetbase'].includes(sync.tax_source)) {
        errors.tax_source = 'quickbooks.validation.tax-source';
    }
}
