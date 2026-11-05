#!/bin/sh
set -eu

# Build input is deliberately mandatory: never substitute a guessed checksum.
: "${SQLITE_SOURCE_YEAR:?Set the year of the official 3.54.1 source archive}"
: "${SQLITE_SHA3_256:?Set the official SHA3-256 pin for sqlite-autoconf-3540100.tar.gz}"
case "$SQLITE_SOURCE_YEAR" in
    [0-9][0-9][0-9][0-9]) ;;
    *) printf '%s\n' 'Invalid official source year' >&2; exit 2 ;;
esac
export SQLITE_SOURCE_YEAR SQLITE_SHA3_256
php -r 'if (!preg_match("/\A[0-9a-f]{64}\z/D", getenv("SQLITE_SHA3_256"))) { fwrite(STDERR, "Invalid SHA3-256 pin\n"); exit(2); }'
mkdir -p /opt/sqlite /opt/sqlite-build
cd /opt/sqlite-build
url="https://www.sqlite.org/$SQLITE_SOURCE_YEAR/sqlite-autoconf-3540100.tar.gz"
curl --fail --silent --show-error --proto '=https' --tlsv1.2 --connect-timeout 10 --max-time 120 --max-filesize 20000000 --output source.tar.gz "$url"
php -r '$actual = hash_file("sha3-256", "source.tar.gz"); if (!hash_equals(getenv("SQLITE_SHA3_256"), $actual)) { fwrite(STDERR, "Official archive checksum mismatch\n"); exit(1); }'
# Inspect archive member names before extraction into a new build-only directory.
php -r '$p = new PharData("source.tar.gz"); foreach (new RecursiveIteratorIterator($p) as $file) { $name = str_replace("phar://" . realpath("source.tar.gz") . "/", "", $file->getPathname()); if (!preg_match("~\Asqlite-autoconf-3540100/[A-Za-z0-9_./+-]+\z~D", $name) || str_contains($name, "../") || $file->isLink()) { fwrite(STDERR, "Unsafe archive entry\n"); exit(1); } }'
tar -xzf source.tar.gz
cd sqlite-autoconf-3540100
php -r 'if (hash_file("sha3-256", "sqlite3.c") !== "e2b869d3003b33c942b7dd7063604fbc53553e1b66609570df39b9b7b558ee81") { fwrite(STDERR, "Official amalgamation checksum mismatch\n"); exit(1); }'
CFLAGS='-O2 -DSQLITE_ENABLE_FTS5' ./configure --prefix=/opt/sqlite --enable-fts5
make -j2
make install
# The official PHP image already includes PDO SQLite. Register the verified
# shared library before starting either CLI or FPM; do not rebuild a builtin
# extension with docker-php-ext-install.
printf '/opt/sqlite/lib\n' > /etc/ld.so.conf.d/00-commit-lab-sqlite.conf
ldconfig
export LD_LIBRARY_PATH=/opt/sqlite/lib
php -r '$db = new PDO("sqlite::memory:"); $version = $db->query("SELECT sqlite_version()")->fetchColumn(); if ($version !== "3.54.1") { fwrite(STDERR, "Wrong linked SQLite: " . $version . "\n"); exit(1); } $db->exec("CREATE VIRTUAL TABLE temp.q USING fts5(body)"); $r = ["official_url" => "https://www.sqlite.org/" . getenv("SQLITE_SOURCE_YEAR") . "/sqlite-autoconf-3540100.tar.gz", "sha3_256" => getenv("SQLITE_SHA3_256"), "sqlite_version" => $version, "sqlite_source_id" => $db->query("SELECT sqlite_source_id()")->fetchColumn(), "fts5" => true]; file_put_contents("/opt/sqlite/qualification.json", json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");'
dpkg-query -W -f='${Package}\t${Version}\n' > /opt/sqlite/packages.txt
php --version > /opt/sqlite/php-version.txt
nginx -v 2> /opt/sqlite/nginx-version.txt
cc --version > /opt/sqlite/compiler-version.txt
