<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Enums;

/**
 * <OperacionExenta> — why a supply is exempt.
 *
 * Deliberately a SEPARATE enum from OperationType. The two elements are
 * mutually exclusive in the schema and E-codes are not valid
 * CalificacionOperacion values, so folding E1..E6 into OperationType would
 * invite exactly the bug this fixes: an exempt supply filed as a 0% taxed one.
 */
enum ExemptionCause: string
{
    case ARTICLE_20 = 'E1';
    case ARTICLE_21 = 'E2';
    case ARTICLE_22 = 'E3';
    case ARTICLES_23_24 = 'E4';
    case ARTICLE_25 = 'E5';
    case OTHER = 'E6';

    public function description(): string
    {
        return match ($this) {
            self::ARTICLE_20 => 'Exempt under article 20 LIVA',
            self::ARTICLE_21 => 'Exempt under article 21 LIVA (exports)',
            self::ARTICLE_22 => 'Exempt under article 22 LIVA',
            self::ARTICLES_23_24 => 'Exempt under articles 23 and 24 LIVA',
            self::ARTICLE_25 => 'Exempt under article 25 LIVA (intra-Community supply)',
            self::OTHER => 'Exempt on other grounds',
        };
    }
}
