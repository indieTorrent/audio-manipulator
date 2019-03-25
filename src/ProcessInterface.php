<?php

namespace IndieHD\AudioManipulator;

interface ProcessInterface
{
    public function run(string $command, callable $callback = null, $env = []);

    public function setTimeout(int $seconds);

    public function isSuccessful();

    public function getOutput();
}
