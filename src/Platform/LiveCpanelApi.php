<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Platform;

final class LiveCpanelApi implements CpanelApi
{
    private ?object $cpanel;

    public function __construct($cpanel = null)
    {
        $this->cpanel = $cpanel;
    }

    public function available(): bool
    {
        return is_object($this->cpanel)
            && method_exists($this->cpanel, 'api2')
            && method_exists($this->cpanel, 'uapi');
    }

    public function fetchCrontabLines(): ?array
    {
        if (!$this->available()) {
            return null;
        }

        try {
            $response = $this->cpanel->api2('Cron', 'fetchcron', []);
        } catch (\Throwable $error) {
            return null;
        }

        $data = self::extractApi2Data($response);

        return is_array($data) ? $data : null;
    }

    public function documentRootPhpVersions(): array
    {
        if (!$this->available()) {
            return [];
        }

        try {
            $response = $this->cpanel->uapi('LangPHP', 'php_get_vhost_versions', []);
        } catch (\Throwable $error) {
            return [];
        }

        $data = self::extractUapiData($response);

        if (!is_array($data)) {
            return [];
        }

        $versions = [];

        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $documentRoot = (string) ($entry['documentroot'] ?? '');
            $version = (string) ($entry['version'] ?? '');

            if ($documentRoot === '' || $version === '') {
                continue;
            }

            $versions[] = [
                'vhost' => (string) ($entry['vhost'] ?? $entry['account'] ?? ''),
                'documentroot' => rtrim($documentRoot, '/'),
                'version' => $version,
                'source' => (string) ($entry['phpversion_source']['type'] ?? ''),
            ];
        }

        return $versions;
    }

    private static function extractApi2Data($response)
    {
        if (!is_array($response)) {
            return null;
        }

        if (isset($response['cpanelresult']['data'])) {
            return $response['cpanelresult']['data'];
        }

        if (isset($response['data'])) {
            return $response['data'];
        }

        return null;
    }

    private static function extractUapiData($response)
    {
        if (!is_array($response)) {
            return null;
        }

        if (isset($response['cpanelresult']['result']['data'])) {
            return $response['cpanelresult']['result']['data'];
        }

        if (isset($response['cpanelresult']['data'])) {
            return $response['cpanelresult']['data'];
        }

        if (isset($response['result']['data'])) {
            return $response['result']['data'];
        }

        if (isset($response['data'])) {
            return $response['data'];
        }

        return null;
    }
}
