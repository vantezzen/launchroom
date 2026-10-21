<?php

declare(strict_types=1);

namespace App\Http\Transfers;

use App\Models\Transfers\DataTransfer;
use App\Services\Transfers\TransferStore;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a ready download chunk by chunk (docs/14 §12.1): each chunk is
 * decrypted in memory, written out and dropped, so memory stays at one
 * chunk whatever the file's size.
 */
final class TransferDownloadResponse
{
    public static function make(DataTransfer $transfer, TransferStore $store): StreamedResponse
    {
        $response = new StreamedResponse(static function () use ($transfer, $store): void {
            for ($i = 0; $i < $transfer->chunk_count; $i++) {
                echo $store->get($transfer, $i);
                flush();
            }
        });

        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Length', (string) $transfer->size_bytes);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $transfer->file_name, self::asciiName($transfer->file_name)));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if ($transfer->digest !== null) {
            $response->headers->set('X-Launchroom-Sha256-Chunks', $transfer->digest);
        }

        return $response;
    }

    private static function asciiName(string $name): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'download';

        return $ascii === '' ? 'download' : $ascii;
    }
}
