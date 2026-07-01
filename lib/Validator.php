<?php
/**
 * Input Validator Class
 * Provides whitelist-based validation for common data types
 * 
 * Usage:
 *   if (!Validator::email($email)) { die("Invalid email"); }
 *   if (!Validator::positiveInteger($id)) { die("Invalid ID"); }
 */

class Validator {
    private static $errors = [];
    private static $maxLengths = [
        'name' => 150,
        'email' => 254,
        'subject' => 255,
        'remarks' => 2000,
        'generic' => 255,
    ];

    /**
     * Validate email address
     */
    public static function email($email, $maxLen = 254) {
        $email = trim($email ?? '');
        if (empty($email) || strlen($email) > $maxLen) {
            return false;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate string with length and character restrictions
     */
    public static function string($str, $minLen = 1, $maxLen = 255, $pattern = null) {
        $str = trim($str ?? '');
        $len = strlen($str);
        
        // Length check
        if ($len < $minLen || $len > $maxLen) {
            return false;
        }
        
        // Pattern check (if provided)
        if ($pattern && preg_match($pattern, $str) === 0) {
            return false;
        }
        
        return true;
    }

    /**
     * Validate name (letters, spaces, hyphens, apostrophes, accents)
     */
    public static function name($name, $maxLen = 150) {
        $name = trim($name ?? '');
        
        if (strlen($name) < 2 || strlen($name) > $maxLen) {
            return false;
        }
        
        // Allow: letters (with accents), spaces, hyphens, apostrophes, periods
        // Pattern: \p{L} = Unicode letters, \p{M} = Unicode marks (accents)
        return preg_match('/^[\p{L}\p{M}\s.\'-]{2,}$/u', $name) === 1;
    }

    /**
     * Validate integer
     */
    public static function integer($int, $min = PHP_INT_MIN, $max = PHP_INT_MAX) {
        $int = filter_var($int, FILTER_VALIDATE_INT);
        return ($int !== false) && ($int >= $min) && ($int <= $max);
    }

    /**
     * Validate positive integer (ID, count, etc.)
     */
    public static function positiveInteger($int) {
        return self::integer($int, 1, PHP_INT_MAX);
    }

    /**
     * Validate datetime-local input (from HTML5 input type="datetime-local")
     * Format: YYYY-MM-DDTHH:MM (no seconds)
     */
    public static function datetimeLocal($dt) {
        // Remove 'T' separator and convert to datetime
        $formatted = str_replace('T', ' ', $dt ?? '');
        // Manual datetime validation since we removed datetime() method
        $d = \DateTime::createFromFormat('Y-m-d H:i', $formatted);
        return $d && $d->format('Y-m-d H:i') === $formatted;
    }

    /**
     * Validate that value is from whitelist
     */
    public static function inList($value, array $whitelist) {
        return in_array($value, $whitelist, true);
    }

    /**
     * Validate array of integers (for performer IDs, etc.)
     */
    public static function integerArray($arr, $minElements = 0, $maxElements = 1000) {
        if (!is_array($arr)) {
            return false;
        }
        
        $count = count($arr);
        if ($count < $minElements || $count > $maxElements) {
            return false;
        }
        
        foreach ($arr as $item) {
            if (!self::integer($item, 1)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Add validation error
     */
    public static function addError($field, $message) {
        self::$errors[$field] = $message;
    }

    /**
     * Get all validation errors
     */
    public static function getErrors() {
        return self::$errors;
    }

    /**
     * Check if any errors exist
     */
    public static function hasErrors() {
        return !empty(self::$errors);
    }

    /**
     * Clear all errors
     */
    public static function clearErrors() {
        self::$errors = [];
    }

    /**
     * Get max allowed length for field type
     */
    public static function getMaxLength($fieldType) {
        return self::$maxLengths[$fieldType] ?? self::$maxLengths['generic'];
    }

    /**
     * Validate subject (ticket subject, title, etc.)
     * Min 5 chars, max 255
     */
    public static function subject($subject, $minLen = 5, $maxLen = 255) {
        $subject = trim($subject ?? '');
        $len = strlen($subject);
        
        if ($len < $minLen || $len > $maxLen) {
            return false;
        }
        
        // Disallow only control characters, allow everything else
        return preg_match('/^[^\x00-\x1F\x7F]*$/', $subject) === 1;
    }

    /**
     * Validate remarks/notes (textarea field)
     * Min 1 char, max 2000, allows newlines
     */
    public static function remarks($remarks, $minLen = 1, $maxLen = 2000) {
        $remarks = trim($remarks ?? '');
        $len = strlen($remarks);
        
        if ($len < $minLen || $len > $maxLen) {
            return false;
        }
        
        // For remarks, we allow newlines (\n, \r) but not control characters
        return preg_match('/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/', $remarks) === 1;
    }
}
?>
