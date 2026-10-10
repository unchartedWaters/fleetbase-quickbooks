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
    return batches.map((batch, index) => activityRow(batch, index));
}

function activityRow(batch, index) {
    return {
        id: activityId(batch),
        headerId: `quickbooks-activity-${index}`,
        trigger: batch.trigger,
        direction: batch.direction || 'outbound',
        status: batch.status || '',
        created: countField(batch, 'created'),
        aligned: countField(batch, 'aligned'),
        linked: countField(batch, 'linked'),
        skipped: countField(batch, 'skipped'),
        updated: countField(batch, 'updated'),
        unmatched: countField(batch, 'unmatched'),
        voided: countField(batch, 'voided'),
        failed: countField(batch, 'failed'),
        error: activityError(batch),
        when: formatWhen(batch.finished_at || batch.created_at),
    };
}

function activityId(batch) {
    if (batch.uuid) {
        return batch.uuid;
    }

    const stamp = batch.started_at || batch.finished_at || '';

    return `${batch.trigger}-${stamp}`;
}

function countField(batch, name) {
    if (batch[name] !== undefined && batch[name] !== null) {
        return batch[name];
    }

    const counted = batch[`${name}_count`];
    if (counted !== undefined && counted !== null) {
        return counted;
    }

    return 0;
}

function activityError(batch) {
    if (typeof batch.error === 'string' && batch.error !== '') {
        return batch.error;
    }

    return batch.error || null;
}
