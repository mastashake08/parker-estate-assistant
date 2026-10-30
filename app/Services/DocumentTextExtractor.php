<?php

namespace App\Services;

use DOMDocument;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

/**
 * Reads the text out of the document types the assistant can analyze.
 */
class DocumentTextExtractor
{
    /**
     * The file extensions that can be read.
     */
    public const EXTENSIONS = ['pdf', 'docx', 'txt', 'md', 'csv'];

    /**
     * Read the text from the file at the given path.
     *
     * @throws RuntimeException When the file cannot be read as its type.
     */
    public function extract(string $path, string $extension): string
    {
        try {
            $text = match (strtolower($extension)) {
                'pdf' => (new PdfParser)->parseFile($path)->getText(),
                'docx' => $this->wordText($path),
                default => (string) file_get_contents($path),
            };
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        return $this->clean($text);
    }

    /**
     * Read the paragraphs of a Word document, one per line.
     */
    protected function wordText(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Not a Word document.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException('Not a Word document.');
        }

        $document = new DOMDocument;
        $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        $paragraphs = [];

        foreach ($document->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'p') as $paragraph) {
            $paragraphs[] = $paragraph->textContent;
        }

        return implode("\n", $paragraphs);
    }

    /**
     * Make the text valid UTF-8 with tidy line breaks.
     */
    protected function clean(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
