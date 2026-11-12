<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use PHPUnit\Framework\Attributes\After;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Sample files for upload tests, and clean-up of what the tests stored
 * (the test environment stores uploads under var/test-public, see config/packages/uploads.yaml).
 */
trait UploadTestTrait
{
    /** A valid 1 × 1 pixel PNG. */
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $temporaryFiles = [];

    #[After]
    protected function removeUploadedFiles(): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove($this->temporaryFiles);
        $filesystem->remove(self::uploadPublicDir());
        $this->temporaryFiles = [];
    }

    private static function uploadPublicDir(): string
    {
        return \dirname(__DIR__, 2).'/var/test-public';
    }

    private static function storedFileExists(?string $path): bool
    {
        return null !== $path && is_file(self::uploadPublicDir().'/'.$path);
    }

    private function attachFile(Form $form, string $field, string $path): void
    {
        $input = $form[$field];
        self::assertInstanceOf(FileFormField::class, $input);
        $input->upload($path);
    }

    private function setCheckbox(Form $form, string $field, bool $checked): void
    {
        $input = $form[$field];
        self::assertInstanceOf(ChoiceFormField::class, $input);
        $checked ? $input->tick() : $input->untick();
    }

    private function pngFile(string $name = 'photo.png'): string
    {
        return $this->temporaryFile($name, base64_decode(self::PNG_BASE64, true));
    }

    /**
     * A real PNG padded beyond the 5 MB limit.
     */
    private function oversizedPngFile(): string
    {
        return $this->temporaryFile('enorme.png', base64_decode(self::PNG_BASE64, true).str_repeat("\0", 6 * 1024 * 1024));
    }

    /**
     * Plain text hidden behind an image extension.
     */
    private function fakeImageFile(): string
    {
        return $this->temporaryFile('photo.jpg', "<?php echo 'not an image';\n");
    }

    private function temporaryFile(string $name, string $content): string
    {
        $directory = sys_get_temp_dir().'/panier-sauvage-tests-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($directory.'/'.$name, $content);
        $this->temporaryFiles[] = $directory;

        return $directory.'/'.$name;
    }
}
