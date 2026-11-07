#!/usr/bin/env bash
# Copy spec/ into each implementation so each one can be installed on its own.
# Run this after editing anything in spec/. CI runs it with --check.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
targets=("$root/cli/wonder_alt/spec" "$root/wordpress/wonder-alt-text/spec")

if [[ "${1:-}" == "--check" ]]; then
  for t in "${targets[@]}"; do
    diff -r "$root/spec" "$t" >/dev/null || { echo "Out of sync: $t. Run scripts/sync-spec.sh" >&2; exit 1; }
  done
  echo "spec copies are in sync"
  exit 0
fi

for t in "${targets[@]}"; do
  rm -rf "$t"
  mkdir -p "$t"
  cp "$root"/spec/* "$t"/
  echo "synced $t"
done
