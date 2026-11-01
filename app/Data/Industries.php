<?php

namespace App\Data;

final class Industries
{
    public const OTHER = 'Other';

    public const ALL = [
        'Retail & eCommerce',
        'Consumer Goods',
        'Software & Technology',
        'Financial Services',
        'Media & Entertainment',
        'Professional Services',
        'Local Services',
        'Education & Careers',
        'Automotive',
        'Health',
        'Travel & Hospitality',
        self::OTHER,
    ];

    /**
     * ALL minus OTHER: the industries someone would deliberately onboard a
     * new account into - OTHER is just the fallback for an account nobody
     * has mapped yet, not a real category to pick from a form.
     *
     * @return list<string>
     */
    public static function onboardable(): array
    {
        return array_values(array_diff(self::ALL, [self::OTHER]));
    }
}
