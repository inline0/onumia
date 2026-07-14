<?php

declare (strict_types=1);
namespace Onumia\Lib\Pitmaster\Graph;

use Onumia\Lib\Pitmaster\Diff\TreeDiff;
use Onumia\Lib\Pitmaster\Index\Index;
use Onumia\Lib\Pitmaster\Index\IndexEntry;
use Onumia\Lib\Pitmaster\Index\IndexWriter;
use Onumia\Lib\Pitmaster\Merge\MergeBase;
use Onumia\Lib\Pitmaster\Merge\ThreeWayMerge;
use Onumia\Lib\Pitmaster\Object\Blob;
use Onumia\Lib\Pitmaster\Object\Commit;
use Onumia\Lib\Pitmaster\Object\ObjectId;
use Onumia\Lib\Pitmaster\Object\ObjectType;
use Onumia\Lib\Pitmaster\Object\Tree;
use Onumia\Lib\Pitmaster\Object\TreeEntry;
use Onumia\Lib\Pitmaster\Ref\RefDatabase;
use Onumia\Lib\Pitmaster\Storage\ObjectDatabase;
/**
 * Git rebase: replay commits from one branch onto another.
 *
 * Takes the commits that are in the current branch but not in the target,
 * and replays them one by one on top of the target.
 */
final class Rebase
{
    public function __construct(private readonly ObjectDatabase $objects, private readonly RefDatabase $refs)
    {
    }
    /**
     * Rebase the current branch onto the given target.
     *
     * @return array{success: bool, commits: int, conflicts: array<int, string>}
     */
    public function rebase(ObjectId $onto): array
    {
        $headId = $this->refs->resolveHead();
        if ($headId === null) {
            return ['success' => \false, 'commits' => 0, 'conflicts' => ['HEAD not set']];
        }
        // Find merge base
        $mergeBase = new MergeBase($this->objects);
        $base = $mergeBase->find($headId, $onto);
        if ($base === null) {
            return ['success' => \false, 'commits' => 0, 'conflicts' => ['No common ancestor']];
        }
        // Collect commits to replay (from base to HEAD, excluding base)
        $walker = new CommitWalker($this->objects);
        $allCommits = $walker->walk($headId, 1000);
        $toReplay = [];
        foreach ($allCommits as $commit) {
            if ($commit->id->equals($base)) {
                break;
            }
            $toReplay[] = $commit;
        }
        // Reverse to replay in chronological order
        $toReplay = array_reverse($toReplay);
        if ($toReplay === []) {
            return ['success' => \true, 'commits' => 0, 'conflicts' => []];
        }
        // Move HEAD to onto
        $currentId = $onto;
        $conflicts = [];
        foreach ($toReplay as $commit) {
            // Create new commit with same changes but new parent
            $content = Commit::buildContent(tree: $commit->tree, parents: [$currentId], author: $commit->author, committer: $commit->committer, message: $commit->message);
            $newId = ObjectId::compute(ObjectType::Commit, $content);
            $newCommit = Commit::parse($content, $newId);
            $this->objects->write($newCommit);
            $currentId = $newId;
        }
        // Update HEAD
        $head = $this->refs->readHead();
        if ($head !== null) {
            $this->refs->update($head->target, $currentId);
        }
        return ['success' => \true, 'commits' => count($toReplay), 'conflicts' => $conflicts];
    }
}
