<?php

declare(strict_types=1);

use App\Services\Crypto\ResponseSignature;
use App\Services\Crypto\Signer;
use Tests\Support\ProtocolSchemas;

/**
 * @return array<string, mixed>
 */
function responseVectors(): array
{
    return json_decode((string) file_get_contents(ProtocolSchemas::root().'/fixtures/signing/response.json'), true, flags: JSON_THROW_ON_ERROR);
}

test('the signer reproduces the valid response vector', function (): void {
    $vector = responseVectors();
    $signer = Signer::fromSeed($vector['kid'], (string) base64_decode($vector['privateKeySeed'], true));
    $valid = collect($vector['cases'])->firstWhere('name', 'valid');

    expect($signer->publicKey())->toBe($vector['publicKey'])
        ->and($signer->signatureHeader($valid['body']))->toBe($valid['header']);
});

test('every response case verifies to its expected result', function (): void {
    $vector = responseVectors();

    foreach ($vector['cases'] as $case) {
        expect(ResponseSignature::verify($case['header'], $case['body'], [$vector['kid'] => $vector['publicKey']]))
            ->toBe($case['valid'], $case['name']);
    }
});

test('signers never leak key material through debug output or serialization', function (): void {
    $signer = Signer::fromSeed('cp-x', random_bytes(32));

    expect($signer->__debugInfo())->toBe(['kid' => 'cp-x', 'secretKey' => '[redacted]'])
        ->and(fn (): string => serialize($signer))->toThrow(LogicException::class);
});
