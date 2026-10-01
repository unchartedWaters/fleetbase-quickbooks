export function connectionState(connection) {
    if (!connection || !connection.realm_id) {
        return 'disconnected';
    }
    if (connection.needs_reauth) {
        return 'needs-reauth';
    }

    return 'connected';
}

export function connectPayload(importCustomers = false) {
    return {
        import_customers: Boolean(importCustomers),
    };
}
