<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Streams a file through AES-256-GCM in 1 MiB chunks, so a multi-gigabyte dump
 * never has to fit in memory.
 *
 * Format: "OCBK1" | chunk size (uint32 BE) | then per chunk:
 *         nonce (12 bytes) | final flag (1 byte) | length (uint32 BE) | ciphertext | tag (16 bytes)
 *
 * Every chunk has its own random nonce. The chunk's index and final flag are
 * bound in as additional authenticated data, so chunks cannot be reordered,
 * dropped or cut off after the last one without decryption failing. Any
 * tampering, a wrong key or a truncated file makes `decrypt()` throw. It never
 * returns partial plaintext as if it were whole.
 */
class BackupEncryptor
{
    private const MAGIC = 'OCBK1';

    private const CIPHER = 'aes-256-gcm';

    private const CHUNK_BYTES = 1024 * 1024;

    private readonly string $key;

    public function __construct(?string $configuredKey)
    {
        $key = (string) $configuredKey;
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if (! is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY must be 32 bytes, base64-encoded with a "base64:" prefix. Backups are never written unencrypted.');
        }

        $this->key = $key;
    }

    public function encrypt(string $sourcePath, string $targetPath): void
    {
        $in = fopen($sourcePath, 'rb');
        $out = fopen($targetPath, 'wb');

        try {
            fwrite($out, self::MAGIC.pack('N', self::CHUNK_BYTES));
            $index = 0;
            $chunk = fread($in, self::CHUNK_BYTES);

            do {
                $next = fread($in, self::CHUNK_BYTES);
                $final = $next === '' || $next === false;
                $nonce = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt((string) $chunk, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $this->aad($index, $final));

                if ($cipher === false) {
                    throw new RuntimeException('Backup encryption failed.');
                }

                fwrite($out, $nonce.($final ? "\x01" : "\x00").pack('N', strlen($cipher)).$cipher.$tag);
                $chunk = $next;
                $index++;
            } while (! $final);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public function decrypt(string $sourcePath, string $targetPath): void
    {
        $in = fopen($sourcePath, 'rb');
        $out = fopen($targetPath, 'wb');

        try {
            if (fread($in, 5) !== self::MAGIC) {
                throw new RuntimeException('Not an OralCares backup file.');
            }

            fread($in, 4);
            $index = 0;
            $sawFinal = false;

            while (! $sawFinal) {
                $header = fread($in, 17);

                if ($header === false || strlen($header) !== 17) {
                    throw new RuntimeException('Backup file is truncated.');
                }

                $nonce = substr($header, 0, 12);
                $final = $header[12] === "\x01";
                $length = unpack('N', substr($header, 13, 4))[1];
                $cipher = $length > 0 ? fread($in, $length) : '';
                $tag = fread($in, 16);

                if (strlen((string) $cipher) !== $length || strlen((string) $tag) !== 16) {
                    throw new RuntimeException('Backup file is truncated.');
                }

                $plain = openssl_decrypt((string) $cipher, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $this->aad($index, $final));

                if ($plain === false) {
                    throw new RuntimeException('Backup file failed authentication: wrong key, or it has been modified.');
                }

                fwrite($out, $plain);
                $sawFinal = $final;
                $index++;
            }

            if (fread($in, 1) !== '') {
                throw new RuntimeException('Backup file has data after its final chunk.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    private function aad(int $index, bool $final): string
    {
        return self::MAGIC.pack('J', $index).($final ? "\x01" : "\x00");
    }
}
