<?php

namespace DEMO;

use JsonSerializable;
use RuntimeException;
use stdClass;
use ArrayAccess;
use InvalidArgumentException;
use Exception;

/**
 * Formatters
 */
interface IFormatter
{
    /**
     * format
     *
//     * @param mixed $value
//     * @return string
     */
    public static function format($value);
}

class IntegerFormatter implements IFormatter
{
    /**
     * format
     *
//     * @param mixed $value
//     * @return string
     */
    public static function format($value)
    { //echo "value:".$value."\n";
        $value = (string) $value;
        $arguments = func_get_args();
        $digit = 64;

        if (isset($arguments[1]) && is_numeric($arguments[1])) {
            $digit = intval($arguments[1]);
        }
        $bn = Utils::toBn($value);
        $bnHex = $bn->toHex(true);
        $padded = mb_substr($bnHex, 0, 1);

        if ($padded !== 'f') {
            $padded = '0';
	}
	// echo "bnHex: $bnHex\n";
	// echo "padded: $padded\n";
	if(($digit - mb_strlen($bnHex))>0)
          return implode('', array_fill(0, $digit-mb_strlen($bnHex), $padded)) . $bnHex;
	else
	  return $bnHex;
    }
}

class BigNumberFormatter implements IFormatter
{
    /**
     * format
     *
//     * @param mixed $value
//     * @return string
     */
    public static function format($value)
    {
        $value = Utils::toString($value);
        $bn = Utils::toBn($value);

        return $bn;
    }
}

/**
 * Pure-PHP arbitrary precision integer arithmetic library. Supports base-2, base-10, base-16, and base-256
 * numbers.
 */
class BigNumber
{
    /**#@+
     * Reduction constants
     *
     * @access private
     * @see BigInteger::_reduce()
     */
    /**
     * @see BigInteger::_montgomery()
     * @see BigInteger::_prepMontgomery()
     */
    const MONTGOMERY = 0;
    /**
     * @see BigInteger::_barrett()
     */
    const BARRETT = 1;
    /**
     * @see BigInteger::_mod2()
     */
    const POWEROF2 = 2;
    /**
     * @see BigInteger::_remainder()
     */
    const CLASSIC = 3;
    /**
     * @see BigInteger::__clone()
     */
    const NONE = 4;
    /**#@-*/

    /**#@+
     * Array constants
     *
     * Rather than create a thousands and thousands of new BigInteger objects in repeated function calls to add() and
     * multiply() or whatever, we'll just work directly on arrays, taking them in as parameters and returning them.
     *
     * @access private
     */
    /**
     * $result[self::VALUE] contains the value.
     */
    const VALUE = 0;
    /**
     * $result[self::SIGN] contains the sign.
     */
    const SIGN = 1;
    /**#@-*/

    /**#@+
     * @access private
     * @see BigInteger::_montgomery()
     * @see BigInteger::_barrett()
     */
    /**
     * Cache constants
     *
     * $cache[self::VARIABLE] tells us whether or not the cached data is still valid.
     */
    const VARIABLE = 0;
    /**
     * $cache[self::DATA] contains the cached data.
     */
    const DATA = 1;
    /**#@-*/

    /**#@+
     * Mode constants.
     *
     * @access private
     * @see BigInteger::__construct()
     */
    /**
     * To use the pure-PHP implementation
     */
    const MODE_INTERNAL = 1;
    /**
     * To use the BCMath library
     *
     * (if enabled; otherwise, the internal implementation will be used)
     */
    const MODE_BCMATH = 2;
    /**
     * To use the GMP library
     *
     * (if present; otherwise, either the BCMath or the internal implementation will be used)
     */
    const MODE_GMP = 3;
    /**#@-*/

    /**
     * Karatsuba Cutoff
     *
     * At what point do we switch between Karatsuba multiplication and schoolbook long multiplication?
     *
     * @access private
     */
    const KARATSUBA_CUTOFF = 25;

    /**#@+
     * Static properties used by the pure-PHP implementation.
     *
     * @see __construct()
     */
    protected static $base;
    protected static $baseFull;
    protected static $maxDigit;
    protected static $msb;

    /**
     * $max10 in greatest $max10Len satisfying
     * $max10 = 10**$max10Len <= 2**$base.
     */
    protected static $max10;

    /**
     * $max10Len in greatest $max10Len satisfying
     * $max10 = 10**$max10Len <= 2**$base.
     */
    protected static $max10Len;
    protected static $maxDigit2;
    /**#@-*/

    /**
     * Holds the BigInteger's value.
     *
     * @access private
     */
    var $value;

    /**
     * Holds the BigInteger's magnitude.
     *
     * @var bool
     * @access private
     */
    var $is_negative = false;

    /**
     * Precision
     *
     * @see self::setPrecision()
     * @access private
     */
    var $precision = -1;

    /**
     * Precision Bitmask
     *
     * @see self::setPrecision()
     * @access private
     * @var object
     */
    var $bitmask = false;

    /**
     * Mode independent value used for serialization.
     *
     * If the bcmath or gmp extensions are installed $this->value will be a non-serializable resource, hence the need for
     * a variable that'll be serializable regardless of whether or not extensions are being used.  Unlike $this->value,
     * however, $this->hex is only calculated when $this->__sleep() is called.
     *
     * @see self::__sleep()
     * @see self::__wakeup()
     * @var string
     * @access private
     */
    var $hex;

    /**
     * Converts base-2, base-10, base-16, and binary strings (base-256) to BigIntegers.
     *
     * If the second parameter - $base - is negative, then it will be assumed that the number's are encoded using
     * two's compliment.  The sole exception to this is -10, which is treated the same as 10 is.
     *
     * Here's an example:
     * <code>
     * <?php
     *    $a = new \phpseclib\Math\BigInteger('0x32', 16); // 50 in base-16
     *
     *    echo $a->toString(); // outputs 50
     * ?>
     * </code>
     *
     * @param int|string|resource $x base-10 number or base-$base number if $base set.
     * @param int $base
     * @return \phpseclib\Math\BigInteger
     * @access public
     */
    function __construct($x = 0, $base = 10)
    {
        if (!defined('MATH_BIGINTEGER_MODE')) {
            switch (true) {
                case extension_loaded('gmp'):
                    define('MATH_BIGINTEGER_MODE', self::MODE_GMP);
                    break;
                case extension_loaded('bcmath'):
                    define('MATH_BIGINTEGER_MODE', self::MODE_BCMATH);
                    break;
                default:
                    define('MATH_BIGINTEGER_MODE', self::MODE_INTERNAL);
            }
        }

        if (extension_loaded('openssl') && !defined('MATH_BIGINTEGER_OPENSSL_DISABLE') && !defined('MATH_BIGINTEGER_OPENSSL_ENABLED')) {
            // some versions of XAMPP have mismatched versions of OpenSSL which causes it not to work
            $versions = array();

            // avoid generating errors (even with suppression) when phpinfo() is disabled (common in production systems)
            if (strpos(ini_get('disable_functions'), 'phpinfo') === false) {
                ob_start();
                @phpinfo();
                $content = ob_get_contents();
                ob_end_clean();

                preg_match_all('#OpenSSL (Header|Library) Version(.*)#im', $content, $matches);

                if (!empty($matches[1])) {
                    for ($i = 0; $i < count($matches[1]); $i++) {
                        $fullVersion = trim(str_replace('=>', '', strip_tags($matches[2][$i])));

                        // Remove letter part in OpenSSL version
                        if (!preg_match('/(\d+\.\d+\.\d+)/i', $fullVersion, $m)) {
                            $versions[$matches[1][$i]] = $fullVersion;
                        } else {
                            $versions[$matches[1][$i]] = $m[0];
                        }
                    }
                }
            }

            // it doesn't appear that OpenSSL versions were reported upon until PHP 5.3+
            switch (true) {
                case !isset($versions['Header']):
                case !isset($versions['Library']):
                case $versions['Header'] == $versions['Library']:
                case version_compare($versions['Header'], '1.0.0') >= 0 && version_compare($versions['Library'], '1.0.0') >= 0:
                    define('MATH_BIGINTEGER_OPENSSL_ENABLED', true);
                    break;
                default:
                    define('MATH_BIGINTEGER_OPENSSL_DISABLE', true);
            }
        }

        if (!defined('PHP_INT_SIZE')) {
            define('PHP_INT_SIZE', 4);
        }

        if (empty(self::$base) && MATH_BIGINTEGER_MODE == self::MODE_INTERNAL) {
            switch (PHP_INT_SIZE) {
                case 8: // use 64-bit integers if int size is 8 bytes
                    self::$base      = 31;
                    self::$baseFull  = 0x80000000;
                    self::$maxDigit  = 0x7FFFFFFF;
                    self::$msb       = 0x40000000;
                    self::$max10     = 1000000000;
                    self::$max10Len  = 9;
                    self::$maxDigit2 = pow(2, 62);
                    break;
                //case 4: // use 64-bit floats if int size is 4 bytes
                default:
                    self::$base      = 26;
                    self::$baseFull  = 0x4000000;
                    self::$maxDigit  = 0x3FFFFFF;
                    self::$msb       = 0x2000000;
                    self::$max10     = 10000000;
                    self::$max10Len  = 7;
                    self::$maxDigit2 = pow(2, 52); // pow() prevents truncation
            }
        }

        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                switch (true) {
                    case is_resource($x) && get_resource_type($x) == 'GMP integer':
                        // PHP 5.6 switched GMP from using resources to objects
                    case $x instanceof \GMP:
                        $this->value = $x;
                        return;
                }
                $this->value = gmp_init(0);
                break;
            case self::MODE_BCMATH:
                $this->value = '0';
                break;
            default:
                $this->value = array();
        }

        // '0' counts as empty() but when the base is 256 '0' is equal to ord('0') or 48
        // '0' is the only value like this per http://php.net/empty
        if (empty($x) && (abs($base) != 256 || $x !== '0')) {
            return;
        }

        switch ($base) {
            case -256:
                if (ord($x[0]) & 0x80) {
                    $x = ~$x;
                    $this->is_negative = true;
                }
            case 256:
                switch (MATH_BIGINTEGER_MODE) {
                    case self::MODE_GMP:
                        $this->value = function_exists('gmp_import') ?
                            gmp_import($x) :
                            gmp_init('0x' . bin2hex($x));
                        if ($this->is_negative) {
                            $this->value = gmp_neg($this->value);
                        }
                        break;
                    case self::MODE_BCMATH:
                        // round $len to the nearest 4 (thanks, DavidMJ!)
                        $len = (strlen($x) + 3) & 0xFFFFFFFC;

                        $x = str_pad($x, $len, chr(0), STR_PAD_LEFT);

                        for ($i = 0; $i < $len; $i+= 4) {
                            $this->value = bcmul($this->value, '4294967296', 0); // 4294967296 == 2**32
                            $this->value = bcadd($this->value, 0x1000000 * ord($x[$i]) + ((ord($x[$i + 1]) << 16) | (ord($x[$i + 2]) << 8) | ord($x[$i + 3])), 0);
                        }

                        if ($this->is_negative) {
                            $this->value = '-' . $this->value;
                        }

                        break;
                    // converts a base-2**8 (big endian / msb) number to base-2**26 (little endian / lsb)
                    default:
                        while (strlen($x)) {
                            $this->value[] = $this->_bytes2int($this->_base256_rshift($x, self::$base));
                        }
                }

                if ($this->is_negative) {
                    if (MATH_BIGINTEGER_MODE != self::MODE_INTERNAL) {
                        $this->is_negative = false;
                    }
                    $temp = $this->add(new static('-1'));
                    $this->value = $temp->value;
                }
                break;
            case 16:
            case -16:
                if ($base > 0 && $x[0] == '-') {
                    $this->is_negative = true;
                    $x = substr($x, 1);
                }

                $x = preg_replace('#^(?:0x)?([A-Fa-f0-9]*).*#', '$1', $x);

                $is_negative = false;
                if ($base < 0 && hexdec($x[0]) >= 8) {
                    $this->is_negative = $is_negative = true;
                    $x = bin2hex(~pack('H*', $x));
                }

                switch (MATH_BIGINTEGER_MODE) {
                    case self::MODE_GMP:
                        $temp = $this->is_negative ? '-0x' . $x : '0x' . $x;
                        $this->value = gmp_init($temp);
                        $this->is_negative = false;
                        break;
                    case self::MODE_BCMATH:
                        $x = (strlen($x) & 1) ? '0' . $x : $x;
                        $temp = new static(pack('H*', $x), 256);
                        $this->value = $this->is_negative ? '-' . $temp->value : $temp->value;
                        $this->is_negative = false;
                        break;
                    default:
                        $x = (strlen($x) & 1) ? '0' . $x : $x;
                        $temp = new static(pack('H*', $x), 256);
                        $this->value = $temp->value;
                }

                if ($is_negative) {
                    $temp = $this->add(new static('-1'));
                    $this->value = $temp->value;
                }
                break;
            case 10:
            case -10:
                // (?<!^)(?:-).*: find any -'s that aren't at the beginning and then any characters that follow that
                // (?<=^|-)0*: find any 0's that are preceded by the start of the string or by a - (ie. octals)
                // [^-0-9].*: find any non-numeric characters and then any characters that follow that
                $x = preg_replace('#(?<!^)(?:-).*|(?<=^|-)0*|[^-0-9].*#', '', $x);
                if (!strlen($x) || $x == '-') {
                    $x = '0';
                }

                switch (MATH_BIGINTEGER_MODE) {
                    case self::MODE_GMP:
                        $this->value = gmp_init($x);
                        break;
                    case self::MODE_BCMATH:
                        // explicitly casting $x to a string is necessary, here, since doing $x[0] on -1 yields different
                        // results then doing it on '-1' does (modInverse does $x[0])
                        $this->value = $x === '-' ? '0' : (string) $x;
                        break;
                    default:
                        $temp = new static();

                        $multiplier = new static();
                        $multiplier->value = array(self::$max10);

                        if ($x[0] == '-') {
                            $this->is_negative = true;
                            $x = substr($x, 1);
                        }

                        $x = str_pad($x, strlen($x) + ((self::$max10Len - 1) * strlen($x)) % self::$max10Len, 0, STR_PAD_LEFT);
                        while (strlen($x)) {
                            $temp = $temp->multiply($multiplier);
                            $temp = $temp->add(new static($this->_int2bytes(substr($x, 0, self::$max10Len)), 256));
                            $x = substr($x, self::$max10Len);
                        }

                        $this->value = $temp->value;
                }
                break;
            case 2: // base-2 support originally implemented by Lluis Pamies - thanks!
            case -2:
                if ($base > 0 && $x[0] == '-') {
                    $this->is_negative = true;
                    $x = substr($x, 1);
                }

                $x = preg_replace('#^([01]*).*#', '$1', $x);
                $x = str_pad($x, strlen($x) + (3 * strlen($x)) % 4, 0, STR_PAD_LEFT);

                $str = '0x';
                while (strlen($x)) {
                    $part = substr($x, 0, 4);
                    $str.= dechex(bindec($part));
                    $x = substr($x, 4);
                }

                if ($this->is_negative) {
                    $str = '-' . $str;
                }

                $temp = new static($str, 8 * $base); // ie. either -16 or +16
                $this->value = $temp->value;
                $this->is_negative = $temp->is_negative;

                break;
            default:
                // base not supported, so we'll let $this == 0
        }
    }

    /**
     * Converts a BigInteger to a byte string (eg. base-256).
     *
     * Negative numbers are saved as positive numbers, unless $twos_compliment is set to true, at which point, they're
     * saved as two's compliment.
     *
     * Here's an example:
     * <code>
     * <?php
     *    $a = new \phpseclib\Math\BigInteger('65');
     *
     *    echo $a->toBytes(); // outputs chr(65)
     * ?>
     * </code>
     *
     * @param bool $twos_compliment
     * @return string
     * @access public
     * @internal Converts a base-2**26 number to base-2**8
     */
    function toBytes($twos_compliment = false)
    {
        if ($twos_compliment) {
            $comparison = $this->compare(new static());
            if ($comparison == 0) {
                return $this->precision > 0 ? str_repeat(chr(0), ($this->precision + 1) >> 3) : '';
            }

            $temp = $comparison < 0 ? $this->add(new static(1)) : $this->copy();
            $bytes = $temp->toBytes();

            if (!strlen($bytes)) { // eg. if the number we're trying to convert is -1
                $bytes = chr(0);
            }

            if ($this->precision <= 0 && (ord($bytes[0]) & 0x80)) {
                $bytes = chr(0) . $bytes;
            }

            return $comparison < 0 ? ~$bytes : $bytes;
        }

        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                if (gmp_cmp($this->value, gmp_init(0)) == 0) {
                    return $this->precision > 0 ? str_repeat(chr(0), ($this->precision + 1) >> 3) : '';
                }

                if (function_exists('gmp_export')) {
                    $temp = gmp_export($this->value);
                } else {
                    $temp = gmp_strval(gmp_abs($this->value), 16);
                    $temp = (strlen($temp) & 1) ? '0' . $temp : $temp;
                    $temp = pack('H*', $temp);
                }

                return $this->precision > 0 ?
                    substr(str_pad($temp, $this->precision >> 3, chr(0), STR_PAD_LEFT), -($this->precision >> 3)) :
                    ltrim($temp, chr(0));
            case self::MODE_BCMATH:
                if ($this->value === '0') {
                    return $this->precision > 0 ? str_repeat(chr(0), ($this->precision + 1) >> 3) : '';
                }

                $value = '';
                $current = $this->value;

                if ($current[0] == '-') {
                    $current = substr($current, 1);
                }

                while (bccomp($current, '0', 0) > 0) {
                    $temp = bcmod($current, '16777216');
                    $value = chr($temp >> 16) . chr($temp >> 8) . chr($temp) . $value;
                    $current = bcdiv($current, '16777216', 0);
                }

                return $this->precision > 0 ?
                    substr(str_pad($value, $this->precision >> 3, chr(0), STR_PAD_LEFT), -($this->precision >> 3)) :
                    ltrim($value, chr(0));
        }

        if (!count($this->value)) {
            return $this->precision > 0 ? str_repeat(chr(0), ($this->precision + 1) >> 3) : '';
        }
        $result = $this->_int2bytes($this->value[count($this->value) - 1]);

        $temp = $this->copy();

        for ($i = count($temp->value) - 2; $i >= 0; --$i) {
            $temp->_base256_lshift($result, self::$base);
            $result = $result | str_pad($temp->_int2bytes($temp->value[$i]), strlen($result), chr(0), STR_PAD_LEFT);
        }

        return $this->precision > 0 ?
            str_pad(substr($result, -(($this->precision + 7) >> 3)), ($this->precision + 7) >> 3, chr(0), STR_PAD_LEFT) :
            $result;
    }

    /**
     * Converts a BigInteger to a hex string (eg. base-16)).
     *
     * Negative numbers are saved as positive numbers, unless $twos_compliment is set to true, at which point, they're
     * saved as two's compliment.
     *
     * Here's an example:
     * <code>
     * <?php
     *    $a = new \phpseclib\Math\BigInteger('65');
     *
     *    echo $a->toHex(); // outputs '41'
     * ?>
     * </code>
     *
     * @param bool $twos_compliment
     * @return string
     * @access public
     * @internal Converts a base-2**26 number to base-2**8
     */
    function toHex($twos_compliment = false)
    {
        return bin2hex($this->toBytes($twos_compliment));
    }

    /**
     * Converts a BigInteger to a bit string (eg. base-2).
     *
     * Negative numbers are saved as positive numbers, unless $twos_compliment is set to true, at which point, they're
     * saved as two's compliment.
     *
     * Here's an example:
     * <code>
     * <?php
     *    $a = new \phpseclib\Math\BigInteger('65');
     *
     *    echo $a->toBits(); // outputs '1000001'
     * ?>
     * </code>
     *
     * @param bool $twos_compliment
     * @return string
     * @access public
     * @internal Converts a base-2**26 number to base-2**2
     */
    function toBits($twos_compliment = false)
    {
        $hex = $this->toHex($twos_compliment);
        $bits = '';
        for ($i = strlen($hex) - 6, $start = strlen($hex) % 6; $i >= $start; $i-=6) {
            $bits = str_pad(decbin(hexdec(substr($hex, $i, 6))), 24, '0', STR_PAD_LEFT) . $bits;
        }
        if ($start) { // hexdec('') == 0
            $bits = str_pad(decbin(hexdec(substr($hex, 0, $start))), 8 * $start, '0', STR_PAD_LEFT) . $bits;
        }
        $result = $this->precision > 0 ? substr($bits, -$this->precision) : ltrim($bits, '0');

        if ($twos_compliment && $this->compare(new static()) > 0 && $this->precision <= 0) {
            return '0' . $result;
        }

        return $result;
    }

    /**
     * Converts a BigInteger to a base-10 number.
     *
     * Here's an example:
     * <code>
     * <?php
     *    $a = new \phpseclib\Math\BigInteger('50');
     *
     *    echo $a->toString(); // outputs 50
     * ?>
     * </code>
     *
     * @return string
     * @access public
     * @internal Converts a base-2**26 number to base-10**7 (which is pretty much base-10)
     */
    function toString()
    {
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                return gmp_strval($this->value);
            case self::MODE_BCMATH:
                if ($this->value === '0') {
                    return '0';
                }

                return ltrim($this->value, '0');
        }

        if (!count($this->value)) {
            return '0';
        }

        $temp = $this->copy();
        $temp->bitmask = false;
        $temp->is_negative = false;

        $divisor = new static();
        $divisor->value = array(self::$max10);
        $result = '';
        while (count($temp->value)) {
            list($temp, $mod) = $temp->divide($divisor);
            $result = str_pad(isset($mod->value[0]) ? $mod->value[0] : '', self::$max10Len, '0', STR_PAD_LEFT) . $result;
        }
        $result = ltrim($result, '0');
        if (empty($result)) {
            $result = '0';
        }

        if ($this->is_negative) {
            $result = '-' . $result;
        }

        return $result;
    }

    /**
     * Copy an object
     *
     * PHP5 passes objects by reference while PHP4 passes by value.  As such, we need a function to guarantee
     * that all objects are passed by value, when appropriate.  More information can be found here:
     *
     * {@link http://php.net/language.oop5.basic#51624}
     *
     * @access public
     * @see self::__clone()
     * @return \phpseclib\Math\BigInteger
     */
    function copy()
    {
        $temp = new static();
        $temp->value = $this->value;
        $temp->is_negative = $this->is_negative;
        $temp->precision = $this->precision;
        $temp->bitmask = $this->bitmask;
        return $temp;
    }

    /**
     *  __toString() magic method
     *
     * Will be called, automatically, if you're supporting just PHP5.  If you're supporting PHP4, you'll need to call
     * toString().
     *
     * @access public
     * @internal Implemented per a suggestion by Techie-Michael - thanks!
     */
    function __toString()
    {
        return $this->toString();
    }

    /**
     * __clone() magic method
     *
     * Although you can call BigInteger::__toString() directly in PHP5, you cannot call BigInteger::__clone() directly
     * in PHP5.  You can in PHP4 since it's not a magic method, but in PHP5, you have to call it by using the PHP5
     * only syntax of $y = clone $x.  As such, if you're trying to write an application that works on both PHP4 and
     * PHP5, call BigInteger::copy(), instead.
     *
     * @access public
     * @see self::copy()
     * @return \phpseclib\Math\BigInteger
     */
    function __clone()
    {
        return $this->copy();
    }

    /**
     *  __sleep() magic method
     *
     * Will be called, automatically, when serialize() is called on a BigInteger object.
     *
     * @see self::__wakeup()
     * @access public
     */
    function __sleep()
    {
        $this->hex = $this->toHex(true);
        $vars = array('hex');
        if ($this->precision > 0) {
            $vars[] = 'precision';
        }
        return $vars;
    }

    /**
     *  __wakeup() magic method
     *
     * Will be called, automatically, when unserialize() is called on a BigInteger object.
     *
     * @see self::__sleep()
     * @access public
     */
    function __wakeup()
    {
        $temp = new static($this->hex, -16);
        $this->value = $temp->value;
        $this->is_negative = $temp->is_negative;
        if ($this->precision > 0) {
            // recalculate $this->bitmask
            $this->setPrecision($this->precision);
        }
    }

    /**
     *  __debugInfo() magic method
     *
     * Will be called, automatically, when print_r() or var_dump() are called
     *
     * @access public
     */
    function __debugInfo()
    {
        $opts = array();
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                $engine = 'gmp';
                break;
            case self::MODE_BCMATH:
                $engine = 'bcmath';
                break;
            case self::MODE_INTERNAL:
                $engine = 'internal';
                $opts[] = PHP_INT_SIZE == 8 ? '64-bit' : '32-bit';
        }
        if (MATH_BIGINTEGER_MODE != self::MODE_GMP && defined('MATH_BIGINTEGER_OPENSSL_ENABLED')) {
            $opts[] = 'OpenSSL';
        }
        if (!empty($opts)) {
            $engine.= ' (' . implode('.', $opts) . ')';
        }
        return array(
            'value' => '0x' . $this->toHex(true),
            'engine' => $engine
        );
    }

    /**
     * Adds two BigIntegers.
     *
     * Here's an example:
     * <code>
     * <?php
     *    $a = new \phpseclib\Math\BigInteger('10');
     *    $b = new \phpseclib\Math\BigInteger('20');
     *
     *    $c = $a->add($b);
     *
     *    echo $c->toString(); // outputs 30
     * ?>
     * </code>
     *
     * @param \phpseclib\Math\BigInteger $y
     * @return \phpseclib\Math\BigInteger
     * @access public
     * @internal Performs base-2**52 addition
     */
    function add($y)
    {
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                $temp = new static();
                $temp->value = gmp_add($this->value, $y->value);

                return $this->_normalize($temp);
            case self::MODE_BCMATH:
                $temp = new static();
                $temp->value = bcadd($this->value, $y->value, 0);

                return $this->_normalize($temp);
        }

        $temp = $this->_add($this->value, $this->is_negative, $y->value, $y->is_negative);

        $result = new static();
        $result->value = $temp[self::VALUE];
        $result->is_negative = $temp[self::SIGN];

        return $this->_normalize($result);
    }

    /**
     * Performs addition.
     *
     * @param array $x_value
     * @param bool $x_negative
     * @param array $y_value
     * @param bool $y_negative
     * @return array
     * @access private
     */
    function _add($x_value, $x_negative, $y_value, $y_negative)
    {
        $x_size = count($x_value);
        $y_size = count($y_value);

        if ($x_size == 0) {
            return array(
                self::VALUE => $y_value,
                self::SIGN => $y_negative
            );
        } elseif ($y_size == 0) {
            return array(
                self::VALUE => $x_value,
                self::SIGN => $x_negative
            );
        }

        // subtract, if appropriate
        if ($x_negative != $y_negative) {
            if ($x_value == $y_value) {
                return array(
                    self::VALUE => array(),
                    self::SIGN => false
                );
            }

            $temp = $this->_subtract($x_value, false, $y_value, false);
            $temp[self::SIGN] = $this->_compare($x_value, false, $y_value, false) > 0 ?
                $x_negative : $y_negative;

            return $temp;
        }

        if ($x_size < $y_size) {
            $size = $x_size;
            $value = $y_value;
        } else {
            $size = $y_size;
            $value = $x_value;
        }

        $value[count($value)] = 0; // just in case the carry adds an extra digit

        $carry = 0;
        for ($i = 0, $j = 1; $j < $size; $i+=2, $j+=2) {
            $sum = $x_value[$j] * self::$baseFull + $x_value[$i] + $y_value[$j] * self::$baseFull + $y_value[$i] + $carry;
            $carry = $sum >= self::$maxDigit2; // eg. floor($sum / 2**52); only possible values (in any base) are 0 and 1
            $sum = $carry ? $sum - self::$maxDigit2 : $sum;

            $temp = self::$base === 26 ? intval($sum / 0x4000000) : ($sum >> 31);

            $value[$i] = (int) ($sum - self::$baseFull * $temp); // eg. a faster alternative to fmod($sum, 0x4000000)
            $value[$j] = $temp;
        }

        if ($j == $size) { // ie. if $y_size is odd
            $sum = $x_value[$i] + $y_value[$i] + $carry;
            $carry = $sum >= self::$baseFull;
            $value[$i] = $carry ? $sum - self::$baseFull : $sum;
            ++$i; // ie. let $i = $j since we've just done $value[$i]
        }

        if ($carry) {
            for (; $value[$i] == self::$maxDigit; ++$i) {
                $value[$i] = 0;
            }
            ++$value[$i];
        }

        return array(
            self::VALUE => $this->_trim($value),
            self::SIGN => $x_negative
        );
    }

    /**
     * Subtracts two BigIntegers.
     *
     * Here's an example:
     * <code>
     * <?php
     *    $a = new \phpseclib\Math\BigInteger('10');
     *    $b = new \phpseclib\Math\BigInteger('20');
     *
     *    $c = $a->subtract($b);
     *
     *    echo $c->toString(); // outputs -10
     * ?>
     * </code>
     *
     * @param \phpseclib\Math\BigInteger $y
     * @return \phpseclib\Math\BigInteger
     * @access public
     * @internal Performs base-2**52 subtraction
     */
    function subtract($y)
    {
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                $temp = new static();
                $temp->value = gmp_sub($this->value, $y->value);

                return $this->_normalize($temp);
            case self::MODE_BCMATH:
                $temp = new static();
                $temp->value = bcsub($this->value, $y->value, 0);

                return $this->_normalize($temp);
        }

        $temp = $this->_subtract($this->value, $this->is_negative, $y->value, $y->is_negative);

        $result = new static();
        $result->value = $temp[self::VALUE];
        $result->is_negative = $temp[self::SIGN];

        return $this->_normalize($result);
    }

    /**
     * Performs subtraction.
     *
     * @param array $x_value
     * @param bool $x_negative
     * @param array $y_value
     * @param bool $y_negative
     * @return array
     * @access private
     */
    function _subtract($x_value, $x_negative, $y_value, $y_negative)
    {
        $x_size = count($x_value);
        $y_size = count($y_value);

        if ($x_size == 0) {
            return array(
                self::VALUE => $y_value,
                self::SIGN => !$y_negative
            );
        } elseif ($y_size == 0) {
            return array(
                self::VALUE => $x_value,
                self::SIGN => $x_negative
            );
        }

        // add, if appropriate (ie. -$x - +$y or +$x - -$y)
        if ($x_negative != $y_negative) {
            $temp = $this->_add($x_value, false, $y_value, false);
            $temp[self::SIGN] = $x_negative;

            return $temp;
        }

        $diff = $this->_compare($x_value, $x_negative, $y_value, $y_negative);

        if (!$diff) {
            return array(
                self::VALUE => array(),
                self::SIGN => false
            );
        }

        // switch $x and $y around, if appropriate.
        if ((!$x_negative && $diff < 0) || ($x_negative && $diff > 0)) {
            $temp = $x_value;
            $x_value = $y_value;
            $y_value = $temp;

            $x_negative = !$x_negative;

            $x_size = count($x_value);
            $y_size = count($y_value);
        }

        // at this point, $x_value should be at least as big as - if not bigger than - $y_value

        $carry = 0;
        for ($i = 0, $j = 1; $j < $y_size; $i+=2, $j+=2) {
            $sum = $x_value[$j] * self::$baseFull + $x_value[$i] - $y_value[$j] * self::$baseFull - $y_value[$i] - $carry;
            $carry = $sum < 0; // eg. floor($sum / 2**52); only possible values (in any base) are 0 and 1
            $sum = $carry ? $sum + self::$maxDigit2 : $sum;

            $temp = self::$base === 26 ? intval($sum / 0x4000000) : ($sum >> 31);

            $x_value[$i] = (int) ($sum - self::$baseFull * $temp);
            $x_value[$j] = $temp;
        }

        if ($j == $y_size) { // ie. if $y_size is odd
            $sum = $x_value[$i] - $y_value[$i] - $carry;
            $carry = $sum < 0;
            $x_value[$i] = $carry ? $sum + self::$baseFull : $sum;
            ++$i;
        }

        if ($carry) {
            for (; !$x_value[$i]; ++$i) {
                $x_value[$i] = self::$maxDigit;
            }
            --$x_value[$i];
        }

        return array(
            self::VALUE => $this->_trim($x_value),
            self::SIGN => $x_negative
        );
    }

    /**
     * Multiplies two BigIntegers
     *
     * Here's an example:
     * <code>
     * <?php
     *    $a = new \phpseclib\Math\BigInteger('10');
     *    $b = new \phpseclib\Math\BigInteger('20');
     *
     *    $c = $a->multiply($b);
     *
     *    echo $c->toString(); // outputs 200
     * ?>
     * </code>
     *
     * @param \phpseclib\Math\BigInteger $x
     * @return \phpseclib\Math\BigInteger
     * @access public
     */
    function multiply($x)
    {
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                $temp = new static();
                $temp->value = gmp_mul($this->value, $x->value);

                return $this->_normalize($temp);
            case self::MODE_BCMATH:
                $temp = new static();
                $temp->value = bcmul($this->value, $x->value, 0);

                return $this->_normalize($temp);
        }

        $temp = $this->_multiply($this->value, $this->is_negative, $x->value, $x->is_negative);

        $product = new static();
        $product->value = $temp[self::VALUE];
        $product->is_negative = $temp[self::SIGN];

        return $this->_normalize($product);
    }

    /**
     * Performs multiplication.
     *
     * @param array $x_value
     * @param bool $x_negative
     * @param array $y_value
     * @param bool $y_negative
     * @return array
     * @access private
     */
    function _multiply($x_value, $x_negative, $y_value, $y_negative)
    {
        //if ( $x_value == $y_value ) {
        //    return array(
        //        self::VALUE => $this->_square($x_value),
        //        self::SIGN => $x_sign != $y_value
        //    );
        //}

        $x_length = count($x_value);
        $y_length = count($y_value);

        if (!$x_length || !$y_length) { // a 0 is being multiplied
            return array(
                self::VALUE => array(),
                self::SIGN => false
            );
        }

        return array(
            self::VALUE => min($x_length, $y_length) < 2 * self::KARATSUBA_CUTOFF ?
                $this->_trim($this->_regularMultiply($x_value, $y_value)) :
                $this->_trim($this->_karatsuba($x_value, $y_value)),
            self::SIGN => $x_negative != $y_negative
        );
    }

    /**
     * Performs long multiplication on two BigIntegers
     *
     * Modeled after 'multiply' in MutableBigInteger.java.
     *
     * @param array $x_value
     * @param array $y_value
     * @return array
     * @access private
     */
    function _regularMultiply($x_value, $y_value)
    {
        $x_length = count($x_value);
        $y_length = count($y_value);

        if (!$x_length || !$y_length) { // a 0 is being multiplied
            return array();
        }

        if ($x_length < $y_length) {
            $temp = $x_value;
            $x_value = $y_value;
            $y_value = $temp;

            $x_length = count($x_value);
            $y_length = count($y_value);
        }

        $product_value = $this->_array_repeat(0, $x_length + $y_length);

        // the following for loop could be removed if the for loop following it
        // (the one with nested for loops) initially set $i to 0, but
        // doing so would also make the result in one set of unnecessary adds,
        // since on the outermost loops first pass, $product->value[$k] is going
        // to always be 0

        $carry = 0;

        for ($j = 0; $j < $x_length; ++$j) { // ie. $i = 0
            $temp = $x_value[$j] * $y_value[0] + $carry; // $product_value[$k] == 0
            $carry = self::$base === 26 ? intval($temp / 0x4000000) : ($temp >> 31);
            $product_value[$j] = (int) ($temp - self::$baseFull * $carry);
        }

        $product_value[$j] = $carry;

        // the above for loop is what the previous comment was talking about.  the
        // following for loop is the "one with nested for loops"
        for ($i = 1; $i < $y_length; ++$i) {
            $carry = 0;

            for ($j = 0, $k = $i; $j < $x_length; ++$j, ++$k) {
                $temp = $product_value[$k] + $x_value[$j] * $y_value[$i] + $carry;
                $carry = self::$base === 26 ? intval($temp / 0x4000000) : ($temp >> 31);
                $product_value[$k] = (int) ($temp - self::$baseFull * $carry);
            }

            $product_value[$k] = $carry;
        }

        return $product_value;
    }

    /**
     * Performs Karatsuba multiplication on two BigIntegers
     *
     * See {@link http://en.wikipedia.org/wiki/Karatsuba_algorithm Karatsuba algorithm} and
     * {@link http://math.libtomcrypt.com/files/tommath.pdf#page=120 MPM 5.2.3}.
     *
     * @param array $x_value
     * @param array $y_value
     * @return array
     * @access private
     */
    function _karatsuba($x_value, $y_value)
    {
        $m = min(count($x_value) >> 1, count($y_value) >> 1);

        if ($m < self::KARATSUBA_CUTOFF) {
            return $this->_regularMultiply($x_value, $y_value);
        }

        $x1 = array_slice($x_value, $m);
        $x0 = array_slice($x_value, 0, $m);
        $y1 = array_slice($y_value, $m);
        $y0 = array_slice($y_value, 0, $m);

        $z2 = $this->_karatsuba($x1, $y1);
        $z0 = $this->_karatsuba($x0, $y0);

        $z1 = $this->_add($x1, false, $x0, false);
        $temp = $this->_add($y1, false, $y0, false);
        $z1 = $this->_karatsuba($z1[self::VALUE], $temp[self::VALUE]);
        $temp = $this->_add($z2, false, $z0, false);
        $z1 = $this->_subtract($z1, false, $temp[self::VALUE], false);

        $z2 = array_merge(array_fill(0, 2 * $m, 0), $z2);
        $z1[self::VALUE] = array_merge(array_fill(0, $m, 0), $z1[self::VALUE]);

        $xy = $this->_add($z2, false, $z1[self::VALUE], $z1[self::SIGN]);
        $xy = $this->_add($xy[self::VALUE], $xy[self::SIGN], $z0, false);

        return $xy[self::VALUE];
    }

    /**
     * Performs squaring
     *
     * @param array $x
     * @return array
     * @access private
     */
    function _square($x = false)
    {
        return count($x) < 2 * self::KARATSUBA_CUTOFF ?
            $this->_trim($this->_baseSquare($x)) :
            $this->_trim($this->_karatsubaSquare($x));
    }

    /**
     * Performs traditional squaring on two BigIntegers
     *
     * Squaring can be done faster than multiplying a number by itself can be.  See
     * {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf#page=7 HAC 14.2.4} /
     * {@link http://math.libtomcrypt.com/files/tommath.pdf#page=141 MPM 5.3} for more information.
     *
     * @param array $value
     * @return array
     * @access private
     */
    function _baseSquare($value)
    {
        if (empty($value)) {
            return array();
        }
        $square_value = $this->_array_repeat(0, 2 * count($value));

        for ($i = 0, $max_index = count($value) - 1; $i <= $max_index; ++$i) {
            $i2 = $i << 1;

            $temp = $square_value[$i2] + $value[$i] * $value[$i];
            $carry = self::$base === 26 ? intval($temp / 0x4000000) : ($temp >> 31);
            $square_value[$i2] = (int) ($temp - self::$baseFull * $carry);

            // note how we start from $i+1 instead of 0 as we do in multiplication.
            for ($j = $i + 1, $k = $i2 + 1; $j <= $max_index; ++$j, ++$k) {
                $temp = $square_value[$k] + 2 * $value[$j] * $value[$i] + $carry;
                $carry = self::$base === 26 ? intval($temp / 0x4000000) : ($temp >> 31);
                $square_value[$k] = (int) ($temp - self::$baseFull * $carry);
            }

            // the following line can yield values larger 2**15.  at this point, PHP should switch
            // over to floats.
            $square_value[$i + $max_index + 1] = $carry;
        }

        return $square_value;
    }

    /**
     * Performs Karatsuba "squaring" on two BigIntegers
     *
     * See {@link http://en.wikipedia.org/wiki/Karatsuba_algorithm Karatsuba algorithm} and
     * {@link http://math.libtomcrypt.com/files/tommath.pdf#page=151 MPM 5.3.4}.
     *
     * @param array $value
     * @return array
     * @access private
     */
    function _karatsubaSquare($value)
    {
        $m = count($value) >> 1;

        if ($m < self::KARATSUBA_CUTOFF) {
            return $this->_baseSquare($value);
        }

        $x1 = array_slice($value, $m);
        $x0 = array_slice($value, 0, $m);

        $z2 = $this->_karatsubaSquare($x1);
        $z0 = $this->_karatsubaSquare($x0);

        $z1 = $this->_add($x1, false, $x0, false);
        $z1 = $this->_karatsubaSquare($z1[self::VALUE]);
        $temp = $this->_add($z2, false, $z0, false);
        $z1 = $this->_subtract($z1, false, $temp[self::VALUE], false);

        $z2 = array_merge(array_fill(0, 2 * $m, 0), $z2);
        $z1[self::VALUE] = array_merge(array_fill(0, $m, 0), $z1[self::VALUE]);

        $xx = $this->_add($z2, false, $z1[self::VALUE], $z1[self::SIGN]);
        $xx = $this->_add($xx[self::VALUE], $xx[self::SIGN], $z0, false);

        return $xx[self::VALUE];
    }

    /**
     * Divides two BigIntegers.
     *
     * Returns an array whose first element contains the quotient and whose second element contains the
     * "common residue".  If the remainder would be positive, the "common residue" and the remainder are the
     * same.  If the remainder would be negative, the "common residue" is equal to the sum of the remainder
     * and the divisor (basically, the "common residue" is the first positive modulo).
     *
     * Here's an example:
     * <code>
     * <?php
     *    $a = new \phpseclib\Math\BigInteger('10');
     *    $b = new \phpseclib\Math\BigInteger('20');
     *
     *    list($quotient, $remainder) = $a->divide($b);
     *
     *    echo $quotient->toString(); // outputs 0
     *    echo "\r\n";
     *    echo $remainder->toString(); // outputs 10
     * ?>
     * </code>
     *
     * @param \phpseclib\Math\BigInteger $y
     * @return array
     * @access public
     * @internal This function is based off of {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf#page=9 HAC 14.20}.
     */
    function divide($y)
    {
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                $quotient = new static();
                $remainder = new static();

                list($quotient->value, $remainder->value) = gmp_div_qr($this->value, $y->value);

                if (gmp_sign($remainder->value) < 0) {
                    $remainder->value = gmp_add($remainder->value, gmp_abs($y->value));
                }

                return array($this->_normalize($quotient), $this->_normalize($remainder));
            case self::MODE_BCMATH:
                $quotient = new static();
                $remainder = new static();

                $quotient->value = bcdiv($this->value, $y->value, 0);
                $remainder->value = bcmod($this->value, $y->value);

                if ($remainder->value[0] == '-') {
                    $remainder->value = bcadd($remainder->value, $y->value[0] == '-' ? substr($y->value, 1) : $y->value, 0);
                }

                return array($this->_normalize($quotient), $this->_normalize($remainder));
        }

        if (count($y->value) == 1) {
            list($q, $r) = $this->_divide_digit($this->value, $y->value[0]);
            $quotient = new static();
            $remainder = new static();
            $quotient->value = $q;
            $remainder->value = array($r);
            $quotient->is_negative = $this->is_negative != $y->is_negative;
            return array($this->_normalize($quotient), $this->_normalize($remainder));
        }

        static $zero;
        if (!isset($zero)) {
            $zero = new static();
        }

        $x = $this->copy();
        $y = $y->copy();

        $x_sign = $x->is_negative;
        $y_sign = $y->is_negative;

        $x->is_negative = $y->is_negative = false;

        $diff = $x->compare($y);

        if (!$diff) {
            $temp = new static();
            $temp->value = array(1);
            $temp->is_negative = $x_sign != $y_sign;
            return array($this->_normalize($temp), $this->_normalize(new static()));
        }

        if ($diff < 0) {
            // if $x is negative, "add" $y.
            if ($x_sign) {
                $x = $y->subtract($x);
            }
            return array($this->_normalize(new static()), $this->_normalize($x));
        }

        // normalize $x and $y as described in HAC 14.23 / 14.24
        $msb = $y->value[count($y->value) - 1];
        for ($shift = 0; !($msb & self::$msb); ++$shift) {
            $msb <<= 1;
        }
        $x->_lshift($shift);
        $y->_lshift($shift);
        $y_value = &$y->value;

        $x_max = count($x->value) - 1;
        $y_max = count($y->value) - 1;

        $quotient = new static();
        $quotient_value = &$quotient->value;
        $quotient_value = $this->_array_repeat(0, $x_max - $y_max + 1);

        static $temp, $lhs, $rhs;
        if (!isset($temp)) {
            $temp = new static();
            $lhs =  new static();
            $rhs =  new static();
        }
        $temp_value = &$temp->value;
        $rhs_value =  &$rhs->value;

        // $temp = $y << ($x_max - $y_max-1) in base 2**26
        $temp_value = array_merge($this->_array_repeat(0, $x_max - $y_max), $y_value);

        while ($x->compare($temp) >= 0) {
            // calculate the "common residue"
            ++$quotient_value[$x_max - $y_max];
            $x = $x->subtract($temp);
            $x_max = count($x->value) - 1;
        }

        for ($i = $x_max; $i >= $y_max + 1; --$i) {
            $x_value = &$x->value;
            $x_window = array(
                isset($x_value[$i]) ? $x_value[$i] : 0,
                isset($x_value[$i - 1]) ? $x_value[$i - 1] : 0,
                isset($x_value[$i - 2]) ? $x_value[$i - 2] : 0
            );
            $y_window = array(
                $y_value[$y_max],
                ($y_max > 0) ? $y_value[$y_max - 1] : 0
            );

            $q_index = $i - $y_max - 1;
            if ($x_window[0] == $y_window[0]) {
                $quotient_value[$q_index] = self::$maxDigit;
            } else {
                $quotient_value[$q_index] = $this->_safe_divide(
                    $x_window[0] * self::$baseFull + $x_window[1],
                    $y_window[0]
                );
            }

            $temp_value = array($y_window[1], $y_window[0]);

            $lhs->value = array($quotient_value[$q_index]);
            $lhs = $lhs->multiply($temp);

            $rhs_value = array($x_window[2], $x_window[1], $x_window[0]);

            while ($lhs->compare($rhs) > 0) {
                --$quotient_value[$q_index];

                $lhs->value = array($quotient_value[$q_index]);
                $lhs = $lhs->multiply($temp);
            }

            $adjust = $this->_array_repeat(0, $q_index);
            $temp_value = array($quotient_value[$q_index]);
            $temp = $temp->multiply($y);
            $temp_value = &$temp->value;
            if (count($temp_value)) {
                $temp_value = array_merge($adjust, $temp_value);
            }

            $x = $x->subtract($temp);

            if ($x->compare($zero) < 0) {
                $temp_value = array_merge($adjust, $y_value);
                $x = $x->add($temp);

                --$quotient_value[$q_index];
            }

            $x_max = count($x_value) - 1;
        }

        // unnormalize the remainder
        $x->_rshift($shift);

        $quotient->is_negative = $x_sign != $y_sign;

        // calculate the "common residue", if appropriate
        if ($x_sign) {
            $y->_rshift($shift);
            $x = $y->subtract($x);
        }

        return array($this->_normalize($quotient), $this->_normalize($x));
    }

    /**
     * Divides a BigInteger by a regular integer
     *
     * abc / x = a00 / x + b0 / x + c / x
     *
     * @param array $dividend
     * @param float $divisor
     * @return array
     * @access private
     */
    function _divide_digit($dividend, $divisor)
    {
        $carry = 0;
        $result = array();

        for ($i = count($dividend) - 1; $i >= 0; --$i) {
            $temp = self::$baseFull * $carry + $dividend[$i];
            $result[$i] = $this->_safe_divide($temp, $divisor);
            $carry = (int) ($temp - $divisor * $result[$i]);
        }

        return array($result, $carry);
    }

    /**
     * Performs modular exponentiation.
     *
     * Here's an example:
     * <code>
     * <?php
     *    $a = new \phpseclib\Math\BigInteger('10');
     *    $b = new \phpseclib\Math\BigInteger('20');
     *    $c = new \phpseclib\Math\BigInteger('30');
     *
     *    $c = $a->modPow($b, $c);
     *
     *    echo $c->toString(); // outputs 10
     * ?>
     * </code>
     *
     * @param \phpseclib\Math\BigInteger $e
     * @param \phpseclib\Math\BigInteger $n
     * @return \phpseclib\Math\BigInteger
     * @access public
     * @internal The most naive approach to modular exponentiation has very unreasonable requirements, and
     *    and although the approach involving repeated squaring does vastly better, it, too, is impractical
     *    for our purposes.  The reason being that division - by far the most complicated and time-consuming
     *    of the basic operations (eg. +,-,*,/) - occurs multiple times within it.
     *
     *    Modular reductions resolve this issue.  Although an individual modular reduction takes more time
     *    then an individual division, when performed in succession (with the same modulo), they're a lot faster.
     *
     *    The two most commonly used modular reductions are Barrett and Montgomery reduction.  Montgomery reduction,
     *    although faster, only works when the gcd of the modulo and of the base being used is 1.  In RSA, when the
     *    base is a power of two, the modulo - a product of two primes - is always going to have a gcd of 1 (because
     *    the product of two odd numbers is odd), but what about when RSA isn't used?
     *
     *    In contrast, Barrett reduction has no such constraint.  As such, some bigint implementations perform a
     *    Barrett reduction after every operation in the modpow function.  Others perform Barrett reductions when the
     *    modulo is even and Montgomery reductions when the modulo is odd.  BigInteger.java's modPow method, however,
     *    uses a trick involving the Chinese Remainder Theorem to factor the even modulo into two numbers - one odd and
     *    the other, a power of two - and recombine them, later.  This is the method that this modPow function uses.
     *    {@link http://islab.oregonstate.edu/papers/j34monex.pdf Montgomery Reduction with Even Modulus} elaborates.
     */
    function modPow($e, $n)
    {
        $n = $this->bitmask !== false && $this->bitmask !== true && $this->bitmask->compare($n) < 0 ? $this->bitmask : $n->abs();

        if ($e->compare(new static()) < 0) {
            $e = $e->abs();

            $temp = $this->modInverse($n);
            if ($temp === false) {
                return false;
            }

            return $this->_normalize($temp->modPow($e, $n));
        }

        if (MATH_BIGINTEGER_MODE == self::MODE_GMP) {
            $temp = new static();
            $temp->value = gmp_powm($this->value, $e->value, $n->value);

            return $this->_normalize($temp);
        }

        if ($this->compare(new static()) < 0 || $this->compare($n) > 0) {
            list(, $temp) = $this->divide($n);
            return $temp->modPow($e, $n);
        }

        if (defined('MATH_BIGINTEGER_OPENSSL_ENABLED')) {
            $components = array(
                'modulus' => $n->toBytes(true),
                'publicExponent' => $e->toBytes(true)
            );

            $components = array(
                'modulus' => pack('Ca*a*', 2, $this->_encodeASN1Length(strlen($components['modulus'])), $components['modulus']),
                'publicExponent' => pack('Ca*a*', 2, $this->_encodeASN1Length(strlen($components['publicExponent'])), $components['publicExponent'])
            );

            $RSAPublicKey = pack(
                'Ca*a*a*',
                48,
                $this->_encodeASN1Length(strlen($components['modulus']) + strlen($components['publicExponent'])),
                $components['modulus'],
                $components['publicExponent']
            );

            $rsaOID = pack('H*', '300d06092a864886f70d0101010500'); // hex version of MA0GCSqGSIb3DQEBAQUA
            $RSAPublicKey = chr(0) . $RSAPublicKey;
            $RSAPublicKey = chr(3) . $this->_encodeASN1Length(strlen($RSAPublicKey)) . $RSAPublicKey;

            $encapsulated = pack(
                'Ca*a*',
                48,
                $this->_encodeASN1Length(strlen($rsaOID . $RSAPublicKey)),
                $rsaOID . $RSAPublicKey
            );

            $RSAPublicKey = "-----BEGIN PUBLIC KEY-----\r\n" .
                chunk_split(base64_encode($encapsulated)) .
                '-----END PUBLIC KEY-----';

            $plaintext = str_pad($this->toBytes(), strlen($n->toBytes(true)) - 1, "\0", STR_PAD_LEFT);

            if (openssl_public_encrypt($plaintext, $result, $RSAPublicKey, OPENSSL_NO_PADDING)) {
                return new static($result, 256);
            }
        }

        if (MATH_BIGINTEGER_MODE == self::MODE_BCMATH) {
            $temp = new static();
            $temp->value = bcpowmod($this->value, $e->value, $n->value, 0);

            return $this->_normalize($temp);
        }

        if (empty($e->value)) {
            $temp = new static();
            $temp->value = array(1);
            return $this->_normalize($temp);
        }

        if ($e->value == array(1)) {
            list(, $temp) = $this->divide($n);
            return $this->_normalize($temp);
        }

        if ($e->value == array(2)) {
            $temp = new static();
            $temp->value = $this->_square($this->value);
            list(, $temp) = $temp->divide($n);
            return $this->_normalize($temp);
        }

        return $this->_normalize($this->_slidingWindow($e, $n, self::BARRETT));

        // the following code, although not callable, can be run independently of the above code
        // although the above code performed better in my benchmarks the following could might
        // perform better under different circumstances. in lieu of deleting it it's just been
        // made uncallable

        // is the modulo odd?
        if ($n->value[0] & 1) {
            return $this->_normalize($this->_slidingWindow($e, $n, self::MONTGOMERY));
        }
        // if it's not, it's even

        // find the lowest set bit (eg. the max pow of 2 that divides $n)
        for ($i = 0; $i < count($n->value); ++$i) {
            if ($n->value[$i]) {
                $temp = decbin($n->value[$i]);
                $j = strlen($temp) - strrpos($temp, '1') - 1;
                $j+= 26 * $i;
                break;
            }
        }
        // at this point, 2^$j * $n/(2^$j) == $n

        $mod1 = $n->copy();
        $mod1->_rshift($j);
        $mod2 = new static();
        $mod2->value = array(1);
        $mod2->_lshift($j);

        $part1 = ($mod1->value != array(1)) ? $this->_slidingWindow($e, $mod1, self::MONTGOMERY) : new static();
        $part2 = $this->_slidingWindow($e, $mod2, self::POWEROF2);

        $y1 = $mod2->modInverse($mod1);
        $y2 = $mod1->modInverse($mod2);

        $result = $part1->multiply($mod2);
        $result = $result->multiply($y1);

        $temp = $part2->multiply($mod1);
        $temp = $temp->multiply($y2);

        $result = $result->add($temp);
        list(, $result) = $result->divide($n);

        return $this->_normalize($result);
    }

    /**
     * Performs modular exponentiation.
     *
     * Alias for modPow().
     *
     * @param \phpseclib\Math\BigInteger $e
     * @param \phpseclib\Math\BigInteger $n
     * @return \phpseclib\Math\BigInteger
     * @access public
     */
    function powMod($e, $n)
    {
        return $this->modPow($e, $n);
    }

    /**
     * Sliding Window k-ary Modular Exponentiation
     *
     * Based on {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf#page=27 HAC 14.85} /
     * {@link http://math.libtomcrypt.com/files/tommath.pdf#page=210 MPM 7.7}.  In a departure from those algorithims,
     * however, this function performs a modular reduction after every multiplication and squaring operation.
     * As such, this function has the same preconditions that the reductions being used do.
     *
     * @param \phpseclib\Math\BigInteger $e
     * @param \phpseclib\Math\BigInteger $n
     * @param int $mode
     * @return \phpseclib\Math\BigInteger
     * @access private
     */
    function _slidingWindow($e, $n, $mode)
    {
        static $window_ranges = array(7, 25, 81, 241, 673, 1793); // from BigInteger.java's oddModPow function
        //static $window_ranges = array(0, 7, 36, 140, 450, 1303, 3529); // from MPM 7.3.1

        $e_value = $e->value;
        $e_length = count($e_value) - 1;
        $e_bits = decbin($e_value[$e_length]);
        for ($i = $e_length - 1; $i >= 0; --$i) {
            $e_bits.= str_pad(decbin($e_value[$i]), self::$base, '0', STR_PAD_LEFT);
        }

        $e_length = strlen($e_bits);

        // calculate the appropriate window size.
        // $window_size == 3 if $window_ranges is between 25 and 81, for example.
        for ($i = 0, $window_size = 1; $i < count($window_ranges) && $e_length > $window_ranges[$i]; ++$window_size, ++$i) {
        }

        $n_value = $n->value;

        // precompute $this^0 through $this^$window_size
        $powers = array();
        $powers[1] = $this->_prepareReduce($this->value, $n_value, $mode);
        $powers[2] = $this->_squareReduce($powers[1], $n_value, $mode);

        // we do every other number since substr($e_bits, $i, $j+1) (see below) is supposed to end
        // in a 1.  ie. it's supposed to be odd.
        $temp = 1 << ($window_size - 1);
        for ($i = 1; $i < $temp; ++$i) {
            $i2 = $i << 1;
            $powers[$i2 + 1] = $this->_multiplyReduce($powers[$i2 - 1], $powers[2], $n_value, $mode);
        }

        $result = array(1);
        $result = $this->_prepareReduce($result, $n_value, $mode);

        for ($i = 0; $i < $e_length;) {
            if (!$e_bits[$i]) {
                $result = $this->_squareReduce($result, $n_value, $mode);
                ++$i;
            } else {
                for ($j = $window_size - 1; $j > 0; --$j) {
                    if (!empty($e_bits[$i + $j])) {
                        break;
                    }
                }

                // eg. the length of substr($e_bits, $i, $j + 1)
                for ($k = 0; $k <= $j; ++$k) {
                    $result = $this->_squareReduce($result, $n_value, $mode);
                }

                $result = $this->_multiplyReduce($result, $powers[bindec(substr($e_bits, $i, $j + 1))], $n_value, $mode);

                $i += $j + 1;
            }
        }

        $temp = new static();
        $temp->value = $this->_reduce($result, $n_value, $mode);

        return $temp;
    }

    /**
     * Modular reduction
     *
     * For most $modes this will return the remainder.
     *
     * @see self::_slidingWindow()
     * @access private
//     * @param array $x
//     * @param array $n
//     * @param int $mode
//     * @return array
     */
    function _reduce($x, $n, $mode)
    {
        switch ($mode) {
            case self::MONTGOMERY:
                return $this->_montgomery($x, $n);
            case self::BARRETT:
                return $this->_barrett($x, $n);
            case self::POWEROF2:
                $lhs = new static();
                $lhs->value = $x;
                $rhs = new static();
                $rhs->value = $n;
                return $x->_mod2($n);
            case self::CLASSIC:
                $lhs = new static();
                $lhs->value = $x;
                $rhs = new static();
                $rhs->value = $n;
                list(, $temp) = $lhs->divide($rhs);
                return $temp->value;
            case self::NONE:
                return $x;
            default:
                // an invalid $mode was provided
        }
    }

    /**
     * Modular reduction preperation
     *
     * @see self::_slidingWindow()
     * @access private
     * @param array $x
     * @param array $n
     * @param int $mode
     * @return array
     */
    function _prepareReduce($x, $n, $mode)
    {
        if ($mode == self::MONTGOMERY) {
            return $this->_prepMontgomery($x, $n);
        }
        return $this->_reduce($x, $n, $mode);
    }

    /**
     * Modular multiply
     *
     * @see self::_slidingWindow()
     * @access private
     * @param array $x
     * @param array $y
     * @param array $n
     * @param int $mode
     * @return array
     */
    function _multiplyReduce($x, $y, $n, $mode)
    {
        if ($mode == self::MONTGOMERY) {
            return $this->_montgomeryMultiply($x, $y, $n);
        }
        $temp = $this->_multiply($x, false, $y, false);
        return $this->_reduce($temp[self::VALUE], $n, $mode);
    }

    /**
     * Modular square
     *
     * @see self::_slidingWindow()
     * @access private
     * @param array $x
     * @param array $n
     * @param int $mode
     * @return array
     */
    function _squareReduce($x, $n, $mode)
    {
        if ($mode == self::MONTGOMERY) {
            return $this->_montgomeryMultiply($x, $x, $n);
        }
        return $this->_reduce($this->_square($x), $n, $mode);
    }

    /**
     * Modulos for Powers of Two
     *
     * Calculates $x%$n, where $n = 2**$e, for some $e.  Since this is basically the same as doing $x & ($n-1),
     * we'll just use this function as a wrapper for doing that.
     *
     * @see self::_slidingWindow()
     * @access private
     * @param \phpseclib\Math\BigInteger $n
     * @return \phpseclib\Math\BigInteger
     */
    function _mod2($n)
    {
        $temp = new static();
        $temp->value = array(1);
        return $this->bitwise_and($n->subtract($temp));
    }

    /**
     * Barrett Modular Reduction
     *
     * See {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf#page=14 HAC 14.3.3} /
     * {@link http://math.libtomcrypt.com/files/tommath.pdf#page=165 MPM 6.2.5} for more information.  Modified slightly,
     * so as not to require negative numbers (initially, this script didn't support negative numbers).
     *
     * Employs "folding", as described at
     * {@link http://www.cosic.esat.kuleuven.be/publications/thesis-149.pdf#page=66 thesis-149.pdf#page=66}.  To quote from
     * it, "the idea [behind folding] is to find a value x' such that x (mod m) = x' (mod m), with x' being smaller than x."
     *
     * Unfortunately, the "Barrett Reduction with Folding" algorithm described in thesis-149.pdf is not, as written, all that
     * usable on account of (1) its not using reasonable radix points as discussed in
     * {@link http://math.libtomcrypt.com/files/tommath.pdf#page=162 MPM 6.2.2} and (2) the fact that, even with reasonable
     * radix points, it only works when there are an even number of digits in the denominator.  The reason for (2) is that
     * (x >> 1) + (x >> 1) != x / 2 + x / 2.  If x is even, they're the same, but if x is odd, they're not.  See the in-line
     * comments for details.
     *
     * @see self::_slidingWindow()
     * @access private
     * @param array $n
     * @param array $m
     * @return array
     */
    function _barrett($n, $m)
    {
        static $cache = array(
            self::VARIABLE => array(),
            self::DATA => array()
        );

        $m_length = count($m);

        // if ($this->_compare($n, $this->_square($m)) >= 0) {
        if (count($n) > 2 * $m_length) {
            $lhs = new static();
            $rhs = new static();
            $lhs->value = $n;
            $rhs->value = $m;
            list(, $temp) = $lhs->divide($rhs);
            return $temp->value;
        }

        // if (m.length >> 1) + 2 <= m.length then m is too small and n can't be reduced
        if ($m_length < 5) {
            return $this->_regularBarrett($n, $m);
        }

        // n = 2 * m.length

        if (($key = array_search($m, $cache[self::VARIABLE])) === false) {
            $key = count($cache[self::VARIABLE]);
            $cache[self::VARIABLE][] = $m;

            $lhs = new static();
            $lhs_value = &$lhs->value;
            $lhs_value = $this->_array_repeat(0, $m_length + ($m_length >> 1));
            $lhs_value[] = 1;
            $rhs = new static();
            $rhs->value = $m;

            list($u, $m1) = $lhs->divide($rhs);
            $u = $u->value;
            $m1 = $m1->value;

            $cache[self::DATA][] = array(
                'u' => $u, // m.length >> 1 (technically (m.length >> 1) + 1)
                'm1'=> $m1 // m.length
            );
        } else {
            extract($cache[self::DATA][$key]);
        }

        $cutoff = $m_length + ($m_length >> 1);
        $lsd = array_slice($n, 0, $cutoff); // m.length + (m.length >> 1)
        $msd = array_slice($n, $cutoff);    // m.length >> 1
        $lsd = $this->_trim($lsd);
        $temp = $this->_multiply($msd, false, $m1, false);
        $n = $this->_add($lsd, false, $temp[self::VALUE], false); // m.length + (m.length >> 1) + 1

        if ($m_length & 1) {
            return $this->_regularBarrett($n[self::VALUE], $m);
        }

        // (m.length + (m.length >> 1) + 1) - (m.length - 1) == (m.length >> 1) + 2
        $temp = array_slice($n[self::VALUE], $m_length - 1);
        // if even: ((m.length >> 1) + 2) + (m.length >> 1) == m.length + 2
        // if odd:  ((m.length >> 1) + 2) + (m.length >> 1) == (m.length - 1) + 2 == m.length + 1
        $temp = $this->_multiply($temp, false, $u, false);
        // if even: (m.length + 2) - ((m.length >> 1) + 1) = m.length - (m.length >> 1) + 1
        // if odd:  (m.length + 1) - ((m.length >> 1) + 1) = m.length - (m.length >> 1)
        $temp = array_slice($temp[self::VALUE], ($m_length >> 1) + 1);
        // if even: (m.length - (m.length >> 1) + 1) + m.length = 2 * m.length - (m.length >> 1) + 1
        // if odd:  (m.length - (m.length >> 1)) + m.length     = 2 * m.length - (m.length >> 1)
        $temp = $this->_multiply($temp, false, $m, false);

        // at this point, if m had an odd number of digits, we'd be subtracting a 2 * m.length - (m.length >> 1) digit
        // number from a m.length + (m.length >> 1) + 1 digit number.  ie. there'd be an extra digit and the while loop
        // following this comment would loop a lot (hence our calling _regularBarrett() in that situation).

        $result = $this->_subtract($n[self::VALUE], false, $temp[self::VALUE], false);

        while ($this->_compare($result[self::VALUE], $result[self::SIGN], $m, false) >= 0) {
            $result = $this->_subtract($result[self::VALUE], $result[self::SIGN], $m, false);
        }

        return $result[self::VALUE];
    }

    /**
     * (Regular) Barrett Modular Reduction
     *
     * For numbers with more than four digits BigInteger::_barrett() is faster.  The difference between that and this
     * is that this function does not fold the denominator into a smaller form.
     *
     * @see self::_slidingWindow()
     * @access private
     * @param array $x
     * @param array $n
     * @return array
     */
    function _regularBarrett($x, $n)
    {
        static $cache = array(
            self::VARIABLE => array(),
            self::DATA => array()
        );

        $n_length = count($n);

        if (count($x) > 2 * $n_length) {
            $lhs = new static();
            $rhs = new static();
            $lhs->value = $x;
            $rhs->value = $n;
            list(, $temp) = $lhs->divide($rhs);
            return $temp->value;
        }

        if (($key = array_search($n, $cache[self::VARIABLE])) === false) {
            $key = count($cache[self::VARIABLE]);
            $cache[self::VARIABLE][] = $n;
            $lhs = new static();
            $lhs_value = &$lhs->value;
            $lhs_value = $this->_array_repeat(0, 2 * $n_length);
            $lhs_value[] = 1;
            $rhs = new static();
            $rhs->value = $n;
            list($temp, ) = $lhs->divide($rhs); // m.length
            $cache[self::DATA][] = $temp->value;
        }

        // 2 * m.length - (m.length - 1) = m.length + 1
        $temp = array_slice($x, $n_length - 1);
        // (m.length + 1) + m.length = 2 * m.length + 1
        $temp = $this->_multiply($temp, false, $cache[self::DATA][$key], false);
        // (2 * m.length + 1) - (m.length - 1) = m.length + 2
        $temp = array_slice($temp[self::VALUE], $n_length + 1);

        // m.length + 1
        $result = array_slice($x, 0, $n_length + 1);
        // m.length + 1
        $temp = $this->_multiplyLower($temp, false, $n, false, $n_length + 1);
        // $temp == array_slice($temp->_multiply($temp, false, $n, false)->value, 0, $n_length + 1)

        if ($this->_compare($result, false, $temp[self::VALUE], $temp[self::SIGN]) < 0) {
            $corrector_value = $this->_array_repeat(0, $n_length + 1);
            $corrector_value[count($corrector_value)] = 1;
            $result = $this->_add($result, false, $corrector_value, false);
            $result = $result[self::VALUE];
        }

        // at this point, we're subtracting a number with m.length + 1 digits from another number with m.length + 1 digits
        $result = $this->_subtract($result, false, $temp[self::VALUE], $temp[self::SIGN]);
        while ($this->_compare($result[self::VALUE], $result[self::SIGN], $n, false) > 0) {
            $result = $this->_subtract($result[self::VALUE], $result[self::SIGN], $n, false);
        }

        return $result[self::VALUE];
    }

    /**
     * Performs long multiplication up to $stop digits
     *
     * If you're going to be doing array_slice($product->value, 0, $stop), some cycles can be saved.
     *
     * @see self::_regularBarrett()
     * @param array $x_value
     * @param bool $x_negative
     * @param array $y_value
     * @param bool $y_negative
     * @param int $stop
     * @return array
     * @access private
     */
    function _multiplyLower($x_value, $x_negative, $y_value, $y_negative, $stop)
    {
        $x_length = count($x_value);
        $y_length = count($y_value);

        if (!$x_length || !$y_length) { // a 0 is being multiplied
            return array(
                self::VALUE => array(),
                self::SIGN => false
            );
        }

        if ($x_length < $y_length) {
            $temp = $x_value;
            $x_value = $y_value;
            $y_value = $temp;

            $x_length = count($x_value);
            $y_length = count($y_value);
        }

        $product_value = $this->_array_repeat(0, $x_length + $y_length);

        // the following for loop could be removed if the for loop following it
        // (the one with nested for loops) initially set $i to 0, but
        // doing so would also make the result in one set of unnecessary adds,
        // since on the outermost loops first pass, $product->value[$k] is going
        // to always be 0

        $carry = 0;

        for ($j = 0; $j < $x_length; ++$j) { // ie. $i = 0, $k = $i
            $temp = $x_value[$j] * $y_value[0] + $carry; // $product_value[$k] == 0
            $carry = self::$base === 26 ? intval($temp / 0x4000000) : ($temp >> 31);
            $product_value[$j] = (int) ($temp - self::$baseFull * $carry);
        }

        if ($j < $stop) {
            $product_value[$j] = $carry;
        }

        // the above for loop is what the previous comment was talking about.  the
        // following for loop is the "one with nested for loops"

        for ($i = 1; $i < $y_length; ++$i) {
            $carry = 0;

            for ($j = 0, $k = $i; $j < $x_length && $k < $stop; ++$j, ++$k) {
                $temp = $product_value[$k] + $x_value[$j] * $y_value[$i] + $carry;
                $carry = self::$base === 26 ? intval($temp / 0x4000000) : ($temp >> 31);
                $product_value[$k] = (int) ($temp - self::$baseFull * $carry);
            }

            if ($k < $stop) {
                $product_value[$k] = $carry;
            }
        }

        return array(
            self::VALUE => $this->_trim($product_value),
            self::SIGN => $x_negative != $y_negative
        );
    }

    /**
     * Montgomery Modular Reduction
     *
     * ($x->_prepMontgomery($n))->_montgomery($n) yields $x % $n.
     * {@link http://math.libtomcrypt.com/files/tommath.pdf#page=170 MPM 6.3} provides insights on how this can be
     * improved upon (basically, by using the comba method).  gcd($n, 2) must be equal to one for this function
     * to work correctly.
     *
     * @see self::_prepMontgomery()
     * @see self::_slidingWindow()
     * @access private
     * @param array $x
     * @param array $n
     * @return array
     */
    function _montgomery($x, $n)
    {
        static $cache = array(
            self::VARIABLE => array(),
            self::DATA => array()
        );

        if (($key = array_search($n, $cache[self::VARIABLE])) === false) {
            $key = count($cache[self::VARIABLE]);
            $cache[self::VARIABLE][] = $x;
            $cache[self::DATA][] = $this->_modInverse67108864($n);
        }

        $k = count($n);

        $result = array(self::VALUE => $x);

        for ($i = 0; $i < $k; ++$i) {
            $temp = $result[self::VALUE][$i] * $cache[self::DATA][$key];
            $temp = $temp - self::$baseFull * (self::$base === 26 ? intval($temp / 0x4000000) : ($temp >> 31));
            $temp = $this->_regularMultiply(array($temp), $n);
            $temp = array_merge($this->_array_repeat(0, $i), $temp);
            $result = $this->_add($result[self::VALUE], false, $temp, false);
        }

        $result[self::VALUE] = array_slice($result[self::VALUE], $k);

        if ($this->_compare($result, false, $n, false) >= 0) {
            $result = $this->_subtract($result[self::VALUE], false, $n, false);
        }

        return $result[self::VALUE];
    }

    /**
     * Montgomery Multiply
     *
     * Interleaves the montgomery reduction and long multiplication algorithms together as described in
     * {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf#page=13 HAC 14.36}
     *
     * @see self::_prepMontgomery()
     * @see self::_montgomery()
     * @access private
     * @param array $x
     * @param array $y
     * @param array $m
     * @return array
     */
    function _montgomeryMultiply($x, $y, $m)
    {
        $temp = $this->_multiply($x, false, $y, false);
        return $this->_montgomery($temp[self::VALUE], $m);

        // the following code, although not callable, can be run independently of the above code
        // although the above code performed better in my benchmarks the following could might
        // perform better under different circumstances. in lieu of deleting it it's just been
        // made uncallable

        static $cache = array(
            self::VARIABLE => array(),
            self::DATA => array()
        );

        if (($key = array_search($m, $cache[self::VARIABLE])) === false) {
            $key = count($cache[self::VARIABLE]);
            $cache[self::VARIABLE][] = $m;
            $cache[self::DATA][] = $this->_modInverse67108864($m);
        }

        $n = max(count($x), count($y), count($m));
        $x = array_pad($x, $n, 0);
        $y = array_pad($y, $n, 0);
        $m = array_pad($m, $n, 0);
        $a = array(self::VALUE => $this->_array_repeat(0, $n + 1));
        for ($i = 0; $i < $n; ++$i) {
            $temp = $a[self::VALUE][0] + $x[$i] * $y[0];
            $temp = $temp - self::$baseFull * (self::$base === 26 ? intval($temp / 0x4000000) : ($temp >> 31));
            $temp = $temp * $cache[self::DATA][$key];
            $temp = $temp - self::$baseFull * (self::$base === 26 ? intval($temp / 0x4000000) : ($temp >> 31));
            $temp = $this->_add($this->_regularMultiply(array($x[$i]), $y), false, $this->_regularMultiply(array($temp), $m), false);
            $a = $this->_add($a[self::VALUE], false, $temp[self::VALUE], false);
            $a[self::VALUE] = array_slice($a[self::VALUE], 1);
        }
        if ($this->_compare($a[self::VALUE], false, $m, false) >= 0) {
            $a = $this->_subtract($a[self::VALUE], false, $m, false);
        }
        return $a[self::VALUE];
    }

    /**
     * Prepare a number for use in Montgomery Modular Reductions
     *
     * @see self::_montgomery()
     * @see self::_slidingWindow()
     * @access private
     * @param array $x
     * @param array $n
     * @return array
     */
    function _prepMontgomery($x, $n)
    {
        $lhs = new static();
        $lhs->value = array_merge($this->_array_repeat(0, count($n)), $x);
        $rhs = new static();
        $rhs->value = $n;

        list(, $temp) = $lhs->divide($rhs);
        return $temp->value;
    }

    /**
     * Modular Inverse of a number mod 2**26 (eg. 67108864)
     *
     * Based off of the bnpInvDigit function implemented and justified in the following URL:
     *
     * {@link http://www-cs-students.stanford.edu/~tjw/jsbn/jsbn.js}
     *
     * The following URL provides more info:
     *
     * {@link http://groups.google.com/group/sci.crypt/msg/7a137205c1be7d85}
     *
     * As for why we do all the bitmasking...  strange things can happen when converting from floats to ints. For
     * instance, on some computers, var_dump((int) -4294967297) yields int(-1) and on others, it yields
     * int(-2147483648).  To avoid problems stemming from this, we use bitmasks to guarantee that ints aren't
     * auto-converted to floats.  The outermost bitmask is present because without it, there's no guarantee that
     * the "residue" returned would be the so-called "common residue".  We use fmod, in the last step, because the
     * maximum possible $x is 26 bits and the maximum $result is 16 bits.  Thus, we have to be able to handle up to
     * 40 bits, which only 64-bit floating points will support.
     *
     * Thanks to Pedro Gimeno Fortea for input!
     *
     * @see self::_montgomery()
     * @access private
     * @param array $x
     * @return int
     */
    function _modInverse67108864($x) // 2**26 == 67,108,864
    {
        $x = -$x[0];
        $result = $x & 0x3; // x**-1 mod 2**2
        $result = ($result * (2 - $x * $result)) & 0xF; // x**-1 mod 2**4
        $result = ($result * (2 - ($x & 0xFF) * $result))  & 0xFF; // x**-1 mod 2**8
        $result = ($result * ((2 - ($x & 0xFFFF) * $result) & 0xFFFF)) & 0xFFFF; // x**-1 mod 2**16
        $result = fmod($result * (2 - fmod($x * $result, self::$baseFull)), self::$baseFull); // x**-1 mod 2**26
        return $result & self::$maxDigit;
    }

    /**
     * Calculates modular inverses.
     *
     * Say you have (30 mod 17 * x mod 17) mod 17 == 1.  x can be found using modular inverses.
     *
     * Here's an example:
     * <code>
     * <?php
     *    $a = new \phpseclib\Math\BigInteger(30);
     *    $b = new \phpseclib\Math\BigInteger(17);
     *
     *    $c = $a->modInverse($b);
     *    echo $c->toString(); // outputs 4
     *
     *    echo "\r\n";
     *
     *    $d = $a->multiply($c);
     *    list(, $d) = $d->divide($b);
     *    echo $d; // outputs 1 (as per the definition of modular inverse)
     * ?>
     * </code>
     *
     * @param \phpseclib\Math\BigInteger $n
     * @return \phpseclib\Math\BigInteger|false
     * @access public
     * @internal See {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf#page=21 HAC 14.64} for more information.
     */
    function modInverse($n)
    {
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                $temp = new static();
                $temp->value = gmp_invert($this->value, $n->value);

                return ($temp->value === false) ? false : $this->_normalize($temp);
        }

        static $zero, $one;
        if (!isset($zero)) {
            $zero = new static();
            $one = new static(1);
        }

        // $x mod -$n == $x mod $n.
        $n = $n->abs();

        if ($this->compare($zero) < 0) {
            $temp = $this->abs();
            $temp = $temp->modInverse($n);
            return $this->_normalize($n->subtract($temp));
        }

        extract($this->extendedGCD($n));

        if (isset($gcd) && !$gcd->equals($one)) {
            return false;
        }

        $x = isset($x) ? ($x->compare($zero) < 0 ? $x->add($n) : $x) : null;

        return $this->compare($zero) < 0 ? $this->_normalize($n->subtract($x)) : $this->_normalize($x);
    }

    /**
     * Calculates the greatest common divisor and Bezout's identity.
     *
     * Say you have 693 and 609.  The GCD is 21.  Bezout's identity states that there exist integers x and y such that
     * 693*x + 609*y == 21.  In point of fact, there are actually an infinite number of x and y combinations and which
     * combination is returned is dependent upon which mode is in use.  See
     * {@link http://en.wikipedia.org/wiki/B%C3%A9zout%27s_identity Bezout's identity - Wikipedia} for more information.
     *
     * Here's an example:
     * <code>
     * <?php
     *    $a = new \phpseclib\Math\BigInteger(693);
     *    $b = new \phpseclib\Math\BigInteger(609);
     *
     *    extract($a->extendedGCD($b));
     *
     *    echo $gcd->toString() . "\r\n"; // outputs 21
     *    echo $a->toString() * $x->toString() + $b->toString() * $y->toString(); // outputs 21
     * ?>
     * </code>
     *
//     * @param \phpseclib\Math\BigInteger $n
//     * @return \phpseclib\Math\BigInteger
     * @access public
     * @internal Calculates the GCD using the binary xGCD algorithim described in
     *    {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap14.pdf#page=19 HAC 14.61}.  As the text above 14.61 notes,
     *    the more traditional algorithim requires "relatively costly multiple-precision divisions".
     */
    function extendedGCD($n)
    {
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                extract(gmp_gcdext($this->value, $n->value));

                return array(
                    'gcd' => $this->_normalize(new static(isset($g) ? $g : 0)),
                    'x'   => $this->_normalize(new static(isset($s) ? $s : 0)),
                    'y'   => $this->_normalize(new static(isset($t) ? $t : 0))
                );
            case self::MODE_BCMATH:
                // it might be faster to use the binary xGCD algorithim here, as well, but (1) that algorithim works
                // best when the base is a power of 2 and (2) i don't think it'd make much difference, anyway.  as is,
                // the basic extended euclidean algorithim is what we're using.

                $u = $this->value;
                $v = $n->value;

                $a = '1';
                $b = '0';
                $c = '0';
                $d = '1';

                while (bccomp($v, '0', 0) != 0) {
                    $q = bcdiv($u, $v, 0);

                    $temp = $u;
                    $u = $v;
                    $v = bcsub($temp, bcmul($v, $q, 0), 0);

                    $temp = $a;
                    $a = $c;
                    $c = bcsub($temp, bcmul($a, $q, 0), 0);

                    $temp = $b;
                    $b = $d;
                    $d = bcsub($temp, bcmul($b, $q, 0), 0);
                }

                return array(
                    'gcd' => $this->_normalize(new static($u)),
                    'x'   => $this->_normalize(new static($a)),
                    'y'   => $this->_normalize(new static($b))
                );
        }

        $y = $n->copy();
        $x = $this->copy();
        $g = new static();
        $g->value = array(1);

        while (!(($x->value[0] & 1)|| ($y->value[0] & 1))) {
            $x->_rshift(1);
            $y->_rshift(1);
            $g->_lshift(1);
        }

        $u = $x->copy();
        $v = $y->copy();

        $a = new static();
        $b = new static();
        $c = new static();
        $d = new static();

        $a->value = $d->value = $g->value = array(1);
        $b->value = $c->value = array();

        while (!empty($u->value)) {
            while (!($u->value[0] & 1)) {
                $u->_rshift(1);
                if ((!empty($a->value) && ($a->value[0] & 1)) || (!empty($b->value) && ($b->value[0] & 1))) {
                    $a = $a->add($y);
                    $b = $b->subtract($x);
                }
                $a->_rshift(1);
                $b->_rshift(1);
            }

            while (!($v->value[0] & 1)) {
                $v->_rshift(1);
                if ((!empty($d->value) && ($d->value[0] & 1)) || (!empty($c->value) && ($c->value[0] & 1))) {
                    $c = $c->add($y);
                    $d = $d->subtract($x);
                }
                $c->_rshift(1);
                $d->_rshift(1);
            }

            if ($u->compare($v) >= 0) {
                $u = $u->subtract($v);
                $a = $a->subtract($c);
                $b = $b->subtract($d);
            } else {
                $v = $v->subtract($u);
                $c = $c->subtract($a);
                $d = $d->subtract($b);
            }
        }

        return array(
            'gcd' => $this->_normalize($g->multiply($v)),
            'x'   => $this->_normalize($c),
            'y'   => $this->_normalize($d)
        );
    }

    /**
     * Calculates the greatest common divisor
     *
     * Say you have 693 and 609.  The GCD is 21.
     *
     * Here's an example:
     * <code>
     * <?php
     *    $a = new \phpseclib\Math\BigInteger(693);
     *    $b = new \phpseclib\Math\BigInteger(609);
     *
     *    $gcd = a->extendedGCD($b);
     *
     *    echo $gcd->toString() . "\r\n"; // outputs 21
     * ?>
     * </code>
     *
     * @param \phpseclib\Math\BigInteger $n
     * @return \phpseclib\Math\BigInteger
     * @access public
     */
    function gcd($n)
    {
        extract($this->extendedGCD($n));
        return isset($gcd) ? $gcd : null;
    }

    /**
     * Absolute value.
     *
     * @return \phpseclib\Math\BigInteger
     * @access public
     */
    function abs()
    {
        $temp = new static();

        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                $temp->value = gmp_abs($this->value);
                break;
            case self::MODE_BCMATH:
                $temp->value = (bccomp($this->value, '0', 0) < 0) ? substr($this->value, 1) : $this->value;
                break;
            default:
                $temp->value = $this->value;
        }

        return $temp;
    }

    /**
     * Compares two numbers.
     *
     * Although one might think !$x->compare($y) means $x != $y, it, in fact, means the opposite.  The reason for this is
     * demonstrated thusly:
     *
     * $x  > $y: $x->compare($y)  > 0
     * $x  < $y: $x->compare($y)  < 0
     * $x == $y: $x->compare($y) == 0
     *
     * Note how the same comparison operator is used.  If you want to test for equality, use $x->equals($y).
     *
     * @param \phpseclib\Math\BigInteger $y
     * @return int that is < 0 if $this is less than $y; > 0 if $this is greater than $y, and 0 if they are equal.
     * @access public
     * @see self::equals()
     * @internal Could return $this->subtract($x), but that's not as fast as what we do do.
     */
    function compare($y)
    {
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                $r = gmp_cmp($this->value, $y->value);
                if ($r < -1) {
                    $r = -1;
                }
                if ($r > 1) {
                    $r = 1;
                }
                return $r;
            case self::MODE_BCMATH:
                return bccomp($this->value, $y->value, 0);
        }

        return $this->_compare($this->value, $this->is_negative, $y->value, $y->is_negative);
    }

    /**
     * Compares two numbers.
     *
     * @param array $x_value
     * @param bool $x_negative
     * @param array $y_value
     * @param bool $y_negative
     * @return int
     * @see self::compare()
     * @access private
     */
    function _compare($x_value, $x_negative, $y_value, $y_negative)
    {
        if ($x_negative != $y_negative) {
            return (!$x_negative && $y_negative) ? 1 : -1;
        }

        $result = $x_negative ? -1 : 1;

        if (count($x_value) != count($y_value)) {
            return (count($x_value) > count($y_value)) ? $result : -$result;
        }
        $size = max(count($x_value), count($y_value));

        $x_value = array_pad($x_value, $size, 0);
        $y_value = array_pad($y_value, $size, 0);

        for ($i = count($x_value) - 1; $i >= 0; --$i) {
            if ($x_value[$i] != $y_value[$i]) {
                return ($x_value[$i] > $y_value[$i]) ? $result : -$result;
            }
        }

        return 0;
    }

    /**
     * Tests the equality of two numbers.
     *
     * If you need to see if one number is greater than or less than another number, use BigInteger::compare()
     *
     * @param \phpseclib\Math\BigInteger $x
     * @return bool
     * @access public
     * @see self::compare()
     */
    function equals($x)
    {
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                return gmp_cmp($this->value, $x->value) == 0;
            default:
                return $this->value === $x->value && $this->is_negative == $x->is_negative;
        }
    }

    /**
     * Set Precision
     *
     * Some bitwise operations give different results depending on the precision being used.  Examples include left
     * shift, not, and rotates.
     *
     * @param int $bits
     * @access public
     */
    function setPrecision($bits)
    {
        $this->precision = $bits;
        if (MATH_BIGINTEGER_MODE != self::MODE_BCMATH) {
            $this->bitmask = new static(chr((1 << ($bits & 0x7)) - 1) . str_repeat(chr(0xFF), $bits >> 3), 256);
        } else {
            $this->bitmask = new static(bcpow('2', $bits, 0));
        }

        $temp = $this->_normalize($this);
        $this->value = $temp->value;
    }

    /**
     * Logical And
     *
     * @param \phpseclib\Math\BigInteger $x
     * @access public
     * @internal Implemented per a request by Lluis Pamies i Juarez <lluis _a_ pamies.cat>
     * @return \phpseclib\Math\BigInteger
     */
    function bitwise_and($x)
    {
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                $temp = new static();
                $temp->value = gmp_and($this->value, $x->value);

                return $this->_normalize($temp);
            case self::MODE_BCMATH:
                $left = $this->toBytes();
                $right = $x->toBytes();

                $length = max(strlen($left), strlen($right));

                $left = str_pad($left, $length, chr(0), STR_PAD_LEFT);
                $right = str_pad($right, $length, chr(0), STR_PAD_LEFT);

                return $this->_normalize(new static($left & $right, 256));
        }

        $result = $this->copy();

        $length = min(count($x->value), count($this->value));

        $result->value = array_slice($result->value, 0, $length);

        for ($i = 0; $i < $length; ++$i) {
            $result->value[$i]&= $x->value[$i];
        }

        return $this->_normalize($result);
    }

    /**
     * Logical Or
     *
     * @param \phpseclib\Math\BigInteger $x
     * @access public
     * @internal Implemented per a request by Lluis Pamies i Juarez <lluis _a_ pamies.cat>
     * @return \phpseclib\Math\BigInteger
     */
    function bitwise_or($x)
    {
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                $temp = new static();
                $temp->value = gmp_or($this->value, $x->value);

                return $this->_normalize($temp);
            case self::MODE_BCMATH:
                $left = $this->toBytes();
                $right = $x->toBytes();

                $length = max(strlen($left), strlen($right));

                $left = str_pad($left, $length, chr(0), STR_PAD_LEFT);
                $right = str_pad($right, $length, chr(0), STR_PAD_LEFT);

                return $this->_normalize(new static($left | $right, 256));
        }

        $length = max(count($this->value), count($x->value));
        $result = $this->copy();
        $result->value = array_pad($result->value, $length, 0);
        $x->value = array_pad($x->value, $length, 0);

        for ($i = 0; $i < $length; ++$i) {
            $result->value[$i]|= $x->value[$i];
        }

        return $this->_normalize($result);
    }

    /**
     * Logical Exclusive-Or
     *
     * @param \phpseclib\Math\BigInteger $x
     * @access public
     * @internal Implemented per a request by Lluis Pamies i Juarez <lluis _a_ pamies.cat>
     * @return \phpseclib\Math\BigInteger
     */
    function bitwise_xor($x)
    {
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                $temp = new static();
                $temp->value = gmp_xor(gmp_abs($this->value), gmp_abs($x->value));
                return $this->_normalize($temp);
            case self::MODE_BCMATH:
                $left = $this->toBytes();
                $right = $x->toBytes();

                $length = max(strlen($left), strlen($right));

                $left = str_pad($left, $length, chr(0), STR_PAD_LEFT);
                $right = str_pad($right, $length, chr(0), STR_PAD_LEFT);

                return $this->_normalize(new static($left ^ $right, 256));
        }

        $length = max(count($this->value), count($x->value));
        $result = $this->copy();
        $result->is_negative = false;
        $result->value = array_pad($result->value, $length, 0);
        $x->value = array_pad($x->value, $length, 0);

        for ($i = 0; $i < $length; ++$i) {
            $result->value[$i]^= $x->value[$i];
        }

        return $this->_normalize($result);
    }

    /**
     * Logical Not
     *
     * @access public
     * @internal Implemented per a request by Lluis Pamies i Juarez <lluis _a_ pamies.cat>
     * @return \phpseclib\Math\BigInteger
     */
    function bitwise_not()
    {
        // calculuate "not" without regard to $this->precision
        // (will always result in a smaller number.  ie. ~1 isn't 1111 1110 - it's 0)
        $temp = $this->toBytes();
        if ($temp == '') {
            return $this->_normalize(new static());
        }
        $pre_msb = decbin(ord($temp[0]));
        $temp = ~$temp;
        $msb = decbin(ord($temp[0]));
        if (strlen($msb) == 8) {
            $msb = substr($msb, strpos($msb, '0'));
        }
        $temp[0] = chr(bindec($msb));

        // see if we need to add extra leading 1's
        $current_bits = strlen($pre_msb) + 8 * strlen($temp) - 8;
        $new_bits = $this->precision - $current_bits;
        if ($new_bits <= 0) {
            return $this->_normalize(new static($temp, 256));
        }

        // generate as many leading 1's as we need to.
        $leading_ones = chr((1 << ($new_bits & 0x7)) - 1) . str_repeat(chr(0xFF), $new_bits >> 3);
        $this->_base256_lshift($leading_ones, $current_bits);

        $temp = str_pad($temp, strlen($leading_ones), chr(0), STR_PAD_LEFT);

        return $this->_normalize(new static($leading_ones | $temp, 256));
    }

    /**
     * Logical Right Shift
     *
     * Shifts BigInteger's by $shift bits, effectively dividing by 2**$shift.
     *
     * @param int $shift
     * @return \phpseclib\Math\BigInteger
     * @access public
     * @internal The only version that yields any speed increases is the internal version.
     */
    function bitwise_rightShift($shift)
    {
        $temp = new static();

        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                static $two;

                if (!isset($two)) {
                    $two = gmp_init('2');
                }

                $temp->value = gmp_div_q($this->value, gmp_pow($two, $shift));

                break;
            case self::MODE_BCMATH:
                $temp->value = bcdiv($this->value, bcpow('2', $shift, 0), 0);

                break;
            default: // could just replace _lshift with this, but then all _lshift() calls would need to be rewritten
                // and I don't want to do that...
                $temp->value = $this->value;
                $temp->_rshift($shift);
        }

        return $this->_normalize($temp);
    }

    /**
     * Logical Left Shift
     *
     * Shifts BigInteger's by $shift bits, effectively multiplying by 2**$shift.
     *
     * @param int $shift
     * @return \phpseclib\Math\BigInteger
     * @access public
     * @internal The only version that yields any speed increases is the internal version.
     */
    function bitwise_leftShift($shift)
    {
        $temp = new static();

        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                static $two;

                if (!isset($two)) {
                    $two = gmp_init('2');
                }

                $temp->value = gmp_mul($this->value, gmp_pow($two, $shift));

                break;
            case self::MODE_BCMATH:
                $temp->value = bcmul($this->value, bcpow('2', $shift, 0), 0);

                break;
            default: // could just replace _rshift with this, but then all _lshift() calls would need to be rewritten
                // and I don't want to do that...
                $temp->value = $this->value;
                $temp->_lshift($shift);
        }

        return $this->_normalize($temp);
    }

    /**
     * Logical Left Rotate
     *
     * Instead of the top x bits being dropped they're appended to the shifted bit string.
     *
     * @param int $shift
//     * @return \phpseclib\Math\BigInteger
     * @access public
     */
    function bitwise_leftRotate($shift)
    {
        $bits = $this->toBytes();

        if ($this->precision > 0) {
            $precision = $this->precision;
            if (MATH_BIGINTEGER_MODE == self::MODE_BCMATH) {
                $mask = $this->bitmask->subtract(new static(1));
                $mask = $mask->toBytes();
            } else {
                $mask = $this->bitmask->toBytes();
            }
        } else {
            $temp = ord($bits[0]);
            for ($i = 0; $temp >> $i; ++$i) {
            }
            $precision = 8 * strlen($bits) - 8 + $i;
            $mask = chr((1 << ($precision & 0x7)) - 1) . str_repeat(chr(0xFF), $precision >> 3);
        }

        if ($shift < 0) {
            $shift+= $precision;
        }
        $shift%= $precision;

        if (!$shift) {
            return $this->copy();
        }

        $left = $this->bitwise_leftShift($shift);
        $left = $left->bitwise_and(new static($mask, 256));
        $right = $this->bitwise_rightShift($precision - $shift);
        $result = MATH_BIGINTEGER_MODE != self::MODE_BCMATH ? $left->bitwise_or($right) : $left->add($right);
        return $this->_normalize($result);
    }

    /**
     * Logical Right Rotate
     *
     * Instead of the bottom x bits being dropped they're prepended to the shifted bit string.
     *
     * @param int $shift
     * @return \phpseclib\Math\BigInteger
     * @access public
     */
    function bitwise_rightRotate($shift)
    {
        return $this->bitwise_leftRotate(-$shift);
    }

    /**
     * Generates a random BigInteger
     *
     * Byte length is equal to $length. Uses \phpseclib\Crypt\Random if it's loaded and mt_rand if it's not.
     *
     * @param int $size
//     * @return \phpseclib\Math\BigInteger
     * @access private
     */
    function _random_number_helper($size)
    {
        $random = '';

        if ($size & 1) {
            $random.= chr(mt_rand(0, 255));
        }

        $blocks = $size >> 1;
        for ($i = 0; $i < $blocks; ++$i) {
            // mt_rand(-2147483648, 0x7FFFFFFF) always produces -2147483648 on some systems
            $random.= pack('n', mt_rand(0, 0xFFFF));
        }

        return new static($random, 256);
    }

    /**
     * Generate a random number
     *
     * Returns a random number between $min and $max where $min and $max
     * can be defined using one of the two methods:
     *
     * $min->random($max)
     * $max->random($min)
     *
     * @param \phpseclib\Math\BigInteger $arg1
     * @param \phpseclib\Math\BigInteger $arg2
     * @return \phpseclib\Math\BigInteger
     * @access public
     * @internal The API for creating random numbers used to be $a->random($min, $max), where $a was a BigInteger object.
     *           That method is still supported for BC purposes.
     */
    function random($arg1, $arg2 = false)
    {
        if ($arg1 === false) {
            return false;
        }

        if ($arg2 === false) {
            $max = $arg1;
            $min = $this;
        } else {
            $min = $arg1;
            $max = $arg2;
        }

        $compare = $max->compare($min);

        if (!$compare) {
            return $this->_normalize($min);
        } elseif ($compare < 0) {
            // if $min is bigger then $max, swap $min and $max
            $temp = $max;
            $max = $min;
            $min = $temp;
        }

        static $one;
        if (!isset($one)) {
            $one = new static(1);
        }

        $max = $max->subtract($min->subtract($one));
        $size = strlen(ltrim($max->toBytes(), chr(0)));

        /*
            doing $random % $max doesn't work because some numbers will be more likely to occur than others.
            eg. if $max is 140 and $random's max is 255 then that'd mean both $random = 5 and $random = 145
            would produce 5 whereas the only value of random that could produce 139 would be 139. ie.
            not all numbers would be equally likely. some would be more likely than others.

            creating a whole new random number until you find one that is within the range doesn't work
            because, for sufficiently small ranges, the likelihood that you'd get a number within that range
            would be pretty small. eg. with $random's max being 255 and if your $max being 1 the probability
            would be pretty high that $random would be greater than $max.

            phpseclib works around this using the technique described here:

            http://crypto.stackexchange.com/questions/5708/creating-a-small-number-from-a-cryptographically-secure-random-string
        */
        $random_max = new static(chr(1) . str_repeat("\0", $size), 256);
        $random = $this->_random_number_helper($size);

        list($max_multiple) = $random_max->divide($max);
        $max_multiple = $max_multiple->multiply($max);

        while ($random->compare($max_multiple) >= 0) {
            $random = $random->subtract($max_multiple);
            $random_max = $random_max->subtract($max_multiple);
            $random = $random->bitwise_leftShift(8);
            $random = $random->add($this->_random_number_helper(1));
            $random_max = $random_max->bitwise_leftShift(8);
            list($max_multiple) = $random_max->divide($max);
            $max_multiple = $max_multiple->multiply($max);
        }
        list(, $random) = $random->divide($max);

        return $this->_normalize($random->add($min));
    }

    /**
     * Generate a random prime number.
     *
     * If there's not a prime within the given range, false will be returned.
     * If more than $timeout seconds have elapsed, give up and return false.
     *
     * @param \phpseclib\Math\BigInteger $arg1
     * @param \phpseclib\Math\BigInteger $arg2
     * @param int $timeout
     * @return Math_BigInteger|false
     * @access public
     * @internal See {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap4.pdf#page=15 HAC 4.44}.
     */
    function randomPrime($arg1, $arg2 = false, $timeout = false)
    {
        if ($arg1 === false) {
            return false;
        }

        if ($arg2 === false) {
            $max = $arg1;
            $min = $this;
        } else {
            $min = $arg1;
            $max = $arg2;
        }

        $compare = $max->compare($min);

        if (!$compare) {
            return $min->isPrime() ? $min : false;
        } elseif ($compare < 0) {
            // if $min is bigger then $max, swap $min and $max
            $temp = $max;
            $max = $min;
            $min = $temp;
        }

        static $one, $two;
        if (!isset($one)) {
            $one = new static(1);
            $two = new static(2);
        }

        $start = time();

        $x = $this->random($min, $max);

        // gmp_nextprime() requires PHP 5 >= 5.2.0 per <http://php.net/gmp-nextprime>.
        if (MATH_BIGINTEGER_MODE == self::MODE_GMP && extension_loaded('gmp')) {
            $p = new static();
            $p->value = gmp_nextprime($x->value);

            if ($p->compare($max) <= 0) {
                return $p;
            }

            if (!$min->equals($x)) {
                $x = $x->subtract($one);
            }

            return $x->randomPrime($min, $x);
        }

        if ($x->equals($two)) {
            return $x;
        }

        $x->_make_odd();
        if ($x->compare($max) > 0) {
            // if $x > $max then $max is even and if $min == $max then no prime number exists between the specified range
            if ($min->equals($max)) {
                return false;
            }
            $x = $min->copy();
            $x->_make_odd();
        }

        $initial_x = $x->copy();

        while (true) {
            if ($timeout !== false && time() - $start > $timeout) {
                return false;
            }

            if ($x->isPrime()) {
                return $x;
            }

            $x = $x->add($two);

            if ($x->compare($max) > 0) {
                $x = $min->copy();
                if ($x->equals($two)) {
                    return $x;
                }
                $x->_make_odd();
            }

            if ($x->equals($initial_x)) {
                return false;
            }
        }
    }

    /**
     * Make the current number odd
     *
     * If the current number is odd it'll be unchanged.  If it's even, one will be added to it.
     *
     * @see self::randomPrime()
     * @access private
     */
    function _make_odd()
    {
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                gmp_setbit($this->value, 0);
                break;
            case self::MODE_BCMATH:
                if ($this->value[strlen($this->value) - 1] % 2 == 0) {
                    $this->value = bcadd($this->value, '1');
                }
                break;
            default:
                $this->value[0] |= 1;
        }
    }

    /**
     * Checks a numer to see if it's prime
     *
     * Assuming the $t parameter is not set, this function has an error rate of 2**-80.  The main motivation for the
     * $t parameter is distributability.  BigInteger::randomPrime() can be distributed across multiple pageloads
     * on a website instead of just one.
     *
     * @param \phpseclib\Math\BigInteger $t
     * @return bool
     * @access public
     * @internal Uses the
     *     {@link http://en.wikipedia.org/wiki/Miller%E2%80%93Rabin_primality_test Miller-Rabin primality test}.  See
     *     {@link http://www.cacr.math.uwaterloo.ca/hac/about/chap4.pdf#page=8 HAC 4.24}.
     */
    function isPrime($t = false)
    {
        $length = strlen($this->toBytes());

        if (!$t) {
            // see HAC 4.49 "Note (controlling the error probability)"
            // @codingStandardsIgnoreStart
            if ($length >= 163) { $t =  2; } // floor(1300 / 8)
            else if ($length >= 106) { $t =  3; } // floor( 850 / 8)
            else if ($length >= 81 ) { $t =  4; } // floor( 650 / 8)
            else if ($length >= 68 ) { $t =  5; } // floor( 550 / 8)
            else if ($length >= 56 ) { $t =  6; } // floor( 450 / 8)
            else if ($length >= 50 ) { $t =  7; } // floor( 400 / 8)
            else if ($length >= 43 ) { $t =  8; } // floor( 350 / 8)
            else if ($length >= 37 ) { $t =  9; } // floor( 300 / 8)
            else if ($length >= 31 ) { $t = 12; } // floor( 250 / 8)
            else if ($length >= 25 ) { $t = 15; } // floor( 200 / 8)
            else if ($length >= 18 ) { $t = 18; } // floor( 150 / 8)
            else                     { $t = 27; }
            // @codingStandardsIgnoreEnd
        }

        // ie. gmp_testbit($this, 0)
        // ie. isEven() or !isOdd()
        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                return gmp_prob_prime($this->value, $t) != 0;
            case self::MODE_BCMATH:
                if ($this->value === '2') {
                    return true;
                }
                if ($this->value[strlen($this->value) - 1] % 2 == 0) {
                    return false;
                }
                break;
            default:
                if ($this->value == array(2)) {
                    return true;
                }
                if (~$this->value[0] & 1) {
                    return false;
                }
        }

        static $primes, $zero, $one, $two;

        if (!isset($primes)) {
            $primes = array(
                3,    5,    7,    11,   13,   17,   19,   23,   29,   31,   37,   41,   43,   47,   53,   59,
                61,   67,   71,   73,   79,   83,   89,   97,   101,  103,  107,  109,  113,  127,  131,  137,
                139,  149,  151,  157,  163,  167,  173,  179,  181,  191,  193,  197,  199,  211,  223,  227,
                229,  233,  239,  241,  251,  257,  263,  269,  271,  277,  281,  283,  293,  307,  311,  313,
                317,  331,  337,  347,  349,  353,  359,  367,  373,  379,  383,  389,  397,  401,  409,  419,
                421,  431,  433,  439,  443,  449,  457,  461,  463,  467,  479,  487,  491,  499,  503,  509,
                521,  523,  541,  547,  557,  563,  569,  571,  577,  587,  593,  599,  601,  607,  613,  617,
                619,  631,  641,  643,  647,  653,  659,  661,  673,  677,  683,  691,  701,  709,  719,  727,
                733,  739,  743,  751,  757,  761,  769,  773,  787,  797,  809,  811,  821,  823,  827,  829,
                839,  853,  857,  859,  863,  877,  881,  883,  887,  907,  911,  919,  929,  937,  941,  947,
                953,  967,  971,  977,  983,  991,  997
            );

            if (MATH_BIGINTEGER_MODE != self::MODE_INTERNAL) {
                for ($i = 0; $i < count($primes); ++$i) {
                    $primes[$i] = new static($primes[$i]);
                }
            }

            $zero = new static();
            $one = new static(1);
            $two = new static(2);
        }

        if ($this->equals($one)) {
            return false;
        }

        // see HAC 4.4.1 "Random search for probable primes"
        if (MATH_BIGINTEGER_MODE != self::MODE_INTERNAL) {
            foreach ($primes as $prime) {
                list(, $r) = $this->divide($prime);
                if ($r->equals($zero)) {
                    return $this->equals($prime);
                }
            }
        } else {
            $value = $this->value;
            foreach ($primes as $prime) {
                list(, $r) = $this->_divide_digit($value, $prime);
                if (!$r) {
                    return count($value) == 1 && $value[0] == $prime;
                }
            }
        }

        $n   = $this->copy();
        $n_1 = $n->subtract($one);
        $n_2 = $n->subtract($two);

        $r = $n_1->copy();
        $r_value = $r->value;
        // ie. $s = gmp_scan1($n, 0) and $r = gmp_div_q($n, gmp_pow(gmp_init('2'), $s));
        if (MATH_BIGINTEGER_MODE == self::MODE_BCMATH) {
            $s = 0;
            // if $n was 1, $r would be 0 and this would be an infinite loop, hence our $this->equals($one) check earlier
            while ($r->value[strlen($r->value) - 1] % 2 == 0) {
                $r->value = bcdiv($r->value, '2', 0);
                ++$s;
            }
        } else {
            for ($i = 0, $r_length = count($r_value); $i < $r_length; ++$i) {
                $temp = ~$r_value[$i] & 0xFFFFFF;
                for ($j = 1; ($temp >> $j) & 1; ++$j) {
                }
                if ($j != 25) {
                    break;
                }
            }
            $s = 26 * $i + $j;
            $r->_rshift($s);
        }

        for ($i = 0; $i < $t; ++$i) {
            $a = $this->random($two, $n_2);
            $y = $a->modPow($r, $n);

            if (!$y->equals($one) && !$y->equals($n_1)) {
                for ($j = 1; $j < $s && !$y->equals($n_1); ++$j) {
                    $y = $y->modPow($two, $n);
                    if ($y->equals($one)) {
                        return false;
                    }
                }

                if (!$y->equals($n_1)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Logical Left Shift
     *
     * Shifts BigInteger's by $shift bits.
     *
     * @param int $shift
     * @access private
     */
    function _lshift($shift)
    {
        if ($shift == 0) {
            return;
        }

        $num_digits = (int) ($shift / self::$base);
        $shift %= self::$base;
        $shift = 1 << $shift;

        $carry = 0;

        for ($i = 0; $i < count($this->value); ++$i) {
            $temp = $this->value[$i] * $shift + $carry;
            $carry = self::$base === 26 ? intval($temp / 0x4000000) : ($temp >> 31);
            $this->value[$i] = (int) ($temp - $carry * self::$baseFull);
        }

        if ($carry) {
            $this->value[count($this->value)] = $carry;
        }

        while ($num_digits--) {
            array_unshift($this->value, 0);
        }
    }

    /**
     * Logical Right Shift
     *
     * Shifts BigInteger's by $shift bits.
     *
     * @param int $shift
     * @access private
     */
    function _rshift($shift)
    {
        if ($shift == 0) {
            return;
        }

        $num_digits = (int) ($shift / self::$base);
        $shift %= self::$base;
        $carry_shift = self::$base - $shift;
        $carry_mask = (1 << $shift) - 1;

        if ($num_digits) {
            $this->value = array_slice($this->value, $num_digits);
        }

        $carry = 0;

        for ($i = count($this->value) - 1; $i >= 0; --$i) {
            $temp = $this->value[$i] >> $shift | $carry;
            $carry = ($this->value[$i] & $carry_mask) << $carry_shift;
            $this->value[$i] = $temp;
        }

        $this->value = $this->_trim($this->value);
    }

    /**
     * Normalize
     *
     * Removes leading zeros and truncates (if necessary) to maintain the appropriate precision
     *
     * @param \phpseclib\Math\BigInteger $result
     * @return \phpseclib\Math\BigInteger
     * @see self::_trim()
     * @access private
     */
    function _normalize($result)
    {
        $result->precision = $this->precision;
        $result->bitmask = $this->bitmask;

        switch (MATH_BIGINTEGER_MODE) {
            case self::MODE_GMP:
                if ($this->bitmask !== false) {
                    $flip = gmp_cmp($result->value, gmp_init(0)) < 0;
                    if ($flip) {
                        $result->value = gmp_neg($result->value);
                    }
                    $result->value = gmp_and($result->value, $result->bitmask->value);
                    if ($flip) {
                        $result->value = gmp_neg($result->value);
                    }
                }

                return $result;
            case self::MODE_BCMATH:
                if (!empty($result->bitmask->value)) {
                    $result->value = bcmod($result->value, $result->bitmask->value);
                }

                return $result;
        }

        $value = &$result->value;

        if (!count($value)) {
            $result->is_negative = false;
            return $result;
        }

        $value = $this->_trim($value);

        if (!empty($result->bitmask->value)) {
            $length = min(count($value), count($this->bitmask->value));
            $value = array_slice($value, 0, $length);

            for ($i = 0; $i < $length; ++$i) {
                $value[$i] = $value[$i] & $this->bitmask->value[$i];
            }
        }

        return $result;
    }

    /**
     * Trim
     *
     * Removes leading zeros
     *
     * @param array $value
//     * @return \phpseclib\Math\BigInteger
     * @access private
     */
    function _trim($value)
    {
        for ($i = count($value) - 1; $i >= 0; --$i) {
            if ($value[$i]) {
                break;
            }
            unset($value[$i]);
        }

        return $value;
    }

    /**
     * Array Repeat
     *
     * @param array $input
     * @param mixed $multiplier
     * @return array
     * @access private
     */
    function _array_repeat($input, $multiplier)
    {
        return ($multiplier) ? array_fill(0, $multiplier, $input) : array();
    }

    /**
     * Logical Left Shift
     *
     * Shifts binary strings $shift bits, essentially multiplying by 2**$shift.
     *
     * @param string $x (by reference)
     * @param int $shift
     * @return string
     * @access private
     */
    function _base256_lshift(&$x, $shift)
    {
        if ($shift == 0) {
            return;
        }

        $num_bytes = $shift >> 3; // eg. floor($shift/8)
        $shift &= 7; // eg. $shift % 8

        $carry = 0;
        for ($i = strlen($x) - 1; $i >= 0; --$i) {
            $temp = ord($x[$i]) << $shift | $carry;
            $x[$i] = chr($temp);
            $carry = $temp >> 8;
        }
        $carry = ($carry != 0) ? chr($carry) : '';
        $x = $carry . $x . str_repeat(chr(0), $num_bytes);
    }

    /**
     * Logical Right Shift
     *
     * Shifts binary strings $shift bits, essentially dividing by 2**$shift and returning the remainder.
     *
     * @param string $x (by referenc)
     * @param int $shift
     * @return string
     * @access private
     */
    function _base256_rshift(&$x, $shift)
    {
        if ($shift == 0) {
            $x = ltrim($x, chr(0));
            return '';
        }

        $num_bytes = $shift >> 3; // eg. floor($shift/8)
        $shift &= 7; // eg. $shift % 8

        $remainder = '';
        if ($num_bytes) {
            $start = $num_bytes > strlen($x) ? -strlen($x) : -$num_bytes;
            $remainder = substr($x, $start);
            $x = substr($x, 0, -$num_bytes);
        }

        $carry = 0;
        $carry_shift = 8 - $shift;
        for ($i = 0; $i < strlen($x); ++$i) {
            $temp = (ord($x[$i]) >> $shift) | $carry;
            $carry = (ord($x[$i]) << $carry_shift) & 0xFF;
            $x[$i] = chr($temp);
        }
        $x = ltrim($x, chr(0));

        $remainder = chr($carry >> $carry_shift) . $remainder;

        return ltrim($remainder, chr(0));
    }

    // one quirk about how the following functions are implemented is that PHP defines N to be an unsigned long
    // at 32-bits, while java's longs are 64-bits.

    /**
     * Converts 32-bit integers to bytes.
     *
     * @param int $x
     * @return string
     * @access private
     */
    function _int2bytes($x)
    {
        return ltrim(pack('N', $x), chr(0));
    }

    /**
     * Converts bytes to 32-bit integers
     *
     * @param string $x
     * @return int
     * @access private
     */
    function _bytes2int($x)
    {
        $temp = unpack('Nint', str_pad($x, 4, chr(0), STR_PAD_LEFT));
        return $temp['int'];
    }

    /**
     * DER-encode an integer
     *
     * The ability to DER-encode integers is needed to create RSA public keys for use with OpenSSL
     *
     * @see self::modPow()
     * @access private
     * @param int $length
     * @return string
     */
    function _encodeASN1Length($length)
    {
        if ($length <= 0x7F) {
            return chr($length);
        }

        $temp = ltrim(pack('N', $length), chr(0));
        return pack('Ca*', 0x80 | strlen($temp), $temp);
    }

    /**
     * Single digit division
     *
     * Even if int64 is being used the division operator will return a float64 value
     * if the dividend is not evenly divisible by the divisor. Since a float64 doesn't
     * have the precision of int64 this is a problem so, when int64 is being used,
     * we'll guarantee that the dividend is divisible by first subtracting the remainder.
     *
     * @access private
     * @param int $x
     * @param int $y
     * @return int
     */
    function _safe_divide($x, $y)
    {
        if (self::$base === 26) {
            return (int) ($x / $y);
        }

        // self::$base === 31
        return ($x - ($x % $y)) / $y;
    }
}

/**
 * Utils
 */
class Utils
{
    /**
     * SHA3_NULL_HASH
     *
     * @const string
     */
    const SHA3_NULL_HASH = 'c5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470';

    public static function isHex($value)
    {
        return (is_string($value) && preg_match('/^(0x)?[a-fA-F0-9]+$/', $value) === 1);
    }

    public static function encode($arr, $enc)
    {
        if( $enc === "hex" )
            return self::toHex($arr);
        return $arr;
    }

    public static function toArray($msg, $enc = false)
    {
        if( is_array($msg) )
            return array_slice($msg, 0);

        if( !$msg )
            return array();

        if( !is_string($msg) )
            throw new Exception("Not implemented");

        if( !$enc )
            return array_slice(unpack("C*", $msg), 0);

        if( $enc === "hex" )
            return array_slice(unpack("C*", hex2bin($msg)), 0);

        return $msg;
    }

    /**
     * toHex
     * Encoding string or integer or numeric string(is not zero prefixed) or big number to hex.
     *
//     * @param string|int|\phpseclib\Math\BigInteger $value
//     * @param bool $isPrefix
//     * @return string
     */
    public static function toHex($value, $isPrefix=false)
    {
        if (is_numeric($value)) {
            // turn to hex number
            $bn = self::toBn($value);
            $hex = $bn->toHex(true);
            $hex = preg_replace('/^0+(?!$)/', '', $hex);
        } elseif (is_string($value)) {
            $value = self::stripZero($value);
            $hex = implode('', unpack('H*', $value));
        } elseif ($value instanceof BigNumber) {
            $hex = $value->toHex(true);
            $hex = preg_replace('/^0+(?!$)/', '', $hex);
        } else {
            throw new InvalidArgumentException('The value to toHex function is not support.');
        }
        if ($isPrefix) {
            return '0x' . $hex;
        }
        return $hex;
    }

    /**
     * jsonToArray
     *
     * @param stdClass|array $json
     * @return array
     */
    public static function jsonToArray($json)
    {
        if ($json instanceof stdClass) {
            $json = (array) $json;
            $typeName = [];

            foreach ($json as $key => $param) {
                if (is_array($param)) {
                    foreach ($param as $subKey => $subParam) {
                        $json[$key][$subKey] = self::jsonToArray($subParam);
                    }
                } elseif ($param instanceof stdClass) {
                    $json[$key] = self::jsonToArray($param);
                }
            }
        } elseif (is_array($json)) {
            foreach ($json as $key => $param) {
                if (is_array($param)) {
                    foreach ($param as $subKey => $subParam) {
                        $json[$key][$subKey] = self::jsonToArray($subParam);
                    }
                } elseif ($param instanceof stdClass) {
                    $json[$key] = self::jsonToArray($param);
                }
            }
        }
        return $json;
    }

    /**
     * hexToBin
     *
     * @param string
     * @return string
     */
    public static function hexToBin($value)
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('The value to hexToBin function must be string.');
        }
        if (self::isZeroPrefixed($value)) {
            $count = 1;
            $value = str_replace('0x', '', $value, $count);
        }
        return pack('H*', $value);
    }

    /**
     * toString
     *
//     * @param mixed $value
//     * @return string
     */
    public static function toString($value)
    {
        $value = (string) $value;

        return $value;
    }

    /**
     * stripZero
     *
     * @param string $value
     * @return string
     */
    public static function stripZero($value)
    {
        if (self::isZeroPrefixed($value)) {
            $count = 1;
            return str_replace('0x', '', $value, $count);
        }
        return $value;
    }

    /**
     * isNegative
     *
     * @param string
     * @return bool
     */
    public static function isNegative($value)
    {
        if (!is_string($value)) {
            throw new \Error('The value to isNegative function must be string.');
        }
        return (strpos($value, '-') === 0);
    }

    /**
     * isZeroPrefixed
     *
     * @param string
     * @return bool
     */
    public static function isZeroPrefixed($value)
    {
        if (!is_string($value)) {
            throw new \Error('The value to isZeroPrefixed function must be string.');
        }
        return (strpos($value, '0x') === 0);
    }

    /**
     * isAddress
     *
     * @param string $value
     * @return bool
     */
    public static function isAddress($value)
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('The value to isAddress function must be string.');
        }
        if (preg_match('/^(0x|0X)?[a-f0-9A-F]{40}$/', $value) !== 1) {
            return false;
        } elseif (preg_match('/^(0x|0X)?[a-f0-9]{40}$/', $value) === 1 || preg_match('/^(0x|0X)?[A-F0-9]{40}$/', $value) === 1) {
            return true;
        }
        return self::isAddressChecksum($value);
    }

    /**
     * isAddressChecksum
     *
     * @param string $value
     * @return bool
     */
    public static function isAddressChecksum($value)
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('The value to isAddressChecksum function must be string.');
        }
        $value = self::stripZero($value);
        $hash = self::stripZero(hash('sha3', mb_strtolower($value)));

        for ($i = 0; $i < 40; $i++) {
            if (
                (intval($hash[$i], 16) > 7 && mb_strtoupper($value[$i]) !== $value[$i]) ||
                (intval($hash[$i], 16) <= 7 && mb_strtolower($value[$i]) !== $value[$i])
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * toBn
     * Change number or number string to bignumber.
     *
//     * @param BigNumber|string|int $number
//     * @return array|\phpseclib\Math\BigInteger
     */
    public static function toBn($number)
    {
        if ($number instanceof BigNumber){
            $bn = $number;
        } elseif (is_int($number)) {
            $bn = new BigNumber($number);
        } elseif (is_numeric($number)) {
            $number = (string) $number;

            if (self::isNegative($number)) {
                $count = 1;
                $number = str_replace('-', '', $number, $count);
                $negative1 = new BigNumber(-1);
            }
            if (strpos($number, '.') > 0) {
                $comps = explode('.', $number);

                if (count($comps) > 2) {
                    throw new \Error('toBn number must be a valid number.');
                }
                $whole = $comps[0];
                $fraction = $comps[1];

                return [
                    new BigNumber($whole),
                    new BigNumber($fraction),
                    strlen($comps[1]),
                    isset($negative1) ? $negative1 : false
                ];
            } else {
                $bn = new BigNumber($number);
            }
            if (isset($negative1)) {
                $bn = $bn->multiply($negative1);
            }
        } elseif (is_string($number)) {
            $number = mb_strtolower($number);

            if (self::isNegative($number)) {
                $count = 1;
                $number = str_replace('-', '', $number, $count);
                $negative1 = new BigNumber(-1);
            }
            if (self::isZeroPrefixed($number) || preg_match('/[a-f]+/', $number) === 1) {
                $number = self::stripZero($number);
                $bn = new BigNumber($number, 16);
            } elseif (empty($number)) {
                $bn = new BigNumber(0);
            } else {
                throw new \Error('toBn number must be valid hex string.');
            }
            if (isset($negative1)) {
                $bn = $bn->multiply($negative1);
            }
        } else {
            throw new \Error('toBn number must be BigNumber, string or int.');
        }
        return $bn;
    }

    /**
     * jsonMethodToString
     *
     * @param stdClass|array $json
     * @return string
     */
    public static function jsonMethodToString($json)
    {
        if ($json instanceof stdClass) {
            // one way to change whole json stdClass to array type
            // $jsonString = json_encode($json);

            // if (JSON_ERROR_NONE !== json_last_error()) {
            //     throw new InvalidArgumentException('json_decode error: ' . json_last_error_msg());
            // }
            // $json = json_decode($jsonString, true);

            // another way to change whole json to array type but need the depth
            // $json = self::jsonToArray($json, $depth)

            // another way to change json to array type but not whole json stdClass
            $json = (array) $json;
            $typeName = [];

            foreach ($json['inputs'] as $param) {
                if (isset($param->type)) {
                    $typeName[] = $param->type;
                }
            }
            return $json['name'] . '(' . implode(',', $typeName) . ')';
        } elseif (!is_array($json)) {
            throw new InvalidArgumentException('jsonMethodToString json must be array or stdClass.');
        }
        if (isset($json['name']) && strpos($json['name'], '(') > 0) {
            return $json['name'];
        }
        $typeName = [];

        foreach ($json['inputs'] as $param) {
            if (isset($param['type'])) {
                $typeName[] = $param['type'];
            }
        }
        return $json['name'] . '(' . implode(',', $typeName) . ')';
    }

    /**
     * sha3
     * keccak256
     *
     * @param string $value
     * @return string
     */
    public static function sha3($value)
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('The value to sha3 function must be string.');
        }
        if (strpos($value, '0x') === 0) {
            $value = self::hexToBin($value);
        }
        $hash = Keccak::hash($value, 256);

        if ($hash === self::SHA3_NULL_HASH) {
            return null;
        }
        return '0x' . $hash;
    }
}


/**
 * Contract Types
 */
interface IType
{
    /**
     * isType
     *
     * @param string $name
     * @return bool
     */
    public function isType($name);

    /**
     * isDynamicType
     *
     * @return bool
     */
    public function isDynamicType();

    /**
     * inputFormat
     *
     * @param mixed $value
     * @param string $name
     * @return string
     */
    public function inputFormat($value, $name);
}

class SolidityType
{
    /**
     * construct
     *
     * @return void
     */
    // public function  __construct() {}

    /**
     * get
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        $method = 'get' . ucfirst($name);

        if (method_exists($this, $method)) {
            return call_user_func_array([$this, $method], []);
        }
        return false;
    }

    /**
     * set
     *
     * @param string $name
     * @param mixed $value
     * @return mixed;
     */
    public function __set($name, $value)
    {
        $method = 'set' . ucfirst($name);

        if (method_exists($this, $method)) {
            return call_user_func_array([$this, $method], [$value]);
        }
        return false;
    }

    /**
     * callStatic
     *
     * @param string $name
     * @param array $arguments
     * @return void
     */
    // public static function __callStatic($name, $arguments) {}

    /**
     * nestedTypes
     *
     * @param string $name
     * @return mixed
     */
    public function nestedTypes($name)
    {
        if (!is_string($name)) {
            throw new InvalidArgumentException('nestedTypes name must string.');
        }
        $matches = [];

        if (preg_match_all('/(\[[0-9]*\])/', $name, $matches, PREG_PATTERN_ORDER) >= 1) {
            return $matches[0];
        }
        return false;
    }

    /**
     * nestedName
     *
     * @param string $name
     * @return string
     */
    public function nestedName($name)
    {
        if (!is_string($name)) {
            throw new InvalidArgumentException('nestedName name must string.');
        }
        $nestedTypes = $this->nestedTypes($name);

        if ($nestedTypes === false) {
            return $name;
        }
        return mb_substr($name, 0, mb_strlen($name) - mb_strlen($nestedTypes[count($nestedTypes) - 1]));
    }

    /**
     * isDynamicArray
     *
     * @param string $name
     * @return bool
     */
    public function isDynamicArray($name)
    {
        $nestedTypes = $this->nestedTypes($name);

        return $nestedTypes && preg_match('/[0-9]{1,}/', $nestedTypes[count($nestedTypes) - 1]) !== 1;
    }

    /**
     * isStaticArray
     *
     * @param string $name
     * @return bool
     */
    public function isStaticArray($name)
    {
        $nestedTypes = $this->nestedTypes($name);

        return $nestedTypes && preg_match('/[0-9]{1,}/', $nestedTypes[count($nestedTypes) - 1]) === 1;
    }

    /**
     * staticArrayLength
     *
     * @param string $name
     * @return int
     */
    public function staticArrayLength($name)
    {
        $nestedTypes = $this->nestedTypes($name);

        if ($nestedTypes === false) {
            return 1;
        }
        $match = [];

        if (preg_match('/[0-9]{1,}/', $nestedTypes[count($nestedTypes) - 1], $match) === 1) {
            return (int) $match[0];
        }
        return 1;
    }

    /**
     * staticPartLength
     *
     * @param string $name
     * @return int
     */
    public function staticPartLength($name)
    {
        $nestedTypes = $this->nestedTypes($name);

        if ($nestedTypes === false) {
            $nestedTypes = ['[1]'];
        }
        $count = 32;

        foreach ($nestedTypes as $type) {
            $num = mb_substr($type, 1, 1);

            if (!is_numeric($num)) {
                $num = 1;
            } else {
                $num = intval($num);
            }
            $count *= $num;
        }

        return $count;
    }

    /**
     * isDynamicType
     *
     * @return bool
     */
    public function isDynamicType()
    {
        return false;
    }

    public function inputFormat($value, $name)
    {
        return $value;
    }

    /**
     * encode
     *
//     * @param mixed $value
//     * @param string $name
//     * @return string
     */
    public function encode($value, $name)
    {
        if ($this->isDynamicArray($name)) {
            $length = count($value);
            $nestedName = $this->nestedName($name);
            $result = [];
            $result[] = IntegerFormatter::format($length);

            foreach ($value as $val) {
                $result[] = $this->encode($val, $nestedName);
            }
            return $result;
        } elseif ($this->isStaticArray($name)) {
            $length = $this->staticArrayLength($name);
            $nestedName = $this->nestedName($name);
            $result = [];

            foreach ($value as $val) {
                $result[] = $this->encode($val, $nestedName);
            }
            return $result;
        }
        return $this->inputFormat($value, $name);
    }

    /**
     * decode
     *
     * @param mixed $value
     * @param string $offset
     * @param string $name
     * @return array
     */
    public function decode($value, $offset, $name)
    {
        if ($this->isDynamicArray($name)) {
            $arrayOffset = (int) Utils::toBn('0x' . mb_substr($value, $offset * 2, 64))->toString();
            $length = (int) Utils::toBn('0x' . mb_substr($value, $arrayOffset * 2, 64))->toString();
            $arrayStart = $arrayOffset + 32;

            $nestedName = $this->nestedName($name);
            $nestedStaticPartLength = $this->staticPartLength($nestedName);
            $roundedNestedStaticPartLength = floor(($nestedStaticPartLength + 31) / 32) * 32;
            $result = [];

            for ($i=0; $i<$length * $roundedNestedStaticPartLength; $i+=$roundedNestedStaticPartLength) {
                $result[] = $this->decode($value, $arrayStart + $i, $nestedName);
            }
            return $result;
        } elseif ($this->isStaticArray($name)) {
            $length = $this->staticArrayLength($name);
            $arrayStart = $offset;

            $nestedName = $this->nestedName($name);
            $nestedStaticPartLength = $this->staticPartLength($nestedName);
            $roundedNestedStaticPartLength = floor(($nestedStaticPartLength + 31) / 32) * 32;
            $result = [];

            for ($i=0; $i<$length * $roundedNestedStaticPartLength; $i+=$roundedNestedStaticPartLength) {
                $result[] = $this->decode($value, $arrayStart + $i, $nestedName);
            }
            return $result;
        } elseif ($this->isDynamicType()) {
            $dynamicOffset = (int) Utils::toBn('0x' . mb_substr($value, $offset * 2, 64))->toString();
            $length = (int) Utils::toBn('0x' . mb_substr($value, $dynamicOffset * 2, 64))->toString();
            $roundedLength = floor(($length + 31) / 32);
            $param = mb_substr($value, $dynamicOffset * 2, ( 1 + $roundedLength) * 64);
            return $this->outputFormat($param, $name);
        }
        $length = $this->staticPartLength($name);
        $param = mb_substr($value, $offset * 2, $length * 2);

        return $this->outputFormat($param, $name);
    }

    public function outputFormat($param, $name)
    {
        return $param;
    }
}

class Address extends SolidityType implements IType
{
    /**
     * construct
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * isType
     *
     * @param string $name
     * @return bool
     */
    public function isType($name)
    {
        return (preg_match('/^address(\[([0-9]*)\])*$/', $name) === 1);
    }

    /**
     * isDynamicType
     *
     * @return bool
     */
    public function isDynamicType()
    {
        return false;
    }

    /**
     * inputFormat
     * to do: iban
     *
     * @param mixed $value
     * @param string $name
     * @return string
     */
    public function inputFormat($value, $name)
    {
        $value = (string) $value;

        if (Utils::isAddress($value)) {
            $value = mb_strtolower($value);

            if (Utils::isZeroPrefixed($value)) {
                $value = Utils::stripZero($value);
            }
        }
        $value = IntegerFormatter::format($value);

        return $value;
    }

    /**
     * outputFormat
     *
     * @param mixed $value
     * @param string $name
     * @return string
     */
    public function outputFormat($value, $name)
    {
        return '0x' . mb_substr($value, 24, 40);
    }
}

class Boolean extends SolidityType implements IType
{
    /**
     * construct
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * isType
     *
     * @param string $name
     * @return bool
     */
    public function isType($name)
    {
        return (preg_match('/^bool(\[([0-9]*)\])*$/', $name) === 1);
    }

    /**
     * isDynamicType
     *
     * @return bool
     */
    public function isDynamicType()
    {
        return false;
    }

    /**
     * inputFormat
     *
     * @param mixed $value
     * @param string $name
     * @return string
     */
    public function inputFormat($value, $name)
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('The value to inputFormat function must be boolean.');
        }
        $value = (int) $value;

        return '000000000000000000000000000000000000000000000000000000000000000' . $value;
    }

    /**
     * outputFormat
     *
     * @param mixed $value
     * @param string $name
     * @return string
     */
    public function outputFormat($value, $name)
    {
        $value = (int) mb_substr($value, 63, 1);

        return (bool) $value;
    }
}

class Bytes extends SolidityType implements IType
{
    /**
     * construct
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * isType
     *
     * @param string $name
     * @return bool
     */
    public function isType($name)
    {
        return (preg_match('/^bytes([0-9]{1,})(\[([0-9]*)\])*$/', $name) === 1);
    }

    /**
     * isDynamicType
     *
     * @return bool
     */
    public function isDynamicType()
    {
        return false;
    }

    /**
     * inputFormat
     *
     * @param mixed $value
     * @param string $name
     * @return string
     */
    public function inputFormat($value, $name)
    {
        if (!Utils::isHex($value)) {
            throw new InvalidArgumentException('The value to inputFormat must be hex bytes.');
        }
        $value = Utils::stripZero($value);

        if (mb_strlen($value) % 2 !== 0) {
            $value = "0" . $value;
            // throw new InvalidArgumentException('The value to inputFormat has invalid length. Value: ' . $value);
        }

        if (mb_strlen($value) > 64) {
            throw new InvalidArgumentException('The value to inputFormat is too long.');
        }
        $l = floor((mb_strlen($value) + 63) / 64);
        $padding = (($l * 64 - mb_strlen($value) + 1) >= 0) ? $l * 64 - mb_strlen($value) : 0;

        return $value . implode('', array_fill(0, $padding, '0'));
    }

    /**
     * outputFormat
     *
     * @param mixed $value
     * @param string $name
     * @return string
     */
    public function outputFormat($value, $name)
    {
        $checkZero = str_replace('0', '', $value);

        if (empty($checkZero)) {
            return '0';
        }
        if (preg_match('/^bytes([0-9]*)/', $name, $match) === 1) {
            $size = intval($match[1]);
            $length = 2 * $size;
            $value = mb_substr($value, 0, $length);
        }
        return '0x' . $value;
    }
}

class DynamicBytes extends SolidityType implements IType
{
    /**
     * construct
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * isType
     *
     * @param string $name
     * @return bool
     */
    public function isType($name)
    {
        return (preg_match('/^bytes(\[([0-9]*)\])*$/', $name) === 1);
    }

    /**
     * isDynamicType
     *
     * @return bool
     */
    public function isDynamicType()
    {
        return true;
    }

    /**
     * inputFormat
     *
     * @param mixed $value
     * @param string $name
     * @return string
     */
    public function inputFormat($value, $name)
    {
        if (!Utils::isHex($value)) {
            throw new InvalidArgumentException('The value to inputFormat must be hex bytes.');
        }
        $value = Utils::stripZero($value);

        if (mb_strlen($value) % 2 !== 0) {
            $value = "0" . $value;
            // throw new InvalidArgumentException('The value to inputFormat has invalid length.');
        }
        $bn = Utils::toBn(floor(mb_strlen($value) / 2));
        $bnHex = $bn->toHex(true);
        $padded = mb_substr($bnHex, 0, 1);

        if ($padded !== '0' && $padded !== 'f') {
            $padded = '0';
        }
        $l = floor((mb_strlen($value) + 63) / 64);
        $padding = (($l * 64 - mb_strlen($value) + 1) >= 0) ? $l * 64 - mb_strlen($value) : 0;

        return implode('', array_fill(0, 64-mb_strlen($bnHex), $padded)) . $bnHex . $value . implode('', array_fill(0, $padding, '0'));
    }

    /**
     * outputFormat
     *
     * @param mixed $value
     * @param string $name
     * @return string
     */
    public function outputFormat($value, $name)
    {
        $checkZero = str_replace('0', '', $value);

        if (empty($checkZero)) {
            return '0';
        }
        $size = intval(Utils::toBn('0x' . mb_substr($value, 0, 64))->toString());
        $length = 2 * $size;

        return '0x' . mb_substr($value, 64, $length);
    }
}

class Integer extends SolidityType implements IType
{
    /**
     * construct
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * isType
     *
     * @param string $name
     * @return bool
     */
    public function isType($name)
    {
        return (preg_match('/^int([0-9]{1,})?(\[([0-9]*)\])*$/', $name) === 1);
    }

    /**
     * isDynamicType
     *
     * @return bool
     */
    public function isDynamicType()
    {
        return false;
    }

    /**
     * inputFormat
     *
     * @param mixed $value
     * @param string $name
     * @return string
     */
    public function inputFormat($value, $name)
    {
        return IntegerFormatter::format($value);
    }

    /**
     * outputFormat
     *
     * @param mixed $value
     * @param string $name
     * @return string
     */
    public function outputFormat($value, $name)
    {
        $match = [];

        if (preg_match('/^[0]+([a-f0-9]+)$/', $value, $match) === 1) {
            // due to value without 0x prefix, we will parse as decimal
            $value = '0x' . $match[1];
        }
        return BigNumberFormatter::format($value);
    }
}

class SolidityTypeStr extends SolidityType implements IType
{
    /**
     * construct
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * isType
     *
     * @param string $name
     * @return bool
     */
    public function isType($name)
    {
        return (preg_match('/^string(\[([0-9]*)\])*$/', $name) === 1);
    }

    /**
     * isDynamicType
     *
     * @return bool
     */
    public function isDynamicType()
    {
        return true;
    }

    /**
     * inputFormat
     *
     * @param mixed $value
     * @param string $name
     * @return string
     */
    public function inputFormat($value, $name)
    {
        $value = Utils::toHex($value);
        $prefix = IntegerFormatter::format(mb_strlen($value) / 2);
        $l = floor((mb_strlen($value) + 63) / 64);
        $padding = (($l * 64 - mb_strlen($value) + 1) >= 0) ? $l * 64 - mb_strlen($value) : 0;

        return $prefix . $value . implode('', array_fill(0, $padding, '0'));
    }

    /**
     * outputFormat
     *
//     * @param mixed $value
//     * @param string $name
//     * @return string
     */
    public function outputFormat($value, $name)
    {
        $strLen = mb_substr($value, 0, 64);
        $strValue = mb_substr($value, 64);
        $match = [];

        if (preg_match('/^[0]+([a-f0-9]+)$/', $strLen, $match) === 1) {
            $strLen = BigNumberFormatter::format('0x' . $match[1])->toString();
        }
        $strValue = mb_substr($strValue, 0, (int) $strLen * 2);

        return Utils::hexToBin($strValue);
    }
}

class Uinteger extends SolidityType implements IType
{
    /**
     * construct
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * isType
     *
     * @param string $name
     * @return bool
     */
    public function isType($name)
    {
        return (preg_match('/^uint([0-9]{1,})?(\[([0-9]*)\])*$/', $name) === 1);
    }

    /**
     * isDynamicType
     *
     * @return bool
     */
    public function isDynamicType()
    {
        return false;
    }

    /**
     * inputFormat
     *
     * @param mixed $value
     * @param string $name
     * @return string
     */
    public function inputFormat($value, $name)
    {
        return IntegerFormatter::format($value);
    }

    /**
     * outputFormat
     *
     * @param mixed $value
     * @param string $name
     * @return string
     */
    public function outputFormat($value, $name)
    {
        $match = [];

        if (preg_match('/^[0]+([a-f0-9]+)$/', $value, $match) === 1) {
            // due to value without 0x prefix, we will parse as decimal
            $value = '0x' . $match[1];
        }
        return BigNumberFormatter::format($value);
    }
}


/**
 * Contract Ethabi
 */
class Ethabi
{
    /**
     * types
     *
     * @var array
     */
    protected $types = [];

    /**
     * construct
     *
     * @param array $types
     * @return void
     */
    public function __construct($types=[])
    {
        if (!is_array($types)) {
            $types = [];
        }
        $this->types = $types;
    }

    /**
     * get
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        $method = 'get' . ucfirst($name);

        if (method_exists($this, $method)) {
            return call_user_func_array([$this, $method], []);
        }
        return false;
    }

    /**
     * set
     *
     * @param string $name
     * @param mixed $value
     * @return mixed
     */
    public function __set($name, $value)
    {
        $method = 'set' . ucfirst($name);

        if (method_exists($this, $method)) {
            return call_user_func_array([$this, $method], [$value]);
        }
        return false;
    }

    /**
     * callStatic
     *
     * @param string $name
     * @param array $arguments
     * @return void
     */
    public static function __callStatic($name, $arguments)
    {
        //
    }

    /**
     * encodeFunctionSignature
     *
     * @param string|stdClass|array $functionName
     * @return string
     */
    public function encodeFunctionSignature($functionName)
    {
        if (!is_string($functionName)) {
            $functionName = Utils::jsonMethodToString($functionName);
        }
        return mb_substr(Utils::sha3($functionName), 0, 10);
    }

    /**
     * encodeEventSignature
     * TODO: Fix same event name with different params
     *
     * @param string|stdClass|array $functionName
     * @return string
     */
    public function encodeEventSignature($functionName)
    {
        if (!is_string($functionName)) {
            $functionName = Utils::jsonMethodToString($functionName);
        }
        return Utils::sha3($functionName);
    }

    /**
     * encodeParameter
     *
     * @param string $type
     * @param mixed $param
     * @return string
     */
    public function encodeParameter($type, $param)
    {
        if (!is_string($type)) {
            throw new InvalidArgumentException('The type to encodeParameter must be string.');
        }
        return $this->encodeParameters([$type], [$param]);
    }

    /**
     * encodeParameters
     *
     * @param stdClass|array $types
     * @param array $params
     * @return string
     */
    public function encodeParameters($types, $params)
    {
        // change json to array
        if ($types instanceof stdClass && isset($types->inputs)) {
            $types = Utils::jsonToArray($types, 2);
        }
        if (is_array($types) && isset($types['inputs'])) {
            $inputTypes = $types;
            $types = [];

            foreach ($inputTypes['inputs'] as $input) {
                if (isset($input['type'])) {
                    $types[] = $input['type'];
                }
            }
        }

        if (count($types) !== count($params)) {
            throw new InvalidArgumentException('encodeParameters number of types must equal to number of params.');
        }

        $typesLength = count($types);
        $solidityTypes = $this->getSolidityTypes($types);
        $encodes = array_fill(0, $typesLength, '');

        foreach ($solidityTypes as $key => $type) {
            $encodes[$key] = call_user_func([$type, 'encode'], $params[$key], $types[$key]);
        }

        $dynamicOffset = 0;

        foreach ($solidityTypes as $key => $type) {
            $staticPartLength = $type->staticPartLength($types[$key]);
            $roundedStaticPartLength = floor(($staticPartLength + 31) / 32) * 32;

            if ($type->isDynamicType($types[$key]) || $type->isDynamicArray($types[$key])) {
                $dynamicOffset += 32;
            } else {
                $dynamicOffset += $roundedStaticPartLength;
            }
        }

        return '0x' . $this->encodeMultiWithOffset($types, $solidityTypes, $encodes, $dynamicOffset);
    }

    /**
     * decodeParameter
     *
     * @param string $type
     * @param mixed $param
     * @return string
     */
    public function decodeParameter($type, $param)
    {
        if (!is_string($type)) {
            throw new InvalidArgumentException('The type to decodeParameter must be string.');
        }
        return $this->decodeParameters([$type], $param)[0];
    }

    /**
     * decodeParameters
     *
     * @param stdClass|array $type
     * @param string $param
     * @return string
     */
    public function decodeParameters($types, $param)
    {
        if (!is_string($param)) {
            throw new InvalidArgumentException('The type or param to decodeParameters must be string.');
        }

        // change json to array
        if ($types instanceof stdClass && isset($types->outputs)) {
            $types = Utils::jsonToArray($types, 2);
        }
        if (is_array($types) && isset($types['outputs'])) {
            $outputTypes = $types;
            $types = [];

            foreach ($outputTypes['outputs'] as $output) {
                if (isset($output['type'])) {
                    $types[] = $output['type'];
                }
            }
        }
        $typesLength = count($types);
        $solidityTypes = $this->getSolidityTypes($types);
        $offsets = array_fill(0, $typesLength, 0);

        for ($i=0; $i<$typesLength; $i++) {
            $offsets[$i] = $solidityTypes[$i]->staticPartLength($types[$i]);
        }
        for ($i=1; $i<$typesLength; $i++) {
            $offsets[$i] += $offsets[$i - 1];
        }
        for ($i=0; $i<$typesLength; $i++) {
            $offsets[$i] -= $solidityTypes[$i]->staticPartLength($types[$i]);
        }
        $result = [];
        $param = mb_strtolower(Utils::stripZero($param));

        for ($i=0; $i<$typesLength; $i++) {
            if (isset($outputTypes['outputs'][$i]['name']) && empty($outputTypes['outputs'][$i]['name']) === false) {
                $result[$outputTypes['outputs'][$i]['name']] = $solidityTypes[$i]->decode($param, $offsets[$i], $types[$i]);
            } else {
                $result[$i] = $solidityTypes[$i]->decode($param, $offsets[$i], $types[$i]);
            }
        }

        return $result;
    }

    /**
     * getSolidityTypes
     *
     * @param array $types
     * @return array
     */
    protected function getSolidityTypes($types)
    {
        if (!is_array($types)) {
            throw new InvalidArgumentException('Types must be array');
        }
        $solidityTypes = array_fill(0, count($types), 0);

        foreach ($types as $key => $type) {
            $match = [];

            if (preg_match('/^([a-zA-Z]+)/', $type, $match) === 1) {
                if (isset($this->types[$match[0]])) {
                    $className = $this->types[$match[0]];

                    if (call_user_func([$this->types[$match[0]], 'isType'], $type) === false) {
                        // check dynamic bytes
                        if ($match[0] === 'bytes') {
                            $className = $this->types['dynamicBytes'];
                        } else {
                            throw new InvalidArgumentException('Unsupport solidity parameter type: ' . $type);
                        }
                    }
                    $solidityTypes[$key] = $className;
                }
            }
        }
        return $solidityTypes;
    }

    /**
     * encodeWithOffset
     *
     * @param string $type
     * @param \Web3\Contracts\SolidityType $solidityType
     * @param mixed $encode
     * @param int $offset
     * @return string
     */
    protected function encodeWithOffset($type, $solidityType, $encoded, $offset)
    {
        if ($solidityType->isDynamicArray($type)) {
            $nestedName = $solidityType->nestedName($type);
            $nestedStaticPartLength = $solidityType->staticPartLength($type);
            $result = $encoded[0];

            if ($solidityType->isDynamicArray($nestedName)) {
                $previousLength = 2;

                for ($i=0; $i<count($encoded); $i++) {
                    if (isset($encoded[$i - 1])) {
                        $previousLength += abs($encoded[$i - 1][0]);
                    }
                    $result .= IntegerFormatter::format($offset + $i * $nestedStaticPartLength + $previousLength * 32);
                }
            }
            for ($i=0; $i<count($encoded); $i++) {
                // $bn = Utils::toBn($result);
                // $divided = $bn->divide(Utils::toBn(2));

                // if (is_array($divided)) {
                //     $additionalOffset = (int) $divided[0]->toString();
                // } else {
                //     $additionalOffset = 0;
                // }
                $additionalOffset = floor(mb_strlen($result) / 2);
                $result .= $this->encodeWithOffset($nestedName, $solidityType, $encoded[$i], $offset + $additionalOffset);
            }
            return mb_substr($result, 64);
        } elseif ($solidityType->isStaticArray($type)) {
            $nestedName = $solidityType->nestedName($type);
            $nestedStaticPartLength = $solidityType->staticPartLength($type);
            $result = '';

            if ($solidityType->isDynamicArray($nestedName)) {
                $previousLength = 0;

                for ($i=0; $i<count($encoded); $i++) {
                    if (isset($encoded[$i - 1])) {
                        $previousLength += abs($encoded[$i - 1])[0];
                    }
                    $result .= IntegerFormatter::format($offset + $i * $nestedStaticPartLength + $previousLength * 32);
                }
            }
            for ($i=0; $i<count($encoded); $i++) {
                // $bn = Utils::toBn($result);
                // $divided = $bn->divide(Utils::toBn(2));

                // if (is_array($divided)) {
                //     $additionalOffset = (int) $divided[0]->toString();
                // } else {
                //     $additionalOffset = 0;
                // }
                $additionalOffset = floor(mb_strlen($result) / 2);
                $result .= $this->encodeWithOffset($nestedName, $solidityType, $encoded[$i], $offset + $additionalOffset);
            }
            return $result;
        }
        return $encoded;
    }

    /**
     * encodeMultiWithOffset
     *
     * @param array $types
     * @param array $solidityTypes
     * @param array $encodes
     * @param int $dynamicOffset
     * @return string
     */
    protected function encodeMultiWithOffset($types, $solidityTypes, $encodes, $dynamicOffset)
    {
        $result = '';

        foreach ($solidityTypes as $key => $type) {
            if ($type->isDynamicType($types[$key]) || $type->isDynamicArray($types[$key])) {
                $result .= IntegerFormatter::format($dynamicOffset);
                $e = $this->encodeWithOffset($types[$key], $type, $encodes[$key], $dynamicOffset);
                $dynamicOffset += floor(mb_strlen($e) / 2); // 128
            } else {
                $a = $this->encodeWithOffset($types[$key], $type, $encodes[$key], $dynamicOffset);
                $result .= $a;
            }
        }
        foreach ($solidityTypes as $key => $type) {
            if ($type->isDynamicType($types[$key]) || $type->isDynamicArray($types[$key])) {
                $e = $this->encodeWithOffset($types[$key], $type, $encodes[$key], $dynamicOffset);
                $result .= $e;
            }
        }
        return $result;
    }
}


/**
 * Class Contract
 */
class Contract
{
    /**
     * provider
     *
     */
    protected $provider;

    /**
     * abi
     *
     * @var array
     */
    protected $abi;

    /**
     * defaultBlock
     *
     * @var mixed
     */
    protected $defaultBlock;

    /**
     * ethabi
     *
     * @var Ethabi
     */
    protected $ethabi;

    public function __construct($provider, $abi, $defaultBlock = 'latest')
    {
        $this->provider = $provider;
        $this->abi = $abi;
        $this->defaultBlock = $defaultBlock;

        $this->ethabi = new Ethabi([
            'address' => new Address,
            'bool' => new Boolean,
            'bytes' => new Bytes,
            'dynamicBytes' => new DynamicBytes,
            'int' => new Integer,
            'string' => new SolidityTypeStr,
            'uint' => new Uinteger,
        ]);
    }

    public function getEthabi()
    {
        return $this->ethabi;
    }
}


/**
 * HDWalletProvider
 */
class HDWalletProvider {
    private $addresses = [];

    public function __construct($privateKeys, $addressIndex = 0, $numberOfAddresses = 10) {
        if (is_string($privateKeys)) {
            $privateKeys = [$privateKeys];
        }

        if ($privateKeys) {
            $this->ethUtilValidation($privateKeys, $addressIndex);
        }

        if (count($this->addresses) === 0) {
            throw new \Error(
                'Could not create addresses from your mnemonic or private key(s). ' .
                'Please check that your inputs are correct.'
            );
        }
    }

    function ethUtilValidation($privateKeys, $addressIndex) {
        for ($i = $addressIndex; $i < count($privateKeys); $i++) {
            $address = $this->getWalletAddress($privateKeys[$i]);
            $this->addresses[] = $address;
        }
    }

    // wallet getAddressString
    function getWalletAddress($privateKey) {
        $Secp256k1 = new Secp256k1();
        $publicKey = $Secp256k1 -> private2public($privateKey);
        $res = Keccak::hash(hex2bin(mb_substr($publicKey, 2)), 256);
        return '0x' . mb_substr($res, strlen($res) - 40, strlen($res));
    }

    function getAddress($idx=null) {
        if (!$idx) {
            return $this->addresses[0];
        } else {
            return $this->addresses[$idx];
        }
    }

    function getAddresses() {
        return $this->addresses;
    }
}

class KeyPair
{
    public $ec;
    public $pub;
    public $priv;

    function __construct($ec, $options)
    {
        $this->ec = $ec;

        $this->priv = null;
        $this->pub = null;

        if( isset($options["priv"]) )
            $this->_importPrivate($options["priv"], $options["privEnc"]);

        if( isset($options["pub"]) )
            $this->_importPublic($options["pub"], $options["pubEnc"]);
    }

    public static function fromPublic($ec, $pub, $enc)
    {
        if( $pub instanceof KeyPair )
            return $pub;

        return new KeyPair($ec, array(
            "pub" => $pub,
            "pubEnc" => $enc
        ));
    }

    public static function fromPrivate($ec, $priv, $enc)
    {
        if( $priv instanceof KeyPair )
            return $priv;

        return new KeyPair($ec, array(
            "priv" => $priv,
            "privEnc" => $enc
        ));
    }

    public function validate()
    {
        $pub = $this->getPublic();

        if( $pub->isInfinity() )
            return array( "result" => false, "reason" => "Invalid public key" );

        if( !$pub->validate() )
            return array( "result" => false, "reason" => "Public key is not a point" );

        if( !$pub->mul($this->ec->curve->n)->isInfinity() )
            return array( "result" => false, "reason" => "Public key * N != O" );

        return array( "result" => true, "reason" => null );
    }

    public function getPublic($compact = false, $enc = "")
    {
        //compact is optional argument
        if( is_string($compact) )
        {
            $enc = $compact;
            $compact = false;
        }

        if( $this->pub === null )
            $this->pub = $this->ec->g->mul($this->priv);

        if( !$enc )
            return $this->pub;

        return $this->pub->encode($enc, $compact);
    }

    public function getPrivate($enc = false)
    {
        if( $enc === "hex" )
            return $this->priv ? $this->priv->toString(16, 2) : '';

        return $this->priv;
    }

    private function _importPrivate($key, $enc)
    {
        $this->priv = new BN($key, (isset($enc) && $enc) ? $enc : 16);

        // Ensure that the priv won't be bigger than n, otherwise we may fail
        // in fixed multiplication method
        $this->priv = $this->priv->umod($this->ec->curve->n);
    }

    private function _importPublic($key, $enc)
    {
        $x = $y = null;
        if ( is_object($key) ) {
            $x = $key->x;
            $y = $key->y;
        } elseif ( is_array($key) ) {
            $x = isset($key["x"]) ? $key["x"] : null;
            $y = isset($key["y"]) ? $key["y"] : null;
        }

        if( $x != null || $y != null )
            $this->pub = $this->ec->curve->point($x, $y);
        else
            $this->pub = $this->ec->curve->decodePoint($key, $enc);
    }

    //ECDH
    public function derive($pub) {
        return $pub->mul($this->priv)->getX();
    }

    //ECDSA
    public function sign($msg, $enc = false, $options = false) {
        return $this->ec->sign($msg, $this, $enc, $options);
    }

    public function verify($msg, $signature) {
        return $this->ec->verify($msg, $signature, $this);
    }

    public function inspect() {
        return "<Key priv: " . (isset($this->priv) ? $this->priv->toString(16, 2) : "") .
            " pub: " . (isset($this->pub) ? $this->pub->inspect() : "") . ">";
    }

    public function __debugInfo() {
        return ["priv" => $this->priv, "pub" => $this->pub];
    }
}

class Signature
{
    public $r;
    public $s;
    public $recoveryParam;

    function __construct($options, $enc = false)
    {
        if ($options instanceof Signature) {
            $this->r = $options->r;
            $this->s = $options->s;
            $this->recoveryParam = $options->recoveryParam;
            return;
        }

        if (isset($options['r'])) {
            assert(isset($options["r"]) && isset($options["s"])); //, "Signature without r or s");
            $this->r = new BN($options["r"], 16);
            $this->s = new BN($options["s"], 16);

            if( isset($options["recoveryParam"]) )
                $this->recoveryParam = $options["recoveryParam"];
            else
                $this->recoveryParam = null;
            return;
        }

        if (!$this->_importDER($options, $enc))
            throw new \Exception('Unknown signature format');

    }

    private static function getLength($buf, &$pos)
    {
        $initial = $buf[$pos++];
        if( !($initial & 0x80) )
            return $initial;

        $octetLen = $initial & 0xf;
        $val = 0;
        for($i = 0; $i < $octetLen; $i++)
        {
            $val = $val << 8;
            $val = $val | $buf[$pos];
            $pos++;
        }
        return $val;
    }

    private static function rmPadding(&$buf)
    {
        $i = 0;
        $len = count($buf) - 1;
        while($i < $len && !$buf[$i] && !($buf[$i+1] & 0x80) )
            $i++;

        if( $i === 0 )
            return $buf;

        return array_slice($buf, $i);
    }

    private function _importDER($data, $enc)
    {
        $data = Utils::toArray($data, $enc);
        $dataLen = count($data);
        $place = 0;

        if( $data[$place++] !== 0x30)
            return false;

        $len = self::getLength($data, $place);
        if( ($len + $place) !== $dataLen )
            return false;

        if( $data[$place++] !== 0x02 )
            return false;

        $rlen = self::getLength($data, $place);
        $r = array_slice($data, $place, $rlen);
        $place += $rlen;

        if( $data[$place++] !== 0x02 )
            return false;

        $slen = self::getLength($data, $place);
        if( $dataLen !== $slen + $place )
            return false;
        $s = array_slice($data, $place, $slen);

        if( $r[0] === 0 && ($r[1] & 0x80 ) )
            $r = array_slice($r, 1);
        if( $s[0] === 0 && ($s[1] & 0x80 ) )
            $s = array_slice($s, 1);

        $this->r = new BN($r);
        $this->s = new BN($s);
        $this->recoveryParam = null;

        return true;
    }

    private static function constructLength(&$arr, $len)
    {
        if( $len < 0x80 )
        {
            array_push($arr, $len);
            return;
        }

        $octets = 1 + (log($len) / M_LN2 >> 3);
        array_push($arr, $octets | 0x80);
        while(--$octets)
            array_push($arr, ($len >> ($octets << 3)) & 0xff);
        array_push($arr, $len);
    }

    public function toDER($enc = false)
    {
        $r = $this->r->toArray();
        $s = $this->s->toArray();

        //Pad values
        if( $r[0] & 0x80 )
            array_unshift($r, 0);
        if( $s[0] & 0x80 )
            array_unshift($s, 0);

        $r = self::rmPadding($r);
        $s = self::rmPadding($s);

        while(!$s[0] && !($s[1] & 0x80))
            array_slice($s, 1);

        $arr = array(0x02);
        self::constructLength($arr, count($r));
        $arr = array_merge($arr, $r, array(0x02));
        self::constructLength($arr, count($s));
        $backHalf = array_merge($arr, $s);
        $res = array(0x30);
        self::constructLength($res, count($backHalf));
        $res = array_merge($res, $backHalf);

        return Utils::encode($res, $enc);
    }
}

/**
 * Secp256k1
 */
class Secp256k1
{

    private $even = false;
    public $testnet = false;
    public $compressed = false;

    private function inverse($x, $p)
    {
        $inv1 = "1";
        $inv2 = "0";

        while ($p != "0" && $p != "1") {
            list($inv1, $inv2) = array(
                $inv2,
                gmp_sub($inv1, gmp_mul($inv2, gmp_div_q($x, $p)))
            );
            list($x, $p) = array(
                $p,
                gmp_mod($x, $p)
            );
        }
        return $inv2;
    }

    private function dblpt($point, $p)
    {
        if (is_null($point))
            return null;
        list($x, $y) = $point;
        if ($y == "0")
            return null;

        $slope = gmp_mul(gmp_mul(3, (gmp_mod(gmp_pow($x, 2), $p))), $this->inverse(gmp_mul(2, $y), $p));
        $xsum  = gmp_sub(gmp_mod(gmp_pow($slope, 2), $p), gmp_mul(2, $x));
        $ysum  = gmp_sub(gmp_mul($slope, (gmp_sub($x, $xsum))), $y);
        return array(
            gmp_mod($xsum, $p),
            gmp_mod($ysum, $p)
        );
    }

    private function addpt($p1, $p2, $p)
    {
        if ($p1 == null || $p2 == null)
            return null;

        list($x1, $y1) = $p1;
        list($x2, $y2) = $p2;
        if ($x1 == $x2)
            return $this->dblpt($p1, $p);

        $slope = gmp_mul(gmp_sub($y1, $y2), $this->inverse(gmp_sub($x1, $x2), $p));
        $xsum  = gmp_sub(gmp_mod(gmp_pow($slope, 2), $p), gmp_add($x1, $x2));
        $ysum  = gmp_sub(gmp_mul($slope, gmp_sub($x1, $xsum)), $y1);
        return array(
            gmp_mod($xsum, $p),
            gmp_mod($ysum, $p)
        );
    }

    private function ptmul($pt, $a, $p)
    {
        $scale = $pt;
        $acc   = null;

        while (substr($a, 0) != "0") {
            if (gmp_mod($a, 2) != "0") {
                if ($acc == null) {
                    $acc = $scale;
                } else {
                    $acc = $this->addpt($acc, $scale, $p);
                }
            }
            $scale = $this->dblpt($scale, $p);
            $a     = gmp_div($a, 2);
        }
        return $acc;
    }

    public function bchexdec($hex)
    {
        $dec = 0;
        $len = strlen($hex);
        for ($i = 1; $i <= $len; $i++) {
            $dec = bcadd($dec, bcmul(strval(hexdec($hex[$i - 1])), bcpow('16', strval($len - $i))));
        }
        $decArray = explode('.', $dec);
        $dec      = $decArray[0];
        return $dec;
    }

    private function bcdechex($dec)
    {
        $hex = '';
        do {
            $last = bcmod($dec, 16);
            $hex  = dechex($last) . $hex;
            $dec  = bcdiv(bcsub($dec, $last), 16);
        } while ($dec > 0);
        return $hex;
    }

    private function pairToKey($array)
    {
        list($x, $y) = $array;
        $x = $this->bcdechex($x);
        $y = $this->bcdechex($y);
        if (intval(substr($y, -1)) % 2 == 0)
            $this->even = true;
        return ($this->compressed) ? $x : $x . $y;
    }

    public function private2public($privateKey)
    {
        $wif_prefixes = array(
            '9',
            'c',
            '5',
            'K',
            'L'
        );
        if (in_array(substr($privateKey, 0, 1), $wif_prefixes))
            $privateKey = $this->wif2key($privateKey);

        $p      = "115792089237316195423570985008687907853269984665640564039457584007908834671663";
        $Gx     = "55066263022277343669578718895168534326250603453777594175500187360389116729240";
        $Gy     = "32670510020758816978083085130507043184471273380659243275938904335757337482424";
        $g      = array(
            $Gx,
            $Gy
        );
        $n      = $this->bchexdec($privateKey);
        $pair   = $this->ptmul($g, $n, $p);
        $pubKey = $this->pairToKey($pair);
        if ($this->compressed) {
            if ($this->even) {
                return '02' . $pubKey;
            } else {
                return '03' . $pubKey;
            }
        } else
            return '04' . $pubKey;
    }

    public function keyFromPrivate($priv, $enc = false) {
        return KeyPair::fromPrivate($this, $priv, $enc);
    }

    // wif format to private key (hex)
    public function wif2key($wif)
    {
        $wif_prefixes = array(
            '9',
            'c',
            'K',
            'L',
            '5'
        );
        if (!in_array(substr($wif, 0, 1), $wif_prefixes))
            die(json_encode(array(
                "code" => 406,
                "error" => 'Invalid WIF prefix'
            )));

        $end        = (substr($wif, 0, 1) == 'c') ? -10 : -8;
        $privateKey = substr($this->base58Decode($wif), 2, $end);
        return $privateKey;
    }

    public function base58Decode($hex)
    {
        //create val to char array
        $string  = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $int_val = "0";
        for ($i = strlen($hex) - 1, $j = "1", $base = strlen($string); $i >= 0; $i--, $j = gmp_mul($j, $base)) {
            $q       = @gmp_mul($j, strval(strpos($string, $hex[$i])));
            $int_val = gmp_add($int_val, $q);
        }
        $hex = $this->bcdechex($int_val);
        if (strlen($hex) == 47)
            $hex = '0' . $hex; //sometimes the first characters (0?) gets cut off. Why?
        if (!$this->testnet)
            $hex = '00' . $hex;
        return $hex;
    }
}


/**
 * Keccak
 */
final class Keccak
{
    private const KECCAK_ROUNDS = 24;
    private const LFSR = 0x01;
    private const ENCODING = '8bit';
    private static $keccakf_rotc = [1, 3, 6, 10, 15, 21, 28, 36, 45, 55, 2, 14, 27, 41, 56, 8, 25, 43, 62, 18, 39, 61, 20, 44];
    private static $keccakf_piln = [10, 7, 11, 17, 18, 3, 5, 16, 8, 21, 24, 4, 15, 23, 19, 13, 12,2, 20, 14, 22, 9, 6, 1];
    private static $x64 = (PHP_INT_SIZE === 8);

    private static function keccakf64(&$st, $rounds): void {
        $keccakf_rndc = [
            [0x00000000, 0x00000001], [0x00000000, 0x00008082], [0x80000000, 0x0000808a], [0x80000000, 0x80008000],
            [0x00000000, 0x0000808b], [0x00000000, 0x80000001], [0x80000000, 0x80008081], [0x80000000, 0x00008009],
            [0x00000000, 0x0000008a], [0x00000000, 0x00000088], [0x00000000, 0x80008009], [0x00000000, 0x8000000a],
            [0x00000000, 0x8000808b], [0x80000000, 0x0000008b], [0x80000000, 0x00008089], [0x80000000, 0x00008003],
            [0x80000000, 0x00008002], [0x80000000, 0x00000080], [0x00000000, 0x0000800a], [0x80000000, 0x8000000a],
            [0x80000000, 0x80008081], [0x80000000, 0x00008080], [0x00000000, 0x80000001], [0x80000000, 0x80008008]
        ];

        $bc = [];
        for ($round = 0; $round < $rounds; $round++) {

            // Theta
            for ($i = 0; $i < 5; $i++) {
                $bc[$i] = [
                    $st[$i][0] ^ $st[$i + 5][0] ^ $st[$i + 10][0] ^ $st[$i + 15][0] ^ $st[$i + 20][0],
                    $st[$i][1] ^ $st[$i + 5][1] ^ $st[$i + 10][1] ^ $st[$i + 15][1] ^ $st[$i + 20][1]
                ];
            }

            for ($i = 0; $i < 5; $i++) {
                $t = [
                    $bc[($i + 4) % 5][0] ^ (($bc[($i + 1) % 5][0] << 1) | ($bc[($i + 1) % 5][1] >> 31)) & (0xFFFFFFFF),
                    $bc[($i + 4) % 5][1] ^ (($bc[($i + 1) % 5][1] << 1) | ($bc[($i + 1) % 5][0] >> 31)) & (0xFFFFFFFF)
                ];

                for ($j = 0; $j < 25; $j += 5) {
                    $st[$j + $i] = [
                        $st[$j + $i][0] ^ $t[0],
                        $st[$j + $i][1] ^ $t[1]
                    ];
                }
            }

            // Rho Pi
            $t = $st[1];
            for ($i = 0; $i < 24; $i++) {
                $j = self::$keccakf_piln[$i];

                $bc[0] = $st[$j];

                $n = self::$keccakf_rotc[$i];
                $hi = $t[0];
                $lo = $t[1];
                if ($n >= 32) {
                    $n -= 32;
                    $hi = $t[1];
                    $lo = $t[0];
                }

                $st[$j] =[
                    (($hi << $n) | ($lo >> (32 - $n))) & (0xFFFFFFFF),
                    (($lo << $n) | ($hi >> (32 - $n))) & (0xFFFFFFFF)
                ];

                $t = $bc[0];
            }

            //  Chi
            for ($j = 0; $j < 25; $j += 5) {
                for ($i = 0; $i < 5; $i++) {
                    $bc[$i] = $st[$j + $i];
                }
                for ($i = 0; $i < 5; $i++) {
                    $st[$j + $i] = [
                        $st[$j + $i][0] ^ ~$bc[($i + 1) % 5][0] & $bc[($i + 2) % 5][0],
                        $st[$j + $i][1] ^ ~$bc[($i + 1) % 5][1] & $bc[($i + 2) % 5][1]
                    ];
                }
            }

            // Iota
            $st[0] = [
                $st[0][0] ^ $keccakf_rndc[$round][0],
                $st[0][1] ^ $keccakf_rndc[$round][1]
            ];
        }
    }

    private static function keccak64($in_raw, int $capacity, int $outputlength, $suffix, bool $raw_output): string {
        $capacity /= 8;

        $inlen = mb_strlen($in_raw, self::ENCODING);

        $rsiz = 200 - 2 * $capacity;
        $rsizw = $rsiz / 8;

        $st = [];
        for ($i = 0; $i < 25; $i++) {
            $st[] = [0, 0];
        }

        for ($in_t = 0; $inlen >= $rsiz; $inlen -= $rsiz, $in_t += $rsiz) {
            for ($i = 0; $i < $rsizw; $i++) {
                $t = unpack('V*', mb_substr($in_raw, intval($i * 8 + $in_t), 8, self::ENCODING));

                $st[$i] = [
                    $st[$i][0] ^ $t[2],
                    $st[$i][1] ^ $t[1]
                ];
            }

            self::keccakf64($st, self::KECCAK_ROUNDS);
        }

        $temp = mb_substr($in_raw, (int) $in_t, (int) $inlen, self::ENCODING);
        $temp = str_pad($temp, (int) $rsiz, "\x0", STR_PAD_RIGHT);
        $temp = substr_replace($temp, chr($suffix), $inlen, 1);
        $temp = substr_replace($temp, chr(ord($temp[intval($rsiz - 1)]) | 0x80), $rsiz - 1, 1);

        for ($i = 0; $i < $rsizw; $i++) {
            $t = unpack('V*', mb_substr($temp, $i * 8, 8, self::ENCODING));

            $st[$i] = [
                $st[$i][0] ^ $t[2],
                $st[$i][1] ^ $t[1]
            ];
        }

        self::keccakf64($st, self::KECCAK_ROUNDS);

        $out = '';
        for ($i = 0; $i < 25; $i++) {
            $out .= $t = pack('V*', $st[$i][1], $st[$i][0]);
        }
        $r = mb_substr($out, 0, intval($outputlength / 8), self::ENCODING);

        return $raw_output ? $r : bin2hex($r);
    }

    private static function keccakf32(&$st, $rounds): void {
        $keccakf_rndc = [
            [0x0000, 0x0000, 0x0000, 0x0001], [0x0000, 0x0000, 0x0000, 0x8082], [0x8000, 0x0000, 0x0000, 0x0808a], [0x8000, 0x0000, 0x8000, 0x8000],
            [0x0000, 0x0000, 0x0000, 0x808b], [0x0000, 0x0000, 0x8000, 0x0001], [0x8000, 0x0000, 0x8000, 0x08081], [0x8000, 0x0000, 0x0000, 0x8009],
            [0x0000, 0x0000, 0x0000, 0x008a], [0x0000, 0x0000, 0x0000, 0x0088], [0x0000, 0x0000, 0x8000, 0x08009], [0x0000, 0x0000, 0x8000, 0x000a],
            [0x0000, 0x0000, 0x8000, 0x808b], [0x8000, 0x0000, 0x0000, 0x008b], [0x8000, 0x0000, 0x0000, 0x08089], [0x8000, 0x0000, 0x0000, 0x8003],
            [0x8000, 0x0000, 0x0000, 0x8002], [0x8000, 0x0000, 0x0000, 0x0080], [0x0000, 0x0000, 0x0000, 0x0800a], [0x8000, 0x0000, 0x8000, 0x000a],
            [0x8000, 0x0000, 0x8000, 0x8081], [0x8000, 0x0000, 0x0000, 0x8080], [0x0000, 0x0000, 0x8000, 0x00001], [0x8000, 0x0000, 0x8000, 0x8008]
        ];

        $bc = [];
        for ($round = 0; $round < $rounds; $round++) {

            // Theta
            for ($i = 0; $i < 5; $i++) {
                $bc[$i] = [
                    $st[$i][0] ^ $st[$i + 5][0] ^ $st[$i + 10][0] ^ $st[$i + 15][0] ^ $st[$i + 20][0],
                    $st[$i][1] ^ $st[$i + 5][1] ^ $st[$i + 10][1] ^ $st[$i + 15][1] ^ $st[$i + 20][1],
                    $st[$i][2] ^ $st[$i + 5][2] ^ $st[$i + 10][2] ^ $st[$i + 15][2] ^ $st[$i + 20][2],
                    $st[$i][3] ^ $st[$i + 5][3] ^ $st[$i + 10][3] ^ $st[$i + 15][3] ^ $st[$i + 20][3]
                ];
            }

            for ($i = 0; $i < 5; $i++) {
                $t = [
                    $bc[($i + 4) % 5][0] ^ ((($bc[($i + 1) % 5][0] << 1) | ($bc[($i + 1) % 5][1] >> 15)) & (0xFFFF)),
                    $bc[($i + 4) % 5][1] ^ ((($bc[($i + 1) % 5][1] << 1) | ($bc[($i + 1) % 5][2] >> 15)) & (0xFFFF)),
                    $bc[($i + 4) % 5][2] ^ ((($bc[($i + 1) % 5][2] << 1) | ($bc[($i + 1) % 5][3] >> 15)) & (0xFFFF)),
                    $bc[($i + 4) % 5][3] ^ ((($bc[($i + 1) % 5][3] << 1) | ($bc[($i + 1) % 5][0] >> 15)) & (0xFFFF))
                ];

                for ($j = 0; $j < 25; $j += 5) {
                    $st[$j + $i] = [
                        $st[$j + $i][0] ^ $t[0],
                        $st[$j + $i][1] ^ $t[1],
                        $st[$j + $i][2] ^ $t[2],
                        $st[$j + $i][3] ^ $t[3]
                    ];
                }
            }

            // Rho Pi
            $t = $st[1];
            for ($i = 0; $i < 24; $i++) {
                $j = self::$keccakf_piln[$i];
                $bc[0] = $st[$j];


                $n = self::$keccakf_rotc[$i] >> 4;
                $m = self::$keccakf_rotc[$i] % 16;

                $st[$j] =  [
                    ((($t[(0+$n) %4] << $m) | ($t[(1+$n) %4] >> (16-$m))) & (0xFFFF)),
                    ((($t[(1+$n) %4] << $m) | ($t[(2+$n) %4] >> (16-$m))) & (0xFFFF)),
                    ((($t[(2+$n) %4] << $m) | ($t[(3+$n) %4] >> (16-$m))) & (0xFFFF)),
                    ((($t[(3+$n) %4] << $m) | ($t[(0+$n) %4] >> (16-$m))) & (0xFFFF))
                ];

                $t = $bc[0];
            }

            //  Chi
            for ($j = 0; $j < 25; $j += 5) {
                for ($i = 0; $i < 5; $i++) {
                    $bc[$i] = $st[$j + $i];
                }
                for ($i = 0; $i < 5; $i++) {
                    $st[$j + $i] = [
                        $st[$j + $i][0] ^ ~$bc[($i + 1) % 5][0] & $bc[($i + 2) % 5][0],
                        $st[$j + $i][1] ^ ~$bc[($i + 1) % 5][1] & $bc[($i + 2) % 5][1],
                        $st[$j + $i][2] ^ ~$bc[($i + 1) % 5][2] & $bc[($i + 2) % 5][2],
                        $st[$j + $i][3] ^ ~$bc[($i + 1) % 5][3] & $bc[($i + 2) % 5][3]
                    ];
                }
            }

            // Iota
            $st[0] = [
                $st[0][0] ^ $keccakf_rndc[$round][0],
                $st[0][1] ^ $keccakf_rndc[$round][1],
                $st[0][2] ^ $keccakf_rndc[$round][2],
                $st[0][3] ^ $keccakf_rndc[$round][3]
            ];
        }
    }

    private static function keccak32($in_raw, int $capacity, int $outputlength, $suffix, bool $raw_output): string {
        $capacity /= 8;

        $inlen = mb_strlen($in_raw, self::ENCODING);

        $rsiz = 200 - 2 * $capacity;
        $rsizw = $rsiz / 8;

        $st = [];
        for ($i = 0; $i < 25; $i++) {
            $st[] = [0, 0, 0, 0];
        }

        for ($in_t = 0; $inlen >= $rsiz; $inlen -= $rsiz, $in_t += $rsiz) {
            for ($i = 0; $i < $rsizw; $i++) {
                $t = unpack('v*', mb_substr($in_raw, intval($i * 8 + $in_t), 8, self::ENCODING));

                $st[$i] = [
                    $st[$i][0] ^ $t[4],
                    $st[$i][1] ^ $t[3],
                    $st[$i][2] ^ $t[2],
                    $st[$i][3] ^ $t[1]
                ];
            }

            self::keccakf32($st, self::KECCAK_ROUNDS);
        }

        $temp = mb_substr($in_raw, (int) $in_t, (int) $inlen, self::ENCODING);
        $temp = str_pad($temp, (int) $rsiz, "\x0", STR_PAD_RIGHT);
        $temp = substr_replace($temp, chr($suffix), $inlen, 1);
        $temp = substr_replace($temp, chr((int) $temp[intval($rsiz - 1)] | 0x80), $rsiz - 1, 1);

        for ($i = 0; $i < $rsizw; $i++) {
            $t = unpack('v*', mb_substr($temp, $i * 8, 8, self::ENCODING));

            $st[$i] = [
                $st[$i][0] ^ $t[4],
                $st[$i][1] ^ $t[3],
                $st[$i][2] ^ $t[2],
                $st[$i][3] ^ $t[1]
            ];
        }

        self::keccakf32($st, self::KECCAK_ROUNDS);

        $out = '';
        for ($i = 0; $i < 25; $i++) {
            $out .= $t = pack('v*', $st[$i][3],$st[$i][2], $st[$i][1], $st[$i][0]);
        }
        $r = mb_substr($out, 0, intval($outputlength / 8), self::ENCODING);

        return $raw_output ? $r: bin2hex($r);
    }

    private static function keccak($in_raw, int $capacity, int $outputlength, $suffix, bool $raw_output): string {
        return self::$x64
            ? self::keccak64($in_raw, $capacity, $outputlength, $suffix, $raw_output)
            : self::keccak32($in_raw, $capacity, $outputlength, $suffix, $raw_output);
    }

    public static function hash($in, int $mdlen, bool $raw_output = false): string {
        if (!in_array($mdlen, [224, 256, 384, 512], true)) {
            throw new Exception('Unsupported Keccak Hash output size.');
        }

        return self::keccak($in, $mdlen, $mdlen, self::LFSR, $raw_output);
    }

    public static function shake($in, int $security_level, int $outlen, bool $raw_output = false): string {
        if (!in_array($security_level, [128, 256], true)) {
            throw new Exception('Unsupported Keccak Shake security level.');
        }

        return self::keccak($in, $security_level, $outlen, 0x1f, $raw_output);
    }

}


/**
 * Transaction
 */
/**
 * It's a instance for generating/serializing ethereum transaction.
 *
 * ```php
 * use Web3p\EthereumTx\Transaction;
 *
 * // generate transaction instance with transaction parameters
 * $transaction = new Transaction([
 *     'nonce' => '0x01',
 *     'from' => '0xb60e8dd61c5d32be8058bb8eb970870f07233155',
 *     'to' => '0xd46e8dd67c5d32be8058bb8eb970870f07244567',
 *     'gas' => '0x76c0',
 *     'gasPrice' => '0x9184e72a000',
 *     'value' => '0x9184e72a',
 *     'chainId' => 1, // optional
 *     'data' => '0xd46e8dd67c5d32be8d46e8dd67c5d32be8058bb8eb970870f072445675058bb8eb970870f072445675'
 * ]);
 *
 * // generate transaction instance with hex encoded transaction
 * $transaction = new Transaction('0xf86c098504a817c800825208943535353535353535353535353535353535353535880de0b6b3a76400008025a028ef61340bd939bc2195fe537567866003e1a15d3c71ff63e1590620aa636276a067cbe9d8997f761aecb703304b3800ccf555c9f3dc64214b297fb1966a3b6d83');
 * ```
 *
 * ```php
 * After generate transaction instance, you can sign transaction with your private key.
 * <code>
 * $signedTransaction = $transaction->sign('your private key');
 * ```
 *
 * Then you can send serialized transaction to ethereum through http rpc with web3.php.
 * ```php
 * $hashedTx = $transaction->serialize();
 * ```
 *
 * @author Peter Lai <alk03073135@gmail.com>
 * @link https://www.web3p.xyz
 * @filesource https://github.com/web3p/ethereum-tx
 */
class Transaction implements ArrayAccess
{
    /**
     * Attribute map for keeping order of transaction key/value
     *
     * @var array
     */
    protected $attributeMap = [
        'from' => [
            'key' => -1
        ],
        'chainId' => [
            'key' => -2
        ],
        'nonce' => [
            'key' => 0,
            'length' => 32,
            'allowLess' => true,
            'allowZero' => false
        ],
        'gasPrice' => [
            'key' => 1,
            'length' => 32,
            'allowLess' => true,
            'allowZero' => false
        ],
        'gasLimit' => [
            'key' => 2,
            'length' => 32,
            'allowLess' => true,
            'allowZero' => false
        ],
        'gas' => [
            'key' => 2,
            'length' => 32,
            'allowLess' => true,
            'allowZero' => false
        ],
        'to' => [
            'key' => 3,
            'length' => 20,
            'allowZero' => true,
        ],
        'value' => [
            'key' => 4,
            'length' => 32,
            'allowLess' => true,
            'allowZero' => false
        ],
        'data' => [
            'key' => 5,
            'allowLess' => true,
            'allowZero' => true
        ],
        'v' => [
            'key' => 6,
            'allowZero' => true
        ],
        'r' => [
            'key' => 7,
            'length' => 32,
            'allowZero' => true
        ],
        's' => [
            'key' => 8,
            'length' => 32,
            'allowZero' => true
        ]
    ];

    /**
     * Raw transaction data
     *
     * @var array
     */
    protected $txData = [];

    /**
     * RLP encoding instance
     *
     * @var \Web3p\RLP\RLP
     */
    protected $rlp;

    /**
     * secp256k1 elliptic curve instance
     *
     * @var \Elliptic\EC
     */
    protected $secp256k1;

    /**
     * Private key instance
     *
     * @var \Elliptic\EC\KeyPair
     */
    protected $privateKey;

    /**
     * Ethereum util instance
     *
     * @var \Web3p\EthereumUtil\Util
     */
    protected $util;

    /**
     * construct
     *
     * @param array|string $txData
     * @return void
     */
    public function __construct($txData=[])
    {
        $this->rlp = new RLP;
        $this->secp256k1 = new EC('secp256k1');
        $this->util = new Utils();

        if (is_array($txData)) {
            foreach ($txData as $key => $data) {
                $this->offsetSet($key, $data);
            }
        } elseif (is_string($txData)) {
            $tx = [];

            if ($this->util->isHex($txData)) {
                $txData = $this->rlp->decode($txData);

                foreach ($txData as $txKey => $data) {
                    if (is_int($txKey)) {
                        $hexData = $data;

                        if (strlen($hexData) > 0) {
                            $tx[$txKey] = '0x' . $hexData;
                        } else {
                            $tx[$txKey] = $hexData;
                        }
                    }
                }
            }
            $this->txData = $tx;
        }
    }

    /**
     * Return the value in the transaction with given key or return the protected property value if get(property_name} function is existed.
     *
     * @param string $name key or protected property name
     * @return mixed
     */
    public function __get(string $name)
    {
        $method = 'get' . ucfirst($name);

        if (method_exists($this, $method)) {
            return call_user_func_array([$this, $method], []);
        }
        return $this->offsetGet($name);
    }

    /**
     * Set the value in the transaction with given key or return the protected value if set(property_name} function is existed.
     *
     * @param string $name key, eg: to
     * @param mixed value
     * @return void
     */
    public function __set(string $name, $value)
    {
        $method = 'set' . ucfirst($name);

        if (method_exists($this, $method)) {
            return call_user_func_array([$this, $method], [$value]);
        }
        return $this->offsetSet($name, $value);
    }

    /**
     * Return hash of the ethereum transaction without signature.
     *
     * @return string hex encoded of the transaction
     */
    public function __toString()
    {
        return $this->hash(false);
    }

    /**
     * Set the value in the transaction with given key.
     *
     * @param string $offset key, eg: to
     * @param string value
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        $txKey = isset($this->attributeMap[$offset]) ? $this->attributeMap[$offset] : null;

        if (is_array($txKey)) {
            $checkedValue = ($value) ? (string) $value : '';
            $isHex = $this->util->isHex($checkedValue);
            $checkedValue = $this->util->stripZero($checkedValue);

            if (!isset($txKey['allowLess']) || (isset($txKey['allowLess']) && $txKey['allowLess'] === false)) {
                // check length
                if (isset($txKey['length'])) {
                    if ($isHex) {
                        if (strlen($checkedValue) > $txKey['length'] * 2) {
                            throw new InvalidArgumentException($offset . ' exceeds the length limit.');
                        }
                    } else {
                        if (strlen($checkedValue) > $txKey['length']) {
                            throw new InvalidArgumentException($offset . ' exceeds the length limit.');
                        }
                    }
                }
            }
            if (!isset($txKey['allowZero']) || (isset($txKey['allowZero']) && $txKey['allowZero'] === false)) {
                // check zero
                if (preg_match('/^0*$/', $checkedValue) === 1) {
                    // set value to empty string
                    $value = '';
                }
            }
            $this->txData[$txKey['key']] = $value;
        }
    }

    /**
     * Return whether the value is in the transaction with given key.
     *
     * @param string $offset key, eg: to
     * @return bool
     */
    public function offsetExists($offset)
    {
        $txKey = isset($this->attributeMap[$offset]) ? $this->attributeMap[$offset] : null;

        if (is_array($txKey)) {
            return isset($this->txData[$txKey['key']]);
        }
        return false;
    }

    /**
     * Unset the value in the transaction with given key.
     *
     * @param string $offset key, eg: to
     * @return void
     */
    public function offsetUnset($offset)
    {
        $txKey = isset($this->attributeMap[$offset]) ? $this->attributeMap[$offset] : null;

        if (is_array($txKey) && isset($this->txData[$txKey['key']])) {
            unset($this->txData[$txKey['key']]);
        }
    }

    /**
     * Return the value in the transaction with given key.
     *
     * @param string $offset key, eg: to
     * @return mixed value of the transaction
     */
    public function offsetGet($offset)
    {
        $txKey = isset($this->attributeMap[$offset]) ? $this->attributeMap[$offset] : null;

        if (is_array($txKey) && isset($this->txData[$txKey['key']])) {
            return $this->txData[$txKey['key']];
        }
        return null;
    }

    /**
     * Return raw ethereum transaction data.
     *
     * @return array raw ethereum transaction data
     */
    public function getTxData()
    {
        return $this->txData;
    }

    /**
     * RLP serialize the ethereum transaction.
     *
     * @return \Web3p\RLP\RLP\Buffer serialized ethereum transaction
     */
    public function serialize()
    {
        $chainId = $this->offsetGet('chainId');

        // sort tx data
        if (ksort($this->txData) !== true) {
            throw new RuntimeException('Cannot sort tx data by keys.');
        }
        if ($chainId && $chainId > 0) {
            $txData = array_fill(0, 9, '');
        } else {
            $txData = array_fill(0, 6, '');
        }
        foreach ($this->txData as $key => $data) {
            if ($key >= 0) {
                $txData[$key] = $data;
            }
        }
        return $this->rlp->encode($txData);
    }

    /**
     * Sign the transaction with given hex encoded private key.
     *
     * @param string $privateKey hex encoded private key
     * @return string hex encoded signed ethereum transaction
     */
    public function sign(string $privateKey)
    {
        if ($this->util->isHex($privateKey)) {
            $privateKey = $this->util->stripZero($privateKey);
            $ecPrivateKey = $this->secp256k1->keyFromPrivate($privateKey, 'hex');
        } else {
            throw new InvalidArgumentException('Private key should be hex encoded string');
        }
        $txHash = $this->hash(false);
        $signature = $ecPrivateKey->sign($txHash, [
            'canonical' => true
        ]);
        $r = $signature->r;
        $s = $signature->s;
        $v = $signature->recoveryParam + 35;

        $chainId = $this->offsetGet('chainId');

        if ($chainId && $chainId > 0) {
            $v += (int) $chainId * 2;
        }

        $this->offsetSet('r', '0x' . $r->toString(16));
        $this->offsetSet('s', '0x' . $s->toString(16));
        $this->offsetSet('v', $v);
        $this->privateKey = $ecPrivateKey;

        return $this->serialize();
    }

    /**
     * Return hash of the ethereum transaction with/without signature.
     *
     * @param bool $includeSignature hash with signature
     * @return string hex encoded hash of the ethereum transaction
     */
    public function hash(bool $includeSignature=false)
    {
        $chainId = $this->offsetGet('chainId');

        // sort tx data
        if (ksort($this->txData) !== true) {
            throw new RuntimeException('Cannot sort tx data by keys.');
        }
        if ($includeSignature) {
            $txData = $this->txData;
        } else {
            $rawTxData = $this->txData;

            if ($chainId && $chainId > 0) {
                $v = (int) $chainId;
                $this->offsetSet('r', '');
                $this->offsetSet('s', '');
                $this->offsetSet('v', $v);
                $txData = array_fill(0, 9, '');
            } else {
                $txData = array_fill(0, 6, '');
            }

            foreach ($this->txData as $key => $data) {
                if ($key >= 0) {
                    $txData[$key] = $data;
                }
            }
            $this->txData = $rawTxData;
        }
        $serializedTx = $this->rlp->encode($txData);

        return $this->util->sha3(hex2bin($serializedTx));
    }

    /**
     * Recover from address with given signature (r, s, v) if didn't set from.
     *
     * @return string hex encoded ethereum address
     */
    public function getFromAddress()
    {
        $from = $this->offsetGet('from');

        if ($from) {
            return $from;
        }
        if (!isset($this->privateKey) || !($this->privateKey instanceof KeyPair)) {
            // recover from hash
            $r = $this->offsetGet('r');
            $s = $this->offsetGet('s');
            $v = $this->offsetGet('v');
            $chainId = $this->offsetGet('chainId');

            if (!$r || !$s) {
                throw new RuntimeException('Invalid signature r and s.');
            }
            $txHash = $this->hash(false);

            if ($chainId && $chainId > 0) {
                $v -= ($chainId * 2);
            }
            $v -= 35;
            $publicKey = $this->secp256k1->recoverPubKey($txHash, [
                'r' => $r,
                's' => $s
            ], $v);
            $publicKey = $publicKey->encode('hex');
        } else {
            $publicKey = $this->privateKey->getPublic(false, 'hex');
        }
        $from = '0x' . substr($this->util->sha3(substr(hex2bin($publicKey), 1)), 24);

        $this->offsetSet('from', $from);
        return $from;
    }
}


/**
 * web3p RLP
 */

/**
 * It's a string type instance for ethereum recursive length encoding.
 * Note: there is only static function in this class.
 *
 */
class RLPStr
{
    /**
     * Return encoded string of given input and encoding.
     *
     * @param string $input input
     * @param string $encoding encoding
     * @return string encoded string of given input and encoding
     */
    static function encode(string $input, string $encoding='utf8')
    {
        $output = '';
        switch ($encoding) {
            case 'hex':
                if (strpos($input, '0x') === 0) {
                    $input = str_replace('0x', '', $input);
                }
                $output = $input;

                break;
            case 'ascii':
                $outputs = array_map('ord', str_split($input, 1));
                foreach ($outputs as $src) {
                    $output .= dechex($src);
                }
                break;
            case 'utf8':
                $outputs = unpack('C*', $input);
                foreach ($outputs as $src) {
                    $output .= dechex($src);
                }
                break;
            default:
                throw new InvalidArgumentException('Didn\'t support the encoding.');
                break;
        }
        $outputLen = mb_strlen($output);
        if ($outputLen > 0 && $outputLen % 2 !== 0) {
            return '0' . $output;
        }
        return $output;
    }

    /**
     * Return decoded hex encoded of given input, same with hex2bin
     *
     * @param string $input hex encoded string
     * @return string decoded of hex encoded string
     */
    static function decodeHex(string $input)
    {
        if (strpos($input, '0x') === 0) {
            $input = str_replace('0x', '', $input);
        }
        if (!preg_match('/[a-f0-9]+/i', $input)) {
            throw new InvalidArgumentException('Invalid hex string.');
        }
        $inputLen = mb_strlen($input);
        if ($inputLen > 0 && $inputLen % 2 !== 0) {
            $input = '0' . $input;
            $inputLen += 1;
        }
        $output = '';
        $start = 0;
        while ($start < $inputLen) {
            $hex = mb_substr($input, $start, 2);
            $chr = chr(hexdec($hex));
            $output .= $chr;
            $start += 2;
        }
        return $output;
    }
}

class RLPNumeric
{
    /**
     * Return hex encoded of numeric string.
     *
     * @param string $input numeric string
     * @return string encoded hex of input
     */
    static function encode(string $input)
    {
        if (!$input || $input < 0) {
            return '';
        }
        if (is_float($input)) {
            $input = number_format($input, 0, '', '');
        }
        $intInput = strval($input);
        $output = dechex($intInput);
        $outputLen = mb_strlen($output);
        if ($outputLen > 0 && $outputLen % 2 !== 0) {
            return '0' . $output;
        }
        return $output;
    }
}

/**
 * It's a instance for ethereum recursive length encoding.
 *
 * RLP encode:
 *
 * ```php
 * use Web3p\RLP\RLP;

 * $rlp = new RLP;
 * // c483646f67
 * $encoded = $rlp->encode(['dog']);
 *
 * // 83646f67
 * $encoded = $rlp->encode('dog');
 * ```
 *
 * RLP decode:
 *
 * ```php
 * use Web3p\RLP\RLP;
 * use Web3p\RLP\Types\Str;
 *
 * $rlp = new RLP;
 * $encoded = $rlp->encode(['dog']);
 *
 * // only accept 0x prefixed hex string
 * $decoded = $rlp->decode('0x' . $encoded);
 *
 * // show 646f67
 * echo $decoded[0];
 *
 * // show dog
 * echo hex2bin($decoded[0]);
 *
 * // or you can
 * echo Str::decodeHex($decoded[0]);
 * ```
 *
 * @author Peter Lai <alk03073135@gmail.com>
 * @link https://www.web3p.xyz
 * @filesource https://github.com/web3p/rlp
 */
class RLP
{
    /**
     * Return RLP encoded of the given inputs.
     *
     * @param mixed $inputs mixed type of data you want to RLP encode
     * @return string RLP encoded hex string of inputs
     */
    public function encode($inputs)
    {
        $output = '';
        if (is_array($inputs)) {
            foreach ($inputs as $input) {
                $output .= $this->encode($input);
            }
            $length = mb_strlen($output) / 2;
            return $this->encodeLength($length, 192) . $output;
        }
        $input = $this->encodeInput($inputs);
        $length = mb_strlen($input) / 2;

        // first byte < 0x80
        if ($length === 1 && mb_substr($input, 0, 1) < 8) {
            return $input;
        }
        return $this->encodeLength($length, 128) . $input;
    }

    /**
     * Return RLP decoded of the given hex encoded data.
     *
     * @param string $input hex encoded data
     * @return array decoded data
     */
    public function decode(string $input)
    {
        if (strpos($input, '0x') === 0) {
            $input = str_replace('0x', '', $input);
        }
        if (!preg_match('/[a-f0-9]/i', $input)) {
            throw new InvalidArgumentException('The input type didn\'t support.');
        }
        $input = $this->padToEven($input);
        $decoded = $this->decodeData($input);
        return $decoded['data'];
    }

    /**
     * Main function of RLP decode.
     *
     * @param string $input hex encoded data
     * @return array decoded data
     */
    protected function decodeData(string $input)
    {
        $firstByte = mb_substr($input, 0, 2);
        $firstByteDec = hexdec($firstByte);

        if ($firstByteDec <= 0x7f) {
            return [
                'data' => $firstByte,
                'remainder' => mb_substr($input, 2)
            ];
        } elseif ($firstByteDec <= 0xb7) {
            $length = $firstByteDec - 0x7f;
            $data = '';

            if ($firstByteDec !== 0x80) {
                $data = mb_substr($input, 2, ($length - 1) * 2);
            }
            $firstByteData = hexdec(mb_substr($data, 0, 2));
            if ($length === 2 && $firstByteData < 0x80) {
                throw new RuntimeException('Byte must be less than 0x80.');
            }
            return [
                'data' => $data,
                'remainder' => mb_substr($input, $length * 2)
            ];
        } elseif ($firstByteDec <= 0xbf) {
            $llength = $firstByteDec - 0xb6;
            $hexLength = mb_substr($input, 2, ($llength - 1) * 2);

            if ($hexLength === '00') {
                throw new RuntimeException('Invalid RLP.');
            }
            $length = hexdec($hexLength);
            $data = mb_substr($input, $llength * 2, $length * 2);

            if (mb_strlen($data) < $length * 2) {
                throw new RuntimeException('Invalid RLP.');
            }
            return [
                'data' => $data,
                'remainder' => mb_substr($input, ($length + $llength) * 2)
            ];
        } elseif ($firstByteDec <= 0xf7) {
            $length = $firstByteDec - 0xbf;
            $innerRemainder = mb_substr($input, 2, ($length - 1) * 2);
            $decoded = [];

            while (mb_strlen($innerRemainder)) {
                $data = $this->decodeData($innerRemainder);
                $decoded[] = $data['data'];
                $innerRemainder = $data['remainder'];
            }
            return [
                'data' => $decoded,
                'remainder' => mb_substr($input, $length * 2)
            ];
        } else {
            $llength = $firstByteDec - 0xf6;
            $hexLength = mb_substr($input, 2, ($llength - 1) * 2);
            $decoded = [];

            if ($hexLength === '00') {
                throw new RuntimeException('Invalid RLP.');
            }
            $length = hexdec($hexLength);
            $totalLength = $llength + $length;

            if ($totalLength * 2 > mb_strlen($input)) {
                throw new RuntimeException('Invalid RLP: total length is bigger than data length.');
            }
            $innerRemainder = $hexLength = mb_substr($input, $llength * 2, $totalLength * 2);

            if (mb_strlen($innerRemainder) === 0) {
                throw new RuntimeException('Invalid RLP: list has invalid length.');
            }

            while (mb_strlen($innerRemainder)) {
                $data = $this->decodeData($innerRemainder);
                $decoded[] = $data['data'];
                $innerRemainder = $data['remainder'];
            }
            return [
                'data' => $decoded,
                'remainder' => mb_substr($input, $length * 2)
            ];
        }
    }

    /**
     * Return RLP encoded the length of data.
     *
     * @param int $length length of data
     * @param int $offset offset of data
     * @return string hex encoded of the length
     */
    protected function encodeLength(int $length, int $offset)
    {
        if ($length < 56) {
            return dechex(strval($length + $offset));
        }
        $hexLength = $this->intToHex($length);
        $firstByte = $this->intToHex($offset + 55 + (strlen($hexLength) / 2));
        return $firstByte . $hexLength;
    }

    /**
     * Return hex of the given integer.
     *
     * @param int $input integer
     * @return string hex encoded of the input
     */
    protected function intToHex(int $input)
    {
        $hex = dechex($input);

        return $this->padToEven($hex);
    }

    /**
     * Pad hex encoded data to even length (add 0).
     *
     * @param string $input hex encoded string
     * @return string hex encoded string
     */
    protected function padToEven(string $input)
    {
        if ((strlen($input) % 2) !== 0 ) {
            $input = '0' . $input;
        }
        return $input;
    }

    /**
     * Main encode function to transform data to hex encoded string.
     *
     * @param mixed $input data
     * @return string hex encoded string
     */
    protected function encodeInput($input)
    {
        if (is_string($input)) {
            if (strpos($input, '0x') === 0) {
                return RLPStr::encode($input, 'hex');
            }
            return RLPStr::encode($input);
        } elseif (is_numeric($input)) {
            return RLPNumeric::encode($input);
        } elseif ($input === null) {
            return '';
        }
        throw new InvalidArgumentException('The input type didn\'t support.');
    }
}

class EllipticPHPUtils
{
    public static function toArray($msg, $enc = false)
    {
        if( is_array($msg) )
            return array_slice($msg, 0);

        if( !$msg )
            return array();

        if( !is_string($msg) )
            throw new Exception("Not implemented");

        if( !$enc )
            return array_slice(unpack("C*", $msg), 0);

        if( $enc === "hex" )
            return array_slice(unpack("C*", hex2bin($msg)), 0);

        return $msg;
    }

    public static function toHex($msg)
    {
        if( is_string($msg) )
            return bin2hex($msg);

        if( !is_array($msg) )
            throw new Exception("Not implemented");

        $binary = call_user_func_array("pack", array_merge(["C*"], $msg));
        return bin2hex($binary);
    }

    public static function toBin($msg, $enc = false)
    {
        if( is_array($msg) )
            return call_user_func_array("pack", array_merge(["C*"], $msg));

        if( $enc === "hex" )
            return hex2bin($msg);

        return $msg;
    }

    public static function encode($arr, $enc)
    {
        if( $enc === "hex" )
            return self::toHex($arr);
        return $arr;
    }

    // Represent num in a w-NAF form
    public static function getNAF($num, $w)
    {
        $naf = array();
        $ws = 1 << ($w + 1);
        $k = clone($num);

        while( $k->cmpn(1) >= 0 )
        {
            if( !$k->isOdd() )
                array_push($naf, 0);
            else
            {
                $mod = $k->andln($ws - 1);
                $z = $mod;
                if( $mod > (($ws >> 1) - 1))
                    $z = ($ws >> 1) - $mod;
                $k->isubn($z);
                array_push($naf, $z);
            }

            // Optimization, shift by word if possible
            $shift = (!$k->isZero() && $k->andln($ws - 1) === 0) ? ($w + 1) : 1;
            for($i = 1; $i < $shift; $i++)
                array_push($naf, 0);
            $k->iushrn($shift);
        }

        return $naf;
    }

    // Represent k1, k2 in a Joint Sparse Form
    public static function getJSF($k1, $k2)
    {
        $jsf = array( array(), array() );
        $k1 = $k1->_clone();
        $k2 = $k2->_clone();
        $d1 = 0;
        $d2 = 0;

        while( $k1->cmpn(-$d1) > 0 || $k2->cmpn(-$d2) > 0 )
        {
            // First phase
            $m14 = ($k1->andln(3) + $d1) & 3;
            $m24 = ($k2->andln(3) + $d2) & 3;
            if( $m14 === 3 )
                $m14 = -1;
            if( $m24 === 3 )
                $m24 = -1;

            $u1 = 0;
            if( ($m14 & 1) !== 0 )
            {
                $m8 = ($k1->andln(7) + $d1) & 7;
                $u1 = ( ($m8 === 3 || $m8 === 5) && $m24 === 2 ) ? -$m14 : $m14;
            }
            array_push($jsf[0], $u1);

            $u2 = 0;
            if( ($m24 & 1) !== 0 )
            {
                $m8 = ($k2->andln(7) + $d2) & 7;
                $u2 = ( ($m8 === 3 || $m8 === 5) && $m14 === 2 ) ? -$m24 : $m24;
            }
            array_push($jsf[1], $u2);

            // Second phase
            if( (2 * $d1) === ($u1 + 1) )
                $d1 = 1 - $d1;
            if( (2 * $d2) === ($u2 + 1) )
                $d2 = 1 - $d2;
            $k1->iushrn(1);
            $k2->iushrn(1);
        }

        return $jsf;
    }

    public static function intFromLE($bytes) {
        return new BN($bytes, 'hex', 'le');
    }

    public static function parseBytes($bytes) {
        if (is_string($bytes))
            return self::toArray($bytes, 'hex');
        return $bytes;
    }

    public static function randBytes($count)
    {
        $res = "";
        for($i = 0; $i < $count; $i++)
            $res .= chr(rand(0, 255));
        return $res;
    }

    public static function optionAssert(&$array, $key, $value = false, $required = false)
    {
        if( isset($array[$key]) )
            return;
        if( $required )
            throw new Exception("Missing option " . $key);
        $array[$key] = $value;
    }
}

class HmacDRBG
{
    private $hash;
    private $predResist;
    private $outLen;
    private $minEntropy;
    private $reseed;
    private $reseedInterval;
    private $K;
    private $V;

    function __construct($options)
    {
        EllipticPHPUtils::optionAssert($options, "predResist");
        EllipticPHPUtils::optionAssert($options, "hash", null, true);
        EllipticPHPUtils::optionAssert($options["hash"], "outSize", null, true);
        EllipticPHPUtils::optionAssert($options["hash"], "hmacStrength", null, true);
        EllipticPHPUtils::optionAssert($options["hash"], "algo", null, true);
        EllipticPHPUtils::optionAssert($options, "minEntropy");
        EllipticPHPUtils::optionAssert($options, "entropy", null, true);
        EllipticPHPUtils::optionAssert($options, "entropyEnc");
        EllipticPHPUtils::optionAssert($options, "nonce", "");
        EllipticPHPUtils::optionAssert($options, "nonceEnc");
        EllipticPHPUtils::optionAssert($options, "pers", "");
        EllipticPHPUtils::optionAssert($options, "persEnc");

        $this->hash = $options["hash"];
        $this->predResist = $options["predResist"];

        $this->outLen = $this->hash["outSize"];
        $this->minEntropy = $options["minEntropy"] ?: $this->hash["hmacStrength"];

        $this->reseed = null;
        $this->reseedInterval = null;
        $this->K = null;
        $this->V = null;

        $entropy  = EllipticPHPUtils::toBin($options["entropy"], $options["entropyEnc"]);
        $nonce  = EllipticPHPUtils::toBin($options["nonce"], $options["nonceEnc"]);
        $pers  = EllipticPHPUtils::toBin($options["pers"], $options["persEnc"]);

        if (assert_options(ASSERT_ACTIVE)) {
            assert(strlen($entropy) >= ($this->minEntropy / 8));
        }
        $this->_init($entropy, $nonce, $pers);
    }

    private function _init($entropy, $nonce, $pers)
    {
        $seed = $entropy . $nonce . $pers;

        $this->K = str_repeat(chr(0x00), $this->outLen / 8);
        $this->V = str_repeat(chr(0x01), $this->outLen / 8);

        $this->_update($seed);
        $this->reseed = 1;
        $this->reseedInterval = 0x1000000000000; // 2^48
    }

    private function _hmac()
    {
        return hash_init($this->hash["algo"], HASH_HMAC, $this->K ? : '');
    }

    private function _update($seed = false)
    {
        $kmac = $this->_hmac();
        hash_update($kmac, $this->V ?: '');
        hash_update($kmac, chr(0x00));

        if( $seed )
            hash_update($kmac, $seed);
        $this->K = hash_final($kmac, true);

        $kmac = $this->_hmac();
        hash_update($kmac, $this->V ?: '');
        $this->V = hash_final($kmac, true);

        if(!$seed)
            return;

        $kmac = $this->_hmac();
        hash_update($kmac, $this->V ?: '');
        hash_update($kmac, chr(0x01));
        hash_update($kmac, $seed);
        $this->K = hash_final($kmac, true);

        $kmac = $this->_hmac();
        hash_update($kmac, $this->V ?: '');
        $this->V = hash_final($kmac, true);
    }

    // TODO: reseed()

    public function generate($len, $enc = null, $add = null, $addEnc = null)
    {
        if ($this->reseed > $this->reseedInterval)
            throw new \Exception("Reseed is required");

        // Optional encoding
        if( !is_string($enc) )
        {
            $addEnc = $enc;
            $add = $enc;
            $enc = null;
        }

        // Optional additional data
        if( $add != null ) {
            $add = EllipticPHPUtils::toBin($add, $addEnc);
            $this->_update($add);
        }

        $temp = "";
        while( strlen($temp) < $len )
        {
            $hmac = $this->_hmac();
            hash_update($hmac, $this->V ?: '');
            $this->V = hash_final($hmac, true);
            $temp .= $this->V;
        }

        $res = substr($temp, 0, $len);
        $this->_update($add);
        $this->reseed++;

        return EllipticPHPUtils::encode(EllipticPHPUtils::toArray($res), $enc);
    }
}

class EC
{
    public $curve;
    public $n;
    public $nh;
    public $g;
    public $hash;

    function __construct($options)
    {
        if( is_string($options) )
        {
            $options = Curves::getCurve($options);
        }

        if( $options instanceof PresetCurve )
            $options = array("curve" => $options);

        $this->curve = $options["curve"]->curve;
        $this->n = $this->curve->n;
        $this->nh = $this->n->ushrn(1);

        //Point on curve
        $this->g = $options["curve"]->g;
        $this->g->precompute($options["curve"]->n->bitLength() + 1);

        //Hash for function for DRBG
        if( isset($options["hash"]) )
            $this->hash = $options["hash"];
        else
            $this->hash = $options["curve"]->hash;
    }

    public function keyPair($options) {
        return new KeyPair($this, $options);
    }

    public function keyFromPrivate($priv, $enc = false) {
        return KeyPair::fromPrivate($this, $priv, $enc);
    }

    public function keyFromPublic($pub, $enc = false) {
        return KeyPair::fromPublic($this, $pub, $enc);
    }

    public function genKeyPair($options = null)
    {
        // Instantiate HmacDRBG
        $drbg = new HmacDRBG(array(
            "hash" => $this->hash,
            "pers" => isset($options["pers"]) ? $options["pers"] : "",
            "entropy" => isset($options["entropy"]) ? $options["entropy"] : EllipticPHPUtils::randBytes($this->hash["hmacStrength"]),
            "nonce" => $this->n->toArray()
        ));

        $bytes = $this->n->byteLength();
        $ns2 = $this->n->sub(new BN(2));
        while(true)
        {
            $priv = new BN($drbg->generate($bytes));
            if( $priv->cmp($ns2) > 0 )
                continue;

            $priv->iaddn(1);
            return $this->keyFromPrivate($priv);
        }
    }

    private function _truncateToN($msg, $truncOnly = false)
    {
        $delta = intval(($msg->byteLength() * 8) - $this->n->bitLength());
        if( $delta > 0 ) {
            $msg = $msg->ushrn($delta);
        }
        if( $truncOnly || $msg->cmp($this->n) < 0 )
            return $msg;

        return $msg->sub($this->n);
    }

    public function sign($msg, $key, $enc = null, $options = null)
    {
        if( !is_string($enc) )
        {
            $options = $enc;
            $enc = null;
        }

        $key = $this->keyFromPrivate($key, $enc);
        $msg = $this->_truncateToN(new BN($msg, 16));

        // Zero-extend key to provide enough entropy
        $bytes = $this->n->byteLength();
        $bkey = $key->getPrivate()->toArray("be", $bytes);

        // Zero-extend nonce to have the same byte size as N
        $nonce = $msg->toArray("be", $bytes);

        $kFunc = null;
        if( isset($options["k"]) )
            $kFunc = $options["k"];
        else
        {
            // Instatiate HmacDRBG
            $drbg = new HmacDRBG(array(
                "hash" => $this->hash,
                "entropy" => $bkey,
                "nonce" => $nonce,
                "pers" => isset($options["pers"]) ? $options["pers"] : "",
                "persEnc" => isset($options["persEnc"]) ? $options["persEnc"] : false
            ));

            $kFunc = function($iter) use ($drbg, $bytes) {
                return new BN($drbg->generate($bytes));
            };
        }

        // Number of bytes to generate
        $ns1 = $this->n->sub(new BN(1));

        $canonical = isset($options["canonical"]) ? $options["canonical"] : false;
        for($iter = 0; true; $iter++)
        {
            $k = $kFunc($iter);
            $k = $this->_truncateToN($k, true);

            if( $k->cmpn(1) <= 0 || $k->cmp($ns1) >= 0 )
                continue;

            // Fix the bit-length of the random nonce,
            // so that it doesn't leak via timing.
            // This does not change that ks = k mod k
            $ks = $k->add($this->n);
            $kt = $ks->add($this->n);
            if ($ks->bitLength() === $this->n->bitLength()) {
                $kp = $this->g->mul($kt);
            } else {
                $kp = $this->g->mul($ks);
            }

            if( $kp->isInfinity() )
                continue;

            $kpX = $kp->getX();
            $r = $kpX->umod($this->n);
            if( $r->isZero() )
                continue;

            $s = $k->invm($this->n)->mul($r->mul($key->getPrivate())->iadd($msg));
            $s = $s->umod($this->n);
            if( $s->isZero() )
                continue;

            $recoveryParam = ($kp->getY()->isOdd() ? 1 : 0) | ($kpX->cmp($r) !== 0 ? 2 : 0);

            // Use complement of `s`, if it is > `n / 2`
            if( $canonical && $s->cmp($this->nh) > 0 )
            {
                $s = $this->n->sub($s);
                $recoveryParam ^= 1;
            }

            return new Signature(array(
                "r" => $r,
                "s" => $s,
                "recoveryParam" => $recoveryParam
            ));
        }
    }

    public function verify($msg, $signature, $key, $enc = false)
    {
        $msg = $this->_truncateToN(new BN($msg, 16));
        $key = $this->keyFromPublic($key, $enc);
        $signature = new Signature($signature, "hex");

        // Perform primitive values validation
        $r = $signature->r;
        $s = $signature->s;

        if( $r->cmpn(1) < 0 || $r->cmp($this->n) >= 0 )
            return false;
        if( $s->cmpn(1) < 0 || $s->cmp($this->n) >= 0 )
            return false;

        // Validate signature
        $sinv = $s->invm($this->n);
        $u1 = $sinv->mul($msg)->umod($this->n);
        $u2 = $sinv->mul($r)->umod($this->n);

        if( !$this->curve->_maxwellTrick )
        {
            $p = $this->g->mulAdd($u1, $key->getPublic(), $u2);
            if( $p->isInfinity() )
                return false;

            return $p->getX()->umod($this->n)->cmp($r) === 0;
        }

        // NOTE: Greg Maxwell's trick, inspired by:
        // https://git.io/vad3K

        $p = $this->g->jmulAdd($u1, $key->getPublic(), $u2);
        if( $p->isInfinity() )
            return false;

        // Compare `p.x` of Jacobian point with `r`,
        // this will do `p.x == r * p.z^2` instead of multiplying `p.x` by the
        // inverse of `p.z^2`
        return $p->eqXToP($r);
    }

    public function recoverPubKey($msg, $signature, $j, $enc = false)
    {
        assert((3 & $j) === $j); //, "The recovery param is more than two bits");
        $signature = new Signature($signature, $enc);

        $e = new BN($msg, 16);
        $r = $signature->r;
        $s = $signature->s;

        // A set LSB signifies that the y-coordinate is odd
        $isYOdd = ($j & 1) == 1;
        $isSecondKey = $j >> 1;

        if ($r->cmp($this->curve->p->umod($this->curve->n)) >= 0 && $isSecondKey)
            throw new \Exception("Unable to find second key candinate");

        // 1.1. Let x = r + jn.
        if( $isSecondKey )
            $r = $this->curve->pointFromX($r->add($this->curve->n), $isYOdd);
        else
            $r = $this->curve->pointFromX($r, $isYOdd);

        $eNeg = $this->n->sub($e);

        // 1.6.1 Compute Q = r^-1 (sR -  eG)
        //               Q = r^-1 (sR + -eG)
        $rInv = $signature->r->invm($this->n);
        return $this->g->mulAdd($eNeg, $r, $s)->mul($rInv);
    }

    public function getKeyRecoveryParam($e, $signature, $Q, $enc = false)
    {
        $signature = new Signature($signature, $enc);
        if( $signature->recoveryParam != null )
            return $signature->recoveryParam;

        for($i = 0; $i < 4; $i++)
        {
            $Qprime = null;
            try {
                $Qprime = $this->recoverPubKey($e, $signature, $i);
            }
            catch(\Exception $e) {
                continue;
            }

            if( $Qprime->eq($Q))
                return $i;
        }
        throw new \Exception("Unable to find valid recovery factor");
    }
}


if (!defined("S_MATH_BIGINTEGER_MODE")) {
    if (extension_loaded("gmp")) {
        define("S_MATH_BIGINTEGER_MODE", "gmp");
    }
    else if (extension_loaded("bcmath")) {
        define("S_MATH_BIGINTEGER_MODE", "bcmath");
    }
    else {
        if (!defined("S_MATH_BIGINTEGER_QUIET")) {
            throw new \Exception("Cannot use BigInteger. Neither gmp nor bcmath module is loaded");
        }
    }
}

if (S_MATH_BIGINTEGER_MODE == "gmp") {

    if (!extension_loaded("gmp")) {
        throw new \Exception("Extension gmp not loaded");
    }

    class BigInteger {

        public $value;

        public function __construct($value = 0, $base = 10) {
            $this->value = $base === true ? $value : BigInteger::getGmp($value, $base);
        }

        public static function createSafe($value = 0, $base = 10) {
            try {
                return new BigInteger($value, $base);
            }
            catch (\Exception $e) {
                return false;
            }
        }

        public static function isGmp($var) {
            if (is_resource($var)) {
                return get_resource_type($var) == "GMP integer";
            }
            if (class_exists("GMP") && $var instanceof \GMP) {
                return true;
            }
            return false;
        }

        public static function getGmp($value = 0, $base = 10) {
            if ($value instanceof BigInteger) {
                return $value->value;
            }
            if (BigInteger::isGmp($value)) {
                return $value;
            }
            $type = gettype($value);
            $className = '';
            if ($type == "integer") {
                $gmp = gmp_init($value);
                if ($gmp === false) {
                    throw new \Exception("Cannot initialize");
                }
                $className = 'integer';
                return $gmp;
            }
            if ($type == "string") {
                if ($base != 2 && $base != 10 && $base != 16 && $base != 256) {
                    throw new \Exception("Unsupported BigInteger base");
                }
                if ($base == 256) {
                    $value = bin2hex($value);
                    $base = 16;
                }
                $level = error_reporting();
                error_reporting(0);
                $gmp = gmp_init($value, $base);
                error_reporting($level);
                if ($gmp === false) {
                    throw new \Exception("Cannot initialize");
                }
                $className = 'string';
                return $gmp;
            }
            throw new \Exception("Unsupported value, only string and integer are allowed, receive " . $type . ($type == "object" ? ", class: " . $className : ""));
        }

        public function toDec() {
            return gmp_strval($this->value, 10);
        }

        public function toHex() {
            $hex = gmp_strval($this->value, 16);
            return strlen($hex) % 2 == 1 ? "0". $hex : $hex;
        }

        public function toBytes() {
            return hex2bin($this->toHex());
        }

        public function toBase($base) {
            if ($base < 2 || $base > 62) {
                throw new \Exception("Invalid base");
            }
            return gmp_strval($this->value, $base);
        }

        public function toBits() {
            return gmp_strval($this->value, 2);
        }

        public function toString($base = 10) {
            if ($base == 2) {
                return $this->toBits();
            }
            if ($base == 10) {
                return $this->toDec();
            }
            if ($base == 16) {
                return $this->toHex();
            }
            if ($base == 256) {
                return $this->toBytes();
            }
            return $this->toBase($base);
        }

        public function __toString() {
            return $this->toString();
        }

        public function toNumber() {
            return gmp_intval($this->value);
        }

        public function add($x) {
            return new BigInteger(gmp_add($this->value, BigInteger::getGmp($x)), true);
        }

        public function sub($x) {
            return new BigInteger(gmp_sub($this->value, BigInteger::getGmp($x)), true);
        }

        public function mul($x) {
            return new BigInteger(gmp_mul($this->value, BigInteger::getGmp($x)), true);
        }

        public function div($x) {
            return new BigInteger(gmp_div_q($this->value, BigInteger::getGmp($x)), true);
        }

        public function divR($x) {
            return new BigInteger(gmp_div_r($this->value, BigInteger::getGmp($x)), true);
        }

        public function divQR($x) {
            $res = gmp_div_qr($this->value, BigInteger::getGmp($x));
            return array(new BigInteger($res[0], true), new BigInteger($res[1], true));
        }

        public function mod($x) {
            return new BigInteger(gmp_mod($this->value, BigInteger::getGmp($x)), true);
        }

        public function gcd($x) {
            return new BigInteger(gmp_gcd($this->value, BigInteger::getGmp($x)), true);
        }

        public function modInverse($x) {
            $res = gmp_invert($this->value, BigInteger::getGmp($x));
            return $res === false ? false : new BigInteger($res, true);
        }

        public function pow($x) {
            return new BigInteger(gmp_pow($this->value, (new BigInteger($x))->toNumber()), true);
        }

        public function powMod($x, $n) {
            return new BigInteger(gmp_powm($this->value, BigInteger::getGmp($x), BigInteger::getGmp($n)), true);
        }

        public function abs() {
            return new BigInteger(gmp_abs($this->value), true);
        }

        public function neg() {
            return new BigInteger(gmp_neg($this->value), true);
        }

        public function binaryAnd($x) {
            return new BigInteger(gmp_and($this->value, BigInteger::getGmp($x)), true);
        }

        public function binaryOr($x) {
            return new BigInteger(gmp_or($this->value, BigInteger::getGmp($x)), true);
        }

        public function binaryXor($x) {
            return new BigInteger(gmp_xor($this->value, BigInteger::getGmp($x)), true);
        }

        public function setbit($index, $bitOn = true) {
            $cpy = gmp_init(gmp_strval($this->value, 16), 16);
            gmp_setbit($cpy, $index, $bitOn);
            return new BigInteger($cpy, true);
        }

        public function testbit($index) {
            return gmp_testbit($this->value, $index);
        }

        public function scan0($start) {
            return gmp_scan0($this->value, $start);
        }

        public function scan1($start) {
            return gmp_scan1($this->value, $start);
        }

        public function cmp($x) {
            return gmp_cmp($this->value, BigInteger::getGmp($x));
        }

        public function equals($x) {
            return $this->cmp($x) === 0;
        }

        public function sign() {
            return gmp_sign($this->value);
        }
    }

}
else if (S_MATH_BIGINTEGER_MODE == "bcmath") {

    if (!extension_loaded("bcmath")) {
        throw new \Exception("Extension bcmath not loaded");
    }

    class BigInteger{

        public static $chars = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuv";
        public $value;

        public function __construct($value = 0, $base = 10) {
            $this->value = $base === true ? $value : BigInteger::getBC($value, $base);
        }

        public static function createSafe($value = 0, $base = 10) {
            try {
                return new BigInteger($value, $base);
            }
            catch (\Exception $e) {
                return false;
            }
        }

        public static function checkBinary($str) {
            $len = strlen($str);
            for ($i = 0; $i < $len; $i++) {
                $c = ord($str[$i]);
                if (($i != 0 || $c != 45) && ($c < 48 || $c > 49)) {
                    return false;
                }
            }
            return true;
        }

        public static function checkDecimal($str) {
            $len = strlen($str);
            for ($i = 0; $i < $len; $i++) {
                $c = ord($str[$i]);
                if (($i != 0 || $c != 45) && ($c < 48 || $c > 57)) {
                    return false;
                }
            }
            return true;
        }

        public static function checkHex($str) {
            $len = strlen($str);
            for ($i = 0; $i < $len; $i++) {
                $c = ord($str[$i]);
                if (($i != 0 || $c != 45) && ($c < 48 || $c > 57) && ($c < 65 || $c > 70) && ($c < 97 || $c > 102)) {
                    return false;
                }
            }
            return true;
        }

        public static function getBC($value = 0, $base = 10) {
            if ($value instanceof BigInteger) {
                return $value->value;
            }
            $type = gettype($value);
            $className = '';
            if ($type == "integer") {
                $className = 'integer';
                return strval($value);
            }
            if ($type == "string") {
                $className = 'string';
                if ($base == 2) {
                    $value = str_replace(" ", "", $value);
                    if (!BigInteger::checkBinary($value)) {
                        throw new \Exception("Invalid characters");
                    }
                    $minus = $value[0] == "-";
                    if ($minus) {
                        $value = substr($value, 1);
                    }
                    $len = strlen($value);
                    $m = 1;
                    $res = "0";
                    for ($i = $len - 1; $i >= 0; $i -= 8) {
                        $h = $i - 7 < 0 ? substr($value, 0, $i + 1) : substr($value, $i - 7, 8);
                        $res = bcadd($res, bcmul(bindec($h), $m, 0), 0);
                        $m = bcmul($m, "256", 0);
                    }
                    return ($minus ? "-" : "") . $res;
                }
                if ($base == 10) {
                    $value = str_replace(" ", "", $value);
                    if (!BigInteger::checkDecimal($value)) {
                        throw new \Exception("Invalid characters");
                    }
                    return $value;
                }
                if ($base == 16) {
                    $value = str_replace(" ", "", $value);
                    if (!BigInteger::checkHex($value)) {
                        throw new \Exception("Invalid characters");
                    }
                    $minus = $value[0] == "-";
                    if ($minus) {
                        $value = substr($value, 1);
                    }
                    $len = strlen($value);
                    $m = 1;
                    $res = "0";
                    for ($i = $len - 1; $i >= 0; $i -= 2) {
                        $h = $i == 0 ? "0" . substr($value, 0, 1) : substr($value, $i - 1, 2);
                        $res = bcadd($res, bcmul(hexdec($h), $m, 0), 0);
                        $m = bcmul($m, "256", 0);
                    }
                    return ($minus ? "-" : "") . $res;
                }
                if ($base == 256) {
                    $len = strlen($value);
                    $m = 1;
                    $res = "0";
                    for ($i = $len - 1; $i >= 0; $i -= 6) {
                        $h = $i - 5 < 0 ? substr($value, 0, $i + 1) : substr($value, $i - 5, 6);
                        $res = bcadd($res, bcmul(base_convert(bin2hex($h), 16, 10), $m, 0), 0);
                        $m = bcmul($m, "281474976710656", 0);
                    }
                    return $res;
                }
                throw new \Exception("Unsupported BigInteger base");
            }
            throw new \Exception("Unsupported value, only string and integer are allowed, receive " . $type . ($type == "object" ? ", class: " . $className : ""));
        }

        public function toDec() {
            return $this->value;
        }

        public function toHex() {
            return bin2hex($this->toBytes());
        }

        public function toBytes() {
            $value = "";
            $current = $this->value;
            if ($current[0] == "-") {
                $current = substr($current, 1);
            }
            while (bccomp($current, "0", 0) > 0) {
                $temp = bcmod($current, "281474976710656");
                $value = hex2bin(str_pad(base_convert($temp, 10, 16), 12, "0", STR_PAD_LEFT)) . $value;
                $current = bcdiv($current, "281474976710656", 0);
            }
            return ltrim($value, chr(0));
        }

        public function toBase($base) {
            if ($base < 2 || $base > 62) {
                throw new \Exception("Invalid base");
            }
            $value = '';
            $current = $this->value;
            $base = BigInteger::getBC($base);

            if ($current[0] == '-') {
                $current = substr($current, 1);
            }

            while (bccomp($current, '0', 0) > 0) {
                $v = bcmod($current, $base);
                $value = BigInteger::$chars[$v] . $value;
                $current = bcdiv($current, $base, 0);
            }
            return $value;
        }

        public function toBits() {
            $bytes = $this->toBytes();
            $res = "";
            $len = strlen($bytes);
            for ($i = 0; $i < $len; $i++) {
                $b = decbin(ord($bytes[$i]));
                $res .= strlen($b) != 8 ? str_pad($b, 8, "0", STR_PAD_LEFT) : $b;
            }
            $res = ltrim($res, "0");
            return strlen($res) == 0 ? "0" : $res;
        }

        public function toString($base = 10) {
            if ($base == 2) {
                return $this->toBits();
            }
            if ($base == 10) {
                return $this->toDec();
            }
            if ($base == 16) {
                return $this->toHex();
            }
            if ($base == 256) {
                return $this->toBytes();
            }
            return $this->toBase($base);
        }

        public function __toString() {
            return $this->toString();
        }

        public function toNumber() {
            return intval($this->value);
        }

        public function add($x) {
            return new BigInteger(bcadd($this->value, BigInteger::getBC($x), 0), true);
        }

        public function sub($x) {
            return new BigInteger(bcsub($this->value, BigInteger::getBC($x), 0), true);
        }

        public function mul($x) {
            return new BigInteger(bcmul($this->value, BigInteger::getBC($x), 0), true);
        }

        public function div($x) {
            return new BigInteger(bcdiv($this->value, BigInteger::getBC($x), 0), true);
        }

        public function divR($x) {
            return new BigInteger(bcmod($this->value, BigInteger::getBC($x)), true);
        }

        public function divQR($x) {
            return array(
                $this->div($x),
                $this->divR($x)
            );
        }

        public function mod($x) {
            $xv = BigInteger::getBC($x);
            $mod = bcmod($this->value, $xv);
            if ($mod[0] == "-") {
                $mod = bcadd($mod, $xv[0] == "-" ? substr($xv, 1) : $xv, 0);
            }
            return new BigInteger($mod, true);
        }

        public function extendedGcd($n) {
            $u = $this->value;
            $v = (new BigInteger($n))->abs()->value;

            $a = "1";
            $b = "0";
            $c = "0";
            $d = "1";

            while (bccomp($v, "0", 0) != 0) {
                $q = bcdiv($u, $v, 0);

                $temp = $u;
                $u = $v;
                $v = bcsub($temp, bcmul($v, $q, 0), 0);

                $temp = $a;
                $a = $c;
                $c = bcsub($temp, bcmul($a, $q, 0), 0);

                $temp = $b;
                $b = $d;
                $d = bcsub($temp, bcmul($b, $q, 0), 0);
            }

            return array(
                "gcd" => new BigInteger($u, true),
                "x" => new BigInteger($a, true),
                "y" => new BigInteger($b, true)
            );
        }

        public function gcd($x) {
            return $this->extendedGcd($x)["gcd"];
        }

        public function modInverse($n) {
            $n = (new BigInteger($n))->abs();

            if ($this->sign() < 0) {
                $temp = $this->abs();
                $temp = $temp->modInverse($n);
                return $n->sub($temp);
            }

            extract($this->extendedGcd($n));

            if (isset($gcd) && !$gcd->equals(1)) {
                return false;
            }

            $x = isset($x) ? ($x->sign() < 0 ? $x->add($n) : $x) : null;

            return $this->sign() < 0 ? $n->sub($x) : $x;
        }

        public function pow($x) {
            return new BigInteger(bcpow($this->value, BigInteger::getBC($x), 0), true);
        }

        public function powMod($x, $n) {
            return new BigInteger(bcpowmod($this->value, BigInteger::getBC($x), BigInteger::getBC($n), 0), true);
        }

        public function abs() {
            return new BigInteger($this->value[0] == "-" ? substr($this->value, 1) : $this->value, true);
        }

        public function neg() {
            return new BigInteger($this->value[0] == "-" ? substr($this->value, 1) : "-" . $this->value, true);
        }

        public function binaryAnd($x) {
            $left = $this->toBytes();
            $right = (new BigInteger($x))->toBytes();

            $length = max(strlen($left), strlen($right));

            $left = str_pad($left, $length, chr(0), STR_PAD_LEFT);
            $right = str_pad($right, $length, chr(0), STR_PAD_LEFT);

            return new BigInteger($left & $right, 256);
        }

        public function binaryOr($x) {
            $left = $this->toBytes();
            $right = (new BigInteger($x))->toBytes();

            $length = max(strlen($left), strlen($right));

            $left = str_pad($left, $length, chr(0), STR_PAD_LEFT);
            $right = str_pad($right, $length, chr(0), STR_PAD_LEFT);

            return new BigInteger($left | $right, 256);
        }

        public function binaryXor($x) {
            $left = $this->toBytes();
            $right = (new BigInteger($x))->toBytes();

            $length = max(strlen($left), strlen($right));

            $left = str_pad($left, $length, chr(0), STR_PAD_LEFT);
            $right = str_pad($right, $length, chr(0), STR_PAD_LEFT);

            return new BigInteger($left ^ $right, 256);
        }

        public function setbit($index, $bitOn = true) {
            $bits = $this->toBits();
            $bits[strlen($bits) - $index - 1] = $bitOn ? "1" : "0";
            return new BigInteger($bits, 2);
        }

        public function testbit($index) {
            $bytes = $this->toBytes();
            $bytesIndex = intval($index / 8);
            $len = strlen($bytes);
            $b = $bytesIndex >= $len ? 0 : ord($bytes[$len - $bytesIndex - 1]);
            $v = 1 << ($index % 8);
            return ($b & $v) === $v;
        }

        public function scan0($start) {
            $bits = $this->toBits();
            $len = strlen($bits);
            if ($start < 0 || $start >= $len) {
                return -1;
            }
            $pos = strrpos($bits, "0", -1 - $start);
            return $pos === false ? -1 : $len - $pos - 1;
        }

        public function scan1($start) {
            $bits = $this->toBits();
            $len = strlen($bits);
            if ($start < 0 || $start >= $len) {
                return -1;
            }
            $pos = strrpos($bits, "1", -1 - $start);
            return $pos === false ? -1 : $len - $pos - 1;
        }

        public function cmp($x) {
            return bccomp($this->value, BigInteger::getBC($x));
        }

        public function equals($x) {
            return $this->value === BigInteger::getBC($x);
        }

        public function sign() {
            return $this->value[0] === "-" ? -1 : ($this->value === "0" ? 0 : 1);
        }
    }

}
else {
    if (!defined("S_MATH_BIGINTEGER_QUIET")) {
        throw new \Exception("Unsupported S_MATH_BIGINTEGER_MODE " . S_MATH_BIGINTEGER_MODE);
    }
}


class Red
{
    public $m;

    function __construct($m) {
        if( is_string($m) )
            $this->m = Red::primeByName($m);
        else
            $this->m = $m;

        if( !$this->m->gtn(1) )
            throw new Exception("Modulus must be greater than 1");
    }


    public static function primeByName($name)
    {
        switch($name) {
            case "k256":
                return new BN("ffffffff ffffffff ffffffff ffffffff ffffffff ffffffff fffffffe fffffc2f", 16);
            case "p224":
                return new BN("ffffffff ffffffff ffffffff ffffffff 00000000 00000000 00000001", 16);
            case "p192":
                return new BN("ffffffff ffffffff ffffffff fffffffe ffffffff ffffffff", 16);
            case "p25519":
                return new BN("7fffffffffffffff ffffffffffffffff ffffffffffffffff ffffffffffffffed", 16);
            default:
                throw new Exception("Unknown prime name " . $name);
        }
    }

    public function verify1(BN $num)
    {
        if (assert_options(ASSERT_ACTIVE)) assert(!$num->negative()); //,"red works only with positives");
        assert($num->red); //, "red works only with red numbers");
    }

    public function verify2(BN $a, BN $b)
    {
        if (assert_options(ASSERT_ACTIVE)) assert(!$a->negative() && !$b->negative()); //, "red works only with positives");
        assert($a->red && ($a->red == $b->red)); //, "red works only with red numbers");
    }

    public function imod(BN $a) {
        return $a->umod($this->m)->_forceRed($this);
    }

    public function neg(BN $a)
    {
        if( $a->isZero() )
            return $a->_clone();
        return $this->m->sub($a)->_forceRed($this);
    }

    public function add(BN $a, BN $b)
    {
        $this->verify2($a, $b);

        $res = $a->add($b);
        if( $res->cmp($this->m) >= 0 )
            $res->isub($this->m);
        return $res->_forceRed($this);
    }

    public function iadd(BN &$a, BN $b)
    {
        $this->verify2($a, $b);

        $a->iadd($b);
        if( $a->cmp($this->m) >= 0 )
            $a->isub($this->m);

        return $a;
    }

    public function sub(BN $a, BN $b)
    {
        $this->verify2($a, $b);

        $res = $a->sub($b);
        if( $res->negative() )
            $res->iadd($this->m);

        return $res->_forceRed($this);
    }

    public function isub(BN &$a, $b)
    {
        $this->verify2($a, $b);

        $a->isub($b);
        if( $a->negative() )
            $a->iadd($this->m);

        return $a;
    }

    public function shl(BN $a, $num) {
        $this->verify1($a);
        return $this->imod($a->ushln($num));
    }

    public function imul(BN &$a, BN $b) {
        $this->verify2($a, $b);
        $res = $a->imul($b);
        return $this->imod($res);
    }

    public function mul(BN $a, BN $b) {
        $this->verify2($a, $b);
        $res = $a->mul($b);
        return $this->imod($res);
    }

    public function sqr(BN $a) {
        $res = $a->_clone();
        return $this->imul($res, $a);
    }

    public function isqr(BN &$a) {
        return $this->imul($a, $a);
    }

    public function sqrt(BN $a) {
        if ($a->isZero())
            return $a->_clone();

        $mod3 = $this->m->andln(3);
        assert($mod3 % 2 == 1);

        // Fast case
        if ($mod3 == 3) {
            $pow = $this->m->add(new BN(1))->iushrn(2);
            return $this->pow($a, $pow);
        }

        // Tonelli-Shanks algorithm (Totally unoptimized and slow)
        //
        // Find Q and S, that Q * 2 ^ S = (P - 1)
        $q = $this->m->subn(1);
        $s = 0;
        while (!$q->isZero() && $q->andln(1) == 0) {
            $s++;
            $q->iushrn(1);
        }
        if (assert_options(ASSERT_ACTIVE)) assert(!$q->isZero());

        $one = (new BN(1))->toRed($this);
        $nOne = $one->redNeg();

        // Find quadratic non-residue
        // NOTE: Max is such because of generalized Riemann hypothesis.
        $lpow = $this->m->subn(1)->iushrn(1);
        $z = $this->m->bitLength();
        $z = (new BN(2 * $z * $z))->toRed($this);

        while ($this->pow($z, $lpow)->cmp($nOne) != 0) {
            $z->redIAdd($nOne);
        }

        $c = $this->pow($z, $q);
        $r = $this->pow($a, $q->addn(1)->iushrn(1));
        $t = $this->pow($a, $q);
        $m = $s;
        while ($t->cmp($one) != 0) {
            $tmp = $t;
            for ($i = 0; $tmp->cmp($one) != 0; $i++) {
                $tmp = $tmp->redSqr();
            }
            if ($i >= $m) {
                throw new \Exception("Assertion failed");
            }
            if ($m - $i - 1 > 54) {
                $b = $this->pow($c, (new BN(1))->iushln($m - $i - 1));
            } else {
                $b = clone($c);
                $b->bi = $c->bi->powMod(1 << ($m - $i - 1), $this->m->bi);
            }

            $r = $r->redMul($b);
            $c = $b->redSqr();
            $t = $t->redMul($c);
            $m = $i;
        }

        return $r;
    }

    public function invm(BN &$a) {
        $res = $a->invm($this->m);
        return $this->imod($res);
    }

    public function pow(BN $a, BN $num) {
        $r = clone($a);
        $r->bi = $a->bi->powMod($num->bi, $this->m->bi);
        return $r;
    }

    public function convertTo(BN $num) {
        $r = $num->umod($this->m);
        return $r === $num ? $r->_clone() : $r;
    }

    public function convertFrom(BN $num) {
        $res = $num->_clone();
        $res->red = null;
        return $res;
    }
}

class BN implements JsonSerializable
{
    public $bi;
    public $red;

    function __construct($number, $base = 10, $endian = null)
    {
        if( $number instanceof BN ) {
            $this->bi = $number->bi;
            $this->red = $number->red;
            return;
        }

        // Reduction context
        $this->red = null;

        if ( $number instanceof BigInteger ) {
            $this->bi = $number;
            return;
        }

        if( is_array($number) )
        {
            $number = call_user_func_array("pack", array_merge(array("C*"), $number));
            $number = bin2hex($number);
            $base = 16;
        }

        if( $base == "hex" )
            $base = 16;

        if ($endian == 'le') {
            if ($base != 16)
                throw new \Exception("Not implemented");
            $number = bin2hex(strrev(hex2bin($number)));
        }

        $this->bi = new BigInteger($number, $base);
    }

    public function negative() {
        return $this->bi->sign() < 0 ? 1 : 0;
    }

    public static function isBN($num) {
        return ($num instanceof BN);
    }

    public static function max($left, $right) {
        return ( $left->cmp($right) > 0 ) ? $left : $right;
    }

    public static function min($left, $right) {
        return ( $left->cmp($right) < 0 ) ? $left : $right;
    }

    public function copy($dest)
    {
        $dest->bi = $this->bi;
        $dest->red = $this->red;
    }

    public function _clone() {
        return clone($this);
    }

    public function toString($base = 10, $padding = 0)
    {
        if( $base == "hex" )
            $base = 16;
        $str = $this->bi->abs()->toString($base);
        if ($padding > 0) {
            $len = strlen($str);
            $mod = $len % $padding;
            if ($mod > 0)
                $len = $len + $padding - $mod;
            $str = str_pad($str, $len, "0", STR_PAD_LEFT);
        }
        if( $this->negative() )
            return "-" . $str;
        return $str;
    }

    public function toNumber() {
        return $this->bi->toNumber();
    }

    public function jsonSerialize() {
        return $this->toString(16);
    }

    public function toArray($endian = "be", $length = -1)
    {
        $hex = $this->toString(16);
        if( $hex[0] === "-" )
            $hex = substr($hex, 1);

        if( strlen($hex) % 2 )
            $hex = "0" . $hex;

        $bytes = array_map(
            function($v) { return hexdec($v); },
            str_split($hex, 2)
        );

        if( $length > 0 )
        {
            $count = count($bytes);
            if( $count > $length )
                throw new Exception("Byte array longer than desired length");

            for($i = $count; $i < $length; $i++)
                array_unshift($bytes, 0);
        }

        if( $endian === "le" )
            $bytes = array_reverse($bytes);

        return $bytes;
    }

    public function bitLength() {
        $bin = $this->toString(2);
        return strlen($bin) - ( $bin[0] === "-" ? 1 : 0 );
    }

    public function zeroBits() {
        return $this->bi->scan1(0);
    }

    public function byteLength() {
        return ceil($this->bitLength() / 8);
    }

    //TODO toTwos, fromTwos

    public function isNeg() {
        return $this->negative() !== 0;
    }

    // Return negative clone of `this`
    public function neg() {
        return $this->_clone()->ineg();
    }

    public function ineg() {
        $this->bi = $this->bi->neg();
        return $this;
    }

    // Or `num` with `this` in-place
    public function iuor(BN $num) {
        $this->bi = $this->bi->binaryOr($num->bi);
        return $this;
    }

    public function ior(BN $num) {
        if (assert_options(ASSERT_ACTIVE)) assert(!$this->negative() && !$num->negative());
        return $this->iuor($num);
    }

    // Or `num` with `this`
    public function _or(BN $num) {
        if( $this->ucmp($num) > 0 )
            return $this->_clone()->ior($num);
        return $num->_clone()->ior($this);
    }

    public function uor(BN $num) {
        if( $this->ucmp($num) > 0 )
            return $this->_clone()->iuor($num);
        return $num->_clone()->ior($this);
    }

    // And `num` with `this` in-place
    public function iuand(BN $num) {
        $this->bi = $this->bi->binaryAnd($num->bi);
        return $this;
    }

    public function iand(BN $num) {
        if (assert_options(ASSERT_ACTIVE)) assert(!$this->negative() && !$num->negative());
        return $this->iuand($num);
    }

    // And `num` with `this`
    public function _and(BN $num) {
        if( $this->ucmp($num) > 0 )
            return $this->_clone()->iand($num);
        return $num->_clone()->iand($this);
    }

    public function uand(BN $num) {
        if( $this->ucmp($num) > 0 )
            return $this->_clone()->iuand($num);
        return $num->_clone()->iuand($this);
    }

    // Xor `num` with `this` in-place
    public function iuxor(BN $num) {
        $this->bi = $this->bi->binaryXor($num->bi);
        return $this;
    }

    public function ixor(BN $num) {
        if (assert_options(ASSERT_ACTIVE)) assert(!$this->negative() && !$num->negative());
        return $this->iuxor($num);
    }

    // Xor `num` with `this`
    public function _xor(BN $num) {
        if( $this->ucmp($num) > 0 )
            return $this->_clone()->ixor($num);
        return $num->_clone()->ixor($this);
    }

    public function uxor(BN $num) {
        if( $this->ucmp($num) > 0 )
            return $this->_clone()->iuxor($num);
        return $num->_clone()->iuxor($this);
    }

    // Not ``this`` with ``width`` bitwidth
    public function inotn($width)
    {
        assert(is_integer($width) && $width >= 0);
        $neg = false;
        if( $this->isNeg() )
        {
            $this->negi();
            $neg = true;
        }

        for($i = 0; $i < $width; $i++)
            $this->bi = $this->bi->setbit($i, !$this->bi->testbit($i));

        return $neg ? $this->negi() : $this;
    }

    public function negi()
    {
        return '';
    }

    public function notn($width) {
        return $this->_clone()->inotn($width);
    }

    // Set `bit` of `this`
    public function setn($bit, $val) {
        assert(is_integer($bit) && $bit > 0);
        $this->bi = $this->bi->setbit($bit, !!$val);
        return $this;
    }

    // Add `num` to `this` in-place
    public function iadd(BN $num) {
        $this->bi = $this->bi->add($num->bi);
        return $this;
    }

    // Add `num` to `this`
    public function add(BN $num) {
        return $this->_clone()->iadd($num);
    }

    // Subtract `num` from `this` in-place
    public function isub(BN $num) {
        $this->bi = $this->bi->sub($num->bi);
        return $this;
    }

    // Subtract `num` from `this`
    public function sub(BN $num) {
        return $this->_clone()->isub($num);
    }

    // Multiply `this` by `num`
    public function mul(BN $num) {
        return $this->_clone()->imul($num);
    }

    // In-place Multiplication
    public function imul(BN $num) {
        $this->bi = $this->bi->mul($num->bi);
        return $this;
    }

    public function imuln($num)
    {
        assert(is_numeric($num));
        $int = intval($num);
        $res = $this->bi->mul($int);

        if( ($num - $int) > 0 )
        {
            $mul = 10;
            $frac = ($num - $int) * $mul;
            $int = intval($frac);
            while( ($frac - $int) > 0 )
            {
                $mul *= 10;
                $frac *= 10;
                $int = intval($frac);
            }

            $tmp = $this->bi->mul($int);
            $tmp = $tmp->div($mul);
            $res = $res->add($tmp);
        }

        $this->bi = $res;
        return $this;
    }

    public function muln($num) {
        return $this->_clone()->imuln($num);
    }

    // `this` * `this`
    public function sqr() {
        return $this->mul($this);
    }

    // `this` * `this` in-place
    public function isqr() {
        return $this->imul($this);
    }

    // Math.pow(`this`, `num`)
    public function pow(BN $num) {
        $res = clone($this);
        $res->bi = $res->bi->pow($num->bi);
        return $res;
    }

    // Shift-left in-place
    public function iushln($bits) {
        assert(is_integer($bits) && $bits >= 0);
        if ($bits < 54) {
            $this->bi = $this->bi->mul(1 << $bits);
        } else {
            $this->bi = $this->bi->mul((new BigInteger(2))->pow($bits));
        }
        return $this;
    }

    public function ishln($bits) {
        if (assert_options(ASSERT_ACTIVE)) assert(!$this->negative());
        return $this->iushln($bits);
    }

    // Shift-right in-place
    // NOTE: `hint` is a lowest bit before trailing zeroes
    // NOTE: if `extended` is present - it will be filled with destroyed bits
    public function iushrn($bits, $hint = 0, &$extended = null) {
        if( $hint != 0 )
            throw new Exception("Not implemented");

        assert(is_integer($bits) && $bits >= 0);

        if( $extended != null )
            $extended = $this->maskn($bits);

        if ($bits < 54) {
            $this->bi = $this->bi->div(1 << $bits);
        } else {
            $this->bi = $this->bi->div((new BigInteger(2))->pow($bits));
        }
        return $this;
    }

    public function ishrn($bits, $hint = null, $extended = null) {
        if (assert_options(ASSERT_ACTIVE)) assert(!$this->negative());
        return $this->iushrn($bits, $hint, $extended);
    }

    // Shift-left
    public function shln($bits) {
        return $this->_clone()->ishln($bits);
    }

    public function ushln($bits) {
        return $this->_clone()->iushln($bits);
    }

    // Shift-right
    public function shrn($bits) {
        return $this->_clone()->ishrn($bits);
    }

    public function ushrn($bits) {
        return $this->_clone()->iushrn($bits);
    }

    // Test if n bit is set
    public function testn($bit) {
        assert(is_integer($bit) && $bit >= 0);
        return $this->bi->testbit($bit);
    }

    // Return only lowers bits of number (in-place)
    public function imaskn($bits) {
        assert(is_integer($bits) && $bits >= 0);
        if (assert_options(ASSERT_ACTIVE)) assert(!$this->negative());
        $mask = "";
        for($i = 0; $i < $bits; $i++)
            $mask .= "1";
        return $this->iand(new BN($mask, 2));
    }

    // Return only lowers bits of number
    public function maskn($bits) {
        return $this->_clone()->imaskn($bits);
    }

    // Add plain number `num` to `this`
    public function iaddn($num) {
        assert(is_numeric($num));
        $this->bi = $this->bi->add(intval($num));
        return $this;
    }

    // Subtract plain number `num` from `this`
    public function isubn($num) {
        assert(is_numeric($num));
        $this->bi = $this->bi->sub(intval($num));
        return $this;
    }

    public function addn($num) {
        return $this->_clone()->iaddn($num);
    }

    public function subn($num) {
        return $this->_clone()->isubn($num);
    }

    public function iabs() {
        if ($this->bi->sign() < 0) {
            $this->bi = $this->bi->abs();
        }
        return $this;
    }

    public function abs() {
        $res = clone($this);
        if ($res->bi->sign() < 0)
            $res->bi = $res->bi->abs();
        return $res;
    }

    // Find `this` / `num`
    public function div(BN $num) {
        if (assert_options(ASSERT_ACTIVE)) assert(!$num->isZero());
        $res = clone($this);
        $res->bi = $res->bi->div($num->bi);
        return $res;
    }

    // Find `this` % `num`
    public function mod(BN $num) {
        if (assert_options(ASSERT_ACTIVE)) assert(!$num->isZero());
        $res = clone($this);
        $res->bi = $res->bi->divR($num->bi);
        return $res;
    }

    public function umod(BN $num) {
        if (assert_options(ASSERT_ACTIVE)) assert(!$num->isZero());
        $tmp = $num->bi->sign() < 0 ? $num->bi->abs() : $num->bi;
        $res = clone($this);
        $res->bi = $this->bi->mod($tmp);
        return $res;
    }

    // Find Round(`this` / `num`)
    public function divRound(BN $num)
    {
        if (assert_options(ASSERT_ACTIVE)) assert(!$num->isZero());

        $negative = $this->negative() !== $num->negative();

        $res = $this->_clone()->abs();
        $arr = $res->bi->divQR($num->bi->abs());
        $res->bi = $arr[0];
        $tmp = $num->bi->sub($arr[1]->mul(2));
        if( $tmp->cmp(0) <= 0 && (!$negative || $this->negative() === 0) )
            $res->iaddn(1);
        return $negative ? $res->negi() : $res;
    }

    public function modn($num) {
        assert(is_numeric($num) && $num != 0);
        return $this->bi->divR(intval($num))->toNumber();
    }

    // In-place division by number
    public function idivn($num) {
        assert(is_numeric($num) && $num != 0);
        $this->bi = $this->bi->div(intval($num));
        return $this;
    }

    public function divn($num) {
        return $this->_clone()->idivn($num);
    }

    public function gcd(BN $num) {
        $res = clone($this);
        $res->bi = $this->bi->gcd($num->bi);
        return $res;
    }

    public function invm(BN $num) {
        $res = clone($this);
        $res->bi = $res->bi->modInverse($num->bi);
        return $res;
    }

    public function isEven() {
        return !$this->bi->testbit(0);
    }

    public function isOdd() {
        return $this->bi->testbit(0);
    }

    public function andln($num) {
        assert(is_numeric($num));
        return $this->bi->binaryAnd($num)->toNumber();
    }

    public function bincn($num) {
        $tmp = (new BN(1))->iushln($num);
        return $this->add($tmp);
    }

    public function isZero() {
        return $this->bi->sign() == 0;
    }

    public function cmpn($num) {
        assert(is_numeric($num));
        return $this->bi->cmp($num);
    }

    // Compare two numbers and return:
    // 1 - if `this` > `num`
    // 0 - if `this` == `num`
    // -1 - if `this` < `num`
    public function cmp(BN $num) {
        return $this->bi->cmp($num->bi);
    }

    public function ucmp(BN $num) {
        return $this->bi->abs()->cmp($num->bi->abs());
    }

    public function gtn($num) {
        return $this->cmpn($num) > 0;
    }

    public function gt(BN $num) {
        return $this->cmp($num) > 0;
    }

    public function gten($num) {
        return $this->cmpn($num) >= 0;
    }

    public function gte(BN $num) {
        return $this->cmp($num) >= 0;
    }

    public function ltn($num) {
        return $this->cmpn($num) < 0;
    }

    public function lt(BN $num) {
        return $this->cmp($num) < 0;
    }

    public function lten($num) {
        return $this->cmpn($num) <= 0;
    }

    public function lte(BN $num) {
        return $this->cmp($num) <= 0;
    }

    public function eqn($num) {
        return $this->cmpn($num) === 0;
    }

    public function eq(BN $num) {
        return $this->cmp($num) === 0;
    }

    public function toRed(Red &$ctx) {
        if( $this->red !== null )
            throw new Exception("Already a number in reduction context");
        if( $this->negative() !== 0 )
            throw new Exception("red works only with positives");
        return $ctx->convertTo($this)->_forceRed($ctx);
    }

    public function fromRed() {
        if( $this->red === null )
            throw new Exception("fromRed works only with numbers in reduction context");
        return $this->red->convertFrom($this);
    }

    public function _forceRed(Red &$ctx) {
        $this->red = $ctx;
        return $this;
    }

    public function forceRed(Red &$ctx) {
        if( $this->red !== null )
            throw new Exception("Already a number in reduction context");
        return $this->_forceRed($ctx);
    }

    public function redAdd(BN $num) {
        if( $this->red === null )
            throw new Exception("redAdd works only with red numbers");

        $res = clone($this);
        $res->bi = $res->bi->add($num->bi);
        if ($res->bi->cmp($this->red->m->bi) >= 0)
            $res->bi = $res->bi->sub($this->red->m->bi);
        return $res;
        // return $this->red->add($this, $num);
    }

    public function redIAdd(BN $num) {
        if( $this->red === null )
            throw new Exception("redIAdd works only with red numbers");
        $res = $this;
        $res->bi = $res->bi->add($num->bi);
        if ($res->bi->cmp($this->red->m->bi) >= 0)
            $res->bi = $res->bi->sub($this->red->m->bi);
        return $res;
        //return $this->red->iadd($this, $num);
    }

    public function redSub(BN $num) {
        if( $this->red === null )
            throw new Exception("redSub works only with red numbers");
        $res = clone($this);
        $res->bi = $this->bi->sub($num->bi);
        if ($res->bi->sign() < 0)
            $res->bi = $res->bi->add($this->red->m->bi);
        return $res;
        //return $this->red->sub($this, $num);
    }

    public function redISub(BN $num) {
        if( $this->red === null )
            throw new Exception("redISub works only with red numbers");
        $this->bi = $this->bi->sub($num->bi);
        if ($this->bi->sign() < 0)
            $this->bi = $this->bi->add($this->red->m->bi);
        return $this;

//        return $this->red->isub($this, $num);
    }

    public function redShl(BN $num) {
        if( $this->red === null )
            throw new Exception("redShl works only with red numbers");
        return $this->red->shl($this, $num);
    }

    public function redMul(BN $num) {
        if( $this->red === null )
            throw new Exception("redMul works only with red numbers");
        $res = clone($this);
        $res->bi = $this->bi->mul($num->bi)->mod($this->red->m->bi);
        return $res;
        /*
        return $this->red->mul($this, $num);
        */
    }

    public function redIMul(BN $num) {
        if( $this->red === null )
            throw new Exception("redIMul works only with red numbers");
        $this->bi = $this->bi->mul($num->bi)->mod($this->red->m->bi);
        return $this;
        //return $this->red->imul($this, $num);
    }

    public function redSqr() {
        if( $this->red === null )
            throw new Exception("redSqr works only with red numbers");
        $res = clone($this);
        $res->bi = $this->bi->mul($this->bi)->mod($this->red->m->bi);
        return $res;
        /*
        $this->red->verify1($this);
        return $this->red->sqr($this);
        */
    }

    public function redISqr() {
        if( $this->red === null )
            throw new Exception("redISqr works only with red numbers");
        $res = $this;
        $res->bi = $this->bi->mul($this->bi)->mod($this->red->m->bi);
        return $res;
        /*        $this->red->verify1($this);
                return $this->red->isqr($this);
                */
    }

    public function redSqrt() {
        if( $this->red === null )
            throw new Exception("redSqrt works only with red numbers");
        $this->red->verify1($this);
        return $this->red->sqrt($this);
    }

    public function redInvm() {
        if( $this->red === null )
            throw new Exception("redInvm works only with red numbers");
        $this->red->verify1($this);
        return $this->red->invm($this);
    }

    public function redNeg() {
        if( $this->red === null )
            throw new Exception("redNeg works only with red numbers");
        $this->red->verify1($this);
        return $this->red->neg($this);
    }

    public function redPow(BN $num) {
        if( $this->red === null )
            throw new Exception("redNeg works only with red numbers");
        $this->red->verify2($this, $num);
        return $this->red->pow($this, $num);
    }

    public static function red($num) {
        return new Red($num);
    }

    public static function mont($num) {
        return new Red($num);
    }

    public function inspect() {
        return ($this->red == null ? "<BN: " : "<BN-R: ") . $this->toString(16) . ">";
    }

    public function __debugInfo() {
        if ($this->red != null) {
            return ["BN-R" => $this->toString(16)];
        } else {
            return ["BN" => $this->toString(16)];
        }
    }
}

abstract class Point
{
    public $curve;
    public $type;
    public $precomputed;

    function __construct($curve, $type)
    {
        $this->curve = $curve;
        $this->type = $type;
        $this->precomputed = null;
    }

    abstract public function eq($other);
    abstract public function add($p);
    abstract public function getX();
    abstract public function getY();
    abstract public function dbl();

    public function validate() {
        return $this->curve->validate($this);
    }

    public function encodeCompressed($enc) {
        return $this->encode($enc, true);
    }

    public function encode($enc, $compact = false) {
        return EllipticPHPUtils::encode($this->_encode($compact), $enc);
    }

    protected function _encode($compact)
    {
        $len = $this->curve->p->byteLength();
        $x = $this->getX()->toArray("be", $len);

        if( $compact )
        {
            array_unshift($x, ($this->getY()->isEven() ? 0x02 : 0x03));
            return $x;
        }

        return array_merge(array(0x04), $x, $this->getY()->toArray("be", $len));
    }

    public function precompute($power = null)
    {
        if( isset($this->precomputed) )
            return $this;

        $this->precomputed = array(
            "naf" => $this->_getNAFPoints(8),
            "doubles" => $this->_getDoubles(4, $power),
            "beta" => $this->_getBeta()
        );

        return $this;
    }

    protected function _hasDoubles($k)
    {
        if( !isset($this->precomputed) || !isset($this->precomputed["doubles"]) )
            return false;

        return count($this->precomputed["doubles"]["points"]) >= ceil(($k->bitLength() + 1) / $this->precomputed["doubles"]["step"]);
    }

    public function _getDoubles($step = null, $power = null)
    {
        if( isset($this->precomputed) && isset($this->precomputed["doubles"]) )
            return $this->precomputed["doubles"];

        $doubles = array( $this );
        $acc = $this;
        for($i = 0; $i < $power; $i += $step)
        {
            for($j = 0; $j < $step; $j++)
                $acc = $acc->dbl();
            array_push($doubles, $acc);
        }

        return array(
            "step" => $step,
            "points" => $doubles
        );
    }

    public function _getNAFPoints($wnd)
    {
        if( isset($this->precomputed) && isset($this->precomputed["naf"]) )
            return $this->precomputed["naf"];

        $res = array( $this );
        $max = (1  << $wnd) - 1;
        $dbl = $max === 1 ? null : $this->dbl();
        for($i = 1; $i < $max; $i++)
            array_push($res, $res[$i - 1]->add($dbl));

        return array(
            "wnd" => $wnd,
            "points" => $res
        );
    }

    public function _getBeta() {
        return null;
    }

    public function dblp($k)
    {
        $r = $this;
        for($i = 0; $i < $k; $i++)
            $r = $r->dbl();
        return $r;
    }
}

class JPoint extends Point
{
    public $x;
    public $y;
    public $z;
    public $zOne;

    function __construct($curve, $x, $y, $z)
    {
        parent::__construct($curve, "jacobian");

        if( $x == null && $y == null && $z == null )
        {
            $this->x = $this->curve->one;
            $this->y = $this->curve->one;
            $this->z = new BN(0);
        }
        else
        {
            $this->x = new BN($x, 16);
            $this->y = new BN($y, 16);
            $this->z = new BN($z, 16);
        }

        if( !$this->x->red )
            $this->x = $this->x->toRed($this->curve->red);
        if( !$this->y->red )
            $this->y = $this->y->toRed($this->curve->red);
        if( !$this->z->red )
            $this->z = $this->z->toRed($this->curve->red);

        return $this->zOne = $this->z == $this->curve->one;
    }

    public function getX()
    {
        return $this->x->fromRed();
    }

    public function getY()
    {
        return $this->y->fromRed();
    }

    public function toP()
    {
        if( $this->isInfinity() )
            return $this->curve->point(null, null);

        $zinv = $this->z->redInvm();
        $zinv2 = $zinv->redSqr();
        $ax = $this->x->redMul($zinv2);
        $ay = $this->y->redMul($zinv2)->redMul($zinv);

        return $this->curve->point($ax, $ay);
    }

    public function neg() {
        return $this->curve->jpoint($this->x, $this->y->redNeg(), $this->z);
    }

    public function add($p)
    {
        // O + P = P
        if( $this->isInfinity() )
            return $p;

        // P + O = P
        if( $p->isInfinity() )
            return $this;

        // 12M + 4S + 7A
        $pz2 = $p->z->redSqr();
        $z2 = $this->z->redSqr();
        $u1 = $this->x->redMul($pz2);
        $u2 = $p->x->redMul($z2);
        $s1 = $this->y->redMul($pz2->redMul($p->z));
        $s2 = $p->y->redMul($z2->redMul($this->z));

        $h = $u1->redSub($u2);
        $r = $s1->redSub($s2);

        if( $h->isZero() )
        {
            if( ! $r->isZero() )
                return $this->curve->jpoint(null, null, null);
            else
                return $this->dbl();
        }

        $h2 = $h->redSqr();
        $h3 = $h2->redMul($h);
        $v = $u1->redMul($h2);

        $nx = $r->redSqr()->redIAdd($h3)->redISub($v)->redISub($v);
        $ny = $r->redMul($v->redISub($nx))->redISub($s1->redMul($h3));
        $nz = $this->z->redMul($p->z)->redMul($h);

        return $this->curve->jpoint($nx, $ny, $nz);
    }

    public function mixedAdd($p)
    {
        // O + P = P
        if( $this->isInfinity() )
            return $p->toJ();

        // P + O = P
        if( $p->isInfinity() )
            return $this;

        // 8M + 3S + 7A
        $z2 = $this->z->redSqr();
        $u1 = $this->x;
        $u2 = $p->x->redMul($z2);
        $s1 = $this->y;
        $s2 = $p->y->redMul($z2)->redMul($this->z);

        $h = $u1->redSub($u2);
        $r = $s1->redSub($s2);

        if( $h->isZero() )
        {
            if( ! $r->isZero() )
                return $this->curve->jpoint(null, null, null);
            else
                return $this->dbl();
        }

        $h2 = $h->redSqr();
        $h3 = $h2->redMul($h);
        $v = $u1->redMul($h2);

        $nx = $r->redSqr()->redIAdd($h3)->redISub($v)->redISub($v);
        $ny = $r->redMul($v->redISub($nx))->redISub($s1->redMul($h3));
        $nz = $this->z->redMul($h);

        return $this->curve->jpoint($nx, $ny, $nz);
    }

    public function dblp($pow = null)
    {
        if( $pow == 0 || $this->isInfinity() )
            return $this;

        if( $pow == null )
            return $this->dbl();

        if( $this->curve->zeroA || $this->curve->threeA )
        {
            $r = $this;
            for($i = 0; $i < $pow; $i++)
                $r = $r->dbl();
            return $r;
        }

        // 1M + 2S + 1A + N * (4S + 5M + 8A)
        // N = 1 => 6M + 6S + 9A
        $jx = $this->x;
        $jy = $this->y;
        $jz = $this->z;
        $jz4 = $jz->redSqr()->redSqr();

        //Reuse results
        $jyd = $jy->redAdd($jy);
        for($i = 0; $i < $pow; $i++)
        {
            $jx2 = $jx->redSqr();
            $jyd2 = $jyd->redSqr();
            $jyd4 = $jyd2->redSqr();
            $c = $jx2->redAdd($jx2)->redIAdd($jx2)->redIAdd($this->curve->a->redMul($jz4));

            $t1 = $jx->redMul($jyd2);
            $nx = $c->redSqr()->redISub($t1->redAdd($t1));
            $t2 = $t1->redISub($nx);
            $dny = $c->redMul($t2);
            $dny = $dny->redIAdd($dny)->redISub($jyd4);
            $nz = $jyd->redMul($jz);
            if( ($i + 1) < $pow)
                $jz4 = $jz4->redMul($jyd4);

            $jx = $nx;
            $jz = $nz;
            $jyd = $dny;
        }

        return $this->curve->jpoint($jx, $jyd->redMul($this->curve->tinv), $jz);
    }

    public function dbl()
    {
        if( $this->isInfinity() )
            return $this;

        if( $this->curve->zeroA )
            return $this->_zeroDbl();
        elseif( $this->curve->threeA )
            return $this->_threeDbl();
        return $this->_dbl();
    }

    private function _zOneDbl($withA)
    {
        $xx = $this->x->redSqr();
        $yy = $this->y->redSqr();
        $yyyy = $yy->redSqr();

        // S = 2 * ((X1 + YY)^2 - XX - YYYY)
        $s = $this->x->redAdd($yy)->redSqr()->redISub($xx)->redISub($yyyy);
        $s = $s->redIAdd($s);

        // M = 3 * XX + a; a = 0
        $m = null;
        if( $withA )
            $m = $xx->redAdd($xx)->redIAdd($xx)->redIAdd($this->curve->a);
        else
            $m = $xx->redAdd($xx)->redIAdd($xx);

        // T = M ^ 2 - 2*S
        $t = $m->redSqr()->redISub($s)->redISub($s);

        $yyyy8 = $yyyy->redIAdd($yyyy);
        $yyyy8 = $yyyy8->redIAdd($yyyy8);
        $yyyy8 = $yyyy8->redIAdd($yyyy8);

        $ny = $m->redMul($s->redISub($t))->redISub($yyyy8);
        $nz = $this->y->redAdd($this->y);
        return $this->curve->jpoint($t, $ny, $nz);
    }

    private function _zeroDbl()
    {
        // Z = 1
        if( $this->zOne )
        {
            // hyperelliptic.org/EFD/g1p/auto-shortw-jacobian-0.html
            //     #doubling-mdbl-2007-bl
            // 1M + 5S + 14A
            return $this->_zOneDbl(false);
        }

        // hyperelliptic.org/EFD/g1p/auto-shortw-jacobian-0.html
        //     #doubling-dbl-2009-l
        // 2M + 5S + 13A

        $a = $this->x->redSqr();
        $b = $this->y->redSqr();
        $c = $b->redSqr();
        // D = 2 * ((X1 + B)^2 - A - C)
        $d = $this->x->redAdd($b)->redSqr()->redISub($a)->redISub($c);
        $d = $d->redIAdd($d);
        $e = $a->redAdd($a)->redIAdd($a);
        $f = $e->redSqr();

        $c8 = $c->redIAdd($c);
        $c8 = $c8->redIAdd($c8);
        $c8 = $c8->redIAdd($c8);

        // X3 = F - 2 * D
        $nx = $f->redISub($d)->redISub($d);
        // Y3 = E * (D - X3) - 8 * C
        $ny = $e->redMul($d->redISub($nx))->redISub($c8);
        // Z3 = 2 * Y1 * Z1
        $nz = $this->y->redMul($this->z);
        $nz = $nz->redIAdd($nz);

        return $this->curve->jpoint($nx, $ny, $nz);
    }

    private function _threeDbl()
    {
        if( $this->zOne )
        {
            // hyperelliptic.org/EFD/g1p/auto-shortw-jacobian-3.html
            //     #doubling-mdbl-2007-bl
            // 1M + 5S + 15A

            // XX = X1^2
            $xx = $this->x->redSqr();
            // YY = Y1^2
            $yy = $this->y->redSqr();
            // YYYY = YY^2
            $yyyy = $yy->redSqr();
            // S = 2 * ((X1 + YY)^2 - XX - YYYY)
            $s = $this->x->redAdd($yy)->redSqr()->redISub($xx)->redISub($yyyy);
            $s = $s->redIAdd($s);
            // M = 3 * XX + a
            $m = $xx->redAdd($xx)->redIAdd($xx)->redIAdd($this->curve->a);
            // T = M^2 - 2 * S
            $t = $m->redSqr()->redISub($s)->redISub($s);
            // X3 = T
            $nx = $t;
            // Y3 = M * (S - T) - 8 * YYYY
            $yyyy8 = $yyyy->redIAdd($yyyy);
            $yyyy8 = $yyyy8->redIAdd($yyyy8);
            $yyyy8 = $yyyy8->redIAdd($yyyy8);
            $ny = $m->redMul($s->redISub($t))->redISub($yyyy8);
            // Z3 = 2 * Y1
            $nz = $this->y->redAdd($this->y);
        } else {
            // hyperelliptic.org/EFD/g1p/auto-shortw-jacobian-3.html#doubling-dbl-2001-b
            // 3M + 5S

            // delta = Z1^2
            $delta = $this->z->redSqr();
            // gamma = Y1^2
            $gamma = $this->y->redSqr();
            // beta = X1 * gamma
            $beta = $this->x->redMul($gamma);
            // alpha = 3 * (X1 - delta) * (X1 + delta)
            $alpha = $this->x->redSub($delta)->redMul($this->x->redAdd($delta));
            $alpha = $alpha->redAdd($alpha)->redIAdd($alpha);
            // X3 = alpha^2 - 8 * beta
            $beta4 = $beta->redIAdd($beta);
            $beta4 = $beta4->redIAdd($beta4);
            $beta8 = $beta4->redAdd($beta4);
            $nx = $alpha->redSqr()->redISub($beta8);
            // Z3 = (Y1 + Z1)^2 - gamma - delta
            $nz = $this->y->redAdd($this->z)->redSqr()->redISub($gamma)->redISub($delta);

            $ggamma8 = $gamma->redSqr();
            $ggamma8 = $ggamma8->redIAdd($ggamma8);
            $ggamma8 = $ggamma8->redIAdd($ggamma8);
            $ggamma8 = $ggamma8->redIAdd($ggamma8);
            // Y3 = alpha * (4 * beta - X3) - 8 * gamma^2
            $ny = $alpha->redMul($beta4->redISub($nx))->redISub($ggamma8);
        }
        return $this->curve->jpoint($nx, $ny, $nz);
    }

    private function _dbl()
    {
        // 4M + 6S + 10A
        $jx = $this->x;
        $jy = $this->y;
        $jz = $this->z;
        $jz4 = $jz->redSqr()->redSqr();

        $jx2 = $jx->redSqr();
        $jy2 = $jy->redSqr();

        $c = $jx2->redAdd($jx2)->redIAdd($jx2)->redIAdd($this->curve->a->redMul($jz4));
        $jxd4 = $jx->redAdd($jx);
        $jxd4 = $jxd4->redIAdd($jxd4);
        $t1 = $jxd4->redMul($jy2);
        $nx = $c->redSqr()->redISub($t1->redAdd($t1));
        $t2 = $t1->redISub($nx);

        $jyd8 = $jy2->redSqr();
        $jyd8 = $jyd8->redIAdd($jyd8);
        $jyd8 = $jyd8->redIAdd($jyd8);
        $jyd8 = $jyd8->redIAdd($jyd8);

        $ny = $c->redMul($t2)->redISub($jyd8);
        $nz = $jy->redAdd($jy)->redMul($jz);

        return $this->curve->jpoint($nx, $ny, $nz);
    }

    public function trpl()
    {
        if( !$this->curve->zeroA )
            return $this->dbl()->add($this);

        // hyperelliptic.org/EFD/g1p/auto-shortw-jacobian-0.html#tripling-tpl-2007-bl
        // 5M + 10S + ...

        $xx = $this->x->redSqr();
        $yy = $this->y->redSqr();
        $zz = $this->z->redSqr();
        // YYYY = YY^2
        $yyyy = $yy->redSqr();

        // M = 3 * XX + a * ZZ2; a = 0
        $m = $xx->redAdd($xx)->redIAdd($xx);
        // MM = M^2
        $mm = $m->redSqr();

        // E = 6 * ((X1 + YY)^2 - XX - YYYY) - MM
        $e = $this->x->redAdd($yy)->redSqr()->redISub($xx)->redISub($yyyy);
        $e = $e->redIAdd($e);
        $e = $e->redAdd($e)->redIAdd($e);
        $e = $e->redISub($mm);

        $ee = $e->redSqr();
        // T = 16*YYYY
        $t = $yyyy->redIAdd($yyyy);
        $t = $t->redIAdd($t);
        $t = $t->redIAdd($t);
        $t = $t->redIAdd($t);

        // U = (M + E)^2 - MM - EE - T
        $u = $m->redAdd($e)->redSqr()->redISub($mm)->redISub($ee)->redISub($t);

        $yyu4 = $yy->redMul($u);
        $yyu4 = $yyu4->redIAdd($yyu4);
        $yyu4 = $yyu4->redIAdd($yyu4);

        // X3 = 4 * (X1 * EE - 4 * YY * U)
        $nx = $this->x->redMul($ee)->redISub($yyu4);
        $nx = $nx->redIAdd($nx);
        $nx = $nx->redIAdd($nx);

        // Y3 = 8 * Y1 * (U * (T - U) - E * EE)
        $ny = $this->y->redMul($u->redMul($t->redISub($u))->redISub($e->redMul($ee)));
        $ny = $ny->redIAdd($ny);
        $ny = $ny->redIAdd($ny);
        $ny = $ny->redIAdd($ny);

        // Z3 = (Z1 + E)^2 - ZZ - EE
        $nz = $this->z->redAdd($e)->redSqr()->redISub($zz)->redISub($ee);

        return $this->curve->jpoint($nx, $ny, $nz);
    }

    public function mul($k, $kbase) {
        return $this->curve->_wnafMul($this, new BN($k, $kbase));
    }

    public function eq($p)
    {
        if( $p->type == "affine" )
            return $this->eq($p->toJ());

        if( $this == $p )
            return true;

        // x1 * z2^2 == x2 * z1^2
        $z2 = $this->z->redSqr();
        $pz2 = $p->z->redSqr();
        if( ! $this->x->redMul($pz2)->redISub($p->x->redMul($z2))->isZero() )
            return false;

        // y1 * z2^3 == y2 * z1^3
        $z3 = $z2->redMul($this->z);
        $pz3 = $pz2->redMul($p->z);

        return $this->y->redMul($pz3)->redISub($p->y->redMul($z3))->isZero();
    }

    public function eqXToP($x)
    {
        $zs = $this->z->redSqr();
        $rx = $x->toRed($this->curve->red)->redMul($zs);
        if( $this->x->cmp($rx) == 0 )
            return true;

        $xc = $x->_clone();
        $t = $this->curve->redN->redMul($zs);

        while(true)
        {
            $xc->iadd($this->curve->n);
            if( $xc->cmp($this->curve->p) >= 0 )
                return false;

            $rx->redIAdd($t);
            if( $this->x->cmp($rx) == 0 )
                return true;
        }
    }

    public function inspect()
    {
        if( $this->isInfinity() )
            return "<EC JPoint Infinity>";

        return "<EC JPoint x: " . $this->x->toString(16, 2) .
            " y: " . $this->y->toString(16, 2) .
            " z: " . $this->z->toString(16, 2) . ">";
    }

    public function __debugInfo() {
        return [
            "EC JPoint" => ($this->isInfinity() ?
                "Infinity" :
                [
                    "x" => $this->x->toString(16,2),
                    "y" => $this->y->toString(16,2),
                    "z" => $this->z->toString(16,2)
                ]
            )
        ];
    }

    public function isInfinity() {
        // XXX This code assumes that zero is always zero in red
        return $this->z->isZero();
    }
}

class ShortPoint extends Point implements JsonSerializable
{
    public $x;
    public $y;
    public $inf;

    function __construct($curve, $x, $y, $isRed)
    {
        parent::__construct($curve, 'affine');

        if( $x == null && $y == null )
        {
            $this->x = null;
            $this->y = null;
            $this->inf = true;
        }
        else
        {
            $this->x = new BN($x, 16);
            $this->y = new BN($y, 16);
            // Force redgomery representation when loading from JSON
            if( $isRed )
            {
                $this->x->forceRed($this->curve->red);
                $this->y->forceRed($this->curve->red);
            }

            if( !$this->x->red )
                $this->x = $this->x->toRed($this->curve->red);
            if( !$this->y->red )
                $this->y = $this->y->toRed($this->curve->red);
            $this->inf = false;
        }
    }

    public function _getBeta()
    {
        if( !isset($this->curve->endo) )
            return null;

        if( isset($this->precomputed) && isset($this->precomputed["beta"]) )
            return $this->precomputed["beta"];

        $beta = $this->curve->point($this->x->redMul($this->curve->endo["beta"]), $this->y);
        if( isset($this->precomputed) )
        {
            $endoMul = function($p) {
                return $this->curve->point($p->x->redMul($this->curve->endo["beta"]), $p->y);
            };
            $beta->precomputed = array(
                "beta" => null,
                "naf" => null,
                "doubles" => null
            );

            if( isset($this->precomputed["naf"]) )
            {
                $beta->precomputed["naf"] = array(
                    "wnd" => $this->precomputed["naf"]["wnd"],
                    "points" => array_map($endoMul, $this->precomputed["naf"]["points"])
                );
            }

            if( isset($this->precomputed["doubles"]) )
            {
                $beta->precomputed["doubles"] = array(
                    "step" => $this->precomputed["doubles"]["step"],
                    "points" => array_map($endoMul, $this->precomputed["doubles"]["points"])
                );
            }
            $this->precomputed["beta"] = $beta;
        }
        return $beta;
    }

    //toJSON()
    public function jsonSerialize()
    {
        $res = array($this->x, $this->y);

        if( !isset($this->precomputed) )
            return $res;

        $pre = array();
        $addPre = false;
        if( isset($this->precomputed["doubles"]) )
        {
            $pre["doubles"] = array(
                "step" => $this->precomputed["doubles"]["step"],
                "points" => array_slice($this->precomputed["doubles"]["points"], 1)
            );
            $addPre = true;
        }

        if( isset($this->precomputed["naf"]) )
        {
            $pre["naf"] = array(
                "naf" => $this->precomputed["naf"]["wnd"],
                "points" => array_slice($this->precomputed["naf"]["points"], 1)
            );
            $addPre = true;
        }

        if( $addPre )
            array_push($res, $pre);

        return $res;
    }

    public static function fromJSON($curve, $obj, $red)
    {
        if( is_string($obj) )
            $obj = json_decode($obj);

        $point = $curve->point($obj[0], $obj[1], $red);
        if( count($obj) === 2 )
            return $point;

        $pre = $obj[2];
        $point->precomputed = array("beta" => null);
        $obj2point = function($obj) use ($curve, $red) {
            return $curve->point($obj[0], $obj[1], $red);
        };

        if( isset($pre["doubles"]) )
        {
            $tmp = array_map($obj2point, $pre["doubles"]["points"]);
            array_unshift($tmp, $point);
            $point->precomputed["doubles"] = array(
                "step" => $pre["doubles"]["step"],
                "points" => $tmp
            );
        }

        if( isset($pre["naf"]) )
        {
            $tmp = array_map($obj2point, $pre["naf"]["points"]);
            array_unshift($tmp, $point);
            $point->precomputed["naf"] = array(
                "wnd" => $pre["naf"]["wnd"],
                "points" => $tmp
            );
        }

        return $point;
    }

    public function inspect()
    {
        if( $this->isInfinity() )
            return "<EC Point Infinity>";

        return "<EC Point x: " . $this->x->fromRed()->toString(16, 2) .
            " y: " . $this->y->fromRed()->toString(16, 2) . ">";
    }

    public function __debugInfo() {
        return [
            "EC Point" => ($this->isInfinity() ?
                "Infinity" :
                [
                    "x" => $this->x->fromRed()->toString(16, 2),
                    "y" => $this->y->fromRed()->toString(16, 2)
                ])
        ];
    }
    public function isInfinity() {
        return $this->inf;
    }

    public function add($point)
    {
        // O + P = P
        if( $this->inf )
            return $point;

        // P + O = P
        if( $point->inf )
            return $this;

        // P + P = 2P
        if( $this->eq($point) )
            return $this->dbl();

        // P + (-P) = O
        if( $this->neg()->eq($point) )
            return $this->curve->point(null, null);

        // P + Q = O
        if( $this->x->cmp($point->x) === 0 )
            return $this->curve->point(null, null);

        $c = $this->y->redSub($point->y);
        if( ! $c->isZero() )
            $c = $c->redMul($this->x->redSub($point->x)->redInvm());
        $nx = $c->redSqr()->redISub($this->x)->redISub($point->x);
        $ny = $c->redMul($this->x->redSub($nx))->redISub($this->y);

        return $this->curve->point($nx, $ny);
    }

    public function dbl()
    {
        if( $this->inf )
            return $this;

        // 2P = 0
        $ys1 = $this->y->redAdd($this->y);
        if( $ys1->isZero() )
            return $this->curve->point(null, null);

        $x2 = $this->x->redSqr();
        $dyinv = $ys1->redInvm();
        $c = $x2->redAdd($x2)->redIAdd($x2)->redIAdd($this->curve->a)->redMul($dyinv);

        $nx = $c->redSqr()->redISub($this->x->redAdd($this->x));
        $ny = $c->redMul($this->x->redSub($nx))->redISub($this->y);

        return $this->curve->point($nx, $ny);
    }

    public function getX() {
        return $this->x->fromRed();
    }

    public function getY() {
        return $this->y->fromRed();
    }

    public function mul($k)
    {
        $k = new BN($k, 16);

        if( $this->_hasDoubles($k) )
            return $this->curve->_fixedNafMul($this, $k);
        elseif( isset($this->curve->endo) )
            return $this->curve->_endoWnafMulAdd(array($this), array($k));

        return $this->curve->_wnafMul($this, $k);
    }

    public function mulAdd($k1, $p2, $k2, $j = false)
    {
        $points = array($this, $p2);
        $coeffs = array($k1, $k2);

        if( isset($this->curve->endo) )
            return $this->curve->_endoWnafMulAdd($points, $coeffs, $j);

        return $this->curve->_wnafMulAdd(1, $points, $coeffs, 2, $j);
    }

    public function jmulAdd($k1, $p2, $k2) {
        return $this->mulAdd($k1, $p2, $k2, true);
    }

    public function eq($point)
    {
        return (
            $this === $point ||
            $this->inf === $point->inf &&
            ($this->inf || $this->x->cmp($point->x) === 0 && $this->y->cmp($point->y) === 0)
        );
    }

    public function neg($precompute = false)
    {
        if( $this->inf )
            return $this;

        $res = $this->curve->point($this->x, $this->y->redNeg());
        if( $precompute && isset($this->precomputed) )
        {
            $res->precomputed = array();
            $pre = $this->precomputed;
            $negate = function($point) {
                return $point->neg();
            };

            if( isset($pre["naf"]) )
            {
                $res->precomputed["naf"] = array(
                    "wnd" => $pre["naf"]["wnd"],
                    "points" => array_map($negate, $pre["naf"]["points"])
                );
            }

            if( isset($pre["doubles"]) )
            {
                $res->precomputed["doubles"] = array(
                    "step" => $pre["doubles"]["step"],
                    "points" => array_map($negate, $pre["doubles"]["points"])
                );
            }

        }
        return $res;
    }

    public function toJ()
    {
        if( $this->inf )
            return $this->curve->jpoint(null, null, null);

        return $this->curve->jpoint($this->x, $this->y, $this->curve->one);
    }
}


class Curves
{
    private static $curves;

    public static function hasCurve($name) {
        return isset(self::$curves[$name]);
    }
    public static function getCurve($name) {
        if (!isset(self::$curves[$name])) {
            throw new \Exception('Unknown curve ' . $name);
        }
        return self::$curves[$name];
    }

    public static function defineCurve($name, $options)
    {
        self::$curves[$name] = new PresetCurve($options);
    }
}

$sha256 = [ "blockSize" => 512, "outSize" => 256, "hmacStrength" => 192, "padLength" => 64, "algo" => 'sha256' ];
$sha224 = [ "blockSize" => 512, "outSize" => 224, "hmacStrength" => 192, "padLength" => 64, "algo" => 'sha224' ];
$sha512 = [ "blockSize" => 1024, "outSize" => 512, "hmacStrength" => 192, "padLength" => 128, "algo" => 'sha512' ];
$sha384 = [ "blockSize" => 1024, "outSize" => 384, "hmacStrength" => 192, "padLength" => 128, "algo" => 'sha384' ];
$sha1 = [ "blockSize" => 512, "outSize" => 160, "hmacStrength" => 80, "padLength" => 64, "algo" => 'sha1' ];

Curves::defineCurve("p192", array(
    "type" => "short",
    "prime" => "p192",
    "p" => "ffffffff ffffffff ffffffff fffffffe ffffffff ffffffff",
    "a" => "ffffffff ffffffff ffffffff fffffffe ffffffff fffffffc",
    "b" => "64210519 e59c80e7 0fa7e9ab 72243049 feb8deec c146b9b1",
    "n" => "ffffffff ffffffff ffffffff 99def836 146bc9b1 b4d22831",
    "hash" => $sha256,
    "gRed" => false,
    "g" => array(
        "188da80e b03090f6 7cbf20eb 43a18800 f4ff0afd 82ff1012",
        "07192b95 ffc8da78 631011ed 6b24cdd5 73f977a1 1e794811"
    )
));

Curves::defineCurve("p224", array(
    "type" => "short",
    "prime" => "p224",
    "p" => "ffffffff ffffffff ffffffff ffffffff 00000000 00000000 00000001",
    "a" => "ffffffff ffffffff ffffffff fffffffe ffffffff ffffffff fffffffe",
    "b" => "b4050a85 0c04b3ab f5413256 5044b0b7 d7bfd8ba 270b3943 2355ffb4",
    "n" => "ffffffff ffffffff ffffffff ffff16a2 e0b8f03e 13dd2945 5c5c2a3d",
    "hash" => $sha256,
    "gRed" => false,
    "g" => array(
        "b70e0cbd 6bb4bf7f 321390b9 4a03c1d3 56c21122 343280d6 115c1d21",
        "bd376388 b5f723fb 4c22dfe6 cd4375a0 5a074764 44d58199 85007e34"
    )
));

Curves::defineCurve("p256", array(
    "type" => "short",
    "prime" => null,
    "p" => "ffffffff 00000001 00000000 00000000 00000000 ffffffff ffffffff ffffffff",
    "a" => "ffffffff 00000001 00000000 00000000 00000000 ffffffff ffffffff fffffffc",
    "b" => "5ac635d8 aa3a93e7 b3ebbd55 769886bc 651d06b0 cc53b0f6 3bce3c3e 27d2604b",
    "n" => "ffffffff 00000000 ffffffff ffffffff bce6faad a7179e84 f3b9cac2 fc632551",
    "hash" => $sha256,
    "gRed" => false,
    "g" => array(
        "6b17d1f2 e12c4247 f8bce6e5 63a440f2 77037d81 2deb33a0 f4a13945 d898c296",
        "4fe342e2 fe1a7f9b 8ee7eb4a 7c0f9e16 2bce3357 6b315ece cbb64068 37bf51f5"
    )
));

Curves::defineCurve("p384", array(
    "type" => "short",
    "prime" => null,
    "p" => "ffffffff ffffffff ffffffff ffffffff ffffffff ffffffff ffffffff " .
        "fffffffe ffffffff 00000000 00000000 ffffffff",
    "a" => "ffffffff ffffffff ffffffff ffffffff ffffffff ffffffff ffffffff " .
        "fffffffe ffffffff 00000000 00000000 fffffffc",
    "b" => "b3312fa7 e23ee7e4 988e056b e3f82d19 181d9c6e fe814112 0314088f " .
        "5013875a c656398d 8a2ed19d 2a85c8ed d3ec2aef",
    "n" => "ffffffff ffffffff ffffffff ffffffff ffffffff ffffffff c7634d81 " .
        "f4372ddf 581a0db2 48b0a77a ecec196a ccc52973",
    "hash" => $sha384,
    "gRed" => false,
    "g" => array(
        "aa87ca22 be8b0537 8eb1c71e f320ad74 6e1d3b62 8ba79b98 59f741e0 82542a38 " .
        "5502f25d bf55296c 3a545e38 72760ab7",
        "3617de4a 96262c6f 5d9e98bf 9292dc29 f8f41dbd 289a147c e9da3113 b5f0b8c0 " .
        "0a60b1ce 1d7e819d 7a431d7c 90ea0e5f"
    )
));

Curves::defineCurve("p521", array(
    "type" => "short",
    "prime" => null,
    "p" => "000001ff ffffffff ffffffff ffffffff ffffffff ffffffff " .
        "ffffffff ffffffff ffffffff ffffffff ffffffff ffffffff " .
        "ffffffff ffffffff ffffffff ffffffff ffffffff",
    "a" => "000001ff ffffffff ffffffff ffffffff ffffffff ffffffff " .
        "ffffffff ffffffff ffffffff ffffffff ffffffff ffffffff " .
        "ffffffff ffffffff ffffffff ffffffff fffffffc",
    "b" => "00000051 953eb961 8e1c9a1f 929a21a0 b68540ee a2da725b " .
        "99b315f3 b8b48991 8ef109e1 56193951 ec7e937b 1652c0bd " .
        "3bb1bf07 3573df88 3d2c34f1 ef451fd4 6b503f00",
    "n" => "000001ff ffffffff ffffffff ffffffff ffffffff ffffffff " .
        "ffffffff ffffffff fffffffa 51868783 bf2f966b 7fcc0148 " .
        "f709a5d0 3bb5c9b8 899c47ae bb6fb71e 91386409",
    "hash" => $sha512,
    "gRed" => false,
    "g" => array(
        "000000c6 858e06b7 0404e9cd 9e3ecb66 2395b442 9c648139 " .
        "053fb521 f828af60 6b4d3dba a14b5e77 efe75928 fe1dc127 " .
        "a2ffa8de 3348b3c1 856a429b f97e7e31 c2e5bd66",
        "00000118 39296a78 9a3bc004 5c8a5fb4 2c7d1bd9 98f54449 " .
        "579b4468 17afbd17 273e662c 97ee7299 5ef42640 c550b901 " .
        "3fad0761 353c7086 a272c240 88be9476 9fd16650"
    )
));

Curves::defineCurve("curve25519", array(
    "type" => "mont",
    "prime" => "p25519",
    "p" => "7fffffffffffffff ffffffffffffffff ffffffffffffffff ffffffffffffffed",
    "a" => "76d06",
    "b" => "0",
    "n" => "1000000000000000 0000000000000000 14def9dea2f79cd6 5812631a5cf5d3ed",
    "hash" => $sha256,
    "gRed" => false,
    "g" => array(
        "9"
    )
));

Curves::defineCurve("ed25519", array(
    "type" => "edwards",
    "prime" => "p25519",
    "p" => "7fffffffffffffff ffffffffffffffff ffffffffffffffff ffffffffffffffed",
    "a" => "-1",
    "c" => "1",
    // -121665 * (121666^(-1)) (mod P)
    "d" => "52036cee2b6ffe73 8cc740797779e898 00700a4d4141d8ab 75eb4dca135978a3",
    "n" => "1000000000000000 0000000000000000 14def9dea2f79cd6 5812631a5cf5d3ed",
    "hash" => $sha256,
    "gRed" => false,
    "g" => array(
        "216936d3cd6e53fec0a4e231fdd6dc5c692cc7609525a7b2c9562d608f25d51a",
        // 4/5
        "6666666666666666666666666666666666666666666666666666666666666658"
    )
));

$pre = array(
    "doubles" => array(
        "step" => 4,
        "points" => array(
            array(
                "e60fce93b59e9ec53011aabc21c23e97b2a31369b87a5ae9c44ee89e2a6dec0a",
                "f7e3507399e595929db99f34f57937101296891e44d23f0be1f32cce69616821"
            ),
            array(
                "8282263212c609d9ea2a6e3e172de238d8c39cabd5ac1ca10646e23fd5f51508",
                "11f8a8098557dfe45e8256e830b60ace62d613ac2f7b17bed31b6eaff6e26caf"
            ),
            array(
                "175e159f728b865a72f99cc6c6fc846de0b93833fd2222ed73fce5b551e5b739",
                "d3506e0d9e3c79eba4ef97a51ff71f5eacb5955add24345c6efa6ffee9fed695"
            ),
            array(
                "363d90d447b00c9c99ceac05b6262ee053441c7e55552ffe526bad8f83ff4640",
                "4e273adfc732221953b445397f3363145b9a89008199ecb62003c7f3bee9de9"
            ),
            array(
                "8b4b5f165df3c2be8c6244b5b745638843e4a781a15bcd1b69f79a55dffdf80c",
                "4aad0a6f68d308b4b3fbd7813ab0da04f9e336546162ee56b3eff0c65fd4fd36"
            ),
            array(
                "723cbaa6e5db996d6bf771c00bd548c7b700dbffa6c0e77bcb6115925232fcda",
                "96e867b5595cc498a921137488824d6e2660a0653779494801dc069d9eb39f5f"
            ),
            array(
                "eebfa4d493bebf98ba5feec812c2d3b50947961237a919839a533eca0e7dd7fa",
                "5d9a8ca3970ef0f269ee7edaf178089d9ae4cdc3a711f712ddfd4fdae1de8999"
            ),
            array(
                "100f44da696e71672791d0a09b7bde459f1215a29b3c03bfefd7835b39a48db0",
                "cdd9e13192a00b772ec8f3300c090666b7ff4a18ff5195ac0fbd5cd62bc65a09"
            ),
            array(
                "e1031be262c7ed1b1dc9227a4a04c017a77f8d4464f3b3852c8acde6e534fd2d",
                "9d7061928940405e6bb6a4176597535af292dd419e1ced79a44f18f29456a00d"
            ),
            array(
                "feea6cae46d55b530ac2839f143bd7ec5cf8b266a41d6af52d5e688d9094696d",
                "e57c6b6c97dce1bab06e4e12bf3ecd5c981c8957cc41442d3155debf18090088"
            ),
            array(
                "da67a91d91049cdcb367be4be6ffca3cfeed657d808583de33fa978bc1ec6cb1",
                "9bacaa35481642bc41f463f7ec9780e5dec7adc508f740a17e9ea8e27a68be1d"
            ),
            array(
                "53904faa0b334cdda6e000935ef22151ec08d0f7bb11069f57545ccc1a37b7c0",
                "5bc087d0bc80106d88c9eccac20d3c1c13999981e14434699dcb096b022771c8"
            ),
            array(
                "8e7bcd0bd35983a7719cca7764ca906779b53a043a9b8bcaeff959f43ad86047",
                "10b7770b2a3da4b3940310420ca9514579e88e2e47fd68b3ea10047e8460372a"
            ),
            array(
                "385eed34c1cdff21e6d0818689b81bde71a7f4f18397e6690a841e1599c43862",
                "283bebc3e8ea23f56701de19e9ebf4576b304eec2086dc8cc0458fe5542e5453"
            ),
            array(
                "6f9d9b803ecf191637c73a4413dfa180fddf84a5947fbc9c606ed86c3fac3a7",
                "7c80c68e603059ba69b8e2a30e45c4d47ea4dd2f5c281002d86890603a842160"
            ),
            array(
                "3322d401243c4e2582a2147c104d6ecbf774d163db0f5e5313b7e0e742d0e6bd",
                "56e70797e9664ef5bfb019bc4ddaf9b72805f63ea2873af624f3a2e96c28b2a0"
            ),
            array(
                "85672c7d2de0b7da2bd1770d89665868741b3f9af7643397721d74d28134ab83",
                "7c481b9b5b43b2eb6374049bfa62c2e5e77f17fcc5298f44c8e3094f790313a6"
            ),
            array(
                "948bf809b1988a46b06c9f1919413b10f9226c60f668832ffd959af60c82a0a",
                "53a562856dcb6646dc6b74c5d1c3418c6d4dff08c97cd2bed4cb7f88d8c8e589"
            ),
            array(
                "6260ce7f461801c34f067ce0f02873a8f1b0e44dfc69752accecd819f38fd8e8",
                "bc2da82b6fa5b571a7f09049776a1ef7ecd292238051c198c1a84e95b2b4ae17"
            ),
            array(
                "e5037de0afc1d8d43d8348414bbf4103043ec8f575bfdc432953cc8d2037fa2d",
                "4571534baa94d3b5f9f98d09fb990bddbd5f5b03ec481f10e0e5dc841d755bda"
            ),
            array(
                "e06372b0f4a207adf5ea905e8f1771b4e7e8dbd1c6a6c5b725866a0ae4fce725",
                "7a908974bce18cfe12a27bb2ad5a488cd7484a7787104870b27034f94eee31dd"
            ),
            array(
                "213c7a715cd5d45358d0bbf9dc0ce02204b10bdde2a3f58540ad6908d0559754",
                "4b6dad0b5ae462507013ad06245ba190bb4850f5f36a7eeddff2c27534b458f2"
            ),
            array(
                "4e7c272a7af4b34e8dbb9352a5419a87e2838c70adc62cddf0cc3a3b08fbd53c",
                "17749c766c9d0b18e16fd09f6def681b530b9614bff7dd33e0b3941817dcaae6"
            ),
            array(
                "fea74e3dbe778b1b10f238ad61686aa5c76e3db2be43057632427e2840fb27b6",
                "6e0568db9b0b13297cf674deccb6af93126b596b973f7b77701d3db7f23cb96f"
            ),
            array(
                "76e64113f677cf0e10a2570d599968d31544e179b760432952c02a4417bdde39",
                "c90ddf8dee4e95cf577066d70681f0d35e2a33d2b56d2032b4b1752d1901ac01"
            ),
            array(
                "c738c56b03b2abe1e8281baa743f8f9a8f7cc643df26cbee3ab150242bcbb891",
                "893fb578951ad2537f718f2eacbfbbbb82314eef7880cfe917e735d9699a84c3"
            ),
            array(
                "d895626548b65b81e264c7637c972877d1d72e5f3a925014372e9f6588f6c14b",
                "febfaa38f2bc7eae728ec60818c340eb03428d632bb067e179363ed75d7d991f"
            ),
            array(
                "b8da94032a957518eb0f6433571e8761ceffc73693e84edd49150a564f676e03",
                "2804dfa44805a1e4d7c99cc9762808b092cc584d95ff3b511488e4e74efdf6e7"
            ),
            array(
                "e80fea14441fb33a7d8adab9475d7fab2019effb5156a792f1a11778e3c0df5d",
                "eed1de7f638e00771e89768ca3ca94472d155e80af322ea9fcb4291b6ac9ec78"
            ),
            array(
                "a301697bdfcd704313ba48e51d567543f2a182031efd6915ddc07bbcc4e16070",
                "7370f91cfb67e4f5081809fa25d40f9b1735dbf7c0a11a130c0d1a041e177ea1"
            ),
            array(
                "90ad85b389d6b936463f9d0512678de208cc330b11307fffab7ac63e3fb04ed4",
                "e507a3620a38261affdcbd9427222b839aefabe1582894d991d4d48cb6ef150"
            ),
            array(
                "8f68b9d2f63b5f339239c1ad981f162ee88c5678723ea3351b7b444c9ec4c0da",
                "662a9f2dba063986de1d90c2b6be215dbbea2cfe95510bfdf23cbf79501fff82"
            ),
            array(
                "e4f3fb0176af85d65ff99ff9198c36091f48e86503681e3e6686fd5053231e11",
                "1e63633ad0ef4f1c1661a6d0ea02b7286cc7e74ec951d1c9822c38576feb73bc"
            ),
            array(
                "8c00fa9b18ebf331eb961537a45a4266c7034f2f0d4e1d0716fb6eae20eae29e",
                "efa47267fea521a1a9dc343a3736c974c2fadafa81e36c54e7d2a4c66702414b"
            ),
            array(
                "e7a26ce69dd4829f3e10cec0a9e98ed3143d084f308b92c0997fddfc60cb3e41",
                "2a758e300fa7984b471b006a1aafbb18d0a6b2c0420e83e20e8a9421cf2cfd51"
            ),
            array(
                "b6459e0ee3662ec8d23540c223bcbdc571cbcb967d79424f3cf29eb3de6b80ef",
                "67c876d06f3e06de1dadf16e5661db3c4b3ae6d48e35b2ff30bf0b61a71ba45"
            ),
            array(
                "d68a80c8280bb840793234aa118f06231d6f1fc67e73c5a5deda0f5b496943e8",
                "db8ba9fff4b586d00c4b1f9177b0e28b5b0e7b8f7845295a294c84266b133120"
            ),
            array(
                "324aed7df65c804252dc0270907a30b09612aeb973449cea4095980fc28d3d5d",
                "648a365774b61f2ff130c0c35aec1f4f19213b0c7e332843967224af96ab7c84"
            ),
            array(
                "4df9c14919cde61f6d51dfdbe5fee5dceec4143ba8d1ca888e8bd373fd054c96",
                "35ec51092d8728050974c23a1d85d4b5d506cdc288490192ebac06cad10d5d"
            ),
            array(
                "9c3919a84a474870faed8a9c1cc66021523489054d7f0308cbfc99c8ac1f98cd",
                "ddb84f0f4a4ddd57584f044bf260e641905326f76c64c8e6be7e5e03d4fc599d"
            ),
            array(
                "6057170b1dd12fdf8de05f281d8e06bb91e1493a8b91d4cc5a21382120a959e5",
                "9a1af0b26a6a4807add9a2daf71df262465152bc3ee24c65e899be932385a2a8"
            ),
            array(
                "a576df8e23a08411421439a4518da31880cef0fba7d4df12b1a6973eecb94266",
                "40a6bf20e76640b2c92b97afe58cd82c432e10a7f514d9f3ee8be11ae1b28ec8"
            ),
            array(
                "7778a78c28dec3e30a05fe9629de8c38bb30d1f5cf9a3a208f763889be58ad71",
                "34626d9ab5a5b22ff7098e12f2ff580087b38411ff24ac563b513fc1fd9f43ac"
            ),
            array(
                "928955ee637a84463729fd30e7afd2ed5f96274e5ad7e5cb09eda9c06d903ac",
                "c25621003d3f42a827b78a13093a95eeac3d26efa8a8d83fc5180e935bcd091f"
            ),
            array(
                "85d0fef3ec6db109399064f3a0e3b2855645b4a907ad354527aae75163d82751",
                "1f03648413a38c0be29d496e582cf5663e8751e96877331582c237a24eb1f962"
            ),
            array(
                "ff2b0dce97eece97c1c9b6041798b85dfdfb6d8882da20308f5404824526087e",
                "493d13fef524ba188af4c4dc54d07936c7b7ed6fb90e2ceb2c951e01f0c29907"
            ),
            array(
                "827fbbe4b1e880ea9ed2b2e6301b212b57f1ee148cd6dd28780e5e2cf856e241",
                "c60f9c923c727b0b71bef2c67d1d12687ff7a63186903166d605b68baec293ec"
            ),
            array(
                "eaa649f21f51bdbae7be4ae34ce6e5217a58fdce7f47f9aa7f3b58fa2120e2b3",
                "be3279ed5bbbb03ac69a80f89879aa5a01a6b965f13f7e59d47a5305ba5ad93d"
            ),
            array(
                "e4a42d43c5cf169d9391df6decf42ee541b6d8f0c9a137401e23632dda34d24f",
                "4d9f92e716d1c73526fc99ccfb8ad34ce886eedfa8d8e4f13a7f7131deba9414"
            ),
            array(
                "1ec80fef360cbdd954160fadab352b6b92b53576a88fea4947173b9d4300bf19",
                "aeefe93756b5340d2f3a4958a7abbf5e0146e77f6295a07b671cdc1cc107cefd"
            ),
            array(
                "146a778c04670c2f91b00af4680dfa8bce3490717d58ba889ddb5928366642be",
                "b318e0ec3354028add669827f9d4b2870aaa971d2f7e5ed1d0b297483d83efd0"
            ),
            array(
                "fa50c0f61d22e5f07e3acebb1aa07b128d0012209a28b9776d76a8793180eef9",
                "6b84c6922397eba9b72cd2872281a68a5e683293a57a213b38cd8d7d3f4f2811"
            ),
            array(
                "da1d61d0ca721a11b1a5bf6b7d88e8421a288ab5d5bba5220e53d32b5f067ec2",
                "8157f55a7c99306c79c0766161c91e2966a73899d279b48a655fba0f1ad836f1"
            ),
            array(
                "a8e282ff0c9706907215ff98e8fd416615311de0446f1e062a73b0610d064e13",
                "7f97355b8db81c09abfb7f3c5b2515888b679a3e50dd6bd6cef7c73111f4cc0c"
            ),
            array(
                "174a53b9c9a285872d39e56e6913cab15d59b1fa512508c022f382de8319497c",
                "ccc9dc37abfc9c1657b4155f2c47f9e6646b3a1d8cb9854383da13ac079afa73"
            ),
            array(
                "959396981943785c3d3e57edf5018cdbe039e730e4918b3d884fdff09475b7ba",
                "2e7e552888c331dd8ba0386a4b9cd6849c653f64c8709385e9b8abf87524f2fd"
            ),
            array(
                "d2a63a50ae401e56d645a1153b109a8fcca0a43d561fba2dbb51340c9d82b151",
                "e82d86fb6443fcb7565aee58b2948220a70f750af484ca52d4142174dcf89405"
            ),
            array(
                "64587e2335471eb890ee7896d7cfdc866bacbdbd3839317b3436f9b45617e073",
                "d99fcdd5bf6902e2ae96dd6447c299a185b90a39133aeab358299e5e9faf6589"
            ),
            array(
                "8481bde0e4e4d885b3a546d3e549de042f0aa6cea250e7fd358d6c86dd45e458",
                "38ee7b8cba5404dd84a25bf39cecb2ca900a79c42b262e556d64b1b59779057e"
            ),
            array(
                "13464a57a78102aa62b6979ae817f4637ffcfed3c4b1ce30bcd6303f6caf666b",
                "69be159004614580ef7e433453ccb0ca48f300a81d0942e13f495a907f6ecc27"
            ),
            array(
                "bc4a9df5b713fe2e9aef430bcc1dc97a0cd9ccede2f28588cada3a0d2d83f366",
                "d3a81ca6e785c06383937adf4b798caa6e8a9fbfa547b16d758d666581f33c1"
            ),
            array(
                "8c28a97bf8298bc0d23d8c749452a32e694b65e30a9472a3954ab30fe5324caa",
                "40a30463a3305193378fedf31f7cc0eb7ae784f0451cb9459e71dc73cbef9482"
            ),
            array(
                "8ea9666139527a8c1dd94ce4f071fd23c8b350c5a4bb33748c4ba111faccae0",
                "620efabbc8ee2782e24e7c0cfb95c5d735b783be9cf0f8e955af34a30e62b945"
            ),
            array(
                "dd3625faef5ba06074669716bbd3788d89bdde815959968092f76cc4eb9a9787",
                "7a188fa3520e30d461da2501045731ca941461982883395937f68d00c644a573"
            ),
            array(
                "f710d79d9eb962297e4f6232b40e8f7feb2bc63814614d692c12de752408221e",
                "ea98e67232d3b3295d3b535532115ccac8612c721851617526ae47a9c77bfc82"
            )
        )
    ),
    "naf" => array(
        "wnd" => 7,
        "points" => array(
            array(
                "f9308a019258c31049344f85f89d5229b531c845836f99b08601f113bce036f9",
                "388f7b0f632de8140fe337e62a37f3566500a99934c2231b6cb9fd7584b8e672"
            ),
            array(
                "2f8bde4d1a07209355b4a7250a5c5128e88b84bddc619ab7cba8d569b240efe4",
                "d8ac222636e5e3d6d4dba9dda6c9c426f788271bab0d6840dca87d3aa6ac62d6"
            ),
            array(
                "5cbdf0646e5db4eaa398f365f2ea7a0e3d419b7e0330e39ce92bddedcac4f9bc",
                "6aebca40ba255960a3178d6d861a54dba813d0b813fde7b5a5082628087264da"
            ),
            array(
                "acd484e2f0c7f65309ad178a9f559abde09796974c57e714c35f110dfc27ccbe",
                "cc338921b0a7d9fd64380971763b61e9add888a4375f8e0f05cc262ac64f9c37"
            ),
            array(
                "774ae7f858a9411e5ef4246b70c65aac5649980be5c17891bbec17895da008cb",
                "d984a032eb6b5e190243dd56d7b7b365372db1e2dff9d6a8301d74c9c953c61b"
            ),
            array(
                "f28773c2d975288bc7d1d205c3748651b075fbc6610e58cddeeddf8f19405aa8",
                "ab0902e8d880a89758212eb65cdaf473a1a06da521fa91f29b5cb52db03ed81"
            ),
            array(
                "d7924d4f7d43ea965a465ae3095ff41131e5946f3c85f79e44adbcf8e27e080e",
                "581e2872a86c72a683842ec228cc6defea40af2bd896d3a5c504dc9ff6a26b58"
            ),
            array(
                "defdea4cdb677750a420fee807eacf21eb9898ae79b9768766e4faa04a2d4a34",
                "4211ab0694635168e997b0ead2a93daeced1f4a04a95c0f6cfb199f69e56eb77"
            ),
            array(
                "2b4ea0a797a443d293ef5cff444f4979f06acfebd7e86d277475656138385b6c",
                "85e89bc037945d93b343083b5a1c86131a01f60c50269763b570c854e5c09b7a"
            ),
            array(
                "352bbf4a4cdd12564f93fa332ce333301d9ad40271f8107181340aef25be59d5",
                "321eb4075348f534d59c18259dda3e1f4a1b3b2e71b1039c67bd3d8bcf81998c"
            ),
            array(
                "2fa2104d6b38d11b0230010559879124e42ab8dfeff5ff29dc9cdadd4ecacc3f",
                "2de1068295dd865b64569335bd5dd80181d70ecfc882648423ba76b532b7d67"
            ),
            array(
                "9248279b09b4d68dab21a9b066edda83263c3d84e09572e269ca0cd7f5453714",
                "73016f7bf234aade5d1aa71bdea2b1ff3fc0de2a887912ffe54a32ce97cb3402"
            ),
            array(
                "daed4f2be3a8bf278e70132fb0beb7522f570e144bf615c07e996d443dee8729",
                "a69dce4a7d6c98e8d4a1aca87ef8d7003f83c230f3afa726ab40e52290be1c55"
            ),
            array(
                "c44d12c7065d812e8acf28d7cbb19f9011ecd9e9fdf281b0e6a3b5e87d22e7db",
                "2119a460ce326cdc76c45926c982fdac0e106e861edf61c5a039063f0e0e6482"
            ),
            array(
                "6a245bf6dc698504c89a20cfded60853152b695336c28063b61c65cbd269e6b4",
                "e022cf42c2bd4a708b3f5126f16a24ad8b33ba48d0423b6efd5e6348100d8a82"
            ),
            array(
                "1697ffa6fd9de627c077e3d2fe541084ce13300b0bec1146f95ae57f0d0bd6a5",
                "b9c398f186806f5d27561506e4557433a2cf15009e498ae7adee9d63d01b2396"
            ),
            array(
                "605bdb019981718b986d0f07e834cb0d9deb8360ffb7f61df982345ef27a7479",
                "2972d2de4f8d20681a78d93ec96fe23c26bfae84fb14db43b01e1e9056b8c49"
            ),
            array(
                "62d14dab4150bf497402fdc45a215e10dcb01c354959b10cfe31c7e9d87ff33d",
                "80fc06bd8cc5b01098088a1950eed0db01aa132967ab472235f5642483b25eaf"
            ),
            array(
                "80c60ad0040f27dade5b4b06c408e56b2c50e9f56b9b8b425e555c2f86308b6f",
                "1c38303f1cc5c30f26e66bad7fe72f70a65eed4cbe7024eb1aa01f56430bd57a"
            ),
            array(
                "7a9375ad6167ad54aa74c6348cc54d344cc5dc9487d847049d5eabb0fa03c8fb",
                "d0e3fa9eca8726909559e0d79269046bdc59ea10c70ce2b02d499ec224dc7f7"
            ),
            array(
                "d528ecd9b696b54c907a9ed045447a79bb408ec39b68df504bb51f459bc3ffc9",
                "eecf41253136e5f99966f21881fd656ebc4345405c520dbc063465b521409933"
            ),
            array(
                "49370a4b5f43412ea25f514e8ecdad05266115e4a7ecb1387231808f8b45963",
                "758f3f41afd6ed428b3081b0512fd62a54c3f3afbb5b6764b653052a12949c9a"
            ),
            array(
                "77f230936ee88cbbd73df930d64702ef881d811e0e1498e2f1c13eb1fc345d74",
                "958ef42a7886b6400a08266e9ba1b37896c95330d97077cbbe8eb3c7671c60d6"
            ),
            array(
                "f2dac991cc4ce4b9ea44887e5c7c0bce58c80074ab9d4dbaeb28531b7739f530",
                "e0dedc9b3b2f8dad4da1f32dec2531df9eb5fbeb0598e4fd1a117dba703a3c37"
            ),
            array(
                "463b3d9f662621fb1b4be8fbbe2520125a216cdfc9dae3debcba4850c690d45b",
                "5ed430d78c296c3543114306dd8622d7c622e27c970a1de31cb377b01af7307e"
            ),
            array(
                "f16f804244e46e2a09232d4aff3b59976b98fac14328a2d1a32496b49998f247",
                "cedabd9b82203f7e13d206fcdf4e33d92a6c53c26e5cce26d6579962c4e31df6"
            ),
            array(
                "caf754272dc84563b0352b7a14311af55d245315ace27c65369e15f7151d41d1",
                "cb474660ef35f5f2a41b643fa5e460575f4fa9b7962232a5c32f908318a04476"
            ),
            array(
                "2600ca4b282cb986f85d0f1709979d8b44a09c07cb86d7c124497bc86f082120",
                "4119b88753c15bd6a693b03fcddbb45d5ac6be74ab5f0ef44b0be9475a7e4b40"
            ),
            array(
                "7635ca72d7e8432c338ec53cd12220bc01c48685e24f7dc8c602a7746998e435",
                "91b649609489d613d1d5e590f78e6d74ecfc061d57048bad9e76f302c5b9c61"
            ),
            array(
                "754e3239f325570cdbbf4a87deee8a66b7f2b33479d468fbc1a50743bf56cc18",
                "673fb86e5bda30fb3cd0ed304ea49a023ee33d0197a695d0c5d98093c536683"
            ),
            array(
                "e3e6bd1071a1e96aff57859c82d570f0330800661d1c952f9fe2694691d9b9e8",
                "59c9e0bba394e76f40c0aa58379a3cb6a5a2283993e90c4167002af4920e37f5"
            ),
            array(
                "186b483d056a033826ae73d88f732985c4ccb1f32ba35f4b4cc47fdcf04aa6eb",
                "3b952d32c67cf77e2e17446e204180ab21fb8090895138b4a4a797f86e80888b"
            ),
            array(
                "df9d70a6b9876ce544c98561f4be4f725442e6d2b737d9c91a8321724ce0963f",
                "55eb2dafd84d6ccd5f862b785dc39d4ab157222720ef9da217b8c45cf2ba2417"
            ),
            array(
                "5edd5cc23c51e87a497ca815d5dce0f8ab52554f849ed8995de64c5f34ce7143",
                "efae9c8dbc14130661e8cec030c89ad0c13c66c0d17a2905cdc706ab7399a868"
            ),
            array(
                "290798c2b6476830da12fe02287e9e777aa3fba1c355b17a722d362f84614fba",
                "e38da76dcd440621988d00bcf79af25d5b29c094db2a23146d003afd41943e7a"
            ),
            array(
                "af3c423a95d9f5b3054754efa150ac39cd29552fe360257362dfdecef4053b45",
                "f98a3fd831eb2b749a93b0e6f35cfb40c8cd5aa667a15581bc2feded498fd9c6"
            ),
            array(
                "766dbb24d134e745cccaa28c99bf274906bb66b26dcf98df8d2fed50d884249a",
                "744b1152eacbe5e38dcc887980da38b897584a65fa06cedd2c924f97cbac5996"
            ),
            array(
                "59dbf46f8c94759ba21277c33784f41645f7b44f6c596a58ce92e666191abe3e",
                "c534ad44175fbc300f4ea6ce648309a042ce739a7919798cd85e216c4a307f6e"
            ),
            array(
                "f13ada95103c4537305e691e74e9a4a8dd647e711a95e73cb62dc6018cfd87b8",
                "e13817b44ee14de663bf4bc808341f326949e21a6a75c2570778419bdaf5733d"
            ),
            array(
                "7754b4fa0e8aced06d4167a2c59cca4cda1869c06ebadfb6488550015a88522c",
                "30e93e864e669d82224b967c3020b8fa8d1e4e350b6cbcc537a48b57841163a2"
            ),
            array(
                "948dcadf5990e048aa3874d46abef9d701858f95de8041d2a6828c99e2262519",
                "e491a42537f6e597d5d28a3224b1bc25df9154efbd2ef1d2cbba2cae5347d57e"
            ),
            array(
                "7962414450c76c1689c7b48f8202ec37fb224cf5ac0bfa1570328a8a3d7c77ab",
                "100b610ec4ffb4760d5c1fc133ef6f6b12507a051f04ac5760afa5b29db83437"
            ),
            array(
                "3514087834964b54b15b160644d915485a16977225b8847bb0dd085137ec47ca",
                "ef0afbb2056205448e1652c48e8127fc6039e77c15c2378b7e7d15a0de293311"
            ),
            array(
                "d3cc30ad6b483e4bc79ce2c9dd8bc54993e947eb8df787b442943d3f7b527eaf",
                "8b378a22d827278d89c5e9be8f9508ae3c2ad46290358630afb34db04eede0a4"
            ),
            array(
                "1624d84780732860ce1c78fcbfefe08b2b29823db913f6493975ba0ff4847610",
                "68651cf9b6da903e0914448c6cd9d4ca896878f5282be4c8cc06e2a404078575"
            ),
            array(
                "733ce80da955a8a26902c95633e62a985192474b5af207da6df7b4fd5fc61cd4",
                "f5435a2bd2badf7d485a4d8b8db9fcce3e1ef8e0201e4578c54673bc1dc5ea1d"
            ),
            array(
                "15d9441254945064cf1a1c33bbd3b49f8966c5092171e699ef258dfab81c045c",
                "d56eb30b69463e7234f5137b73b84177434800bacebfc685fc37bbe9efe4070d"
            ),
            array(
                "a1d0fcf2ec9de675b612136e5ce70d271c21417c9d2b8aaaac138599d0717940",
                "edd77f50bcb5a3cab2e90737309667f2641462a54070f3d519212d39c197a629"
            ),
            array(
                "e22fbe15c0af8ccc5780c0735f84dbe9a790badee8245c06c7ca37331cb36980",
                "a855babad5cd60c88b430a69f53a1a7a38289154964799be43d06d77d31da06"
            ),
            array(
                "311091dd9860e8e20ee13473c1155f5f69635e394704eaa74009452246cfa9b3",
                "66db656f87d1f04fffd1f04788c06830871ec5a64feee685bd80f0b1286d8374"
            ),
            array(
                "34c1fd04d301be89b31c0442d3e6ac24883928b45a9340781867d4232ec2dbdf",
                "9414685e97b1b5954bd46f730174136d57f1ceeb487443dc5321857ba73abee"
            ),
            array(
                "f219ea5d6b54701c1c14de5b557eb42a8d13f3abbcd08affcc2a5e6b049b8d63",
                "4cb95957e83d40b0f73af4544cccf6b1f4b08d3c07b27fb8d8c2962a400766d1"
            ),
            array(
                "d7b8740f74a8fbaab1f683db8f45de26543a5490bca627087236912469a0b448",
                "fa77968128d9c92ee1010f337ad4717eff15db5ed3c049b3411e0315eaa4593b"
            ),
            array(
                "32d31c222f8f6f0ef86f7c98d3a3335ead5bcd32abdd94289fe4d3091aa824bf",
                "5f3032f5892156e39ccd3d7915b9e1da2e6dac9e6f26e961118d14b8462e1661"
            ),
            array(
                "7461f371914ab32671045a155d9831ea8793d77cd59592c4340f86cbc18347b5",
                "8ec0ba238b96bec0cbdddcae0aa442542eee1ff50c986ea6b39847b3cc092ff6"
            ),
            array(
                "ee079adb1df1860074356a25aa38206a6d716b2c3e67453d287698bad7b2b2d6",
                "8dc2412aafe3be5c4c5f37e0ecc5f9f6a446989af04c4e25ebaac479ec1c8c1e"
            ),
            array(
                "16ec93e447ec83f0467b18302ee620f7e65de331874c9dc72bfd8616ba9da6b5",
                "5e4631150e62fb40d0e8c2a7ca5804a39d58186a50e497139626778e25b0674d"
            ),
            array(
                "eaa5f980c245f6f038978290afa70b6bd8855897f98b6aa485b96065d537bd99",
                "f65f5d3e292c2e0819a528391c994624d784869d7e6ea67fb18041024edc07dc"
            ),
            array(
                "78c9407544ac132692ee1910a02439958ae04877151342ea96c4b6b35a49f51",
                "f3e0319169eb9b85d5404795539a5e68fa1fbd583c064d2462b675f194a3ddb4"
            ),
            array(
                "494f4be219a1a77016dcd838431aea0001cdc8ae7a6fc688726578d9702857a5",
                "42242a969283a5f339ba7f075e36ba2af925ce30d767ed6e55f4b031880d562c"
            ),
            array(
                "a598a8030da6d86c6bc7f2f5144ea549d28211ea58faa70ebf4c1e665c1fe9b5",
                "204b5d6f84822c307e4b4a7140737aec23fc63b65b35f86a10026dbd2d864e6b"
            ),
            array(
                "c41916365abb2b5d09192f5f2dbeafec208f020f12570a184dbadc3e58595997",
                "4f14351d0087efa49d245b328984989d5caf9450f34bfc0ed16e96b58fa9913"
            ),
            array(
                "841d6063a586fa475a724604da03bc5b92a2e0d2e0a36acfe4c73a5514742881",
                "73867f59c0659e81904f9a1c7543698e62562d6744c169ce7a36de01a8d6154"
            ),
            array(
                "5e95bb399a6971d376026947f89bde2f282b33810928be4ded112ac4d70e20d5",
                "39f23f366809085beebfc71181313775a99c9aed7d8ba38b161384c746012865"
            ),
            array(
                "36e4641a53948fd476c39f8a99fd974e5ec07564b5315d8bf99471bca0ef2f66",
                "d2424b1b1abe4eb8164227b085c9aa9456ea13493fd563e06fd51cf5694c78fc"
            ),
            array(
                "336581ea7bfbbb290c191a2f507a41cf5643842170e914faeab27c2c579f726",
                "ead12168595fe1be99252129b6e56b3391f7ab1410cd1e0ef3dcdcabd2fda224"
            ),
            array(
                "8ab89816dadfd6b6a1f2634fcf00ec8403781025ed6890c4849742706bd43ede",
                "6fdcef09f2f6d0a044e654aef624136f503d459c3e89845858a47a9129cdd24e"
            ),
            array(
                "1e33f1a746c9c5778133344d9299fcaa20b0938e8acff2544bb40284b8c5fb94",
                "60660257dd11b3aa9c8ed618d24edff2306d320f1d03010e33a7d2057f3b3b6"
            ),
            array(
                "85b7c1dcb3cec1b7ee7f30ded79dd20a0ed1f4cc18cbcfcfa410361fd8f08f31",
                "3d98a9cdd026dd43f39048f25a8847f4fcafad1895d7a633c6fed3c35e999511"
            ),
            array(
                "29df9fbd8d9e46509275f4b125d6d45d7fbe9a3b878a7af872a2800661ac5f51",
                "b4c4fe99c775a606e2d8862179139ffda61dc861c019e55cd2876eb2a27d84b"
            ),
            array(
                "a0b1cae06b0a847a3fea6e671aaf8adfdfe58ca2f768105c8082b2e449fce252",
                "ae434102edde0958ec4b19d917a6a28e6b72da1834aff0e650f049503a296cf2"
            ),
            array(
                "4e8ceafb9b3e9a136dc7ff67e840295b499dfb3b2133e4ba113f2e4c0e121e5",
                "cf2174118c8b6d7a4b48f6d534ce5c79422c086a63460502b827ce62a326683c"
            ),
            array(
                "d24a44e047e19b6f5afb81c7ca2f69080a5076689a010919f42725c2b789a33b",
                "6fb8d5591b466f8fc63db50f1c0f1c69013f996887b8244d2cdec417afea8fa3"
            ),
            array(
                "ea01606a7a6c9cdd249fdfcfacb99584001edd28abbab77b5104e98e8e3b35d4",
                "322af4908c7312b0cfbfe369f7a7b3cdb7d4494bc2823700cfd652188a3ea98d"
            ),
            array(
                "af8addbf2b661c8a6c6328655eb96651252007d8c5ea31be4ad196de8ce2131f",
                "6749e67c029b85f52a034eafd096836b2520818680e26ac8f3dfbcdb71749700"
            ),
            array(
                "e3ae1974566ca06cc516d47e0fb165a674a3dabcfca15e722f0e3450f45889",
                "2aeabe7e4531510116217f07bf4d07300de97e4874f81f533420a72eeb0bd6a4"
            ),
            array(
                "591ee355313d99721cf6993ffed1e3e301993ff3ed258802075ea8ced397e246",
                "b0ea558a113c30bea60fc4775460c7901ff0b053d25ca2bdeee98f1a4be5d196"
            ),
            array(
                "11396d55fda54c49f19aa97318d8da61fa8584e47b084945077cf03255b52984",
                "998c74a8cd45ac01289d5833a7beb4744ff536b01b257be4c5767bea93ea57a4"
            ),
            array(
                "3c5d2a1ba39c5a1790000738c9e0c40b8dcdfd5468754b6405540157e017aa7a",
                "b2284279995a34e2f9d4de7396fc18b80f9b8b9fdd270f6661f79ca4c81bd257"
            ),
            array(
                "cc8704b8a60a0defa3a99a7299f2e9c3fbc395afb04ac078425ef8a1793cc030",
                "bdd46039feed17881d1e0862db347f8cf395b74fc4bcdc4e940b74e3ac1f1b13"
            ),
            array(
                "c533e4f7ea8555aacd9777ac5cad29b97dd4defccc53ee7ea204119b2889b197",
                "6f0a256bc5efdf429a2fb6242f1a43a2d9b925bb4a4b3a26bb8e0f45eb596096"
            ),
            array(
                "c14f8f2ccb27d6f109f6d08d03cc96a69ba8c34eec07bbcf566d48e33da6593",
                "c359d6923bb398f7fd4473e16fe1c28475b740dd098075e6c0e8649113dc3a38"
            ),
            array(
                "a6cbc3046bc6a450bac24789fa17115a4c9739ed75f8f21ce441f72e0b90e6ef",
                "21ae7f4680e889bb130619e2c0f95a360ceb573c70603139862afd617fa9b9f"
            ),
            array(
                "347d6d9a02c48927ebfb86c1359b1caf130a3c0267d11ce6344b39f99d43cc38",
                "60ea7f61a353524d1c987f6ecec92f086d565ab687870cb12689ff1e31c74448"
            ),
            array(
                "da6545d2181db8d983f7dcb375ef5866d47c67b1bf31c8cf855ef7437b72656a",
                "49b96715ab6878a79e78f07ce5680c5d6673051b4935bd897fea824b77dc208a"
            ),
            array(
                "c40747cc9d012cb1a13b8148309c6de7ec25d6945d657146b9d5994b8feb1111",
                "5ca560753be2a12fc6de6caf2cb489565db936156b9514e1bb5e83037e0fa2d4"
            ),
            array(
                "4e42c8ec82c99798ccf3a610be870e78338c7f713348bd34c8203ef4037f3502",
                "7571d74ee5e0fb92a7a8b33a07783341a5492144cc54bcc40a94473693606437"
            ),
            array(
                "3775ab7089bc6af823aba2e1af70b236d251cadb0c86743287522a1b3b0dedea",
                "be52d107bcfa09d8bcb9736a828cfa7fac8db17bf7a76a2c42ad961409018cf7"
            ),
            array(
                "cee31cbf7e34ec379d94fb814d3d775ad954595d1314ba8846959e3e82f74e26",
                "8fd64a14c06b589c26b947ae2bcf6bfa0149ef0be14ed4d80f448a01c43b1c6d"
            ),
            array(
                "b4f9eaea09b6917619f6ea6a4eb5464efddb58fd45b1ebefcdc1a01d08b47986",
                "39e5c9925b5a54b07433a4f18c61726f8bb131c012ca542eb24a8ac07200682a"
            ),
            array(
                "d4263dfc3d2df923a0179a48966d30ce84e2515afc3dccc1b77907792ebcc60e",
                "62dfaf07a0f78feb30e30d6295853ce189e127760ad6cf7fae164e122a208d54"
            ),
            array(
                "48457524820fa65a4f8d35eb6930857c0032acc0a4a2de422233eeda897612c4",
                "25a748ab367979d98733c38a1fa1c2e7dc6cc07db2d60a9ae7a76aaa49bd0f77"
            ),
            array(
                "dfeeef1881101f2cb11644f3a2afdfc2045e19919152923f367a1767c11cceda",
                "ecfb7056cf1de042f9420bab396793c0c390bde74b4bbdff16a83ae09a9a7517"
            ),
            array(
                "6d7ef6b17543f8373c573f44e1f389835d89bcbc6062ced36c82df83b8fae859",
                "cd450ec335438986dfefa10c57fea9bcc521a0959b2d80bbf74b190dca712d10"
            ),
            array(
                "e75605d59102a5a2684500d3b991f2e3f3c88b93225547035af25af66e04541f",
                "f5c54754a8f71ee540b9b48728473e314f729ac5308b06938360990e2bfad125"
            ),
            array(
                "eb98660f4c4dfaa06a2be453d5020bc99a0c2e60abe388457dd43fefb1ed620c",
                "6cb9a8876d9cb8520609af3add26cd20a0a7cd8a9411131ce85f44100099223e"
            ),
            array(
                "13e87b027d8514d35939f2e6892b19922154596941888336dc3563e3b8dba942",
                "fef5a3c68059a6dec5d624114bf1e91aac2b9da568d6abeb2570d55646b8adf1"
            ),
            array(
                "ee163026e9fd6fe017c38f06a5be6fc125424b371ce2708e7bf4491691e5764a",
                "1acb250f255dd61c43d94ccc670d0f58f49ae3fa15b96623e5430da0ad6c62b2"
            ),
            array(
                "b268f5ef9ad51e4d78de3a750c2dc89b1e626d43505867999932e5db33af3d80",
                "5f310d4b3c99b9ebb19f77d41c1dee018cf0d34fd4191614003e945a1216e423"
            ),
            array(
                "ff07f3118a9df035e9fad85eb6c7bfe42b02f01ca99ceea3bf7ffdba93c4750d",
                "438136d603e858a3a5c440c38eccbaddc1d2942114e2eddd4740d098ced1f0d8"
            ),
            array(
                "8d8b9855c7c052a34146fd20ffb658bea4b9f69e0d825ebec16e8c3ce2b526a1",
                "cdb559eedc2d79f926baf44fb84ea4d44bcf50fee51d7ceb30e2e7f463036758"
            ),
            array(
                "52db0b5384dfbf05bfa9d472d7ae26dfe4b851ceca91b1eba54263180da32b63",
                "c3b997d050ee5d423ebaf66a6db9f57b3180c902875679de924b69d84a7b375"
            ),
            array(
                "e62f9490d3d51da6395efd24e80919cc7d0f29c3f3fa48c6fff543becbd43352",
                "6d89ad7ba4876b0b22c2ca280c682862f342c8591f1daf5170e07bfd9ccafa7d"
            ),
            array(
                "7f30ea2476b399b4957509c88f77d0191afa2ff5cb7b14fd6d8e7d65aaab1193",
                "ca5ef7d4b231c94c3b15389a5f6311e9daff7bb67b103e9880ef4bff637acaec"
            ),
            array(
                "5098ff1e1d9f14fb46a210fada6c903fef0fb7b4a1dd1d9ac60a0361800b7a00",
                "9731141d81fc8f8084d37c6e7542006b3ee1b40d60dfe5362a5b132fd17ddc0"
            ),
            array(
                "32b78c7de9ee512a72895be6b9cbefa6e2f3c4ccce445c96b9f2c81e2778ad58",
                "ee1849f513df71e32efc3896ee28260c73bb80547ae2275ba497237794c8753c"
            ),
            array(
                "e2cb74fddc8e9fbcd076eef2a7c72b0ce37d50f08269dfc074b581550547a4f7",
                "d3aa2ed71c9dd2247a62df062736eb0baddea9e36122d2be8641abcb005cc4a4"
            ),
            array(
                "8438447566d4d7bedadc299496ab357426009a35f235cb141be0d99cd10ae3a8",
                "c4e1020916980a4da5d01ac5e6ad330734ef0d7906631c4f2390426b2edd791f"
            ),
            array(
                "4162d488b89402039b584c6fc6c308870587d9c46f660b878ab65c82c711d67e",
                "67163e903236289f776f22c25fb8a3afc1732f2b84b4e95dbda47ae5a0852649"
            ),
            array(
                "3fad3fa84caf0f34f0f89bfd2dcf54fc175d767aec3e50684f3ba4a4bf5f683d",
                "cd1bc7cb6cc407bb2f0ca647c718a730cf71872e7d0d2a53fa20efcdfe61826"
            ),
            array(
                "674f2600a3007a00568c1a7ce05d0816c1fb84bf1370798f1c69532faeb1a86b",
                "299d21f9413f33b3edf43b257004580b70db57da0b182259e09eecc69e0d38a5"
            ),
            array(
                "d32f4da54ade74abb81b815ad1fb3b263d82d6c692714bcff87d29bd5ee9f08f",
                "f9429e738b8e53b968e99016c059707782e14f4535359d582fc416910b3eea87"
            ),
            array(
                "30e4e670435385556e593657135845d36fbb6931f72b08cb1ed954f1e3ce3ff6",
                "462f9bce619898638499350113bbc9b10a878d35da70740dc695a559eb88db7b"
            ),
            array(
                "be2062003c51cc3004682904330e4dee7f3dcd10b01e580bf1971b04d4cad297",
                "62188bc49d61e5428573d48a74e1c655b1c61090905682a0d5558ed72dccb9bc"
            ),
            array(
                "93144423ace3451ed29e0fb9ac2af211cb6e84a601df5993c419859fff5df04a",
                "7c10dfb164c3425f5c71a3f9d7992038f1065224f72bb9d1d902a6d13037b47c"
            ),
            array(
                "b015f8044f5fcbdcf21ca26d6c34fb8197829205c7b7d2a7cb66418c157b112c",
                "ab8c1e086d04e813744a655b2df8d5f83b3cdc6faa3088c1d3aea1454e3a1d5f"
            ),
            array(
                "d5e9e1da649d97d89e4868117a465a3a4f8a18de57a140d36b3f2af341a21b52",
                "4cb04437f391ed73111a13cc1d4dd0db1693465c2240480d8955e8592f27447a"
            ),
            array(
                "d3ae41047dd7ca065dbf8ed77b992439983005cd72e16d6f996a5316d36966bb",
                "bd1aeb21ad22ebb22a10f0303417c6d964f8cdd7df0aca614b10dc14d125ac46"
            ),
            array(
                "463e2763d885f958fc66cdd22800f0a487197d0a82e377b49f80af87c897b065",
                "bfefacdb0e5d0fd7df3a311a94de062b26b80c61fbc97508b79992671ef7ca7f"
            ),
            array(
                "7985fdfd127c0567c6f53ec1bb63ec3158e597c40bfe747c83cddfc910641917",
                "603c12daf3d9862ef2b25fe1de289aed24ed291e0ec6708703a5bd567f32ed03"
            ),
            array(
                "74a1ad6b5f76e39db2dd249410eac7f99e74c59cb83d2d0ed5ff1543da7703e9",
                "cc6157ef18c9c63cd6193d83631bbea0093e0968942e8c33d5737fd790e0db08"
            ),
            array(
                "30682a50703375f602d416664ba19b7fc9bab42c72747463a71d0896b22f6da3",
                "553e04f6b018b4fa6c8f39e7f311d3176290d0e0f19ca73f17714d9977a22ff8"
            ),
            array(
                "9e2158f0d7c0d5f26c3791efefa79597654e7a2b2464f52b1ee6c1347769ef57",
                "712fcdd1b9053f09003a3481fa7762e9ffd7c8ef35a38509e2fbf2629008373"
            ),
            array(
                "176e26989a43c9cfeba4029c202538c28172e566e3c4fce7322857f3be327d66",
                "ed8cc9d04b29eb877d270b4878dc43c19aefd31f4eee09ee7b47834c1fa4b1c3"
            ),
            array(
                "75d46efea3771e6e68abb89a13ad747ecf1892393dfc4f1b7004788c50374da8",
                "9852390a99507679fd0b86fd2b39a868d7efc22151346e1a3ca4726586a6bed8"
            ),
            array(
                "809a20c67d64900ffb698c4c825f6d5f2310fb0451c869345b7319f645605721",
                "9e994980d9917e22b76b061927fa04143d096ccc54963e6a5ebfa5f3f8e286c1"
            ),
            array(
                "1b38903a43f7f114ed4500b4eac7083fdefece1cf29c63528d563446f972c180",
                "4036edc931a60ae889353f77fd53de4a2708b26b6f5da72ad3394119daf408f9"
            )
        )
    )
);

Curves::defineCurve("secp256k1", array(
    "type" => "short",
    "prime" => "k256",
    "p" => "ffffffff ffffffff ffffffff ffffffff ffffffff ffffffff fffffffe fffffc2f",
    "a" => "0",
    "b" => "7",
    "n" => "ffffffff ffffffff ffffffff fffffffe baaedce6 af48a03b bfd25e8c d0364141",
    "h" => "1",
    "hash" => array(
        "outSize" => 256,
        "hmacStrength" => 192,
        "algo" => "sha256"
    ),

    // Precomputed endomorphism
    "beta" => "7ae96a2b657c07106e64479eac3434e99cf0497512f58995c1396c28719501ee",
    "lambda" => "5363ad4cc05c30e0a5261c028812645a122e22ea20816678df02967c1b23bd72",
    "basis" => array(
        array(
            "a" => "3086d221a7d46bcde86c90e49284eb15",
            "b" => "-e4437ed6010e88286f547fa90abfe4c3"
        ),
        array(
            "a" => "114ca50f7a8e2f3f657c1108d9d44cfd8",
            "b" => "3086d221a7d46bcde86c90e49284eb15"
        )
    ),

    "gRed" => false,
    "g" => array(
        "79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798",
        "483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8",
        $pre
    )
));

abstract class BaseCurve
{
    public $type;
    public $p;
    public $red;
    public $zero;
    public $one;
    public $two;
    public $n;
    public $g;
    protected $_wnafT1;
    protected $_wnafT2;
    protected $_wnafT3;
    protected $_wnafT4;
    public $redN;
    public $_maxwellTrick;

    function __construct($type, $conf)
    {
        $this->type = $type;
        $this->p = new BN($conf["p"], 16);

        //Use Montgomery, when there is no fast reduction for the prime
        $this->red = isset($conf["prime"]) ? BN::red($conf["prime"]) : BN::mont($this->p);

        //Useful for many curves
        $this->zero = (new BN(0))->toRed($this->red);
        $this->one = (new BN(1))->toRed($this->red);
        $this->two = (new BN(2))->toRed($this->red);

        //Curve configuration, optional
        $this->n = isset($conf["n"]) ? new BN($conf["n"], 16) : null;
        $this->g = isset($conf["g"]) ? $this->pointFromJSON($conf["g"], isset($conf["gRed"]) ? $conf["gRed"] : null) : null;

        //Temporary arrays
        $this->_wnafT1 = array(0,0,0,0);
        $this->_wnafT2 = array(0,0,0,0);
        $this->_wnafT3 = array(0,0,0,0);
        $this->_wnafT4 = array(0,0,0,0);

        //Generalized Greg Maxwell's trick
        $adjustCount = $this->n != null ? $this->p->div($this->n) : null;
        if( $adjustCount == null || $adjustCount->cmpn(100) > 0 )
        {
            $this->redN = null;
            $this->_maxwellTrick = false;
        }
        else
        {
            $this->redN = $this->n->toRed($this->red);
            $this->_maxwellTrick = true;
        }
    }

    abstract public function point($x, $z);
    abstract public function validate($point);
    abstract public function pointFromJSON($obj, $red=null);
    abstract public function jpoint($x, $y, $z, $t = null);
    abstract public function pointFromX($x, $odd);

    public function _fixedNafMul($p, $k)
    {
        assert(isset($p->precomputed));

        $doubles = $p->_getDoubles();
        $naf = EllipticPHPUtils::getNAF($k, 1);
        $I = (1 << ($doubles["step"] + 1)) - ($doubles["step"] % 2 == 0 ? 2 : 1);
        $I = $I / 3;

        //Translate to more windowed form
        $repr = array();
        for($j = 0; $j < count($naf); $j += $doubles["step"])
        {
            $nafW = 0;
            for($k = $j + $doubles["step"] - 1; $k >= $j; $k--)
                $nafW = ($nafW << 1) + (isset($naf[$k]) ? $naf[$k] : 0);
            array_push($repr, $nafW);
        }

        $a = $this->jpoint(null, null, null);
        $b = $this->jpoint(null, null, null);

        for($i = $I; $i > 0; $i--)
        {
            for($j = 0; $j < count($repr); $j++)
            {
                $nafW = $repr[$j];
                if ($nafW == $i) {
                    $b = $b->mixedAdd($doubles["points"][$j]);
                } else if($nafW == -$i) {
                    $b = $b->mixedAdd($doubles["points"][$j]->neg());
                }
            }
            $a = $a->add($b);
        }

        return $a->toP();
    }

    public function _wnafMul($p, $k)
    {
        $w = 4;

        //Precompute window
        $nafPoints = $p->_getNAFPoints($w);
        $w = $nafPoints["wnd"];
        $wnd = $nafPoints["points"];

        //Get NAF form
        $naf = EllipticPHPUtils::getNAF($k, $w);

        //Add `this`*(N+1) for every w-NAF index
        $acc = $this->jpoint(null, null, null);
        for($i = count($naf) - 1; $i >= 0; $i--)
        {
            //Count zeros
            for($k = 0; $i >= 0 && $naf[$i] == 0; $i--)
                $k++;

            if($i >= 0)
                $k++;
            $acc = $acc->dblp($k);

            if($i < 0)
                break;
            $z = $naf[$i];

            assert($z != 0);

            if( $p->type == "affine" )
            {
                //J +- P
                if( $z > 0 )
                    $acc = $acc->mixedAdd($wnd[($z - 1) >> 1]);
                else
                    $acc = $acc->mixedAdd($wnd[(-$z - 1) >> 1]->neg());
            }
            else
            {
                //J +- J
                if( $z > 0 )
                    $acc = $acc->add($wnd[($z - 1) >> 1]);
                else
                    $acc = $acc->add($wnd[(-$z - 1) >> 1]->neg());
            }
        }
        return $p->type == "affine" ? $acc->toP() : $acc;
    }

    public function _wnafMulAdd($defW, $points, $coeffs, $len, $jacobianResult = false)
    {
        $wndWidth = &$this->_wnafT1;
        $wnd = &$this->_wnafT2;
        $naf = &$this->_wnafT3;

        //Fill all arrays
        $max = 0;
        for($i = 0; $i < $len; $i++)
        {
            $p = $points[$i];
            $nafPoints = $p->_getNAFPoints($defW);
            $wndWidth[$i] = $nafPoints["wnd"];
            $wnd[$i] = $nafPoints["points"];
        }
        //Comb all window NAFs
        for($i = $len - 1; $i >= 1; $i -= 2)
        {
            $a = $i - 1;
            $b = $i;
            if( $wndWidth[$a] != 1 || $wndWidth[$b] != 1 )
            {
                $naf[$a] = EllipticPHPUtils::getNAF($coeffs[$a], $wndWidth[$a]);
                $naf[$b] = EllipticPHPUtils::getNAF($coeffs[$b], $wndWidth[$b]);
                $max = max(count($naf[$a]), $max);
                $max = max(count($naf[$b]), $max);
                continue;
            }

            $comb = array(
                $points[$a], /* 1 */
                null,        /* 3 */
                null,        /* 5 */
                $points[$b]  /* 7 */
            );

            //Try to avoid Projective points, if possible
            if( $points[$a]->y->cmp($points[$b]->y) == 0 )
            {
                $comb[1] = $points[$a]->add($points[$b]);
                $comb[2] = $points[$a]->toJ()->mixedAdd($points[$b]->neg());
            }
            elseif( $points[$a]->y->cmp($points[$b]->y->redNeg()) == 0 )
            {
                $comb[1] = $points[$a]->toJ()->mixedAdd($points[$b]);
                $comb[2] = $points[$a]->add($points[$b]->neg());
            }
            else
            {
                $comb[1] = $points[$a]->toJ()->mixedAdd($points[$b]);
                $comb[2] = $points[$a]->toJ()->mixedAdd($points[$b]->neg());
            }

            $index = array(
                -3, /* -1 -1 */
                -1, /* -1  0 */
                -5, /* -1  1 */
                -7, /*  0 -1 */
                0,  /*  0  0 */
                7,  /*  0  1 */
                5,  /*  1 -1 */
                1,  /*  1  0 */
                3   /*  1  1 */
            );

            $jsf = EllipticPHPUtils::getJSF($coeffs[$a], $coeffs[$b]);
            $max = max(count($jsf[0]), $max);
            if ($max > 0) {
                $naf[$a] = array_fill(0, $max, 0);
                $naf[$b] = array_fill(0, $max, 0);
            } else {
                $naf[$a] = [];
                $naf[$b] = [];
            }

            for($j = 0; $j < $max; $j++)
            {
                $ja = isset($jsf[0][$j]) ? $jsf[0][$j] : 0;
                $jb = isset($jsf[1][$j]) ? $jsf[1][$j] : 0;

                $naf[$a][$j] = $index[($ja + 1) * 3 + ($jb + 1)];
                $naf[$b][$j] = 0;
                $wnd[$a] = $comb;
            }
        }

        $acc = $this->jpoint(null, null, null);
        $tmp = &$this->_wnafT4;
        for($i = $max; $i >= 0; $i--)
        {
            $k = 0;

            while($i >= 0)
            {
                $zero = true;
                for($j = 0; $j < $len; $j++)
                {
                    $tmp[$j] = isset($naf[$j][$i]) ? $naf[$j][$i] : 0;
                    if( $tmp[$j] != 0 )
                        $zero = false;
                }
                if( !$zero )
                    break;
                $k++;
                $i--;
            }

            if( $i >=0 )
                $k++;

            $acc = $acc->dblp($k);
            if( $i < 0 )
                break;

            for($j = 0; $j < $len; $j++)
            {
                $z = $tmp[$j];
                $p = null;
                if( $z == 0 )
                    continue;
                elseif( $z > 0 )
                    $p = $wnd[$j][($z - 1) >> 1];
                elseif( $z < 0 )
                    $p = $wnd[$j][(-$z - 1) >> 1]->neg();

                if( $p->type == "affine" )
                    $acc = $acc->mixedAdd($p);
                else
                    $acc = $acc->add($p);
            }
        }

        //Zeroify references
        for($i = 0; $i < $len; $i++)
            $wnd[$i] = null;

        if( $jacobianResult )
            return $acc;
        else
            return $acc->toP();
    }

    public function decodePoint($bytes, $enc = false)
    {
        $bytes = EllipticPHPUtils::toArray($bytes, $enc);
        $len = $this->p->byteLength();

        $count = count($bytes);
        //uncompressed, hybrid-odd, hybrid-even
        if(($bytes[0] == 0x04 || $bytes[0] == 0x06 || $bytes[0] == 0x07) && ($count - 1) == (2 * $len) )
        {
            if( $bytes[0] == 0x06 )
                assert($bytes[$count - 1] % 2 == 0);
            elseif( $bytes[0] == 0x07 )
                assert($bytes[$count - 1] % 2 == 1);

            return $this->point(array_slice($bytes, 1, $len), array_slice($bytes, 1 + $len, $len));
        }

        if( ($bytes[0] == 0x02 || $bytes[0] == 0x03) && ($count - 1) == $len )
            return $this->pointFromX(array_slice($bytes, 1, $len), $bytes[0] == 0x03);

        throw new Exception("Unknown point format");
    }
}

class ShortCurve extends BaseCurve
{
    public $a;
    public $b;
    public $tinv;
    public $zeroA;
    public $threeA;
    public $endo;
    private $_endoWnafT1;
    private $_endoWnafT2;

    function __construct($conf)
    {
        parent::__construct("short", $conf);

        $this->a = (new BN($conf["a"], 16))->toRed($this->red);
        $this->b = (new BN($conf["b"], 16))->toRed($this->red);
        $this->tinv = $this->two->redInvm();

        $this->zeroA = $this->a->fromRed()->isZero();
        $this->threeA = $this->a->fromRed()->sub($this->p)->cmpn(-3) === 0;

        // If curve is endomorphic, precalculate beta and lambda
        $this->endo = $this->_getEndomorphism($conf);
        $this->_endoWnafT1 = array(0,0,0,0);
        $this->_endoWnafT2 = array(0,0,0,0);
    }

    private function _getEndomorphism($conf)
    {
        // No efficient endomorphism
        if( !$this->zeroA || !isset($this->g) || !isset($this->n) || $this->p->modn(3) != 1 )
            return null;

        // Compute beta and lambda, that lambda * P = (beta * Px; Py)
        $beta = null;
        $lambda = null;
        if( isset($conf["beta"]) )
            $beta = (new BN($conf["beta"], 16))->toRed($this->red);
        else
        {
            $betas = $this->_getEndoRoots($this->p);
            // Choose smallest beta
            $beta = $betas[0]->cmp($betas[1]) < 0 ? $betas[0] : $betas[1];
            $beta = $beta->toRed($this->red);
        }

        if( isset($conf["lambda"]) )
            $lambda = new BN($conf["lambda"], 16);
        else
        {
            // Choose the lambda that is matching selected beta
            $lambdas = $this->_getEndoRoots($this->n);
            if( $this->g->mul($lambdas[0])->x->cmp($this->g->x->redMul($beta)) == 0 )
                $lambda = $lambdas[0];
            else
            {
                $lambda = $lambdas[1];
                if (assert_options(ASSERT_ACTIVE)) {
                    assert($this->g->mul($lambda)->x->cmp($this->g->x->redMul($beta)) === 0);
                }
            }
        }

        // Get basis vectors, used for balanced length-two representation
        $basis = null;
        if( !isset($conf["basis"]) )
            $basis = $this->_getEndoBasis($lambda);
        else
        {
            $callback = function($vector) {
                return array(
                    "a" => new BN($vector["a"], 16),
                    "b" => new BN($vector["b"], 16)
                );
            };
            $basis = array_map($callback, $conf["basis"]);
        }

        return array(
            "beta" => $beta,
            "lambda" => $lambda,
            "basis" => $basis
        );
    }

    private function _getEndoRoots($num)
    {
        // Find roots of for x^2 + x + 1 in F
        // Root = (-1 +- Sqrt(-3)) / 2
        //
        $red = $num === $this->p ? $this->red : BN::mont($num);
        $tinv = (new BN(2))->toRed($red)->redInvm();
        $ntinv = $tinv->redNeg();

        $s = (new BN(3))->toRed($red)->redNeg()->redSqrt()->redMul($tinv);

        return array(
            $ntinv->redAdd($s)->fromRed(),
            $ntinv->redSub($s)->fromRed()
        );
    }

    private function _getEndoBasis($lambda)
    {
        // aprxSqrt >= sqrt(this.n)
        $aprxSqrt = $this->n->ushrn(intval($this->n->bitLength() / 2));

        // 3.74
        // Run EGCD, until r(L + 1) < aprxSqrt
        $u = $lambda;
        $v = $this->n->_clone();
        $x1 = new BN(1);
        $y1 = new BN(0);
        $x2 = new BN(0);
        $y2 = new BN(1);

        // NOTE: all vectors are roots of: a + b * lambda = 0 (mod n)
        $a0 = 0;
        $b0 = 0;
        // First vector
        $a1 = 0;
        $b1 = 0;
        // Second vector
        $a2 = 0;
        $b2 = 0;

        $prevR = 0;
        $i = 0;
        $r = 0;
        $x = 0;

        while( ! $u->isZero() )
        {
            $q = $v->div($u);
            $r = $v->sub($q->mul($u));
            $x = $x2->sub($q->mul($x1));
            $y = $y2->sub($q->mul($y2));

            if( !$a1 && $r->cmp($aprxSqrt) < 0 )
            {
                $a0 = is_integer($prevR) ? $prevR : $prevR->neg();
                $b0 = $x1;
                $a1 = $r->neg();
                $b1 = $x;
            }
            elseif($a1 && ++$i === 2)
                break;

            $prevR = $r;
            $v = $u;
            $u = $r;
            $x2 = $x1;
            $x1 = $x;
            $y2 = $y1;
            $y1 = $y;
        }
        $a2 = $r->neg();
        $b2 = $x;

        $len1 = $a1->sqr()->add($b1->sqr());
        $len2 = $a2->sqr()->add($b2->sqr());
        if( $len2->cmp($len1) >= 0 )
        {
            $a2 = $a0;
            $b2 = $b0;
        }

        // Normalize signs
        if( $a1->negative() )
        {
            $a1 = $a1->neg();
            $b1 = $b1->neg();
        }

        if( $a2->negative() )
        {
            $a2 = $a2->neg();
            $b2 = $b2->neg();
        }

        return array(
            array( "a" => $a1, "b" => $b1 ),
            array( "a" => $a2, "b" => $b2 ),
        );
    }

    public function _endoSplit($k)
    {
        $basis = $this->endo["basis"];
        $v1 = $basis[0];
        $v2 = $basis[1];

        $c1 = $v2["b"]->mul($k)->divRound($this->n);
        $c2 = $v1["b"]->neg()->mul($k)->divRound($this->n);

        $p1 = $c1->mul($v1["a"]);
        $p2 = $c2->mul($v2["a"]);
        $q1 = $c1->mul($v1["b"]);
        $q2 = $c2->mul($v2["b"]);

        //Calculate answer
        $k1 = $k->sub($p1)->sub($p2);
        $k2 = $q1->add($q2)->neg();

        return array( "k1" => $k1, "k2" => $k2 );
    }

    public function pointFromX($x, $odd)
    {
        $x = new BN($x, 16);
        if( !$x->red )
            $x = $x->toRed($this->red);

        $y2 = $x->redSqr()->redMul($x)->redIAdd($x->redMul($this->a))->redIAdd($this->b);
        $y = $y2->redSqrt();
        if( $y->redSqr()->redSub($y2)->cmp($this->zero) !== 0 )
            throw new Exception("Invalid point");

        // XXX Is there any way to tell if the number is odd without converting it
        // to non-red form?
        $isOdd = $y->fromRed()->isOdd();
        if( $odd != $isOdd )
            $y = $y->redNeg();

        return $this->point($x, $y);
    }

    public function validate($point)
    {
        if( $point->inf )
            return true;

        $x = $point->x;
        $y = $point->y;

        $ax = $this->a->redMul($x);
        $rhs = $x->redSqr()->redMul($x)->redIAdd($ax)->redIAdd($this->b);
        return $y->redSqr()->redISub($rhs)->isZero();
    }

    public function _endoWnafMulAdd($points, $coeffs, $jacobianResult = false)
    {
        $npoints = &$this->_endoWnafT1;
        $ncoeffs = &$this->_endoWnafT2;

        for($i = 0; $i < count($points); $i++)
        {
            $split = $this->_endoSplit($coeffs[$i]);
            $p = $points[$i];
            $beta = $p->_getBeta();

            if( $split["k1"]->negative() )
            {
                $split["k1"]->ineg();
                $p =  $p->neg(true);
            }

            if( $split["k2"]->negative() )
            {
                $split["k2"]->ineg();
                $beta = $beta->neg(true);
            }

            $npoints[$i * 2] = $p;
            $npoints[$i * 2 + 1] = $beta;
            $ncoeffs[$i * 2] = $split["k1"];
            $ncoeffs[$i * 2 + 1] = $split["k2"];
        }
        $res = $this->_wnafMulAdd(1, $npoints, $ncoeffs, $i * 2, $jacobianResult);

        // Clean-up references to points and coefficients
        for($j = 0; $j < 2 * $i; $j++)
        {
            $npoints[$j] = null;
            $ncoeffs[$j] = null;
        }

        return $res;
    }

    public function point($x, $y, $isRed = false) {
        return new ShortPoint($this, $x, $y, $isRed);
    }

    public function pointFromJSON($obj, $red=null) {
        return ShortPoint::fromJSON($this, $obj, $red);
    }

    public function jpoint($x, $y, $z, $t=null) {
        return new JPoint($this, $x, $y, $z);
    }
}

class PresetCurve
{
    public $curve;
    public $g;
    public $n;
    public $hash;

    function __construct($options)
    {
        if ( $options["type"] === "short" )
            $this->curve = new ShortCurve($options);
        elseif ( $options["type"] === "edwards" )
            $this->curve = new EdwardsCurve($options);
        else
            $this->curve = new MontCurve($options);

        $this->g = $this->curve->g;
        $this->n = $this->curve->n;
        $this->hash = isset($options["hash"]) ? $options["hash"] : null;
    }
}

class MontPoint extends Point
{
    public $x;
    public $y;
    public $z;

    function __construct($curve, $x, $z)
    {
        parent::__construct($curve, "projective");
        if( $x == null && $z == null )
        {
            $this->x = $this->curve->one;
            $this->z = $this->curve->zero;
        }
        else
        {
            $this->x = new BN($x, 16);
            $this->z = new BN($z, 16);
            if( !$this->x->red )
                $this->x = $this->x->toRed($this->curve->red);
            if( !$this->z->red )
                $this->z = $this->z->toRed($this->curve->red);
        }
    }

    public function precompute($power = null) {
        // No-op
    }

    protected function _encode($compact) {
        return $this->getX()->toArray("be", $this->curve->p->byteLength());
    }

    public static function fromJSON($curve, $obj) {
        return new MontPoint($curve, $obj[0], isset($obj[1]) ? $obj[1] : $curve->one);
    }

    public function inspect()
    {
        if( $this->isInfinity() )
            return "<EC Point Infinity>";
        return "<EC Point x: " . $this->x->fromRed()->toString(16, 2) .
            " z: " . $this->z->fromRed()->toString(16, 2) . ">";
    }

    public function isInfinity() {
        // XXX This code assumes that zero is always zero in red
        return $this->z->isZero();
    }

    public function dbl()
    {
        // http://hyperelliptic.org/EFD/g1p/auto-montgom-xz.html#doubling-dbl-1987-m-3
        // 2M + 2S + 4A

        // A = X1 + Z1
        $a = $this->x->redAdd($this->z);
        // AA = A^2
        $aa = $a->redSqr();
        // B = X1 - Z1
        $b = $this->x->redSub($this->z);
        // BB = B^2
        $bb = $b->redSqr();
        // C = AA - BB
        $c = $aa->redSub($bb);
        // X3 = AA * BB
        $nx = $aa->redMul($bb);
        // Z3 = C * (BB + A24 * C)
        $nz = $c->redMul( $bb->redAdd($this->curve->a24->redMul($c)) );
        return $this->curve->point($nx, $nz);
    }

    public function add($p) {
        throw new \Exception('Not supported on Montgomery curve');
    }

    public function diffAdd($p, $diff)
    {
        // http://hyperelliptic.org/EFD/g1p/auto-montgom-xz.html#diffadd-dadd-1987-m-3
        // 4M + 2S + 6A

        // A = X2 + Z2
        $a = $this->x->redAdd($this->z);
        // B = X2 - Z2
        $b = $this->x->redSub($this->z);
        // C = X3 + Z3
        $c = $p->x->redAdd($p->z);
        // D = X3 - Z3
        $d = $p->x->redSub($p->z);
        // DA = D * A
        $da = $d->redMul($a);
        // CB = C * B
        $cb = $c->redMul($b);
        // X5 = Z1 * (DA + CB)^2
        $nx = $diff->z->redMul($da->redAdd($cb)->redSqr());
        // Z5 = X1 * (DA - CB)^2
        $nz = $diff->x->redMul($da->redSub($cb)->redSqr());

        return $this->curve->point($nx, $nz);
    }

    public function mul($k)
    {
        $t = $k->_clone();
        $a = $this; // (N / 2) * Q + Q
        $b = $this->curve->point(null, null); // (N / 2) * Q
        $c = $this; // Q

        $bits = array();
        while( !$t->isZero() )
        {
            // TODO: Maybe it is faster to use toString(2)?
            array_push($bits, $t->andln(1));
            $t->iushrn(1);
        }

        for($i = count($bits) - 1; $i >= 0; $i--)
        {
            if( $bits[$i] === 0 )
            {
                // N * Q + Q = ((N / 2) * Q + Q)) + (N / 2) * Q
                $a = $a->diffAdd($b, $c);
                // N * Q = 2 * ((N / 2) * Q + Q))
                $b = $b->dbl();
            }
            else
            {
                // N * Q = ((N / 2) * Q + Q) + ((N / 2) * Q)
                $b = $a->diffAdd($b, $c);
                // N * Q + Q = 2 * ((N / 2) * Q + Q)
                $a = $a->dbl();
            }
        }

        return $b;
    }

    public function eq($other) {
        return $this->getX()->cmp($other->getX()) === 0;
    }

    public function normalize()
    {
        $this->x = $this->x->redMul($this->z->redInvm());
        $this->z = $this->curve->one;
        return $this;
    }

    public function getX() {
        $this->normalize();
        return $this->x->fromRed();
    }

    public function getY() {
        $this->normalize();
        return $this->y->fromRed();
    }
}

class MontCurve extends BaseCurve
{
    public $a;
    public $b;
    public $i4;
    public $a24;

    function __construct($conf)
    {
        parent::__construct("mont", $conf);

        $this->a = (new BN($conf["a"], 16))->toRed($this->red);
        $this->b = (new BN($conf["b"], 16))->toRed($this->red);
        $this->i4 = (new BN(4))->toRed($this->red)->redInvm();
        $this->a24 = $this->i4->redMul($this->a->redAdd($this->two));
    }

    public function validate($point)
    {
        $x = $point->normalize()->x;
        $x2 = $x->redSqr();
        $rhs = $x2->redMul($x)->redAdd($x2->redMul($this->a))->redAdd($x);
        $y = $rhs->redSqr();

        return $y->redSqr()->cmp($rhs) ===0;
    }

    public function decodePoint($bytes, $enc = false) {
        return $this->point(EllipticPHPUtils::toArray($bytes, $enc), 1);
    }

    public function point($x, $z) {
        return new MontPoint($this, $x, $z);
    }

    public function pointFromJSON($obj, $red=null) {
        return MontPoint::fromJSON($this, $obj);
    }

    public function pointFromX($x, $odd)
    {
        $x = new BN($x, 16);
        if( !$x->red )
            $x = $x->toRed($this->red);

        $y2 = $x->redSqr()->redMul($x)->redIAdd($x->redMul($this->a))->redIAdd($this->b);
        $y = $y2->redSqrt();
        if( $y->redSqr()->redSub($y2)->cmp($this->zero) !== 0 )
            throw new Exception("Invalid point");

        // XXX Is there any way to tell if the number is odd without converting it
        // to non-red form?
        $isOdd = $y->fromRed()->isOdd();
        if( $odd != $isOdd )
            $y = $y->redNeg();

        return $this->point($x, $y);
    }

    public function jpoint($x, $y, $z, $t=null) {
        return new JPoint($x, $y, $z, $t);
    }
}


class EdwardsPoint extends Point
{
    public $x;
    public $y;
    public $z;
    public $t;
    public $zOne;

    function __construct($curve, $x = null, $y = null, $z = null, $t = null) {
        parent::__construct($curve, 'projective');
        if ($x == null && $y == null && $z == null) {
            $this->x = $this->curve->zero;
            $this->y = $this->curve->one;
            $this->z = $this->curve->one;
            $this->t = $this->curve->zero;
            $this->zOne = true;
        } else {
            $this->x = new BN($x, 16);
            $this->y = new BN($y, 16);
            $this->z = $z ? new BN($z, 16) : $this->curve->one;
            $this->t = $t ? new BN($t, 16) : null;
            if (!$this->x->red)
                $this->x = $this->x->toRed($this->curve->red);
            if (!$this->y->red)
                $this->y = $this->y->toRed($this->curve->red);
            if (!$this->z->red)
                $this->z = $this->z->toRed($this->curve->red);
            if ($this->t && !$this->t->red)
                $this->t = $this->t->toRed($this->curve->red);
            $this->zOne = $this->z == $this->curve->one;

            // Use extended coordinates
            if ($this->curve->extended && !$this->t) {
                $this->t = $this->x->redMul($this->y);
                if (!$this->zOne)
                    $this->t = $this->t->redMul($this->z->redInvm());
            }
        }
    }

    public static function fromJSON($curve, $obj) {
        return new EdwardsPoint($curve,
            isset($obj[0]) ? $obj[0] : null,
            isset($obj[1]) ? $obj[1] : null,
            isset($obj[2]) ? $obj[2] : null
        );
    }

    public function inspect() {
        if ($this->isInfinity())
            return '<EC Point Infinity>';
        return '<EC Point x: ' . $this->x->fromRed()->toString(16, 2) .
            ' y: ' . $this->y->fromRed()->toString(16, 2) .
            ' z: ' . $this->z->fromRed()->toString(16, 2) . '>';
    }

    public function isInfinity() {
        // XXX This code assumes that zero is always zero in red
        return $this->x->cmpn(0) == 0 &&
            $this->y->cmp($this->z) == 0;
    }

    public function _extDbl() {
        // hyperelliptic.org/EFD/g1p/auto-twisted-extended-1.html
        //     #doubling-dbl-2008-hwcd
        // 4M + 4S

        // A = X1^2
        $a = $this->x->redSqr();
        // B = Y1^2
        $b = $this->y->redSqr();
        // C = 2 * Z1^2
        $c = $this->z->redSqr();
        $c = $c->redIAdd($c);
        // D = a * A
        $d = $this->curve->_mulA($a);
        // E = (X1 + Y1)^2 - A - B
        $e = $this->x->redAdd($this->y)->redSqr()->redISub($a)->redISub($b);
        // G = D + B
        $g = $d->redAdd($b);
        // F = G - C
        $f = $g->redSub($c);
        // H = D - B
        $h = $d->redSub($b);
        // X3 = E * F
        $nx = $e->redMul($f);
        // Y3 = G * H
        $ny = $g->redMul($h);
        // T3 = E * H
        $nt = $e->redMul($h);
        // Z3 = F * G
        $nz = $f->redMul($g);
        return $this->curve->point($nx, $ny, $nz, $nt);
    }

    public function _projDbl() {
        // hyperelliptic.org/EFD/g1p/auto-twisted-projective.html
        //     #doubling-dbl-2008-bbjlp
        //     #doubling-dbl-2007-bl
        // and others
        // Generally 3M + 4S or 2M + 4S

        // B = (X1 + Y1)^2
        $b = $this->x->redAdd($this->y)->redSqr();
        // C = X1^2
        $c = $this->x->redSqr();
        // D = Y1^2
        $d = $this->y->redSqr();

        if ($this->curve->twisted) {
            // E = a * C
            $e = $this->curve->_mulA($c);
            // F = E + D
            $f = $e->redAdd($d);
            if ($this->zOne) {
                // X3 = (B - C - D) * (F - 2)
                $nx = $b->redSub($c)->redSub($d)->redMul($f->redSub($this->curve->two));
                // Y3 = F * (E - D)
                $ny = $f->redMul($e->redSub($d));
                // Z3 = F^2 - 2 * F
                $nz = $f->redSqr()->redSub($f)->redSub($f);
            } else {
                // H = Z1^2
                $h = $this->z->redSqr();
                // J = F - 2 * H
                $j = $f->redSub($h)->redISub($h);
                // X3 = (B-C-D)*J
                $nx = $b->redSub($c)->redISub($d)->redMul($j);
                // Y3 = F * (E - D)
                $ny = $f->redMul($e->redSub($d));
                // Z3 = F * J
                $nz = $f->redMul($j);
            }
        } else {
            // E = C + D
            $e = $c->redAdd($d);
            // H = (c * Z1)^2
            $h = $this->curve->_mulC($this->c->redMul($this->z))->redSqr();
            // J = E - 2 * H
            $j = $e->redSub($h)->redSub($h);
            // X3 = c * (B - E) * J
            $nx = $this->curve->_mulC($b->redISub($e))->redMul($j);
            // Y3 = c * E * (C - D)
            $ny = $this->curve->_mulC($e)->redMul($c->redISub($d));
            // Z3 = E * J
            $nz = $e->redMul($j);
        }
        return $this->curve->point($nx, $ny, $nz);
    }

    public function dbl() {
        if ($this->isInfinity())
            return $this;

        // Double in extended coordinates
        if ($this->curve->extended)
            return $this->_extDbl();
        else
            return $this->_projDbl();
    }

    public function _extAdd($p) {
        // hyperelliptic.org/EFD/g1p/auto-twisted-extended-1.html
        //     #addition-add-2008-hwcd-3
        // 8M

        // A = (Y1 - X1) * (Y2 - X2)
        $a = $this->y->redSub($this->x)->redMul($p->y->redSub($p->x));
        // B = (Y1 + X1) * (Y2 + X2)
        $b = $this->y->redAdd($this->x)->redMul($p->y->redAdd($p->x));
        // C = T1 * k * T2
        $c = $this->t->redMul($this->curve->dd)->redMul($p->t);
        // D = Z1 * 2 * Z2
        $d = $this->z->redMul($p->z->redAdd($p->z));
        // E = B - A
        $e = $b->redSub($a);
        // F = D - C
        $f = $d->redSub($c);
        // G = D + C
        $g = $d->redAdd($c);
        // H = B + A
        $h = $b->redAdd($a);
        // X3 = E * F
        $nx = $e->redMul($f);
        // Y3 = G * H
        $ny = $g->redMul($h);
        // T3 = E * H
        $nt = $e->redMul($h);
        // Z3 = F * G
        $nz = $f->redMul($g);
        return $this->curve->point($nx, $ny, $nz, $nt);
    }

    public function _projAdd($p) {
        // hyperelliptic.org/EFD/g1p/auto-twisted-projective.html
        //     #addition-add-2008-bbjlp
        //     #addition-add-2007-bl
        // 10M + 1S

        // A = Z1 * Z2
        $a = $this->z->redMul($p->z);
        // B = A^2
        $b = $a->redSqr();
        // C = X1 * X2
        $c = $this->x->redMul($p->x);
        // D = Y1 * Y2
        $d = $this->y->redMul($p->y);
        // E = d * C * D
        $e = $this->curve->d->redMul($c)->redMul($d);
        // F = B - E
        $f = $b->redSub($e);
        // G = B + E
        $g = $b->redAdd($e);
        // X3 = A * F * ((X1 + Y1) * (X2 + Y2) - C - D)
        $tmp = $this->x->redAdd($this->y)->redMul($p->x->redAdd($p->y))->redISub($c)->redISub($d);
        $nx = $a->redMul($f)->redMul($tmp);
        if ($this->curve->twisted) {
            // Y3 = A * G * (D - a * C)
            $ny = $a->redMul($g)->redMul($d->redSub($this->curve->_mulA($c)));
            // Z3 = F * G
            $nz = $f->redMul($g);
        } else {
            // Y3 = A * G * (D - C)
            $ny = $a->redMul($g)->redMul($d->redSub($c));
            // Z3 = c * F * G
            $nz = $this->curve->_mulC($f)->redMul($g);
        }
        return $this->curve->point($nx, $ny, $nz);
    }

    public function add($p) {
        if ($this->isInfinity())
            return $p;
        if ($p->isInfinity())
            return $this;

        if ($this->curve->extended)
            return $this->_extAdd($p);
        else
            return $this->_projAdd($p);
    }

    public function mul($k) {
        if ($this->_hasDoubles($k))
            return $this->curve->_fixedNafMul($this, $k);
        else
            return $this->curve->_wnafMul($this, $k);
    }

    public function mulAdd($k1, $p, $k2) {
        return $this->curve->_wnafMulAdd(1, [ $this, $p ], [ $k1, $k2 ], 2, false);
    }

    public function jmulAdd($k1, $p, $k2) {
        return $this->curve->_wnafMulAdd(1, [ $this, $p ], [ $k1, $k2 ], 2, true);
    }

    public function normalize() {
        if ($this->zOne)
            return $this;

        // Normalize coordinates
        $zi = $this->z->redInvm();
        $this->x = $this->x->redMul($zi);
        $this->y = $this->y->redMul($zi);
        if ($this->t)
            $this->t = $this->t->redMul($zi);
        $this->z = $this->curve->one;
        $this->zOne = true;
        return $this;
    }

    public function neg() {
        return $this->curve->point($this->x->redNeg(),
            $this->y,
            $this->z,
            ($this->t != null) ? $this->t->redNeg() : null);
    }

    public function getX() {
        $this->normalize();
        return $this->x->fromRed();
    }

    public function getY() {
        $this->normalize();
        return $this->y->fromRed();
    }

    public function eq($other) {
        return $this == $other ||
            $this->getX()->cmp($other->getX()) == 0 &&
            $this->getY()->cmp($other->getY()) == 0;
    }

    public function eqXToP($x) {
        $rx = $x->toRed($this->curve->red)->redMul($this->z);
        if ($this->x->cmp($rx) == 0)
            return true;

        $xc = $x->_clone();
        $t = $this->curve->redN->redMul($this->z);
        for (;;) {
            $xc->iadd($this->curve->n);
            if ($xc->cmp($this->curve->p) >= 0)
                return false;

            $rx->redIAdd($t);
            if ($this->x->cmp($rx) == 0)
                return true;
        }
        return false;
    }

    // Compatibility with BaseCurve
    public function toP() { return $this->normalize(); }
    public function mixedAdd($p) { return $this->add($p); }
}

class EdwardsCurve extends BaseCurve
{
    public $twisted;
    public $mOneA;
    public $extended;
    public $a;
    public $c;
    public $c2;
    public $d;
    public $d2;
    public $dd;
    public $oneC;

    function __construct($conf)
    {
        // NOTE: Important as we are creating point in Base.call()
        $this->twisted = ($conf["a"] | 0) != 1;
        $this->mOneA = $this->twisted && ($conf["a"] | 0) == -1;
        $this->extended = $this->mOneA;
        parent::__construct("edward", $conf);

        $this->a = (new BN($conf["a"], 16))->umod($this->red->m);
        $this->a = $this->a->toRed($this->red);
        $this->c = (new BN($conf["c"], 16))->toRed($this->red);
        $this->c2 = $this->c->redSqr();
        $this->d = (new BN($conf["d"], 16))->toRed($this->red);
        $this->dd = $this->d->redAdd($this->d);
        if (assert_options(ASSERT_ACTIVE)) {
            assert(!$this->twisted || $this->c->fromRed()->cmpn(1) == 0);
        }
        $this->oneC = ($conf["c"] | 0) == 1;
    }

    public function _mulA($num) {
        if ($this->mOneA)
            return $num->redNeg();
        else
            return $this->a->redMul($num);
    }

    public function _mulC($num) {
        if ($this->oneC)
            return $num;
        else
            return $this->c->redMul($num);
    }

    // Just for compatibility with Short curve
    public function jpoint($x, $y, $z, $t = null) {
        return $this->point($x, $y, $z, $t);
    }

    public function pointFromX($x, $odd = false) {
        $x = new BN($x, 16);
        if (!$x->red)
            $x = $x->toRed($this->red);

        $x2 = $x->redSqr();
        $rhs = $this->c2->redSub($this->a->redMul($x2));
        $lhs = $this->one->redSub($this->c2->redMul($this->d)->redMul($x2));

        $y2 = $rhs->redMul($lhs->redInvm());
        $y = $y2->redSqrt();
        if ($y->redSqr()->redSub($y2)->cmp($this->zero) != 0)
            throw new \Exception('invalid point');

        $isOdd = $y->fromRed()->isOdd();
        if ($odd && !$isOdd || !$odd && $isOdd)
            $y = $y->redNeg();

        return $this->point($x, $y);
    }

    public function pointFromY($y, $odd = false) {
        $y = new BN($y, 16);
        if (!$y->red)
            $y = $y->toRed($this->red);

        // x^2 = (y^2 - 1) / (d y^2 + 1)
        $y2 = $y->redSqr();
        $lhs = $y2->redSub($this->one);
        $rhs = $y2->redMul($this->d)->redAdd($this->one);
        $x2 = $lhs->redMul($rhs->redInvm());

        if ($x2->cmp($this->zero) == 0) {
            if ($odd)
                throw new \Exception('invalid point');
            else
                return $this->point($this->zero, $y);
        }

        $x = $x2->redSqrt();
        if ($x->redSqr()->redSub($x2)->cmp($this->zero) != 0)
            throw new \Exception('invalid point');

        if ($x->isOdd() != $odd)
            $x = $x->redNeg();

        return $this->point($x, $y);
    }

    public function validate($point) {
        if ($point->isInfinity())
            return true;

        // Curve: A * X^2 + Y^2 = C^2 * (1 + D * X^2 * Y^2)
        $point->normalize();

        $x2 = $point->x->redSqr();
        $y2 = $point->y->redSqr();
        $lhs = $x2->redMul($this->a)->redAdd($y2);
        $rhs = $this->c2->redMul($this->one->redAdd($this->d->redMul($x2)->redMul($y2)));

        return $lhs->cmp($rhs) == 0;
    }

    public function pointFromJSON($obj, $red=null) {
        return EdwardsPoint::fromJSON($this, $obj);
    }

    public function point($x = null, $y = null, $z = null, $t = null) {
        return new EdwardsPoint($this, $x, $y, $z, $t);
    }
}
