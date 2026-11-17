<?php

namespace ErnestDefoe\MillwrightBridge\Tests\Unit;

use ErnestDefoe\MillwrightBridge\Bridge;
use Flarum\Foundation\Paths;
use Flarum\Locale\LocaleManager;
use Flarum\Locale\PrefixedYamlFileLoader;
use Flarum\Locale\Translator;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 A console run translated before Flarum 1.8 had loaded any locale, and the
 * empty catalogue it cached showed raw keys across the whole forum.
 */
class LocaleCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/bridge-locale-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/storage/locale', 0777, true);
        mkdir($this->dir.'/public');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function test_an_empty_cached_catalogue_is_rebuilt(): void
    {
        // What the old console run did: translate with nothing loaded, which caches an empty catalogue.
        $this->translator()->trans('core.forum.header.log_in_link');
        $this->assertNotEmpty(glob($this->dir.'/storage/locale/catalogue.*'));

        // The next process loads its locales, but the cached catalogue wins.
        $translator = $this->translator();
        $locales = new LocaleManager($translator, $this->dir.'/storage/locale');
        $locales->addLocale('en', 'English');
        $locales->addTranslations('en', dirname(__DIR__, 2).'/resources/locale/en.yml');
        $key = 'ernestdefoe-millwright-bridge.api.ext_local';
        $this->assertSame($key, $translator->trans($key));

        new Bridge(new Paths(['base' => $this->dir, 'public' => $this->dir.'/public', 'storage' => $this->dir.'/storage', 'vendor' => $this->dir.'/vendor']), $locales, $translator);

        $this->assertStringStartsWith('{name} is a local package', $translator->trans($key));
    }

    private function translator(): Translator
    {
        $translator = new Translator('en', null, $this->dir.'/storage/locale', false);
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('prefixed_yaml', new PrefixedYamlFileLoader());

        return $translator;
    }
}
