<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com).
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\SouinCache\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File;
use OOCS\SouinCache\Model\Caddyfile\Generator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento souin:caddyfile:generate: Souin counterpart of varnish:vcl:generate.
 *
 * Options override the settings of Stores > Configuration > Advanced > System > Full Page Cache > Souin.
 */
class GenerateCaddyfileCommand extends Command
{
    protected const EXIT_SUCCESS = 0;
    protected const EXIT_FAILURE = 1;

    /**
     * Option name => generator setting
     */
    protected const OPTIONS = [
        'listen' => ['listen', 'Caddyfile site address, e.g. :80'],
        'backend-host' => ['backend_host', 'Web server Caddy forwards to'],
        'backend-port' => ['backend_port', 'Web server port'],
        'access-list' => ['access_list', 'IP/CIDR allowed to purge (repeatable)'],
        'trusted-proxies' => ['trusted_proxies', 'IP/CIDR of trusted proxies, or private_ranges (repeatable)'],
        'grace-period' => ['grace_period', 'Seconds an expired page may still be served'],
        'otter-size' => ['otter_size', 'Maximum entries of the in-memory storage'],
        'redis-url' => ['redis_url', 'host:port of a shared Redis storage'],
        'redis-db' => ['redis_db', 'Redis database number'],
        'redis-password' => ['redis_password', 'Redis password'],
        'api-path' => ['api_path', 'Purge API path, e.g. /souin-api/souin'],
        'ttl' => ['ttl', 'Fallback TTL in seconds'],
    ];

    protected const REPEATABLE = ['access-list', 'trusted-proxies'];

    /**
     * @var Generator
     */
    protected $generator;

    /**
     * @var File
     */
    protected $file;

    /**
     * @param Generator $generator
     * @param File $file
     * @param string|null $name
     */
    public function __construct(
        Generator $generator,
        File $file,
        ?string $name = null
    ) {
        $this->generator = $generator;
        $this->file = $file;
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('souin:caddyfile:generate')
            ->setDescription('Generate the Caddyfile for Souin')
            ->addOption('output-file', null, InputOption::VALUE_REQUIRED, 'Write to this file instead of stdout');
        foreach (self::OPTIONS as $option => list(, $description)) {
            $mode = InputOption::VALUE_REQUIRED;
            if (in_array($option, self::REPEATABLE, true)) {
                $mode |= InputOption::VALUE_IS_ARRAY;
            }
            $this->addOption($option, null, $mode, $description);
        }
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $overrides = [];
        foreach (self::OPTIONS as $option => list($setting)) {
            $overrides[$setting] = $input->getOption($option);
        }

        try {
            $caddyfile = $this->generator->generate($overrides);
            $outputFile = $input->getOption('output-file');
            if ($outputFile) {
                $this->file->filePutContents($outputFile, $caddyfile);
                $output->writeln("<info>Wrote {$outputFile}</info>");
            } else {
                $output->write($caddyfile, false, OutputInterface::OUTPUT_RAW);
            }
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return static::EXIT_FAILURE;
        }

        return static::EXIT_SUCCESS;
    }
}
