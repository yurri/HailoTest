<?php
/**
 * Hailo code test
 *
 * Task description:
 *
 * > Develop a program that, given a series of points (latitude,longitude,timestamp) for a cab journey from A-B
 * > will disregard potentially erroneous points. Try to demonstrate a knowledge of Object Oriented concepts in
 * > your answer. Your answer must be returned as a single PHP file which can be run against the PHP 5.3 CLI.
 * > The attached dataset is provided as an example, with a png of the 'cleaned' route as a guide.
 *
 * Source dataset is available as Google Fusion Table (as a table or as a map) at:
 * https://www.google.com/fusiontables/data?docid=1K3dZUCoi42261GwGbfVhL5y5H769FPoBwD3qrHE#map:id=3
 *
 * Removed points are available at:
 * https://www.google.com/fusiontables/DataSource?docid=1y_qlutjY_1LEdNDWDGujT1mKHZ-qfHJIDg6UHhQ#map:id=3
 *
 * Cleansed route is available at:
 * https://www.google.com/fusiontables/DataSource?docid=1RiCn2LyVQLiQ8Xq8_9shT-MfpSvpkwoMiglyf3Y#map:id=3
 *
 * @author  Yuriy Akopov (akopov@hotmail.co.uk)
 * @date    2013-04-03
 */
class GeoPoint {
    /**
     * Earth radius in metres - will need to inherit from this class and re-bind (possible in PHP 5.3+) if on Mars
     */
    const EARTH_RADIUS = 6371000;

    /**
     * @var float|null
     */
    protected $latitude = null;

    /**
     * @var float|null
     */
    protected $longitude = null;

    /**
     * @var int|null
     */
    protected $timestamp = null;

    /**
     * Converts given value from degrees to radians
     *
     * (we don't have to stick to "readable" units such as km and rad to solve this particular problem
     * but it's still nice and simplified testing)
     *
     * @param   float   $value
     * @return  float
     */
    protected function toRadians($value) {
        return $value * pi() / 180;
    }

    /**
     * @param bool $asRadians
     * @return float|null
     */
    public function getLatitude($asRadians = false) {
        if ($asRadians) {
            return $this->toRadians($this->latitude);
        }

        return $this->latitude;
    }

    /**
     * @param bool $asRadians
     * @return float|null
     */
    public function getLongitude($asRadians = false) {
        if ($asRadians) {
            return $this->toRadians($this->longitude);
        }

        return $this->longitude;
    }

    /**
     * @return int|null
     */
    public function getTimestamp() {
        return $this->timestamp;
    }

    /**
     * Receives a row read from the input CSV file and initialises point fields
     *
     * @param   array   $csvRow
     */
    public function __construct(array $csvRow) {
        $this->latitude     = (float) $csvRow[0];
        $this->longitude    = (float) $csvRow[1];
        $this->timestamp    = (int) $csvRow[2];
	}

    /**
     * Calculates distance (in metres) between this point and the one given
     *
     * @param   GeoPoint    $point
     * @return  float
     */
    public function distanceTo(GeoPoint $point) {
        // it doesn't have much effect on a particular dataset (can approximate with straight lines)
        // but let's still assume the Earth is spherical and we need to calculate the length of an arc

        $dLat = $this->toRadians($point->getLatitude() - $this->getLatitude());
        $dLon = $this->toRadians($point->getLongitude() - $this->getLongitude());

        $lat1 = $this->getLatitude(true);
        $lat2 = $point->getLatitude(true);

        $a =
            sin($dLat / 2) * sin($dLat / 2) +
            sin($dLon / 2) * sin($dLon / 2) * cos($lat1) * cos($lat2)
        ;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return abs(self::EARTH_RADIUS * $c);
    }

    /**
     * Calculates average speed (in km/h) between this point and the one given
     *
     * @param   GeoPoint    $point
     * @return  float
     */
    public function speedTo(GeoPoint $point) {
        $dTime = $point->getTimestamp() - $this->getTimestamp();
        if ($dTime === 0) {
            return 0;
        }

        // metres per second
        $speed = $this->distanceTo($point) / $dTime;

        // km per hour for readability
        return $speed * ((60 * 60) / 1000);
    }

    /**
     * Returns point properties in a form compatible with input format
     *
     * @return   array
     */
    public function toArray() {
        return array(
            $this->getLatitude(),
            $this->getLongitude(),
            $this->getTimestamp()
        );
    }
}

/**
 * Factory class to read points from input data and output them in the same format
 * Also performs calculations over the set of points
 */
