#!/bin/sh
set -eu

ROOT="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="$ROOT/storage/db-backups"
ENV_FILE="$ROOT/.env"
COMPOSE="docker compose"

load_env() {
	if [ ! -f "$ENV_FILE" ]; then
		echo >&2 "Missing $ENV_FILE"
		exit 1
	fi

	# shellcheck disable=SC1090
	set -a
	. "$ENV_FILE"
	set +a

	DB_DATABASE="${DB_DATABASE:-laravel}"
	DB_USERNAME="${DB_USERNAME:-laravel}"
}

postgres_running() {
	$COMPOSE ps --status running --services 2>/dev/null | grep -qx postgres
}

require_postgres() {
	if ! postgres_running; then
		echo >&2 "PostgreSQL container is not running. Start it with: make up"
		exit 1
	fi
}

next_version() {
	mkdir -p "$BACKUP_DIR"
	max=0

	for file in "$BACKUP_DIR"/*.sql.gz; do
		[ -e "$file" ] || continue
		base="$(basename "$file" .sql.gz)"
		case "$base" in
			*[!0-9]*)
				continue
				;;
		esac
		if [ "$base" -gt "$max" ]; then
			max="$base"
		fi
	done

	echo $((max + 1))
}

format_version() {
	printf '%03d' "$1"
}

backup_file_for_version() {
	echo "$BACKUP_DIR/$(format_version "$1").sql.gz"
}

cmd_backup() {
	load_env
	require_postgres

	version="$(next_version)"
	file="$(backup_file_for_version "$version")"
	mkdir -p "$BACKUP_DIR"

	cd "$ROOT"
	$COMPOSE exec -T postgres pg_dump \
		-U "$DB_USERNAME" \
		-d "$DB_DATABASE" \
		--clean \
		--if-exists \
		--no-owner \
		--no-acl \
		| gzip >"$file"

	echo "Backup saved: $file (version $version)"
}

cmd_restore() {
	version="${1:-}"
	if [ -z "$version" ]; then
		echo >&2 "Usage: make restore VERSION=<number>"
		exit 1
	fi

	case "$version" in
		*[!0-9]*)
			echo >&2 "VERSION must be a number, got: $version"
			exit 1
			;;
	esac

	load_env
	require_postgres

	file="$(backup_file_for_version "$version")"
	if [ ! -f "$file" ]; then
		echo >&2 "Backup not found: $file"
		echo >&2 "Available backups:"
		cmd_list || true
		exit 1
	fi

	cd "$ROOT"
	gunzip -c "$file" | $COMPOSE exec -T postgres psql \
		-U "$DB_USERNAME" \
		-d "$DB_DATABASE" \
		-v ON_ERROR_STOP=1 \
		--single-transaction

	echo "Restored database from version $version ($file)"
}

cmd_list() {
	mkdir -p "$BACKUP_DIR"
	found=0

	for file in "$BACKUP_DIR"/*.sql.gz; do
		[ -e "$file" ] || continue
		found=1
		ls -lh "$file"
	done

	if [ "$found" -eq 0 ]; then
		echo "No backups in $BACKUP_DIR"
	fi
}

cd "$ROOT"

case "${1:-}" in
	backup)
		cmd_backup
		;;
	restore)
		cmd_restore "${2:-}"
		;;
	list)
		cmd_list
		;;
	*)
		echo >&2 "Usage: $0 backup|restore <version>|list"
		exit 1
		;;
esac
