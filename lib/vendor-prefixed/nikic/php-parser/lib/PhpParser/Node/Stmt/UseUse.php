<?php

declare (strict_types=1);
namespace Onumia\Lib\PhpParser\Node\Stmt;

use Onumia\Lib\PhpParser\Node\UseItem;
require __DIR__ . '/../UseItem.php';
if (\false) {
    /**
     * For classmap-authoritative support.
     *
     * @deprecated use \Onumia\Lib\PhpParser\Node\UseItem instead.
     */
    class UseUse extends UseItem
    {
    }
}
