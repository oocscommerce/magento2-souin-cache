<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com).
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\SouinCache\Model\Caddyfile;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader;
use OOCS\SouinCache\Helper\Data;

/**
 * Build a Caddyfile from etc/Caddyfile.template and the Souin settings (or the given overrides).
 *
 * Every value lands in the Caddyfile as is, so each one is validated against a strict pattern.
 */
class Generator
{
    public const TEMPLATE = 'Caddyfile.template';

    /**
     * Host name, IPv4/IPv6 address or CIDR
     */
    protected const ADDRESS_PATTERN = '#^[0-9A-Za-z.:\-\[\]/]+$#';

    /**
     * @var Reader
     */
    protected $moduleReader;

    /**
     * @var File
     */
    protected $file;

    /**
     * @var Data
     */
    protected $helper;

    /**
     * @param Reader $moduleReader
     * @param File $file
     * @param Data $helper
     */
    public function __construct(
        Reader $moduleReader,
        File $file,
        Data $helper
    ) {
        $this->moduleReader = $moduleReader;
        $this->file = $file;
        $this->helper = $helper;
    }

    /**
     * @param array $overrides Values replacing the configured ones, keyed as in Data::CADDYFILE_SETTINGS,
     *                         plus "api_path" and "ttl"
     * @return string
     * @throws LocalizedException
     */
    public function generate(array $overrides = []): string
    {
        $settings = array_merge(
            $this->helper->getCaddyfileSettings(),
            ['api_path' => $this->helper->getApiPath(), 'ttl' => (string)$this->helper->getTtl()],
            array_filter($overrides, function ($value) {
                return $value !== null && $value !== '' && $value !== [];
            })
        );

        $variables = $this->getVariables($settings);
        $template = $this->file->fileGetContents(
            $this->moduleReader->getModuleDir(Dir::MODULE_ETC_DIR, 'OOCS_SouinCache') . '/' . self::TEMPLATE
        );

        $search = [];
        foreach (array_keys($variables) as $name) {
            $search[] = '{{ ' . $name . ' }}';
        }

        return str_replace($search, array_values($variables), $template);
    }

    /**
     * Template variables from the settings
     *
     * @param array $settings
     * @return array
     * @throws LocalizedException
     */
    protected function getVariables(array $settings): array
    {
        $apiPath = '/' . trim((string)$settings['api_path'], '/');
        if (!preg_match('#^(/[0-9A-Za-z_\-]+)(/[0-9A-Za-z_\-]+)$#', $apiPath, $apiParts)) {
            throw new LocalizedException(__('The purge API path must have two segments, e.g. /souin-api/souin.'));
        }

        $listen = trim((string)$settings['listen']);
        if (!preg_match('#^[0-9A-Za-z.\-\[\]]*:\d+$#', $listen)) {
            throw new LocalizedException(__('The listen address must look like :80 or 127.0.0.1:6081.'));
        }

        return [
            'api_path' => $apiPath,
            'api_basepath' => $apiParts[1],
            'api_souin_basepath' => $apiParts[2],
            'listen' => $listen,
            'backend_host' => $this->validateAddress((string)$settings['backend_host'], 'backend host'),
            'backend_port' => $this->validateNumber((string)$settings['backend_port'], 'backend port', 1),
            'access_list' => $this->formatAddressList($settings['access_list'], 'purge access list'),
            'trusted_proxies' => $this->formatAddressList($settings['trusted_proxies'], 'trusted proxies'),
            'grace_period' => $this->validateNumber((string)$settings['grace_period'], 'grace period', 0),
            'ttl' => $this->validateNumber((string)$settings['ttl'], 'TTL', 0),
            'storage' => $this->getStorage($settings),
        ];
    }

    /**
     * Redis when a URL is set, else the in-memory Otter storage
     *
     * @param array $settings
     * @return string
     * @throws LocalizedException
     */
    protected function getStorage(array $settings): string
    {
        $redisUrl = trim((string)$settings['redis_url']);
        if ($redisUrl !== '') {
            $options = [
                'InitAddress ' . $this->validateAddress($redisUrl, 'Redis server'),
                'SelectDB ' . $this->validateNumber((string)($settings['redis_db'] ?? '') ?: '4', 'Redis DB', 0),
            ];
            $password = (string)($settings['redis_password'] ?? '');
            if ($password !== '') {
                $options[] = 'Password ' . $this->quote($password, 'Redis password');
            }
            // rueidis PINGs every idle connection each Dialer.KeepAlive (1s by default)
            $options[] = "Dialer {\n\t\t\t\t\tKeepAlive 30s\n\t\t\t\t}";

            return "redis {\n\t\t\tconfiguration {\n\t\t\t\t" . implode("\n\t\t\t\t", $options)
                . "\n\t\t\t}\n\t\t}";
        }

        $size = $this->validateNumber((string)$settings['otter_size'], 'in-memory storage size', 1);
        $redisDb = $this->validateNumber((string)($settings['redis_db'] ?? '') ?: '4', 'Redis DB', 0);

        // Redis alternative, shared by several Caddy nodes: replace the otter block with it
        return "otter {\n\t\t\tconfiguration {\n\t\t\t\tsize " . $size . "\n\t\t\t}\n\t\t}\n"
            . "\t\t# redis {\n\t\t#\tconfiguration {\n\t\t#\t\tInitAddress 127.0.0.1:6379\n"
            . "\t\t#\t\tSelectDB " . $redisDb . "\n\t\t#\t\t# Password `secret`\n"
            . "\t\t#\t\tDialer {\n\t\t#\t\t\tKeepAlive 30s\n\t\t#\t\t}\n\t\t#\t}\n\t\t# }";
    }

    /**
     * Caddyfile backtick token: taken literally, no escape sequences
     *
     * @param string $value
     * @param string $label
     * @return string
     * @throws LocalizedException
     */
    protected function quote(string $value, string $label): string
    {
        if (preg_match('/[`\x00-\x1F\x7F]/', $value)) {
            throw new LocalizedException(__('Invalid %1: backticks and control characters are not allowed.', $label));
        }

        return '`' . $value . '`';
    }

    /**
     * @param string|array $list Comma/space separated string or array
     * @param string $label
     * @return string Space separated
     * @throws LocalizedException
     */
    protected function formatAddressList($list, string $label): string
    {
        if (is_array($list)) {
            $list = implode(',', $list);
        }
        $addresses = array_values(array_filter(preg_split('/[\s,]+/', (string)$list), 'strlen'));
        if (!$addresses) {
            throw new LocalizedException(__('The %1 is empty.', $label));
        }
        foreach ($addresses as $address) {
            $this->validateAddress($address, $label);
        }

        return implode(' ', $addresses);
    }

    /**
     * @param string $value
     * @param string $label
     * @return string
     * @throws LocalizedException
     */
    protected function validateAddress(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === 'private_ranges' || preg_match(self::ADDRESS_PATTERN, $value)) {
            return $value;
        }

        throw new LocalizedException(__('Invalid %1: "%2".', $label, $value));
    }

    /**
     * @param string $value
     * @param string $label
     * @param int $min
     * @return string
     * @throws LocalizedException
     */
    protected function validateNumber(string $value, string $label, int $min): string
    {
        $value = trim($value);
        if (!preg_match('/^\d+$/', $value) || (int)$value < $min) {
            throw new LocalizedException(__('Invalid %1: "%2".', $label, $value));
        }

        return (string)(int)$value;
    }
}
