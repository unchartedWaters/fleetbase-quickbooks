#!/usr/bin/env python3
"""Create or update unchartedwaters/quickbooks-api on Packagist.

Packagist documents these endpoints at https://packagist.org/apidoc:
  POST /api/update-package
  POST /api/create-package

Authorization is `Bearer username:token`. The token is never printed.
Create needs the main API token. Update accepts a main or safe token.
"""

import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path

REPOSITORY = "https://github.com/unchartedWaters/fleetbase-quickbooks"
PACKAGE = "unchartedwaters/quickbooks-api"


def redact(text: str, username: str, token: str) -> str:
    if username:
        text = text.replace(username, "[redacted]")
    if token:
        text = text.replace(token, "[redacted]")
    return text


def post(path: str, username: str, token: str) -> tuple[int, dict]:
    body = json.dumps({"repository": REPOSITORY}).encode()
    request = urllib.request.Request(
        f"https://packagist.org/api/{path}",
        data=body,
        method="POST",
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Authorization": f"Bearer {username}:{token}",
            "User-Agent": "unchartedwaters-quickbooks-api (+https://github.com/unchartedWaters/fleetbase-quickbooks)",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            raw = response.read().decode("utf-8", "replace")
            status = response.status
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", "replace")
        status = exc.code

    try:
        payload = json.loads(raw) if raw else {}
    except json.JSONDecodeError:
        payload = {"raw": raw[:500]}

    if not isinstance(payload, dict):
        payload = {"raw": raw[:500]}

    print(f"{path} HTTP {status} status={payload.get('status', '')}")
    message = payload.get("message")
    if isinstance(message, str) and message:
        print(redact(message, username, token))
    return status, payload


def missing_package(status: int, payload: dict) -> bool:
    if status == 404:
        return True
    text = json.dumps(payload).lower()
    return "not found" in text or "does not exist" in text or "could not find" in text


def succeeded(status: int, payload: dict) -> bool:
    return status < 400 and payload.get("status") == "success"


def main() -> None:
    composer = json.loads(Path("composer.json").read_text(encoding="utf-8"))
    if composer.get("name") != PACKAGE:
        print(f"composer.json name is {composer.get('name')}, expected {PACKAGE}", file=sys.stderr)
        sys.exit(1)

    username = os.environ.get("PACKAGIST_USERNAME", "")
    token = os.environ.get("PACKAGIST_TOKEN", "")
    if username == "" or token == "":
        print(
            "PACKAGIST_USERNAME and PACKAGIST_TOKEN are required. "
            "Create them on the Packagist account that will own "
            f"{PACKAGE}. Create uses the main API token.",
            file=sys.stderr,
        )
        sys.exit(1)

    status, payload = post("update-package", username, token)
    if missing_package(status, payload):
        status, payload = post("create-package", username, token)
        if not succeeded(status, payload):
            sys.exit(1)
        status, payload = post("update-package", username, token)

    if not succeeded(status, payload):
        sys.exit(1)

    print(f"Packagist accepted {PACKAGE} from {REPOSITORY}")


if __name__ == "__main__":
    main()
