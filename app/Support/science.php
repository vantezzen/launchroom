<?php

function parseMemoryValue($value)
{
    if (stripos($value, 'GiB') !== false) {
        return (float) $value * 1024 * 1024 * 1024;
    } elseif (stripos($value, 'MiB') !== false) {
        return (float) $value * 1024 * 1024;
    } elseif (stripos($value, 'KiB') !== false) {
        return (float) $value * 1024;
    } elseif (stripos($value, 'B') !== false) {
        return (float) $value;
    }

    return (float) $value; // Assume raw bytes if no unit
}
