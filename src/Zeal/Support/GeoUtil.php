<?php namespace Zeal\Support;

/**
 * GEO相关的一些通用方法。
 */
class GeoUtil
{
    /** 度量单位：千米 */
    const DISTANCE_UNIT_KILOMETER = 'K';
    /** 度量单位：英里 */
    const DISTANCE_UNIT_MILES = 'M';
    /** 度量单位：海里 */
    const DISTANCE_UNIT_NAUTICAL_MILES = 'N';

    /**
     * 计算两个坐标之间的距离
     *
     * @param float $lat1 起始纬度
     * @param float $lon1 起始经度
     * @param float $lat2 结束纬度
     * @param float $lon2 结束经度
     * @param string $unit 度量单位，缺省为DISTANCE_UNIT_KILOMETER
     *
     * @return float|null 注意如果用来计算的两个坐标有问题，返回的是NAN，调用方应该通过 is_nan() 来判断是不是有效计算出来的距离。
     */
    public static function distance($lat1, $lon1, $lat2, $lon2, $unit = self::DISTANCE_UNIT_KILOMETER)
    {
        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        $unit = strtoupper($unit);

        if ($unit == self::DISTANCE_UNIT_KILOMETER) {
            return ($miles * 1.609344);
        }
        else if ($unit == self::DISTANCE_UNIT_NAUTICAL_MILES) {
            return ($miles * 0.8684);
        }
        else {
            return $miles;
        }
    }

    /**
     * 得到两个定位信息之间的时间+距离偏移量。固定算法。
     *
     * @param float $lat1 起始纬度
     * @param float $lng1 起始经度
     * @param float $lat2 结束纬度
     * @param float $lng2 结束经度
     * @param integer $timestamp1 起点记录时间戳
     * @param integer $timestamp2 终点记录时间戳
     *
     * @param int &$l 两点间距离（千米），在计算后会进行设置。
     * @param int &$t 两点间时间差（秒），在计算后会进行设置。
     *
     * @return integer 两个定位信息之间的时间+距离偏移量阀值。
     */
    public static function getDTOffset($lat1, $lng1, $lat2, $lng2, $timestamp1, $timestamp2, &$l = 0, &$t = 0)
    {
        $l = self::distance($lat1, $lng1, $lat2, $lng2);
        $t = function_exists('bcsub') ? bcsub($timestamp2 . '', '' . $timestamp1) : ($timestamp2 - $timestamp1);

        return round(($l * 1000 + 1) * ($t + 1));
    }

    /**
     * 查找一堆坐标的中心点
     *
     * @param array $data array(array(lat,lng)...)
     * @param bool $withMaxRd 是否返回离中心点最远的半径长度，缺省为false不进行计算。
     *
     * @return array|bool array($lat,$lng,半径（米）)
     */
    public static function getCenterFromDegrees($data, $withMaxRd = false)
    {
        if (!is_array($data)) return false;

        $num_coords = count($data);

        $X = 0.0;
        $Y = 0.0;
        $Z = 0.0;

        foreach ($data as $coord) {
            $lat = $coord[0] * pi() / 180;
            $lon = $coord[1] * pi() / 180;

            $a = cos($lat) * cos($lon);
            $b = cos($lat) * sin($lon);
            $c = sin($lat);

            $X += $a;
            $Y += $b;
            $Z += $c;
        }

        $X /= $num_coords;
        $Y /= $num_coords;
        $Z /= $num_coords;

        $lon = atan2($Y, $X);
        $hyp = sqrt($X * $X + $Y * $Y);
        $lat = atan2($Z, $hyp);

        $ret = array($lat * 180 / pi(), $lon * 180 / pi(), 0);
        if (!$withMaxRd) return $ret;

        $maxRd = 0;
        foreach ($data as $coord) {
            $theRd = self::distance($ret[0], $ret[1], $coord[0], $coord[1]);
            if ($theRd > $maxRd) $maxRd = $theRd;
        }
        $ret[2] = $maxRd * 1000;

        return $ret;
    }

    /**
     * 判断经纬度是否是基本有意义的数据（全0、全1、带E科学记数的都要排除掉）
     *
     * @param float|string $longitude
     * @param float|string $latitude
     *
     * @return bool
     */
    public static function isMeaningfulCoord($longitude, $latitude)
    {
        if (is_null($longitude) || is_null($latitude)) {
            return false;
        }

        if (@floatval($longitude) == 0 && @floatval($latitude) == 0) {
            // 经纬度都是0，虽然理论上这个坐标是存在的，但实际这个坐标不可能开着车子到那里。基本就是错误的坐标数据。
            return false;
        }

        if (@floatval($longitude) == 1 && @floatval($latitude) == 1) {
            // 经纬度都是1，虽然理论上这个坐标是存在的，但实际这个坐标不可能开着车子到那里。基本就是错误的坐标数据。
            return false;
        }

        if (strpos(strtoupper($longitude . $latitude), 'E') > 0) {
            // 经纬度有科学计数存在，什么玩意。。。
            return false;
        }

        return true;
    }

    /**
     * 检测指定的点是否位于一个多边形内。
     *
     * ray-casting algorithm based on
     * http://www.ecse.rpi.edu/Homepages/wrf/Research/Short_Notes/pnpoly.html
     *
     * TODO: More efficiency improvements
     * @link http://alienryderflex.com/polygon/
     *
     * For Complex Polygon with spline curves:
     * @link http://alienryderflex.com/polyspline/
     *
     * @param double $x
     * @param double $y
     * @param array[] $polygon 不少于3个(x,y)组成的多边形位置信息。
     *
     * @return bool
     */
    public static function isPointInPolygon($x, $y, array $polygon)
    {
        $inside = false;
        $cornerCount = count($polygon);
        $x = (float) $x;
        $y = (float) $y;
        $j = $cornerCount - 1;
        for ($i = 0; $i < $cornerCount; $j = $i++) {
            $xi = (float) $polygon[$i][0];
            $yi = (float) $polygon[$i][1];
            $xj = (float) $polygon[$j][0];
            $yj = (float) $polygon[$j][1];

            $intersect =
                (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);

            $intersect && $inside = !$inside;
        }

        return $inside;
    }
}
