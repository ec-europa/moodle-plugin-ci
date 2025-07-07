<?php

/*
 * This file is part of the Moodle Plugin CI package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Copyright (c) 2018 Blackboard Inc. (http://www.blackboard.com)
 * License http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace MoodlePluginCI\Command;

use MoodlePluginCI\Bridge\MoodlePlugin;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

class PHPDocCommand extends AbstractMoodleCommand
{
    use ExecuteTrait;

    private Finder $finder;

    protected function configure(): void
    {
        parent::configure();

        $this->setName('phpdoc')
            ->setDescription('Run Moodle PHPDoc Checker on a plugin')
            ->addOption('max-warnings', null, InputOption::VALUE_REQUIRED,
                'Number of warnings to trigger nonzero exit code - default: -1', -1);
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->initializeExecute($output, $this->getHelper('process'));
        $this->finder = Finder::create()->name('*.php');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->outputHeading($output, 'Moodle PHPDoc Checker on %s');

        // We need local_moodlecheck plugin to run this check.
        $pluginlocation  = __DIR__ . '/../../vendor/moodlehq/moodle-local_moodlecheck';
        $plugin          = new MoodlePlugin($pluginlocation);
        $directory       = $this->moodle->getComponentInstallDirectory($plugin->getComponent());
        if (!is_dir($directory)) {
            // Copy plugin into Moodle if it does not exist.
            $filesystem = new Filesystem();
            $filesystem->mirror($plugin->directory, $directory);
        }

        $files = $this->plugin->getFiles($this->finder);
        if (count($files) === 0) {
            return $this->outputSkip($output);
        }

        $cmd = [
            'php',
            'local/moodlecheck/cli/moodlecheck.php',
            '-p=' . implode(',', $files),
            '-f=text',
        ];

        $process = $this->execute->passThroughProcess(new Process($cmd, $this->moodle->getPublicDirectory(), null, null, null));

        if (isset($filesystem)) {
            // Remove plugin if we added it, so we leave things clean.
            $filesystem->remove($directory);
        }

        // moodlecheck.php does not return valid exit status,
        // We have to parse output to see if there are errors and/or warnings.
        $results        = $process->getOutput();
        $totalProblems  = (int) preg_match_all('~^\\s+Line~m', $results);
        $totalWarnings  = (int) preg_match_all('~\(warning\)$~m', $results);
        $totalErrors    = $totalProblems - $totalWarnings;
        $pseudoExitCode = ($totalErrors > 0) ? 1 : 0; // Calculate exit code based on # errors by default.

        // If we aren't using the max-warnings option, (pseudo) process exit code is enough for us.
        if ($input->getOption('max-warnings') < 0) {
            return $pseudoExitCode;
        }

        // With errors or warnings over the max-warnings threshold, fail the command.
        return ($totalErrors > 0 || ($totalWarnings > $input->getOption('max-warnings'))) ? 1 : 0;
    }
}
