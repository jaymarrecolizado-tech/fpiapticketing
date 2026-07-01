<?php
/**
 * Input Sanitizer Class
 * Handles:
 * - Unicode normalization
 * - Control character removal
 * - LIKE query escaping
 * - Context-aware output escaping
 * - Safe filename generation
 * 
 * Usage:
 *   $safe = Sanitizer::normalize($userInput);
 *   $safe = Sanitizer::escapeLike($searchTerm);  // For LIKE queries
 *   echo Sanitizer::escapeHtml($text);            // For HTML output
 */

class Sanitizer {
    
    /**
     * Normalize and clean input
     * - Unicode NFC normalization
     * - Remove control characters
     * - Trim whitespace
     */
    public static function normalize($str, $removeControls = true) {
        if (!is_string($str)) {
            $str = (string)$str;
        }
        
        // Unicode normalization (NFC - Canonical Decomposition, followed by Canonical Composition)
        if (function_exists('normalizer_normalize')) {
            $str = normalizer_normalize($str, Normalizer::FORM_C);
        }
        
        // Remove control characters (C0 controls: 0x00-0x1F, DEL: 0x7F, C1 controls: 0x80-0x9F)
        if ($removeControls) {
            $str = preg_replace('/[\x00-\x1F\x7F\x80-\x9F]/u', '', $str);
        }
        
        // Trim whitespace
        $str = trim($str);
        
        return $str;
    }

    /**
     * Escape LIKE query wildcards (%, _, \)
     * Must be used with prepared statements!
     * 
     * Example:
     *   $term = Sanitizer::escapeLike($_POST['search']);
     *   $stmt->execute(["%{$term}%"]);  // Safe for LIKE
     */
    public static function escapeLike($str) {
        $str = self::normalize($str);
        // Escape: backslash, percent sign, underscore
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $str);
    }

    /**
     * Escape for HTML body content
     * Converts: <, >, &, ", ', etc.
     */
    public static function escapeHtml($str) {
        $str = self::normalize($str);
        return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape for HTML attribute
     * Same as escapeHtml (ENT_QUOTES handles both)
     */
    public static function escapeHtmlAttr($str) {
        return self::escapeHtml($str);
    }

    /**
     * Escape for JavaScript (inline in <script> or event handlers)
     * Use json_encode for safer approach
     */
    public static function escapeJs($str) {
        $str = self::normalize($str);
        // Using json_encode is safer than manual escaping
        return json_encode($str, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Escape for URL parameter
     */
    public static function escapeUrl($str) {
        $str = self::normalize($str);
        return rawurlencode($str);
    }

    /**
     * Escape for CSV field
     * Prevents formula injection by prepending single quote if needed
     */
    public static function escapeCsv($str) {
        $str = self::normalize($str);
        
        // If starts with formula characters, prepend single quote
        if (preg_match('/^[=+\-@]/', $str)) {
            $str = "'" . $str;
        }
        
        // Escape double quotes by doubling them
        $str = str_replace('"', '""', $str);
        
        return $str;
    }

    /**
     * Safe filename from user input
     * Removes/replaces dangerous characters
     */
    public static function filename($filename) {
        $filename = self::normalize($filename);
        
        // Remove path traversal attempts
        $filename = str_replace(['..', '/', '\\', ':'], '', $filename);
        
        // Replace special characters with underscore
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        
        // Remove leading/trailing dots and spaces
        $filename = trim($filename, '. ');
        
        // Limit length
        $filename = substr($filename, 0, 255);
        
        return $filename ?: 'file';
    }

    /**
     * Truncate string to max length while preserving words
     * Useful for display purposes
     */
    public static function truncate($str, $max = 100, $suffix = '...') {
        $str = self::normalize($str);
        
        if (strlen($str) <= $max) {
            return $str;
        }
        
        // Truncate and find last space to preserve word boundaries
        $truncated = substr($str, 0, $max);
        $lastSpace = strrpos($truncated, ' ');
        
        if ($lastSpace !== false) {
            $truncated = substr($truncated, 0, $lastSpace);
        }
        
        return rtrim($truncated) . $suffix;
    }

    /**
     * Remove or collapse multiple spaces
     */
    public static function collapseWhitespace($str) {
        $str = self::normalize($str);
        return preg_replace('/\s+/', ' ', $str);
    }

    /**
     * Convert to uppercase (for case-insensitive comparisons in DB)
     * While preserving Unicode characters
     */
    public static function toUpper($str) {
        $str = self::normalize($str);
        
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($str, 'UTF-8');
        }
        
        return strtoupper($str);
    }

   

    /**
     * Sanitize remarks/textarea (allow newlines)
     */
    public static function remarks($str, $maxLen = 2000) {
        $str = self::normalize($str, false); // Keep line breaks
        
        // Remove only dangerous control chars, preserve newlines
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);
        
        // Limit length
        $str = substr($str, 0, $maxLen);
        
        return $str;
    }

    /**
     * Log message safely (remove sensitive data patterns)
     * Remove passwords, tokens, email addresses
     */
    public static function logSafe($str) {
        $str = self::normalize($str);
        
        // Remove email-like patterns
        $str = preg_replace('/[\w\.-]+@[\w\.-]+\.\w+/', '[EMAIL]', $str);
        
        // Remove common password/key patterns
        $str = preg_replace('/(password|token|key|secret|authorization)[\s:=\'"]+([\w\-\.]+)/i', '$1=[REDACTED]', $str);
        
        return $str;
    }

    /**
     * Array sanitizer - apply sanitizer function to all values
     */
    public static function array($arr, $callable = 'self::normalize') {
        if (!is_array($arr)) {
            return [];
        }
        
        $result = [];
        foreach ($arr as $key => $value) {
            if (is_string($value)) {
                // Only sanitize string values
                if (strpos($callable, '::') === false) {
                    $result[$key] = call_user_func($callable, $value);
                } else {
                    $parts = explode('::', $callable);
                    $result[$key] = call_user_func([self::class, $parts[1]], $value);
                }
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }
}
?>
