#!/usr/bin/env python3
"""Fail when package.json, composer.json, and extension.json versions differ."""

import json
import sys
from pathlib import Path

FILES = ("package.json", "composer.json", "extension.json")


def read_version(name: str) -> str:
    path = Path(name)
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        print(f"{name}: {exc}", file=sys.stderr)
        sys.exit(1)

    version = data.get("version") if isinstance(data, dict) else None
    if not isinstance(version, str) or version == "":
        print(f"{name} version must be a non-empty string", file=sys.stderr)
        sys.exit(1)
    return version


def main() -> None:
    versions = {name: read_version(name) for name in FILES}
    expected = versions[FILES[0]]
    if any(version != expected for version in versions.values()):
        for name in FILES:
            print(f"{name}: {versions[name]}", file=sys.stderr)
        print(
            "package.json, composer.json, and extension.json versions must match",
            file=sys.stderr,
        )
        sys.exit(1)

    print(f"versions match: {expected}")


if __name__ == "__main__":
    main()
