## Bitwarden/Vaultwarden synchronization tool

Tool for synchronizing organization passwords between Bitwarden/Vaultwarden servers

## Requirements:

 - [Bitwarden CLI Tool](https://bitwarden.com/help/cli/) (2024.6.0 or newer)
 - Redis
 - PHP 8.0+ (recommended 8.3.6+)
 - PHP Extension: redis
 - PHP Extension: sockets
 - PHP Extension: pcntl (optional: the script stops the bw processes and exits cleanly on SIGTERM and SIGINT)
 - PHP Library: [php-shellcommand](https://github.com/mikehaertl/php-shellcommand)

## What you need to know about how the tool works

***Redis database*** 

The redis database stores references to UUID items on both servers (since it is impossible to make UUIDs the same on both servers). If the database is lost and both organizations contain data, the regular synchronization refuses to run (it would create a copy of everything): it logs an error and skips the cycle. Stop the service and run `php run.php --rebuild-mapping` once: it links the collections (same name) and the items (same type, name and user name) that exist on both servers and creates nothing; items that differ in content are reported and are synchronized after the next change of either of them. `$config['FORCE_INITIAL_SYNC'] = true` starts from scratch anyway. For a first synchronization the **destination server organization should not contain any items**.

The stored references are the only thing that prevents duplicates, so keep them safe. The docker-compose.yml starts Redis with `--save 60 1` (a snapshot every minute if anything changed), the default Redis policy may lose up to an hour of changes after a crash. For stronger durability enable AOF, but never just add `--appendonly yes` to an existing installation: Redis would load the (still empty) append only file instead of `dump.rdb` and the references would be lost. Enable it on the running server first (`redis-cli config set appendonly yes`), wait until `INFO persistence` shows that the rewrite is finished and only then add the option to the command.

***Delete Items***

The only way to delete an item on both servers is to add "[DELETE]" to the beginning or to the end of the name (the marker in the middle of a name is just text). Classic item deletion will not produce any result. This is to avoid loss of important data during the user process, especially at the early stage of tool development. The counterpart is deleted first and the marked item second, so if something fails the marker is still there and the next cycle repeats the deletion. The items go to the trash of the servers.

***Synchronized items***

Only "Login" items from the organizations set in `ORGANIZATION_ID` are synchronized. Items of other types (Secure Note, Card, Identity, SSH Key), items of the personal vault and items of other organizations are skipped. Skipped items of unsupported types are reported in the log on every synchronization cycle.

***Collections of an item***

Moving an item between collections is synchronized too (`SYNC_COLLECTION_MEMBERSHIP`): the tool remembers the collections of every item after each cycle and copies the change made on one server to the other one (an item is never left without collections). Collections are created on the other server, but their renaming and deletion is not synchronized.

***Conflicts***

If an item was changed on both servers between two cycles, `CONFLICT_POLICY` decides. `newest` (default) keeps the newer version and saves the older one as an item named "name (conflict copy date)" on the server where it was overwritten (it is then synchronized like any new item), `local` or `remote` always take the version of that server, `skip` changes nothing and reports an error until somebody resolves the conflict. "Newer" compares the revision dates of the servers, so their clocks should be in sync. An item changed in the same way on both servers is not a conflict.

***Log and notifications***

Every line of the log has a time and a level (`LOG_LEVEL`: debug, info, warning, error; warnings and errors go to stderr). Each synchronization cycle ends with one summary line: how many items were created, updated, deleted, skipped and failed in each direction and how many errors happened. Failed API calls are logged with the HTTP status and the message of the server.

A cycle is skipped when `bw sync` fails or the vault is locked, so the tool never works with stale data. If `FAIL_ALERT_CYCLES` cycles in a row fail, one Telegram notification with the last error is sent, and another one when the synchronization recovers. If the vault stays locked for `LOCKED_EXIT_CYCLES` cycles, the script stops (a supervisor such as `restart: always` starts it again and it logs in again). The same notification is not repeated more often than once per `NOTIFICAION['THROTTLE']` seconds, also across restarts.

***Command line options***

`php run.php` synchronizes in a loop. `--once` runs one cycle and exits (exit code 1 if there were errors), `--dry-run` runs one cycle and only logs what would be created, updated or deleted, `--rebuild-mapping` is described above, `--verbose` logs everything, `--help` lists the options.

***Docker***

docker/docker-compose.yml builds the image from this repository (the code is not downloaded from GitHub). In `config.php` use `$config['BWCLI'] = '/usr/local/bin/bw';` and `$config['BW_LOCAL']['APPDATA_DIR'] = '/bwlocal';` (`/bwremote` for the second server), so that the login of `bw` survives the re-creation of the container. The version of the Bitwarden CLI can be pinned with the build arguments `BW_VERSION` and `BW_SHA256`, the Redis image with `REDIS_TAG`. The container is "healthy" while a cycle without errors has finished recently.

***Tests***

`php tests/run.php` (or `composer test`) runs the tests of the synchronization logic, they need neither Redis nor Bitwarden.

***Recommendations for creating API user***

It is recommended to create a separate user to work with the API. The minimum user role in the organization is "Manager", the option "Garant access to all current and future collections" should be checked.

--

Host this application on [SERVERio](https://serverio.io/vps_docker)
