#!/usr/bin/env bash
# Start a local PlayTube preview (requires PHP + config.php + MySQL).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
PORT="${PORT:-8080}"
HOST="${HOST:-127.0.0.1}"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP is not installed or not on PATH."
  echo "Install PHP 7.1+ (e.g. brew install php) and retry."
  exit 1
fi

if [[ ! -f "$ROOT/config.php" ]]; then
  echo "Missing $ROOT/config.php (gitignored — copy from production/staging)."
  echo "It must define DB credentials and site_url for local use."
  exit 1
fi

echo "Starting PlayTube at http://${HOST}:${PORT}"
echo "Open a watch URL, e.g. http://${HOST}:${PORT}/watch/<slug>.html"
echo "Press Ctrl+C to stop."
php -S "${HOST}:${PORT}" -t "$ROOT"
