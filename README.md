# Millwright Bridge

Upgrade a **Flarum 1.8** forum to **Flarum 2.0** from the admin page, or with one command. It works on shared hosting too.

Nothing changes until the last step, and if that step fails, the forum is put back exactly as it was.

## What it does

1. **Check.** Confirms PHP 8.3+, permissions, disk space and memory. It then looks up every installed extension on Packagist:
   - extensions with a Flarum 2 release are marked ready;
   - extensions without one are named, and need removing first;
   - renamed packages are spotted (for example `v17development/flarum-seo` → `fof/seo`), as are packages Flarum 2 retired.
2. **Build Flarum 2.** Installs the complete Flarum 2 dependency tree into `storage/bridge/stage` with a bundled Composer, while the forum keeps running. If it cannot be resolved, you see why, and nothing has changed.
3. **Upgrade:**
   1. backs up the database and `composer.json`, `composer.lock` and `config.php`;
   2. swaps Flarum 2 into `vendor/` by renaming, so nothing is deleted;
   3. switches `config.php` to the `mariadb` driver where needed;
   4. updates the database, using `php flarum migrate` or, on hosts that forbid processes, Flarum 2's own updater;
   5. keeps every enabled extension enabled;
   6. installs [Millwright](https://github.com/ernestdefoe/millwright) in Bridge's place;
   7. checks that the forum answers.

   **If anything fails, everything is put back:** files, settings and database.

## Install (on Flarum 1.8)

```bash
composer require ernestdefoe/millwright-bridge
php flarum extension:enable ernestdefoe-millwright-bridge
```

Then open **Admin → Millwright Bridge**. You can also do it all from the command line:

```bash
php flarum bridge:upgrade --check   # report only
php flarum bridge:upgrade           # check, build, then ask before swapping
```

> If Composer refuses to install on Flarum 1.8 with a security advisory about `league/flysystem`, that is Flarum 1's own dependency, which current Composer blocks by default. For this one command, add `--no-security-blocking`.

## If an upgrade is interrupted

Every step is recorded before it happens. If the request was killed partway through and the forum is not working, run this with PHP 8.3:

```bash
php storage/bridge/undo.php
```

It needs nothing but PHP, so it works whatever state `vendor/` is in. On a host without SSH, run it once from a cron job.

## Before you start

- Take your host's own backup as well. Bridge keeps its own, but two is better than one.
- PHP **8.3 or newer** is required by Flarum 2. Switch the site's PHP version in your host's control panel first; the check tells you if this is needed.
- Extensions with no Flarum 2 release must be removed first. The check lists them.

## License

MIT
