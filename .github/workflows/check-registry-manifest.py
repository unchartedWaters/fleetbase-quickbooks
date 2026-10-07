#!/usr/bin/env python3
"""Fail when the npm tarball would omit files the Fleetbase registry reads.

`flb publish` runs `npm publish` against https://registry.fleetbase.io.
That registry stores composer.json and extension.json from the tarball, and
`composer require` later downloads the same tarball. The pack must contain
those manifests and the PHP package.
"""

import json
import subprocess
import sys
from pathlib import Path

REQUIRED = (
    "package.json",
    "composer.json",
    "extension.json",
    "server/src/Providers/QuickbooksServiceProvider.php",
    "server/src/routes.php",
    "server/src/Auth/Schemas/Quickbooks.php",
)

REQUIRED_PREFIXES = ("server/migrations/",)


def pack_paths() -> list[str]:
    result = subprocess.run(
        ["npm", "pack", "--dry-run", "--json", "--ignore-scripts"],
        check=False,
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        sys.stderr.write(result.stderr)
        sys.exit(result.returncode)

    raw = result.stdout
    start = raw.find("[")
    if start < 0:
        sys.stderr.write(result.stderr)
        sys.stderr.write("npm pack did not return a JSON file list\n")
        sys.exit(1)

    try:
        packed = json.loads(raw[start:])
    except json.JSONDecodeError as exc:
        sys.stderr.write(f"npm pack JSON: {exc}\n")
        sys.exit(1)

    files = packed[0].get("files", []) if packed else []
    return [item["path"] for item in files if isinstance(item, dict) and "path" in item]


def main() -> None:
    paths = pack_paths()
    missing = [name for name in REQUIRED if name not in paths]
    for prefix in REQUIRED_PREFIXES:
        if not any(path.startswith(prefix) for path in paths):
            missing.append(prefix)

    if missing:
        print("npm pack is missing Fleetbase registry files:", file=sys.stderr)
        for name in missing:
            print(f"  {name}", file=sys.stderr)
        sys.exit(1)

    composer = json.loads(Path("composer.json").read_text(encoding="utf-8"))
    package = json.loads(Path("package.json").read_text(encoding="utf-8"))
    if composer.get("name") != "unchartedwaters/quickbooks-api":
        print("composer.json name must be unchartedwaters/quickbooks-api", file=sys.stderr)
        sys.exit(1)
    if package.get("name") != "@unchartedwaters/quickbooks-engine":
        print("package.json name must be @unchartedwaters/quickbooks-engine", file=sys.stderr)
        sys.exit(1)

    print(f"registry tarball includes {len(paths)} files")


if __name__ == "__main__":
    main()
