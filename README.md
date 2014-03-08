sitesync
========

PHP CLI script to synchronize files and databases to and from a remote server.

File sync uses rsync.

DB sync uses mysql/mysqldump over ssh.

### Warning
This script has the capacity to delete or overwrite your files and wipe your databases very easily.

The default modes of operation perform dry runs, but in any case, make sure you test well and have backups.

### Requirements:
* PHP CLI >= 5.3.0
* ssh, mysql, mysqldump, bzip2, bunzip2 executables available in your path.

### Recommended setup:
* Clone the repository.
* Create a symlink to sitesync.php at ~/bin/sitesync (or otherwise in your $PATH).
* Copy sitesync-aliases.php.example to ~/.sitesync-aliases.php and customize. This file might end up having passwords in it so make sure the permissions are secure.
* Set up ssh public key authentication with remote servers so you don't have to use passwords.

### Pro Tip
At your own risk, add this to an alias to remove the filesync confirmation prompt:
```PHP
'filesync_no_confirm' => true,
```
