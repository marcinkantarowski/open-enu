#!/usr/bin/env bash
# =============================================================================
#  Put a backup back (.ai/platform/PLAN.md §9.3).
# =============================================================================
#  Destructive by definition, so it asks. The confirmation is the database name
#  rather than "yes": typing the name means having read which database is about
#  to be replaced.
#
#  Usage: restore.sh HOST [BACKUP_FILE]
#         BACKUP_FILE defaults to the latest object at BACKUP_REMOTE.
# =============================================================================
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
. "$HERE/../lib/log.sh"

HOST="${1:-}"; WHICH="${2:-}"
[ -n "$HOST" ] || die "usage: restore.sh HOST [BACKUP_FILE]"

. "$HERE/../lib/stage.sh"
SLUG="$(project_slug "$ROOT")"
# Production's. Staging has no backups: its data is disposable by design.
APP_DIR="$(stage_dir "$SLUG" production)"

REMOTE() { ssh -o BatchMode=yes "$HOST" "$@"; }
CO="docker compose --env-file $APP_DIR/.env -f docker/compose.prod.yml"

DB_NAME="$(REMOTE "grep '^DB_NAME=' $APP_DIR/.env | cut -d= -f2-")"
[ -n "$DB_NAME" ] || die "could not read DB_NAME from $HOST:$APP_DIR/.env"

if [ -z "$WHICH" ]; then
  BACKUP_REMOTE="$(REMOTE "grep '^BACKUP_REMOTE=' $APP_DIR/.env | cut -d= -f2-" || true)"
  [ -n "$BACKUP_REMOTE" ] || die "no BACKUP_REMOTE set and no file given"
  WHICH="$(REMOTE "rclone lsf '$BACKUP_REMOTE/' --include '*.dump*' | sort | tail -1")"
  [ -n "$WHICH" ] || die "no backups found at $BACKUP_REMOTE"
fi

log_warn "About to REPLACE the contents of $DB_NAME on $HOST with:"
log_info "  $WHICH"
log_info "Everything written since that dump will be gone."
echo
printf '  Type the database name (%s) to confirm: ' "$DB_NAME"
read -r answer </dev/tty
[ "$answer" = "$DB_NAME" ] || { log_info "aborted - nothing was changed"; exit 1; }

# A dump of the current state first, always. The most common restore mistake is
# restoring the wrong backup, and without this there is no way back from it.
log_step "Dumping the current state first"
REMOTE "cd $APP_DIR && bash scripts/remote/backup.sh pre-restore" >/dev/null 2>&1 \
  || log_warn "could not take a safety dump - continuing, but there is no way back from this"

log_step "Stopping the application (leaving the database up)"
REMOTE "cd $APP_DIR && $CO stop api worker-events worker-jobs scheduler" >/dev/null 2>&1

log_step "Restoring"
REMOTE "set -e
  cd $APP_DIR
  BACKUP_REMOTE=\$(grep '^BACKUP_REMOTE=' .env | cut -d= -f2-)
  KEY=\$(grep '^BACKUP_AGE_KEY_FILE=' .env | cut -d= -f2-)
  mkdir -p /tmp/restore-\$\$ && cd /tmp/restore-\$\$
  if [ -f '$APP_DIR/backups/$WHICH' ]; then cp '$APP_DIR/backups/$WHICH' .; else rclone copy \"\$BACKUP_REMOTE/$WHICH\" . --quiet; fi
  FILE='$WHICH'
  case \"\$FILE\" in
    *.age)
      [ -n \"\$KEY\" ] || { echo 'encrypted backup and no BACKUP_AGE_KEY_FILE on this server' >&2; exit 1; }
      age --decrypt -i \"\$KEY\" -o restored.dump \"\$FILE\" ;;
    *) cp \"\$FILE\" restored.dump ;;
  esac
  cd $APP_DIR
  $CO exec -T postgres psql -U \$(grep '^DB_USER=' .env | cut -d= -f2-) -d postgres \
    -c 'DROP DATABASE IF EXISTS $DB_NAME WITH (FORCE);' -c 'CREATE DATABASE $DB_NAME;'
  $CO exec -T postgres pg_restore -U \$(grep '^DB_USER=' .env | cut -d= -f2-) -d $DB_NAME --no-owner --no-privileges < /tmp/restore-\$\$/restored.dump
  rm -rf /tmp/restore-\$\$
" || die "the restore failed - the application is still stopped; the safety dump is in $APP_DIR/backups"

log_step "Starting the application"
REMOTE "cd $APP_DIR && $CO up -d" >/dev/null 2>&1

log_ok "restored $WHICH into $DB_NAME"
log_info "check: curl -fsS https://api.\$DOMAIN/health/deep"
