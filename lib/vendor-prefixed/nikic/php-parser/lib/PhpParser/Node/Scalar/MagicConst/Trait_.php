<?php declare(strict_types=1);

namespace Onumia\Lib\PhpParser\Node\Scalar\MagicConst;

use Onumia\Lib\PhpParser\Node\Scalar\MagicConst;

class Trait_ extends MagicConst {
    public function getName(): string {
        return '__TRAIT__';
    }

    public function getType(): string {
        return 'Scalar_MagicConst_Trait';
    }
}
