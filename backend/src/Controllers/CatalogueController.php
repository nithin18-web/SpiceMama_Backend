<?php
declare(strict_types=1);
namespace SpiceMama\Controllers;
use SpiceMama\Repositories\CatalogueReader;
use SpiceMama\Http\{ApiException, Request, Response};
use SpiceMama\Validation\CatalogueQuery;

final class CatalogueController
{
    public function __construct(private readonly \Closure $reader) {}

    public function handle(Request $request): Response
    {
        if ($request->method !== 'GET') {
            throw new ApiException(404, 'not_found', 'Endpoint not found.');
        }
        if ($request->path === '/api/v1/products') {
            $query = CatalogueQuery::parse($request->query);
            return new Response(200, ($this->reader)()->products($query));
        }
        if (preg_match('#^/api/v1/products/([^/]+)$#D', $request->path, $matches)) {
            if ($request->query !== []) {
                throw new ApiException(422, 'validation_failed', 'Unknown query parameter.');
            }
            $slug = CatalogueQuery::slug($matches[1]);
            $product = ($this->reader)()->product($slug);
            if ($product === null) {
                throw new ApiException(404, 'not_found', 'Product not found.');
            }
            return new Response(200, ['data' => $product]);
        }
        if ($request->path === '/api/v1/categories') {
            if ($request->query !== []) {
                throw new ApiException(422, 'validation_failed', 'Unknown query parameter.');
            }
            return new Response(200, ['data' => ($this->reader)()->categories()]);
        }
        // Do not expose partially implemented admin operations.
        throw new ApiException(404, 'not_found', 'Endpoint not found.');
    }
}
