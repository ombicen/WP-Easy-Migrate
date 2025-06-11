<?php
use PHPUnit\Framework\TestCase;

class ExporterTest extends TestCase
{
    public function testCreateFilteredArchiveFailure()
    {
        $refClass = new ReflectionClass('WPEasyMigrate\Exporter');
        $exporter = $refClass->newInstanceWithoutConstructor();

        $method = $refClass->getMethod('create_filtered_archive');
        $method->setAccessible(true);

        $sourceDir = sys_get_temp_dir();
        $archivePath = '/dev/null';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cannot create archive');

        $method->invoke($exporter, $sourceDir, $archivePath, []);
    }
}
?>
