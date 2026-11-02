<?php
declare(strict_types=1);
namespace SpiceMama\Controllers;
use SpiceMama\Http\{ApiException, Request, Response};
use SpiceMama\Middleware\AdminGuard;
use SpiceMama\Repositories\ProductRepository;
use SpiceMama\Support\Clock;
use SpiceMama\Validation\{AdminInput, CatalogueQuery};

final class AdminProductController
{
    public function __construct(private readonly AdminGuard $guard, private readonly \Closure $db, private readonly Clock $clock) {}

    public function list(Request $request): Response
    {
        $this->guard->admin($request);
        return new Response(200, $this->products()->page(CatalogueQuery::parse($request->query, true)));
    }

    public function show(Request $request, string $id): Response
    {
        $this->guard->admin($request);
        $id = AdminInput::id($id);
        AdminInput::noQuery($request->query);
        return self::found($this->products()->find($id));
    }

    public function create(Request $request): Response
    {
        $admin = $this->guard->admin($request);
        $input = AdminInput::productCreate($request->json());
        return new Response(201, ['data' => $this->products()->create($input, $admin['id'])]);
    }

    public function update(Request $request, string $id): Response
    {
        $admin = $this->guard->admin($request);
        $id = AdminInput::id($id);
        $changes = AdminInput::productPatch($request->json());
        return self::found($this->products()->update($id, $changes, $admin['id']));
    }

    public function status(Request $request, string $id): Response
    {
        $admin = $this->guard->admin($request);
        $id = AdminInput::id($id);
        $status = AdminInput::status($request->json());
        return self::found($this->products()->setStatus($id, $status, $admin['id']));
    }

    public static function found(?array $product): Response
    {
        return $product === null ? throw new ApiException(404, 'not_found', 'Product not found.') : new Response(200, ['data' => $product]);
    }

    private function products(): ProductRepository
    {
        return new ProductRepository(($this->db)(), $this->clock);
    }
}
