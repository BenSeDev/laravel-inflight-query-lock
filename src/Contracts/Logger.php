<?php

namespace Bensedev\LaravelInflightQueryLock\Contracts;

interface Logger
{
    public function handle(string $message): void;
}
