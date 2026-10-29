<?php

namespace App\Application\DemoAi;

use App\Application\Review\DemoReviewConfiguration;
use App\Domain\DemoAi\DemoAiException;

final class DemoAiConfiguration
{
    public function __construct(private readonly DemoReviewConfiguration $demo) {}

    public function workspaceId(): string
    {
        $connection = (string) config('database.default');
        $database = config("database.connections.{$connection}.database");
        if (! $this->demo->enabled() || ! is_string($database)
            || (! str_ends_with($database, '_demo') && ! str_ends_with($database, '_test'))
            || ! in_array(config("database.connections.{$connection}.driver"), ['mysql', 'mariadb'], true)) {
            throw new DemoAiException('demo_ai.workspace_configuration');
        }

        return (string) $this->demo->user()->workspace_id;
    }
}
