<?php

namespace IndieHD\AudioManipulator\Processing;

use Symfony\Component\Process\Exception\ProcessFailedException as SymfonyProcessFailedException;

class ProcessFailedException extends SymfonyProcessFailedException
{
    public function __construct(ProcessInterface $process)
    {
        parent::__construct($process->getProcess());
    }
}
