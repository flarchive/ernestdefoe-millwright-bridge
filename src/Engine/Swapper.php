<?php

namespace ErnestDefoe\MillwrightBridge\Engine;

/**
 * Put one vendor/ in place of another, and back again.
 *
 * Renaming is the fast path: two renames, nothing copied, nothing deleted.
 *
 * 🚨 Some hosts refuse to rename vendor/ at all. In Flarum-in-a-box, and any
 * Docker image with Flarum baked in, vendor/ comes from a read-only image layer
 * on overlayfs, and renaming a directory from a lower layer fails with "Invalid
 * cross-device link" (ClaudiusH, 2026-10-09; the same wall Millwright hit in
 * 1.14.0). There the CONTENTS are swapped instead: Flarum 1's tree is copied
 * aside, vendor/ is emptied, and Flarum 2's entries are moved in one by one
 * (they live in the writable layer, so those moves work).
 *
 * Plain PHP only: this runs after the swap and from undo.php.
 */
final class Swapper
{
    /**
     * @return string 'rename' | 'contents': how it was done, for swapBack()
     */
    /**
     * @param ?callable $beforeContents called before anything in vendor/ is
     *        removed, so the caller can record that the contents swap began:
     *        the undo must know which way to reverse even if this dies halfway
     */
    public static function swapIn(string $vendor, string $staged, string $aside, ?callable $beforeContents = null): string
    {
        if (@rename($vendor, $aside)) {
            if (@rename($staged, $vendor)) {
                return 'rename';
            }

            $why = self::lastError();
            @rename($aside, $vendor);   // as it was

            throw new \RuntimeException('the Flarum 2 build could not be moved into vendor/ ('.$why.').');
        }

        $renameError = self::lastError();

        // Contents instead: copy Flarum 1 aside (nothing destroyed until the copy is complete).
        self::copyTree($vendor, $aside);

        if ($beforeContents !== null) {
            $beforeContents();
        }

        self::emptyDir($vendor);

        if (! self::moveChildren($staged, $vendor)) {
            $why = self::lastError();
            self::emptyDir($vendor);
            self::copyTree($aside, $vendor);

            throw new \RuntimeException('vendor/ could not be renamed ('.$renameError.'), and moving Flarum 2 into it also failed ('.$why.').');
        }

        return 'contents';
    }

    /** @param ?string $returnTo where Flarum 2's build goes back to, so another attempt need not rebuild it */
    public static function swapBack(string $vendor, string $aside, string $mode, ?string $returnTo = null): void
    {
        if ($mode === 'rename') {
            $parkAt = $returnTo !== null && ! file_exists($returnTo) ? $returnTo : $vendor.'-flarum2-'.date('YmdHis');

            if (is_dir($vendor) && ! @rename($vendor, $parkAt)) {
                self::emptyDir($vendor);
                @rmdir($vendor);
            }

            if (! @rename($aside, $vendor)) {
                throw new \RuntimeException('Flarum 1\'s files could not be moved back into vendor/ ('.self::lastError().'). They are in '.$aside.'.');
            }

            return;
        }

        self::emptyDir($vendor);
        self::copyTree($aside, $vendor);
    }

    /** Copy a tree, recreating symlinks rather than following them. */
    public static function copyTree(string $from, string $to): void
    {
        if (! is_dir($to) && ! @mkdir($to, 0775, true)) {
            throw new \RuntimeException('could not create '.$to.' ('.self::lastError().').');
        }

        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $src = $from.'/'.$name;
            $dst = $to.'/'.$name;

            if (is_link($src)) {
                @symlink((string) readlink($src), $dst);
            } elseif (is_dir($src)) {
                self::copyTree($src, $dst);
            } elseif (! @copy($src, $dst)) {
                throw new \RuntimeException('could not copy '.$src.' ('.self::lastError().').');
            }
        }
    }

    /** Remove everything inside $dir, never following a symlink out of it. */
    public static function emptyDir(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir.'/'.$name;

            if (is_dir($path) && ! is_link($path)) {
                self::emptyDir($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    private static function moveChildren(string $from, string $to): bool
    {
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            if (@rename($from.'/'.$name, $to.'/'.$name)) {
                continue;
            }

            // A different filesystem (vendor/ as its own mount or volume): copy instead.
            try {
                $src = $from.'/'.$name;

                if (is_link($src)) {
                    @symlink((string) readlink($src), $to.'/'.$name);
                } elseif (is_dir($src)) {
                    self::copyTree($src, $to.'/'.$name);
                } elseif (! @copy($src, $to.'/'.$name)) {
                    return false;
                }
            } catch (\RuntimeException $e) {
                return false;
            }
        }

        return true;
    }

    private static function lastError(): string
    {
        $e = error_get_last();

        return $e === null ? 'no reason given' : preg_replace('/^rename\([^)]*\): /', '', $e['message']);
    }
}
