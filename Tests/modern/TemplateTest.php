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
        self::assertSame(31, $count);
    }
}
