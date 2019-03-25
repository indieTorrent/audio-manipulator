<?php

namespace IndieHD\AudioManipulator\Transcoding;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use IndieHD\AudioManipulator\Utility;
use IndieHD\AudioManipulator\Validation\Validator;
use IndieHD\AudioManipulator\Tagging\Tagger;
use IndieHD\AudioManipulator\Process;
use IndieHD\AudioManipulator\ProcessFailedException;

class Transcoder
{
    public $process;

    private $singleThreaded = true;

    public function __construct(
        Validator $validator,
        Tagger $tagger,
        Process $process,
        Logger $logger
    ) {
        $this->validator = $validator;
        $this->tagger = $tagger;
        $this->process = $process;
        $this->logger = $logger;

        // TODO Make the log location configurable.

        $fileHandler = new StreamHandler(
            'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR
            . 'transcoder.log',
            Logger::INFO
        );

        $this->logger->pushHandler($fileHandler);
    }

    public function transcode(
        $inputFile,
        $outputFile,
        $trackPreviewStart = 0,
        $performTrim = false,
        $clipLength = 90,
        $fadeIn = false,
        $fadeInLength = 6,
        $fadeOut = false,
        $fadeOutLength = 6
    ) {
        if (!file_exists($inputFile)) {
            throw new \RuntimeException('The input file "' . $inputFile . '" appears not to exist');
        }

        // Grab the file extension to determine the implicit audio format of the
        // input file.

        $fileExt = pathinfo($inputFile, PATHINFO_EXTENSION);
        $inputFormat = $fileExt;

        // Attempt to validate the input file according to its implied file type.

        $valRes = $this->validator->validateAudioFile($inputFile, $inputFormat);

        if ($valRes === false) {
            throw new \RuntimeException('The input file does not validate as a ' . strtoupper($inputFormat) . ' file');
        }

        // The audio type validation function returns the audio file's
        // details if the validation succeeds. We may as well leverage that
        // for the next step.

        $fileDetails = &$valRes;

        // Determine whether or not the audio file is actually shorter than
        // the specified clip length. This is done to prevent the resultant
        // file from being padded with silence.

        if ($fileDetails['playtime_seconds'] < $clipLength) {
            // No trim or fades are necessary if the play-time
            // is less than the specified clip length.

            $performTrim = false;
            $fadeIn = false;
            $fadeOut = false;
        }

        // This block prevents problems from a track preview start
        // time that is too far into the track to allow for a 90-second
        // preview clip.

        if (($fileDetails['playtime_seconds'] - $trackPreviewStart) < 90) {
            // We'll force the track preview to start exactly 90 seconds
            // before the end of the track, thus forcing a 90-second preview
            // clip length.

            $trackPreviewStart = $fileDetails['playtime_seconds'] - 90;
        }

        // Convert the clip length from seconds to hh:mm:ss format.

        $clipLength = Utility::sec2hms($clipLength, false, false);

        $cmd = '';

        //Attempt to create a preview clip.

        $cmd .= 'sox';

        if ($this->singleThreaded === true) {
            $cmd .= ' --single-threaded';
        }

        // If setlocale(LC_CTYPE, "en_US.UTF-8") is not called here, any UTF-8 character will equate to an empty string.

        setlocale(LC_CTYPE, 'en_US.UTF-8');

        $cmd .= ' -V4';
        $cmd .= ' ' . escapeshellarg($inputFile);
        $cmd .= ' --channels 2';
        $cmd .= ' ' . escapeshellarg($outputFile);

        if ($performTrim !== false) {
            $cmd .= ' trim ' . escapeshellarg($trackPreviewStart) . ' ' . escapeshellarg($clipLength);
        }

        if ($fadeIn !== false || $fadeOut !== false) {
            $cmd .= ' fade q ';

            if ($fadeIn === false) {
                // Setting a fade-in length of zero in SoX is the
                // same as having no fade-in at all.

                $fadeInLength = '0';
            }

            $cmd .= escapeshellarg($fadeInLength);

            if ($fadeOut !== false) {
                $cmd .= ' ' . escapeshellarg($clipLength) . ' ' . escapeshellarg($fadeOutLength);
            }
        }

        // If "['LC_ALL' => 'en_US.utf8']" is not passed here, any UTF-8 character will appear as a "#" symbol.

        $env = ['LC_ALL' => 'en_US.utf8'];

        $this->process->setTimeout(600);
    
        $this->process->run($cmd, null, $env);

        if (!$this->process->isSuccessful()) {
            throw new ProcessFailedException($this->process);
        }

        $this->logger->info($cmd . PHP_EOL . PHP_EOL . $this->process->getOutput());

        // Grab the file extension to determine the implicit audio format of the
        // output file.

        $fileExt = pathinfo($outputFile, PATHINFO_EXTENSION);

        $outputFormat = $fileExt;

        // First, we'll see if the file was output successfully.

        if (!file_exists($outputFile)) {
            throw new \RuntimeException('The ' . strtoupper($outputFormat)
                . ' file appears not to have been created');
        }

        // On the Windows platform, SoX's exit status is not preserved, thus
        // we must confirm that the operation was completed successfully by
        // other means.

        // We'll use a validation function to analyze the resultant file and ensure that the
        // file meets our expectations.

        // Grab the file extension to determine the implicit audio format of the
        // input file.

        $fileExt = pathinfo($outputFile, PATHINFO_EXTENSION);

        $outputFormat = $fileExt;

        if (!$this->validator->validateAudioFile($outputFile, $outputFormat)) {
            throw new \RuntimeException('The ' . strtoupper($outputFormat)
                . ' file appears to have been created, but does not validate as'
                . ' such; ensure that the determined audio format (e.g., MP1,'
                . ' MP2, etc.) is in the array of allowable formats');
        }

        return ['result' => true, 'error' => null];
    }

