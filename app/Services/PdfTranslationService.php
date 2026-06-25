<?php

namespace App\Services;

use App\Models\PdfTranslationJob;
use App\Support\ExternalServiceException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PdfTranslationService
{
    public function process(PdfTranslationJob $job): PdfTranslationJob
    {
        $job->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        try {
            $sourcePath = Storage::disk('public')->path($job->original_path);
            $text = $this->extractText($sourcePath);

            if (trim($text) === '') {
                throw new ExternalServiceException('Nao foi possivel extrair texto do PDF. Se for PDF escaneado, sera necessario OCR.', 422);
            }

            $pages = $this->splitPages($text);
            $translatedPages = [];
            $spanishBlocks = 0;

            foreach ($pages as $pageNumber => $pageText) {
                $translated = $this->translateSpanishOnly($pageText, $pageNumber + 1);
                if (trim($translated) !== trim($pageText)) {
                    $spanishBlocks++;
                }
                $translatedPages[] = $translated;
            }

            $translatedPath = 'pdf-translations/translated/' . $job->user_id . '/' . Str::uuid() . '.pdf';
            Storage::disk('public')->put($translatedPath, $this->buildSimplePdf($translatedPages));

            $job->update([
                'status' => 'completed',
                'translated_path' => $translatedPath,
                'page_count' => count($pages),
                'spanish_blocks' => $spanishBlocks,
                'processed_at' => now(),
                'meta' => [
                    'mode' => 'text-layer',
                    'note' => 'PDF traduzido gerado a partir do texto extraido. O arquivo original foi mantido intacto.',
                ],
            ]);
        } catch (\Throwable $exception) {
            $job->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'processed_at' => now(),
            ]);
        }

        return $job->fresh() ?: $job;
    }

    private function extractText(string $sourcePath): string
    {
        $binary = trim((string) config('services.pdf_tools.pdftotext_binary', 'pdftotext')) ?: 'pdftotext';
        $command = $this->escapeCommand($binary) . ' -layout -enc UTF-8 ' . escapeshellarg($sourcePath) . ' -';

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new ExternalServiceException('Nao foi possivel iniciar o extrator de PDF.', 500);
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new ExternalServiceException(
                'Falha ao ler PDF. Instale poppler-utils no servidor ou configure PDFTOTEXT_BINARY. ' . trim($stderr),
                422
            );
        }

        return $stdout;
    }

    private function escapeCommand(string $binary): string
    {
        if (str_contains($binary, DIRECTORY_SEPARATOR) || str_contains($binary, ' ')) {
            return escapeshellarg($binary);
        }

        return preg_replace('/[^A-Za-z0-9_.-]/', '', $binary) ?: 'pdftotext';
    }

    /**
     * @return array<int, string>
     */
    private function splitPages(string $text): array
    {
        $pages = preg_split("/\f+/", $text) ?: [$text];
        $pages = array_values(array_map(fn (string $page) => trim($page), $pages));
        $pages = array_values(array_filter($pages, fn (string $page) => $page !== ''));

        return $pages === [] ? [''] : $pages;
    }

    private function translateSpanishOnly(string $text, int $pageNumber): string
    {
        $apiKey = trim((string) config('services.openai.api_key'));
        if ($apiKey === '') {
            throw new ExternalServiceException('OPENAI_API_KEY nao configurada no servidor.', 500);
        }

        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('services.openai.model', 'gpt-5-mini');

        $response = Http::withToken($apiKey)
            ->timeout(120)
            ->acceptJson()
            ->post($baseUrl . '/responses', [
                'model' => $model,
                'input' => [
                    [
                        'role' => 'system',
                        'content' => 'You translate documents. Translate only Spanish text into English. Preserve Portuguese and every other language exactly as written. Preserve line breaks, numbers, codes, names, punctuation, and spacing as much as possible. Return only the final page text.',
                    ],
                    [
                        'role' => 'user',
                        'content' => "Page {$pageNumber}:\n\n" . $text,
                    ],
                ],
            ]);

        if (!$response->successful()) {
            $message = (string) ($response->json('error.message') ?: $response->body());
            throw new ExternalServiceException('Falha na OpenAI: ' . $message, $response->status());
        }

        $payload = $response->json();
        $output = $this->extractOpenAiText(is_array($payload) ? $payload : []);
        if (trim($output) === '') {
            throw new ExternalServiceException('OpenAI nao retornou texto traduzido.', 502);
        }

        return trim($output);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractOpenAiText(array $payload): string
    {
        if (isset($payload['output_text']) && is_string($payload['output_text'])) {
            return $payload['output_text'];
        }

        $chunks = [];
        foreach (($payload['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (($item['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text']) && is_string($content['text'])) {
                    $chunks[] = $content['text'];
                }
            }
        }

        return trim(implode("\n", $chunks));
    }

    /**
     * @param array<int, string> $pages
     */
    private function buildSimplePdf(array $pages): string
    {
        $objects = [];
        $pageRefs = [];
        $fontObjectNumber = 3;

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[$fontObjectNumber] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        $nextObject = 4;
        foreach ($pages as $page) {
            $contentObject = $nextObject++;
            $pageObject = $nextObject++;
            $objects[$contentObject] = $this->pdfStream($this->pageContent($page));
            $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $contentObject . ' 0 R >>';
            $pageRefs[] = $pageObject . ' 0 R';
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageRefs) . '] /Count ' . count($pageRefs) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (max(array_keys($objects)) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= max(array_keys($objects)); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . (max(array_keys($objects)) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    private function pdfStream(string $content): string
    {
        return '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
    }

    private function pageContent(string $text): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $content = "BT\n/F1 9 Tf\n50 792 Td\n12 TL\n";
        $lineCount = 0;
        foreach ($lines as $line) {
            foreach ($this->wrapLine($line, 95) as $wrapped) {
                if ($lineCount >= 62) {
                    break 2;
                }
                $content .= '(' . $this->escapePdfText($wrapped) . ") Tj\nT*\n";
                $lineCount++;
            }
        }

        return $content . "ET";
    }

    /**
     * @return array<int, string>
     */
    private function wrapLine(string $line, int $limit): array
    {
        if (mb_strlen($line) <= $limit) {
            return [$line];
        }

        return explode("\n", wordwrap($line, $limit, "\n", true));
    }

    private function escapePdfText(string $text): string
    {
        $text = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text) ?: $text;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
