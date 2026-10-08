export function connectionState(connection) {
    if (!connection || !connection.realm_id) {
        return 'disconnected';
    }
    if (connection.needs_reauth) {
        return 'needs-reauth';
    }

    return 'connected';
}
