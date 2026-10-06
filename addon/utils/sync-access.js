// Sync now and reconcile share this permission. QuickBooks Operator grants it.
export const SYNC_NOW_PERMISSION = 'quickbooks reconcile sync';

export function canRunSyncNow(abilities) {
    return abilities?.can?.(SYNC_NOW_PERMISSION) === true;
}
