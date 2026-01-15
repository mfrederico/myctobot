<?php
/**
 * SSH Key Service
 * Generates and manages SSH keys for workstation authentication
 */

namespace app\services;

use \app\Bean;
use \Flight;

class SSHKeyService {

    private static $supportedTypes = ['ecdsa', 'ed25519', 'rsa'];

    /**
     * Generate a new SSH key pair
     *
     * @param string $type Key type: ecdsa, ed25519, or rsa
     * @param string $comment Comment for the key (usually email)
     * @return array ['public_key' => string, 'private_key' => string, 'fingerprint' => string]
     */
    public static function generateKeyPair(string $type = 'ecdsa', string $comment = ''): array {
        if (!in_array($type, self::$supportedTypes)) {
            throw new \InvalidArgumentException("Unsupported key type: $type");
        }

        // Create temp files for key generation
        $tempDir = sys_get_temp_dir();
        $keyFile = $tempDir . '/sshkey_' . uniqid() . '_' . time();
        $pubFile = $keyFile . '.pub';

        try {
            // Build ssh-keygen command based on type
            $bits = '';
            $keyTypeArg = $type;

            switch ($type) {
                case 'ecdsa':
                    $keyTypeArg = 'ecdsa';
                    $bits = '-b 521'; // ECDSA supports 256, 384, or 521 bits
                    break;
                case 'ed25519':
                    $keyTypeArg = 'ed25519';
                    // ED25519 doesn't need bits specification
                    break;
                case 'rsa':
                    $keyTypeArg = 'rsa';
                    $bits = '-b 4096';
                    break;
            }

            $commentArg = $comment ? "-C " . escapeshellarg($comment) : "-C ''";

            // Generate key with no passphrase (-N '')
            $cmd = sprintf(
                'ssh-keygen -t %s %s -f %s -N "" %s 2>&1',
                escapeshellarg($keyTypeArg),
                $bits,
                escapeshellarg($keyFile),
                $commentArg
            );

            exec($cmd, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \RuntimeException("ssh-keygen failed: " . implode("\n", $output));
            }

            // Read generated keys
            if (!file_exists($keyFile) || !file_exists($pubFile)) {
                throw new \RuntimeException("Key files were not created");
            }

            $privateKey = file_get_contents($keyFile);
            $publicKey = trim(file_get_contents($pubFile));

            // Get fingerprint
            $fingerprint = self::getFingerprint($pubFile);

            return [
                'public_key' => $publicKey,
                'private_key' => $privateKey,
                'fingerprint' => $fingerprint,
                'type' => $type
            ];

        } finally {
            // Clean up temp files
            @unlink($keyFile);
            @unlink($pubFile);
        }
    }

    /**
     * Get fingerprint of a public key
     */
    private static function getFingerprint(string $pubKeyFile): string {
        $cmd = sprintf('ssh-keygen -lf %s 2>&1', escapeshellarg($pubKeyFile));
        exec($cmd, $output, $returnCode);

        if ($returnCode === 0 && !empty($output[0])) {
            // Output format: "521 SHA256:xxxx comment (ECDSA)"
            // Extract the SHA256:xxxx part
            if (preg_match('/SHA256:[^\s]+/', $output[0], $matches)) {
                return $matches[0];
            }
        }

        return 'Unknown';
    }

    /**
     * Encrypt private key for storage
     */
    public static function encryptPrivateKey(string $privateKey): string {
        $encryptionKey = Flight::get('app.encryption_key') ?? Flight::get('app.secret') ?? 'default-key';

        // Use OpenSSL AES-256-CBC encryption
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($privateKey, 'aes-256-cbc', $encryptionKey, 0, $iv);

        // Prepend IV for decryption
        return base64_encode($iv . base64_decode($encrypted));
    }

    /**
     * Decrypt private key from storage
     */
    public static function decryptPrivateKey(string $encryptedKey): string {
        $encryptionKey = Flight::get('app.encryption_key') ?? Flight::get('app.secret') ?? 'default-key';

        $data = base64_decode($encryptedKey);
        $iv = substr($data, 0, 16);
        $encrypted = base64_encode(substr($data, 16));

        return openssl_decrypt($encrypted, 'aes-256-cbc', $encryptionKey, 0, $iv);
    }

