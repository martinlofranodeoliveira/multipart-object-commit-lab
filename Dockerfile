FROM php:8.4-fpm-bookworm@sha256:1b6bb4f3d32dfbcd1e159d7bd47caa8852507f61666db7731d73f34acec781b6

LABEL org.opencontainers.image.title="Multipart Object Commit Lab" \
      org.opencontainers.image.version="0.1.0" \
      org.opencontainers.image.authors="Lofrano Tecnologia" \
      org.opencontainers.image.url="https://lofranotecnologia.com.br" \
      org.opencontainers.image.source="https://github.com/martinlofranodeoliveira/multipart-object-commit-lab" \
      org.opencontainers.image.licenses="MIT"

# Dependencies are installed at image-build time, never by the unprivileged harness.
# Debian packages must not start a system service during preparation.
RUN printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d \
    && chmod 0755 /usr/sbin/policy-rc.d \
    && apt-get update \
    && apt-get install -y --no-install-recommends nginx curl ca-certificates build-essential pkg-config libsqlite3-dev util-linux

ARG SQLITE_SOURCE_YEAR=2026
ARG SQLITE_SHA3_256=b6ced585c750916b1052667239a12c25a7ff34e7f06e1e6e01ecb0427657d9c4
COPY tools/build-sqlite.sh /opt/build-sqlite.sh
RUN SQLITE_SOURCE_YEAR="$SQLITE_SOURCE_YEAR" SQLITE_SHA3_256="$SQLITE_SHA3_256" sh /opt/build-sqlite.sh

WORKDIR /app
COPY . /app
RUN php bin/qualify.php \
    && useradd --uid 10001 --create-home --shell /usr/sbin/nologin lab
USER 10001:10001
CMD ["php", "tests/run.php"]
