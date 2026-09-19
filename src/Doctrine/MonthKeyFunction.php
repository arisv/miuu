<?php

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * MONTH_KEY(unixTimestamp) → 'YYYYMM' string, the month bucket used by "group by date".
 * Evaluated in the database session time zone (UTC in the dev stack); PHP mirrors it with
 * gmdate('Ym') in {@see \App\Service\ListOrdering::groupValue()}.
 */
class MonthKeyFunction extends FunctionNode
{
    private Node $timestamp;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->timestamp = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf("DATE_FORMAT(FROM_UNIXTIME(%s), '%%Y%%m')", $this->timestamp->dispatch($sqlWalker));
    }
}
