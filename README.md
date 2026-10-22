## Bitwarden/Vaultwarden synchronization tool

Tool for synchronizing organization passwords between Bitwarden/Vaultwarden servers

## Requirements:

 - [Bitwarden CLI Tool](https://bitwarden.com/help/cli/) (2024.6.0 or newer)
 - Redis
 - PHP 8.0+ (recommended 8.3.6+)
 - PHP Extension: redis
 - PHP Extension: sockets
 - PHP Extension: pcntl
 - PHP Library: [php-shellcommand](https://github.com/mikehaertl/php-shellcommand)

## What you need to know about how the tool works

***Redis database*** 

The redis database stores references to UUID items on both servers (since it is impossible to make UUIDs the same on both servers). In the case of a lost database, you need to start the synchronization over, this means that one of the servers must be the source server and the other the destination server, and also the **destination server orginization must not contain any items in the organization**.

The stored references are the only thing that prevents duplicates, so keep them safe. The docker-compose.yml starts Redis with `--save 60 1` (a snapshot every minute if anything changed), the default Redis policy may lose up to an hour of changes after a crash. For stronger durability enable AOF, but never just add `--appendonly yes` to an existing installation: Redis would load the (still empty) append only file instead of `dump.rdb` and the references would be lost. Enable it on the running server first (`redis-cli config set appendonly yes`), wait until `INFO persistence` shows that the rewrite is finished and only then add the option to the command.

***Delete Items***

The only way to delete an item on both servers is to add "[DELETE]" to the name. Classic item deletion will not produce any result. This is to avoid loss of important data during the user process, especially at the early stage of tool development.

***Synchronized items***

Only "Login" items from the organizations set in `ORGANIZATION_ID` are synchronized. Items of other types (Secure Note, Card, Identity, SSH Key), items of the personal vault and items of other organizations are skipped. Skipped items of unsupported types are reported in the log on every synchronization cycle.

***Log and notifications***

Every line of the log has a time and a level (`LOG_LEVEL`: debug, info, warning, error; warnings and errors go to stderr). Each synchronization cycle ends with one summary line: how many items were created, updated, deleted, skipped and failed in each direction and how many errors happened. Failed API calls are logged with the HTTP status and the message of the server.

A cycle is skipped when `bw sync` fails or the vault is locked, so the tool never works with stale data. If `FAIL_ALERT_CYCLES` cycles in a row fail, one Telegram notification with the last error is sent, and another one when the synchronization recovers. If the vault stays locked for `LOCKED_EXIT_CYCLES` cycles, the script stops (a supervisor such as `restart: always` starts it again and it logs in again). The same notification is not repeated more often than once per `NOTIFICAION['THROTTLE']` seconds, also across restarts.

***Recommendations for creating API user***

It is recommended to create a separate user to work with the API. The minimum user role in the organization is "Manager", the option "Garant access to all current and future collections" should be checked.

--

Host this application on [SERVERio](https://serverio.io/vps_docker)
