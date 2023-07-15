<?php
/**
 * @param DateInterval $interval The interval
 * @param string[] $format
 * @param string $separator
 * @return string Formatted interval string.
 */
function human_date_interval(DateInterval $interval, $format = null, $separator = ', ') {
    if (!is_array($format)) {
        $format = [
            'months' => '%d month(s)',
            'days' => '%d day(s)',
            'hours' => '%d hour(s)',
            'minutes' => '%d minute(s)',
            'seconds' => '%d second(s)',
        ];
    }

    $p1y = (new DateTime())->setTimeStamp(0)->add(new \DateInterval('P1Y'))->getTimeStamp();
    $p1m = (new DateTime())->setTimeStamp(0)->add(new \DateInterval('P1M'))->getTimeStamp();
    $p1d = (new DateTime())->setTimeStamp(0)->add(new \DateInterval('P1D'))->getTimeStamp();
    $pt1h = (new DateTime())->setTimeStamp(0)->add(new \DateInterval('PT1H'))->getTimeStamp();
    $pt1m = (new DateTime())->setTimeStamp(0)->add(new \DateInterval('PT1M'))->getTimeStamp();

    $result['seconds'] = $seconds = (new DateTime())->setTimeStamp(0)->add($interval)->getTimeStamp();
    $result['years'] = (int) ($result['seconds'] / $p1y);
    $result['months'] = (int) (($result['seconds'] = ($result['seconds'] - ($p1y * $result['years']))) / $p1m);
    $result['days'] = (int) (($result['seconds'] = ($result['seconds'] - ($p1m * $result['months']))) / $p1d);
    $result['hours'] = (int) (($result['seconds'] = ($result['seconds'] - ($p1d * $result['days']))) / $pt1h);
    $result['minutes'] = (int) (($result['seconds'] = ($result['seconds'] - ($pt1h * $result['hours']))) / $pt1m);
    $result['seconds'] = (int) ($result['seconds'] - ($pt1m * $result['minutes']));

    $formattedResult = [];
    foreach ($format as $t => $v) {
        if ($result[$t] > 0) {
            $formattedResult[$t] = sprintf($v, $result[$t]);
        }
    }

    return implode($separator, $formattedResult);
}

/**
 * @see https://stackoverflow.com/a/28047922/5431347
 *
 * @param int $bytes
 * @return string
 */
function byteConvert($bytes)
{
    if ($bytes == 0)
        return '0.00 B';

    $s = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');
    $e = floor(log($bytes, 1024));

    return round($bytes/pow(1024, $e), 2) . ' ' . $s[$e];
}
