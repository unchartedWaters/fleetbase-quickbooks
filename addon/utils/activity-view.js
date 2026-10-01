function formatWhen(value) {
    if (!value) {
        return '';
    }

    const when = new Date(value);
    if (Number.isNaN(when.getTime())) {
        return String(value);
    }

    return when.toLocaleString();
}

export function activityRows(batches = []) {
    return batches.map((batch, index) => ({
        id: batch.uuid || `${batch.trigger}-${batch.started_at || batch.finished_at || ''}`,
        headerId: `quickbooks-activity-${index}`,
        trigger: batch.trigger,
        direction: batch.direction || 'outbound',
        status: batch.status || '',
        created: batch.created ?? batch.created_count ?? 0,
        aligned: batch.aligned ?? batch.aligned_count ?? 0,
        linked: batch.linked ?? batch.linked_count ?? 0,
        skipped: batch.skipped ?? batch.skipped_count ?? 0,
        updated: batch.updated ?? batch.updated_count ?? 0,
        unmatched: batch.unmatched ?? batch.unmatched_count ?? 0,
        voided: batch.voided ?? batch.voided_count ?? 0,
        failed: batch.failed ?? batch.failed_count ?? 0,
        error: typeof batch.error === 'string' && batch.error !== '' ? batch.error : batch.error || null,
        when: formatWhen(batch.finished_at || batch.created_at),
    }));
}