    /**
     * Create a new SSH key record in database
     */
    public static function createKey(int $memberId, string $name, string $type = 'ecdsa', string $description = '', string $comment = ''): array {
        // Generate key pair
        $keyPair = self::generateKeyPair($type, $comment);

        // Encrypt private key before storing
        $encryptedPrivateKey = self::encryptPrivateKey($keyPair['private_key']);

        // Store in database
        $bean = Bean::dispense('sshkeys');
        $bean->member_id = $memberId;
        $bean->name = $name;
        $bean->description = $description;
        $bean->key_type = $type;
        $bean->public_key = $keyPair['public_key'];
        $bean->private_key_encrypted = $encryptedPrivateKey;
        $bean->fingerprint = $keyPair['fingerprint'];
        $bean->is_shared = 0;
        $bean->created_at = date('Y-m-d H:i:s');
        Bean::store($bean);

        return [
            'id' => $bean->id,
            'name' => $name,
            'type' => $type,
            'public_key' => $keyPair['public_key'],
            'private_key' => $keyPair['private_key'], // Return once for user to copy
            'fingerprint' => $keyPair['fingerprint'],
            'created_at' => $bean->created_at
        ];
    }

    /**
     * Get all SSH keys for display (without private keys)
     */
    public static function getKeys(): array {
        $beans = Bean::findAll('sshkeys', ' ORDER BY created_at DESC ');
        $keys = [];

        foreach ($beans as $bean) {
            $keys[] = [
                'id' => $bean->id,
                'name' => $bean->name,
                'description' => $bean->description,
                'key_type' => $bean->key_type,
                'public_key' => $bean->public_key,
                'fingerprint' => $bean->fingerprint,
                'created_at' => $bean->created_at,
                'last_used_at' => $bean->last_used_at
            ];
        }

        return $keys;
    }

    /**
     * Get a single key by ID (with decrypted private key)
     */
    public static function getKey(int $keyId, bool $includePrivate = false): ?array {
        $bean = Bean::load('sshkeys', $keyId);

        if (!$bean || !$bean->id) {
            return null;
        }

        $key = [
            'id' => $bean->id,
            'name' => $bean->name,
            'description' => $bean->description,
            'key_type' => $bean->key_type,
            'public_key' => $bean->public_key,
            'fingerprint' => $bean->fingerprint,
            'created_at' => $bean->created_at,
            'last_used_at' => $bean->last_used_at
        ];

        if ($includePrivate) {
            $key['private_key'] = self::decryptPrivateKey($bean->private_key_encrypted);
        }

        return $key;
    }

    /**
     * Delete an SSH key
     */
    public static function deleteKey(int $keyId): bool {
        $bean = Bean::load('sshkeys', $keyId);

        if (!$bean || !$bean->id) {
            return false;
        }

        Bean::trash($bean);
        return true;
    }

    /**
     * Update key last_used_at timestamp
     */
    public static function markUsed(int $keyId): void {
        $bean = Bean::load('sshkeys', $keyId);
        if ($bean && $bean->id) {
            $bean->last_used_at = date('Y-m-d H:i:s');
            Bean::store($bean);
        }
    }

    /**
     * Get supported key types
     */
    public static function getSupportedTypes(): array {
        return [
            'ecdsa' => [
                'name' => 'ECDSA',
                'description' => 'Elliptic Curve DSA - Good balance of security and compatibility',
                'recommended' => true
            ],
            'ed25519' => [
                'name' => 'ED25519',
                'description' => 'Edwards-curve DSA - Modern, fast, highly secure',
                'recommended' => false
            ],
            'rsa' => [
                'name' => 'RSA',
                'description' => 'RSA 4096-bit - Maximum compatibility with older systems',
                'recommended' => false
            ]
        ];
    }
}
