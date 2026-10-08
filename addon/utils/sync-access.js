// Sync now and reconcile share this permission. QuickBooks Operator grants it.
export const SYNC_NOW_PERMISSION = 'quickbooks reconcile sync';
export const CONNECT_PERMISSION = 'quickbooks connect connection';
export const DISCONNECT_PERMISSION = 'quickbooks disconnect connection';
export const UPDATE_SETTINGS_PERMISSION = 'quickbooks update settings';

function allows(abilities, permission) {
    return abilities?.can?.(permission) === true;
}

export function canRunSyncNow(abilities) {
    return allows(abilities, SYNC_NOW_PERMISSION);
}

export function canConnect(abilities) {
    return allows(abilities, CONNECT_PERMISSION);
}

export function canDisconnect(abilities) {
    return allows(abilities, DISCONNECT_PERMISSION);
}

export function canUpdateSettings(abilities) {
    return allows(abilities, UPDATE_SETTINGS_PERMISSION);
}

// Sync now needs the saved Client ID and Client secret.
// OAuth start is the check that requires a public https redirect.
// http://localhost is a valid API URL and does not block Sync now.
export function keysSavedForSync(source) {
    const clientId = typeof source?.client_id === 'string' ? source.client_id.trim() : '';

    return clientId !== '' && source?.client_secret_set === true;
}
