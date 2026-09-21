#!/bin/sh
# =============================================================================
#  Make the writable paths writable, then hand off to supervisord.
# =============================================================================
#  /app/var and /app/vendor are named volumes. Docker creates a named volume
#  owned by root unless the image already contains that path with the ownership
#  you want - and even then, a volume created before the image was fixed keeps
#  its old ownership forever. php-fpm runs as www-data, so the first request
#  dies with "Unable to create the cache directory".
#
#  The Dockerfile pre-creates both paths with the right owner so FRESH volumes
#  are correct; this chown is what repairs volumes that already exist, so an
#  existing checkout self-heals instead of needing `docker volume rm`.
# =============================================================================
set -e

for d in /app/var /app/vendor; do
  mkdir -p "$d"
  if [ "$(stat -c %U "$d")" != "www-data" ]; then
    echo "[entrypoint] taking ownership of $d for www-data"
    chown -R www-data:www-data "$d"
  fi
done

# The dev CA, so the API and its workers can call this stack over HTTPS. A
# webhook endpoint pointed at *.${DOMAIN} otherwise fails at the TLS handshake,
# and the error names a certificate rather than the missing trust.
#
# Dev only: compose.dev.yml mounts it and the production compose file does not,
# where the CA is a real one the base image already trusts.
# Guarded on being able to write the trust store rather than on the file being
# there: a container that drops privileges before the entrypoint (the scheduler
# does) cannot install it, and failing loudly once per loop iteration is worse
# than not needing it in the first place.
if [ -f /etc/open-enu/certs/rootCA.pem ] && [ -w /usr/local/share/ca-certificates ]; then
  cp /etc/open-enu/certs/rootCA.pem /usr/local/share/ca-certificates/open-enu-dev-ca.crt
  update-ca-certificates >/dev/null 2>&1 || true
fi

exec "$@"
