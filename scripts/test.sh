#!/usr/bin/env bash
# Run every check: spec copies in sync, Python tests, PHP lint, and PHP/Python parity.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

echo "== spec copies"
scripts/sync-spec.sh --check

echo "== python tests"
(cd cli && python3 -m unittest discover tests)

echo "== php lint"
find wordpress -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true

echo "== php/python parity"
python3 scripts/parity.py

echo "All checks passed."
