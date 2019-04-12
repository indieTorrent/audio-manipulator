<?php

namespace IndieHD\AudioManipulator\Flac;

use Psr\Log\LoggerInterface;

use IndieHD\AudioManipulator\Processing\ProcessFailedException;
use IndieHD\AudioManipulator\Converting\ConverterInterface;
use IndieHD\AudioManipulator\Processing\ProcessInterface;
use IndieHD\AudioManipulator\Validation\ValidatorInterface;
use IndieHD\AudioManipulator\Mp3\Mp3WriterInterface;
use IndieHD\AudioManipulator\Alac\AlacWriterInterface;
use IndieHD\AudioManipulator\Wav\WavWriterInterface;
use IndieHD\AudioManipulator\CliCommand\SoxCommandInterface;
use IndieHD\AudioManipulator\CliCommand\FfmpegCommandInterface;

class FlacConverter implements
    ConverterInterface,
    Mp3WriterInterface,
    AlacWriterInterface,
    WavWriterInterface
{
    private $validator;
    private $process;
    private $logger;
    private $sox;
    private $ffmpeg;

    protected $supportedOutputFormats;

    public function __construct(
        ValidatorInterface $validator,
        ProcessInterface $process,
        LoggerInterface $logger,
        SoxCommandInterface $sox,
        FfmpegCommandInterface $ffmpeg
    ) {
        $this->validator = $validator;
        $this->process = $process;
        $this->logger = $logger;
        $this->sox = $sox;
        $this->ffmpeg = $ffmpeg;

        $this->setSupportedOutputFormats([
            'wav',
            'mp3',
            'm4a',
            'ogg',
        ]);
    }

    public function setSupportedOutputFormats(array $supportedOutputFormats): void
    {
        $this->supportedOutputFormats = $supportedOutputFormats;
    }

    private function writeFile(string $inputFile, string $outputFile): array
    {
        $this->validator->validateAudioFile($inputFile, 'flac');

        $this->sox->input($inputFile);

        $this->sox->output($outputFile);

        // If "['LC_ALL' => 'en_US.utf8']" is not passed here, any UTF-8
        // character will appear as a "#" symbol.

        $env = ['LC_ALL' => 'en_US.utf8'];

        $this->process->setCommand($this->sox->compose());

        $this->process->setTimeout(600);

        $this->process->run(null, $env);

        if (!$this->process->isSuccessful()) {
            throw new ProcessFailedException($this->process);
        }

        $this->logger->info(
            $this->process->getProcess()->getCommandLine() . PHP_EOL . PHP_EOL
                . $this->process->getOutput()
        );

        // On the Windows platform, SoX's exit status is not preserved, thus
        // we must confirm that the operation was completed successfully by
        // other means.

        // We'll use a validation function to analyze the resultant file and ensure that the
        // file meets our expectations.

        // Grab the file extension to determine the implicit audio format of the
        // output file.

        $fileExt = pathinfo($outputFile, PATHINFO_EXTENSION);

        $outputFormat = $fileExt;

        return $this->validator->validateAudioFile($outputFile, $outputFormat);
    }

    public function toMp3(string $inputFile, string $outputFile): array
    {
        return $this->writeFile($inputFile, $outputFile);
    }

    /**
     * @param string $inputFile
     * @param string $outputFile
     * @return array
     */
    public function toAlac(string $inputFile, string $outputFile): array
    {
        $this->validator->validateAudioFile($inputFile, 'flac');

        // In avconv/ffmpeg version 9.16 (and possibly earlier), embedded artwork with a
        // width or height that is not divisible by 2 will cause a failure, e.g.:
        // "width not divisible by 2 (1419x1419)". So, we must strip any "odd" artwork.
        // It's entirely possible that artwork was not copied in earlier versions, so
        // this error did not occur.

        // TODO Determine whether or not this is still necessary.

        #$this->tagger->removeArtwork($inputFile);

        $this->ffmpeg->input($inputFile);

        $this->ffmpeg->output($outputFile);

        $this->ffmpeg->overwriteOutput($outputFile);

        $this->ffmpeg->forceAudioCodec('alac');

        // If "['LC_ALL' => 'en_US.utf8']" is not passed here, any UTF-8
        // character will appear as a "#" symbol.

        $env = ['LC_ALL' => 'en_US.utf8'];

        $this->process->setCommand($this->ffmpeg->compose());

        $this->process->setTimeout(600);

        $this->process->run(null, $env);

        if (!$this->process->isSuccessful()) {
            throw new ProcessFailedException($this->process);
        }

        $this->logger->info(
            $this->process->getProcess()->getCommandLine() . PHP_EOL . PHP_EOL
            . $this->process->getOutput()
        );

        // We'll use a validation function to analyze the resultant file and ensure that the
        // file meets our expectations.

        // Grab the file extension to determine the implicit audio format of the
        // input file.

        $fileExt = pathinfo($outputFile, PATHINFO_EXTENSION);

        $outputFormat = $fileExt;

        return $this->validator->validateAudioFile($outputFile, $outputFormat);
    }

    public function toWav(string $inputFile, string $outputFile): array
    {
        return $this->writeFile($inputFile, $outputFile);
    }
}
