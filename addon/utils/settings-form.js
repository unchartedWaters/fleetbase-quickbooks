export function fieldState(settings, key) {
    const source = settings?.sources?.[key] || 'default';
    const scope = settings?.scope || 'company';

    return {
        value: settings?.[key] ?? '',
        source,
        inherited: scope === 'company' && source !== 'company',
    };
}

function savedSecretPresentation(set) {
    return {
        value: '',
        set: Boolean(set),
    };
}

export function secretPresentation(settings) {
    return savedSecretPresentation(settings?.client_secret_set);
}

export function webhookVerifierPresentation(settings) {
    return savedSecretPresentation(settings?.webhook_verifier_set);
}

export const DEFAULT_ENVIRONMENT = 'production';

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

export function validateSettings({ auth, sync, secretSet }) {
    const errors = {};
    if (!String(auth?.client_id || '').trim()) {
        errors.client_id = 'quickbooks.validation.client-id';
    }

    const environment = auth?.environment || DEFAULT_ENVIRONMENT;
    if (!['sandbox', 'production'].includes(environment)) {
        errors.environment = 'quickbooks.validation.environment';
    }

    if (!String(auth?.client_secret || '').trim() && !secretSet) {
        errors.client_secret = 'quickbooks.validation.client-secret';
    }

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

    return errors;
}
