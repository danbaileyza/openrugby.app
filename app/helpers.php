<?php

if (! function_exists('ordinal')) {
    /**
     * Convert a number to its ordinal form (1st, 2nd, 3rd, etc.)
     */
    function ordinal(int $number): string
    {
        $suffixes = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];

        if (($number % 100) >= 11 && ($number % 100) <= 13) {
            return $number . 'th';
        }

        return $number . $suffixes[$number % 10];
    }
}
