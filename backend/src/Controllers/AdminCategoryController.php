<?php
declare(strict_types=1);
namespace SpiceMama\Controllers;
use SpiceMama\Http\{ApiException, Request, Response};
use SpiceMama\Middleware\AdminGuard;
use SpiceMama\Repositories\CategoryRepository;
use SpiceMama\Support\Clock;
use SpiceMama\Validation\AdminInput;

final class AdminCategoryController
{
    public function __construct(private readonly AdminGuard $guard, private readonly \Closure $db, private readonly Clock $clock) {}

    public function list(Request $request): Response
    {
        $this->guard->admin($request);
        AdminInput::noQuery($request->query);
        return new Response(200, ['data' => $this->categories()->all()]);
    }

    public function create(Request $request): Response
    {
        $admin = $this->guard->admin($request);
        $input = AdminInput::categoryCreate($request->json());
        return new Response(201, ['data' => $this->categories()->create($input, $admin['id'])]);
    }

    public function update(Request $request, string $id): Response
    {
        $admin = $this->guard->admin($request);
        $id = AdminInput::id($id);
        $changes = AdminInput::categoryPatch($request->json());
        $category = $this->categories()->update($id, $changes, $admin['id']);
        return $category === null ? throw new ApiException(404, 'not_found', 'Category not found.') : new Response(200, ['data' => $category]);
    }

    private function categories(): CategoryRepository
    {
        return new CategoryRepository(($this->db)(), $this->clock);
    }
}
