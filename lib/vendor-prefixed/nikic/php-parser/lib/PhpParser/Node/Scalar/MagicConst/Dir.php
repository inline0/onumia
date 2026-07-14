<?php

declare (strict_types=1);
namespace Onumia\Lib\PhpParser\Node\Scalar\MagicConst;

use Onumia\Lib\PhpParser\Node\Scalar\MagicConst;
class Dir extends MagicConst
{
    public function getName(): string
    {
        return '__DIR__';
    }
    public function getType(): string
    {
        return 'Scalar_MagicConst_Dir';
    }
}
