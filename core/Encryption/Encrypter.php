<?php

declare(strict_types=1);

namespace Kayra\Encryption;

use SensitiveParameter;

/**
 * Authenticated symmetric encryption, and message signing.
 *
 * AES-256-GCM is used rather than CBC+HMAC: it is authenticated by
 * construction, so there is no encrypt-then-MAC ordering to get wrong and no
 * separate MAC key to manage. Every ciphertext carries a random 96-bit nonce
 * and a 128-bit authentication tag, so tampering is detected on decrypt rather
 * than surfacing as corrupt plaintext.
 *
 * The key comes from APP_KEY. It is 32 raw bytes, normally stored base64-encoded.
 */
final class Encrypter
{
    private const CIPHER = 'aes-256-gcm';
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    /** Bumped if the payload format ever changes, so old values fail loudly. */
    private const VERSION = 'k1';

    public function __construct(
        #[SensitiveParameter] private readonly string $key,
    ) {
        if (strlen($this->key) !== 32) {
            throw new EncryptionException(
                'The encryption key must be exactly 32 bytes. Run `kayra key:generate`.',
            );
        }
    }

    /**
     * Build from a configured value, decoding the `base64:` form.
     */
    public static function fromKey(#[SensitiveParameter] string $key): self
    {
        if ($key === '') {
            throw new EncryptionException('No APP_KEY is set. Run `kayra key:generate`.');
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded === false) {
                throw new EncryptionException('APP_KEY is not valid base64.');
            }

            $key = $decoded;
        }

        return new self($key);
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * Encrypt a value.
     *
     * @param bool $serialize Serialise non-string values. Only ever unserialise
     *                        data this application encrypted — see decrypt().
     */
    public function encrypt(#[SensitiveParameter] mixed $value, bool $serialize = true): string
    {
        $plaintext = $serialize ? serialize($value) : (string) $value;

        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::VERSION,          // version travels as additional authenticated data
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new EncryptionException('Encryption failed.');
        }

        return self::VERSION . '.' . self::base64UrlEncode($nonce . $tag . $ciphertext);
    }

    /**
     * Decrypt a value produced by {@see encrypt()}.
     *
     * Throws on any tampering: the GCM tag is verified before the plaintext is
     * returned, so a modified payload can never reach application code.
     */
    public function decrypt(string $payload, bool $unserialize = true): mixed
    {
        $separator = strpos($payload, '.');

        if ($separator === false || substr($payload, 0, $separator) !== self::VERSION) {
            throw new EncryptionException('Malformed or unsupported ciphertext.');
        }

        $raw = self::base64UrlDecode(substr($payload, $separator + 1));

        if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new EncryptionException('Malformed ciphertext.');
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $tag = substr($raw, self::NONCE_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::NONCE_BYTES + self::TAG_BYTES);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::VERSION,
        );

        if ($plaintext === false) {
            throw new EncryptionException('The payload is invalid or has been tampered with.');
        }

        if (!$unserialize) {
            return $plaintext;
        }

        // Only values this application encrypted reach here — the GCM tag has
        // already proven authenticity — so unserialize() is not an injection
        // vector. allowed_classes stays false anyway: session payloads should
        // not be able to instantiate arbitrary classes.
        return unserialize($plaintext, ['allowed_classes' => false]);
    }

    /**
     * A keyed signature for a message, for tamper-evident but readable values
     * such as signed URLs.
     */
    public function sign(string $message): string
    {
        return hash_hmac('sha256', $message, $this->key);
    }

    /**
     * Verify a signature in constant time.
     */
    public function verify(string $message, string $signature): bool
    {
        return hash_equals($this->sign($message), $signature);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