class GeoPointsFactory {
    /**
     * This can be optimised as we can start calculations while reading saving at least one loop over the input dataset
     * But we're supposed to chase 'OO' over 'high efficiency' here, so reading and calculations are separated
     *
     * @param   resource    $stream
     * @return  GeoPoint[]
     */
    public static function fromCsv($stream) {
        $points = array();
        while ($row = fgetcsv($stream)) {
            // we can potentially bump into php memory limit here, but supporting that case is too much code clutter for a test
            $points[] = new GeoPoint($row);
        }

        // sorting read points by timestamp (although the sample dataset is already sorted)
        usort($points, function($a, $b) {
            $t1 = $a->getTimestamp();
            $t2 = $b->getTimestamp();

            if ($t1 < $t2) {
                return -1;
            } else if ($t1 > $t2) {
                return 1;
            }

            return 0;
        });

        return $points;
    }

    /**
     * Outputs given points into a supplied context in the same CSV format as input data
     *
     * @param   GeoPoint[]  $points
     * @param   resource    $stream
     * @return  string
     */
    public static function toCsv(array $points, $stream) {
        foreach ($points as $pt) {
            fwrite($stream, implode(',', $pt->toArray()) . PHP_EOL);
        }
    }

    /**
     * Finally, analyses given set of points returning ones recognised as signal or noise (param controlled)
     *
     * This task is actually a classical Machine Learning problem ("linear regression with multiple variables")
     * and it has a universal solution with first breaking input data into clusters (in which of them the cab
     * moves relatively straight), then plotting them in 3D matrix (coordinates plus time), then approximating
     * clusters with a surface in that matrix, then calculating distance to that surface for every point of the cluster.
     *
     * Or we can use a general "gradient descent" approach again common in the Machine learning.
     *
     * However, I've got a strong feeling this would be an overkill for this task because honestly these algorithms
     * are best implemented without OOP as they're more math than programming (making a perfect case for FP)
     *
     * So let's use a simpler (but still effective enough) approach here by calculating cab's average speed between
     * points and considering points to be noise when the speed between their previous and next neighbours is too high.
     *
     * In terms of Machine Learning speed is going to be our "cost function"
     *
     * @param   GeoPoint[]  $points
     * @param   float       $ratio          speed/average speed ratio to consider suspicious
     * @param   bool        $returnNoise    for testing purposes it's more convenient to return noise, not cleansed route
     * @return  GeoPoint[]
     */
    public static function filterNoise(array $points, $ratio, $returnNoise = false) {
        $ptPrev = null;
        $speeds = array();
        foreach($points as $ptCur) {
            if (is_null($ptPrev)) {
                $ptPrev = $ptCur;
            }

            // skipping points where the cab is standing still
            if ($ptPrev->distanceTo($ptCur) === 0) {
                continue;
            }
            $speeds[] = $ptPrev->speedTo($ptCur);

            $ptPrev = $ptCur;
        }

        if (empty($speeds)) {
            // all points recorded at the same place, but it's not an error as technically every point is valid
            // reporting no noise
            return ($returnNoise ? array() : $points);
        }

        // a point is considered a noise when the speed calculated from it to its two neighbours (previous and the next
        // in the set) is greater than average speed * ratio supplied
        // if the speed is through the roof to only one of the neighbours but then normal it could be valid
        // or require more sophisticated solution

        $avgSpeed = array_sum($speeds) / count($speeds);    // 69.88 km/h for sample dataset
        $noiseMargin = $avgSpeed * $ratio;                  // speed to consider suspicious

        $result = array();
        $ptPrev = null;

        for ($i = 0; $i < count($points); $i++) {
            $ptCur  = $points[$i];
            $ptPrev = isset($points[$i - 1]) ? $points[$i - 1] : null;
            $ptNext = isset($points[$i + 1]) ? $points[$i + 1] : null;

            // this way for the first and the last point only one check would be required to suspect them a noise
            $noisePrev = is_null($ptPrev);
            $noiseNext = is_null($ptNext);

            // check the previous point
            if (!$noisePrev) {
                if ($ptPrev->speedTo($ptCur) > $noiseMargin) {
                    $noisePrev = true;
                }
            }
            // check the next point
            if (!$noiseNext) {
                if ($ptCur->speedTo($ptNext) > $noiseMargin) {
                    $noiseNext = true;
                }
            }

            if ($noisePrev and $noiseNext) {
                // current point is probably a noise
                if ($returnNoise) {
                    $result[] = $ptCur;
                }
            } else if (!$returnNoise) {
                $result[] = $ptCur;
            }
        }

        return $result;
    }
}

// some of the points which look as noise to a human eye (for testing):
// #135 - 1326379585
// #93  - 1326379365
// #75  - 1326379271
// #180 - 1326380169
// #195 - 1326380295

$src = fopen('points.csv', 'r') or die('Cannot open input file');
$points = GeoPointsFactory::fromCsv($src);
fclose($src);

// change last parameter to true to get filtered out points instead of cleansed route
$noise  = GeoPointsFactory::filterNoise($points, 1.34 /* manually adjusted coefficient */, false);

$stdout = fopen('php://stdout', 'w');
print GeoPointsFactory::toCsv($noise, $stdout);
fclose($stdout);
