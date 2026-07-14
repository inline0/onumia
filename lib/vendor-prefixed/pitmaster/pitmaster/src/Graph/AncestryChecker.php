<?php

declare (strict_types=1);
namespace Onumia\Lib\Pitmaster\Graph;

use Onumia\Lib\Pitmaster\Merge\MergeBase;
use Onumia\Lib\Pitmaster\Object\ObjectId;
use Onumia\Lib\Pitmaster\Storage\ObjectDatabase;
/**
 * Check ancestry relationships between commits.
 */
final class AncestryChecker
{
    private readonly MergeBase $mergeBase;
    public function __construct(ObjectDatabase $objects)
    {
        $this->mergeBase = new MergeBase($objects);
    }
    /**
     * Is commit A an ancestor of commit B?
     */
    public function isAncestor(ObjectId $a, ObjectId $b): bool
    {
        return $this->mergeBase->isAncestor($a, $b);
    }
}
