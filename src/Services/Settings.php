<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Models\PbSetting;
use Andre\AiPageBuilder\Support\Schema as PbSchema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Persistent, app-wide configuration for the builder itself — the home page,
 * the email/SMTP transport, and anything else the admin tunes from the
 * Settings screen. Backed by the `page_builder_settings` key/value table.
 *
 * Values round-trip as native PHP through JSON. Sensitive values (SMTP
 * password) use {@see setEncrypted}/{@see getEncrypted} so they are never
 * stored in plaintext.
 *
 * Resolution is best-effort: if the table has not been migrated yet (fresh
 * install) every read returns its default and every write is a silent no-op,
 * so reading a setting during boot / routing can never break the app.
 */
class Settings
{
    /** @var array<string,mixed>|null Request-memoised key => decoded value. */
    private ?array $cache = null;

    /**
     * Read a setting, decoded to its native PHP value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->load();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * @return array<string,mixed> All settings, keyed, decoded.
     */
    public function all(): array
    {
        return $this->load();
    }

    /**
     * Write a setting (JSON-encoded). Pass null to leave it set-to-null; use
     * forget() to remove it entirely.
     */
    public function set(string $key, mixed $value): void
    {
        if (! $this->tableExists()) {
            return;
        }

        PbSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => json_encode($value)],
        );

        $this->cache = null;
    }

    /**
     * Persist an encrypted value (e.g. an SMTP password). Stored as a tagged
     * envelope so reads know to decrypt; getEncrypted() reverses it.
     */
    public function setEncrypted(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            $this->set($key, null);

            return;
        }

        $this->set($key, ['__enc' => Crypt::encryptString($value)]);
    }

    /**
     * Read + decrypt a value written with setEncrypted(). Returns the default
     * if unset or if decryption fails (e.g. the app key rotated).
     */
    public function getEncrypted(string $key, ?string $default = null): ?string
    {
        $raw = $this->get($key);

        if (is_array($raw) && isset($raw['__enc']) && is_string($raw['__enc'])) {
            try {
                return Crypt::decryptString($raw['__enc']);
            } catch (Throwable) {
                return $default;
            }
        }

        return is_string($raw) ? $raw : $default;
    }

    /**
     * True when a value (other than null) is stored for the key — useful to
     * tell "configured but empty" from "never set" for secrets.
     */
    public function has(string $key): bool
    {
        $all = $this->load();

        return array_key_exists($key, $all) && $all[$key] !== null;
    }

    public function forget(string $key): void
    {
        if (! $this->tableExists()) {
            return;
        }

        PbSetting::query()->where('key', $key)->delete();
        $this->cache = null;
    }

    /**
     * Drop the request-memoised copy (mainly for tests).
     */
    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * @return array<string,mixed>
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (! $this->tableExists()) {
            return $this->cache = [];
        }

        $out = [];
        foreach (PbSetting::query()->get(['key', 'value']) as $row) {
            /** @var PbSetting $row */
            $out[$row->key] = $this->decode($row->value);
        }

        return $this->cache = $out;
    }

    private function decode(?string $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        // Tolerate legacy / non-JSON values by returning them verbatim.
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    private function tableExists(): bool
    {
        try {
            return Schema::connection(PbSchema::connection())->hasTable(PbSchema::table('settings'));
        } catch (Throwable) {
            return false;
        }
    }
}