    //Accepts a WAV file as input and converts the audio data to
    //an MP3 file per the specified attributes.

    public function wavToMp3($inputFile, $outputFile, $encodingMethod, $quality, $frequency = null, $bitWidth = null)
    {
        if (!file_exists($inputFile)) {
            $error = 'The input file appears not to exist';
            return array('result' => false, 'error' => $error);
        }

        //The AUDIO DATA format will be 'wav' if everything is functioning as expected.

        if (!$this->validator->validateAudioFile($inputFile, 'wav')) {
            $error = 'The input file does not validate as a WAV file';
            return array('result' => false, 'error' => $error);
        }

        //Attempt to convert the WAV file to an MP3 file.

        $cmd = 'lame --quiet -T --noreplaygain -q 0 ';

        if (!is_null($frequency)) {
            $cmd .= '--resample ' . $frequency . ' ';
        }

        if (!is_null($bitWidth)) {
            $cmd .= '--bitwidth ' . $bitWidth . ' ';
        }

        if ($encodingMethod == 'cbr') {
            // If the bitrate mode is cbr, $quality should be an actual bitrate,
            // e.g., 128 (kbps).

            $cmd .= '--cbr -b ' . $quality;
        } elseif ($encodingMethod == 'abr') {
            // If the bitrate mode is abr, $quality should be an actual bitrate,
            // e.g., 128 (kbps).

            $cmd .= '--abr ' . $quality;
        } elseif ($encodingMethod == 'vbr') {
            // If the bitrate mode is vbr, $quality should be an integer between
            // 0 and 9 (0 being the highest quality, 9 being the lowest quality).

            // IMPORTANT: In versions of Lame > 3.97, -v equates to --vbr-new
            // instead of --vbr-old, which was the behavior prior to 3.97.

            $cmd .= '--vbr-new -V ' . $quality;
        } else {
            $error = 'A valid encoding method was not specified';
            return array('result' => false, 'error' => $error);
        }

        // If setlocale(LC_CTYPE, "en_US.UTF-8") is not called here, any UTF-8 character will equate to an empty string.

        setlocale(LC_CTYPE, 'en_US.UTF-8');

        $cmd .= escapeshellarg($inputFile) . ' ' . escapeshellarg($outputFile);

        // If "['LC_ALL' => 'en_US.utf8']" is not passed here, any UTF-8 character will appear as a "#" symbol.

        $env = ['LC_ALL' => 'en_US.utf8'];
    
        $this->process->setTimeout(600);
    
        $this->process->run($cmd, null, $env);
    
        $this->logger->info($cmd . PHP_EOL . PHP_EOL . $this->process->getOutput());

        // First, we'll see if the file was output successfully.

        if (!file_exists($outputFile)) {
            $error = 'The MP3 file appears not to have been created; the command'
                . ' history was *****' . print_r($this->commandHistory, true) . '*****';
            
            return array('result' => false, 'error' => $error);
        }

        // We'll use a validation function to analyze the resultant file and ensure that the
        // file meets our expectations.
        
        if (!$this->validator->validateAudioFile($outputFile, 'mp3')) {
            $error = 'The MP3 file appears to have been created, but does not validate as such';
            return array('result' => false, 'error' => $error);
        }

        return array('result' => true, 'error' => null);
    }

