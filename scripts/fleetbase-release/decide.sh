#!/usr/bin/env bash
# Decides whether this run of .github/workflows/fleetbase-release.yml checks a Fleetbase release,
# and writes run=true or run=false to $GITHUB_OUTPUT. Tested by FleetbaseReleaseCheckTest.
#
# A scheduled run checks only a new Fleetbase release: the newest one, published in the last
# 14 days, that no run has checked yet (no unexpired fleetbase-<version>-results artifact, from
# any branch), once its API image is on Docker Hub. Results are kept 90 days, so the 14-day limit
# stops a release from being checked again when its results expire (as long as the repository's
# artifact retention setting allows at least 14 days). A run by hand or a v* tag of
# this extension always checks, and fails if the image is not on Docker Hub.
#
# Environment: EVENT (github.event_name), VERSION (the release tag), PUBLISHED_AT (its
# published_at), DIGEST (the API image digest, empty when Docker Hub does not have it),
# GITHUB_REPOSITORY, GITHUB_OUTPUT, and GH_TOKEN for gh.
set -euo pipefail

skip() {
    echo "::notice::$1"
    echo "run=false" >> "$GITHUB_OUTPUT"
    exit 0
}

if [ "${EVENT}" = schedule ]; then
    age_days=$(( ($(date -u +%s) - $(date -u -d "${PUBLISHED_AT}" +%s)) / 86400 ))
    if [ "${age_days}" -ge 14 ]; then
        skip "Fleetbase ${VERSION} came out ${age_days} days ago; there is no new release to check."
    fi
    checked=$(gh api "repos/${GITHUB_REPOSITORY}/actions/artifacts?name=fleetbase-${VERSION}-results&per_page=100" \
        --jq '[.artifacts[] | select(.expired == false)] | length')
    if [ "${checked}" != 0 ]; then
        skip "Fleetbase ${VERSION} was already checked. Run the workflow by hand to check it again."
    fi
fi

if [ -z "${DIGEST:-}" ]; then
    if [ "${EVENT}" = schedule ]; then
        skip "fleetbase/fleetbase-api:${VERSION} is not on Docker Hub yet; the next scheduled run tries again."
    fi
    echo "::error::fleetbase/fleetbase-api:${VERSION} is not on Docker Hub."
    exit 1
fi

echo "run=true" >> "$GITHUB_OUTPUT"
