# Millwright Bridge: Flarum 2 release

**You made it to Flarum 2.**

Millwright Bridge upgrades a forum from Flarum 1.8 to 2.0. On Flarum 2 its job is done: [Millwright](https://github.com/ernestdefoe/millwright), installed by the upgrade, keeps the forum up to date from here.

This release does nothing. Disable it and remove it:

```bash
composer remove ernestdefoe/millwright-bridge
```

It exists so that tools checking a Flarum 1.8 forum for Flarum 2 versions of its extensions find one, so Bridge itself is never what stands between a forum and its upgrade.

For the upgrader, see the [`main` branch](https://github.com/ernestdefoe/millwright-bridge/tree/main) (0.x releases, Flarum 1.8).
