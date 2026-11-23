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

# The code has to be there. In development /app is a bind mount, and a Docker
# daemon that starts its containers before the host directory is reachable -
# Docker Desktop on WSL2 does exactly that after a reboot - mounts an EMPTY
# directory instead. Everything then "runs": php-fpm answers 404 for every
# path, a worker loops on "Could not open input file", and the only symptom is
# an unhealthy API with nothing wrong in its log.
#
# Exiting here is the fix, not just the report: every service has a restart
# policy, a restart mounts the directory again, and by then it is there.
if [ ! -f /app/composer.json ]; then
  echo "[entrypoint] /app has no composer.json - the code is not mounted (yet). Exiting so the restart policy tries again." >&2
  exit 1
fi

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

# Production: every container warms its own cache from the code in its image.
# Never a shared volume - prod never checks a compiled container is current, so
# a cache kept across releases boots the next release on the previous one's
# container. Not at build time either: it bakes in the server's environment.
#
# Then preload, which needs that cache: switched on only once the file exists,
# because PHP refuses to start at all when opcache.preload names a missing file.
# A failed warm-up is said and survived - the first request builds the cache.
if [ "${APP_ENV:-}" = prod ]; then
  if php bin/console cache:warmup --no-interaction >/dev/null; then
    chown -R www-data:www-data /app/var/cache 2>/dev/null || true
    preload=/app/var/cache/prod/App_KernelProdContainer.preload.php
    if [ -f "$preload" ] && [ -w /usr/local/etc/php/conf.d ]; then
      printf 'opcache.preload = %s\nopcache.preload_user = www-data\n' "$preload" \
        > /usr/local/etc/php/conf.d/zz-preload.ini
    fi
  else
    echo "[entrypoint] cache:warmup failed - starting without preload; the first request builds the cache" >&2
  fi
fi

exec "$@"
