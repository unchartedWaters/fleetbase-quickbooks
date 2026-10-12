// Sync now and reconcile share this permission. QuickBooks Operator grants it.
export const SYNC_NOW_PERMISSION = 'quickbooks reconcile sync';
export const CONNECT_PERMISSION = 'quickbooks connect connection';
export const DISCONNECT_PERMISSION = 'quickbooks disconnect connection';

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