    public function convertWavToFlac($inputFile, $outputFile)
    {
        $cmd = 'sox';

        if ($this->singleThreaded === true) {
            $cmd .= ' --single-threaded';
        }

        // If setlocale(LC_CTYPE, "en_US.UTF-8") is not called here, any UTF-8 character will equate to an empty string.

        setlocale(LC_CTYPE, 'en_US.UTF-8');

        $cmd .= escapeshellarg($inputFile) . ' ' . escapeshellarg($outputFile);

        // If "['LC_ALL' => 'en_US.utf8']" is not passed here, any UTF-8 character will appear as a "#" symbol.

        $env = ['LC_ALL' => 'en_US.utf8'];
    
        $this->process->setTimeout(600);
    
        $this->process->run($cmd, null, $env);
    
        $this->logger->info($cmd . PHP_EOL . PHP_EOL . $this->process->getOutput());

        // Grab the file extension to determine the implicit audio format of the
        // output file.
        
        $fileExt = pathinfo($outputFile, PATHINFO_EXTENSION);
        
        $outputFormat = $fileExt;

        // First, we'll see if the file was output successfully.
        
        if (!file_exists($outputFile)) {
            $error = 'The ' . strtoupper($outputFormat) . ' file appears not to'
                . ' have been created; the command history was *****'
                . print_r($this->commandHistory, true) . '*****';
            
            return array('result' => false, 'error' => $error);
        }

        // On the Windows platform, SoX's exit status is not preserved, thus
        // we must confirm that the operation was completed successfully by
        // other means.
        
        // We'll use a validation function to analyze the resultant file and ensure that the
        // file meets our expectations.

        // Grab the file extension to determine the implicit audio format of the
        // input file.
        
        $fileExt = pathinfo($outputFile, PATHINFO_EXTENSION);
        
        $outputFormat = $fileExt;

        $fileDetails = $this->validator->validateAudioFile($outputFile, $outputFormat);

        if ($fileDetails === false) {
            $error = 'The ' . strtoupper($outputFormat) . ' file appears to have'
                . ' been created, but does not validate as such; ensure that the'
                . ' determined audio format (e.g., MP1, MP2, etc.) is in the'
                . ' array of allowable formats';
            
            return array('result' => false, 'error' => $error);
        }

        return array('result' => $fileDetails, 'error' => null);
    }

    /**
     * Important: NEVER call this function on a "master" file, as it removes the
     * artwork from THAT file (and not a copy)!
     * @param string $file
     * @param array $tagData
     * @param boolean $allowBlank
     * @param string $coverFile
     * @return multitype:boolean string |multitype:boolean NULL
     */
    public function transcodeFlacToAlac($file, $tagData = array(), $allowBlank = false, $coverFile = null)
    {
        //In avconv/ffmpeg version 9.16 (and possibly earlier), embedded artwork with a
        //width or height that is not divisible by 2 will cause a failure, e.g.:
        //"width not divisible by 2 (1419x1419)". So, we must strip any "odd" artwork.
        //It's entirely possible that artwork was not copied in earlier versions, so
        //this error did not occur.

        $r = $this->tagger->removeArtwork($file);

        $cmd1 = 'ffmpeg -i';

        //Tag data is copied automatically. Nice!!!

        $pathParts = pathinfo($file);

        $outfile = $pathParts['dirname'] . DIRECTORY_SEPARATOR . $pathParts['filename'] . '.m4a';

        // If setlocale(LC_CTYPE, "en_US.UTF-8") is not called here, any UTF-8 character will equate to an empty string.

        setlocale(LC_CTYPE, 'en_US.UTF-8');

        $cmd1 .= ' ' . escapeshellarg($file) . ' -acodec alac ' . escapeshellarg($outfile);

        // If "['LC_ALL' => 'en_US.utf8']" is not passed here, any UTF-8 character will appear as a "#" symbol.

        $env = ['LC_ALL' => 'en_US.utf8'];

        $r1 = \GlobalMethods::openProcess($cmd1, null, $env);

        if ($r1['exitCode'] == 0) {
            //Write the cover artwork into the file, and fail gracefully.

            //Note the unconventional letter-case of the executable name and its options
            //(which are indeed case-sensitive).

            if (is_string($coverFile) && strlen($coverFile) > 0) {
                $cmd2 = 'AtomicParsley ' . escapeshellarg($outfile) . ' --artwork '
                    . escapeshellarg($coverFile) . ' --overWrite';

                $r2 = \GlobalMethods::openProcess($cmd2, null, $env);

                if ($r2['exitCode'] != 0) {
                    $e = 'The FLAC file was transcoded to an ALAC file successfully,
                        but the album artwork could not be embedded; the command was: "' . $cmd2 . '"';

                    \GlobalMethods::logCriticalError($e);
                }
            }
        } else {
            $error = 'The FLAC file could not be transcoded to an ALAC file; the command was: "' . $cmd1 . '"';
            return array('result' => false, 'error' => $error);
        }

        return array('result' => true, 'error' => null);
    }
}
