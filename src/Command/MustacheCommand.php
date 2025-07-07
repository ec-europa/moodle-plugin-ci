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

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Lints mustache template files.
 */
class MustacheCommand extends AbstractMoodleCommand
{
    use ExecuteTrait;

    protected function configure(): void
    {
        parent::configure();

        $this->setName('mustache')
            ->setDescription('Run Mustache Lint on a plugin');
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->initializeExecute($output, $this->getHelper('process'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->outputHeading($output, 'Mustache Lint on %s');

        $files = $this->plugin->getFiles(Finder::create()->name('*.mustache'));
        if (count($files) === 0) {
            return $this->outputSkip($output);
        }

        $linter  = __DIR__ . '/../../vendor/moodlehq/moodle-local_ci/mustache_lint/mustache_lint.php';
        $jarFile = $this->resolveJarFile();

        // This is a workaround to execute mustache_lint.php file from within a phar.
        // (by copying both the script and the jar file to a temporary directory)
        $filesystem = new Filesystem();
        $tmpDir     = sys_get_temp_dir();
        $wrapper    = tempnam($tmpDir, 'mustache-linter-wrapper');
        $jarTmpFile = $tmpDir . '/vnu.jar';
        $filesystem->dumpFile($wrapper, sprintf('<?php include \'%s\';', $linter));
        $filesystem->copy($jarFile, $jarTmpFile, true);

        $code = 0;
        foreach ($files as $file) {
            $cmd = [
                'env',
                '-u',
                // _JAVA_OPTIONS is something Travis CI started to set in Trusty.  This breaks Mustache because
                // the output from vnu.jar needs to be captured and JSON decoded.  When _JAVA_OPTIONS is present,
                // then a message like "Picked up _JAVA_OPTIONS..." is printed which breaks JSON decoding.
                '_JAVA_OPTIONS',
                'php',
                $wrapper,
                '--filename=' . $file,
                '--validator=' . $jarTmpFile,
                '--basename=' . $this->moodle->getPublicDirectory(),
            ];
            // _JAVA_OPTIONS is something Travis CI started to set in Trusty.  This breaks Mustache because
            // the output from vnu.jar needs to be captured and JSON decoded.  When _JAVA_OPTIONS is present,
            // then a message like "Picked up _JAVA_OPTIONS..." is printed which breaks JSON decoding.
            $process = $this->execute->passThroughProcess(new Process($cmd, $this->moodle->directory, null, null, null));

            if (!$process->isSuccessful()) {
                $code = 1;
            }
        }

        $filesystem->remove($wrapper);
        $filesystem->remove($jarTmpFile);

        return $code;
    }

    /**
     * @return string
     */
    private function resolveJarFile(): string
    {
        // Check if locally installed.
        $file = __DIR__ . '/../../vendor/moodlehq/moodle-local_ci/node_modules/vnu-jar/build/dist/vnu.jar';
        if (is_file($file)) {
            // No need to use realpath() when running from a phar.
            return (\Phar::running() !== '') ? $file : realpath($file);
        }

        // Check for global install.
        $this->validateJarVersion();

        $cmd = [
            'npm',
            '-g',
            'prefix',
        ];
        $process = $this->execute->mustRun($cmd);
        $file    = trim($process->getOutput()) . '/lib/node_modules/vnu-jar/build/dist/vnu.jar';

        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('Failed to find %s', $file));
        }

        return $file;
    }

    private function validateJarVersion(): void
    {
        $cmd = [
            'npm',
            '-g',
            'list',
            '--json',
        ];
        $json = json_decode($this->execute->mustRun($cmd)->getOutput(), true);
        if (!isset($json['dependencies']['vnu-jar']['version'])) {
            throw new \RuntimeException('Failed to find vnu-jar');
        }
        $version = $json['dependencies']['vnu-jar']['version'];
        if (!version_compare($version, '17.3.0', '>=') && !version_compare($version, '18.0.0', '<')) {
            throw new \RuntimeException('Global install of vnu-jar does not match version constraints: vnu-jar@>=17.3.0 <18.0.0');
        }
    }
}
