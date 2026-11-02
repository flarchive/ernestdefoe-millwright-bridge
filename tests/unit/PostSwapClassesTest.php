<?php

namespace ErnestDefoe\MillwrightBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 🚨 After the swap, only classes loaded before it exist: vendor/ is Flarum 2's
 * and Bridge is not in it. An upgrade started from the web loads nothing but
 * the controller, Bridge, Stager and what Upgrader preloads, so every class the
 * post-swap code names must be in that set. Preflight was not, and it killed
 * the migrate step on Flarum-in-a-box.
 */
class PostSwapClassesTest extends TestCase
{
    private const AFTER_SWAP = ['Upgrader', 'Restorer', 'Swapper', 'Database', 'Caches', 'Php', 'Updater', 'Health', 'Compatibility', 'Site', 'Journal'];

    public function test_post_swap_code_names_only_preloaded_classes(): void
    {
        $engine = dirname(__DIR__, 2).'/src/Engine';
        $loaded = array_merge(self::AFTER_SWAP, ['Stager']);   // Bridge::upgrade checks staged() first

        foreach (self::AFTER_SWAP as $class) {
            // Code only: a comment may name a class without loading it.
            $code = implode('', array_map(
                fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
                token_get_all((string) file_get_contents($engine.'/'.$class.'.php'))
            ));

            preg_match_all('/\b([A-Z][A-Za-z]+)::/', $code, $m);

            foreach (array_unique($m[1]) as $used) {
                if (in_array($used, ['self', 'static', 'PDO', 'Semver'], true) || ! is_file($engine.'/'.$used.'.php')) {
                    continue;
                }

                $this->assertContains($used, $loaded, "$class uses $used after the swap, but $used is never loaded before it");
            }
        }

        $upgrader = (string) file_get_contents($engine.'/Upgrader.php');

        foreach (self::AFTER_SWAP as $class) {
            if (! in_array($class, ['Upgrader', 'Site', 'Journal'], true)) {
                $this->assertStringContainsString($class.'::class', $upgrader, "Upgrader::preload() must load $class");
            }
        }
    }
}
