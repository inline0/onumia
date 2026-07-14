<?php

declare (strict_types=1);
namespace Onumia\Lib\PhpParser\Node\Expr\AssignOp;

use Onumia\Lib\PhpParser\Node\Expr\AssignOp;
class ShiftRight extends AssignOp
{
    public function getType(): string
    {
        return 'Expr_AssignOp_ShiftRight';
    }
}
