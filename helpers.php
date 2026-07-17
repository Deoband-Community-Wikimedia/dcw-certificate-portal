<?php
/**
 * Shared helper functions for the DCW Certificate Portal
 */

if (!function_exists('sanitizeForFilename')) {
    /**
     * Sanitizes a string to be safe for use as a filename.
     * Removes invalid filesystem characters (/ \ : * ? " < > |) and control characters.
     *
     * @param string $str
     * @return string
     */
    function sanitizeForFilename($str) {
        // Remove characters that are illegal in Windows/Linux/macOS filenames
        $str = preg_replace('/[\/\\\:\*\?"<>\|]/', '', $str);
        // Remove control characters (ASCII 0-31)
        $str = preg_replace('/[\x00-\x1F\x7F]/', '', $str);
        // Trim whitespace and dots
        $str = trim($str, " .");
        return $str === '' ? 'Untitled' : $str;
    }
}
