<?php

namespace App\Command;

use App\PackagistExtension;
use Bolt\Configuration\Config;
use Bolt\Extension\ExtensionRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ListCommand extends Command
{
    protected static $defaultName = 'app:list';

    /** @var SymfonyStyle */
    private $io;

    /** @var ExtensionRegistry */
    private $extensionRegistry;

    public function __construct(ExtensionRegistry $extensionRegistry, Config $config)
    {
        $this->extensionRegistry = $extensionRegistry;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Fetch List of extensions and themes from Packagist')
            ->addArgument('type', InputArgument::OPTIONAL, 'The type of packages to fetch (\'extension\' or \'theme\').')
        ;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        // Ask for the type if it's not defined
        $type = $input->getArgument('type');
        if ($type !== null) {
            $this->io->text(' > <info>Type</info>: ' . $type);
        } else {
            $type = $this->io->choice('Which type to get?', ['extension', 'theme']);
            $input->setArgument('type', $type);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $packagist = $this->extensionRegistry->getExtension(PackagistExtension::class);

        $packagist->fetchPackages($input->getArgument('type'));

        $io->success('Done.');
    }
}
