<?php

declare(strict_types=1);

namespace OneSMTP\Conflict;

interface MailConflictDetectorInterface
{
    /**
     * @return array{plugins:list<string>,hooks:array<string,int>}
     */
    public function detect(): array;
}
