<?php

declare(strict_types=1);

namespace App\Shop\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Image;

/**
 * Local storage of the images uploaded by producers (shop logo, product photos).
 *
 * Stored paths are relative to the public directory ("uploads/products/12/3f9a….jpg"),
 * so templates display them with `asset(product.photoPath)`.
 */
final readonly class ImageStorage
{
    public const string MAX_SIZE = '5M';

    /** Accepted MIME types (detected from the content) and the extension each one is stored with. */
    public const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const string ROOT = 'uploads';

    private Filesystem $filesystem;

    public function __construct(
        #[Autowire(param: 'app.uploads.public_dir')]
        private string $publicDir,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * The validation constraint every image upload field must carry.
     */
    public static function constraint(): Image
    {
        return new Image(
            maxSize: self::MAX_SIZE,
            mimeTypes: array_keys(self::EXTENSIONS),
            maxSizeMessage: 'Cette image est trop lourde ({{ size }} {{ suffix }}). Maximum : {{ limit }} {{ suffix }}.',
            mimeTypesMessage: 'Choisis une image JPEG, PNG ou WebP.',
        );
    }

    /**
     * Moves the uploaded image under "uploads/<folder>/" with a random name.
     *
     * @param string $folder sub-folder, e.g. "products/12"
     *
     * @return string the path to persist, relative to the public directory
     *
     * @throws \InvalidArgumentException when the file is not an accepted image
     */
    public function store(UploadedFile $file, string $folder): string
    {
        $extension = self::EXTENSIONS[$file->getMimeType() ?? ''] ?? null;
        if (null === $extension) {
            throw new \InvalidArgumentException('Only JPEG, PNG and WebP images can be stored.');
        }

        if (1 !== preg_match('#^[a-z0-9]+(?:/[a-z0-9]+)*$#', $folder)) {
            throw new \InvalidArgumentException(\sprintf('Invalid upload folder "%s".', $folder));
        }

        $relativeDir = self::ROOT.'/'.$folder;
        $name = bin2hex(random_bytes(16)).'.'.$extension;
        $file->move($this->publicDir.'/'.$relativeDir, $name);

        return $relativeDir.'/'.$name;
    }

    /**
     * Deletes a previously stored image. Paths outside the upload directory are ignored.
     */
    public function remove(?string $path): void
    {
        if (null === $path || !str_starts_with($path, self::ROOT.'/') || str_contains($path, '..')) {
            return;
        }

        $this->filesystem->remove($this->publicDir.'/'.$path);
    }
}
