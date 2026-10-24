<?php

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class TemplateTest extends TestCase
{
    public function testAllTemplatesParseWithCurrentTranslationExtension(): void
    {
        $twig = new Environment(new FilesystemLoader(ROOT.'/views'));
        $twig->addExtension(new TranslationExtension(new Translator('en_US')));
        $templates = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT.'/views'));
        $count = 0;
        foreach ($templates as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'html') { continue; }
            $twig->parse($twig->tokenize(new Twig\Source(file_get_contents($file->getPathname()), $file->getFilename())));
            $count++;
        }
        self::assertGreaterThanOrEqual(31, $count);
    }

    public function testNoPageTemplateOverridesTheBaseStylesheetWithoutProvidingOne(): void
    {
        $pages = 0;
        foreach (glob(ROOT.'/views/*.html') as $file) {
            $source = file_get_contents($file);
            if (!str_contains($source, 'extends "base.html"')) { continue; }
            $pages++;
            if (preg_match('/\{% block stylesheet %\}(.*?)\{% endblock/s', $source, $block)) {
                self::assertMatchesRegularExpression('/stylesheet/', $block[1], basename($file).' overrides the stylesheet block without a stylesheet');
            }
            self::assertStringNotContainsString('<style', $source, basename($file).' styles itself inline');
            self::assertDoesNotMatchRegularExpression('/\sstyle="/', $source, basename($file).' uses inline styles');
        }
        self::assertGreaterThanOrEqual(7, $pages);
    }
}
