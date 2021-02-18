<?php

namespace IndieHD\AudioManipulator\Tests\CliCommand;

use IndieHD\AudioManipulator\CliCommand\CliCommand;
use PHPUnit\Framework\TestCase;

class CliCommandTest extends TestCase
{
    private $stub;

    /**
     * @inheritdoc
     */
    public function setUp(): void
    {
        $this->stub = $this->getMockForAbstractClass(CliCommand::class);

        $this->stub->setParts(['infile' => []]);

        $this->stub->addArgument('infile', 'test.flac');
    }

    public function testItRemovesAllArguments()
    {
        $this->stub->removeAllArguments();

        $args = $this->stub->getCommandParts()['infile'];

        $this->assertIsArray($args);

        $this->assertEmpty($args);
    }
}
