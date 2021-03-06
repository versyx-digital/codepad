<?php

declare(strict_types=1);

require __DIR__.'/../../config/bootstrap.php';

const MAX_RUNTIME_SECONDS = 5;

if (posix_geteuid() !== 0) {
    fprintf(STDERR, 'this script must run as root');
    die;
}

$code = stream_get_contents(STDIN);
if (!is_string($code)) {
    throw new \RuntimeException('failed to read the code from stdin! (stream_get_contents failed)');
}

$file = tempnam(env('CHROOT_ROOT'), 'unsafe');
if (!is_string($file)) {
    throw new \RuntimeException('tempnam failed!');
}

if (strlen($code) !== file_put_contents($file, $code)) {
    throw new \RuntimeException('failed to write the code to disk! (out of diskspace?)');
}

if (!chmod($file, 0444)) {
    throw new \RuntimeException('failed to chmod!');
}

$starttime = microtime(true);
$unused = [];
$ph = proc_open('chroot --userspec=nobody '.env('CHROOT_ROOT').' /php-'.$argv[1].'/bin/php '.escapeshellarg(basename($file)), $unused, $unused);
$terminated = false;
while (($status = proc_get_status($ph))['running']) {
    usleep(100 * 1000);
    if (!$terminated && microtime(true) - $starttime > MAX_RUNTIME_SECONDS) {
        $terminated = true;
        echo 'max runtime reached ('.MAX_RUNTIME_SECONDS.' seconds), terminating...';
        pKill((int) ($status['pid']));
    }
}

proc_close($ph);
