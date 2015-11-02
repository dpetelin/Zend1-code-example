<?php

/**
 * Class for convertation dimensions
 *
 * @category    Core
 * @author      ISSArt LTD <contacts@issart.com>
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 * @link        www.issart.com
 * @since       1.0
 * @version     $Revision: 1.0 $
 */
class Core_Convert
{
    /**
     * @param mixed $value
     * @param string $from - see constants Zend_Measure_Length::*
     * @param string $to - see constants Zend_Measure_Length::*
     * @return float
     */
    public static function length($value, $from, $to)
    {
        if ($from && $to) {
            $unit = new Zend_Measure_Length($value, $from);
            $value = $unit->convertTo($to);
        }
        return (float) $value;
    }

    /**
     * @param mixed $value
     * @param string $from - see constants Zend_Measure_Weight::*
     * @param string $to - see constants Zend_Measure_Weight::*
     * @return float
     */
    public static function weight($value, $from, $to)
    {
        if ($from && $to) {
            $unit = new Zend_Measure_Weight($value, $from);
            $value = $unit->convertTo($to);
        }
        return (float) $value;
    }

    public static function weightKgToLbs($value)
    {
        $weight = $value * Zend_Controller_Action_HelperBroker::getStaticHelper('settings')->get('constant.kilogramPoundRelation');

        $lb = floor((float)$weight);
        $oz = round(16 * ((float)$weight - $lb));

        return array($lb, $oz);
    }
}