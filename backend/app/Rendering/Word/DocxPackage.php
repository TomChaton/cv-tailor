<?php

namespace App\Rendering\Word;

use Throwable;
use ZipArchive;

/**
 * A `.docx` is a zip of XML parts, and this is the zip.
 *
 * `ZipArchive` writes to a path rather than to a string, so the package is
 * assembled in a temporary file and read back. The file is removed whether or
 * not the assembly worked: a half-written package left on disk would be read
 * by nothing and found by somebody.
 *
 * Entries are added in the order they are given, which is why the content
 * types come first — readers look for that part at the front of the archive.
 *
 * Every failure leaves as a `DocxNotWritten`, including one the runtime raised
 * on its own. `revision.docx_failed` carries the failure's class and nothing
 * else, and a line naming the framework's generic error exception would say
 * only that something went wrong somewhere.
 */
final readonly class DocxPackage
{
    /**
     * @param  array<string, string>  $parts  Path inside the package to its XML.
     * @return string The file's bytes.
     */
    public function zip(array $parts): string
    {
        try {
            $file = tempnam(sys_get_temp_dir(), 'cv-docx-');
        } catch (Throwable $failure) {
            throw DocxNotWritten::noTemporaryFile($failure);
        }

        if ($file === false) {
            throw DocxNotWritten::noTemporaryFile();
        }

        try {
            return $this->write($file, $parts);
        } finally {
            // A temporary file nobody removed is worth less than the document
            // that was just assembled in it.
            try {
                unlink($file);
            } catch (Throwable) {
                // Nothing to do about it and nothing that depends on it.
            }
        }
    }

    /**
     * @param  array<string, string>  $parts
     */
    private function write(string $file, array $parts): string
    {
        $package = new ZipArchive;

        if ($package->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw DocxNotWritten::packageNotOpened();
        }

        foreach ($parts as $path => $xml) {
            if (! $package->addFromString($path, $xml)) {
                throw DocxNotWritten::partNotAdded($path);
            }
        }

        if (! $package->close()) {
            throw DocxNotWritten::packageNotClosed();
        }

        try {
            $bytes = file_get_contents($file);
        } catch (Throwable $failure) {
            throw DocxNotWritten::packageNotRead($failure);
        }

        if ($bytes === false) {
            throw DocxNotWritten::packageNotRead();
        }

        return $bytes;
    }
}
