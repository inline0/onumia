<?php declare(strict_types=1);

namespace Onumia\Lib\PhpParser\NodeVisitor;

use Onumia\Lib\PhpParser\Node;
use Onumia\Lib\PhpParser\NodeVisitorAbstract;

/**
 * Visitor cloning all nodes and linking to the original nodes using an attribute.
 *
 * This visitor is required to perform format-preserving pretty prints.
 */
class CloningVisitor extends NodeVisitorAbstract {
    public function enterNode(Node $origNode) {
        $node = clone $origNode;
        $node->setAttribute('origNode', $origNode);
        return $node;
    }
}
