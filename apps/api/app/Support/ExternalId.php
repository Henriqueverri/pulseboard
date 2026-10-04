<?php

namespace App\Support;

/**
 * Format of the ids that integrating systems use for their own records
 * (customers, products, transactions), unique per organization.
 */
final class ExternalId
{
    public const MAX_LENGTH = 128;

    public const PATTERN = '/^[A-Za-z0-9._:-]+$/';

    /**
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['string', 'max:'.self::MAX_LENGTH, 'regex:'.self::PATTERN];
    }
}
