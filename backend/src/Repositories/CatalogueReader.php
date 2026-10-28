<?php
declare(strict_types=1);
namespace SpiceMama\Repositories;

interface CatalogueReader
{
    public function products(array $query): array;
    public function product(string $slug): ?array;
    public function categories(): array;
}
