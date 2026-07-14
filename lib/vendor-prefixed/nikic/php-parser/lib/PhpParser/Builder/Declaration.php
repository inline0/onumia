<?php

declare (strict_types=1);
namespace Onumia\Lib\PhpParser\Builder;

use Onumia\Lib\PhpParser;
use Onumia\Lib\PhpParser\BuilderHelpers;
abstract class Declaration implements Onumia\Lib\PhpParser\Builder
{
    /** @var array<string, mixed> */
    protected array $attributes = [];
    /**
     * Adds a statement.
     *
     * @param Onumia\Lib\PhpParser\Node\Stmt|Onumia\Lib\PhpParser\Builder $stmt The statement to add
     *
     * @return $this The builder instance (for fluid interface)
     */
    abstract public function addStmt($stmt);
    /**
     * Adds multiple statements.
     *
     * @param (Onumia\Lib\PhpParser\Node\Stmt|Onumia\Lib\PhpParser\Builder)[] $stmts The statements to add
     *
     * @return $this The builder instance (for fluid interface)
     */
    public function addStmts(array $stmts)
    {
        foreach ($stmts as $stmt) {
            $this->addStmt($stmt);
        }
        return $this;
    }
    /**
     * Sets doc comment for the declaration.
     *
     * @param Onumia\Lib\PhpParser\Comment\Doc|string $docComment Doc comment to set
     *
     * @return $this The builder instance (for fluid interface)
     */
    public function setDocComment($docComment)
    {
        $this->attributes['comments'] = [BuilderHelpers::normalizeDocComment($docComment)];
        return $this;
    }
}
