# nginx configs — read before editing

## `monadungeon-https.conf` is NOT the file running in production

`compose.prod.yml` bind-mounts this file to `/etc/nginx/conf.d/default.conf`, but
on the production host (`/opt/monadungeon`) it is **locally modified and has been
for a long time** — `git status` there permanently shows it as ` M`.

The live version is roughly three times the size of the committed one, because
that single nginx container is the shared edge proxy for every site on the box,
not just monadungeon. As of the last audit it served vhosts for:

| project | domains |
| --- | --- |
| monadungeon (Karak) | `karak.avelent.work` (`monadungeon.avelent.work` redirects to it) |
| showcase | `showcase.monadungeon.xyz` |
| watermelon | `watermelon.monadungeon.xyz` |
| td | `td.monadungeon.xyz`, `td.avelent.work` |
| ledger | `ledger.avelent.work`, `ledger-py.avelent.work` |
| avelent site | `avelent.work`, `www.avelent.work` |
| countryatlas | `countryatlas.org` |
| swapnad | `swapnad.xyz`, `www.swapnad.xyz`, `indexer.swapnad.xyz` |

The committed version contains only the monadungeon vhosts and is older than the live one: the live
`karak.avelent.work` server block also carries the push location below (online tables), kept out of the
committed file so that `git pull` on the host keeps working.

```nginx
    # Push (Mercure, Server-Sent Events): long-lived streams, never buffered
    location /.well-known/mercure {
        proxy_pass http://mercure:80;
        proxy_http_version 1.1;
        proxy_set_header Connection '';
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_buffering off;
        proxy_cache off;
        proxy_read_timeout 1h;
    }
```

Its `location /api` also passes `proxy_set_header X-Forwarded-Port $server_port;`, so the Google sign-in callback
is built as `https://karak.avelent.work/...` (the app now falls back to the scheme's default port without it).

### Consequences

**Never `git checkout` this file on the production host.** Replacing the live
config with the committed one drops every other project's vhost and takes those
sites down on the next reload.

**Avoid committing changes to this file.** The host copy is locally modified, so
any incoming commit that touches it makes `git pull` abort — which means
`scripts/deploy.sh` fails at its first step. Change the live config on the server
instead, and back it up first (the host already has a `.bak-*` convention).

### Editing the live config safely

```bash
# on the server
cd /opt/monadungeon
cp -a docker/nginx/monadungeon-https.conf docker/nginx/monadungeon-https.conf.bak-$(date +%s)
# ...edit...
docker exec nginx_monadungeon_prod nginx -t     # must pass before reloading
docker exec nginx_monadungeon_prod nginx -s reload   # reload, not restart — no downtime
```

Re-read the file immediately before writing it. Other projects deploy to this
same host and append their own vhosts, so a copy fetched minutes earlier may
already be stale — overwriting it wholesale silently deletes their blocks.

## Certbot

The `certbot` service in `compose.prod.yml` has an entrypoint that is an infinite
`certbot renew` loop. `docker compose run --rm certbot certonly ...` therefore
**ignores the `certonly` arguments entirely** and just runs the renew loop. Any
one-off certbot invocation must override it:

```bash
docker compose -f compose.prod.yml --env-file .env.prod \
    run --rm --entrypoint certbot certbot certonly --webroot \
    --webroot-path=/var/www/certbot -d <domain>
```

## Unused files

`fe.conf`, `monadungeon-http.conf`, `monadungeon-unified.conf`,
`monadungeon.xyz.conf`, `prod.conf`, `prod-http-only.conf` are not mounted by
`compose.prod.yml` and appear to be leftovers from earlier setups.
