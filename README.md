## Bitwarden/Vaultwarden synchronization tool

Tool for synchronizing organization passwords between Bitwarden/Vaultwarden servers

**Warning!** Very raw code. Use only at your own risk

## Requirements:

 - [Bitwarden CLI Tool](https://bitwarden.com/help/cli/) (2024.6.0 or newer)
 - Redis
 - PHP 8.0+ (recommended 8.3.6+)
 - PHP Extension: redis
 - PHP Extension: pcntl
 - PHP Library: [php-shellcommand](https://github.com/mikehaertl/php-shellcommand)

## What you need to know about how the tool works

***Redis database*** 

The redis database stores references to UUID items on both servers (since it is impossible to make UUIDs the same on both servers). In the case of a lost database, you need to start the synchronization over, this means that one of the servers must be the source server and the other the destination server, and also the **destination server orginization must not contain any items in the organization**.

***Delete Items***

The only way to delete an item on both servers is to add "[DELETE]" to the name. Classic item deletion will not produce any result. This is to avoid loss of important data during the user process, especially at the early stage of tool development.

***Recommendations for creating API user***

It is recommended to create a separate user to work with the API. The minimum user role in the organization is "Manager", the option "Garant access to all current and future collections" should be checked.

 
