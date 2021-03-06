<?php

namespace SensioLabs\DeprecationDetector\Console\Command;

use SensioLabs\DeprecationDetector\EventListener\ProgressListener;
use SensioLabs\DeprecationDetector\Finder\ParsedPhpFileFinder;
use SensioLabs\DeprecationDetector\ProgressEvent;
use SensioLabs\DeprecationDetector\RuleSet\Loader;
use SensioLabs\DeprecationDetector\RuleSet\RuleSet;
use SensioLabs\DeprecationDetector\Violation\Violation;
use SensioLabs\DeprecationDetector\Violation\ViolationChecker\ComposedViolationChecker;
use SensioLabs\DeprecationDetector\Violation\ViolationChecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CheckCommand extends Command
{
    /**
     * @see Command
     */
    protected function configure()
    {
        $this
            ->setName('check')
            ->setDefinition(
                array(
                    new InputArgument('source', InputArgument::OPTIONAL, 'The path to the source files', 'src/'),
                    new InputArgument(
                        'ruleset',
                        InputArgument::OPTIONAL,
                        'The path to the composer.lock file, a rule set or source directory',
                        'composer.lock'
                    ),
                    new InputOption(
                        'container-cache',
                        null,
                        InputOption::VALUE_REQUIRED,
                        'The path to symfony container cache',
                        'app/cache/dev/appDevDebugProjectContainer.xml'
                    ),
                    new InputOption('no-cache', null, InputOption::VALUE_NONE, 'Disable rule set cache'),
                    new InputOption('cache-dir', null, InputOption::VALUE_REQUIRED, 'Cache directory', '.rules/'),
                )
            )
            ->setDescription('Check for deprecated usage')
            ->setHelp(<<<EOF
The <info>%command.name%</info> command tries to find all usages of deprecated features and functions in your application.
The command will generate a rule set for deprecated classes, methods and interfaces based on your libraries, lock file or by loading an existing rule set.
After that the application is checked against this rule set or list of rule sets.

Execute the script with default behavior:

    <info>php %command.full_name%</info>

By default the command checks the folder src/ against the composer.lock, but you can easily specify other folders and files:

    <info>php %command.full_name% </info><comment>path/to/script/ path/to/library/</comment>

    - the first path argument defines the application source to check
    - the second path argument defines the rule set path:

The rule set path can have the following values:

    - path to composer.lock file
    - path to library sources (directory)
    - path to rule set file

After generating a rule set it is cached within the directory .rules, but you can use the caching options to change this behavior:

    - to disable caching use the option <comment>no-cache</comment>:

      <info>php %command.full_name% </info><comment>--no-cache</comment>

    - to change the cache directory use the option <comment>cache-dir</comment>:

      <info>php %command.full_name% </info><comment>--cache-dir=path/to/cache</comment>

To get additional runtime output for information about process progress use the option <comment>verbose</comment>:

    <info>php %command.full_name% </info><comment>--verbose</comment>
EOF
            );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     *
     * @throws \RuntimeException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sourceArg = realpath($input->getArgument('source'));
        $ruleSetArg = realpath($input->getArgument('ruleset'));
        $container = $this->getApplication()->getContainer();

        if (false === $sourceArg || false === $ruleSetArg) {
            throw new \InvalidArgumentException(
                sprintf(
                    '%s argument is invalid: "%s" is not a path.',
                    $sourceArg ? 'Rule set' : 'Source directory',
                    $sourceArg ? $input->getArgument('ruleset') : $input->getArgument('source')
                )
            );
        }

        $symfonyMode = $container['symfony_container_reader']->loadContainer($input->getOption('container-cache'));

        $output->writeln(
            sprintf(
                'Checking your %s for deprecations - this could take a while ...',
                $symfonyMode ? 'symfony application' : 'application'
            )
        );

        if ($input->getOption('no-cache')) {
            $container['ruleset.cache']->disable();
        } else {
            $container['ruleset.cache']->setCacheDir($input->getOption('cache-dir'));
        }

        if ($input->getOption('verbose')) {
            $container['event_dispatcher']->addSubscriber(new ProgressListener($output));
        }

        $ruleSet = $this->loadRuleSet($ruleSetArg);

        if (null === $ruleSet) {
            $output->writeln(sprintf('<error>check aborted - no rule set found for %s</error>', $ruleSetArg));

            return 0;
        }

        $lib = (is_dir($ruleSetArg) ? $ruleSetArg : realpath('vendor')); // TODO: not hard coded
        $container['ancestor_resolver']->setSourcePaths(array($sourceArg, $lib));

        $files = $container['finder.php_usage'];
        $files->in($sourceArg);
        $violations = $this->getViolations($ruleSet, $files);

        if (0 === count($violations)) {
            $output->writeln('<info>There are no violations - congratulations!</info>');

            return 0;
        }

        $output->writeln(sprintf('<comment>There are %s deprecations:</comment>', count($violations)));

        $container['violation.renderer']->renderViolations($violations);

        return 0;
    }

    /**
     * @param $ruleSet
     *
     * @return RuleSet
     *
     * @throws \RuntimeException
     */
    protected function loadRuleSet($ruleSet)
    {
        $container = $this->getApplication()->getContainer();

        /* @var Loader\LoaderInterface $loader */
        if (is_dir($ruleSet)) {
            $loader = $container['ruleset.loader.directory'];
        } elseif ('composer.lock' === basename($ruleSet)) {
            $loader = $container['ruleset.loader.composer'];
        } else {
            $loader = $container['ruleset.loader.ruleset'];
        }

        return $loader->loadRuleSet($ruleSet);
    }

    /**
     * @param RuleSet             $ruleSet
     * @param ParsedPhpFileFinder $files
     *
     * @return Violation[]
     */
    protected function getViolations(RuleSet $ruleSet, ParsedPhpFileFinder $files)
    {
        $container = $this->getApplication()->getContainer();

        // TODO: move to container?
        $checker = new ComposedViolationChecker(
            array(
                new ViolationChecker\ClassViolationChecker($ruleSet),
                new ViolationChecker\InterfaceViolationChecker($ruleSet),
                new ViolationChecker\MethodViolationChecker($ruleSet, $container['ancestor_resolver']),
                new ViolationChecker\SuperTypeViolationChecker($ruleSet),
                new ViolationChecker\TypeHintViolationChecker($ruleSet),
                new ViolationChecker\MethodDefinitionViolationChecker($ruleSet, $container['ancestor_resolver']),
            )
        );

        $total = count($files);
        $container['event_dispatcher']->dispatch(
            ProgressEvent::CHECKER,
            new ProgressEvent(0, $total)
        );

        $result = array();
        foreach ($files as $i => $file) {
            $result = array_merge($result, $checker->check($file));

            $container['event_dispatcher']->dispatch(
                ProgressEvent::CHECKER,
                new ProgressEvent(++$i, $total)
            );
        }

        return $result;
    }
}
