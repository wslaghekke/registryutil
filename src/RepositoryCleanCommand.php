<?php

namespace App;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class RepositoryCleanCommand extends Command
{
    /** @var CacheInterface */
    private $cache;

    public function __construct(CacheInterface $cache)
    {
        parent::__construct(null);
        $this->cache = $cache;
    }


    protected function configure()
    {
        $this->setName('repository:clean')
            ->addArgument('registry', InputArgument::REQUIRED, 'Name of registry')
            ->addArgument('repository', InputArgument::REQUIRED, 'Name of repository')
            ->addOption('cacheExpireSeconds', 'c', InputOption::VALUE_OPTIONAL, 'How long to cache azure responses', 86400);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $registry = $input->getArgument('registry');
        $repository = $input->getArgument('repository');

        $manifestData = $this->getManifests($output, $registry, $repository, $input->getOption('cacheExpireSeconds'));
        foreach ($manifestData as $manifest) {
            if (\count($manifest['tags']) === 0) {
                $output->writeln('Orphaned manifest: ' . $manifest['digest']);
            } else {
                $output->writeln('Tagged manifest: ' . $manifest['digest'] . ' tags: ' . implode(', ', $manifest['tags']));
            }
        }
    }

    /**
     * @param OutputInterface $output
     * @param $registry
     * @param $repository
     * @param $ttl
     * @return array
     */
    protected function getManifests(OutputInterface $output, string $registry, string $repository, int $ttl): array
    {
        $repositorySlug = str_replace(['{', '}', '(', ')', '/', '\\', '@', ':'], '_', $repository);
        $cacheKey = "show-manifest.$registry.$repositorySlug";
        if (null !== ($cachedData = $this->cache->get($cacheKey))) {
            return $cachedData;
        }

        $manifestProcess = new Process("az acr repository show-manifests --name $registry --repository $repository");
        $manifestProcess->setTimeout(null);
        $manifestProcess->mustRun(function ($type, $buffer) use ($output) {
            if (Process::ERR === $type) {
                $output->write($buffer);
            }
        });
        $manifestOutput = $manifestProcess->getOutput();
        $manifestData = json_decode($manifestOutput, true);
        if ($manifestData === null) {
            throw new \RuntimeException('Failed to get manifests: ' . $manifestOutput);
        }
        $this->cache->set($cacheKey, $manifestData, $ttl);

        return $manifestData;
    }

}