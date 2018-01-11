<?php

namespace CL\Tissue\Adapter\ClamAv;

use CL\Tissue\Adapter\AbstractAdapter;
use CL\Tissue\Exception\AdapterException;
use CL\Tissue\Model\Detection;
use Symfony\Component\Process\Process;

class ClamAvAdapter extends AbstractAdapter
{
    /**
     * @var string
     */
    protected $clamScanPath;

    /**
     * @var string
     */
    protected $databasePath;

    /**
     * @param string      $clamScanPath
     * @param string|null $databasePath
     *
     * @throws AdapterException If the given path to clamscan (or clamdscan) is not executable
     */
    public function __construct(string $clamScanPath, string $databasePath = null)
    {
        if (!is_executable($clamScanPath)) {
            throw new AdapterException(sprintf(
                'The path to `clamscan` or `clamdscan` could not be found or is not executable (path: %s)',
                $clamScanPath
            ));
        }

        $this->clamScanPath = $clamScanPath;
        $this->databasePath = $databasePath;
    }

    /**
     * {@inheritdoc}
     * @throws \CL\Tissue\Exception\AdapterException
     */
    protected function detect(string $path): ?Detection
    {
        $process = $this->createProcess($path);
        $returnCode = $process->run();
        $output = trim($process->getOutput());
        if (0 !== $returnCode && false === strpos($output, ' FOUND')) {
            throw AdapterException::fromProcess($process);
        }

        foreach (explode("\n", $output) as $line) {
            if (substr($line, -6) === ' FOUND') {
                $file = substr($line, 0, strrpos($line, ':'));
                $description = substr(substr($line, strrpos($line, ':') + 2), 0, -6);

                return $this->createDetection($file, Detection::TYPE_VIRUS, $description);
            }
        }

        return null;
    }

    /**
     * @param string $path
     *
     * @return Process
     */
    private function createProcess(string $path): Process
    {
        $pb = $this->createProcessBuilder([$this->clamScanPath]);
        $pb->add('--no-summary');

        if ($this->usesDaemon($this->clamScanPath)) {
            // Pass filedescriptor to clamd (useful if clamd is running as a different user)
            $pb->add('--fdpass');
        } elseif ($this->databasePath !== null) {
            // Only the (isolated) binary version can change the signature-database used
            $pb->add(sprintf('--database=%s', $this->databasePath));
        }

        $pb->add($path);

        return $pb->getProcess();
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    private function usesDaemon(string $path): bool
    {
        return substr($path, -9) === 'clamdscan';
    }
}
