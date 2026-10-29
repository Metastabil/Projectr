<?php
declare(strict_types=1);

if (!function_exists('esc')) {
    /**
     * Escapes special characters in a string to make it safe for use in HTML or XML contexts.
     *
     * @param string|null $string $string The input string to be escaped.
     * @return string The escaped string.
     */
    function esc(?string $string): string {
        return htmlspecialchars($string ?? "", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }
}