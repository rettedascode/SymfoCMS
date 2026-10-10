# Setting up a website in the browser

A new installation greets its first visitor with a setup wizard. It replaces the command-line steps for creating the database and the first administrator.

## Before you start

Put the files on the server, point the web server to the `public/` folder and install the PHP packages:

```bash
composer install --no-dev --optimize-autoloader
php bin/console asset-map:compile
```

Then open the website. Every address leads to `/install` until the setup is complete.

## The three steps

1. **Server** – checks the PHP version, the required PHP extensions and that `var/` and `public/uploads/` are writable. Points marked in red have to be fixed before you can continue.
2. **Database** – the first choice, a file on the server (SQLite), needs no settings and is fine for small websites. For MySQL/MariaDB or PostgreSQL you enter server, database name, user and password. The connection is tried out before it is stored in `.env.local`.
3. **Website** – the name of the website, its language, and name, email address and password of the first administrator.

After the last step the tables are created, a first homepage is added and you are logged in to the admin area.

## Good to know

- **The wizard closes itself for good.** As soon as the website has a user, every address of the wizard answers "not found". The file `var/installed.lock` only saves a database query; deleting it does not reopen the wizard.
- **Finish the setup right after uploading the files.** Until then, anybody who opens the website can run the wizard.
- **Existing websites are not affected.** A website that already has users – set up on the command line or with an earlier version – never shows the wizard.
- **Server-managed configuration is respected.** When `DATABASE_URL` is a real environment variable or a compiled `.env.local.php` exists, the wizard shows the connection, tests it and uses it unchanged.
- **If `.env.local` cannot be written**, the wizard shows the line to put into the file by hand.
- **To switch the wizard off entirely**, set `APP_INSTALLER=0` in the environment. The command-line way keeps working:

  ```bash
  php bin/console doctrine:migrations:migrate -n
  php bin/console app:create-admin you@example.com
  ```
