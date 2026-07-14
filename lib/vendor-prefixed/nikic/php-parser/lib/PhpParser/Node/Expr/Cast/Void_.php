<?php

declare (strict_types=1);
namespace Onumia\Lib\PhpParser\Node\Expr\Cast;

use Onumia\Lib\PhpParser\Node\Expr\Cast;
class Void_ extends Cast
{
    public function getType(): string
    {
        return 'Expr_Cast_Void';
    }
}
