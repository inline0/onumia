<?php declare(strict_types=1);

namespace Onumia\Lib\PhpParser\Node\Expr\BinaryOp;

use Onumia\Lib\PhpParser\Node\Expr\BinaryOp;

class ShiftLeft extends BinaryOp {
    public function getOperatorSigil(): string {
        return '<<';
    }

    public function getType(): string {
        return 'Expr_BinaryOp_ShiftLeft';
    }
}
