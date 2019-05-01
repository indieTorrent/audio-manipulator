<?php

namespace IndieHD\AudioManipulator\Flac;

use getID3;
use getid3_writetags;

use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Monolog\Handler\HandlerInterface;

use Symfony\Component\Filesystem\Exception\FileNotFoundException;

use IndieHD\FilenameSanitizer\FilenameSanitizerInterface;

use IndieHD\AudioManipulator\Flac\FlacTaggerInterface;
use IndieHD\AudioManipulator\Tagging\AudioTaggerException;

use IndieHD\AudioManipulator\Validation\ValidatorInterface;

use IndieHD\AudioManipulator\Processing\Process;
use IndieHD\AudioManipulator\Processing\ProcessInterface;
use IndieHD\AudioManipulator\Processing\ProcessFailedException;

use IndieHD\AudioManipulator\CliCommand\MetaflacCommandInterface;

class FlacTagger implements FlacTaggerInterface
{
    private $env;
    private $logName = 'FLAC_TAGGER_LOG';
    private $loggingEnabled = false;

    public $getid3;
    private $writeTags;
    private $process;
    private $logger;
    private $handler;
    private $filenameSanitizer;
    public $command;
    private $validator;

    public function __construct(
        getID3 $getid3,
        getid3_writetags $writeTags,
        ProcessInterface $process,
        LoggerInterface $logger,
        HandlerInterface $handler,
        FilenameSanitizerInterface $filenameSanitizer,
        MetaflacCommandInterface $command,
        ValidatorInterface $validator
    ) {
        $this->getid3 = $getid3;
        $this->writeTags = $writeTags;
        $this->process = $process;
        $this->logger = $logger;
        $this->handler = $handler;
        $this->filenameSanitizer = $filenameSanitizer;
        $this->command = $command;
        $this->validator = $validator;

        $this->configureLogger();

        // If "['LC_ALL' => 'en_US.utf8']" is not passed here, any UTF-8
        // character will appear as a "#" symbol in the resultant tag value.

        $this->env = ['LC_ALL' => 'en_US.utf8'];
    }

    protected function configureLogger(): void
    {
        if (!empty(getenv($this->logName))) {
            $this->logger->pushHandler($this->handler);
        }

        if (getenv('ENABLE_LOGGING') === 'true') {
            $this->loggingEnabled = true;
        }
    }

    protected function log(string $message, string $level = 'info'): void
    {
        if ($this->loggingEnabled) {
            $this->logger->{$level}($message);
        }
    }

    /**
     * Add metadata tags to FLAC files.
     *
     * @param string $file
     * @param array $tagData
     * @return array
     */
    public function writeTags(string $file, array $tagData): void
    {
        if (!file_exists($file)) {
            throw new FileNotFoundException('The input file "' . $file . '" appears not to exist');
        }

        $this->command->input($file);

        // TODO Removing all tags as a matter of course is problematic because
        // the Artist may have added custom tags that he/she spent considerable
        // time creating, as in the case of normalization data. It should be
        // determined whether or not this is still necessary.

        // Attempt to remove any existing tags before writing new tags.
        // IMPORTANT: The --remove-vc-all option is deprecated in favor of the
        // --remove-all-tags option; using the deprecated option will cause the
        // command to fail on systems on which the option is not supported.
        // Changed to --remove-all because cover art was not being removed.
        // -CBJ 2011.01.18

        $this->removeAllTags($file);

        $this->command->input($file);

        $this->attemptWrite($tagData);

        $this->verifyTagData($file, $tagData);
    }

    public function removeAllTags(string $file): void
    {
        $this->command
            ->input($file)
            ->removeAll();

        $this->runProcess($this->command->compose());
    }

    public function removeTags(string $file, array $tags): void
    {
        $this->command
            ->input($file)
            ->removeTags($tags);

        $this->runProcess($this->command->compose());
    }

    public function writeArtwork(string $audioFile, string $imageFile): void
    {
        $this->command
            ->input($audioFile)
            ->importPicture($imageFile);

        $this->runProcess($this->command->compose());
    }

    public function removeArtwork(string $file): void
    {
        $this->command
            ->input($file)
            ->removeBlockType(['PICTURE']);

        $this->runProcess($this->command->compose());
    }

    protected function runProcess(array $cmd): Process
    {
        $this->process->setCommand($cmd);

        $this->process->setTimeout(600);

        $this->process->run(null, $this->env);

        if (!$this->process->isSuccessful()) {
            throw new ProcessFailedException($this->process);
        }

        $this->log(
            $this->process->getProcess()->getCommandLine() . PHP_EOL . PHP_EOL
            . $this->process->getOutput()
        );

        $this->command->removeAllArguments();

        return $this->process;
    }

    protected function attemptWrite(array $tagData): void
    {
        foreach ($tagData as $fieldName => $fieldDataArray) {
            foreach ($fieldDataArray as $numericIndex => $fieldValue) {
                $this->command->setTag($fieldName, $fieldValue);
            }
        }

        $this->runProcess($this->command->compose());
    }

    // TODO As it stands, this function is problematic because the Vorbis Comment
    // standard allows for multiple instances of the same tag name, e.g., passing
    // --set-tag=ARTIST=Foo --set-tag=ARTIST=Bar is perfectly valid. This function
    // should be modified to accommodate that fact.

    protected function verifyTagData(string $file, array $tagData): void
    {
        $fileDetails = $this->getid3->analyze($file);

        // TODO Determine what this was used for and whether or not it needs to stay.

        //if ($allowBlank !== true) {

        $vorbiscomment = $fileDetails['tags']['vorbiscomment'];

        $failures = [];

        // Compare the passed tag data to the values acquired from the file.

        foreach ($tagData as $fieldName => $fieldDataArray) {
            foreach ($fieldDataArray as $numericIndex => $fieldValue) {
                if ($vorbiscomment[$fieldName][0] != $fieldValue) {
                    $failures[] = $fieldName;
                }
            }
        }

        if (count($failures) > 0) {
            throw new AudioTaggerException(
                'Expected value does not match actual value for tags: ' . implode(', ', $failures)
            );
        }
    }
}
