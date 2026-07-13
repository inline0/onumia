<?php

declare(strict_types=1);

namespace Onumia\Lib\Pitmaster\Storage;

use Onumia\Lib\Pitmaster\Object\GitObject;
use Onumia\Lib\Pitmaster\Object\ObjectId;

/**
 * Interface for reading and writing git objects.
 */
interface ObjectStore
{
    public function read(ObjectId $id): ?GitObject;

    public function exists(ObjectId $id): bool;

    public function write(GitObject $object): ObjectId;
}
