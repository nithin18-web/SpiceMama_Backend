<?php
declare(strict_types=1);
namespace SpiceMama\Controllers;
use SpiceMama\Http\{ApiException, Request, Response};
use SpiceMama\Middleware\AdminGuard;
use SpiceMama\Repositories\{ImageRepository, ProductRepository};
use SpiceMama\Services\{ImageProcessor, ImageStore};
use SpiceMama\Support\Clock;
use SpiceMama\Validation\AdminInput;

final class AdminImageController
{
    public function __construct(
        private readonly AdminGuard $guard,
        private readonly \Closure $db,
        private readonly Clock $clock,
        private readonly \Closure $store,
        private readonly ImageProcessor $processor = new ImageProcessor(),
    ) {}

    public function upload(Request $request): Response
    {
        $admin = $this->guard->admin($request);
        if (Request::mediaType($request->header('content-type') ?? '') !== 'multipart/form-data') {
            throw new ApiException(415, 'unsupported_media_type', 'Send the image as multipart form data.');
        }
        // Over post_max_size PHP discards the whole body, so report size rather than missing fields.
        $limit = ImageProcessor::bytes((string) ini_get('post_max_size'));
        if ($limit > 0 && (int) ($request->header('content-length') ?? 0) > $limit) {
            throw new ApiException(413, 'payload_too_large', 'Upload is too large for this server.', ['file' => 'Use a smaller file.']);
        }
        [$productId, $altText, $file] = AdminInput::upload($request->form, $request->files);
        $images = new ImageRepository(($this->db)(), $this->clock);
        // Cheap existence check before decoding; re-checked under lock when inserting.
        if ((new ProductRepository(($this->db)(), $this->clock))->find($productId) === null) {
            throw self::missingProduct();
        }
        /** @var ImageStore $store */
        $store = ($this->store)();
        $staged = $store->stagingPath();
        try {
            $meta = $this->processor->process($file['tmp_name'], $staged);
            $name = $store->publish($staged, $meta['ext']);
        } finally {
            if (is_file($staged)) {
                unlink($staged);
            }
        }
        try {
            $image = $images->add($productId, $name, $meta, $altText, $admin['id']);
        } catch (\Throwable $error) {
            // Compensate: the database write failed, so the public file must not linger.
            $store->delete($name);
            throw $error;
        }
        if ($image === null) {
            $store->delete($name);
            throw self::missingProduct();
        }
        return new Response(201, ['data' => $image]);
    }

    public function update(Request $request, string $id): Response
    {
        $admin = $this->guard->admin($request);
        $id = AdminInput::id($id);
        $altText = AdminInput::altText($request->json());
        $image = (new ImageRepository(($this->db)(), $this->clock))->updateAlt($id, $altText, $admin['id']);
        return $image === null ? throw self::missingImage() : new Response(200, ['data' => $image]);
    }

    public function delete(Request $request, string $id): Response
    {
        $admin = $this->guard->admin($request);
        $id = AdminInput::id($id);
        $name = (new ImageRepository(($this->db)(), $this->clock))->remove($id, $admin['id']);
        if ($name === null) {
            throw self::missingImage();
        }
        // After commit: a failed unlink leaves an orphan for bin/maintenance.php, never a broken record.
        if (!($this->store)()->delete($name)) {
            error_log('SpiceMama orphaned upload left for maintenance cleanup');
        }
        return new Response(204);
    }

    public function reorder(Request $request, string $id): Response
    {
        $admin = $this->guard->admin($request);
        $id = AdminInput::id($id);
        $ids = AdminInput::imageOrder($request->json());
        return AdminProductController::found((new ImageRepository(($this->db)(), $this->clock))->reorder($id, $ids, $admin['id']));
    }

    private static function missingProduct(): ApiException
    {
        return new ApiException(422, 'validation_failed', 'Some fields are invalid.', ['product_id' => 'Product not found.']);
    }

    private static function missingImage(): ApiException
    {
        return new ApiException(404, 'not_found', 'Image not found.');
    }
}
