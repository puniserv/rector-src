<?php

declare(strict_types=1);

namespace Rector\Utils\ParallelProcessRunner;

use Rector\Utils\ParallelProcessRunner\Exception\CouldNotDeterminedCpuCoresException;

final class SystemInfo
{
    /**
     * @see https://gist.github.com/divinity76/01ef9ca99c111565a72d3a8a6e42f7fb
     *
     * returns number of cpu cores
     * Copyleft 2018, license: WTFPL
     */
    public function getCpuCores(): int
    {
        if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $str = trim(shell_exec('wmic cpu get NumberOfCores 2>&1'));
            if (! preg_match('#(\d+)#', $str, $matches)) {
                throw new CouldNotDeterminedCpuCoresException('wmic failed to get number of cpu cores on windows!');
            }
            return (int) $matches[1];
        }
        $ret = @shell_exec('nproc');
        if (is_string($ret)) {
            $ret = trim($ret);
            if (($tmp = filter_var($ret, FILTER_VALIDATE_INT)) !== false) {
                return (int) $tmp;
            }
        }
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            $count = substr_count($cpuinfo, 'processor');
            if ($count > 0) {
                return $count;
            }
        }
        throw new CouldNotDeterminedCpuCoresException('failed to detect number of CPUs!');
    }
}
